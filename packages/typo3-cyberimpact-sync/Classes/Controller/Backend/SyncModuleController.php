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
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\PathUtility;

final class SyncModuleController
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly CyberimpactClient $cyberimpactClient,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly StorageRepository $storageRepository,
        private readonly RunManager $runManager,
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly ExcelChunkReader $excelChunkReader,
        private readonly ContactRowMapper $contactRowMapper,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $flashMessages = [];
        if (strtoupper($request->getMethod()) === 'POST') {
            $flashMessages[] = $this->handleUpload($request);
        }

        // Build API URLs using UriBuilder
        $apiUrls = [
            'testToken' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.test-token'),
            'cyberimpactFields' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.cyberimpact-fields'),
            'cyberimpactGroups' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.cyberimpact-groups'),
            'columnMapping' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.column-mapping'),
            'selectedGroup' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.selected-group'),
            'exactSyncSettings' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.exact-sync-settings'),
        ];

        $queryParams = $request->getQueryParams();
        $content = implode('', $flashMessages) . $this->renderUploadForm($apiUrls);
        $content .= $this->renderExactSyncSettings($apiUrls);
        $content .= $this->renderRunsList();

        $runUid = (int)($queryParams['run'] ?? 0);
        if ($runUid > 0) {
            $content .= $this->renderRunDetail($runUid);
        }

        $jsUrl = PathUtility::getPublicResourceWebPath('EXT:cyberimpact_sync/Resources/Public/JavaScript/sync-module.js');
        $content .= '<script src="' . htmlspecialchars($jsUrl) . '"></script>';

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
            $result = $this->cyberimpactClient->checkConnection($token);

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
            $customFields = $this->cyberimpactClient->fetchCustomFields();

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
            $groups = $this->cyberimpactClient->fetchGroups();

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

    /**
     * Sauvegarde l'action exactSync pour les contacts manquants (unsubscribe ou delete).
     */
    public function saveExactSyncSettings(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Method not allowed'], 405);
        }

        try {
            $parsedBody = $request->getParsedBody();
            $action = trim((string)($parsedBody['missing_contacts_action'] ?? 'unsubscribe'));

            if (!in_array($action, ['unsubscribe', 'delete'], true)) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'Action invalide. Valeurs acceptées: unsubscribe, delete',
                ], 400);
            }

            $settings = ImportSettingsRepository::make()->findFirst();
            $settings->setMissingContactsAction($action);
            ImportSettingsRepository::make()->update($settings);

            return new JsonResponse([
                'ok' => true,
                'message' => 'Paramètre de synchronisation exacte sauvegardé',
                'action' => $action,
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

    private function renderUploadForm(array $apiUrls = []): string
    {
        $dataAttrs = '';
        if (!empty($apiUrls)) {
            $dataAttrs = ' data-url-test-token="' . htmlspecialchars($apiUrls['testToken'] ?? '') . '"'
                . ' data-url-cyberimpact-fields="' . htmlspecialchars($apiUrls['cyberimpactFields'] ?? '') . '"'
                . ' data-url-cyberimpact-groups="' . htmlspecialchars($apiUrls['cyberimpactGroups'] ?? '') . '"'
                . ' data-url-column-mapping="' . htmlspecialchars($apiUrls['columnMapping'] ?? '') . '"'
                . ' data-url-selected-group="' . htmlspecialchars($apiUrls['selectedGroup'] ?? '') . '"'
                . ' data-url-exact-sync-settings="' . htmlspecialchars($apiUrls['exactSyncSettings'] ?? '') . '"';
        }

        return <<<HTML
<style>
.cyberimpact-container {
    max-width: 1200px;
    margin: 2rem 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.cyberimpact-header {
    background: #667eea;
    color: white;
    padding: 3rem 2rem;
    border-radius: 8px;
    margin-bottom: 2.5rem;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.15);
}

.cyberimpact-header h1 {
    margin: 0 0 0.5rem 0;
    font-size: 2rem;
    font-weight: 600;
    letter-spacing: -0.5px;
}

.cyberimpact-header p {
    margin: 0;
    opacity: 0.95;
    font-size: 0.95rem;
}

.cyberimpact-section {
    margin-bottom: 2.5rem;
}

.cyberimpact-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
}

.cyberimpact-card:hover {
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.cyberimpact-card-header {
    background: #f5f7fa;
    border-bottom: 1px solid #e5e7eb;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.cyberimpact-card-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #1f2937;
}

.cyberimpact-step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: #667eea;
    color: white;
    border-radius: 50%;
    font-weight: 700;
    font-size: 0.9rem;
}

.cyberimpact-card-body {
    padding: 2rem;
}

.cyberimpact-form-group {
    margin-bottom: 1.25rem;
}

.cyberimpact-form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #1f2937;
    font-size: 0.9rem;
}

.cyberimpact-form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    background: white;
}

.cyberimpact-form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.cyberimpact-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.cyberimpact-btn-primary {
    background: #667eea;
    color: white;
}

.cyberimpact-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
}

.cyberimpact-btn-secondary {
    background: #f3f4f6;
    color: #1f2937;
    border: 1px solid #d1d5db;
}

.cyberimpact-btn-secondary:hover {
    background: #e5e7eb;
}

.cyberimpact-btn-success {
    background: #10b981;
    color: white;
}

.cyberimpact-btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
}

.cyberimpact-alert {
    padding: 1rem 1.25rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    border-left: 4px solid;
    font-size: 0.9rem;
}

.cyberimpact-alert-success {
    background: #ecfdf5;
    border-color: #10b981;
    color: #065f46;
}

.cyberimpact-alert-danger {
    background: #fef2f2;
    border-color: #ef4444;
    color: #7f1d1d;
}

.cyberimpact-alert-info {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #1e40af;
}

.cyberimpact-loading {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #6b7280;
    font-size: 0.85rem;
}

.cyberimpact-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #d1d5db;
    border-top-color: #667eea;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.cyberimpact-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.cyberimpact-table thead tr {
    background: #f9fafb;
    border-bottom: 2px solid #e5e7eb;
}

.cyberimpact-table th {
    padding: 0.75rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
}

.cyberimpact-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #f3f4f6;
}

.cyberimpact-table tbody tr:hover {
    background: #f9fafb;
}

.cyberimpact-hint {
    color: #6b7280;
    font-size: 0.85rem;
    margin-top: 0.5rem;
}

.cyberimpact-status-badge {
    display: inline-block;
    padding: 0.35rem 0.75rem;
    background: #ecfdf5;
    color: #065f46;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.8rem;
}

.cyberimpact-hidden { display: none !important; }
</style>

<div id="cyberimpact-module-container"$dataAttrs>
    <!-- Header -->
    <div class="cyberimpact-container">
        <div class="cyberimpact-header">
            <h1>🚀 Cyberimpact Sync Pro</h1>
            <p>Gérez vos imports de contacts avec simplicité et efficacité</p>
        </div>

    <!-- Section 1: Token -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">1</span>
                <h3>Établir la connexion</h3>
            </div>
            <div class="cyberimpact-card-body">
                <form id="token_form" onsubmit="return false;">
                    <div class="cyberimpact-form-group" style="max-width: 450px;">
                        <label for="cyberimpact_token">Token API Cyberimpact</label>
                        <input type="password" class="cyberimpact-form-control" id="cyberimpact_token" 
                               name="cyberimpact_token" placeholder="Collez votre token API"
                               autocomplete="off">
                        <p class="cyberimpact-hint">Votre token de sécurité depuis Cyberimpact</p>
                    </div>
                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <button type="button" id="test_token_btn" class="cyberimpact-btn cyberimpact-btn-primary">
                            ✓ Tester et sauvegarder
                        </button>
                        <span id="token_loading" class="cyberimpact-loading cyberimpact-hidden">
                            <span class="cyberimpact-spinner"></span>
                            Vérification en cours…
                        </span>
                    </div>
                </form>
                
                <div id="token_status" class="cyberimpact-hidden" style="margin-top: 1.5rem;">
                    <div class="cyberimpact-alert cyberimpact-alert-success">
                        <strong>✓ Connexion validée</strong><br>
                        Compte: <strong id="account_name"></strong><br>
                        Utilisateur: <em id="account_user"></em> (<em id="account_email"></em>)
                    </div>
                </div>
                
                <div id="token_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>
            </div>
        </div>
    </div>

    <!-- Section 2: Mapping -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">2</span>
                <h3>Configurer le mapping</h3>
            </div>
            <div class="cyberimpact-card-body">
                <p class="cyberimpact-hint" style="margin-bottom: 1.5rem;">
                    Associez les colonnes Excel aux champs Cyberimpact. La détection automatique est utilisée si vide.
                </p>
                
                <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary mb-3" id="load_fields_btn">
                    ⬇ Charger les champs Cyberimpact
                </button>
                <span id="fields_loading" class="cyberimpact-loading cyberimpact-hidden ms-2">
                    <span class="cyberimpact-spinner"></span>
                    Chargement…
                </span>
                <div id="fields_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>
                
                <form id="mapping_form" onsubmit="return false;" class="cyberimpact-hidden" style="margin-top: 1.5rem;">
                    <div class="table-responsive" style="margin-bottom: 1.5rem;">
                        <table class="cyberimpact-table">
                            <thead>
                                <tr>
                                    <th>Champ Cyberimpact</th>
                                    <th>Colonne Excel</th>
                                </tr>
                            </thead>
                            <tbody id="mapping_tbody"></tbody>
                        </table>
                    </div>
                    
                    <button type="submit" class="cyberimpact-btn cyberimpact-btn-primary">Enregistrer mapping</button>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="submit" class="cyberimpact-btn cyberimpact-btn-primary">Enregistrer mapping</button>
                        <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary" id="clear_mapping_btn">Effacer</button>
                    </div>
                </form>
                
                <div id="mapping_empty_hint" class="cyberimpact-alert cyberimpact-alert-info">
                    Cliquez sur "Charger les champs Cyberimpact" pour commencer.
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Groupe -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">3</span>
                <h3>Choisir un groupe cible</h3>
            </div>
            <div class="cyberimpact-card-body">
                <p class="cyberimpact-hint" style="margin-bottom: 1.5rem;">
                    Optionnel. Les contacts seront automatiquement ajoutés à ce groupe après l'import.
                </p>
                
                <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary mb-3" id="load_groups_btn">
                    ⬇ Charger les groupes Cyberimpact
                </button>
                <span id="groups_loading" class="cyberimpact-loading cyberimpact-hidden ms-2">
                    <span class="cyberimpact-spinner"></span>
                    Chargement…
                </span>
                <div id="groups_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>
                
                <form id="group_form" onsubmit="return false;" style="margin-top: 1rem;">
                    <div class="cyberimpact-form-group" style="max-width: 450px;">
                        <label for="selected_group_id">Groupe cible</label>
                        <select id="selected_group_id" name="selected_group_id" class="cyberimpact-form-control">
                            <option value="">-- Sélectionner un groupe --</option>
                        </select>
                    </div>
                    <button type="submit" class="cyberimpact-btn cyberimpact-btn-primary">Enregistrer groupe</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Section 4: Import -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">4</span>
                <h3>Importer votre fichier</h3>
            </div>
            <div class="cyberimpact-card-body">
                <form method="post" enctype="multipart/form-data">
                    <div class="cyberimpact-form-group" style="max-width: 450px;">
                        <label for="source_file">Fichier Excel (.xlsx)</label>
                        <input class="cyberimpact-form-control" type="file" id="source_file" 
                               name="source_file" accept=".xlsx" required>
                        <p class="cyberimpact-hint">Sélectionnez un fichier Excel à importer</p>
                    </div>
                    <button class="cyberimpact-btn cyberimpact-btn-success" type="submit">
                        ⬆ Uploader et créer un run
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.mb-3 { margin-bottom: 1rem; }
.ms-2 { margin-left: 0.5rem; }
.mt-3 { margin-top: 1rem; }
</style>
</div>

HTML;
    }

    private function renderExactSyncSettings(array $apiUrls = []): string
    {
        try {
            $settings = ImportSettingsRepository::make()->findFirst();
            $currentAction = $settings->getMissingContactsAction();
        } catch (\Throwable) {
            $currentAction = 'unsubscribe';
        }

        $currentActionAttr = htmlspecialchars($currentAction);
        $exactSyncUrlAttr = htmlspecialchars($apiUrls['exactSyncSettings'] ?? '');

        return <<<HTML
<div class="cyberimpact-section">
    <div class="cyberimpact-card">
        <div class="cyberimpact-card-header">
            <span class="cyberimpact-step-number">⚙</span>
            <h3>Paramètres d'exactSync</h3>
        </div>
        <div class="cyberimpact-card-body" data-current-action="$currentActionAttr" data-url-exact-sync-settings="$exactSyncUrlAttr">
            <p style="margin: 0 0 1.5rem; color: #6b7280; font-size: 0.95rem;">
                Définissez l'action à appliquer aux contacts manquants lors de la synchronisation exacte.
            </p>
            <form id="exactSyncForm" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                <div style="display: flex; gap: 1rem; flex: 1; min-width: 300px;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="missing_contacts_action" value="unsubscribe" style="cursor: pointer;" 
                               id="action-unsubscribe" /> Désabonner
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="missing_contacts_action" value="delete" style="cursor: pointer;" 
                               id="action-delete" /> Supprimer
                    </label>
                </div>
                <button type="button" class="cyberimpact-btn cyberimpact-btn-primary" id="saveExactSyncBtn">
                    Sauvegarder
                </button>
            </form>
            <div id="exactSyncMessage" style="margin-top: 1rem; display: none; padding: 1rem; border-radius: 8px;"></div>
        </div>
    </div>
</div>
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



    private function extensionConfiguration(): ExtensionConfiguration
    {
        return $this->extensionConfiguration;
    }

    private function storageRepository(): StorageRepository
    {
        return $this->storageRepository;
    }

    private function runManager(): RunManager
    {
        return $this->runManager;
    }

    private function runStorage(): RunStorage
    {
        return $this->runStorage;
    }

    private function chunkStorage(): ChunkStorage
    {
        return $this->chunkStorage;
    }

    private function errorStorage(): ErrorStorage
    {
        return $this->errorStorage;
    }

    private function excelChunkReader(): ExcelChunkReader
    {
        return $this->excelChunkReader;
    }

    private function contactRowMapper(): ContactRowMapper
    {
        return $this->contactRowMapper;
    }
}
