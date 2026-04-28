<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'cyberimpact:verifier-connexion', description: 'Vérifier la connectivité API Cyberimpact.')]
final class CheckConnectionCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(private readonly CyberimpactClient $client)
    {
        parent::__construct();
        // Initialisation du Logger
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Tentative de connexion à l\'API Cyberimpact via CheckConnectionCommand.');

        try {
            $result = $this->client->checkConnection();
        } catch (\Throwable $exception) {
            $this->logger->error('Connexion Cyberimpact échouée (exception) : ' . $exception->getMessage(), [
                'exception' => $exception
            ]);
            $output->writeln('<error>Connexion Cyberimpact échouée : ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($result['ok'] === true) {
            $this->logger->info('Connexion Cyberimpact réussie.', [
                'statusCode' => $result['statusCode'],
                'message' => $result['message']
            ]);
            $output->writeln('<info>' . $result['message'] . ' (HTTP ' . $result['statusCode'] . ')</info>');
            return Command::SUCCESS;
        }

        $this->logger->warning('L\'API Cyberimpact a répondu mais avec une erreur de validation.', $result);
        $output->writeln('<error>' . $result['message'] . '</error>');
        return Command::FAILURE;
    }
}