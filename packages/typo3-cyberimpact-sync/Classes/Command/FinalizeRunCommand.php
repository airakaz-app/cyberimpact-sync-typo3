<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Run\RunFinalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cyberimpact:finalize-run', description: 'Finalize next run pending finalization.')]
final class FinalizeRunCommand extends Command
{
    public function __construct(
        private readonly RunFinalizer $runFinalizer,
        private readonly RunStorage $runStorage,
    ) {
        parent::__construct();
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
        if ($confirmRunUid > 0) {
            $run = $this->runStorage->findRunByUid($confirmRunUid);
            if ($run === null) {
                $output->writeln('<error>Run introuvable pour confirmation: #' . $confirmRunUid . '.</error>');
                return Command::FAILURE;
            }

            $this->runStorage->markExactSyncConfirmed($confirmRunUid);
            $output->writeln('<info>Confirmation enregistrée pour le run #' . $confirmRunUid . '.</info>');
        }

        $result = $this->runFinalizer->finalizeNextRun();

        if ($result['status'] === 'none' || $result['status'] === 'deferred' || $result['status'] === 'blocked') {
            $output->writeln('<comment>' . $result['message'] . '</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>' . $result['message'] . '</info>');

        return Command::SUCCESS;
    }
}
