<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;

final class RunManager
{
    public function __construct(
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
    ) {
    }

    public function createQueuedRun(int $sourceFileUid, bool $dryRun = true, bool $exactSync = false): int
    {
        return $this->runStorage->createRun($sourceFileUid, $dryRun, $exactSync);
    }

    public function queueFromFalFile(int $sourceFileUid, bool $dryRun = true, bool $exactSync = false): ?int
    {
        $existingRun = $this->runStorage->findOpenRunBySourceFileUid($sourceFileUid);
        if ($existingRun !== null) {
            return null;
        }

        return $this->runStorage->createRun($sourceFileUid, $dryRun, $exactSync);
    }

    public function updateRunTotalRows(int $runUid, int $totalRows): void
    {
        $this->runStorage->updateTotalRows($runUid, $totalRows);
    }

    /**
     * @param array<int, array<string, string>> $contacts
     */
    public function createChunksFromContacts(int $runUid, array $contacts, int $chunkSize = 500): int
    {
        if ($chunkSize <= 0) {
            $chunkSize = 500;
        }

        $contactsByEmail = [];
        foreach ($contacts as $contact) {
            $email = strtolower(trim((string)($contact['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $contactsByEmail[$email] = $contact;
        }

        $contacts = array_values($contactsByEmail);
        $chunks = array_chunk($contacts, $chunkSize);
        foreach ($chunks as $index => $chunkContacts) {
            $this->chunkStorage->createChunkForRun($runUid, $index, $chunkContacts);
        }

        return count($chunks);
    }

    public function getRunByUid(int $runUid): ?array
    {
        return $this->runStorage->findRunByUid($runUid);
    }
}
