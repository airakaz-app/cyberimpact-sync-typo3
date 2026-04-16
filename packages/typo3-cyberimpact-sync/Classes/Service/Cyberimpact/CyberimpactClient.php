<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Cyberimpact;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class CyberimpactClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImportSettingsRepository $importSettingsRepository,
    ) {
    }

    /**
     * Vérifie la connexion à l'API Cyberimpact.
     *
     * @param string|null $customToken Token à tester ; si absent, utilise celui enregistré en BD.
     * @return array{ok: bool, statusCode: int, message: string, ping?: string, username?: string, email?: string, account?: string}
     */
    public function checkConnection(?string $customToken = null): array
    {
        $ctx = $this->buildRequestContext($customToken);
        if ($ctx === null) {
            return ['ok' => false, 'statusCode' => 0, 'message' => 'Token manquant.'];
        }

        $response   = $this->requestWithRetry(
            $ctx['baseUrl'] . '/ping',
            'GET',
            ['headers' => $this->buildHeaders($ctx['token'], 'check_connection'), 'timeout' => $ctx['timeout']],
            $ctx['settings']
        );
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $payload = json_decode((string)$response->getBody(), true);
            if (is_array($payload) && ($payload['ping'] ?? '') === 'success') {
                return [
                    'ok'         => true,
                    'statusCode' => $statusCode,
                    'message'    => 'Connexion Cyberimpact OK.',
                    'ping'       => $payload['ping'],
                    'username'   => $payload['username'] ?? '',
                    'email'      => $payload['email']    ?? '',
                    'account'    => $payload['account']  ?? '',
                ];
            }
        }

        return [
            'ok'         => false,
            'statusCode' => $statusCode,
            'message'    => $statusCode >= 200 && $statusCode < 300
                ? 'Réponse invalide de l\'API Cyberimpact.'
                : sprintf('Connexion échouée (HTTP %d).', $statusCode),
        ];
    }

    /**
     * Envoie un batch d'ajout/mise-à-jour de membres vers Cyberimpact.
     *
     * @param array<int, array<string, mixed>> $contacts
     * @param array<int, int>                  $groups   IDs de groupes (optionnel)
     * @return array{ok: bool, statusCode: int, upsertOk: int, upsertFailed: int, message: string}
     */
    public function upsertMembers(array $contacts, array $groups = []): array
    {
        $ctx = $this->buildRequestContext();
        if ($ctx === null) {
            return ['ok' => false, 'statusCode' => 0, 'upsertOk' => 0, 'upsertFailed' => count($contacts), 'message' => 'Token manquant.'];
        }

        if ($contacts === []) {
            return ['ok' => true, 'statusCode' => 200, 'upsertOk' => 0, 'upsertFailed' => 0, 'message' => 'Aucun contact à envoyer.'];
        }

        $consentProof = $this->resolveConsentProof();
        $payload      = [
            'batchType'           => 'addMembers',
            'relationType'        => 'express-consent',
            'defaultConsentDate'  => date('Y-m-d'),
            'defaultConsentProof' => $consentProof,
            'members'             => $contacts,
        ];

        if ($groups !== []) {
            $payload['groups'] = array_values(array_map('intval', $groups));
        }

        $response   = $this->requestWithRetry(
            $ctx['baseUrl'] . '/batches',
            'POST',
            ['headers' => $this->buildHeaders($ctx['token'], 'upsert_members'), 'body' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'timeout' => $ctx['timeout']],
            $ctx['settings']
        );
        $statusCode = $response->getStatusCode();
        $ok         = $statusCode >= 200 && $statusCode < 300;

        if ($ok) {
            $batchId = $this->extractBatchId((string)$response->getBody());
            if ($batchId > 0) {
                $polled = $this->pollBatchResult($batchId, $ctx);
                return [
                    'ok'           => $polled['failed'] === 0,
                    'statusCode'   => $statusCode,
                    'upsertOk'     => $polled['ok'],
                    'upsertFailed' => $polled['failed'],
                    'message'      => 'Batch upsert terminé.',
                ];
            }
        }

        return [
            'ok'           => $ok,
            'statusCode'   => $statusCode,
            'upsertOk'     => $ok ? count($contacts) : 0,
            'upsertFailed' => $ok ? 0 : count($contacts),
            'message'      => $ok ? 'Batch upsert envoyé.' : sprintf('Batch upsert échoué (HTTP %d).', $statusCode),
        ];
    }

    /**
     * Récupère tous les contacts abonnés sous forme email → memberId.
     *
     * @return array<string, int|null>
     */
    public function fetchSubscribedContacts(): array
    {
        $ctx = $this->buildRequestContext();
        if ($ctx === null) {
            return [];
        }

        $page     = 1;
        $limit    = max(1, (int)($ctx['settings']['membersPerPage']  ?? 500));
        $maxPages = max(1, (int)($ctx['settings']['membersMaxPages'] ?? 100));
        $contacts = [];

        do {
            $response = $this->requestWithRetry(
                $ctx['baseUrl'] . '/members',
                'GET',
                [
                    'headers' => $this->buildHeaders($ctx['token'], 'fetch_members_p' . $page),
                    'query'   => ['page' => $page, 'limit' => $limit, 'status' => 'all'],
                    'timeout' => $ctx['timeout'],
                ],
                $ctx['settings']
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                break;
            }

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                break;
            }

            foreach ((array)($payload['members'] ?? []) as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $email = strtolower(trim((string)($member['email'] ?? '')));
                if ($email !== '') {
                    $contacts[$email] = isset($member['id']) ? (int)$member['id'] : null;
                }
            }

            $memberCount = count($payload['members'] ?? []);
            $hasMore     = $this->resolveHasMore($payload, $page, $limit, $memberCount);
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $contacts;
    }

    /**
     * Désabonne une liste d'adresses e-mail.
     *
     * @param array<int, string>      $emails
     * @return array{ok: int, failed: int, message: string}
     */
    public function unsubscribeMembers(array $emails): array
    {
        $ids = $this->normalizeEmails($emails);
        if ($ids === []) {
            return ['ok' => 0, 'failed' => 0, 'message' => 'Aucune adresse valide.'];
        }

        return $this->sendBatch('unsubscribe', $ids, 'unsubscribe_members');
    }

    /**
     * Supprime une liste de membres par ID ou adresse e-mail.
     *
     * @param array<int, string|int> $ids
     * @return array{ok: int, failed: int, message: string}
     */
    public function deleteMembers(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $normalized[] = $id;
            } elseif (is_string($id) && trim($id) !== '') {
                $normalized[] = strtolower(trim($id));
            }
        }

        if ($normalized === []) {
            return ['ok' => 0, 'failed' => 0, 'message' => 'Aucun identifiant valide.'];
        }

        return $this->sendBatch('deleteMembers', $normalized, 'delete_members');
    }

    /**
     * Récupère tous les groupes du compte Cyberimpact.
     *
     * @return array<int, array{id: int, title: string, membersCount: int, isDynamic: bool}>
     */
    public function fetchGroups(): array
    {
        $ctx = $this->buildRequestContext();
        if ($ctx === null) {
            return [];
        }

        $page     = 1;
        $limit    = max(1, (int)($ctx['settings']['groupsPerPage']  ?? 500));
        $maxPages = max(1, (int)($ctx['settings']['groupsMaxPages'] ?? 100));
        $groups   = [];

        do {
            $response = $this->requestWithRetry(
                $ctx['baseUrl'] . '/groups',
                'GET',
                [
                    'headers' => $this->buildHeaders($ctx['token'], 'fetch_groups_p' . $page),
                    'query'   => ['page' => $page, 'limit' => $limit],
                    'timeout' => $ctx['timeout'],
                ],
                $ctx['settings']
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                break;
            }

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                break;
            }

            foreach ((array)($payload['groups'] ?? []) as $group) {
                if (is_array($group)) {
                    $groups[] = $group;
                }
            }

            $batchCount = count($payload['groups'] ?? []);
            $hasMore    = $this->resolveHasMore($payload, $page, $limit, $batchCount);
            $page++;
        } while ($hasMore && $page <= $maxPages);

        return $groups;
    }

    /**
     * Récupère les champs personnalisés définis sur le compte Cyberimpact.
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public function fetchCustomFields(): array
    {
        $ctx = $this->buildRequestContext();
        if ($ctx === null) {
            return [];
        }

        $response = $this->requestWithRetry(
            $ctx['baseUrl'] . '/customfields',
            'GET',
            ['headers' => $this->buildHeaders($ctx['token'], 'fetch_customfields'), 'timeout' => $ctx['timeout']],
            $ctx['settings']
        );

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return [];
        }

        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }

        return array_filter((array)($payload['customFields'] ?? []), 'is_array');
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * @param array<int, string|int> $ids
     * @return array{ok: int, failed: int, message: string}
     */
    private function sendBatch(string $batchType, array $ids, string $operation): array
    {
        $ctx = $this->buildRequestContext();
        if ($ctx === null) {
            return ['ok' => 0, 'failed' => count($ids), 'message' => 'Token manquant.'];
        }

        $response   = $this->requestWithRetry(
            $ctx['baseUrl'] . '/batches',
            'POST',
            [
                'headers' => $this->buildHeaders($ctx['token'], $operation),
                'body'    => json_encode(['batchType' => $batchType, 'ids' => array_values($ids)], JSON_UNESCAPED_UNICODE),
                'timeout' => $ctx['timeout'],
            ],
            $ctx['settings']
        );
        $status     = $response->getStatusCode();
        $ok         = $status >= 200 && $status < 300;

        if ($ok) {
            $batchId = $this->extractBatchId((string)$response->getBody());
            if ($batchId > 0) {
                $polled = $this->pollBatchResult($batchId, $ctx);
                return ['ok' => $polled['ok'], 'failed' => $polled['failed'], 'message' => $operation . ' terminé.'];
            }
        }

        return [
            'ok'      => $ok ? count($ids) : 0,
            'failed'  => $ok ? 0 : count($ids),
            'message' => $ok ? $operation . ' envoyé.' : sprintf('%s échoué (HTTP %d).', $operation, $status),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveHasMore(array $payload, int $page, int $perPage, int $batchCount): bool
    {
        $meta     = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (array_key_exists('hasMore', $meta)) {
            return (bool)$meta['hasMore'];
        }

        $lastPage = (int)($meta['lastPage'] ?? $meta['last_page'] ?? $meta['totalPages'] ?? 0);
        if ($lastPage > 0) {
            return $page < $lastPage;
        }

        return $batchCount >= $perPage;
    }

    /**
     * @param array<int, string> $emails
     * @return array<int, string>
     */
    private function normalizeEmails(array $emails): array
    {
        $normalized = [];
        foreach ($emails as $email) {
            $value = strtolower(trim($email));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * Exécute une requête HTTP avec retry exponentiel sur 429 et 5xx.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $settings
     */
    private function requestWithRetry(string $url, string $method, array $options, array $settings): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts    = max(1, (int)($settings['apiRetryMaxAttempts'] ?? 3));
        $initialDelayMs = max(0, (int)($settings['apiRetryInitialDelayMs'] ?? 250));
        $lastException  = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->requestFactory->request($url, $method, $options);
                $status   = $response->getStatusCode();

                if (($status === 429 || $status >= 500) && $attempt < $maxAttempts) {
                    $delayMs = min($initialDelayMs * (2 ** ($attempt - 1)), 30000);
                    if ($delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                    continue;
                }

                return $response;
            } catch (\Throwable $exception) {
                $lastException = $exception;
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }
                $delayMs = $initialDelayMs * (2 ** ($attempt - 1));
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Échec de la requête Cyberimpact.');
    }

    private function extractBatchId(string $body): int
    {
        $payload = json_decode($body, true);
        return is_array($payload) && isset($payload['id']) ? (int)$payload['id'] : 0;
    }

    /**
     * @param array{settings: array<string, mixed>, baseUrl: string, timeout: float, token: string} $ctx
     * @return array{ok: int, failed: int}
     */
    private function pollBatchResult(int $batchId, array $ctx): array
    {
        $settings       = $ctx['settings'];
        $maxAttempts    = max(1, (int)($settings['batchPollMaxAttempts']    ?? 30));
        $intervalMs     = max(100, (int)($settings['batchPollIntervalMs']   ?? 1000));
        $initialSleepMs = max(0, (int)($settings['batchPollInitialDelayMs'] ?? 1000));

        if ($initialSleepMs > 0) {
            usleep($initialSleepMs * 1000);
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->requestWithRetry(
                $ctx['baseUrl'] . '/batches/' . $batchId,
                'GET',
                ['headers' => $this->buildHeaders($ctx['token'], 'poll_batch_' . $batchId), 'timeout' => $ctx['timeout']],
                $settings
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                break;
            }

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                break;
            }

            $status = strtoupper((string)($payload['status'] ?? ''));
            if ($status === 'COMPLETED' || $status === 'DONE') {
                $result    = is_array($payload['result'] ?? null) ? $payload['result'] : [];
                $successes = is_array($result['successes'] ?? null) ? $result['successes'] : [];
                $errors    = is_array($result['errors']    ?? null) ? $result['errors']    : [];

                return ['ok' => count($successes), 'failed' => count($errors)];
            }

            usleep($intervalMs * 1000);
        }

        return ['ok' => 0, 'failed' => 0];
    }

    /**
     * Construit le contexte de requête (token, URL de base, paramètres).
     *
     * @param string|null $customToken Token à forcer (test de connexion).
     * @return array{settings: array<string, mixed>, baseUrl: string, timeout: float, token: string}|null
     */
    private function buildRequestContext(?string $customToken = null): ?array
    {
        $settings = $this->getExtSettings();
        $token    = $customToken ?? $this->resolveToken($settings);

        if ($token === '') {
            return null;
        }

        return [
            'settings' => $settings,
            'baseUrl'  => rtrim((string)($settings['apiBaseUrl'] ?? 'https://api.cyberimpact.com'), '/'),
            'timeout'  => (float)($settings['apiTimeout'] ?? 15),
            'token'    => $token,
        ];
    }

    /**
     * Résout le token : BD → variable d'environnement → configuration d'extension.
     *
     * @param array<string, mixed> $settings
     */
    private function resolveToken(array $settings): string
    {
        try {
            $dbToken = (string)($this->importSettingsRepository->findFirst()->getCyberimpactToken() ?? '');
            if (trim($dbToken) !== '') {
                return trim($dbToken);
            }
        } catch (\Throwable) {
        }

        $envToken = getenv('CYBERIMPACT_TOKEN');
        if (is_string($envToken) && trim($envToken) !== '') {
            return trim($envToken);
        }

        return trim((string)($settings['apiToken'] ?? ''));
    }

    /**
     * Retourne la preuve de consentement (BD → valeur par défaut).
     */
    private function resolveConsentProof(): string
    {
        try {
            $proof = $this->importSettingsRepository->findFirst()->getDefaultConsentProof();
            if ($proof !== null && trim($proof) !== '') {
                return trim($proof);
            }
        } catch (\Throwable) {
        }

        return 'Import automatique via API';
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $token, string $operation): array
    {
        return [
            'Authorization'    => 'Bearer ' . $token,
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
            'X-Correlation-Id' => sprintf(
                'cyberimpact-sync-%s-%s-%s',
                $operation,
                date('YmdHis'),
                bin2hex(random_bytes(4))
            ),
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
}
