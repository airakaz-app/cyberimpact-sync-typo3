/**
 * Cyberimpact Sync Module - Event Handlers
 * TYPO3 v12 compatible
 */

(function() {


    // Get API URLs from data attributes on main container
    const mainContainer = document.getElementById('cyberimpact-module-container');
    if (!mainContainer) return;

    const apiUrls = {
        testToken: mainContainer.dataset.urlTestToken,
        cyberimpactFields: mainContainer.dataset.urlCyberimpactFields,
        cyberimpactGroups: mainContainer.dataset.urlCyberimpactGroups,
        columnMapping: mainContainer.dataset.urlColumnMapping,
        selectedGroup: mainContainer.dataset.urlSelectedGroup,
    };

    // Get exactSync URL from the card body element
    const exactSyncCardBody = document.querySelector('[data-url-exact-sync-settings]');
    if (exactSyncCardBody) {
        apiUrls.exactSyncSettings = exactSyncCardBody.dataset.urlExactSyncSettings;
    }

    // Get currentAction for exactSync
    const currentAction = exactSyncCardBody?.dataset.currentAction || 'unsubscribe';

    // Get pre-loaded data from data attributes
    const preloadedData = {
        token: mainContainer.dataset.currentToken || '',
        mapping: tryParseJson(mainContainer.dataset.currentMapping || '{}'),
        groupId: mainContainer.dataset.currentGroupId || '',
    };

    function tryParseJson(jsonString) {
        try {
            if (typeof jsonString !== 'string' || !jsonString.trim()) {
                return {};
            }
            const parsed = JSON.parse(jsonString);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            console.warn('Failed to parse JSON from data attribute:', jsonString, e);
            return {};
        }
    }

    // Validate URLs
    for (const [key, url] of Object.entries(apiUrls)) {
        if (!url && key !== 'exactSyncSettings') {
            console.warn(`Missing API URL: ${key}`);
        }
    }

    // ==================== Initialize Pre-Loaded Data ====================
    // Initialize token status if token exists
    const tokenInput = document.getElementById('cyberimpact_token');
    const tokenStatus = document.getElementById('token_status');
    if (preloadedData.token && tokenStatus) {
        tokenStatus.classList.remove('cyberimpact-hidden');
        tokenInput.type = 'password';
    }

    // Initialize group selection if group exists
    const groupSelect = document.getElementById('selected_group_id');
    if (preloadedData.groupId && groupSelect) {
        groupSelect.value = preloadedData.groupId;
    }

    // ==================== Test Token ====================
    const testTokenBtn = document.getElementById('test_token_btn');
    const tokenInput = document.getElementById('cyberimpact_token');
    const tokenLoading = document.getElementById('token_loading');
    const tokenStatus = document.getElementById('token_status');
    const tokenError = document.getElementById('token_error');

    if (testTokenBtn) {
        testTokenBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            const token = tokenInput.value.trim();
            if (!token) {
                alert('Veuillez entrer un token');
                return;
            }

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
                    document.getElementById('account_name').textContent = data.account || 'N/A';
                    document.getElementById('account_user').textContent = data.username || '-';
                    document.getElementById('account_email').textContent = data.email || '-';
                    tokenStatus.classList.remove('cyberimpact-hidden');
                    tokenInput.type = 'password';
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
    const loadFieldsBtn = document.getElementById('load_fields_btn');
    const fieldsLoading = document.getElementById('fields_loading');
    const fieldsError = document.getElementById('fields_error');
    const mappingForm = document.getElementById('mapping_form');
    const mappingEmptyHint = document.getElementById('mapping_empty_hint');
    const mappingTbody = document.getElementById('mapping_tbody');
    const clearMappingBtn = document.getElementById('clear_mapping_btn');

    async function loadFieldsData() {
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

            // Standard fields
            for (const [key, label] of Object.entries(data.standardFields)) {
                const tr = document.createElement('tr');
                const existingValue = preloadedData.mapping?.standard?.[key] || '';
                tr.innerHTML = '<td>' + escapeHtml(label) + '</td>' +
                    '<td><input type="text" name="standard[' + escapeHtml(key) + ']" class="cyberimpact-form-control" ' +
                    'placeholder="Nom de la colonne Excel…" value="' + escapeHtml(existingValue) + '"></td>';
                mappingTbody.appendChild(tr);
            }

            // Custom fields
            for (const field of data.customFields) {
                const tr = document.createElement('tr');
                const existingValue = preloadedData.mapping?.customFields?.[field.id] || '';
                tr.innerHTML = '<td>' + escapeHtml(field.name) + '</td>' +
                    '<td><input type="text" name="customFields[' + escapeHtml(field.id.toString()) + ']" class="cyberimpact-form-control" ' +
                    'placeholder="Nom de la colonne Excel…" value="' + escapeHtml(existingValue) + '"></td>';
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

    // Auto-load fields if mapping exists
    if ((preloadedData.mapping?.standard && Object.keys(preloadedData.mapping.standard).length > 0) ||
        (preloadedData.mapping?.customFields && Object.keys(preloadedData.mapping.customFields).length > 0)) {
        loadFieldsData();
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
    const loadGroupsBtn = document.getElementById('load_groups_btn');
    const groupsLoading = document.getElementById('groups_loading');
    const groupsError = document.getElementById('groups_error');
    const groupSelect = document.getElementById('selected_group_id');
    const groupForm = document.getElementById('group_form');

    async function loadGroupsData() {
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
            for (const group of data.groups) {
                const option = document.createElement('option');
                option.value = group.id;
                option.textContent = group.title + ' (' + group.membersCount + ' membres)';
                groupSelect.appendChild(option);
            }

            // Restore pre-selected group if it exists
            if (preloadedData.groupId) {
                groupSelect.value = preloadedData.groupId;
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

    // Auto-load groups if a group was pre-selected
    if (preloadedData.groupId && groupSelect && groupSelect.options.length <= 1) {
        loadGroupsData();
    }

    // ==================== Save Mapping ====================
    if (mappingForm) {
        mappingForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Build nested structure from FormData
            const formData = new FormData(mappingForm);
            const data = { standard: {}, customFields: {} };
            
            for (const [key, value] of formData.entries()) {
                if (key.startsWith('standard[') && key.endsWith(']')) {
                    const fieldName = key.substring(9, key.length - 1);
                    data.standard[fieldName] = value;
                } else if (key.startsWith('customFields[') && key.endsWith(']')) {
                    const fieldId = key.substring(13, key.length - 1);
                    data.customFields[fieldId] = value;
                }
            }

            try {
                const response = await fetch(apiUrls.columnMapping, {
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

    // ==================== Save Group ====================
    if (groupForm) {
        groupForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const groupId = groupSelect.value;

            try {
                const response = await fetch(apiUrls.selectedGroup, {
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

    // ==================== ExactSync Settings ====================
    const exactSyncBtn = document.getElementById('saveExactSyncBtn');
    const unsubscribeRadio = document.getElementById('action-unsubscribe');
    const deleteRadio = document.getElementById('action-delete');
    const messageDiv = document.getElementById('exactSyncMessage');

    // Initialize radio selection
    if (deleteRadio && currentAction === 'delete') {
        deleteRadio.checked = true;
    } else if (unsubscribeRadio) {
        unsubscribeRadio.checked = true;
    }

    if (exactSyncBtn && apiUrls.exactSyncSettings) {
        exactSyncBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            const action = document.querySelector('input[name="missing_contacts_action"]:checked')?.value;
            if (!action) {
                showMessage('Veuillez sélectionner une action', 'danger');
                return;
            }

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
                    showMessage('✓ ' + result.message, 'success');
                } else {
                    showMessage('✗ ' + (result.error || 'Erreur inconnue'), 'danger');
                }
            } catch (error) {
                showMessage('✗ Erreur réseau: ' + error.message, 'danger');
            } finally {
                exactSyncBtn.disabled = false;
                exactSyncBtn.textContent = originalText;
            }
        });
    }

    function showMessage(text, type) {
        if (!messageDiv) return;
        messageDiv.textContent = text;
        messageDiv.style.display = 'block';
        messageDiv.style.background = type === 'success' ? '#d1fae5' : '#fee2e2';
        messageDiv.style.color = type === 'success' ? '#065f46' : '#991b1b';
        messageDiv.style.borderLeft = '4px solid ' + (type === 'success' ? '#10b981' : '#ef4444');
    }

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
})();
