<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Service\Run\ChunkProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[AsCommand(name: 'cyberimpact:traiter-chunk', description: 'Traiter le prochain chunk en attente.')]
final class ProcessNextRunCommand extends Command
{
    public function __construct(
        private readonly ChunkProcessor $chunkProcessor,
        private readonly ChunkStorage $chunkStorage,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->getSettings();
        $staleAfterSeconds = max(60, (int)($settings['staleChunkTimeoutSeconds'] ?? 900));
        $requeued = $this->chunkStorage->requeueStaleProcessingChunks($staleAfterSeconds);
        if ($requeued > 0) {
            $output->writeln('<comment>Chunks périmés remis en attente : ' . $requeued . '.</comment>');
        }

        $processed = $this->chunkProcessor->processNextPendingChunk();
        if ($processed === false) {
            $output->writeln('<comment>Aucun chunk en attente trouvé.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Chunk en attente traité.</info>');

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
