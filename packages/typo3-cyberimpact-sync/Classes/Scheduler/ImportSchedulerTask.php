<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Scheduler;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;

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

            // Nettoyage des chunks bloqués
            $chunkStorage = GeneralUtility::makeInstance(ChunkStorage::class);
            $staleAfterSeconds = 900; // 15 minutes
            $requeued = $chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
            if ($requeued > 0) {
                $this->logger->notice('Chunks périmés remis en file d\'attente.', ['requeued' => $requeued]);
            }

            // Étape 2 : Traiter tous les runs en attente ou en cours
            $runStorage = GeneralUtility::makeInstance(RunStorage::class);
            $chunkProcessor = GeneralUtility::makeInstance(ChunkProcessor::class);
            $runs = $runStorage->findQueuedOrProcessingRuns();
            $runCount = 0;
            foreach ($runs as $run) {
                $runUid = (int)$run['uid'];
                $this->logger->info('Traitement du run.', ['run_uid' => $runUid]);
                try {
                    $chunkProcessor->processRunChunks($runUid);
                    $runCount++;
                    $this->logger->info('Run traité avec succès.', ['run_uid' => $runUid]);
                } catch (\Throwable $e) {
                    $this->logger->error('Erreur lors du traitement du run.', [
                        'run_uid' => $runUid,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            $this->logger->notice('Traitement des runs terminé.', ['runs_traites' => $runCount]);

            // Étape 3 : Finalisation - Continuer jusqu'à ce qu'il n'y ait aucun run à finaliser
            $finalizeCommand = GeneralUtility::makeInstance(FinalizeRunCommand::class);
            $finalizeCount = 0;
            while (true) {
                $result = $finalizeCommand->run(new ArrayInput([]), new NullOutput());
                if ($result !== 0) {
                    // Aucun run restant à finaliser
                    $this->logger->notice('Finalisation des runs terminée.', ['runs_finalises' => $finalizeCount]);
                    break;
                }
                $finalizeCount++;
                $this->logger->info('Run finalisé avec succès.', ['run_numero' => $finalizeCount]);
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
