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
