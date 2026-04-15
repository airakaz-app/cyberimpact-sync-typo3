<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;

final class ChunkProcessor
{
    public function __construct(
        private readonly ChunkStorage $chunkStorage,
        private readonly RunStorage $runStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly CyberimpactClient $cyberimpactClient,
        private readonly RunFinalizer $runFinalizer,
    ) {
    }

    public function processNextPendingChunk(): bool
    {
        $chunk = $this->chunkStorage->findNextPendingChunk();
        if ($chunk === null) {
            return false;
        }

        $chunkUid = (int)$chunk['uid'];
        $runUid = (int)$chunk['run_uid'];

        if ($this->chunkStorage->claimChunkForProcessing($chunkUid) === false) {
            return true;
        }

        $this->runStorage->updateRunStatus($runUid, 'processing');

        $contacts = $this->decodeContacts((string)($chunk['payload_json'] ?? '[]'));
        $processedCount = count($contacts);

        if ($processedCount === 0) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            return true;
        }

        $run = $this->runStorage->findRunByUid($runUid);
        $isDryRun = ((int)($run['dry_run'] ?? 1)) === 1;

        if ($isDryRun) {
            $this->runStorage->incrementProcessedCounters($runUid, $processedCount, $processedCount, 0);
            $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            return true;
        }

        try {
            // Load selected group from import settings (optional)
            $importSettings = ImportSettingsRepository::make()->findFirst();
            $selectedGroupId = $importSettings?->getSelectedGroupId();

            // Pass group to upsert (if configured and > 0)
            $groups = [];
            if ($selectedGroupId !== null && $selectedGroupId > 0) {
                $groups = [(int)$selectedGroupId];
            }

            $result = $this->cyberimpactClient->upsertMembers($contacts, $groups);
        } catch (\Throwable $exception) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'failed');
            $this->errorStorage->createRunError(
                $runUid,
                'upsert',
                'http_exception',
                $exception->getMessage(),
                '',
                $chunkUid
            );
            return true;
        }

        $this->runStorage->incrementProcessedCounters(
            $runUid,
            $processedCount,
            (int)$result['upsertOk'],
            (int)$result['upsertFailed']
        );

        if ((bool)$result['ok'] === true) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            return true;
        }

        $this->chunkStorage->updateChunkStatus($chunkUid, 'failed');
        $this->errorStorage->createRunError(
            $runUid,
            'upsert',
            'upsert_failed',
            (string)$result['message'],
            json_encode($contacts, JSON_UNESCAPED_UNICODE) ?: '',
            $chunkUid
        );

        return true;
    }

    /**
     * Traite tous les chunks en attente pour un run spécifique
     */
    public function processRunChunks(int $runUid): void
    {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) {
            throw new \InvalidArgumentException("Run #$runUid not found");
        }

        if ($run['status'] !== 'queued') {
            throw new \InvalidArgumentException("Run #$runUid is not in queued status (current: {$run['status']})");
        }

        // Marquer le run comme en cours de traitement
        $this->runStorage->updateRunStatus($runUid, 'processing');

        // Traiter tous les chunks du run
        $chunks = $this->chunkStorage->findChunksByRunUid($runUid);
        foreach ($chunks as $chunk) {
            if ($chunk['status'] !== 'pending') {
                continue; // Ne traiter que les chunks en attente
            }

            $chunkUid = (int)$chunk['uid'];

            // Revendiquer le chunk pour traitement
            if ($this->chunkStorage->claimChunkForProcessing($chunkUid) === false) {
                continue; // Chunk déjà pris par un autre processus
            }

            $contacts = $this->decodeContacts((string)($chunk['payload_json'] ?? '[]'));
            $processedCount = count($contacts);

            if ($processedCount === 0) {
                $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
                continue;
            }

            $isDryRun = ((int)($run['dry_run'] ?? 1)) === 1;

            if ($isDryRun) {
                $this->runStorage->incrementProcessedCounters($runUid, $processedCount, $processedCount, 0);
                $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
                continue;
            }

            try {
                // Load selected group from import settings (optional)
                $importSettings = ImportSettingsRepository::make()->findFirst();
                $selectedGroupId = $importSettings?->getSelectedGroupId();

                // Pass group to upsert (if configured and > 0)
                $groups = [];
                if ($selectedGroupId !== null && $selectedGroupId > 0) {
                    $groups = [(int)$selectedGroupId];
                }

                $result = $this->cyberimpactClient->upsertMembers($contacts, $groups);
            } catch (\Throwable $exception) {
                $this->chunkStorage->updateChunkStatus($chunkUid, 'failed');
                $this->errorStorage->createRunError(
                    $runUid,
                    'upsert',
                    'http_exception',
                    $exception->getMessage(),
                    '',
                    $chunkUid
                );
                continue;
            }

            $this->runStorage->incrementProcessedCounters(
                $runUid,
                $processedCount,
                (int)$result['upsertOk'],
                (int)$result['upsertFailed']
            );

            if ((bool)$result['ok'] === true) {
                $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            } else {
                $this->chunkStorage->updateChunkStatus($chunkUid, 'failed');
                $this->errorStorage->createRunError(
                    $runUid,
                    'upsert',
                    'upsert_failed',
                    (string)$result['message'],
                    json_encode($contacts, JSON_UNESCAPED_UNICODE) ?: '',
                    $chunkUid
                );
            }
        }

        // Tous les chunks ont été traités
        // Maintenant finaliser le run immédiatement
        $this->runFinalizer->finalizeNextRun();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeContacts(string $payloadJson): array
    {
        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }
}
