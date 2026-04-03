<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Cyberimpact;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class CyberimpactClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * Test la connexion à l'API Cyberimpact.
     * 
     * @param string|null $customToken Token personnalisé à tester (optionnel). Si absent, utilise le token configuré.
     * @return array{ok: bool, statusCode: int, message: string, ping?: string, username?: string, email?: string, account?: string}
     */
    public function checkConnection(?string $customToken = null): array
    {
        $request = $this->buildRequestContext($customToken);
        if ($request === null) {
            return [
                'ok' => false,
                'statusCode' => 0,
                'message' => 'Missing token. Configure CYBERIMPACT_TOKEN or extension setting apiToken.',
            ];
        }

        $response = $this->requestWithRetry(
            url: $request['baseUrl'] . '/ping',
            method: 'GET',
            options: [
                'headers' => $this->buildHeaders($request['token'], 'check_connection'),
                'timeout' => $request['timeout'],
            ],
            settings: $request['settings']
        );

        $statusCode = $response->getStatusCode();
        $ok = $statusCode >= 200 && $statusCode < 300;

        if ($ok) {
            $payload = json_decode((string)$response->getBody(), true);
            if (is_array($payload) && ($payload['ping'] ?? '') === 'success') {
                return [
                    'ok' => true,
                    'statusCode' => $statusCode,
                    'message' => 'Cyberimpact connection OK. Account: ' . ($payload['account'] ?? 'N/A'),
                    'ping' => $payload['ping'] ?? 'success',
                    'username' => $payload['username'] ?? '',
                    'email' => $payload['email'] ?? '',
                    'account' => $payload['account'] ?? '',
                ];
            }
        }

        return [
            'ok' => false,
            'statusCode' => $statusCode,
            'message' => $statusCode >= 200 && $statusCode < 300
                ? 'Cyberimpact connection failed: invalid response.'
                : ('Cyberimpact connection failed with status ' . $statusCode . '.'),
        ];
    }

    /**
     * @param array<int, array<string, string>> $contacts
     * @param array<int> $groups Optional array of group IDs to assign to contacts
     * @return array{ok: bool, statusCode: int, upsertOk: int, upsertFailed: int, message: string}
     */
    public function upsertMembers(array $contacts, array $groups = []): array
    {
        $request = $this->buildRequestContext();
        if ($request === null) {
            return [
                'ok' => false,
                'statusCode' => 0,
                'upsertOk' => 0,
                'upsertFailed' => count($contacts),
                'message' => 'Missing token. Configure CYBERIMPACT_TOKEN or extension setting apiToken.',
            ];
        }

        if (count($contacts) === 0) {
            return [
                'ok' => true,
                'statusCode' => 200,
                'upsertOk' => 0,
                'upsertFailed' => 0,
                'message' => 'No contacts to upsert.',
            ];
        }

        $payload = [
            'batchType' => 'addMembers',
            'relationType' => 'express-consent',
            'defaultConsentDate' => date('Y-m-d'),
            'defaultConsentProof' => 'Import automatique via API',
            'members' => $contacts,
        ];

        // Add groups array if provided (optional)
        if (count($groups) > 0) {
            $payload['groups'] = array_values(array_map('intval', $groups));
        }

        $response = $this->requestWithRetry(
            url: $request['baseUrl'] . '/batches',
            method: 'POST',
            options: [
                'headers' => $this->buildHeaders($request['token'], 'upsert_members'),
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => $request['timeout'],
            ],
            settings: $request['settings']
        );

        $statusCode = $response->getStatusCode();
        $ok = $statusCode >= 200 && $statusCode < 300;

        if ($ok) {
            $batchId = $this->extractBatchId($response);
            if ($batchId > 0) {
                $polled = $this->pollBatchResult($batchId, $request);
                return [
                    'ok' => $polled['failed'] === 0,
                    'statusCode' => $statusCode,
                    'upsertOk' => $polled['ok'],
                    'upsertFailed' => $polled['failed'],
                    'message' => 'Batch upsert polled result.',
                ];
            }
        }

        return [
            'ok' => $ok,
            'statusCode' => $statusCode,
            'upsertOk' => $ok ? count($contacts) : 0,
            'upsertFailed' => $ok ? 0 : count($contacts),
            'message' => $ok
                ? 'Batch upsert success.'
                : ('Batch upsert failed with status ' . $statusCode . '.'),
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public function fetchSubscribedContacts(): array
    {
        $request = $this->buildRequestContext();
        if ($request === null) {
            return [];
        }

        $page = 1;
        $limit = max(1, (int)($request['settings']['membersPerPage'] ?? 500));
        $maxPages = max(1, (int)($request['settings']['membersMaxPages'] ?? 100));
        $contacts = [];

        do {
            $response = $this->requestWithRetry(
                url: $request['baseUrl'] . '/members',
                method: 'GET',
                options: [
                    'headers' => $this->buildHeaders($request['token'], 'fetch_members_page_' . $page),
                    'query' => [
                        'page' => $page,
                        'limit' => $limit,
                        'status' => 'subscribed',
                    ],
                    'timeout' => $request['timeout'],
                ],
                settings: $request['settings']
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                break;
            }

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                break;
            }

            $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }

                $email = strtolower(trim((string)($member['email'] ?? '')));
                if ($email === '') {
                    continue;
                }

                $memberId = isset($member['id']) ? (int)$member['id'] : null;
                $contacts[$email] = $memberId;
            }

            $memberCount = count($members);
            $hasMore = $this->resolveHasMore($payload, $page, $limit, $memberCount);
            $page++;

            if ($page > $maxPages) {
                break;
            }
        } while ($hasMore);

        return $contacts;
    }

    /**
     * @param array<int, string> $emails
     * @return array{ok: int, failed: int, message: string}
     */
    public function unsubscribeMembers(array $emails): array
    {
        $ids = $this->normalizeEmails($emails);
        if ($ids === []) {
            return ['ok' => 0, 'failed' => 0, 'message' => 'No valid emails to unsubscribe.'];
        }

        return $this->sendBatchByIds('unsubscribe', $ids, 'unsubscribe_members');
    }

    /**
     * @param array<int, string|int> $ids
     * @return array{ok: int, failed: int, message: string}
     */
    public function deleteMembers(array $ids): array
    {
        $normalizedIds = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $normalizedIds[] = $id;
                continue;
            }

            $asEmail = is_string($id) ? strtolower(trim($id)) : '';
            if ($asEmail !== '') {
                $normalizedIds[] = $asEmail;
            }
        }

        if ($normalizedIds === []) {
            return ['ok' => 0, 'failed' => 0, 'message' => 'No valid ids/emails to delete.'];
        }

        return $this->sendBatchByIds('deleteMembers', $normalizedIds, 'delete_members');
    }

    /**
     * Fetches all groups from the Cyberimpact account.
     *
     * @return array<int, array{id: int, title: string, membersCount: int, isDynamic: bool}>
     */
    public function fetchGroups(): array
    {
        $request = $this->buildRequestContext();
        if ($request === null) {
            return [];
        }

        $page = 1;
        $limit = max(1, (int)($request['settings']['groupsPerPage'] ?? 500));
        $maxPages = max(1, (int)($request['settings']['groupsMaxPages'] ?? 100));
        $groups = [];

        do {
            $response = $this->requestWithRetry(
                url: $request['baseUrl'] . '/groups',
                method: 'GET',
                options: [
                    'headers' => $this->buildHeaders($request['token'], 'fetch_groups_page_' . $page),
                    'query' => [
                        'page' => $page,
                        'limit' => $limit,
                    ],
                    'timeout' => $request['timeout'],
                ],
                settings: $request['settings']
            );

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                break;
            }

            $payload = json_decode((string)$response->getBody(), true);
            if (!is_array($payload)) {
                break;
            }

            $batch = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
            foreach ($batch as $group) {
                if (is_array($group)) {
                    $groups[] = $group;
                }
            }

            $batchCount = count($batch);
            $hasMore = $this->resolveHasMore($payload, $page, $limit, $batchCount);
            $page++;

            if ($page > $maxPages) {
                break;
            }
        } while ($hasMore);

        return $groups;
    }

    /**
     * Fetches all custom fields defined on the Cyberimpact account.
     *
     * @return array<int, array{id: int, name: string, type: string}>
     */
    public function fetchCustomFields(): array
    {
        $request = $this->buildRequestContext();
        if ($request === null) {
            return [];
        }

        $response = $this->requestWithRetry(
            url: $request['baseUrl'] . '/customfields',
            method: 'GET',
            options: [
                'headers' => $this->buildHeaders($request['token'], 'fetch_customfields'),
                'timeout' => $request['timeout'],
            ],
            settings: $request['settings']
        );

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return [];
        }

        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload)) {
            return [];
        }

        $fields = is_array($payload['customFields'] ?? null) ? $payload['customFields'] : [];
        $result = [];

        foreach ($fields as $field) {
            if (is_array($field)) {
                $result[] = $field;
            }
        }

        return $result;
    }

    /**
     * @param array<int, string|int> $ids
     * @return array{ok: int, failed: int, message: string}
     */
    private function sendBatchByIds(string $batchType, array $ids, string $operation): array
    {
        $request = $this->buildRequestContext();
        if ($request === null) {
            return ['ok' => 0, 'failed' => count($ids), 'message' => 'Missing token.'];
        }

        $payload = [
            'batchType' => $batchType,
            'ids' => array_values($ids),
        ];

        $response = $this->requestWithRetry(
            url: $request['baseUrl'] . '/batches',
            method: 'POST',
            options: [
                'headers' => $this->buildHeaders($request['token'], $operation),
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => $request['timeout'],
            ],
            settings: $request['settings']
        );

        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status < 300;

        if ($ok) {
            $batchId = $this->extractBatchId($response);
            if ($batchId > 0) {
                $polled = $this->pollBatchResult($batchId, $request);
                return [
                    'ok' => $polled['ok'],
                    'failed' => $polled['failed'],
                    'message' => $operation . ' polled result.',
                ];
            }
        }

        return [
            'ok' => $ok ? count($ids) : 0,
            'failed' => $ok ? 0 : count($ids),
            'message' => $ok ? ($operation . ' success.') : ($operation . ' failed with status ' . $status . '.'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveHasMore(array $payload, int $page, int $perPage, int $memberCount): bool
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (array_key_exists('hasMore', $meta)) {
            return (bool)$meta['hasMore'];
        }

        $lastPage = (int)($meta['lastPage'] ?? $meta['last_page'] ?? $meta['totalPages'] ?? 0);
        if ($lastPage > 0) {
            return $page < $lastPage;
        }

        return $memberCount >= $perPage;
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
            if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function requestWithRetry(string $url, string $method, array $options, array $settings): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts = max(1, (int)($settings['apiRetryMaxAttempts'] ?? 3));
        $initialDelayMs = max(0, (int)($settings['apiRetryInitialDelayMs'] ?? 250));
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->requestFactory->request($url, $method, $options);
                $status = $response->getStatusCode();
                $isRetryableStatus = $status === 429 || $status >= 500;

                if ($isRetryableStatus && $attempt < $maxAttempts) {
                    $delayMs = $initialDelayMs * (2 ** ($attempt - 1));
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

        throw $lastException ?? new \RuntimeException('Cyberimpact request failed.');
    }


    private function extractBatchId(\Psr\Http\Message\ResponseInterface $response): int
    {
        $payload = json_decode((string)$response->getBody(), true);
        if (!is_array($payload)) {
            return 0;
        }

        return isset($payload['id']) ? (int)$payload['id'] : 0;
    }

    /**
     * @param array{settings: array<string, mixed>, baseUrl: string, timeout: float, token: string} $request
     * @return array{ok: int, failed: int}
     */
    private function pollBatchResult(int $batchId, array $request): array
    {
        $settings = $request['settings'];
        $maxAttempts = max(1, (int)($settings['batchPollMaxAttempts'] ?? 30));
        $intervalMs = max(100, (int)($settings['batchPollIntervalMs'] ?? 1000));
        $initialSleepMs = max(0, (int)($settings['batchPollInitialDelayMs'] ?? 1000));

        if ($initialSleepMs > 0) {
            usleep($initialSleepMs * 1000);
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->requestWithRetry(
                url: $request['baseUrl'] . '/batches/' . $batchId,
                method: 'GET',
                options: [
                    'headers' => $this->buildHeaders($request['token'], 'poll_batch_' . $batchId),
                    'timeout' => $request['timeout'],
                ],
                settings: $settings
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
                $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
                $successes = is_array($result['successes'] ?? null) ? $result['successes'] : [];
                $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

                return [
                    'ok' => count($successes),
                    'failed' => count($errors),
                ];
            }

            usleep($intervalMs * 1000);
        }

        return [
            'ok' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @return array{settings: array<string, mixed>, baseUrl: string, timeout: float, token: string}|null
     */
    /**
     * Crée le contexte de requête pour les appels API.
     * 
     * @param string|null $customToken Token personnalisé à utiliser (optionnel)
     * @return array{settings: array<string, mixed>, baseUrl: string, timeout: float, token: string}|null
     */
    private function buildRequestContext(?string $customToken = null): ?array
    {
        $settings = $this->getSettings();
        
        // Utiliser le token personnalisé si fourni, sinon résoudre depuis la config
        $token = $customToken ?? $this->resolveToken($settings);
        
        if ($token === '') {
            return null;
        }

        $timeout = (float)($settings['apiTimeout'] ?? 15);

        return [
            'settings' => $settings,
            'baseUrl' => rtrim((string)($settings['apiBaseUrl'] ?? 'https://api.cyberimpact.com'), '/'),
            'timeout' => $timeout,
            'token' => $token,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $token, string $operation): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Correlation-Id' => 'cyberimpact-sync-' . $operation . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveToken(array $settings): string
    {
        $envToken = getenv('CYBERIMPACT_TOKEN');
        if (is_string($envToken) && trim($envToken) !== '') {
            return trim($envToken);
        }

        $configToken = (string)($settings['apiToken'] ?? '');
        return trim($configToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
