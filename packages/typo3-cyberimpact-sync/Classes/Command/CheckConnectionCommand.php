<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cyberimpact:check-connection', description: 'Check Cyberimpact API connectivity.')]
final class CheckConnectionCommand extends Command
{
    public function __construct(private readonly CyberimpactClient $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->client->checkConnection();
        } catch (\Throwable $exception) {
            $output->writeln('<error>Cyberimpact connection failed: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($result['ok'] === true) {
            $output->writeln('<info>' . $result['message'] . ' (HTTP ' . $result['statusCode'] . ')</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>' . $result['message'] . '</error>');
        return Command::FAILURE;
    }
}
