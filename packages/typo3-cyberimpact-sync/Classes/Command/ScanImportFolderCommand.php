<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Command;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[AsCommand(
    name: 'cyberimpact:scanner-dossier',
    description: 'Scanner le dossier FAL entrant, créer et préparer les runs pour les nouveaux fichiers .xlsx.'
)]
final class ScanImportFolderCommand extends Command
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunManager $runManager,
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
        private readonly ErrorStorage $errorStorage,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings       = $this->getExtSettings();
        $storageUid     = (int)($settings['falStorageUid']  ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');
        $chunkSize      = max(1, (int)($settings['chunkSize'] ?? 500));
        $columnMapping  = $this->importSettingsRepository->findFirst()->getColumnMapping();

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            $output->writeln(sprintf('<error>Stockage FAL introuvable : %d</error>', $storageUid));
            return Command::FAILURE;
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $output->writeln(sprintf('<error>Dossier FAL introuvable : %s (stockage %d)</error>', $incomingFolder, $storageUid));
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
                $output->writeln(sprintf('  — %s : run déjà existant, ignoré.', $file->getName()));
                continue;
            }

            $queuedCount++;
            $stats = $this->prepareRun($runUid, $file->getForLocalProcessing(), $chunkSize, $columnMapping);

            $output->writeln(sprintf(
                '  ✓ Run #%d créé pour %s (%d lignes, %d valides, %d erreurs, %d chunks)',
                $runUid,
                $file->getName(),
                $stats['totalRows'],
                $stats['validRows'],
                $stats['errorCount'],
                $stats['chunkCount']
            ));
        }

        $output->writeln(sprintf('<comment>Scan terminé — %d nouveau(x) run(s) créé(s).</comment>', $queuedCount));

        return Command::SUCCESS;
    }

    /**
     * Lit le fichier Excel, extrait les contacts et crée les chunks en base.
     *
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping
     * @return array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}
     */
    private function prepareRun(int $runUid, string $localFilePath, int $chunkSize, ?array $columnMapping): array
    {
        $totalRows   = 0;
        $errorCount  = 0;
        $contacts    = [];

        foreach ($this->excelChunkReader->readChunksFromLocalFile($localFilePath, $chunkSize, $columnMapping) as $chunk) {
            $totalRows += count($chunk['rows'] ?? []);
            $mapped     = $this->contactRowMapper->mapRows($chunk['rows'] ?? [], $chunk['resolvedMap'] ?? null);
            $contacts   = array_merge($contacts, $mapped['contacts']);

            foreach ($mapped['errors'] as $error) {
                $errorCount++;
                $this->errorStorage->createRunError(
                    $runUid,
                    'parse',
                    (string)($error['code']    ?? 'parse_error'),
                    (string)($error['message'] ?? 'Erreur de parsing'),
                    (string)($error['payload'] ?? '')
                );
            }
        }

        $this->runManager->updateRunTotalRows($runUid, $totalRows);
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $contacts, $chunkSize);

        return [
            'totalRows'  => $totalRows,
            'validRows'  => count($contacts),
            'errorCount' => $errorCount,
            'chunkCount' => $chunkCount,
        ];
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
