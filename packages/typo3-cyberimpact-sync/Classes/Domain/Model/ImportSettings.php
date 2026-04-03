<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Domain\Model;

/**
 * ImportSettings entity representing configuration for automated Excel imports.
 * Equivalent to Laravel's import_settings table.
 */
final class ImportSettings
{
    private int $uid = 0;
    private int $pid = 0;
    private int $tstamp = 0;
    private int $crdate = 0;

    // Paths
    private string $sourcePath = 'incoming/';
    private string $archivePath = 'archive/';
    private string $errorPath = 'errors/';

    // CRON Scheduling
    private bool $cronEnabled = false;
    private string $cronMode = 'preset'; // 'preset' or 'custom'
    private string $cronPreset = 'every15'; // every5, every15, hourly, daily, weekly
    private string $cronDailyTime = '09:00';
    private ?string $cronExpression = null;

    // Cyberimpact API Credentials & Account Info
    private ?string $cyberimpactToken = null;
    private ?string $cyberimpactPing = null; // 'success' on success
    private ?string $cyberimpactUsername = null;
    private ?string $cyberimpactEmail = null;
    private ?string $cyberimpactAccount = null;
    private ?int $cyberimpactPingCheckedAt = null;

    // Excel Column Mapping
    private ?array $columnMapping = null; // JSON: {"standard":{...}, "customFields":{...}}

    // Group Assignment
    private ?int $selectedGroupId = null;

    // Sync Options
    private string $missingContactsAction = 'unsubscribe'; // 'unsubscribe' or 'delete'
    private ?string $defaultConsentProof = null;

    // Getters & Setters
    public function getUid(): int
    {
        return $this->uid;
    }

    public function setUid(int $uid): self
    {
        $this->uid = $uid;
        return $this;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function setPid(int $pid): self
    {
        $this->pid = $pid;
        return $this;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function setTstamp(int $tstamp): self
    {
        $this->tstamp = $tstamp;
        return $this;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function setCrdate(int $crdate): self
    {
        $this->crdate = $crdate;
        return $this;
    }

    // Path Methods
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(string $sourcePath): self
    {
        $this->sourcePath = rtrim($sourcePath, '/') . '/';
        return $this;
    }

    public function getArchivePath(): string
    {
        return $this->archivePath;
    }

    public function setArchivePath(string $archivePath): self
    {
        $this->archivePath = rtrim($archivePath, '/') . '/';
        return $this;
    }

    public function getErrorPath(): string
    {
        return $this->errorPath;
    }

    public function setErrorPath(string $errorPath): self
    {
        $this->errorPath = rtrim($errorPath, '/') . '/';
        return $this;
    }

    // CRON Methods
    public function isCronEnabled(): bool
    {
        return $this->cronEnabled;
    }

    public function setCronEnabled(bool $cronEnabled): self
    {
        $this->cronEnabled = $cronEnabled;
        return $this;
    }

    public function getCronMode(): string
    {
        return $this->cronMode;
    }

    public function setCronMode(string $cronMode): self
    {
        if (!in_array($cronMode, ['preset', 'custom'], true)) {
            throw new \InvalidArgumentException("Invalid cron mode: {$cronMode}");
        }
        $this->cronMode = $cronMode;
        return $this;
    }

    public function getCronPreset(): string
    {
        return $this->cronPreset;
    }

    public function setCronPreset(string $cronPreset): self
    {
        if (!in_array($cronPreset, ['every5', 'every15', 'hourly', 'daily', 'weekly'], true)) {
            throw new \InvalidArgumentException("Invalid cron preset: {$cronPreset}");
        }
        $this->cronPreset = $cronPreset;
        return $this;
    }

    public function getCronDailyTime(): string
    {
        return $this->cronDailyTime;
    }

    public function setCronDailyTime(string $cronDailyTime): self
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $cronDailyTime)) {
            throw new \InvalidArgumentException("Invalid time format: {$cronDailyTime}");
        }
        $this->cronDailyTime = $cronDailyTime;
        return $this;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): self
    {
        $this->cronExpression = $cronExpression;
        return $this;
    }

    // Cyberimpact API Methods
    public function getCyberimpactToken(): ?string
    {
        return $this->cyberimpactToken;
    }

    public function setCyberimpactToken(?string $token): self
    {
        $this->cyberimpactToken = $token;
        return $this;
    }

    public function getCyberimpactPing(): ?string
    {
        return $this->cyberimpactPing;
    }

    public function setCyberimpactPing(?string $ping): self
    {
        $this->cyberimpactPing = $ping;
        return $this;
    }

    public function getCyberimpactUsername(): ?string
    {
        return $this->cyberimpactUsername;
    }

    public function setCyberimpactUsername(?string $username): self
    {
        $this->cyberimpactUsername = $username;
        return $this;
    }

    public function getCyberimpactEmail(): ?string
    {
        return $this->cyberimpactEmail;
    }

    public function setCyberimpactEmail(?string $email): self
    {
        $this->cyberimpactEmail = $email;
        return $this;
    }

    public function getCyberimpactAccount(): ?string
    {
        return $this->cyberimpactAccount;
    }

    public function setCyberimpactAccount(?string $account): self
    {
        $this->cyberimpactAccount = $account;
        return $this;
    }

    public function getCyberimpactPingCheckedAt(): ?int
    {
        return $this->cyberimpactPingCheckedAt;
    }

    public function setCyberimpactPingCheckedAt(?int $timestamp): self
    {
        $this->cyberimpactPingCheckedAt = $timestamp;
        return $this;
    }

    public function isTokenValidated(): bool
    {
        return !empty($this->cyberimpactToken) && !empty($this->cyberimpactPingCheckedAt);
    }

    // Column Mapping Methods
    public function getColumnMapping(): ?array
    {
        return $this->columnMapping;
    }

    public function setColumnMapping(?array $mapping): self
    {
        $this->columnMapping = $mapping;
        return $this;
    }

    public function getColumnMappingJson(): ?string
    {
        return $this->columnMapping ? json_encode($this->columnMapping, JSON_UNESCAPED_SLASHES) : null;
    }

    public function setColumnMappingJson(?string $json): self
    {
        $this->columnMapping = $json ? json_decode($json, true) : null;
        return $this;
    }

    // Group Assignment Methods
    public function getSelectedGroupId(): ?int
    {
        return $this->selectedGroupId;
    }

    public function setSelectedGroupId(?int $groupId): self
    {
        $this->selectedGroupId = $groupId;
        return $this;
    }

    public function hasGroupAssignment(): bool
    {
        return $this->selectedGroupId !== null && $this->selectedGroupId > 0;
    }

    // Sync Options Methods
    public function getMissingContactsAction(): string
    {
        return $this->missingContactsAction;
    }

    public function setMissingContactsAction(string $action): self
    {
        if (!in_array($action, ['unsubscribe', 'delete'], true)) {
            throw new \InvalidArgumentException("Invalid action: {$action}");
        }
        $this->missingContactsAction = $action;
        return $this;
    }

    public function getDefaultConsentProof(): ?string
    {
        return $this->defaultConsentProof;
    }

    public function setDefaultConsentProof(?string $proof): self
    {
        if ($proof !== null && strlen($proof) > 255) {
            throw new \InvalidArgumentException("Consent proof too long (max 255 chars)");
        }
        $this->defaultConsentProof = $proof;
        return $this;
    }

    public function hasValidConfiguration(): bool
    {
        return !empty($this->cyberimpactToken)
            && !empty($this->sourcePath)
            && !empty($this->archivePath)
            && !empty($this->errorPath);
    }
}
