<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RunFinalizer
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly RunReportService $runReportService,
        private readonly ExactSyncService $exactSyncService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        // Initialisation du logger pour tracer l'archivage
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function finalizeNextRun(): array
    {
        $run = $this->runStorage->findNextRunToFinalize();
        if ($run === null) {
            return ['status' => 'none', 'message' => 'Aucun run à finaliser.'];
        }

        $runUid = (int)$run['uid'];
        $this->runStorage->updateRunStatus($runUid, 'finalizing');

        $pendingChunks    = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'pending');
        $processingChunks = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'processing');
        $failedChunks     = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'failed');
        $doneChunks       = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'done');

        if ($pendingChunks > 0 || $processingChunks > 0) {
            $this->runStorage->updateRunStatus($runUid, 'processing');
            return [
                'status'  => 'deferred',
                'runUid'  => $runUid,
                'message' => sprintf('Run #%d non finalisable (pending=%d, processing=%d).', $runUid, $pendingChunks, $processingChunks),
            ];
        }

        $exactSyncGuard = $this->checkExactSyncGuard($run);
        if ($exactSyncGuard !== null) {
            $this->runStorage->updateRunStatus($runUid, $exactSyncGuard['status']);
            $this->writeReport($runUid, $exactSyncGuard['status'], $pendingChunks, $processingChunks, $doneChunks, $failedChunks);
            return ['status' => 'blocked', 'runUid' => $runUid, 'message' => $exactSyncGuard['message']];
        }

        $run = $this->runStorage->findRunByUid($runUid) ?? $run;
        $exactSyncMessage = '';
        if (((int)($run['exact_sync'] ?? 0)) === 1) {
            $exactSyncResult  = $this->exactSyncService->executeForRun($run);
            $exactSyncMessage = ' ExactSync : ' . ($exactSyncResult['message'] ?? 'done');
            $run = $this->runStorage->findRunByUid($runUid) ?? $run;
        }

        $finalStatus = $this->determineFinalStatus($run, $failedChunks);
        $this->runStorage->updateRunStatus($runUid, $finalStatus);
        $this->writeReport($runUid, $finalStatus, $pendingChunks, $processingChunks, $doneChunks, $failedChunks);

        // --- AJOUT : ARCHIVAGE DU FICHIER ---
        if (!empty($run['file_uid'])) {
            $this->archiveFile((int)$run['file_uid']);
        }
        // -------------------------------------

        return [
            'status'      => 'finalized',
            'runUid'      => $runUid,
            'finalStatus' => $finalStatus,
            'message'     => sprintf('Run #%d finalisé avec le statut "%s".%s', $runUid, $finalStatus, $exactSyncMessage),
        ];
    }

    /**
     * Déplace le fichier source vers fileadmin/import/archive/
     */
    private function archiveFile(int $fileUid): void
    {
        try {
            $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
            $file = $resourceFactory->getFileObject($fileUid);
            $storage = $file->getStorage();

            $archivePath = 'import/archive/';

            if (!$storage->hasFolder($archivePath)) {
                $storage->createFolder($archivePath);
            }
            
            $targetFolder = $storage->getFolder($archivePath);
            $file->move($targetFolder, $file->getName(), DuplicationBehavior::RENAME);

            $this->logger->info(sprintf('Fichier source (UID %d) archivé vers %s', $fileUid, $archivePath));
        } catch (\Throwable $e) {
            $this->logger->error('Erreur archivage fichier : ' . $e->getMessage());
        }
    }

    private function checkExactSyncGuard(array $run): ?array
    {
        if (((int)($run['exact_sync'] ?? 0)) !== 1) return null;
        $settings = $this->getExtSettings();
        $requireConfirmation = ((int)($settings['exactSyncRequireConfirmation'] ?? 1)) === 1;

        if ($requireConfirmation && ((int)($run['exact_sync_confirmed'] ?? 0)) !== 1) {
            return [
                'status'  => 'blocked_confirmation',
                'message' => sprintf('Run #%d bloqué : confirmation manuelle requise.', (int)$run['uid']),
            ];
        }
        return null;
    }

    private function determineFinalStatus(array $run, int $failedChunks): string
    {
        if ($failedChunks > 0) return 'failed';
        if ((int)($run['upsert_failed'] ?? 0) > 0 || (int)($run['unsubscribe_failed'] ?? 0) > 0) {
            return 'completed_with_errors';
        }
        return 'completed';
    }

    private function writeReport(int $runUid, string $status, int $pendingChunks, int $processingChunks, int $doneChunks, int $failedChunks): void
    {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) return;

        $errors = $this->errorStorage->findErrorsByRunUid($runUid, 1000);
        $summary = [
            'total_rows' => (int)($run['total_rows'] ?? 0),
            'processed_rows' => (int)($run['processed_rows'] ?? 0),
            'upsert_ok' => (int)($run['upsert_ok'] ?? 0),
            'upsert_failed' => (int)($run['upsert_failed'] ?? 0),
            'chunks_done' => $doneChunks,
            'chunks_failed' => $failedChunks,
            'errors_count' => count($errors),
        ];

        $reportFileUid = $this->runReportService->writeRunCsvReport($run, $summary, $errors);
        if ($reportFileUid > 0) {
            $this->runStorage->updateReportFileUid($runUid, $reportFileUid);
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