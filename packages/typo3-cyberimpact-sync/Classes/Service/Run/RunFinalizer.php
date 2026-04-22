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
     * Finalise le prochain run prêt à être clôturé.
     *
     * @return array{status: string, message: string, runUid?: int, finalStatus?: string}
     */
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
            $this->runStorage->updateRunStatus($runUid, $exactSyncGuard['status']);
            $this->writeReport($runUid, $exactSyncGuard['status'], $pendingChunks, $processingChunks, $doneChunks, $failedChunks);

            return [
                'status'  => 'blocked',
                'runUid'  => $runUid,
                'message' => $exactSyncGuard['message'],
            ];
        }

        // Recharger le run pour avoir les compteurs à jour
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

        return [
            'status'      => 'finalized',
            'runUid'      => $runUid,
            'finalStatus' => $finalStatus,
            'message'     => sprintf('Run #%d finalisé avec le statut "%s".%s', $runUid, $finalStatus, $exactSyncMessage),
        ];
    }

    /**
     * Vérifie les garde-fous avant d'exécuter la synchronisation exacte.
     *
     * Note : le contrôle de seuil (exactSyncMaxUnsubscribeCount) est désormais effectué
     * dans ExactSyncService::executeForRun(), APRÈS le calcul réel du nombre de contacts
     * manquants, ce qui garantit un blocage fiable. Le contrôle ici se limitait à
     * unsubscribe_planned=0 (toujours faux) et a donc été supprimé.
     *
     * @param array<string, mixed> $run
     * @return array{status: string, message: string}|null null si tout est OK
     */
    private function checkExactSyncGuard(array $run): ?array
    {
        if (((int)($run['exact_sync'] ?? 0)) !== 1) {
            return null;
        }

        $settings            = $this->getExtSettings();
        $requireConfirmation = ((int)($settings['exactSyncRequireConfirmation'] ?? 1)) === 1;

        if ($requireConfirmation && ((int)($run['exact_sync_confirmed'] ?? 0)) !== 1) {
            return [
                'status'  => 'blocked_confirmation',
                'message' => sprintf(
                    'Run #%d bloqué : confirmation manuelle requise (`cyberimpact:finaliser-run --confirm-run=%d`).',
                    (int)$run['uid'],
                    (int)$run['uid']
                ),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function determineFinalStatus(array $run, int $failedChunks): string
    {
        if ($failedChunks > 0) {
            return 'failed';
        }

        if ((int)($run['upsert_failed'] ?? 0) > 0 || (int)($run['unsubscribe_failed'] ?? 0) > 0) {
            return 'completed_with_errors';
        }

        return 'completed';
    }

    private function writeReport(
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
        $errors        = $this->errorStorage->findErrorsByRunUid($runUid, 1000);
        $summary       = [
            'total_rows'           => (int)($run['total_rows'] ?? 0),
            'processed_rows'       => (int)($run['processed_rows'] ?? 0),
            'upsert_ok'            => (int)($run['upsert_ok'] ?? 0),
            'upsert_failed'        => (int)($run['upsert_failed'] ?? 0),
            'unsubscribe_planned'  => (int)($run['unsubscribe_planned'] ?? 0),
            'unsubscribe_done'     => (int)($run['unsubscribe_done'] ?? 0),
            'unsubscribe_failed'   => (int)($run['unsubscribe_failed'] ?? 0),
            'chunks_pending'       => $pendingChunks,
            'chunks_processing'    => $processingChunks,
            'chunks_done'          => $doneChunks,
            'chunks_failed'        => $failedChunks,
            'errors_count'         => count($errors),
        ];

        $reportFileUid = $this->runReportService->writeRunCsvReport($run, $summary, $errors);
        if ($reportFileUid > 0) {
            $this->runStorage->updateReportFileUid($runUid, $reportFileUid);
        }
    }

    /**
     * @return array<string, mixed>
     */
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
