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
use TYPO3\CMS\Core\Log\LogManager;

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
        $this->logger->error('IMPORT LOG TEST : La tâche est bien passée par ici à ' . date('H:i:s'));

        $this->logger->error('Démarrage du Scheduler Task d\'importation.');

        try {
            // Étape 1 : Scan
            $this->logger->error('Début du scan du dossier entrant.');
            $scanCommand = GeneralUtility::makeInstance(ScanImportFolderCommand::class);
            $scanCommand->run(new ArrayInput([]), new NullOutput());

            // Étape 2 : Chunks
            $processCommand = GeneralUtility::makeInstance(ProcessNextRunCommand::class);
            for ($i = 0; $i < 10; $i++) {
                $result = $processCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    $this->logger->error('Arrêt du traitement des chunks : aucun chunk restant ou erreur.', ['result' => $result]);
                    break;
                }
                $this->logger->error('Chunk {iteration} traité avec succès.', ['iteration' => $i + 1]);
            }

            // Étape 3 : Finalisation
            $finalizeCommand = GeneralUtility::makeInstance(FinalizeRunCommand::class);
            for ($i = 0; $i < 5; $i++) {
                $result = $finalizeCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) break;
                $this->logger->error('Run {iteration} finalisé.', ['iteration' => $i + 1]);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec critique de l\'importation : ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }
}
