<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Run\RunFinalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'cyberimpact:finaliser-run', description: 'Finaliser le prochain run en attente.')]
final class FinalizeRunCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RunFinalizer $runFinalizer,
        private readonly RunStorage $runStorage,
    ) {
        parent::__construct();
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function configure(): void
    {
        $this->addOption(
            'confirm-run',
            null,
            InputOption::VALUE_REQUIRED,
            'UID du run exact-sync à confirmer manuellement avant finalisation.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $confirmRunUid = (int)$input->getOption('confirm-run');
        
        // Log et traitement de la confirmation manuelle
        if ($confirmRunUid > 0) {
            $run = $this->runStorage->findRunByUid($confirmRunUid);
            if ($run === null) {
                $this->logger->error(sprintf('Échec confirmation manuelle : Run #%d introuvable.', $confirmRunUid));
                $output->writeln('<error>Run introuvable pour confirmation: #' . $confirmRunUid . '.</error>');
                return Command::FAILURE;
            }

            $this->runStorage->markExactSyncConfirmed($confirmRunUid);
            $this->logger->info(sprintf('Run #%d marqué comme confirmé manuellement.', $confirmRunUid));
            $output->writeln('<info>Confirmation enregistrée pour le run #' . $confirmRunUid . '.</info>');
        }

        try {
            $result = $this->runFinalizer->finalizeNextRun();

            // Gestion des différents états de finalisation dans les logs
            match ($result['status']) {
                'success' => $this->logger->info('Finalisation réussie : ' . $result['message']),
                'deferred' => $this->logger->debug('Finalisation différée : ' . $result['message']),
                'blocked' => $this->logger->warning('Finalisation bloquée : ' . $result['message']),
                'none' => $this->logger->debug('Aucun run à finaliser.'),
                default => $this->logger->notice('Résultat finalisation : ' . $result['message']),
            };

            if ($result['status'] === 'none' || $result['status'] === 'deferred' || $result['status'] === 'blocked') {
                $output->writeln('<comment>' . $result['message'] . '</comment>');
                // Retourner FAILURE pour 'none' afin que le scheduler sache qu'il n'y a plus rien à finaliser
                return $result['status'] === 'none' ? Command::FAILURE : Command::SUCCESS;
            }

            $output->writeln('<info>' . $result['message'] . '</info>');

        } catch (\Throwable $e) {
            $this->logger->error('Erreur critique lors de la finalisation du run : ' . $e->getMessage(), [
                'exception' => $e
            ]);
            $output->writeln('<error>Erreur : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}