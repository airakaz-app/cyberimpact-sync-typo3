<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Infrastructure\Persistence;

use Cyberimpact\CyberimpactSync\Domain\Model\ImportSettings;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repository for ImportSettings persistence.
 * Handles CRUD operations for import settings stored in tx_cyberimpactsync_import_settings table.
 */
final class ImportSettingsRepository
{
    private const TABLE = 'tx_cyberimpactsync_import_settings';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public static function make(): self
    {
        return GeneralUtility::makeInstance(self::class);
    }

    /**
     * Find the first ImportSettings record (typically there's only one per instance).
     * Creates a default one if none exists.
     */
    public function findFirst(): ImportSettings
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($result === false) {
            // Create default settings if none exist
            $settings = new ImportSettings();
            $settings->setPid(0);
            $this->create($settings);
            return $settings;
        }

        return $this->mapRowToEntity($result);
    }

    /**
     * Find by UID
     */
    public function findByUid(int $uid): ?ImportSettings
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = ' . $queryBuilder->createNamedParameter($uid))
            ->executeQuery()
            ->fetchAssociative();

        return $result ? $this->mapRowToEntity($result) : null;
    }

    /**
     * Find all by page ID (pid)
     */
    public function findByPid(int $pid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $results = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where('pid = ' . $queryBuilder->createNamedParameter($pid))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map([$this, 'mapRowToEntity'], $results);
    }

    /**
     * Find all settings records
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $results = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map([$this, 'mapRowToEntity'], $results);
    }

    /**
     * Create a new ImportSettings record
     */
    public function create(ImportSettings $settings): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            $this->mapEntityToRow($settings),
            ['column_mapping' => \PDO::PARAM_STR]
        );

        $uid = (int)$connection->lastInsertId();
        $settings->setUid($uid);

        return $uid;
    }

    /**
     * Update an existing ImportSettings record
     */
    public function update(ImportSettings $settings): void
    {
        if ($settings->getUid() === 0) {
            throw new \InvalidArgumentException('Cannot update ImportSettings without UID');
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            $this->mapEntityToRow($settings),
            ['uid' => $settings->getUid()],
            ['column_mapping' => \PDO::PARAM_STR]
        );
    }

    /**
     * Delete an ImportSettings record
     */
    public function delete(ImportSettings $settings): void
    {
        if ($settings->getUid() === 0) {
            throw new \InvalidArgumentException('Cannot delete ImportSettings without UID');
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(self::TABLE, ['uid' => $settings->getUid()]);
    }

    /**
     * Map database row to ImportSettings entity
     */
    private function mapRowToEntity(array $row): ImportSettings
    {
        $settings = new ImportSettings();
        $settings->setUid((int)($row['uid'] ?? 0));
        $settings->setPid((int)($row['pid'] ?? 0));
        $settings->setTstamp((int)($row['tstamp'] ?? 0));
        $settings->setCrdate((int)($row['crdate'] ?? 0));

        // Paths
        $settings->setSourcePath($row['source_path'] ?? 'incoming/');
        $settings->setArchivePath($row['archive_path'] ?? 'archive/');
        $settings->setErrorPath($row['error_path'] ?? 'errors/');

        // CRON
        $settings->setCronEnabled((bool)($row['cron_enabled'] ?? false));
        $settings->setCronMode($row['cron_mode'] ?? 'preset');
        $settings->setCronPreset($row['cron_preset'] ?? 'every15');
        $settings->setCronDailyTime($row['cron_daily_time'] ?? '09:00');
        if (!empty($row['cron_expression'])) {
            $settings->setCronExpression($row['cron_expression']);
        }

        // Cyberimpact API
        if (!empty($row['cyberimpact_token'])) {
            $settings->setCyberimpactToken($row['cyberimpact_token']);
        }
        if (!empty($row['cyberimpact_ping'])) {
            $settings->setCyberimpactPing($row['cyberimpact_ping']);
        }
        if (!empty($row['cyberimpact_username'])) {
            $settings->setCyberimpactUsername($row['cyberimpact_username']);
        }
        if (!empty($row['cyberimpact_email'])) {
            $settings->setCyberimpactEmail($row['cyberimpact_email']);
        }
        if (!empty($row['cyberimpact_account'])) {
            $settings->setCyberimpactAccount($row['cyberimpact_account']);
        }
        if (!empty($row['cyberimpact_ping_checked_at'])) {
            $settings->setCyberimpactPingCheckedAt((int)$row['cyberimpact_ping_checked_at']);
        }

        // Column Mapping
        if (!empty($row['column_mapping'])) {
            $settings->setColumnMappingJson($row['column_mapping']);
        }

        // Group Assignment
        if (!empty($row['selected_group_id'])) {
            $settings->setSelectedGroupId((int)$row['selected_group_id']);
        }

        // Sync Options
        $settings->setMissingContactsAction($row['missing_contacts_action'] ?? 'unsubscribe');
        if (!empty($row['default_consent_proof'])) {
            $settings->setDefaultConsentProof($row['default_consent_proof']);
        }

        return $settings;
    }

    /**
     * Map ImportSettings entity to database row
     */
    private function mapEntityToRow(ImportSettings $settings): array
    {
        $row = [
            'pid' => $settings->getPid(),
            'tstamp' => time(),
            'source_path' => $settings->getSourcePath(),
            'archive_path' => $settings->getArchivePath(),
            'error_path' => $settings->getErrorPath(),
            'cron_enabled' => (int)$settings->isCronEnabled(),
            'cron_mode' => $settings->getCronMode(),
            'cron_preset' => $settings->getCronPreset(),
            'cron_daily_time' => $settings->getCronDailyTime(),
            'cron_expression' => $settings->getCronExpression(),
            'cyberimpact_token' => $settings->getCyberimpactToken(),
            'cyberimpact_ping' => $settings->getCyberimpactPing(),
            'cyberimpact_username' => $settings->getCyberimpactUsername(),
            'cyberimpact_email' => $settings->getCyberimpactEmail(),
            'cyberimpact_account' => $settings->getCyberimpactAccount(),
            'cyberimpact_ping_checked_at' => $settings->getCyberimpactPingCheckedAt(),
            'column_mapping' => $settings->getColumnMappingJson(),
            'selected_group_id' => $settings->getSelectedGroupId(),
            'missing_contacts_action' => $settings->getMissingContactsAction(),
            'default_consent_proof' => $settings->getDefaultConsentProof(),
        ];

        if ($settings->getUid() > 0) {
            $row['uid'] = $settings->getUid();
        }

        return $row;
    }
}
