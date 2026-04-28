<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Scheduler;

use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Additional field provider for ImportSchedulerTask.
 * Currently, this task has no additional configuration fields.
 */
final class ImportSchedulerTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    /**
     * @var \TYPO3\CMS\Core\Log\Logger
     */
    protected $logger;

    public function __construct()
    {
        // Initialisation manuelle du logger
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        $this->logger->error('Affichage des champs additionnels pour ImportSchedulerTask.');
        return [];
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $this->logger->error('Validation des champs additionnels.');
        return true;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        $this->logger->error('Sauvegarde de la configuration de la tâche Scheduler.');
    }
}