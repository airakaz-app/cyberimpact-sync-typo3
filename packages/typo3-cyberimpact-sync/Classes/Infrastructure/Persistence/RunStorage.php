<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RunStorage
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public function createRun(int $sourceFileUid, bool $exactSync): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->insert('tx_cyberimpactsync_run', [
            'pid'                  => 0,
            'tstamp'               => time(),
            'crdate'               => time(),
            'status'               => 'queued',
            'exact_sync'           => (int)$exactSync,
            'exact_sync_confirmed' => 0,
            'source_file_uid'      => $sourceFileUid,
        ]);

        return (int)$connection->lastInsertId('tx_cyberimpactsync_run');
    }

    public function findOpenRunBySourceFileUid(int $sourceFileUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_run');

        $row = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_run')
            ->where(
                $queryBuilder->expr()->eq('source_file_uid', $queryBuilder->createNamedParameter($sourceFileUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->in('status', [
                    $queryBuilder->createNamedParameter('queued'),
                    $queryBuilder->createNamedParameter('processing'),
                    $queryBuilder->createNamedParameter('finalizing'),
                ])
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    public function findNextRunToFinalize(): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_run');

        $row = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_run')
            ->where(
                $queryBuilder->expr()->in('status', [
                    $queryBuilder->createNamedParameter('processing'),
                    $queryBuilder->createNamedParameter('finalizing'),
                ])
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
    public function findRecentRuns(int $limit = 20): array
    {
        $limit = max(1, $limit);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_run');

        $rows = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_run')
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }

    public function markExactSyncConfirmed(int $runUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->update('tx_cyberimpactsync_run', [
            'exact_sync_confirmed' => 1,
            'tstamp'               => time(),
        ], ['uid' => $runUid]);
    }

    public function updateReportFileUid(int $runUid, int $reportFileUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->update('tx_cyberimpactsync_run', [
            'report_file_uid' => $reportFileUid,
            'tstamp'          => time(),
        ], ['uid' => $runUid]);
    }

    public function setUnsubscribeCounters(int $runUid, int $planned, int $done, int $failed): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->update('tx_cyberimpactsync_run', [
            'unsubscribe_planned' => $planned,
            'unsubscribe_done'    => $done,
            'unsubscribe_failed'  => $failed,
            'tstamp'              => time(),
        ], ['uid' => $runUid]);
    }

    public function updateRunStatus(int $runUid, string $status): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->update('tx_cyberimpactsync_run', [
            'status' => $status,
            'tstamp' => time(),
        ], ['uid' => $runUid]);
    }

    public function incrementProcessedCounters(int $runUid, int $processedRows, int $upsertOk, int $upsertFailed): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->executeStatement(
            'UPDATE tx_cyberimpactsync_run
             SET processed_rows = processed_rows + :processedRows,
                 upsert_ok      = upsert_ok      + :upsertOk,
                 upsert_failed  = upsert_failed  + :upsertFailed,
                 tstamp         = :timestamp
             WHERE uid = :runUid',
            [
                'processedRows' => $processedRows,
                'upsertOk'      => $upsertOk,
                'upsertFailed'  => $upsertFailed,
                'timestamp'     => time(),
                'runUid'        => $runUid,
            ],
            [
                'processedRows' => ParameterType::INTEGER,
                'upsertOk'      => ParameterType::INTEGER,
                'upsertFailed'  => ParameterType::INTEGER,
                'timestamp'     => ParameterType::INTEGER,
                'runUid'        => ParameterType::INTEGER,
            ]
        );
    }

    public function updateTotalRows(int $runUid, int $totalRows): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_run');
        $connection->update('tx_cyberimpactsync_run', [
            'total_rows' => $totalRows,
            'tstamp'     => time(),
        ], ['uid' => $runUid]);
    }

    public function findRunByUid(int $runUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_run');

        $row = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_run')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($runUid, ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findQueuedOrProcessingRuns(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_cyberimpactsync_run');

        $rows = $queryBuilder
            ->select('*')
            ->from('tx_cyberimpactsync_run')
            ->where(
                $queryBuilder->expr()->in('status', [
                    $queryBuilder->createNamedParameter('queued'),
                    $queryBuilder->createNamedParameter('processing'),
                ])
            )
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return is_array($rows) ? $rows : [];
    }
}
