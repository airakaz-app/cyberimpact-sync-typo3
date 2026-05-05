<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Service\Run\ChunkProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'cyberimpact:traiter-chunk', description: 'Traiter le prochain chunk en attente.')]
final class ProcessNextRunCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ChunkProcessor $chunkProcessor,
        private readonly ChunkStorage $chunkStorage,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
        // Initialisation du Logger PSR-3
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->getSettings();
        $staleAfterSeconds = max(60, (int)($settings['staleChunkTimeoutSeconds'] ?? 900));

        try {
            // Nettoyage des chunks bloqués
            $requeued = $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
            if ($requeued > 0) {
                $this->logger->notice(sprintf('%d chunks périmés ont été remis en file d\'attente.', $requeued));
                $output->writeln('<comment>Chunks périmés remis en attente : ' . $requeued . '.</comment>');
            }

            // Traitement du chunk suivant
            $processed = $this->chunkProcessor->processNextPendingChunk();
            
            if ($processed === false) {
                // On log en debug car c'est un état normal si tout est fini
                $this->logger->debug('Aucun chunk en attente à traiter.');
                $output->writeln('<comment>Aucun chunk en attente trouvé.</comment>');
                return Command::FAILURE;
            }

            $this->logger->info('Chunk traité avec succès par la commande.');
            $output->writeln('<info>✅ Chunk traité avec succès</info>');

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors du traitement du chunk : ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            $output->writeln('<error>Erreur critique : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}