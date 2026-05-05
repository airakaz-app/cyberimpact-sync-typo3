<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Cyberimpact\CyberimpactSync\Service\Run\RunPreparationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'cyberimpact:scanner-dossier',
    description: 'Scanner le dossier FAL entrant, créer et préparer les runs pour les nouveaux fichiers .xlsx.'
)]
final class ScanImportFolderCommand extends Command
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunManager $runManager,
        private readonly RunPreparationService $runPreparationService,
    ) {
        parent::__construct();
        // Initialisation du logger
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('Début du scan du dossier d\'import.');

        $settings       = $this->getExtSettings();
        $storageUid     = (int)($settings['falStorageUid']  ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $chunkSize      = max(1, (int)($settings['chunkSize'] ?? 500));
        $columnMapping  = $this->importSettingsRepository->findFirst()->getColumnMapping();

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $msg = sprintf('Stockage FAL introuvable : %d', $storageUid);
            $this->logger->error($msg);
            $output->writeln('<error>' . $msg . '</error>');
            return Command::FAILURE;
        }
        
        $realPath = $storage->getPublicUrl($storage->getRootLevelFolder());
        $this->logger->error('DEBUG : Le stockage 1 pointe vers : ' . $realPath);
        if (!$storage->hasFolder($incomingFolder)) {
            $msg = sprintf('Dossier FAL introuvable : %s (stockage %d)', $incomingFolder, $storageUid);
            $this->logger->error($msg);
            $output->writeln('<error>' . $msg . '</error>');
            return Command::FAILURE;
        }

        $folder      = $storage->getFolder($incomingFolder);
        $files       = $storage->getFilesInFolder($folder);
        $queuedCount = 0;

        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'xlsx') {
                continue;
            }

            $runUid = $this->runManager->queueFromFalFile($file->getUid());
            if ($runUid === null) {
                $this->logger->debug(sprintf('Fichier %s ignoré (déjà en queue).', $file->getName()));
                continue;
            }

            try {
                $queuedCount++;
                $stats = $this->runPreparationService->prepareRun($runUid, $file->getForLocalProcessing(), $chunkSize, $columnMapping);

                $this->logger->info(sprintf('Run #%d créé pour %s (%d chunks)', $runUid, $file->getName(), $stats['chunkCount']), $stats);

                $output->writeln(sprintf(
                    '  ✓ Run #%d créé pour %s (%d lignes, %d valides, %d erreurs, %d chunks)',
                    $runUid,
                    $file->getName(),
                    $stats['totalRows'],
                    $stats['validRows'],
                    $stats['errorCount'],
                    $stats['chunkCount']
                ));
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Erreur critique lors de la préparation du fichier %s : %s', $file->getName(), $e->getMessage()),
                    ['exception' => $e, 'runUid' => $runUid]
                );
                $output->writeln(sprintf(
                    '  ✗ Erreur Run #%d : %s',
                    $runUid,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('Scan terminé. %d nouveaux runs créés.', $queuedCount));
        $output->writeln(sprintf('<comment>Scan terminé — %d nouveau(x) run(s) créé(s).</comment>', $queuedCount));

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function getExtSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
