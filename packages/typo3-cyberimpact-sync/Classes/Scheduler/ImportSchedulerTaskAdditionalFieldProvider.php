<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Scheduler;

use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Additional field provider for ImportSchedulerTask.
 * Currently, this task has no additional configuration fields.
 */
final class ImportSchedulerTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    /**
     * @param array<string, mixed> $taskInfo
     * @param mixed $task
     * @param SchedulerModuleController $schedulerModule
     * @return array<int, array<string, string>>
     */
    public function getAdditionalFields(
        array &$taskInfo,
        $task,
        SchedulerModuleController $schedulerModule
    ): array {
        // This task has no additional configuration fields
        return [];
    }

    /**
     * @param array<string, mixed> $submittedData
     * @param SchedulerModuleController $schedulerModule
     * @return bool
     */
    public function validateAdditionalFields(
        array &$submittedData,
        SchedulerModuleController $schedulerModule
    ): bool {
        // Nothing to validate
        return true;
    }

    /**
     * @param array<string, mixed> $submittedData
     * @param AbstractTask $task
     * @return void
     */
    public function saveAdditionalFields(
        array $submittedData,
        AbstractTask $task
    ): void {
        // Nothing to save
    }
}
