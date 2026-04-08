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
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * @param array<string, mixed> $run
     * @return array{planned: int, done: int, failed: int, message: string}
     */
    public function executeForRun(array $run): array
    {
        $runUid = (int)($run['uid'] ?? 0);
        if ($runUid <= 0) {
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'Invalid run UID.'];
        }

        // Lire depuis la BD en priorité, fallback sur la configuration d'extension
        $action = $this->getMissingContactsAction();

        $chunks = $this->chunkStorage->findChunksByRunUid($runUid);
        $localEmails = $this->extractLocalEmails($chunks);
        $remoteEmailToId = $this->cyberimpactClient->fetchSubscribedContacts();

        if ($remoteEmailToId === []) {
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'No remote subscribed contacts found.'];
        }

        $missingRemoteEmails = array_values(array_diff(array_keys($remoteEmailToId), $localEmails));
        $planned = count($missingRemoteEmails);

        if ($planned === 0) {
            $this->runStorage->setUnsubscribeCounters($runUid, 0, 0, 0);
            return ['planned' => 0, 'done' => 0, 'failed' => 0, 'message' => 'No missing contacts to sync.'];
        }

        if (((int)($run['dry_run'] ?? 1)) === 1) {
            $this->runStorage->setUnsubscribeCounters($runUid, $planned, $planned, 0);
            return ['planned' => $planned, 'done' => $planned, 'failed' => 0, 'message' => 'Dry-run exact sync simulated.'];
        }

        if ($action === 'delete') {
            $ids = [];
            foreach ($missingRemoteEmails as $email) {
                $memberId = $remoteEmailToId[$email] ?? null;
                $ids[] = $memberId !== null ? $memberId : $email;
            }

            $result = $this->cyberimpactClient->deleteMembers($ids);
        } else {
            $result = $this->cyberimpactClient->unsubscribeMembers($missingRemoteEmails);
        }

        $done = (int)$result['ok'];
        $failed = (int)$result['failed'];

        $this->runStorage->setUnsubscribeCounters($runUid, $planned, $done, $failed);

        return [
            'planned' => $planned,
            'done' => $done,
            'failed' => $failed,
            'message' => (string)$result['message'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, string>
     */
    private function extractLocalEmails(array $chunks): array
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
                if ($email === '') {
                    continue;
                }

                $emails[$email] = $email;
            }
        }

        return array_values($emails);
    }

    /**
     * Récupère l'action exactSync pour les contacts manquants.
     * Priorité: BD > Configuration d'extension > Défaut 'unsubscribe'
     */
    private function getMissingContactsAction(): string
    {
        try {
            // Essayer de lire depuis la BD
            $settings = ImportSettingsRepository::make()->findFirst();
            $bdAction = $settings->getMissingContactsAction();
            if (!empty($bdAction) && in_array($bdAction, ['unsubscribe', 'delete'], true)) {
                return $bdAction;
            }
        } catch (\Throwable) {
            // Continuer au fallback
        }

        // Fallback: Configuration d'extension
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            $configAction = $settings['exactSyncMissingContactsAction'] ?? null;
            if (!empty($configAction) && in_array($configAction, ['unsubscribe', 'delete'], true)) {
                return (string)$configAction;
            }
        } catch (\Throwable) {
            // Continuer au défaut
        }

        // Défaut
        return 'unsubscribe';
    }

}
