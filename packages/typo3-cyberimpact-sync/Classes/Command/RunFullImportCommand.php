<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;
use Cyberimpact\CyberimpactSync\Service\Run\ChunkProcessor;
use Cyberimpact\CyberimpactSync\Service\Run\RunFinalizer;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[AsCommand(
    name: 'cyberimpact:import-complet',
    description: 'Workflow complet : scanner le dossier entrant → traiter les chunks → finaliser les runs.'
)]
final class RunFullImportCommand extends Command
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunManager $runManager,
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
        private readonly ErrorStorage $errorStorage,
        private readonly ChunkProcessor $chunkProcessor,
        private readonly ChunkStorage $chunkStorage,
        private readonly RunFinalizer $runFinalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-processing-seconds',
                null,
                InputOption::VALUE_OPTIONAL,
                'Temps max (secondes) alloué au traitement des chunks.',
                3600
            )
            ->addOption(
                'skip-scan',
                null,
                InputOption::VALUE_NONE,
                'Ignorer la phase de scan et traiter uniquement les chunks existants.'
            )
            ->addOption(
                'skip-finalize',
                null,
                InputOption::VALUE_NONE,
                'Ignorer la phase de finalisation.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime         = time();
        $maxSeconds        = (int)$input->getOption('max-processing-seconds');
        $skipScan          = (bool)$input->getOption('skip-scan');
        $skipFinalize      = (bool)$input->getOption('skip-finalize');

        $output->writeln('<comment>Démarrage du workflow d\'import…</comment>');

        // Phase 1 : Scan
        if (!$skipScan) {
            $output->writeln('<info>Phase 1 — Scan du dossier entrant</info>');
            if ($this->scanFolder($output) === Command::FAILURE) {
                return Command::FAILURE;
            }
        }

        // Phase 2 : Traitement des chunks
        $output->writeln('<info>Phase 2 — Traitement des chunks</info>');
        $this->processChunks($output, $maxSeconds, $startTime);

        // Phase 3 : Finalisation
        if (!$skipFinalize) {
            $output->writeln('<info>Phase 3 — Finalisation des runs</info>');
            $this->finalizeRuns($output);
        }

        $output->writeln(sprintf(
            '<comment>Workflow terminé en %d secondes.</comment>',
            time() - $startTime
        ));

        return Command::SUCCESS;
    }

    // =========================================================================
    // Phases du workflow
    // =========================================================================

    private function scanFolder(OutputInterface $output): int
    {
        $settings       = $this->getExtSettings();
        $storageUid     = (int)($settings['falStorageUid']  ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $chunkSize      = max(1, (int)($settings['chunkSize'] ?? 500));
        $columnMapping  = $this->importSettingsRepository->findFirst()->getColumnMapping();

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $output->writeln(sprintf('<error>Stockage FAL introuvable : %d</error>', $storageUid));
            return Command::FAILURE;
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $output->writeln(sprintf('<warning>Dossier FAL introuvable : %s (stockage %d)</warning>', $incomingFolder, $storageUid));
            return Command::FAILURE;
        }

        $folder      = $storage->getFolder($incomingFolder);
        $files       = $storage->getFilesInFolder($folder);
        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'xlsx') {
                continue;
            }

            $runUid = $this->runManager->queueFromFalFile($file->getUid());
            if ($runUid === null) {
                $skippedCount++;
                continue;
            }

            $queuedCount++;
            $stats = $this->prepareRun($runUid, $file->getForLocalProcessing(), $chunkSize, $columnMapping);

            $output->writeln(sprintf(
                '  ✓ Run #%d : %s (%d lignes, %d valides, %d erreurs, %d chunks)',
                $runUid,
                $file->getName(),
                $stats['totalRows'],
                $stats['validRows'],
                $stats['errorCount'],
                $stats['chunkCount']
            ));
        }

        $output->writeln(sprintf(
            '  Scan terminé : %d run(s) créé(s), %d ignoré(s).',
            $queuedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }

    private function processChunks(OutputInterface $output, int $maxSeconds, int $startTime): void
    {
        $settings          = $this->getExtSettings();
        $staleAfterSeconds = max(60, (int)($settings['staleChunkTimeoutSeconds'] ?? 900));
        $processedCount    = 0;
        $errorCount        = 0;

        while (true) {
            if ((time() - $startTime) >= $maxSeconds) {
                $output->writeln(sprintf('  Délai maximum de %d secondes atteint.', $maxSeconds));
                break;
            }

            $requeued = $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
            if ($requeued > 0) {
                $output->writeln(sprintf('  %d chunk(s) périmé(s) remis en attente.', $requeued));
            }

            try {
                if (!$this->chunkProcessor->processNextPendingChunk()) {
                    break; // Plus aucun chunk en attente
                }
                $processedCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $output->writeln(sprintf('  Erreur chunk : %s', $e->getMessage()));
            }
        }

        $output->writeln(sprintf('  %d chunk(s) traité(s), %d erreur(s).', $processedCount, $errorCount));
    }

    private function finalizeRuns(OutputInterface $output): void
    {
        $finalizedCount = 0;

        while (true) {
            $result = $this->runFinalizer->finalizeNextRun();

            if (in_array($result['status'], ['none', 'deferred', 'blocked'], true)) {
                break;
            }

            if ($result['status'] === 'finalized') {
                $finalizedCount++;
                $output->writeln('  ✓ ' . $result['message']);
            }
        }

        $output->writeln(sprintf('  %d run(s) finalisé(s).', $finalizedCount));
    }

    // =========================================================================
    // Helper : préparation d'un run depuis un fichier Excel
    // =========================================================================

    /**
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping
     * @return array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}
     */
    private function prepareRun(int $runUid, string $filePath, int $chunkSize, ?array $columnMapping): array
    {
        $totalRows  = 0;
        $errorCount = 0;
        $contacts   = [];

        foreach ($this->excelChunkReader->readChunksFromLocalFile($filePath, $chunkSize, $columnMapping) as $chunk) {
            $totalRows += count($chunk['rows'] ?? []);
            $mapped     = $this->contactRowMapper->mapRows($chunk['rows'] ?? [], $chunk['resolvedMap'] ?? null);
            $contacts   = array_merge($contacts, $mapped['contacts']);

            foreach ($mapped['errors'] as $error) {
                $errorCount++;
                $this->errorStorage->createRunError(
                    $runUid,
                    'parse',
                    (string)($error['code']    ?? 'parse_error'),
                    (string)($error['message'] ?? 'Erreur de parsing'),
                    (string)($error['payload'] ?? '')
                );
            }
        }

        $this->runManager->updateRunTotalRows($runUid, $totalRows);
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $contacts, $chunkSize);

        return [
            'totalRows'  => $totalRows,
            'validRows'  => count($contacts),
            'errorCount' => $errorCount,
            'chunkCount' => $chunkCount,
        ];
    }

    /** @return array<string, mixed> */
    private function getExtSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
