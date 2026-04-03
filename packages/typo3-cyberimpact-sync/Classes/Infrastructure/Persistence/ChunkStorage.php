<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ChunkStorage
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * @param array<int, array<string, string>> $contacts
     */
    public function createChunkForRun(int $runUid, int $chunkIndex, array $contacts): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_chunk');
        $connection->insert('tx_cyberimpactsync_chunk', [
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'run_uid' => $runUid,
            'chunk_index' => $chunkIndex,
            'status' => 'pending',
            'attempt_count' => 0,
            'payload_file_uid' => 0,
            'payload_json' => json_encode($contacts, JSON_UNESCAPED_UNICODE),
        ]);

        return (int)$connection->lastInsertId('tx_cyberimpactsync_chunk');
    }

    public function findNextPendingChunk(): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_chunk');
        $row = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_chunk')
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('pending'))
            )
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findChunksByRunUid(int $runUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_chunk');
        $rows = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_chunk')
            ->where(
                $queryBuilder->expr()->eq('run_uid', $queryBuilder->createNamedParameter($runUid, ParameterType::INTEGER))
            )
            ->orderBy('chunk_index', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }


    public function claimChunkForProcessing(int $chunkUid): bool
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_chunk');
        $affected = $connection->executeStatement(
            'UPDATE tx_cyberimpactsync_chunk
             SET status = :processing,
                 attempt_count = attempt_count + 1,
                 tstamp = :timestamp
             WHERE uid = :chunkUid
               AND status = :pending',
            [
                'processing' => 'processing',
                'pending' => 'pending',
                'timestamp' => time(),
                'chunkUid' => $chunkUid,
            ],
            [
                'processing' => ParameterType::STRING,
                'pending' => ParameterType::STRING,
                'timestamp' => ParameterType::INTEGER,
                'chunkUid' => ParameterType::INTEGER,
            ]
        );

        return $affected > 0;
    }

    public function requeueStaleProcessingChunks(int $staleAfterSeconds): int
    {
        $staleAfterSeconds = max(60, $staleAfterSeconds);
        $threshold = time() - $staleAfterSeconds;

        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_chunk');

        return (int)$connection->executeStatement(
            'UPDATE tx_cyberimpactsync_chunk
             SET status = :pending,
                 tstamp = :timestamp
             WHERE status = :processing
               AND tstamp < :threshold',
            [
                'pending' => 'pending',
                'processing' => 'processing',
                'timestamp' => time(),
                'threshold' => $threshold,
            ],
            [
                'pending' => ParameterType::STRING,
                'processing' => ParameterType::STRING,
                'timestamp' => ParameterType::INTEGER,
                'threshold' => ParameterType::INTEGER,
            ]
        );
    }

    public function countChunksByRunAndStatus(int $runUid, string $status): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_chunk');

        $count = $queryBuilder
            ->count('uid')
            ->from('tx_cyberimpactsync_chunk')
            ->where(
                $queryBuilder->expr()->eq('run_uid', $queryBuilder->createNamedParameter($runUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count;
    }

    public function updateChunkStatus(int $chunkUid, string $status): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_chunk');
        $connection->update(
            'tx_cyberimpactsync_chunk',
            [
                'status' => $status,
                'tstamp' => time(),
            ],
            ['uid' => $chunkUid],
            [
                'uid' => ParameterType::INTEGER,
            ]
        );
    }
}
