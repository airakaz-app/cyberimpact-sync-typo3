<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;

final class RunManager
{
    public function __construct(
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
    ) {
    }

    public function queueFromFalFile(int $sourceFileUid, bool $exactSync = false): ?int
    {
        if ($this->runStorage->findOpenRunBySourceFileUid($sourceFileUid) !== null) {
            return null;
        }

        return $this->runStorage->createRun($sourceFileUid, $exactSync);
    }

    public function updateRunTotalRows(int $runUid, int $totalRows): void
    {
        $this->runStorage->updateTotalRows($runUid, $totalRows);
    }

    /**
     * Déduplique par email, découpe en chunks et les persiste.
     *
     * @param array<int, array<string, mixed>> $contacts
     */
    public function createChunksFromContacts(int $runUid, array $contacts, int $chunkSize = 500): int
    {
        $chunkSize = max(1, $chunkSize);

        // Déduplique par email (dernière occurrence gagne)
        $byEmail = [];
        foreach ($contacts as $contact) {
            $email = strtolower(trim((string)($contact['email'] ?? '')));
            if ($email !== '') {
                $byEmail[$email] = $contact;
            }
        }

        $chunks = array_chunk(array_values($byEmail), $chunkSize);
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
