<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Import;

/**
 * Lecteur de fichiers Excel avec support du mapping des colonnes.
 *
 * Supporte deux modes :
 * 1) Mapping configuré (columnMapping non null) : utilise le mapping explicite
 * 2) Détection automatique (columnMapping null) : reconnaissance par alias
 */
final class ExcelChunkReader
{
    /**
     * @param string $localFilePath Chemin du fichier Excel
     * @param int $chunkSize Taille des chunks
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping Mapping configuré (null = auto-detect)
     * @return iterable<int, array{headers: array<int,string>, rows: array<int,array<string,string>>, resolvedMap: array{standard:array<string,string>,customFields:array<string,string>}|null}>
     */
    public function readChunksFromLocalFile(
        string $localFilePath,
        int $chunkSize = 500,
        ?array $columnMapping = null
    ): iterable {
        $allRows = $this->readRows($localFilePath);

        if (empty($allRows)) {
            return;
        }

        // Les rows sont déjà indexées par les noms de colonnes (clés string).
        // On extrait les noms de colonnes depuis les clés du premier row.
        $firstRow = reset($allRows);
        $headerNames = array_values(array_filter(
            array_keys($firstRow),
            static fn(string $k): bool => $k !== '_rownum'
        ));

        // En mode auto-detect (columnMapping === null), on passe resolvedMap = null
        // pour que mapRows() utilise la détection par alias (clés string).
        // En mode explicit, on construit le resolvedMap depuis le columnMapping configuré.
        $resolvedMap = $columnMapping !== null
            ? self::resolveHeaderMap($headerNames, $columnMapping)
            : null;

        $chunk = [];
        foreach ($allRows as $row) {
            $chunk[] = $row;
            if (count($chunk) >= $chunkSize) {
                yield [
                    'headers' => $headerNames,
                    'rows' => $chunk,
                    'resolvedMap' => $resolvedMap,
                ];
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield [
                'headers' => $headerNames,
                'rows' => $chunk,
                'resolvedMap' => $resolvedMap,
            ];
        }
    }

    /**
     * Résout le mapping des en-têtes vers les indices de colonnes.
     * Supporte deux modes : explicit mapping ou auto-detect par alias.
     *
     * @param array<string,string> $headers Headers normalisés en minuscules
     * @param array{standard:array<string,string>,customFields:array<string,string>}|null $columnMapping Mapping configuré
     * @return array{standard:array<string,int>,customFields:array<string,int>}
     */
    public static function resolveHeaderMap(
        array $headers,
        ?array $columnMapping
    ): array {
        if ($columnMapping === null) {
            return self::resolveHeaderMapAuto($headers);
        }
        return self::resolveHeaderMapFromConfig($headers, $columnMapping);
    }

    /**
     * Mode auto-detect : reconnaissance par alias (comportement historique).
     * Seuls les champs standards reconnus par l'API Cyberimpact.
     *
     * @param array<string,string> $headers
     * @return array{standard:array<string,int>,customFields:array<string,int>}
     */
    private static function resolveHeaderMapAuto(array $headers): array
    {
        // Flat lookup: alias → field name — O(1) per header instead of O(fields)
        $aliasLookup = [
            'email' => 'email', 'e-mail' => 'email', 'courriel' => 'email',
            'firstname' => 'firstname', 'first_name' => 'firstname', 'prenom' => 'firstname', 'prénom' => 'firstname',
            'lastname' => 'lastname', 'last_name' => 'lastname', 'nom' => 'lastname', 'nom complet' => 'lastname',
            'company' => 'company', 'entreprise' => 'company', 'compagnie' => 'company',
            'language' => 'language', 'langue' => 'language',
            'postalcode' => 'postalCode', 'postal_code' => 'postalCode', 'code postal' => 'postalCode', 'codepostal' => 'postalCode',
            'country' => 'country', 'pays' => 'country',
            'note' => 'note', 'notes' => 'note',
            'phone' => 'phone', 'telephone' => 'phone', 'téléphone' => 'phone', 'mobile' => 'phone',
        ];

        $standard = [];
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            if (isset($aliasLookup[$normalized])) {
                $standard[$aliasLookup[$normalized]] = (int)$index;
            }
        }

        return ['standard' => $standard, 'customFields' => []];
    }

    /**
     * Mode explicit : utilise le mapping configuré par l'admin.
     * Retourne le nom de colonne normalisé (clé string) pour chaque champ,
     * compatible avec les rows indexées par nom de colonne.
     *
     * @param array<int,string> $headers Noms de colonnes du fichier Excel (liste)
     * @param array{standard:array<string,string>,customFields:array<string,string>} $columnMapping Mapping configuré
     * @return array{standard:array<string,string>,customFields:array<string,string>}
     */
    private static function resolveHeaderMapFromConfig(
        array $headers,
        array $columnMapping
    ): array {
        // Ensemble des noms de colonnes présents dans le fichier (lowercase)
        $headerSet = array_flip(array_map('strtolower', $headers));

        $standard = [];
        foreach ($columnMapping['standard'] ?? [] as $field => $colHeader) {
            if ($colHeader === null || $colHeader === '') {
                continue;
            }
            $normalized = strtolower(trim($colHeader));
            if (array_key_exists($normalized, $headerSet)) {
                $standard[(string)$field] = $normalized;
            }
        }

        $customFields = [];
        foreach ($columnMapping['customFields'] ?? [] as $fieldId => $colHeader) {
            if ($colHeader === null || $colHeader === '') {
                continue;
            }
            $normalized = strtolower(trim($colHeader));
            if (array_key_exists($normalized, $headerSet)) {
                $customFields[(string)$fieldId] = $normalized;
            }
        }

        return ['standard' => $standard, 'customFields' => $customFields];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readRows(string $localFilePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($localFilePath) !== true) {
            return [];
        }

        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
        $zip->close();

        if ($sheetXml === '') {
            return [];
        }

        $sharedStrings = $this->parseSharedStrings($sharedStringsXml);

        libxml_use_internal_errors(true);
        $sheet = simplexml_load_string($sheetXml);
        libxml_clear_errors();
        if ($sheet === false) {
            return [];
        }

        $sheet->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xmlRows = $sheet->xpath('//main:sheetData/main:row');
        if (!is_array($xmlRows) || $xmlRows === []) {
            return [];
        }

        $headers = [];
        $dataRows = [];

        foreach ($xmlRows as $rowIndex => $xmlRow) {
            $values = [];
            $xmlRow->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $cells = $xmlRow->xpath('main:c');
            if (!is_array($cells)) {
                continue;
            }

            foreach ($cells as $cell) {
                $rawValue = (string)($cell->v ?? '');
                $type = (string)($cell['t'] ?? '');

                if ($type === 's' && $rawValue !== '') {
                    $index = (int)$rawValue;
                    $values[] = $sharedStrings[$index] ?? '';
                } else {
                    $values[] = trim($rawValue);
                }
            }

            if ($rowIndex === 0) {
                $headers = array_map(
                    static fn (string $value): string => strtolower(trim($value)),
                    $values
                );
                continue;
            }

            if ($headers === []) {
                continue;
            }

            $mappedRow = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $mappedRow[$header] = $values[$index] ?? '';
            }

            $mappedRow['_rownum'] = (string)($rowIndex + 1);

            if ($mappedRow !== []) {
                $dataRows[] = $mappedRow;
            }
        }

        return $dataRows;
    }

    /**
     * @return array<int, string>
     */
    private function parseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $sharedStrings = simplexml_load_string($xml);
        libxml_clear_errors();
        if ($sharedStrings === false) {
            return [];
        }

        $sharedStrings->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = $sharedStrings->xpath('//main:si');
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            $item->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $texts = $item->xpath('.//main:t');
            if (!is_array($texts)) {
                $values[] = '';
                continue;
            }

            $content = '';
            foreach ($texts as $textNode) {
                $content .= (string)$textNode;
            }

            $values[] = $content;
        }

        return $values;
    }
}
