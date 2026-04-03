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
     * @return iterable<int, array{headers: array<string,string>, rows: array<int,array<string,string>>, resolvedMap: array{standard:array<string,int>,customFields:array<string,int>>}>
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

        // Les headers sont la première ligne, retourner les données après
        $headers = (array)reset($allRows);
        array_shift($allRows);  // Enlever la première ligne (headers)
        
        // Résoudre le mapping
        $resolvedMap = self::resolveHeaderMap($headers, $columnMapping);

        $chunk = [];
        foreach ($allRows as $row) {
            $chunk[] = $row;
            if (count($chunk) >= $chunkSize) {
                yield [
                    'headers' => $headers,
                    'rows' => $chunk,
                    'resolvedMap' => $resolvedMap,
                ];
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            yield [
                'headers' => $headers,
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
        $aliases = [
            'email'      => ['email', 'e-mail', 'courriel'],
            'firstname'  => ['firstname', 'first_name', 'prenom', 'prénom'],
            'lastname'   => ['lastname', 'last_name', 'nom', 'nom complet'],
            'company'    => ['company', 'entreprise', 'compagnie'],
            'language'   => ['language', 'langue'],
            'postalCode' => ['postalcode', 'postal_code', 'code postal', 'codepostal'],
            'country'    => ['country', 'pays'],
            'note'       => ['note', 'notes'],
            'phone'      => ['phone', 'telephone', 'téléphone', 'mobile'],
        ];

        $standard = [];
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            foreach ($aliases as $field => $options) {
                if (in_array($normalized, $options, true)) {
                    $standard[$field] = (int)$index;
                }
            }
        }

        return ['standard' => $standard, 'customFields' => []];
    }

    /**
     * Mode explicit : utilise le mapping configuré par l'admin.
     *
     * @param array<string,string> $headers Headers normalisés (lowercase)
     * @param array{standard:array<string,string>,customFields:array<string,string>} $columnMapping Mapping configuré
     * @return array{standard:array<string,int>,customFields:array<string,int>}
     */
    private static function resolveHeaderMapFromConfig(
        array $headers,
        array $columnMapping
    ): array {
        // Index inversé : "header name" (lowercase) → index
        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            $headerIndex[strtolower(trim($header))] = (int)$idx;
        }

        $standard = [];
        foreach ($columnMapping['standard'] ?? [] as $field => $colHeader) {
            if ($colHeader === null || $colHeader === '') {
                continue;
            }
            $idx = $headerIndex[strtolower(trim($colHeader))] ?? null;
            if ($idx !== null) {
                $standard[(string)$field] = $idx;
            }
        }

        $customFields = [];
        foreach ($columnMapping['customFields'] ?? [] as $fieldId => $colHeader) {
            if ($colHeader === null || $colHeader === '') {
                continue;
            }
            $idx = $headerIndex[strtolower(trim($colHeader))] ?? null;
            if ($idx !== null) {
                $customFields[(string)$fieldId] = $idx;
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
        $sheet = simplexml_load_string($sheetXml);
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

        $sharedStrings = simplexml_load_string($xml);
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
