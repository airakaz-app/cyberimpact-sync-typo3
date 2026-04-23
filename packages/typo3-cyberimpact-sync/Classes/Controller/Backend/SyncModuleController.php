<?php

declare(strict_types=1);

namespace Cyberimpact\CyberimpactSync\Controller\Backend;

use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ChunkStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ErrorStorage;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\ImportSettingsRepository;
use Cyberimpact\CyberimpactSync\Infrastructure\Persistence\RunStorage;
use Cyberimpact\CyberimpactSync\Service\Cyberimpact\CyberimpactClient;
use Cyberimpact\CyberimpactSync\Service\Run\ChunkProcessor;
use Cyberimpact\CyberimpactSync\Service\Run\RunManager;
use Cyberimpact\CyberimpactSync\Service\Run\RunPreparationService;
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
        private readonly ImportSettingsRepository $importSettingsRepository,
        private readonly RunManager $runManager,
        private readonly RunStorage $runStorage,
        private readonly ChunkStorage $chunkStorage,
        private readonly ErrorStorage $errorStorage,
        private readonly RunPreparationService $runPreparationService,
        private readonly ChunkProcessor $chunkProcessor,
    ) {
    }

    // =========================================================================
    // Routes principales
    // =========================================================================

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $flash = '';
            if (strtoupper($request->getMethod()) === 'POST') {
                $flash = $this->handleUpload($request);
            }

            $apiUrls = $this->buildApiUrls();
            $content = $flash
                . $this->renderUploadForm($apiUrls)
                . $this->renderRunsList();

            $runUid = (int)($request->getQueryParams()['run'] ?? 0);
            if ($runUid > 0) {
                $content .= $this->renderRunDetail($runUid);
            }

            $jsUrl = PathUtility::getPublicResourceWebPath('EXT:cyberimpact_sync/Resources/Public/JavaScript/sync-module.js');
            if ($jsUrl) {
                $content .= '<script src="' . htmlspecialchars($jsUrl) . '"></script>';
            }

            return new HtmlResponse($content);
        } catch (\Throwable $e) {
            return new HtmlResponse(
                '<div style="background:#fee2e2;border:1px solid #fca5a5;padding:1rem;margin:1rem;border-radius:8px;">'
                . '<strong>Erreur lors du chargement du module :</strong><br>'
                . htmlspecialchars($e->getMessage())
                . '</div>'
            );
        }
    }

    /** Teste le token Cyberimpact et le sauvegarde s'il est valide. */
    public function testToken(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        $token = trim((string)(($request->getParsedBody())['cyberimpact_token'] ?? ''));
        if ($token === '') {
            return new JsonResponse(['error' => 'Token manquant.'], 400);
        }

        try {
            $result = $this->cyberimpactClient->checkConnection($token);
            if (!$result['ok']) {
                return new JsonResponse(['ok' => false, 'error' => $result['message']], 502);
            }

            $settings = $this->importSettingsRepository->findFirst();
            $settings->setCyberimpactToken($token);
            $settings->setCyberimpactPing($result['ping'] ?? 'success');
            $settings->setCyberimpactUsername($result['username'] ?? '');
            $settings->setCyberimpactEmail($result['email'] ?? '');
            $settings->setCyberimpactAccount($result['account'] ?? '');
            $settings->setCyberimpactPingCheckedAt(time());
            $this->importSettingsRepository->update($settings);

            return new JsonResponse([
                'ok'       => true,
                'message'  => 'Token sauvegardé avec succès.',
                'account'  => $result['account']  ?? '',
                'username' => $result['username']  ?? '',
                'email'    => $result['email']     ?? '',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    /** Retourne les champs Cyberimpact disponibles (standards + personnalisés). */
    public function fetchCyberimpactFields(): ResponseInterface
    {
        try {
            $customFields   = $this->cyberimpactClient->fetchCustomFields();
            $standardFields = [
                'email'      => 'Email (obligatoire)',
                'firstname'  => 'Prénom',
                'lastname'   => 'Nom',
                'company'    => 'Entreprise',
                'language'   => 'Langue',
                'postalCode' => 'Code postal',
                'country'    => 'Pays',
                'note'       => 'Note',
            ];

            return new JsonResponse([
                'standardFields' => $standardFields,
                'customFields'   => array_values(array_map(static fn(array $f): array => [
                    'id'   => (int)($f['id']   ?? 0),
                    'name' => (string)($f['name'] ?? ''),
                    'type' => (string)($f['type'] ?? ''),
                ], $customFields)),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Impossible de récupérer les champs : ' . $e->getMessage()], 502);
        }
    }

    /** Retourne la liste des groupes Cyberimpact disponibles. */
    public function fetchCyberimpactGroups(): ResponseInterface
    {
        try {
            $groups = $this->cyberimpactClient->fetchGroups();

            return new JsonResponse([
                'groups' => array_values(array_map(static fn(array $g): array => [
                    'id'           => (int)($g['id']           ?? 0),
                    'title'        => (string)($g['title']        ?? ''),
                    'membersCount' => (int)($g['membersCount'] ?? 0),
                ], $groups)),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Impossible de récupérer les groupes : ' . $e->getMessage()], 502);
        }
    }

    /** Sauvegarde le mapping colonnes Excel → champs Cyberimpact. */
    public function saveColumnMapping(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $body         = (array)$request->getParsedBody();
            $standard     = array_filter((array)($body['standard']     ?? []), static fn($v) => trim((string)$v) !== '');
            $customFields = array_filter((array)($body['customFields'] ?? []), static fn($v) => trim((string)$v) !== '');

            $mapping  = (!empty($standard) || !empty($customFields))
                ? ['standard' => $standard, 'customFields' => $customFields]
                : null;

            $settings = $this->importSettingsRepository->findFirst();
            $settings->setColumnMapping($mapping);
            $this->importSettingsRepository->update($settings);

            return new JsonResponse([
                'ok'      => true,
                'message' => $mapping === null
                    ? 'Mapping supprimé — détection automatique activée.'
                    : 'Mapping des colonnes sauvegardé.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    /** Sauvegarde le groupe Cyberimpact cible. */
    public function saveSelectedGroup(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $groupId  = (int)(($request->getParsedBody())['selected_group_id'] ?? 0) ?: null;
            $settings = $this->importSettingsRepository->findFirst();
            $settings->setSelectedGroupId($groupId);
            $this->importSettingsRepository->update($settings);

            return new JsonResponse([
                'ok'      => true,
                'message' => $groupId === null ? 'Groupe supprimé.' : 'Groupe Cyberimpact sauvegardé.',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    /** Sauvegarde l'action exactSync pour les contacts manquants. */
    public function saveExactSyncSettings(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $action = trim((string)(($request->getParsedBody())['missing_contacts_action'] ?? 'unsubscribe'));
            if (!in_array($action, ['unsubscribe', 'delete'], true)) {
                return new JsonResponse(['ok' => false, 'error' => 'Action invalide (unsubscribe|delete).'], 400);
            }

            $settings = $this->importSettingsRepository->findFirst();
            $settings->setMissingContactsAction($action);
            $this->importSettingsRepository->update($settings);

            return new JsonResponse([
                'ok'      => true,
                'message' => 'Paramètre de synchronisation exacte sauvegardé.',
                'action'  => $action,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    /** Lance manuellement un run en attente. */
    public function triggerRun(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $body   = json_decode($request->getBody()->getContents(), true);
            $runUid = (int)($body['run_uid'] ?? 0);

            if ($runUid <= 0) {
                return new JsonResponse(['error' => 'UID de run invalide.'], 400);
            }

            $run = $this->runStorage->findRunByUid($runUid);
            if ($run === null) {
                return new JsonResponse(['error' => 'Run introuvable.'], 404);
            }

            if ($run['status'] !== 'queued') {
                return new JsonResponse([
                    'error' => sprintf('Ce run n\'est pas en attente (statut : %s).', $run['status']),
                ], 400);
            }

            $this->chunkProcessor->processRunChunks($runUid);

            $updatedRun = $this->runStorage->findRunByUid($runUid);

            return new JsonResponse([
                'ok'      => true,
                'message' => sprintf('Run #%d traité avec succès.', $runUid),
                'status'  => (string)($updatedRun['status'] ?? 'completed'),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    /** Traite UN chunk en attente pour un run et retourne la progression (polling JS). */
    public function processNextChunk(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $body   = json_decode($request->getBody()->getContents(), true);
            $runUid = (int)($body['run_uid'] ?? 0);

            if ($runUid <= 0) {
                return new JsonResponse(['error' => 'UID de run invalide.'], 400);
            }

            $result = $this->chunkProcessor->processOneChunkForRun($runUid);
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur : ' . $e->getMessage()], 502);
        }
    }

    // =========================================================================
    // Upload : endpoint AJAX (utilisé par le formulaire JS)
    // =========================================================================

    /**
     * Crée le run + chunks depuis un upload multipart/form-data et retourne JSON.
     * Le traitement (appels API Cyberimpact) est déclenché séparément par triggerRun().
     */
    public function handleUploadAjax(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return new JsonResponse(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
        }

        try {
            $result = $this->createRunFromUpload($request);
            if (!$result['ok']) {
                return new JsonResponse(['ok' => false, 'error' => $result['error']], $result['httpCode'] ?? 400);
            }

            $stats = $result['stats'];
            return new JsonResponse([
                'ok'         => true,
                'runUid'     => $result['runUid'],
                'totalRows'  => $stats['totalRows'],
                'validRows'  => $stats['validRows'],
                'errorCount' => $stats['errorCount'],
                'chunkCount' => $stats['chunkCount'],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Erreur serveur : ' . $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Upload : fallback HTML (requête POST classique sans JS)
    // =========================================================================

    private function handleUpload(ServerRequestInterface $request): string
    {
        $result = $this->createRunFromUpload($request);
        if (!$result['ok']) {
            $alertType = ($result['httpCode'] ?? 400) === 409 ? 'warning' : 'danger';
            return $this->alertHtml($alertType, $result['error'] ?? 'Erreur upload.');
        }

        $stats = $result['stats'];
        if ($stats['chunkCount'] === 0) {
            return $this->alertHtml('warning', sprintf(
                'Run #%d créé — aucun contact valide (%d lignes, %d erreurs de parsing).',
                $result['runUid'], $stats['totalRows'], $stats['errorCount']
            ));
        }

        return $this->alertHtml('info', sprintf(
            'Run #%d prêt : %d lignes, %d contacts (%d chunks). Le traitement démarrera automatiquement.',
            $result['runUid'], $stats['totalRows'], $stats['validRows'], $stats['chunkCount']
        ));
    }

    // =========================================================================
    // Logique commune : stockage FAL + création run + préparation chunks
    // =========================================================================

    /**
     * @return array{ok: bool, error?: string, httpCode?: int, runUid?: int, stats?: array{totalRows: int, validRows: int, errorCount: int, chunkCount: int}}
     */
    private function createRunFromUpload(ServerRequestInterface $request): array
    {
        $uploadedFile = ($request->getUploadedFiles())['source_file'] ?? null;
        if ($uploadedFile === null || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Fichier invalide ou erreur lors de l\'upload.', 'httpCode' => 400];
        }

        $originalName = $uploadedFile->getClientFilename() ?? '';
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            return ['ok' => false, 'error' => 'Seuls les fichiers .xlsx sont autorisés.', 'httpCode' => 400];
        }

        $parsedBody     = (array)$request->getParsedBody();
        $exactSync      = true; // Synchronisation exacte activée par défaut
        $importSettings = $this->importSettingsRepository->findFirst();
        $extSettings    = $this->getExtSettings();
        $storageUid     = (int)($extSettings['falStorageUid']  ?? 1);
        $incomingFolder = (string)($extSettings['incomingFolder'] ?? 'incoming/');
        $chunkSize      = max(1, (int)($extSettings['chunkSize'] ?? 500));

        $storage = $this->storageRepository->findByUid($storageUid);
        if (!$storage) {
            return ['ok' => false, 'error' => 'Stockage FAL introuvable (uid=' . $storageUid . ').', 'httpCode' => 500];
        }

        if (!$storage->hasFolder($incomingFolder)) {
            $storage->createFolder($incomingFolder);
        }

        $folder       = $storage->getFolder($incomingFolder);
        $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: ('import_' . time() . '.xlsx');
        $falFile      = $storage->addUploadedFile(
            $uploadedFile,
            $folder,
            $safeFileName,
            \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior::RENAME
        );

        $runUid = $this->runManager->queueFromFalFile($falFile->getUid(), $exactSync);
        if ($runUid === null) {
            return ['ok' => false, 'error' => 'Un run est déjà en cours pour ce fichier.', 'httpCode' => 409];
        }

        // Synchronisation exacte toujours activée et confirmée
        $this->runStorage->markExactSyncConfirmed($runUid);

        $stats = $this->runPreparationService->prepareRun(
            $runUid,
            $falFile->getForLocalProcessing(),
            $chunkSize,
            $importSettings->getColumnMapping()
        );

        return ['ok' => true, 'runUid' => $runUid, 'stats' => $stats];
    }

    // =========================================================================
    // Rendu HTML
    // =========================================================================

    private function renderUploadForm(array $apiUrls = []): string
    {
        try {
            $s                      = $this->importSettingsRepository->findFirst();
            $tokenValidated         = !empty($s->getCyberimpactToken()) ? '1' : '0';
            $accountName            = htmlspecialchars((string)($s->getCyberimpactAccount()  ?? ''));
            $accountUser            = htmlspecialchars((string)($s->getCyberimpactUsername() ?? ''));
            $accountEmail           = htmlspecialchars((string)($s->getCyberimpactEmail()    ?? ''));
            $tokenStatusHidden      = $tokenValidated === '1' ? '' : ' cyberimpact-hidden';
            $tokenFormHidden        = $tokenValidated === '1' ? ' cyberimpact-hidden' : '';
            $mappingBtnHidden       = $s->getColumnMapping() !== null ? ' cyberimpact-hidden' : '';
            $mappingJson            = htmlspecialchars(
                json_encode($s->getColumnMapping() ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
            );
            $currentGroupId         = htmlspecialchars((string)($s->getSelectedGroupId() ?? ''));
            $missingActionLabel     = htmlspecialchars($s->getMissingContactsAction() === 'delete' ? 'Supprimer' : 'Désabonner');
            $mappingBadge           = $s->getColumnMapping() !== null ? 'Configuré ✓' : 'Auto-détection';
            $groupBadge             = ($s->getSelectedGroupId() !== null && $s->getSelectedGroupId() > 0)
                ? 'Groupe #' . $currentGroupId
                : 'Aucun';
            $currentAction          = $s->getMissingContactsAction();
            $exactSyncUrl           = htmlspecialchars($apiUrls['exactSyncSettings'] ?? '');
            $unsubscribeChecked     = $currentAction === 'unsubscribe' ? 'checked' : '';
            $deleteChecked          = $currentAction === 'delete'      ? 'checked' : '';
        } catch (\Throwable) {
            $tokenValidated    = '0';
            $accountName       = $accountUser = $accountEmail = '';
            $tokenStatusHidden = ' cyberimpact-hidden';
            $tokenFormHidden   = '';
            $mappingBtnHidden  = '';
            $mappingJson       = '{}';
            $currentGroupId    = '';
            $missingActionLabel = 'Désabonner';
            $mappingBadge      = 'Auto-détection';
            $groupBadge        = 'Aucun';
            $currentAction     = 'unsubscribe';
            $exactSyncUrl      = '';
            $unsubscribeChecked = 'checked';
            $deleteChecked     = '';
        }

        $dataAttrs = ' data-url-upload="'                . htmlspecialchars($apiUrls['upload']            ?? '') . '"'
            . ' data-url-test-token="'                . htmlspecialchars($apiUrls['testToken']        ?? '') . '"'
            . ' data-url-cyberimpact-fields="'         . htmlspecialchars($apiUrls['cyberimpactFields'] ?? '') . '"'
            . ' data-url-cyberimpact-groups="'         . htmlspecialchars($apiUrls['cyberimpactGroups'] ?? '') . '"'
            . ' data-url-column-mapping="'             . htmlspecialchars($apiUrls['columnMapping']     ?? '') . '"'
            . ' data-url-selected-group="'             . htmlspecialchars($apiUrls['selectedGroup']     ?? '') . '"'
            . ' data-url-trigger-run="'                . htmlspecialchars($apiUrls['triggerRun']        ?? '') . '"'
            . ' data-url-process-next-chunk="'         . htmlspecialchars($apiUrls['processNextChunk']  ?? '') . '"'
            . ' data-token-validated="'                . $tokenValidated . '"'
            . ' data-current-mapping="'                . $mappingJson . '"'
            . ' data-current-group-id="'               . $currentGroupId . '"';

        return <<<HTML
<style>
.cyberimpact-container{max-width:1200px;margin:2rem 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.cyberimpact-header{background:#667eea;color:#fff;padding:3rem 2rem;border-radius:8px;margin-bottom:2.5rem;box-shadow:0 10px 30px rgba(102,126,234,.15)}
.cyberimpact-header h1{margin:0 0 .5rem;font-size:2rem;font-weight:600;letter-spacing:-.5px}
.cyberimpact-header p{margin:0;opacity:.95;font-size:.95rem}
.cyberimpact-section{margin-bottom:2.5rem}
.cyberimpact-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);transition:all .3s ease}
.cyberimpact-card:hover{box-shadow:0 8px 20px rgba(0,0,0,.12)}
.cyberimpact-card-header{background:#f5f7fa;border-bottom:1px solid #e5e7eb;padding:1.5rem;display:flex;align-items:center;gap:.75rem}
.cyberimpact-card-header h3{margin:0;font-size:1.1rem;font-weight:600;color:#1f2937}
.cyberimpact-step-number{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:#667eea;color:#fff;border-radius:50%;font-weight:700;font-size:.9rem}
.cyberimpact-card-body{padding:2rem}
.cyberimpact-form-group{margin-bottom:1.25rem}
.cyberimpact-form-group label{display:block;margin-bottom:.5rem;font-weight:600;color:#1f2937;font-size:.9rem}
.cyberimpact-form-control{width:100%;padding:.75rem 1rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;transition:all .2s ease;background:#fff}
.cyberimpact-form-control:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
.cyberimpact-btn{padding:.75rem 1.5rem;border:none;border-radius:8px;font-weight:600;font-size:.9rem;cursor:pointer;transition:all .2s ease;display:inline-flex;align-items:center;gap:.5rem}
.cyberimpact-btn-primary{background:#667eea;color:#fff}
.cyberimpact-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(102,126,234,.3)}
.cyberimpact-btn-secondary{background:#f3f4f6;color:#1f2937;border:1px solid #d1d5db}
.cyberimpact-btn-secondary:hover{background:#e5e7eb}
.cyberimpact-btn-success{background:#10b981;color:#fff}
.cyberimpact-btn-success:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(16,185,129,.3)}
.cyberimpact-btn-sm{padding:.375rem .75rem;font-size:.8rem;border-radius:6px}
.cyberimpact-alert{padding:1rem 1.25rem;border-radius:8px;margin-bottom:1rem;border-left:4px solid;font-size:.9rem}
.cyberimpact-alert-success{background:#ecfdf5;border-color:#10b981;color:#065f46}
.cyberimpact-alert-danger{background:#fef2f2;border-color:#ef4444;color:#7f1d1d}
.cyberimpact-alert-info{background:#eff6ff;border-color:#3b82f6;color:#1e40af}
.cyberimpact-loading{display:inline-flex;align-items:center;gap:.5rem;color:#6b7280;font-size:.85rem}
.cyberimpact-spinner{display:inline-block;width:16px;height:16px;border:2px solid #d1d5db;border-top-color:#667eea;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.cyberimpact-table{width:100%;border-collapse:collapse;font-size:.9rem}
.cyberimpact-table thead tr{background:#f9fafb;border-bottom:2px solid #e5e7eb}
.cyberimpact-table th{padding:.75rem;text-align:left;font-weight:600;color:#374151}
.cyberimpact-table td{padding:.75rem;border-bottom:1px solid #f3f4f6}
.cyberimpact-table tbody tr:hover{background:#f9fafb}
.cyberimpact-hint{color:#6b7280;font-size:.85rem;margin-top:.5rem}
.cyberimpact-hidden{display:none!important}
.mb-3{margin-bottom:1rem}.ms-2{margin-left:.5rem}
</style>

<div id="cyberimpact-module-container"$dataAttrs>
<div class="cyberimpact-container">
    <div class="cyberimpact-header">
        <h1>Cyberimpact Sync</h1>
        <p>Importez vos contacts Excel directement dans Cyberimpact via l'API</p>
    </div>

    <!-- Étape 1 : Connexion -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">1</span>
                <h3>Connexion API</h3>
            </div>
            <div class="cyberimpact-card-body">
                <form id="token_form"$tokenFormHidden>
                    <div class="cyberimpact-form-group" style="max-width:450px">
                        <label for="cyberimpact_token">Token API Cyberimpact</label>
                        <input type="password" class="cyberimpact-form-control" id="cyberimpact_token"
                               name="cyberimpact_token" placeholder="Collez votre token API" autocomplete="off">
                        <p class="cyberimpact-hint">Votre token de sécurité depuis votre compte Cyberimpact</p>
                    </div>
                    <div style="display:flex;gap:.75rem;align-items:center">
                        <button type="button" id="test_token_btn" class="cyberimpact-btn cyberimpact-btn-primary">
                            ✓ Tester et sauvegarder
                        </button>
                        <span id="token_loading" class="cyberimpact-loading cyberimpact-hidden">
                            <span class="cyberimpact-spinner"></span> Vérification…
                        </span>
                    </div>
                </form>

                <div id="token_status" class="$tokenStatusHidden" style="margin-top:1.5rem">
                    <div class="cyberimpact-alert cyberimpact-alert-success">
                        <strong>✓ Connexion validée</strong><br>
                        Compte : <strong id="account_name">$accountName</strong><br>
                        Utilisateur : <em id="account_user">$accountUser</em>
                        (<em id="account_email">$accountEmail</em>)
                        <div style="margin-top:1rem">
                            <button type="button" id="change_token_btn" class="cyberimpact-btn cyberimpact-btn-secondary cyberimpact-btn-sm">
                                🔄 Changer de token
                            </button>
                        </div>
                    </div>
                </div>
                <div id="token_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>
            </div>
        </div>
    </div>

    <!-- Étape 2 : Mapping -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">2</span>
                <h3>Mapping des colonnes</h3>
            </div>
            <div class="cyberimpact-card-body">
                <p class="cyberimpact-hint" style="margin-bottom:1.5rem">
                    Associez les colonnes de votre fichier Excel aux champs Cyberimpact.
                    Si aucun mapping n'est configuré, la détection automatique par alias est utilisée.
                </p>
                <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary mb-3" id="load_fields_btn">
                    ⬇ Charger les champs Cyberimpact
                </button>
                <span id="fields_loading" class="cyberimpact-loading cyberimpact-hidden ms-2">
                    <span class="cyberimpact-spinner"></span> Chargement…
                </span>
                <div id="fields_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>

                <form id="mapping_form" class="cyberimpact-hidden" style="margin-top:1.5rem">
                    <div class="table-responsive" style="margin-bottom:1.5rem">
                        <table class="cyberimpact-table">
                            <thead>
                                <tr><th>Champ Cyberimpact</th><th>Colonne Excel (en-tête exact)</th></tr>
                            </thead>
                            <tbody id="mapping_tbody"></tbody>
                        </table>
                    </div>
                    <div style="display:flex;gap:.75rem">
                        <button type="submit" class="cyberimpact-btn cyberimpact-btn-primary$mappingBtnHidden">Enregistrer le mapping</button>
                        <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary" id="clear_mapping_btn">Effacer</button>
                    </div>
                    <div id="mappingMessage" style="margin-top:1rem;display:none;padding:1rem;border-radius:8px"></div>
                </form>

                <div id="mapping_empty_hint" class="cyberimpact-alert cyberimpact-alert-info">
                    Cliquez sur « Charger les champs Cyberimpact » pour afficher le formulaire de mapping.
                </div>
            </div>
        </div>
    </div>

    <!-- Étape 3 : Groupe -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">3</span>
                <h3>Groupe cible (optionnel)</h3>
            </div>
            <div class="cyberimpact-card-body">
                <p class="cyberimpact-hint" style="margin-bottom:1.5rem">
                    Les contacts importés seront automatiquement ajoutés à ce groupe dans Cyberimpact.
                </p>
                <button type="button" class="cyberimpact-btn cyberimpact-btn-secondary mb-3" id="load_groups_btn">
                    ⬇ Charger les groupes Cyberimpact
                </button>
                <span id="groups_loading" class="cyberimpact-loading cyberimpact-hidden ms-2">
                    <span class="cyberimpact-spinner"></span> Chargement…
                </span>
                <div id="groups_error" class="cyberimpact-alert cyberimpact-alert-danger cyberimpact-hidden"></div>

                <form id="group_form" style="margin-top:1rem">
                    <div class="cyberimpact-form-group" style="max-width:450px">
                        <label for="selected_group_id">Groupe cible</label>
                        <select id="selected_group_id" name="selected_group_id" class="cyberimpact-form-control">
                            <option value="">-- Aucun groupe --</option>
                        </select>
                    </div>
                    <button type="submit" class="cyberimpact-btn cyberimpact-btn-primary">Enregistrer le groupe</button>
                    <div id="groupMessage" style="margin-top:1rem;display:none;padding:1rem;border-radius:8px"></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Étape 4 : Paramètres de synchronisation exacte -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">4</span>
                <h3>Paramètres de synchronisation exacte</h3>
            </div>
            <div class="cyberimpact-card-body"
                 data-current-action="$currentAction"
                 data-url-exact-sync-settings="$exactSyncUrl">
                <p style="margin:0 0 1.5rem;color:#6b7280;font-size:.95rem">
                    Action à appliquer aux contacts présents dans Cyberimpact
                    mais absents du fichier importé lors d'une synchronisation exacte.
                </p>
                <form id="exactSyncForm" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                        <input type="radio" name="missing_contacts_action" value="unsubscribe"
                               id="action-unsubscribe" $unsubscribeChecked /> Désabonner
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                        <input type="radio" name="missing_contacts_action" value="delete"
                               id="action-delete" $deleteChecked /> Supprimer
                    </label>
                    <button type="button" class="cyberimpact-btn cyberimpact-btn-primary" id="saveExactSyncBtn">
                        Sauvegarder
                    </button>
                </form>
                <div id="exactSyncMessage" style="margin-top:1rem;display:none;padding:1rem;border-radius:8px"></div>
            </div>
        </div>
    </div>

    <!-- Étape 5 : Import -->
    <div class="cyberimpact-section">
        <div class="cyberimpact-card">
            <div class="cyberimpact-card-header">
                <span class="cyberimpact-step-number">5</span>
                <h3>Importer votre fichier</h3>
            </div>
            <div class="cyberimpact-card-body">
                <div class="cyberimpact-alert cyberimpact-alert-info" style="margin-bottom:1.5rem">
                    <strong>Paramètres actifs :</strong><br>
                    Mapping : <strong>{$mappingBadge}</strong>
                    &nbsp;|&nbsp; Groupe : <strong>{$groupBadge}</strong>
                    &nbsp;|&nbsp; Action contacts manquants : <strong>{$missingActionLabel}</strong>
                </div>

                <div id="cyberimpact-upload-flash"></div>
                <form id="upload_form" method="post" enctype="multipart/form-data">
                    <div class="cyberimpact-form-group" style="max-width:450px">
                        <label for="source_file">Fichier Excel (.xlsx)</label>
                        <input class="cyberimpact-form-control" type="file" id="source_file"
                               name="source_file" accept=".xlsx" required>
                        <p class="cyberimpact-hint">Fichier Excel avec une ligne d'en-tête</p>
                    </div>

                    <button class="cyberimpact-btn cyberimpact-btn-success" type="submit">
                        ⬆ Uploader et créer un run
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
HTML;
    }

    private function renderExactSyncSettings(array $apiUrls = []): string
    {
        try {
            $currentAction = $this->importSettingsRepository->findFirst()->getMissingContactsAction();
        } catch (\Throwable) {
            $currentAction = 'unsubscribe';
        }

        $exactSyncUrl       = htmlspecialchars($apiUrls['exactSyncSettings'] ?? '');
        $unsubscribeChecked = $currentAction === 'unsubscribe' ? 'checked' : '';
        $deleteChecked      = $currentAction === 'delete'      ? 'checked' : '';

        return <<<HTML
<div class="cyberimpact-section">
    <div class="cyberimpact-card">
        <div class="cyberimpact-card-header">
            <span class="cyberimpact-step-number">⚙</span>
            <h3>Paramètres de synchronisation exacte</h3>
        </div>
        <div class="cyberimpact-card-body"
             data-current-action="$currentAction"
             data-url-exact-sync-settings="$exactSyncUrl">
            <p style="margin:0 0 1.5rem;color:#6b7280;font-size:.95rem">
                Action à appliquer aux contacts présents dans Cyberimpact
                mais absents du fichier importé lors d'une synchronisation exacte.
            </p>
            <form id="exactSyncForm" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                    <input type="radio" name="missing_contacts_action" value="unsubscribe"
                           id="action-unsubscribe" $unsubscribeChecked /> Désabonner
                </label>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-weight:500">
                    <input type="radio" name="missing_contacts_action" value="delete"
                           id="action-delete" $deleteChecked /> Supprimer
                </label>
                <button type="button" class="cyberimpact-btn cyberimpact-btn-primary" id="saveExactSyncBtn">
                    Sauvegarder
                </button>
            </form>
            <div id="exactSyncMessage" style="margin-top:1rem;display:none;padding:1rem;border-radius:8px"></div>
        </div>
    </div>
</div>
HTML;
    }

    private function renderRunsList(): string
    {
        $rows = $this->runStorage->findRecentRuns(30);
        if ($rows === []) {
            return '<hr><h3>Runs récents</h3><p>Aucun run.</p>';
        }

        $html = '<hr><h3>Runs récents</h3>'
            . '<table class="table table-striped" style="margin-top:.75rem">'
            . '<thead><tr>'
            . '<th>#</th><th>Statut</th><th>ExactSync</th>'
            . '<th>Lignes</th><th>Upsert</th><th>Rapport</th><th>Actions</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $run) {
            $runUid     = (int)($run['uid']    ?? 0);
            $status     = (string)($run['status'] ?? '');
            $triggerBtn = in_array($status, ['queued', 'processing'], true)
                ? ' <button class="btn btn-sm btn-warning trigger-run-btn" data-run-uid="' . $runUid . '" style="margin-left:.5rem">▶ Relancer</button>'
                : '';

            $html .= '<tr>'
                . '<td>' . $runUid . '</td>'
                . '<td><code>' . htmlspecialchars($status) . '</code></td>'
                . '<td>' . (((int)($run['exact_sync'] ?? 0)) === 1 ? 'oui' : 'non') . '</td>'
                . '<td>' . (int)($run['processed_rows'] ?? 0) . ' / ' . (int)($run['total_rows'] ?? 0) . '</td>'
                . '<td>' . (int)($run['upsert_ok'] ?? 0) . ' ok / ' . (int)($run['upsert_failed'] ?? 0) . ' échec</td>'
                . '<td>' . (int)($run['report_file_uid'] ?? 0) . '</td>'
                . '<td><a class="btn btn-default btn-sm" href="?id=0&run=' . $runUid . '">Détail</a>' . $triggerBtn . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderRunDetail(int $runUid): string
    {
        $run = $this->runStorage->findRunByUid($runUid);
        if ($run === null) {
            return '<div class="alert alert-warning" style="margin-top:1rem">Run introuvable.</div>';
        }

        $chunks = $this->chunkStorage->findChunksByRunUid($runUid);
        $errors = $this->errorStorage->findErrorsByRunUid($runUid, 200);

        $html = '<hr><h3>Détail run #' . $runUid . '</h3>'
            . '<p>'
            . '<strong>Statut :</strong> <code>' . htmlspecialchars((string)($run['status'] ?? '')) . '</code>'
            . ' | <strong>ExactSync :</strong> ' . (((int)($run['exact_sync'] ?? 0)) === 1 ? 'oui' : 'non')
            . ' | <strong>Confirmé :</strong> '  . (((int)($run['exact_sync_confirmed'] ?? 0)) === 1 ? 'oui' : 'non')
            . '</p>'
            . '<p>'
            . '<strong>Lignes :</strong> ' . (int)($run['processed_rows'] ?? 0) . ' / ' . (int)($run['total_rows'] ?? 0)
            . ' | <strong>Upsert :</strong> ' . (int)($run['upsert_ok'] ?? 0) . ' ok / ' . (int)($run['upsert_failed'] ?? 0) . ' échec'
            . ' | <strong>Désabonnements :</strong> planifié=' . (int)($run['unsubscribe_planned'] ?? 0)
            . ', fait=' . (int)($run['unsubscribe_done'] ?? 0)
            . ', échec=' . (int)($run['unsubscribe_failed'] ?? 0)
            . ' | <strong>Rapport UID :</strong> ' . (int)($run['report_file_uid'] ?? 0)
            . '</p>';

        $html .= '<h4>Chunks (' . count($chunks) . ')</h4>'
            . '<table class="table table-bordered"><thead><tr><th>#</th><th>UID</th><th>Statut</th><th>Tentatives</th></tr></thead><tbody>';
        foreach ($chunks as $chunk) {
            $html .= '<tr>'
                . '<td>' . (int)($chunk['chunk_index'] ?? 0) . '</td>'
                . '<td>' . (int)($chunk['uid']         ?? 0) . '</td>'
                . '<td><code>' . htmlspecialchars((string)($chunk['status'] ?? '')) . '</code></td>'
                . '<td>' . (int)($chunk['attempt_count'] ?? 0) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<h4>Erreurs (' . count($errors) . ')</h4>'
            . '<table class="table table-bordered"><thead><tr><th>UID</th><th>Chunk</th><th>Étape</th><th>Code</th><th>Message</th></tr></thead><tbody>';
        foreach ($errors as $error) {
            $html .= '<tr>'
                . '<td>' . (int)($error['uid']       ?? 0) . '</td>'
                . '<td>' . (int)($error['chunk_uid'] ?? 0) . '</td>'
                . '<td><code>' . htmlspecialchars((string)($error['stage']   ?? '')) . '</code></td>'
                . '<td><code>' . htmlspecialchars((string)($error['code']    ?? '')) . '</code></td>'
                . '<td>'       . htmlspecialchars((string)($error['message'] ?? '')) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    // =========================================================================
    // Helpers privés
    // =========================================================================

    /** @return array<string, string> */
    private function buildApiUrls(): array
    {
        return [
            'upload'           => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.upload'),
            'testToken'        => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.test-token'),
            'cyberimpactFields' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.cyberimpact-fields'),
            'cyberimpactGroups' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.cyberimpact-groups'),
            'columnMapping'    => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.column-mapping'),
            'selectedGroup'    => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.selected-group'),
            'exactSyncSettings' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.exact-sync-settings'),
            'triggerRun'       => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.trigger-run'),
            'processNextChunk' => (string)$this->uriBuilder->buildUriFromRoute('tools_cyberimpactsync.process-next-chunk'),
        ];
    }

    private function alertHtml(string $type, string $message): string
    {
        $map = [
            'success' => 'cyberimpact-alert-success',
            'warning' => 'alert alert-warning',
            'danger'  => 'alert alert-danger',
        ];
        $cls = $map[$type] ?? 'alert alert-info';

        return '<div class="' . $cls . '" style="margin:1rem 0">' . $message . '</div>';
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
