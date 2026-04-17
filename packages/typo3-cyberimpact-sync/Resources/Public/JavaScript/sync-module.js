/**
 * Cyberimpact Sync Module - Event Handlers
 * TYPO3 v12 compatible
 */

(function() {
    // Get main container and bail early if not found
    const mainContainer = document.getElementById('cyberimpact-module-container');
    if (!mainContainer) return;

    // ==================== API URLs ====================
    const apiUrls = {
        upload:           mainContainer.dataset.urlUpload,
        testToken:        mainContainer.dataset.urlTestToken,
        cyberimpactFields: mainContainer.dataset.urlCyberimpactFields,
        cyberimpactGroups: mainContainer.dataset.urlCyberimpactGroups,
        columnMapping:    mainContainer.dataset.urlColumnMapping,
        selectedGroup:    mainContainer.dataset.urlSelectedGroup,
        triggerRun:       mainContainer.dataset.urlTriggerRun,
    };

    const exactSyncCardBody = document.querySelector('[data-url-exact-sync-settings]');
    if (exactSyncCardBody) {
        apiUrls.exactSyncSettings = exactSyncCardBody.dataset.urlExactSyncSettings;
    }

    // ==================== Pre-loaded data from PHP ====================
    const currentAction  = exactSyncCardBody?.dataset.currentAction || 'unsubscribe';
    const savedGroupId   = mainContainer.dataset.currentGroupId || '';
    const savedMapping   = tryParseJson(mainContainer.dataset.currentMapping || '');
    const tokenValidated = mainContainer.dataset.tokenValidated === '1';

    function tryParseJson(str) {
        try { return str ? JSON.parse(str) : {}; } catch (e) { return {}; }
    }

    // ==================== DOM references ====================
    const testTokenBtn     = document.getElementById('test_token_btn');
    const tokenInput       = document.getElementById('cyberimpact_token');
    const tokenLoading     = document.getElementById('token_loading');
    const tokenStatus      = document.getElementById('token_status');
    const tokenError       = document.getElementById('token_error');

    const loadFieldsBtn    = document.getElementById('load_fields_btn');
    const fieldsLoading    = document.getElementById('fields_loading');
    const fieldsError      = document.getElementById('fields_error');
    const mappingForm      = document.getElementById('mapping_form');
    const mappingEmptyHint = document.getElementById('mapping_empty_hint');
    const mappingTbody     = document.getElementById('mapping_tbody');
    const clearMappingBtn  = document.getElementById('clear_mapping_btn');

    const loadGroupsBtn    = document.getElementById('load_groups_btn');
    const groupsLoading    = document.getElementById('groups_loading');
    const groupsError      = document.getElementById('groups_error');
    const groupSelect      = document.getElementById('selected_group_id');
    const groupForm        = document.getElementById('group_form');

    const exactSyncBtn     = document.getElementById('saveExactSyncBtn');
    const unsubscribeRadio = document.getElementById('action-unsubscribe');
    const deleteRadio      = document.getElementById('action-delete');
    const messageDiv       = document.getElementById('exactSyncMessage');

    // ==================== Initialize from saved state ====================
    if (tokenValidated && tokenStatus) {
        tokenStatus.classList.remove('cyberimpact-hidden');
    }

    if (deleteRadio && currentAction === 'delete') {
        deleteRadio.checked = true;
    } else if (unsubscribeRadio) {
        unsubscribeRadio.checked = true;
    }

    // Auto-load fields if a mapping is saved
    const hasMapping = savedMapping.standard && Object.keys(savedMapping.standard).length > 0;
    if (hasMapping) {
        loadFieldsData();
    }

    // Auto-load groups if a group is saved
    if (savedGroupId) {
        loadGroupsData();
    }

    // ==================== Test Token ====================
    const tokenForm = document.getElementById('token_form');
    if (tokenForm) {
        tokenForm.addEventListener('submit', (e) => e.preventDefault());
    }

    if (testTokenBtn) {
        testTokenBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const token = tokenInput.value.trim();
            if (!token) { alert('Veuillez entrer un token'); return; }

            tokenLoading.classList.remove('cyberimpact-hidden');
            tokenError.classList.add('cyberimpact-hidden');
            tokenStatus.classList.add('cyberimpact-hidden');
            testTokenBtn.disabled = true;

            try {
                const response = await fetch(apiUrls.testToken, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: 'cyberimpact_token=' + encodeURIComponent(token),
                });
                const data = await response.json();
                if (data.ok) {
                    document.getElementById('account_name').textContent  = data.account  || 'N/A';
                    document.getElementById('account_user').textContent  = data.username || '-';
                    document.getElementById('account_email').textContent = data.email    || '-';
                    tokenStatus.classList.remove('cyberimpact-hidden');
                    tokenInput.value = '';
                } else {
                    tokenError.textContent = data.error || 'Erreur inconnue';
                    tokenError.classList.remove('cyberimpact-hidden');
                }
            } catch (err) {
                tokenError.textContent = 'Erreur réseau: ' + err.message;
                tokenError.classList.remove('cyberimpact-hidden');
            } finally {
                tokenLoading.classList.add('cyberimpact-hidden');
                testTokenBtn.disabled = false;
            }
        });
    }

    // ==================== Load Fields ====================
    async function loadFieldsData() {
        if (!loadFieldsBtn) return;
        fieldsLoading.classList.remove('cyberimpact-hidden');
        fieldsError.classList.add('cyberimpact-hidden');
        loadFieldsBtn.disabled = true;

        try {
            const response = await fetch(apiUrls.cyberimpactFields, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

            if (data.error) {
                fieldsError.textContent = data.error;
                fieldsError.classList.remove('cyberimpact-hidden');
                return;
            }

            mappingTbody.innerHTML = '';

            for (const [key, label] of Object.entries(data.standardFields || {})) {
                const saved = savedMapping?.standard?.[key] || '';
                const tr = document.createElement('tr');
                tr.innerHTML = '<td>' + escapeHtml(label) + '</td>'
                    + '<td><input type="text" name="standard[' + escapeHtml(key) + ']" '
                    + 'class="cyberimpact-form-control" placeholder="Colonne Excel…" '
                    + 'value="' + escapeHtml(saved) + '"></td>';
                mappingTbody.appendChild(tr);
            }

            for (const field of (data.customFields || [])) {
                const saved = savedMapping?.customFields?.[String(field.id)] || '';
                const tr = document.createElement('tr');
                tr.innerHTML = '<td>' + escapeHtml(field.name) + '</td>'
                    + '<td><input type="text" name="customFields[' + escapeHtml(String(field.id)) + ']" '
                    + 'class="cyberimpact-form-control" placeholder="Colonne Excel…" '
                    + 'value="' + escapeHtml(saved) + '"></td>';
                mappingTbody.appendChild(tr);
            }

            mappingForm.classList.remove('cyberimpact-hidden');
            mappingEmptyHint.classList.add('cyberimpact-hidden');
        } catch (err) {
            fieldsError.textContent = 'Erreur réseau: ' + err.message;
            fieldsError.classList.remove('cyberimpact-hidden');
        } finally {
            fieldsLoading.classList.add('cyberimpact-hidden');
            loadFieldsBtn.disabled = false;
        }
    }

    if (loadFieldsBtn) {
        loadFieldsBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await loadFieldsData();
        });
    }

    // ==================== Clear Mapping ====================
    if (clearMappingBtn) {
        clearMappingBtn.addEventListener('click', (e) => {
            e.preventDefault();
            mappingTbody.innerHTML = '';
            mappingForm.classList.add('cyberimpact-hidden');
            mappingEmptyHint.classList.remove('cyberimpact-hidden');
        });
    }

    // ==================== Load Groups ====================
    async function loadGroupsData() {
        if (!loadGroupsBtn) return;
        groupsLoading.classList.remove('cyberimpact-hidden');
        groupsError.classList.add('cyberimpact-hidden');
        loadGroupsBtn.disabled = true;

        try {
            const response = await fetch(apiUrls.cyberimpactGroups, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();

            if (data.error) {
                groupsError.textContent = data.error;
                groupsError.classList.remove('cyberimpact-hidden');
                return;
            }

            groupSelect.innerHTML = '<option value="">-- Sélectionner un groupe --</option>';
            for (const group of (data.groups || [])) {
                const option = document.createElement('option');
                option.value = group.id;
                option.textContent = group.title + ' (' + group.membersCount + ' membres)';
                groupSelect.appendChild(option);
            }

            if (savedGroupId) {
                groupSelect.value = savedGroupId;
            }
        } catch (err) {
            groupsError.textContent = 'Erreur réseau: ' + err.message;
            groupsError.classList.remove('cyberimpact-hidden');
        } finally {
            groupsLoading.classList.add('cyberimpact-hidden');
            loadGroupsBtn.disabled = false;
        }
    }

    if (loadGroupsBtn) {
        loadGroupsBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            await loadGroupsData();
        });
    }

    // ==================== Save Mapping ====================
    const mappingMessage = document.getElementById('mappingMessage');
    if (mappingForm) {
        mappingForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const response = await fetch(apiUrls.columnMapping, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new URLSearchParams(new FormData(mappingForm)).toString(),
                });
                const result = await response.json();
                if (result.ok) {
                    showMessage(mappingMessage, '✓ ' + result.message, 'success');
                } else {
                    showMessage(mappingMessage, '✗ ' + (result.error || 'Erreur inconnue'), 'danger');
                }
            } catch (err) {
                showMessage(mappingMessage, '✗ Erreur réseau: ' + err.message, 'danger');
            }
        });
    }

    // ==================== Save Group ====================
    const groupMessage = document.getElementById('groupMessage');
    if (groupForm) {
        groupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const response = await fetch(apiUrls.selectedGroup, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: 'selected_group_id=' + encodeURIComponent(groupSelect.value || ''),
                });
                const result = await response.json();
                if (result.ok) {
                    showMessage(groupMessage, '✓ ' + result.message, 'success');
                } else {
                    showMessage(groupMessage, '✗ ' + (result.error || 'Erreur inconnue'), 'danger');
                }
            } catch (err) {
                showMessage(groupMessage, '✗ Erreur réseau: ' + err.message, 'danger');
            }
        });
    }

    // ==================== ExactSync Settings ====================
    if (exactSyncBtn && apiUrls.exactSyncSettings) {
        exactSyncBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const action = document.querySelector('input[name="missing_contacts_action"]:checked')?.value;
            if (!action) { showMessage('Veuillez sélectionner une action', 'danger'); return; }

            exactSyncBtn.disabled = true;
            const originalText = exactSyncBtn.textContent;
            exactSyncBtn.textContent = 'Sauvegarde...';

            try {
                const response = await fetch(apiUrls.exactSyncSettings, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: 'missing_contacts_action=' + encodeURIComponent(action),
                });
                const result = await response.json();
                if (result.ok) {
                    showMessage(messageDiv, '✓ ' + result.message, 'success');
                } else {
                    showMessage(messageDiv, '✗ ' + (result.error || 'Erreur inconnue'), 'danger');
                }
            } catch (err) {
                showMessage(messageDiv, '✗ Erreur réseau: ' + err.message, 'danger');
            } finally {
                exactSyncBtn.disabled = false;
                exactSyncBtn.textContent = originalText;
            }
        });
    }

    // ==================== Upload automatique (AJAX 2 phases) ====================
    const uploadForm    = document.getElementById('upload_form');
    const uploadFlash   = document.getElementById('cyberimpact-upload-flash');
    const uploadSubmit  = uploadForm?.querySelector('button[type="submit"]');

    if (uploadForm && apiUrls.upload) {
        uploadForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const fileInput = uploadForm.querySelector('input[type="file"]');
            if (!fileInput?.files?.length) {
                showUploadFlash('danger', 'Veuillez sélectionner un fichier .xlsx.');
                return;
            }

            const origLabel = uploadSubmit?.textContent ?? '';
            setUploadBusy(true, '⏳ Upload en cours…');

            // ── Phase 1 : envoi du fichier, création run + chunks ──
            let uploadData;
            try {
                const resp = await fetch(apiUrls.upload, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(uploadForm),
                });
                uploadData = await resp.json();
            } catch (err) {
                showUploadFlash('danger', '❌ Erreur réseau lors de l\'upload : ' + err.message);
                setUploadBusy(false, origLabel);
                return;
            }

            if (!uploadData.ok) {
                showUploadFlash('danger', '❌ ' + (uploadData.error || 'Erreur upload inconnue.'));
                setUploadBusy(false, origLabel);
                return;
            }

            if (uploadData.chunkCount === 0) {
                showUploadFlash('warning',
                    '⚠ Run #' + uploadData.runUid + ' créé — aucun contact valide trouvé'
                    + ' (' + uploadData.totalRows + ' lignes, '
                    + uploadData.errorCount + ' erreurs de parsing).'
                );
                setUploadBusy(false, origLabel);
                fileInput.value = '';
                return;
            }

            // ── Phase 2 : traitement immédiat via API Cyberimpact ──
            setUploadBusy(true, '⏳ Synchronisation Cyberimpact…');
            showUploadFlash('info',
                '⏳ Run #' + uploadData.runUid + ' créé — '
                + uploadData.validRows + ' contacts, '
                + uploadData.chunkCount + ' chunk(s). Envoi vers Cyberimpact…'
            );

            try {
                const triggerResp = await fetch(apiUrls.triggerRun, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ run_uid: uploadData.runUid }),
                });
                const triggerData = await triggerResp.json();

                if (triggerData.ok) {
                    showUploadFlash('success',
                        '✓ Run #' + uploadData.runUid + ' synchronisé avec Cyberimpact.'
                        + ' Statut : <strong>' + escapeHtml(triggerData.status || 'completed') + '</strong>'
                    );
                } else {
                    showUploadFlash('warning',
                        '⚠ Run #' + uploadData.runUid + ' créé mais erreur lors du traitement : '
                        + escapeHtml(triggerData.error || 'Erreur inconnue.')
                    );
                }
            } catch (err) {
                showUploadFlash('warning',
                    '⚠ Run #' + uploadData.runUid + ' créé mais erreur réseau lors du traitement : '
                    + escapeHtml(err.message)
                );
            }

            setUploadBusy(false, origLabel);
            fileInput.value = '';
            refreshRunsList();
        });
    }

    // ==================== Relancer un run bloqué (filet de sécurité) ====================
    document.addEventListener('click', function(e) {
        if (!e.target.classList.contains('trigger-run-btn')) return;

        const runUid      = e.target.dataset.runUid;
        const btn         = e.target;
        const originalTxt = btn.textContent;

        btn.disabled    = true;
        btn.textContent = '⏳ Traitement…';

        fetch(apiUrls.triggerRun || '', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ run_uid: runUid }),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                refreshRunsList();
            } else {
                alert('❌ Erreur : ' + (data.error || 'Erreur inconnue'));
                btn.disabled    = false;
                btn.textContent = originalTxt;
            }
        })
        .catch(err => {
            alert('❌ Erreur de connexion : ' + err.message);
            btn.disabled    = false;
            btn.textContent = originalTxt;
        });
    });

    // ==================== Helpers upload ====================
    function setUploadBusy(busy, label) {
        if (!uploadSubmit) return;
        uploadSubmit.disabled    = busy;
        uploadSubmit.textContent = label;
    }

    function showUploadFlash(type, html) {
        if (!uploadFlash) return;
        const cls = {
            success: 'cyberimpact-alert cyberimpact-alert-success',
            warning: 'alert alert-warning',
            danger:  'alert alert-danger',
            info:    'cyberimpact-alert cyberimpact-alert-info',
        }[type] ?? 'alert alert-info';
        uploadFlash.innerHTML = '<div class="' + cls + '" style="margin-bottom:1rem">' + html + '</div>';
        uploadFlash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function refreshRunsList() {
        // Recharge la page entière pour afficher la liste mise à jour
        window.location.reload();
    }

    // ==================== Helpers ====================
    function showMessage(el, text, type) {
        if (!el) return;
        el.textContent = text;
        el.style.display = 'block';
        el.style.background   = type === 'success' ? '#d1fae5' : '#fee2e2';
        el.style.color        = type === 'success' ? '#065f46' : '#991b1b';
        el.style.borderLeft   = '4px solid ' + (type === 'success' ? '#10b981' : '#ef4444');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
})();
