<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Controller\Backend;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;
use Cyberimpact\CyberimpactSync\Service\Import\ContactRowMapper;
use Cyberimpact\CyberimpactSync\Service\Import\ExcelChunkReader;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class SyncModuleController
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        $moduleTemplate->setTitle('Cyberimpact Sync');

        $flashMessages = [];
        if (strtoupper($request->getMethod()) === 'POST') {
            $flashMessages[] = $this->handleUpload($request);
        }

        $queryParams = $request->getQueryParams();
        $content = implode('', $flashMessages) . $this->renderUploadForm();
        $content .= $this->renderRunsList();

        $runUid = (int)($queryParams['run'] ?? 0);
        if ($runUid > 0) {
            $content .= $this->renderRunDetail($runUid);
        }

        return new HtmlResponse($content);
    }

    /**
     * Teste le token Cyberimpact fourni et le sauvegarde s'il est valide.
     */
    public function testToken(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        $parsedBody = $request->getParsedBody();
        $token = trim((string)($parsedBody['cyberimpact_token'] ?? ''));

        if (empty($token)) {
            return new JsonResponse(['error' => 'Token manquant'], 400);
        }

        try {
            $client = GeneralUtility::makeInstance(CyberimpactClient::class);
            $result = $client->checkConnection($token);

            if (!$result['ok']) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => $result['message'],
                ], 502);
            }

            // Sauvegarder le token et les infos du compte
            $settings = ImportSettingsRepository::make()->findFirst();
            $settings->setCyberimpactToken($token);
            $settings->setCyberimpactPing($result['ping'] ?? 'success');
            $settings->setCyberimpactUsername($result['username'] ?? '');
            $settings->setCyberimpactEmail($result['email'] ?? '');
            $settings->setCyberimpactAccount($result['account'] ?? '');
            $settings->setCyberimpactPingCheckedAt((int)time());
            ImportSettingsRepository::make()->update($settings);

            return new JsonResponse([
                'ok' => true,
                'message' => 'Token sauvegardé avec succès',
                'account' => $result['account'] ?? '',
                'username' => $result['username'] ?? '',
                'email' => $result['email'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Erreur lors de la vérification: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Retourne les champs Cyberimpact disponibles (standards + custom).
     */
    public function fetchCyberimpactFields(): ResponseInterface
    {
        try {
            $client = GeneralUtility::makeInstance(CyberimpactClient::class);
            $customFields = $client->fetchCustomFields();

            // Champs standards disponibles
            $standardFields = [
                'email' => 'Email (obligatoire)',
                'firstname' => 'Prénom',
                'lastname' => 'Nom',
                'company' => 'Entreprise',
                'language' => 'Langue',
                'postalCode' => 'Code postal',
                'country' => 'Pays',
                'note' => 'Note',
            ];

            return new JsonResponse([
                'standardFields' => $standardFields,
                'customFields' => array_values(array_map(static fn(array $field): array => [
                    'id' => (int)($field['id'] ?? 0),
                    'name' => (string)($field['name'] ?? ''),
                    'type' => (string)($field['type'] ?? ''),
                ], $customFields)),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Impossible de récupérer les champs Cyberimpact: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Retourne la liste des groupes Cyberimpact disponibles.
     */
    public function fetchCyberimpactGroups(): ResponseInterface
    {
        try {
            $client = GeneralUtility::makeInstance(CyberimpactClient::class);
            $groups = $client->fetchGroups();

            return new JsonResponse([
                'groups' => array_values(array_map(static fn(array $group): array => [
                    'id' => (int)($group['id'] ?? 0),
                    'title' => (string)($group['title'] ?? ''),
                    'membersCount' => (int)($group['membersCount'] ?? 0),
                ], $groups)),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Impossible de récupérer les groupes Cyberimpact: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Sauvegarde le mapping colonnes Excel → champs Cyberimpact.
     */
    public function saveColumnMapping(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        try {
            $parsedBody = $request->getParsedBody();

            $standard = (array)($parsedBody['standard'] ?? []);
            $customFields = (array)($parsedBody['customFields'] ?? []);

            // Filtrer les valeurs vides
            $standard = array_filter($standard, fn($v) => !empty(trim((string)$v)));
            $customFields = array_filter($customFields, fn($v) => !empty(trim((string)$v)));

            // Si tout est vide, supprimer le mapping (retour à la détection auto)
            $mapping = (!empty($standard) || !empty($customFields))
                ? ['standard' => $standard, 'customFields' => $customFields]
                : null;

            $settings = ImportSettingsRepository::make()->findFirst();
            $settings->setColumnMapping($mapping);
            ImportSettingsRepository::make()->update($settings);

            return new JsonResponse([
                'ok' => true,
                'message' => $mapping === null
                    ? 'Mapping supprimé — détection automatique activée'
                    : 'Mapping des colonnes sauvegardé',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Erreur lors de la sauvegarde: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Sauvegarde le groupe Cyberimpact cible pour l'affectation post-import.
     */
    public function saveSelectedGroup(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        try {
            $parsedBody = $request->getParsedBody();
            $groupId = (int)($parsedBody['selected_group_id'] ?? 0) ?: null;

            $settings = ImportSettingsRepository::make()->findFirst();
            $settings->setSelectedGroupId($groupId);
            ImportSettingsRepository::make()->update($settings);

            return new JsonResponse([
                'ok' => true,
                'message' => $groupId === null
                    ? 'Groupe supprimé'
                    : 'Groupe Cyberimpact sauvegardé',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'Erreur lors de la sauvegarde: ' . $e->getMessage(),
            ], 502);
        }
    }

    private function handleUpload(ServerRequestInterface $request): string
    {
        $uploadedFiles = $request->getUploadedFiles();
        $uploadedFile = $uploadedFiles['source_file'] ?? null;

        if ($uploadedFile === null || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return '<div class="alert alert-danger">Upload invalide.</div>';
        }

        $originalName = $uploadedFile->getClientFilename() ?? '';
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            return '<div class="alert alert-danger">Seuls les fichiers .xlsx sont autorisés.</div>';
        }

        // Charger les settings (mapping + groupe)
        $importSettings = ImportSettingsRepository::make()->findFirst();
        $selectedGroupId = $importSettings->getSelectedGroupId();

        $settings = $this->getSettings();
        $storageUid = (int)($settings['falStorageUid'] ?? 1);
        $incomingFolder = (string)($settings['incomingFolder'] ?? 'incoming/');

        $storage = $this->storageRepository()->getStorageByUid($storageUid);
        if (!$storage) {
            return '<div class="alert alert-danger">FAL storage not found: ' . htmlspecialchars((string)$storageUid) . '</div>';
        }
        if (!$storage->hasFolder($incomingFolder)) {
            $storage->createFolder($incomingFolder);
        }

        $folder = $storage->getFolder($incomingFolder);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cyberimpact_');
        if ($temporaryPath === false) {
            return '<div class="alert alert-danger">Impossible de créer un fichier temporaire.</div>';
        }

        $uploadedFile->moveTo($temporaryPath);

        $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: ('import_' . time() . '.xlsx');
        $falFile = $storage->addFile($temporaryPath, $folder, $safeFileName, 'changeName');

        $runUid = $this->runManager()->queueFromFalFile($falFile->getUid(), true, false);
        if ($runUid === null) {
            return '<div class="alert alert-warning">Un run est déjà en cours pour ce fichier.</div>';
        }

        // Analyser les lignes Excel (avec mapping configuré)
        $stats = $this->analyzeRows($falFile->getForLocalProcessing(), $runUid);
        $this->runManager()->updateRunTotalRows($runUid, $stats['totalRows']);
        
        // Créer les chunks avec le groupe cible (si configuré)
        $chunkCount = $this->runManager()->createChunksFromContacts($runUid, $stats['contacts'], 500);

        $groupInfo = '';
        if ($selectedGroupId !== null && $selectedGroupId > 0) {
            $groupInfo = ' (groupe cible: #' . $selectedGroupId . ')';
        }
        if ($stats['totalRows'] > 0 && $importSettings->getColumnMapping() !== null) {
            $groupInfo .= ' (mapping appliqué)';
        }

        return '<div class="alert alert-success">Fichier importé et run #' . (int)$runUid . ' créé ('
            . $stats['totalRows'] . ' lignes, '
            . $stats['validRows'] . ' contacts valides, '
            . $stats['errorCount'] . ' erreurs, '
            . $chunkCount . ' chunks' . $groupInfo . ').</div>';
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = $this->extensionConfiguration()->get('cyberimpact_sync');
            return is_array($settings) ? $settings : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function renderUploadForm(): string
    {
        return <<<'HTML'
<!-- Section 1: Token Cyberimpact -->
<div class="card" style="margin-bottom: 1.5rem; margin-top: 1rem;">
    <div class="card-header">
        <h3 style="margin: 0;">1) Token Cyberimpact (prioritaire)</h3>
    </div>
    <div class="card-body">
        <form id="token_form" method="POST" style="max-width: 500px;">
            <div class="form-group mb-3">
                <label for="cyberimpact_token" class="form-label"><strong>Token API</strong></label>
                <input type="password" class="form-control" id="cyberimpact_token" 
                       name="cyberimpact_token" placeholder="Entrez votre token Cyberimpact"
                       autocomplete="off">
            </div>
            <div>
                <button type="button" id="test_token_btn" class="btn btn-primary">Tester et sauvegarder</button>
                <span id="token_loading" class="ms-2 d-none text-muted small">Vérification en cours…</span>
            </div>
        </form>
        
        <!-- Status du token si validé -->
        <div id="token_status" style="margin-top: 1.5rem; display:none;">
            <div class="alert alert-success mb-0">
                <strong>✓ Token validé</strong><br>
                Compte: <strong id="account_name"></strong><br>
                Utilisateur: <em id="account_user"></em> (<em id="account_email"></em>)
            </div>
        </div>
        
        <div id="token_error" class="alert alert-danger mt-3" style="display:none;"></div>
    </div>
</div>

<!-- Section 2: Mapping des colonnes Excel -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h3 style="margin: 0;">2) Mapping des colonnes Excel</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Définissez quelle colonne Excel correspond à quel champ Cyberimpact.
            Laissez vide pour ne pas mapper un champ. Si aucun mapping n'est configuré, la détection automatique est utilisée.
        </p>
        
        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="load_fields_btn">
            ⬇ Charger les champs Cyberimpact
        </button>
        <span id="fields_loading" class="ms-2 d-none text-muted small">Chargement…</span>
        <div id="fields_error" class="alert alert-danger mt-2" style="display:none;"></div>
        
        <form id="mapping_form" method="POST" style="display:none;">
            <div class="table-responsive">
                <table class="table table-sm" style="background: #f8f9fa;">
                    <thead>
                        <tr style="background: #e9ecef;">
                            <th>Champ Cyberimpact</th>
                            <th style="width: 300px;">Colonne Excel</th>
                        </tr>
                    </thead>
                    <tbody id="mapping_tbody"></tbody>
                </table>
            </div>
            
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Enregistrer mapping</button>
                <button type="button" class="btn btn-outline-secondary" id="clear_mapping_btn">Effacer le mapping</button>
            </div>
        </form>
        
        <div id="mapping_empty_hint" class="alert alert-info mt-3">
            Cliquez sur "Charger les champs Cyberimpact" pour commencer à configurer le mapping.
        </div>
    </div>
</div>

<!-- Section 3: Affectation à un groupe -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">
        <h3 style="margin: 0;">3) Affectation à un groupe Cyberimpact</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Optionnel. Après l'import, les contacts créés/mis à jour seront ajoutés au groupe sélectionné.
        </p>
        
        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="load_groups_btn">
            ⬇ Charger les groupes Cyberimpact
        </button>
        <span id="groups_loading" class="ms-2 d-none text-muted small">Chargement…</span>
        <div id="groups_error" class="alert alert-danger mt-2" style="display:none;"></div>
        
        <form id="group_form" method="POST">
            <div class="form-group" style="max-width: 500px;">
                <label for="selected_group_id" class="form-label"><strong>Groupe cible</strong></label>
                <select id="selected_group_id" name="selected_group_id" class="form-select">
                    <option value="">-- Sélectionner un groupe --</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Enregistrer groupe</button>
        </form>
    </div>
</div>

<!-- Section 4: Upload du fichier Excel -->
<div class="card">
    <div class="card-header">
        <h3 style="margin: 0;">4) Importer un fichier Excel</h3>
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" style="max-width: 640px;">
            <div class="form-group mb-3">
                <label for="source_file" class="form-label"><strong>Fichier Excel (.xlsx)</strong></label>
                <input class="form-control" type="file" id="source_file" name="source_file" accept=".xlsx" required>
                <div class="form-text">Le fichier sera traité avec le mapping et le groupe configurés ci-dessus.</div>
            </div>
            <button class="btn btn-success" type="submit">⬆ Uploader et créer un run</button>
        </form>
    </div>
</div>

<script>
(function() {
    const tokenForm = document.getElementById('token_form');
    const testTokenBtn = document.getElementById('test_token_btn');
    const tokenInput = document.getElementById('cyberimpact_token');
    const tokenLoading = document.getElementById('token_loading');
    const tokenStatus = document.getElementById('token_status');
    const tokenError = document.getElementById('token_error');
    
    const loadFieldsBtn = document.getElementById('load_fields_btn');
    const fieldsLoading = document.getElementById('fields_loading');
    const fieldsError = document.getElementById('fields_error');
    const mappingForm = document.getElementById('mapping_form');
    const mappingEmptyHint = document.getElementById('mapping_empty_hint');
    const mappingTbody = document.getElementById('mapping_tbody');
    const clearMappingBtn = document.getElementById('clear_mapping_btn');
    
    const loadGroupsBtn = document.getElementById('load_groups_btn');
    const groupsLoading = document.getElementById('groups_loading');
    const groupsError = document.getElementById('groups_error');
    const groupSelect = document.getElementById('selected_group_id');
    const groupForm = document.getElementById('group_form');

    // Test Token
    if (testTokenBtn) {
        testTokenBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const token = tokenInput.value.trim();
            if (!token) {
                alert('Veuillez entrer un token');
                return;
            }
            
            tokenLoading.classList.remove('d-none');
            tokenError.style.display = 'none';
            tokenStatus.style.display = 'none';
            testTokenBtn.disabled = true;
            
            try {
                const response = await fetch(window.location.pathname + '?route=test-token', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: 'cyberimpact_token=' + encodeURIComponent(token),
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    document.getElementById('account_name').textContent = data.account || 'N/A';
                    document.getElementById('account_user').textContent = data.username || '-';
                    document.getElementById('account_email').textContent = data.email || '-';
                    tokenStatus.style.display = 'block';
                    tokenInput.type = 'password';
                    tokenInput.value = '';
                } else {
                    tokenError.textContent = data.error || 'Erreur inconnue';
                    tokenError.style.display = 'block';
                }
            } catch (err) {
                tokenError.textContent = 'Erreur réseau: ' + err.message;
                tokenError.style.display = 'block';
            } finally {
                tokenLoading.classList.add('d-none');
                testTokenBtn.disabled = false;
            }
        });
    }

    // Load Fields
    if (loadFieldsBtn) {
        loadFieldsBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            fieldsLoading.classList.remove('d-none');
            fieldsError.style.display = 'none';
            loadFieldsBtn.disabled = true;
            
            try {
                const response = await fetch(window.location.pathname + '?route=cyberimpact-fields', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                
                if (data.error) {
                    fieldsError.textContent = data.error;
                    fieldsError.style.display = 'block';
                    return;
                }
                
                mappingTbody.innerHTML = '';
                
                // Standard fields
                for (const [key, label] of Object.entries(data.standardFields)) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + label + '</td>' +
                        '<td><input type="text" name="standard[' + key + ']" class="form-control form-control-sm" ' +
                        'placeholder="Nom de la colonne Excel…"></td>';
                    mappingTbody.appendChild(tr);
                }
                
                // Custom fields
                for (const field of data.customFields) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + field.name + '</td>' +
                        '<td><input type="text" name="customFields[' + field.id + ']" class="form-control form-control-sm" ' +
                        'placeholder="Nom de la colonne Excel…"></td>';
                    mappingTbody.appendChild(tr);
                }
                
                mappingForm.style.display = 'block';
                mappingEmptyHint.style.display = 'none';
            } catch (err) {
                fieldsError.textContent = 'Erreur réseau: ' + err.message;
                fieldsError.style.display = 'block';
            } finally {
                fieldsLoading.classList.add('d-none');
                loadFieldsBtn.disabled = false;
            }
        });
    }

    // Clear Mapping
    if (clearMappingBtn) {
        clearMappingBtn.addEventListener('click', (e) => {
            e.preventDefault();
            mappingTbody.innerHTML = '';
            mappingForm.style.display = 'none';
            mappingEmptyHint.style.display = 'block';
        });
    }

    // Load Groups
    if (loadGroupsBtn) {
        loadGroupsBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            groupsLoading.classList.remove('d-none');
            groupsError.style.display = 'none';
            loadGroupsBtn.disabled = true;
            
            try {
                const response = await fetch(window.location.pathname + '?route=cyberimpact-groups', {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                
                if (data.error) {
                    groupsError.textContent = data.error;
                    groupsError.style.display = 'block';
                    return;
                }
                
                groupSelect.innerHTML = '<option value="">-- Sélectionner un groupe --</option>';
                for (const group of data.groups) {
                    const option = document.createElement('option');
                    option.value = group.id;
                    option.textContent = group.title + ' (' + group.membersCount + ' membres)';
                    groupSelect.appendChild(option);
                }
            } catch (err) {
                groupsError.textContent = 'Erreur réseau: ' + err.message;
                groupsError.style.display = 'block';
            } finally {
                groupsLoading.classList.add('d-none');
                loadGroupsBtn.disabled = false;
            }
        });
    }

    // Save Mapping
    if (mappingForm) {
        mappingForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(mappingForm);
            const data = Object.fromEntries(formData);
            
            try {
                const response = await fetch(window.location.pathname + '?route=column-mapping', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(data),
                });
                
                const result = await response.json();
                if (result.ok) {
                    alert(result.message);
                } else {
                    alert('Erreur: ' + result.error);
                }
            } catch (err) {
                alert('Erreur réseau: ' + err.message);
            }
        });
    }

    // Save Group
    if (groupForm) {
        groupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const groupId = groupSelect.value;
            
            try {
                const response = await fetch(window.location.pathname + '?route=selected-group', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: 'selected_group_id=' + encodeURIComponent(groupId || ''),
                });
                
                const result = await response.json();
                if (result.ok) {
                    alert(result.message);
                } else {
                    alert('Erreur: ' + result.error);
                }
            } catch (err) {
                alert('Erreur réseau: ' + err.message);
            }
        });
    }
})();
</script>

<style>
.card {
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.05);
}
.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #ddd;
    padding: 0.75rem 1.25rem;
}
.card-body {
    padding: 1.25rem;
}
.d-none {
    display: none;
}
.ms-2 {
    margin-left: 0.5rem;
}
</style>
HTML;
    }

    private function renderRunsList(): string
    {
        $rows = $this->runStorage()->findRecentRuns(30);
        if ($rows === []) {
            return '<hr><h3>Runs récents</h3><p>Aucun run.</p>';
        }

        $html = '<hr><h3>Runs récents</h3>';
        $html .= '<table class="table table-striped" style="margin-top: 0.75rem;">';
        $html .= '<thead><tr>'
            . '<th>#</th><th>Statut</th><th>Dry-run</th><th>Exact-sync</th><th>Rows</th><th>Upsert</th><th>Report UID</th><th>Détail</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $run) {
            $runUid = (int)($run['uid'] ?? 0);
            $html .= '<tr>';
            $html .= '<td>' . $runUid . '</td>';
            $html .= '<td><code>' . htmlspecialchars((string)($run['status'] ?? '')) . '</code></td>';
            $html .= '<td>' . (((int)($run['dry_run'] ?? 0)) === 1 ? 'oui' : 'non') . '</td>';
            $html .= '<td>' . (((int)($run['exact_sync'] ?? 0)) === 1 ? 'oui' : 'non') . '</td>';
            $html .= '<td>' . (int)($run['processed_rows'] ?? 0) . ' / ' . (int)($run['total_rows'] ?? 0) . '</td>';
            $html .= '<td>' . (int)($run['upsert_ok'] ?? 0) . ' ok / ' . (int)($run['upsert_failed'] ?? 0) . ' failed</td>';
            $html .= '<td>' . (int)($run['report_file_uid'] ?? 0) . '</td>';
            $html .= '<td><a class="btn btn-default" href="?id=0&run=' . $runUid . '">Voir</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function renderRunDetail(int $runUid): string
    {
        $run = $this->runStorage()->findRunByUid($runUid);
        if ($run === null) {
            return '<div class="alert alert-warning" style="margin-top: 1rem;">Run introuvable.</div>';
        }

        $chunks = $this->chunkStorage()->findChunksByRunUid($runUid);
        $errors = $this->errorStorage()->findErrorsByRunUid($runUid, 200);

        $html = '<hr><h3>Détail run #' . $runUid . '</h3>';
        $html .= '<p><strong>Statut:</strong> <code>' . htmlspecialchars((string)($run['status'] ?? '')) . '</code> '
            . '| <strong>Dry-run:</strong> ' . (((int)($run['dry_run'] ?? 0)) === 1 ? 'oui' : 'non')
            . ' | <strong>Exact-sync:</strong> ' . (((int)($run['exact_sync'] ?? 0)) === 1 ? 'oui' : 'non')
            . ' | <strong>Confirmé:</strong> ' . (((int)($run['exact_sync_confirmed'] ?? 0)) === 1 ? 'oui' : 'non')
            . '</p>';

        $html .= '<p><strong>Rows:</strong> ' . (int)($run['processed_rows'] ?? 0) . ' / ' . (int)($run['total_rows'] ?? 0)
            . ' | <strong>Upsert:</strong> ' . (int)($run['upsert_ok'] ?? 0) . ' ok / ' . (int)($run['upsert_failed'] ?? 0) . ' failed'
            . ' | <strong>Unsubscribe:</strong> plan=' . (int)($run['unsubscribe_planned'] ?? 0)
            . ', done=' . (int)($run['unsubscribe_done'] ?? 0)
            . ', failed=' . (int)($run['unsubscribe_failed'] ?? 0)
            . ' | <strong>Report UID:</strong> ' . (int)($run['report_file_uid'] ?? 0)
            . '</p>';

        $html .= '<h4>Chunks (' . count($chunks) . ')</h4>';
        $html .= '<table class="table table-bordered"><thead><tr><th>#Chunk</th><th>UID</th><th>Statut</th><th>Attempts</th></tr></thead><tbody>';
        foreach ($chunks as $chunk) {
            $html .= '<tr>'
                . '<td>' . (int)($chunk['chunk_index'] ?? 0) . '</td>'
                . '<td>' . (int)($chunk['uid'] ?? 0) . '</td>'
                . '<td><code>' . htmlspecialchars((string)($chunk['status'] ?? '')) . '</code></td>'
                . '<td>' . (int)($chunk['attempt_count'] ?? 0) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h4>Erreurs récentes (' . count($errors) . ')</h4>';
        $html .= '<table class="table table-bordered"><thead><tr><th>UID</th><th>Chunk</th><th>Stage</th><th>Code</th><th>Message</th></tr></thead><tbody>';
        foreach ($errors as $error) {
            $html .= '<tr>'
                . '<td>' . (int)($error['uid'] ?? 0) . '</td>'
                . '<td>' . (int)($error['chunk_uid'] ?? 0) . '</td>'
                . '<td><code>' . htmlspecialchars((string)($error['stage'] ?? '')) . '</code></td>'
                . '<td><code>' . htmlspecialchars((string)($error['code'] ?? '')) . '</code></td>'
                . '<td>' . htmlspecialchars((string)($error['message'] ?? '')) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Analyse les lignes Excel avec support du mapping configuré et détection automatique.
     * 
     * @return array{totalRows: int, validRows: int, errorCount: int, contacts: array<int, array<string, mixed>>}
     */
    private function analyzeRows(string $localFilePath, int $runUid): array
    {
        $totalRows = 0;
        $validRows = 0;
        $errorCount = 0;
        $allContacts = [];

        // Charger les settings pour obtenir le mapping configuré
        $settings = ImportSettingsRepository::make()->findFirst();
        $columnMapping = $settings->getColumnMapping();

        // Lire les chunks avec le mapping (null = auto-detect)
        foreach ($this->excelChunkReader()->readChunksFromLocalFile($localFilePath, 500, $columnMapping) as $chunk) {
            $totalRows += count($chunk['rows'] ?? []);

            // Passer le resolvedMap au mapper
            $mapped = $this->contactRowMapper()->mapRows(
                $chunk['rows'] ?? [],
                $chunk['resolvedMap'] ?? null
            );
            
            $validRows += count($mapped['contacts']);
            $allContacts = array_merge($allContacts, $mapped['contacts']);

            foreach ($mapped['errors'] as $error) {
                $errorCount++;
                $this->errorStorage()->createRunError(
                    $runUid,
                    'parse',
                    (string)($error['code'] ?? 'parse_error'),
                    (string)($error['message'] ?? 'Erreur de parsing'),
                    (string)($error['payload'] ?? '')
                );
            }
        }

        return [
            'totalRows' => $totalRows,
            'validRows' => $validRows,
            'errorCount' => $errorCount,
            'contacts' => $allContacts,
        ];
    }

    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }

    private function extensionConfiguration(): ExtensionConfiguration
    {
        return GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    private function storageRepository(): StorageRepository
    {
        return GeneralUtility::makeInstance(StorageRepository::class);
    }

    private function runManager(): RunManager
    {
        return GeneralUtility::makeInstance(RunManager::class);
    }

    private function runStorage(): RunStorage
    {
        return GeneralUtility::makeInstance(RunStorage::class, $this->connectionPool());
    }

    private function chunkStorage(): ChunkStorage
    {
        return GeneralUtility::makeInstance(ChunkStorage::class, $this->connectionPool());
    }

    private function errorStorage(): ErrorStorage
    {
        return GeneralUtility::makeInstance(ErrorStorage::class, $this->connectionPool());
    }

    private function connectionPool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function excelChunkReader(): ExcelChunkReader
    {
        return GeneralUtility::makeInstance(ExcelChunkReader::class);
    }

    private function contactRowMapper(): ContactRowMapper
    {
        return GeneralUtility::makeInstance(ContactRowMapper::class);
    }
}
