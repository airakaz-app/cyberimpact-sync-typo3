<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Encapsule la lecture d'un fichier Excel et la création des chunks en base.
 * Utilisé par les commandes CLI, le scheduler et le contrôleur backend.
 */
final class RunPreparationService
{
    private const MAX_CONTACTS_PER_RUN = 1000000; // Limite de 1M de contacts
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
        private readonly ErrorStorage $errorStorage,
        private readonly RunManager $runManager,
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    /**
     * Lit le fichier Excel, extrait et valide les contacts, enregistre les erreurs
     * de parsing, met à jour le compteur total du run et crée les chunks en base.
     *
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping
     * @return array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}
     * @throws \RuntimeException si erreur critique lors de la lecture
     */
    public function prepareRun(
        int    $runUid,
        string $localFilePath,
        int    $chunkSize,
        ?array $columnMapping
    ): array {
        // Validations préalables
        $chunkSize = max(1, min(10000, (int)$chunkSize)); // Chunk entre 1 et 10000
        
        $totalRows  = 0;
        $errorCount = 0;
        $contacts   = [];

        try {
            foreach ($this->excelChunkReader->readChunksFromLocalFile($localFilePath, $chunkSize, $columnMapping) as $chunk) {
                $rowsInChunk = count($chunk['rows'] ?? []);
                $totalRows += $rowsInChunk;
                
                // Protection contre les fichiers trop volumineux
                if ($totalRows > self::MAX_CONTACTS_PER_RUN) {
                    $this->logger->error(
                        sprintf('Fichier trop volumineux : %d lignes (max %d)', $totalRows, self::MAX_CONTACTS_PER_RUN)
                    );
                    throw new \RuntimeException(
                        sprintf('Fichier Excel trop volumineux : %d lignes (max %d)', 
                            $totalRows, 
                            self::MAX_CONTACTS_PER_RUN
                        )
                    );
                }
                
                $mapped = $this->contactRowMapper->mapRows($chunk['rows'] ?? [], $chunk['resolvedMap'] ?? null);
                array_push($contacts, ...$mapped['contacts']);

                // Enregistrer les erreurs de parsing
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
                
                $this->logger->debug(
                    sprintf('Chunk traité : %d lignes, %d contacts valides, %d erreurs', 
                        $rowsInChunk, 
                        count($mapped['contacts']), 
                        count($mapped['errors'])
                    )
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur critique lors de la préparation du run : ' . $e->getMessage(), [
                'runUid' => $runUid,
                'filePath' => $localFilePath,
                'exception' => $e,
            ]);
            throw $e;
        }

        // Mise à jour du run
        $this->runManager->updateRunTotalRows($runUid, $totalRows);
        $chunkCount = $this->runManager->createChunksFromContacts($runUid, $contacts, $chunkSize);

        $this->logger->info(
            sprintf('Run #%d préparé : %d lignes, %d contacts, %d chunks, %d erreurs',
                $runUid,
                $totalRows,
                count($contacts),
                $chunkCount,
                $errorCount
            )
        );

        return [
            'totalRows'  => $totalRows,
            'validRows'  => count($contacts),
            'errorCount' => $errorCount,
            'chunkCount' => $chunkCount,
        ];
    }
}
