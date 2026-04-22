<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ExactSyncService
{
    public function __construct(
        private readonly ChunkStorage $chunkStorage,
        private readonly RunStorage $runStorage,
        private readonly CyberimpactClient $cyberimpactClient,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
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

        $settings  = $this->getExtSettings();
        $chunkSize = max(1, (int)($settings['chunkSize'] ?? 500));
        $maxCount  = max(1, (int)($settings['exactSyncMaxUnsubscribeCount'] ?? 1000));

        $action          = $this->importSettingsRepository->findFirst()->getMissingContactsAction();
        $chunks          = $this->chunkStorage->findChunksByRunUid($runUid);
        $localEmails     = $this->extractLocalEmailsFromChunks($chunks);
        $remoteEmailToId = $this->cyberimpactClient->fetchSubscribedContacts();

        if ($remoteEmailToId === []) {
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'Aucun contact abonné actif trouvé dans Cyberimpact.'];
        }

        // Contacts abonnés actifs absents du fichier importé → à traiter
        $missingEmails = array_values(array_diff(array_keys($remoteEmailToId), $localEmails));
        $planned       = count($missingEmails);

        if ($planned === 0) {
            $this->runStorage->setUnsubscribeCounters($runUid, 0, 0, 0);
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'Aucun contact manquant à synchroniser.'];
        }

        // Sécurité : bloquer si le nombre dépasse le seuil configuré
        if ($planned > $maxCount) {
            $this->runStorage->setUnsubscribeCounters($runUid, $planned, 0, 0);
            return [
                'planned' => $planned,
                'done'    => 0,
                'failed'  => 0,
                'message' => sprintf(
                    'Bloqué : %d contacts à traiter dépasse le seuil de sécurité (%d). '
                    . 'Augmentez exactSyncMaxUnsubscribeCount si intentionnel.',
                    $planned,
                    $maxCount
                ),
            ];
        }

        // Traitement chunk par chunk pour éviter les batchs massifs
        $emailChunks = array_chunk($missingEmails, $chunkSize);
        $totalDone   = 0;
        $totalFailed = 0;
        $lastMessage = '';

        foreach ($emailChunks as $emailChunk) {
            if ($action === 'delete') {
                $ids = [];
                foreach ($emailChunk as $email) {
                    $memberId = $remoteEmailToId[$email] ?? null;
                    $ids[]    = $memberId !== null ? (int)$memberId : $email;
                }
                $result = $this->cyberimpactClient->deleteMembers($ids);
            } else {
                $result = $this->cyberimpactClient->unsubscribeMembers($emailChunk);
            }

            $totalDone   += (int)$result['ok'];
            $totalFailed += (int)$result['failed'];
            $lastMessage  = (string)$result['message'];

            // Mise à jour progressive des compteurs
            $this->runStorage->setUnsubscribeCounters($runUid, $planned, $totalDone, $totalFailed);
        }

        return [
            'planned' => $planned,
            'done'    => $totalDone,
            'failed'  => $totalFailed,
            'message' => $lastMessage,
        ];
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
