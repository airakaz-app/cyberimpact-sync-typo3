# TYPO3 Cyberimpact Sync Extension

Extension TYPO3 v13+ pour synchroniser des contacts Excel vers la plateforme Cyberimpact via API.

## 📋 Fonctionnalités

- **Upload Excel** - Importer des fichiers `.xlsx` avec validation
- **Mapping Flexible** - Configurez le mapping colonnes Excel ↔ champs Cyberimpact
- **Sélection Groupe** - Assignez les contacts à un groupe Cyberimpact lors de l'import
- **Traitement par Chunks** - Pipeline optimisé avec chunks de 500 contacts
- **Dry-Run & Exact-Sync** - Mode simulation et synchronisation exacte avec unsubscribe
- **Retry Logic** - Gestion des erreurs API avec backoff exponentiel
- **Scheduler Task** - Automatisation via tâche TYPO3 Scheduler
- **Rapports CSV** - Génération de rapports détaillés par import

## 🚀 Installation Rapide

### 1. Configuration TYPO3
```php
// typo3conf/LocalConfiguration.php ou settings.yaml
'cyberimpact_sync' => [
    'falStorageUid' => 1,
    'incomingFolder' => 'incoming/',
    'chunkSize' => 500,
    'exactSyncMaxUnsubscribeCount' => 1000,
]
```

### 2. Token API
- Admin Tools → Cyberimpact Sync
- Section "Token Cyberimpact" → Entrez votre token
- Cliquez "Tester et sauvegarder"

### 3. Mapping des Colonnes (Optionnel)
- Section "Mapping des colonnes"
- Chargez les champs Cyberimpact
- Mappez vos colonnes Excel
- Enregistrez

### 4. Sélectionner un Groupe (Optionnel)
- Section "Affectation à un groupe"
- Chargez les groupes Cyberimpact
- Sélectionnez un groupe cible
- Enregistrez

### 5. Uploader Excel
- Section "Importer un fichier Excel"
- Sélectionnez votre `.xlsx`
- Cliquez "Uploader et créer un run"

## 🛠️ Commandes CLI

```bash
# Scanner folder + queue runs + parse chunks
php bin/typo3 cyberimpact:scan-import-folder

# Traite le prochain chunk
php bin/typo3 cyberimpact:process-next-run

# Finalise les runs complétés
php bin/typo3 cyberimpact:finalize-run

# Test de connexion API
php bin/typo3 cyberimpact:check-connection
```

## 📊 Architecture

| Composant | Rôle |
|-----------|------|
| **SyncModuleController** | Backend module upload + monitoring |
| **ScanImportFolderCommand** | CLI: queue files + parse chunks |
| **ProcessNextRunCommand** | CLI: traite chunks via API |
| **FinalizeRunCommand** | CLI: exact-sync + finalization |
| **CyberimpactClient** | Client API avec retry/pagination |
| **RunManager** | Orchestration runs + chunks |
| **ExactSyncService** | Synchronisation exacte (unsubscribe/delete) |
| **ExcelChunkReader** | Parser Excel (ZipArchive + SimpleXML) |
| **ContactRowMapper** | Mapping lignes Excel → Domain Model |

## 📂 Structure

```
Classes/
├── Command/               # CLI commands
├── Controller/Backend/    # Backend modules
├── Domain/Model/          # Domain entities
├── Infrastructure/        # Storage (DBAL)
├── Scheduler/            # Scheduler tasks
└── Service/              # Business logic

Configuration/
├── Backend/Modules.php   # Module registration
└── Services.yaml         # DI container

Resources/
└── Private/Language/     # i18n files

ext_*.php                 # Extension files
ext_tables.sql            # Database schema
```

## 🔐 Notes de Sécurité

- Token API stocké en DB (chiffré recommandé)
- Env var `CYBERIMPACT_TOKEN` a priorité
- Validation token via `/ping` endpoint
- Deletion/Unsubscribe protègés par ratio + confirmation

## 📞 Support

Pour les erreurs, vérifiez:
1. Token API valide (section 1 du module)
2. Connexion API: `php bin/typo3 cyberimpact:check-connection`
3. Logs TYPO3: `var/log/typo3_*_error.log`
4. DB: `SELECT * FROM tx_cyberimpactsync_error WHERE run_uid=X`

## 📝 License

MIT
