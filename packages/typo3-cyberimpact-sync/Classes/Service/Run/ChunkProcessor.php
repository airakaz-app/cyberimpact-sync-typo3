<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;

final class ChunkProcessor
{
    public function __construct(
        private readonly ChunkStorage $chunkStorage,
        private readonly RunStorage $runStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly CyberimpactClient $cyberimpactClient,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunFinalizer $runFinalizer,
    ) {
    }

    /**
     * Traite le prochain chunk en attente (toutes runs confondues).
     * Utilisé par le scheduler et la commande CLI `cyberimpact:traiter-chunk`.
     *
     * @return bool true si un chunk a été traité, false s'il n'y en avait aucun
     */
    public function processNextPendingChunk(): bool
    {
        $chunk = $this->chunkStorage->findNextPendingChunk();
        if ($chunk === null) {
            return false;
        }

        $chunkUid = (int)$chunk['uid'];
        $runUid   = (int)$chunk['run_uid'];
        $attemptCount = (int)($chunk['attempt_count'] ?? 0);
        $maxAttempts = 5; // Limite de 5 tentatives pour éviter les blocages infinis

        // Si le chunk a trop été tenté, le marquer comme échoué pour éviter un blocage infini
        if ($attemptCount >= $maxAttempts) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'failed');
            $this->errorStorage->createRunError(
                $runUid,
                'process',
                'max_attempts_exceeded',
                sprintf('Chunk échoué : %d tentatives atteint le maximum', $attemptCount),
                '',
                $chunkUid
            );
            return true;
        }

        if (!$this->chunkStorage->claimChunkForProcessing($chunkUid)) {
            // Un autre processus a pris ce chunk en charge
            return true;
        }

        $this->runStorage->updateRunStatus($runUid, 'processing');
        $this->processChunk($chunkUid, $runUid, $chunk);

        return true;
    }

    /**
     * Traite UN seul chunk en attente pour un run, puis retourne la progression.
     * Utilisé par le polling JS (processNextChunk).
     *
     * @return array{done: bool, status: string, processed: int, total: int}
     */
    public function processOneChunkForRun(int $runUid): array
    {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) {
            throw new \InvalidArgumentException(sprintf('Run #%d introuvable.', $runUid));
        }

        $runStatus = (string)($run['status'] ?? '');
        if ($runStatus === 'queued') {
            $this->runStorage->updateRunStatus($runUid, 'processing');
        } elseif ($runStatus !== 'processing') {
            throw new \InvalidArgumentException(
                sprintf('Run #%d ne peut pas être traité (statut : %s).', $runUid, $runStatus)
            );
        }

        // 1 requête GROUP BY au lieu de 3 COUNT séparés
        $counts    = $this->chunkStorage->countChunksByRunGrouped($runUid);
        $total     = $counts['total'];
        $processed = $counts['done'] + $counts['failed'];

        $chunk = $this->chunkStorage->findNextPendingChunkForRun($runUid);

        if ($chunk === null) {
            $this->runFinalizer->finalizeNextRun();
            $updatedRun = $this->runStorage->findRunByUid($runUid);
            return [
                'done'      => true,
                'status'    => (string)($updatedRun['status'] ?? 'completed'),
                'processed' => $processed,
                'total'     => $total,
            ];
        }

        $chunkUid = (int)$chunk['uid'];
        if ($this->chunkStorage->claimChunkForProcessing($chunkUid)) {
            $this->processChunk($chunkUid, $runUid, $chunk);
            // Re-count après traitement (1 requête)
            $counts    = $this->chunkStorage->countChunksByRunGrouped($runUid);
            $processed = $counts['done'] + $counts['failed'];
        }

        return [
            'done'      => false,
            'status'    => 'processing',
            'processed' => $processed,
            'total'     => $total,
        ];
    }

    /**
     * Traite tous les chunks en attente pour un run spécifique, puis finalise.
     * Utilisé par le déclenchement manuel depuis le backend.
     */
    public function processRunChunks(int $runUid): void
    {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) {
            throw new \InvalidArgumentException(sprintf('Run #%d introuvable.', $runUid));
        }

        if ($run['status'] !== 'queued') {
            throw new \InvalidArgumentException(
                sprintf('Run #%d n\'est pas en attente (statut : %s).', $runUid, $run['status'])
            );
        }

        $this->runStorage->updateRunStatus($runUid, 'processing');

        foreach ($this->chunkStorage->findChunksByRunUid($runUid) as $chunk) {
            if ($chunk['status'] !== 'pending') {
                continue;
            }

            $chunkUid = (int)$chunk['uid'];
            if (!$this->chunkStorage->claimChunkForProcessing($chunkUid)) {
                continue;
            }

            $this->processChunk($chunkUid, $runUid, $chunk);
        }

        $this->runFinalizer->finalizeNextRun();
    }

    /**
     * Traitement effectif d'un chunk : appel API Cyberimpact et mise à jour des compteurs.
     *
     * @param array<string, mixed> $chunk
     */
    private function processChunk(int $chunkUid, int $runUid, array $chunk): void
    {
        $contacts = $this->decodeContacts((string)($chunk['payload_json'] ?? '[]'));
        if ($contacts === []) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            return;
        }

        $groups = $this->resolveGroups();

        try {
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
            return;
        }

        $this->runStorage->incrementProcessedCounters(
            $runUid,
            count($contacts),
            (int)$result['upsertOk'],
            (int)$result['upsertFailed']
        );

        if ((bool)$result['ok']) {
            $this->chunkStorage->updateChunkStatus($chunkUid, 'done');
            return;
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
    }

    /**
     * Retourne le tableau d'IDs de groupes configurés (vide = pas d'affectation de groupe).
     *
     * @return array<int, int>
     */
    private function resolveGroups(): array
    {
        $groupId = $this->importSettingsRepository->findFirst()->getSelectedGroupId();
        return ($groupId !== null && $groupId > 0) ? [$groupId] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeContacts(string $payloadJson): array
    {
        $decoded = json_decode($payloadJson, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
