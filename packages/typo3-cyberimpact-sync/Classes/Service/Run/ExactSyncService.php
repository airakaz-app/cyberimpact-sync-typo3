<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;

final class ExactSyncService
{
    public function __construct(
        private readonly ChunkStorage $chunkStorage,
        private readonly RunStorage $runStorage,
        private readonly CyberimpactClient $cyberimpactClient,
        private readonly ImportSettingsRepository $importSettingsRepository,
    ) {
    }

    /**
     * Exécute la synchronisation exacte pour un run terminé :
     * récupère tous les contacts Cyberimpact abonnés, trouve ceux absents du fichier importé
     * et leur applique l'action configurée (désabonner ou supprimer).
     *
     * @param array<string, mixed> $run
     * @return array{planned: int, done: int, failed: int, message: string}
     */
    public function executeForRun(array $run): array
    {
        $runUid = (int)($run['uid'] ?? 0);
        if ($runUid <= 0) {
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'UID de run invalide.'];
        }

        $action              = $this->importSettingsRepository->findFirst()->getMissingContactsAction();
        $chunks              = $this->chunkStorage->findChunksByRunUid($runUid);
        $localEmails         = $this->extractLocalEmailsFromChunks($chunks);
        $remoteEmailToId     = $this->cyberimpactClient->fetchSubscribedContacts();

        if ($remoteEmailToId === []) {
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'Aucun contact abonné trouvé dans Cyberimpact.'];
        }

        $missingEmails = array_values(array_diff(array_keys($remoteEmailToId), $localEmails));
        $planned       = count($missingEmails);

        if ($planned === 0) {
            $this->runStorage->setUnsubscribeCounters($runUid, 0, 0, 0);
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'Aucun contact manquant à synchroniser.'];
        }

        if ($action === 'delete') {
            $ids = [];
            foreach ($missingEmails as $email) {
                $memberId = $remoteEmailToId[$email] ?? null;
                $ids[]    = $memberId !== null ? $memberId : $email;
            }
            $result = $this->cyberimpactClient->deleteMembers($ids);
        } else {
            $result = $this->cyberimpactClient->unsubscribeMembers($missingEmails);
        }

        $done   = (int)$result['ok'];
        $failed = (int)$result['failed'];
        $this->runStorage->setUnsubscribeCounters($runUid, $planned, $done, $failed);

        return [
            'planned' => $planned,
            'done'    => $done,
            'failed'  => $failed,
            'message' => (string)$result['message'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, string>
     */
    private function extractLocalEmailsFromChunks(array $chunks): array
    {
        $emails = [];
        foreach ($chunks as $chunk) {
            $payload = json_decode((string)($chunk['payload_json'] ?? '[]'), true);
            if (!is_array($payload)) {
                continue;
            }

            foreach ($payload as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $email = strtolower(trim((string)($contact['email'] ?? '')));
                if ($email !== '') {
                    $emails[$email] = $email;
                }
            }
        }

        return array_values($emails);
    }
}
