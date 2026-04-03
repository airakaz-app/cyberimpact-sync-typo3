<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class RunFinalizer
{
    public function __construct(
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly RunReportService $runReportService,
        private readonly ExactSyncService $exactSyncService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * @return array{status: string, message: string, runUid?: int, finalStatus?: string}
     */
    public function finalizeNextRun(): array
    {
        $run = $this->runStorage->findNextRunToFinalize();
        if ($run === null) {
            return [
                'status' => 'none',
                'message' => 'Aucun run à finaliser.',
            ];
        }

        $runUid = (int)$run['uid'];
        $this->runStorage->updateRunStatus($runUid, 'finalizing');

        $pendingChunks = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'pending');
        $processingChunks = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'processing');
        $failedChunks = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'failed');
        $doneChunks = $this->chunkStorage->countChunksByRunAndStatus($runUid, 'done');

        if ($pendingChunks > 0 || $processingChunks > 0) {
            $this->runStorage->updateRunStatus($runUid, 'processing');

            return [
                'status' => 'deferred',
                'runUid' => $runUid,
                'message' => sprintf(
                    'Run #%d non finalisable (pending=%d, processing=%d).',
                    $runUid,
                    $pendingChunks,
                    $processingChunks
                ),
            ];
        }

        $exactSyncGuard = $this->checkExactSyncGuard($run);
        if ($exactSyncGuard !== null) {
            $blockedStatus = $exactSyncGuard['status'];
            $this->runStorage->updateRunStatus($runUid, $blockedStatus);
            $this->writeReportForRun($runUid, $blockedStatus, $pendingChunks, $processingChunks, $doneChunks, $failedChunks);

            return [
                'status' => 'blocked',
                'runUid' => $runUid,
                'message' => $exactSyncGuard['message'],
            ];
        }


        $run = $this->runStorage->findRunByUid($runUid) ?? $run;
        $exactSyncResult = null;
        if (((int)($run['exact_sync'] ?? 0)) === 1) {
            $exactSyncResult = $this->exactSyncService->executeForRun($run);
            $run = $this->runStorage->findRunByUid($runUid) ?? $run;
        }

        $upsertFailed = (int)($run['upsert_failed'] ?? 0);
        $finalStatus = $failedChunks > 0
            ? 'failed'
            : (($upsertFailed > 0 || ((int)($run['unsubscribe_failed'] ?? 0)) > 0) ? 'completed_with_errors' : 'completed');

        $this->runStorage->updateRunStatus($runUid, $finalStatus);
        $this->writeReportForRun($runUid, $finalStatus, $pendingChunks, $processingChunks, $doneChunks, $failedChunks);

        return [
            'status' => 'finalized',
            'runUid' => $runUid,
            'finalStatus' => $finalStatus,
            'message' => sprintf('Run #%d finalisé avec le statut "%s".%s', $runUid, $finalStatus, $exactSyncResult !== null ? (' Exact-sync: ' . ($exactSyncResult['message'] ?? 'done')) : ''),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array{status: string, message: string}|null
     */
    private function checkExactSyncGuard(array $run): ?array
    {
        $isExactSync = ((int)($run['exact_sync'] ?? 0)) === 1;
        $isDryRun = ((int)($run['dry_run'] ?? 1)) === 1;
        if ($isExactSync === false || $isDryRun === true) {
            return null;
        }

        $settings = $this->getSettings();
        $requireConfirmation = ((int)($settings['exactSyncRequireConfirmation'] ?? 1)) === 1;
        $maxUnsubscribeCount = (int)($settings['exactSyncMaxUnsubscribeCount'] ?? 1000);

        if ($requireConfirmation && ((int)($run['exact_sync_confirmed'] ?? 0)) !== 1) {
            return [
                'status' => 'blocked_confirmation',
                'message' => sprintf(
                    'Run #%d bloqué: confirmation manuelle requise (`cyberimpact:finalize-run --confirm-run=%d`).',
                    (int)$run['uid'],
                    (int)$run['uid']
                ),
            ];
        }

        $unsubscribePlanned = (int)($run['unsubscribe_planned'] ?? 0);
        if ($unsubscribePlanned > $maxUnsubscribeCount) {
            return [
                'status' => 'blocked_threshold',
                'message' => sprintf(
                    'Run #%d bloqué: unsubscribe_planned=%d dépasse le seuil max=%d.',
                    (int)$run['uid'],
                    $unsubscribePlanned,
                    $maxUnsubscribeCount
                ),
            ];
        }

        return null;
    }

    private function writeReportForRun(
        int $runUid,
        string $status,
        int $pendingChunks,
        int $processingChunks,
        int $doneChunks,
        int $failedChunks,
    ): void {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) {
            return;
        }

        $run['status'] = $status;
        $errors = $this->errorStorage->findErrorsByRunUid($runUid, 1000);
        $summary = [
            'total_rows' => (int)($run['total_rows'] ?? 0),
            'processed_rows' => (int)($run['processed_rows'] ?? 0),
            'upsert_ok' => (int)($run['upsert_ok'] ?? 0),
            'upsert_failed' => (int)($run['upsert_failed'] ?? 0),
            'unsubscribe_planned' => (int)($run['unsubscribe_planned'] ?? 0),
            'unsubscribe_done' => (int)($run['unsubscribe_done'] ?? 0),
            'unsubscribe_failed' => (int)($run['unsubscribe_failed'] ?? 0),
            'chunks_pending' => $pendingChunks,
            'chunks_processing' => $processingChunks,
            'chunks_done' => $doneChunks,
            'chunks_failed' => $failedChunks,
            'errors_count' => count($errors),
        ];

        $reportFileUid = $this->runReportService->writeRunCsvReport($run, $summary, $errors);
        if ($reportFileUid > 0) {
            $this->runStorage->updateReportFileUid($runUid, $reportFileUid);
        }
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
