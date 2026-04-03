<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Register Scheduler Task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Cyberimpact\CyberimpactSync\Scheduler\ImportSchedulerTask::class] = [
    'extension' => 'cyberimpact_sync',
    'title' => 'LLL:EXT:cyberimpact_sync/Resources/Private/Language/locallang_scheduler.xlf:importSchedulerTask.title',
    'description' => 'LLL:EXT:cyberimpact_sync/Resources/Private/Language/locallang_scheduler.xlf:importSchedulerTask.description',
    'additionalFields' => \Cyberimpact\CyberimpactSync\Scheduler\ImportSchedulerTaskAdditionalFieldProvider::class,
];
