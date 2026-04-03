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
    name: 'cyberimpact:run-full-import',
    description: 'Execute complete import workflow: scan folder → process chunks → finalize runs (all-in-one)'
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
            'Max time (seconds) to spend processing chunks (default: 3600 = 1 hour)',
            3600
        );
        $this->addOption(
            'skip-scan',
            null,
            InputOption::VALUE_NONE,
            'Skip scan phase, only process existing chunks'
        );
        $this->addOption(
            'skip-finalize',
            null,
            InputOption::VALUE_NONE,
            'Skip finalize phase, only scan and process'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = time();
        $maxProcessingSeconds = (int)$input->getOption('max-processing-seconds');
        $skipScan = $input->getOption('skip-scan');
        $skipFinalize = $input->getOption('skip-finalize');

        $output->writeln('<comment>🚀 Starting full import workflow...</comment>');
        $output->writeln('');

        // Phase 1: SCAN FOLDER
        if (!$skipScan) {
            $output->writeln('<info>📂 PHASE 1: Scanning incoming folder...</info>');
            $scanResult = $this->scanImportFolder($output);
            if ($scanResult === Command::FAILURE) {
                return Command::FAILURE;
            }
            $output->writeln('');
        }

        // Phase 2: PROCESS CHUNKS
        $output->writeln('<info>⚡ PHASE 2: Processing chunks...</info>');
        $processResult = $this->processAllPendingChunks($output, $maxProcessingSeconds, $startTime);
        $output->writeln('');

        // Phase 3: FINALIZE RUNS
        if (!$skipFinalize) {
            $output->writeln('<info>✅ PHASE 3: Finalizing completed runs...</info>');
            $this->finalizeCompletedRuns($output);
            $output->writeln('');
        }

        $elapsedTime = time() - $startTime;
        $output->writeln(sprintf(
            '<comment>✨ Import workflow completed in %d seconds</comment>',
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
                '<error>❌ FAL storage not found: %d</error>',
                $storageUid
            ));
            return Command::FAILURE;
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $output->writeln(sprintf(
                '<warning>⚠️  FAL folder not found: %s (storage %d)</warning>',
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
                '  ✓ Run #%d: %s (%d rows, %d valid, %d errors, %d chunks)',
                $runUid,
                $file->getName(),
                $prepared['totalRows'],
                $prepared['validRows'],
                $prepared['errorCount'],
                $prepared['chunkCount']
            ));
        }

        $output->writeln(sprintf(
            '<comment>Scan result: %d new runs queued, %d skipped</comment>',
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
                    '<warning>⏱️  Processing timeout reached (%d seconds)</warning>',
                    $maxSeconds
                ));
                break;
            }

            // Requeue stale chunks
            $requeued = $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
            if ($requeued > 0) {
                $output->writeln(sprintf(
                    '  ⚠️  Requeued %d stale chunks',
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
                    '  ✓ Chunk processed (#%d)',
                    $processedCount
                ));
            } catch (\Throwable $e) {
                $errorCount++;
                $output->writeln(sprintf(
                    '  ❌ Error processing chunk: %s',
                    $e->getMessage()
                ));
            }
        }

        $output->writeln(sprintf(
            '<comment>Processing result: %d chunks processed, %d errors</comment>',
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
                    '  ✓ Run finalized: %s',
                    $result['message']
                ));
            }
        }

        $output->writeln(sprintf(
            '<comment>Finalize result: %d runs finalized</comment>',
            $finalizedCount
        ));
    }

    /**
     * Prepare run from file (read Excel, validate, create chunks)
     *
     * @param int $runUid
     * @param string $filePath
     * @param int $chunkSize
     * @return array<string, int>
     */
    private function prepareRunFromFile(int $runUid, string $filePath, int $chunkSize): array
    {
        $rows = $this->excelChunkReader->readAllRows($filePath);
        $validRows = [];
        $errorCount = 0;

        foreach ($rows as $rowIndex => $row) {
            $mapped = $this->contactRowMapper->mapRowToContact($row);
            if ($mapped['contact'] !== null) {
                $validRows[] = $mapped['contact'];
            } else {
                $errorCount++;
                $this->errorStorage->recordRowError($runUid, $rowIndex, $mapped['error'] ?? 'Unknown error');
            }
        }

        $this->runManager->updateRunTotalRows($runUid, count($rows));
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $validRows, $chunkSize);

        return [
            'totalRows' => count($rows),
            'validRows' => count($validRows),
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
