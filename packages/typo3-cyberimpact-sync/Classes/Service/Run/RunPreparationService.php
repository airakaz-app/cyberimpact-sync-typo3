<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Run;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;

/**
 * Encapsule la lecture d'un fichier Excel et la création des chunks en base.
 * Utilisé par les commandes CLI, le scheduler et le contrôleur backend.
 */
final class RunPreparationService
{
    public function __construct(
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
        private readonly ErrorStorage $errorStorage,
        private readonly RunManager $runManager,
    ) {
    }

    /**
     * Lit le fichier Excel, extrait et valide les contacts, enregistre les erreurs
     * de parsing, met à jour le compteur total du run et crée les chunks en base.
     *
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping
     * @return array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}
     */
    public function prepareRun(
        int    $runUid,
        string $localFilePath,
        int    $chunkSize,
        ?array $columnMapping
    ): array {
        $totalRows  = 0;
        $errorCount = 0;
        $contacts   = [];

        foreach ($this->excelChunkReader->readChunksFromLocalFile($localFilePath, $chunkSize, $columnMapping) as $chunk) {
            $totalRows += count($chunk['rows'] ?? []);
            $mapped     = $this->contactRowMapper->mapRows($chunk['rows'] ?? [], $chunk['resolvedMap'] ?? null);
            array_push($contacts, ...$mapped['contacts']);

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
}
