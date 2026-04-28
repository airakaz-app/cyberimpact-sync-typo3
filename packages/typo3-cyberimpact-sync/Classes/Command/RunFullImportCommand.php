<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Service\Run\ChunkProcessor;
use Cyberimpact\CyberimpactSync\Service\Run\RunFinalizer;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Cyberimpact\CyberimpactSync\Service\Run\RunPreparationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'cyberimpact:import-complet',
    description: 'Workflow complet : scanner le dossier entrant → traiter les chunks → finaliser les runs.'
)]
final class RunFullImportCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunManager $runManager,
        private readonly RunPreparationService $runPreparationService,
        private readonly ChunkProcessor $chunkProcessor,
        private readonly ChunkStorage $chunkStorage,
        private readonly RunFinalizer $runFinalizer,
    ) {
        parent::__construct();
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-processing-seconds', null, InputOption::VALUE_OPTIONAL, 'Temps max alloué.', 3600)
            ->addOption('skip-scan', null, InputOption::VALUE_NONE, 'Ignorer le scan.')
            ->addOption('skip-finalize', null, InputOption::VALUE_NONE, 'Ignorer la finalisation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime    = time();
        $maxSeconds   = (int)$input->getOption('max-processing-seconds');
        $skipScan     = (bool)$input->getOption('skip-scan');
        $skipFinalize = (bool)$input->getOption('skip-finalize');

        $this->logger->info('Démarrage du workflow d\'import complet.');
        $output->writeln('<comment>Démarrage du workflow d\'import…</comment>');

        try {
            // Phase 1 : Scan
            if (!$skipScan) {
                $output->writeln('<info>Phase 1 — Scan du dossier entrant</info>');
                if ($this->scanFolder($output) === Command::FAILURE) {
                    return Command::FAILURE;
                }
            }

            // Phase 2 : Traitement
            $output->writeln('<info>Phase 2 — Traitement des chunks</info>');
            $this->processChunks($output, $maxSeconds, $startTime);

            // Phase 3 : Finalisation
            if (!$skipFinalize) {
                $output->writeln('<info>Phase 3 — Finalisation des runs</info>');
                $this->finalizeRuns($output);
            }

            $duration = time() - $startTime;
            $this->logger->info(sprintf('Workflow d\'import complet terminé avec succès en %d secondes.', $duration));
            $output->writeln(sprintf('<comment>Workflow terminé en %d secondes.</comment>', $duration));

        } catch (\Throwable $e) {
            $this->logger->critical('Échec critique du workflow d\'import : ' . $e->getMessage(), ['exception' => $e]);
            $output->writeln('<error>Erreur critique : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function scanFolder(OutputInterface $output): int
    {
        $settings       = $this->getExtSettings();
        $storageUid     = (int)($settings['falStorageUid']  ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $chunkSize      = max(1, (int)($settings['chunkSize'] ?? 500));
        $columnMapping  = $this->importSettingsRepository->findFirst()->getColumnMapping();

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $this->logger->error(sprintf('Scan échoué : Stockage FAL %d introuvable.', $storageUid));
            $output->writeln(sprintf('<error>Stockage FAL introuvable : %d</error>', $storageUid));
            return Command::FAILURE;
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $this->logger->warning(sprintf('Scan ignoré : Dossier %s introuvable.', $incomingFolder));
            $output->writeln(sprintf('<warning>Dossier FAL introuvable : %s</warning>', $incomingFolder));
            return Command::FAILURE;
        }

        $folder       = $storage->getFolder($incomingFolder);
        $files        = $storage->getFilesInFolder($folder);
        $queuedCount  = 0;

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'xlsx') continue;

            $runUid = $this->runManager->queueFromFalFile($file->getUid());
            if ($runUid === null) continue;

            $queuedCount++;
            $stats = $this->runPreparationService->prepareRun($runUid, $file->getForLocalProcessing(), $chunkSize, $columnMapping);
            
            $this->logger->info(sprintf('Nouveau fichier détecté : %s (Run #%d)', $file->getName(), $runUid));
            $output->writeln(sprintf('  ✓ Run #%d : %s', $runUid, $file->getName()));
        }

        return Command::SUCCESS;
    }

    private function processChunks(OutputInterface $output, int $maxSeconds, int $startTime): void
    {
        $settings          = $this->getExtSettings();
        $staleAfterSeconds = max(60, (int)($settings['staleChunkTimeoutSeconds'] ?? 900));
        $processedCount    = 0;

        while (true) {
            if ((time() - $startTime) >= $maxSeconds) {
                $this->logger->notice('Le traitement des chunks a atteint le temps maximum alloué.');
                break;
            }

            $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);

            try {
                if (!$this->chunkProcessor->processNextPendingChunk()) break;
                $processedCount++;
            } catch (\Throwable $e) {
                $this->logger->error('Erreur durant le traitement d\'un chunk : ' . $e->getMessage());
            }
        }
        
        $this->logger->info(sprintf('%d chunks ont été traités durant ce cycle.', $processedCount));
    }

    private function finalizeRuns(OutputInterface $output): void
    {
        $finalizedCount = 0;
        while (true) {
            $result = $this->runFinalizer->finalizeNextRun();
            if (in_array($result['status'], ['none', 'deferred', 'blocked'], true)) break;
            if ($result['status'] === 'finalized') {
                $finalizedCount++;
                $this->logger->info('Run finalisé : ' . $result['message']);
            }
        }
    }

    private function getExtSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) { return []; }
    }
}