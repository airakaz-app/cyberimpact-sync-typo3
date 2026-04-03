<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ErrorStorage
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public function createRunError(int $runUid, string $stage, string $code, string $message, string $payload = '', int $chunkUid = 0): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_error');
        $connection->insert('tx_cyberimpactsync_error', [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'run_uid' => $runUid,
            'chunk_uid' => $chunkUid,
            'stage' => $stage,
            'code' => $code,
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findErrorsByRunUid(int $runUid, int $limit = 500): array
    {
        if ($limit <= 0) {
            $limit = 500;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_error');
        $rows = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_error')
            ->where(
                $queryBuilder->expr()->eq('run_uid', $queryBuilder->createNamedParameter($runUid, ParameterType::INTEGER))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }
}
