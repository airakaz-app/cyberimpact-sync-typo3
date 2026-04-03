<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[AsCommand(name: 'cyberimpact:scan-import-folder', description: 'Scan FAL incoming folder, queue runs and prepare chunks.')]
final class ScanImportFolderCommand extends Command
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RunManager $runManager,
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
        private readonly ErrorStorage $errorStorage,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->getSettings();
        $storageUid = (int)($settings['falStorageUid'] ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $dryRunDefault = (bool)($settings['dryRunDefault'] ?? true);
        $chunkSize = max(1, (int)($settings['chunkSize'] ?? 500));

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $output->writeln(sprintf('<error>FAL storage not found: %d</error>', $storageUid));
            return Command::FAILURE;
        }
        if (!$storage->hasFolder($incomingFolder)) {
            $output->writeln(sprintf('<error>FAL folder not found: %s (storage %d)</error>', $incomingFolder, $storageUid));
            return Command::FAILURE;
        }

        $folder = $storage->getFolder($incomingFolder);
        $files = $storage->getFilesInFolder($folder);

        $queuedCount = 0;
        foreach ($files as $file) {
            if (strtolower($file->getExtension()) !== 'xlsx') {
                continue;
            }

            $runUid = $this->runManager->queueFromFalFile($file->getUid(), $dryRunDefault, false);
            if ($runUid === null) {
                continue;
            }

            $queuedCount++;
            $prepared = $this->prepareRunFromFile($runUid, $file->getForLocalProcessing(), $chunkSize);
            $output->writeln(sprintf(
                '<info>Queued+prepared run #%d for file %s (rows=%d, valid=%d, errors=%d, chunks=%d)</info>',
                $runUid,
                $file->getName(),
                $prepared['totalRows'],
                $prepared['validRows'],
                $prepared['errorCount'],
                $prepared['chunkCount']
            ));
        }

        $output->writeln(sprintf('<comment>Scan completed. New runs queued: %d</comment>', $queuedCount));

        return Command::SUCCESS;
    }

    /**
     * @return array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}
     */
    private function prepareRunFromFile(int $runUid, string $localFilePath, int $chunkSize): array
    {
        $totalRows = 0;
        $validRows = 0;
        $errorCount = 0;
        $contacts = [];

        foreach ($this->excelChunkReader->readChunksFromLocalFile($localFilePath, $chunkSize) as $chunk) {
            $totalRows += count($chunk);

            $mapped = $this->contactRowMapper->mapRows($chunk);
            $validRows += count($mapped['contacts']);
            $contacts = array_merge($contacts, $mapped['contacts']);

            foreach ($mapped['errors'] as $error) {
                $errorCount++;
                $this->errorStorage->createRunError(
                    $runUid,
                    'parse',
                    (string)($error['code'] ?? 'parse_error'),
                    (string)($error['message'] ?? 'Erreur de parsing'),
                    (string)($error['payload'] ?? '')
                );
            }
        }

        $this->runManager->updateRunTotalRows($runUid, $totalRows);
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $contacts, $chunkSize);

        return [
            'totalRows' => $totalRows,
            'validRows' => $validRows,
            'errorCount' => $errorCount,
            'chunkCount' => $chunkCount,
        ];
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
