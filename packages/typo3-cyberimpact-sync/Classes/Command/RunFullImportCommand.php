<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
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
    description: 'Exécuter le workflow complet d\'import : scanner → traiter les chunks → finaliser les runs'
)]
final class RunFullImportCommand extends Command
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
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
        $this->addOption(
            'max-processing-seconds',
            null,
            InputOption::VALUE_OPTIONAL,
            'Temps max (secondes) à consacrer au traitement des chunks (défaut : 3600 = 1 heure)',
            3600
        );
        $this->addOption(
            'skip-scan',
            null,
            InputOption::VALUE_NONE,
            'Ignorer la phase de scan, traiter uniquement les chunks existants'
        );
        $this->addOption(
            'skip-finalize',
            null,
            InputOption::VALUE_NONE,
            'Ignorer la phase de finalisation, scanner et traiter seulement'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = time();
        $maxProcessingSeconds = (int)$input->getOption('max-processing-seconds');
        $skipScan = $input->getOption('skip-scan');
        $skipFinalize = $input->getOption('skip-finalize');

        $output->writeln('<comment>🚀 Démarrage du workflow d\'import complet...</comment>');
        $output->writeln('');

        // Phase 1: SCAN FOLDER
        if (!$skipScan) {
            $output->writeln('<info>📂 PHASE 1 : Scan du dossier incoming...</info>');
            $scanResult = $this->scanImportFolder($output);
            if ($scanResult === Command::FAILURE) {
                return Command::FAILURE;
            }
            $output->writeln('');
        }

        // Phase 2: PROCESS CHUNKS
        $output->writeln('<info>⚡ PHASE 2 : Traitement des chunks...</info>');
        $processResult = $this->processAllPendingChunks($output, $maxProcessingSeconds, $startTime);
        $output->writeln('');

        // Phase 3: FINALIZE RUNS
        if (!$skipFinalize) {
            $output->writeln('<info>✅ PHASE 3 : Finalisation des runs complétés...</info>');
            $this->finalizeCompletedRuns($output);
            $output->writeln('');
        }

        $elapsedTime = time() - $startTime;
        $output->writeln(sprintf(
            '<comment>✨ Workflow d\'import complété en %d secondes</comment>',
            $elapsedTime
        ));

        return Command::SUCCESS;
    }

    /**
     * Phase 1: Scan folder and queue files
     */
    private function scanImportFolder(OutputInterface $output): int
    {
        $settings = $this->getSettings();
        $storageUid = (int)($settings['falStorageUid'] ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $dryRunDefault = (bool)($settings['dryRunDefault'] ?? true);
        $chunkSize = max(1, (int)($settings['chunkSize'] ?? 500));

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $output->writeln(sprintf(
                '<error>❌ Stockage FAL non trouvé : %d</error>',
                $storageUid
            ));
            return Command::FAILURE;
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $output->writeln(sprintf(
                '<warning>⚠️  Dossier FAL non trouvé : %s (stockage %d)</warning>',
                $incomingFolder,
                $storageUid
            ));
            return Command::FAILURE;
        }

        $folder = $storage->getFolder($incomingFolder);
        $files = $storage->getFilesInFolder($folder);

        $queuedCount = 0;
        $skippedCount = 0;

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'xlsx') {
                continue;
            }

            $runUid = $this->runManager->queueFromFalFile(
                $file->getUid(),
                $dryRunDefault,
                false
            );

            if ($runUid === null) {
                $skippedCount++;
                continue;
            }

            $queuedCount++;
            $prepared = $this->prepareRunFromFile(
                $runUid,
                $file->getForLocalProcessing(),
                $chunkSize
            );

            $output->writeln(sprintf(
                '  ✓ Run #%d : %s (%d lignes, %d valides, %d erreurs, %d chunks)',
                $runUid,
                $file->getName(),
                $prepared['totalRows'],
                $prepared['validRows'],
                $prepared['errorCount'],
                $prepared['chunkCount']
            ));
        }

        $output->writeln(sprintf(
            '<comment>Scan terminé : %d nouveaux runs créés, %d ignorés</comment>',
            $queuedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Phase 2: Process all pending chunks until timeout or completion
     */
    private function processAllPendingChunks(
        OutputInterface $output,
        int $maxSeconds,
        int $startTime
    ): int {
        $settings = $this->getSettings();
        $staleAfterSeconds = max(60, (int)($settings['staleChunkTimeoutSeconds'] ?? 900));

        $processedCount = 0;
        $errorCount = 0;

        while (true) {
            // Check timeout
            $elapsedTime = time() - $startTime;
            if ($elapsedTime >= $maxSeconds) {
                $output->writeln(sprintf(
                    '<warning>⏱️  Délai d\'attente dépassé (%d secondes)</warning>',
                    $maxSeconds
                ));
                break;
            }

            // Requeue stale chunks
            $requeued = $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
            if ($requeued > 0) {
                $output->writeln(sprintf(
                    '  ⚠️  %d chunks périmés remis en attente',
                    $requeued
                ));
            }

            // Process next chunk
            try {
                $processed = $this->chunkProcessor->processNextPendingChunk();
                if ($processed === false) {
                    // No more pending chunks
                    break;
                }

                $processedCount++;
                $output->writeln(sprintf(
                    '  ✓ Chunk traité (#%d)',
                    $processedCount
                ));
            } catch (\Throwable $e) {
                $errorCount++;
                $output->writeln(sprintf(
                    '  ❌ Erreur lors du traitement du chunk : %s',
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            '<comment>Traitement terminé : %d chunks traités, %d erreurs</comment>',
            $processedCount,
            $errorCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Phase 3: Finalize all completed runs
     */
    private function finalizeCompletedRuns(OutputInterface $output): void
    {
        $finalizedCount = 0;

        while (true) {
            $result = $this->runFinalizer->finalizeNextRun();

            if ($result['status'] === 'none' || $result['status'] === 'deferred' || $result['status'] === 'blocked') {
                break;
            }

            if ($result['status'] === 'finalized') {
                $finalizedCount++;
                $output->writeln(sprintf(
                    '  ✓ Run finalisé : %s',
                    $result['message']
                ));
            }
        }

        $output->writeln(sprintf(
            '<comment>Finalisation terminée : %d runs finalisés</comment>',
            $finalizedCount
        ));
    }

    /**
     * Prepare run from file (read Excel, validate, create chunks)
     *
     * @return array<string, int>
     */
    private function prepareRunFromFile(int $runUid, string $filePath, int $chunkSize): array
    {
        $totalRows = 0;
        $validRows = 0;
        $errorCount = 0;
        $contacts = [];

        foreach ($this->excelChunkReader->readChunksFromLocalFile($filePath, $chunkSize) as $chunk) {
            $totalRows += count($chunk['rows']);
            $mapped = $this->contactRowMapper->mapRows($chunk['rows'], $chunk['resolvedMap']);
            $validRows += count($mapped['contacts']);
            $contacts = array_merge($contacts, $mapped['contacts']);

            foreach ($mapped['errors'] as $error) {
                $errorCount++;
                $this->errorStorage->createRunError(
                    $runUid,
                    'parse',
                    (string)($error['code'] ?? 'parse_error'),
                    (string)($error['message'] ?? 'Erreur de parsing'),
                    (string)($error['payload'] ?? '')
                );
            }
        }

        $this->runManager->updateRunTotalRows($runUid, $totalRows);
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $contacts, $chunkSize);

        return [
            'totalRows' => $totalRows,
            'validRows' => $validRows,
            'errorCount' => $errorCount,
            'chunkCount' => $chunkCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
