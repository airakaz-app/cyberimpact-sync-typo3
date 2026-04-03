<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Scheduler;

use Cyberimpact\CyberimpactSync\Command\ScanImportFolderCommand;
use Cyberimpact\CyberimpactSync\Command\ProcessNextRunCommand;
use Cyberimpact\CyberimpactSync\Command\FinalizeRunCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Import Scheduler Task - Runs the complete import pipeline:
 * 1. Scan incoming folder (queue new files)
 * 2. Process pending chunks
 * 3. Finalize completed runs
 */
final class ImportSchedulerTask extends AbstractTask
{
    public function execute(): bool
    {
        try {
            // Step 1: Scan incoming folder
            $scanCommand = GeneralUtility::makeInstance(ScanImportFolderCommand::class);
            $scanCommand->run(new ArrayInput([]), new NullOutput());

            // Step 2: Process pending chunks
            $processCommand = GeneralUtility::makeInstance(ProcessNextRunCommand::class);
            for ($i = 0; $i < 10; $i++) {  // Process up to 10 chunks
                $result = $processCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    break;
                }
            }

            // Step 3: Finalize completed runs
            $finalizeCommand = GeneralUtility::makeInstance(FinalizeRunCommand::class);
            for ($i = 0; $i < 5; $i++) {  // Finalize up to 5 runs
                $result = $finalizeCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    break;
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
