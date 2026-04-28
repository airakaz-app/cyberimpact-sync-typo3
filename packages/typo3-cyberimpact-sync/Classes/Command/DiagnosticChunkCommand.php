<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'cyberimpact:diagnostic-chunks', description: 'Diagnostic des états des chunks et détection des blocages.')]
final class DiagnosticChunkCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ChunkStorage $chunkStorage,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Lancement du diagnostic des chunks.');
        $connection = $this->connectionPool->getConnectionForTable('tx_cyberimpactsync_chunk');

        // 1. Statistiques globales
        $stats = $connection->executeQuery(
            'SELECT status, COUNT(*) as cnt FROM tx_cyberimpactsync_chunk GROUP BY status'
        )->fetchAllAssociative();

        $output->writeln("\n<fg=cyan;options=bold>=== STATISTIQUES GLOBALES ===</>\n");
        $logStats = [];
        foreach ($stats as $stat) {
            $status = (string)($stat['status'] ?? '');
            $count = (int)($stat['cnt'] ?? 0);
            $logStats[$status] = $count;
            $icon = match ($status) {
                'done' => '✅',
                'pending' => '⏳',
                'processing' => '⚠️',
                'failed' => '❌',
                default => '❓',
            };
            $output->writeln(sprintf("  %s %s: %d", $icon, $status, $count));
        }
        $this->logger->debug('Stats globales des chunks', $logStats);

        // 2. Chunks bloqués
        $output->writeln("\n<fg=cyan;options=bold>=== CHUNKS EN PROCESSING ===</>\n");
        $processing = $connection->executeQuery(
            'SELECT uid, run_uid, chunk_index, attempt_count, 
                    UNIX_TIMESTAMP(NOW()) - tstamp AS age_seconds
             FROM tx_cyberimpactsync_chunk 
             WHERE status = :processing
             ORDER BY age_seconds DESC',
            ['processing' => 'processing'],
            ['processing' => ParameterType::STRING]
        )->fetchAllAssociative();

        if (empty($processing)) {
            $output->writeln("  ✅ Aucun chunk bloqué");
        } else {
            $countBlocked = count($processing);
            $this->logger->warning(sprintf('%d chunks semblent bloqués en état "processing".', $countBlocked));
            
            $output->writeln(sprintf("  ⚠️  <fg=red>%d chunk(s) bloqué(s)</>\n", $countBlocked));
            foreach ($processing as $chunk) {
                $uid = (int)($chunk['uid'] ?? 0);
                $ageMin = (int)ceil(($chunk['age_seconds'] ?? 0) / 60);
                
                $output->writeln(sprintf(
                    "    Chunk %d (UID %d): bloqué depuis %d min, %d tentatives",
                    (int)$chunk['chunk_index'], $uid, $ageMin, (int)$chunk['attempt_count']
                ));
            }
        }

        // 3. Chunks à risque
        $output->writeln("\n<fg=cyan;options=bold>=== CHUNKS À RISQUE (3+ tentatives) ===</>\n");
        $risky = $connection->executeQuery(
            'SELECT uid, run_uid, chunk_index, status, attempt_count
             FROM tx_cyberimpactsync_chunk 
             WHERE attempt_count >= 3
             ORDER BY attempt_count DESC'
        )->fetchAllAssociative();

        if (!empty($risky)) {
            $this->logger->error(sprintf('%d chunks ont échoué plus de 3 fois.', count($risky)), ['risky_chunks' => $risky]);
            $output->writeln(sprintf("  ⚠️  <fg=yellow>%d chunk(s) à risque</>\n", count($risky)));
            foreach ($risky as $chunk) {
                $output->writeln(sprintf("    Chunk %d (%s): %d tentatives", (int)$chunk['chunk_index'], (string)$chunk['status'], (int)$chunk['attempt_count']));
            }
        } else {
            $output->writeln("  ✅ Aucun chunk à risque");
        }

        // 4. Runs actifs
        $output->writeln("\n<fg=cyan;options=bold>=== RUNS ACTIFS ===</>\n");
        $runs = $connection->executeQuery(
            'SELECT DISTINCT run_uid FROM tx_cyberimpactsync_chunk ORDER BY run_uid DESC LIMIT 5'
        )->fetchAllAssociative();

        if (empty($runs)) {
            $output->writeln("  ❌ Aucun run trouvé");
        } else {
            foreach ($runs as $run) {
                $runUid = (int)($run['run_uid'] ?? 0);
                $counts = $connection->executeQuery(
                    'SELECT status, COUNT(*) as cnt FROM tx_cyberimpactsync_chunk WHERE run_uid = :runUid GROUP BY status',
                    ['runUid' => $runUid],
                    ['runUid' => ParameterType::INTEGER]
                )->fetchAllAssociative();

                $summary = [];
                foreach ($counts as $c) { $summary[(string)$c['status']] = (int)$c['cnt']; }

                $output->writeln(sprintf(
                    "  Run #%d: pending=%d, processing=%d, done=%d, failed=%d",
                    $runUid, $summary['pending'] ?? 0, $summary['processing'] ?? 0, $summary['done'] ?? 0, $summary['failed'] ?? 0
                ));
            }
        }

        $this->logger->info('Diagnostic terminé avec succès.');
        $output->writeln("\n<fg=green;options=bold>Diagnostic terminé</>\n");

        return Command::SUCCESS;
    }
}