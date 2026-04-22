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
    private const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

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

        $sheet->registerXPathNamespace('main', self::XLSX_NS);
        $xmlRows = $sheet->xpath('//main:sheetData/main:row');
        if (!is_array($xmlRows) || $xmlRows === []) {
            return [];
        }

        $headers = [];
        $dataRows = [];

        foreach ($xmlRows as $rowIndex => $xmlRow) {
            $values = [];
            $xmlRow->registerXPathNamespace('main', self::XLSX_NS);
            $cells = $xmlRow->xpath('main:c');
            if (!is_array($cells)) {
                continue;
            }

            foreach ($cells as $cell) {
                // Utilise la référence de cellule (ex : "C3") pour calculer l'index de colonne.
                // Sans ça, les cellules vides omises du XML décalent toutes les colonnes suivantes.
                $colIndex = self::cellRefToColumnIndex((string)($cell['r'] ?? ''));
                $rawValue = (string)($cell->v ?? '');
                $type     = (string)($cell['t'] ?? '');

                $values[$colIndex] = ($type === 's' && $rawValue !== '')
                    ? ($sharedStrings[(int)$rawValue] ?? '')
                    : trim($rawValue);
            }

            if ($rowIndex === 0) {
                // $values est indexé par colonne (0-based), on le réindexe séquentiellement
                ksort($values);
                $headers = array_map(
                    static fn (string $value): string => strtolower(trim($value)),
                    array_values($values)
                );
                continue;
            }

            if ($headers === []) {
                continue;
            }

            $mappedRow = [];
            foreach ($headers as $colIdx => $header) {
                if ($header === '') {
                    continue;
                }
                // Cherche la valeur par index de colonne (les cellules vides ne sont pas dans $values)
                $mappedRow[$header] = $values[$colIdx] ?? '';
            }

            $mappedRow['_rownum'] = (string)($rowIndex + 1);

            if ($mappedRow !== []) {
                $dataRows[] = $mappedRow;
            }
        }

        return $dataRows;
    }

    /**
     * Convertit une référence de cellule XLSX (ex : "A1", "B3", "AB12") en index de colonne 0-basé.
     * Nécessaire pour gérer les cellules vides qui sont omises du XML et provoqueraient sinon
     * un décalage de toutes les colonnes suivantes.
     *
     * A→0, B→1, Z→25, AA→26, AB→27, …
     */
    private static function cellRefToColumnIndex(string $ref): int
    {
        // Extrait uniquement les lettres (partie colonne)
        $letters = preg_replace('/[^A-Za-z]/', '', $ref);
        if ($letters === '' || $letters === null) {
            return 0;
        }
        $letters = strtoupper($letters);
        $index   = 0;
        $len     = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
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

        $sharedStrings->registerXPathNamespace('main', self::XLSX_NS);
        $items = $sharedStrings->xpath('//main:si');
        if (!is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            $item->registerXPathNamespace('main', self::XLSX_NS);
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
