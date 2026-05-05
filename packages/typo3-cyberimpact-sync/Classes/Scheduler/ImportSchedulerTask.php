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

            // Étape 2 : Chunks - Continuer jusqu'à ce qu'il n'y ait aucun chunk restant
            $processCommand = GeneralUtility::makeInstance(ProcessNextRunCommand::class);
            $chunkCount = 0;
            $lastError = '';
            while (true) {
                $result = $processCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    // Aucun chunk restant ou erreur critique
                    $this->logger->notice('Traitement des chunks terminé.', ['chunks_traites' => $chunkCount]);
                    break;
                }
                $chunkCount++;
                $this->logger->info('Chunk traité avec succès.', ['chunk_numero' => $chunkCount]);
            }

            // Étape 3 : Finalisation - Continuer jusqu'à ce qu'il n'y ait aucun run à finaliser
            $finalizeCommand = GeneralUtility::makeInstance(FinalizeRunCommand::class);
            $runCount = 0;
            while (true) {
                $result = $finalizeCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    // Aucun run restant à finaliser
                    $this->logger->notice('Finalisation des runs terminée.', ['runs_finalises' => $runCount]);
                    break;
                }
                $runCount++;
                $this->logger->info('Run finalisé avec succès.', ['run_numero' => $runCount]);
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
