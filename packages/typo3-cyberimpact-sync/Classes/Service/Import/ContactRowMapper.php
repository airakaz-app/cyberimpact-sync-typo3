<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Service\Import;

/**
 * Mapper pour convertir les lignes Excel en contacts Cyberimpact.
 *
 * Supporte deux modes :
 * 1) Avec resolvedMap (mapping explicite) : extraction par index
 * 2) Sans resolvedMap (auto-detect) : extraction par alias
 */
final class ContactRowMapper
{
    /**
     * Mappe les lignes en contacts, avec support du mapping explicite.
     *
     * @param array<int, array<string, string>> $rows Lignes Excel
     * @param array{standard:array<string,int>,customFields:array<string,int>}|null $resolvedMap Mapping résolu (null = auto-detect)
     * @return array{contacts: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
     */
    public function mapRows(
        array $rows,
        ?array $resolvedMap = null
    ): array {
        if ($resolvedMap !== null) {
            return $this->mapRowsWithExplicitMapping($rows, $resolvedMap);
        }
        return $this->mapRowsWithAutoDetect($rows);
    }

    /**
     * Mode auto-detect : recherche par alias (comportement historique).
     *
     * @param array<int, array<string, string>> $rows
     * @return array{contacts: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
     */
    private function mapRowsWithAutoDetect(array $rows): array
    {
        $contactsByEmail = [];
        $errors = [];

        foreach ($rows as $row) {
            $rowNumber = (string)($row['_rownum'] ?? '');
            $email = $this->findValue($row, ['email', 'e-mail', 'courriel']);
            $email = strtolower(trim($email));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => 'invalid_email',
                    'message' => 'Email invalide ou manquant.',
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];
                continue;
            }

            $contactsByEmail[$email] = [
                'email' => $email,
                'firstname' => $this->findValue($row, ['firstname', 'first_name', 'prenom', 'prénom']),
                'lastname' => $this->findValue($row, ['lastname', 'last_name', 'nom', 'nom complet']),
                'phone' => $this->findValue($row, ['phone', 'telephone', 'téléphone', 'mobile']),
            ];
        }

        return [
            'contacts' => array_values($contactsByEmail),
            'errors' => $errors,
        ];
    }

    /**
     * Mode explicit mapping : extraction par index du mapping résolu.
     *
     * @param array<int, array<string, int|string>> $rows Lignes avec values numériques (index → value)
     * @param array{standard:array<string,int>,customFields:array<string,int>} $resolvedMap Mapping résolu
     * @return array{contacts: array<int, array<string, mixed>>, errors: array<int, array<string, string>>}
     */
    private function mapRowsWithExplicitMapping(array $rows, array $resolvedMap): array
    {
        $contactsByEmail = [];
        $errors = [];
        $rowIndex = 0;

        foreach ($rows as $row) {
            $rowIndex++;
            $rowNumber = (string)$rowIndex;

            // Extraire l'email (obligatoire)
            $emailIdx = $resolvedMap['standard']['email'] ?? null;
            if ($emailIdx === null) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => 'no_email_column',
                    'message' => 'Colonne email non trouvée dans le mapping.',
                    'payload' => '{}',
                ];
                continue;
            }

            $email = strtolower(trim((string)($row[$emailIdx] ?? '')));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = [
                    'row' => $rowNumber,
                    'code' => 'invalid_email',
                    'message' => 'Email invalide ou manquant.',
                    'payload' => json_encode($row, JSON_UNESCAPED_UNICODE) ?: '{}',
                ];
                continue;
            }

            $contact = ['email' => $email];

            // Extraire les champs standards
            foreach ($resolvedMap['standard'] as $field => $idx) {
                if ($field === 'email') {
                    continue;
                }
                $value = trim((string)($row[$idx] ?? ''));
                if ($value !== '') {
                    $contact[$field] = $value;
                }
            }

            // Extraire les champs personnalisés
            if (!empty($resolvedMap['customFields'])) {
                $customFields = [];
                foreach ($resolvedMap['customFields'] as $fieldId => $idx) {
                    $value = trim((string)($row[$idx] ?? ''));
                    if ($value !== '') {
                        $customFields[$fieldId] = $value;
                    }
                }
                if (!empty($customFields)) {
                    $contact['customFields'] = $customFields;
                }
            }

            $contactsByEmail[$email] = $contact;
        }

        return [
            'contacts' => array_values($contactsByEmail),
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, string> $row
     * @param array<int, string> $keys
     */
    private function findValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return trim((string)$row[$key]);
            }
        }

        return '';
    }
}
