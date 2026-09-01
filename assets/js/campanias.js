document.addEventListener('DOMContentLoaded', () => {
    const CAN_WRITE_CAMPAIGNS = !!(window.APP_PERMISSIONS && window.APP_PERMISSIONS.canWriteCampaigns);

    const state = {
        campaigns: [],
        currentCampaignId: null,
        currentCampaign: null,
        campaignExtras: [],
        registros: [],
        campaignFiltersOpen: false,
        confirmAction: null,
    };

    const els = {
        submitCampaignFormBtn: document.getElementById('submitCampaignFormBtn'),
        submitRegistroFormBtn: document.getElementById('submitRegistroFormBtn'),

        confirmModal: document.getElementById('confirmModal'),
        closeConfirmBackdrop: document.getElementById('closeConfirmBackdrop'),
        closeConfirmModalBtn: document.getElementById('closeConfirmModalBtn'),
        cancelConfirmBtn: document.getElementById('cancelConfirmBtn'),
        acceptConfirmBtn: document.getElementById('acceptConfirmBtn'),
        confirmModalTitle: document.getElementById('confirmModalTitle'),
        confirmModalText: document.getElementById('confirmModalText'),
        // campañas
        openCampaignFormBtn: document.getElementById('openCampaignFormBtn'),
        campaignFormModal: document.getElementById('campaignFormModal'),
        closeCampaignFormBtn: document.getElementById('closeCampaignFormBtn'),
        closeCampaignFormBackdrop: document.getElementById('closeCampaignFormBackdrop'),
        cancelCampaignFormBtn: document.getElementById('cancelCampaignFormBtn'),
        campaignForm: document.getElementById('campaignForm'),
        campaignFormTitle: document.getElementById('campaignFormTitle'),
        campaignFormSubtitle: document.getElementById('campaignFormSubtitle'),
        campaignId: document.getElementById('campaign_id'),
        campaignNombre: document.getElementById('campaign_nombre'),
        campaignDescripcion: document.getElementById('campaign_descripcion'),
        campaignFechaInicio: document.getElementById('campaign_fecha_inicio'),
        campaignEstado: document.getElementById('campaign_estado'),
        campaignsTableBody: document.getElementById('campaignsTableBody'),
        campaignsTotalLabel: document.getElementById('campaignsTotalLabel'),

        // filtros campañas
        toggleCampaignFiltersBtn: document.getElementById('toggleCampaignFiltersBtn'),
        clearCampaignFiltersBtn: document.getElementById('clearCampaignFiltersBtn'),
        campaignFiltersPanel: document.getElementById('campaignFiltersPanel'),
        campaignFiltersSummary: document.getElementById('campaignFiltersSummary'),
        campaignSearchInput: document.getElementById('campaignSearchInput'),
        campaignEstadoFilter: document.getElementById('campaignEstadoFilter'),
        campaignMesFilter: document.getElementById('campaignMesFilter'),

        // stats campañas
        statCampaniasTotal: document.getElementById('statCampaniasTotal'),
        statCampaniasActivas: document.getElementById('statCampaniasActivas'),
        statCampaniasBorrador: document.getElementById('statCampaniasBorrador'),
        statCampaniasFinalizadas: document.getElementById('statCampaniasFinalizadas'),

        // campos extra campaña
        addCampoExtraBtn: document.getElementById('addCampoExtraBtn'),
        camposExtraContainer: document.getElementById('camposExtraContainer'),

        // detalle campaña
        campaignsSection: document.getElementById('campaignsSection'),
        campaignDetailSection: document.getElementById('campaignDetailSection'),
        campaignDetailTitle: document.getElementById('campaignDetailTitle'),
        campaignDetailMeta: document.getElementById('campaignDetailMeta'),
        backToCampaignsBtn: document.getElementById('backToCampaignsBtn'),
        editCampaignBtn: document.getElementById('editCampaignBtn'),
        openRegistroFormBtn: document.getElementById('openRegistroFormBtn'),

        detailStatTotal: document.getElementById('detailStatTotal'),
        detailStatConfirmados: document.getElementById('detailStatConfirmados'),
        detailStatPendientes: document.getElementById('detailStatPendientes'),
        detailStatAsistieron: document.getElementById('detailStatAsistieron'),

        // filtros registros
        registroSearchInput: document.getElementById('registroSearchInput'),
        registroConfirmoFilter: document.getElementById('registroConfirmoFilter'),
        registroAsistioFilter: document.getElementById('registroAsistioFilter'),

        // tabla registros
        registrosTableBody: document.getElementById('registrosTableBody'),

        // formulario registro
        registroFormModal: document.getElementById('registroFormModal'),
        closeRegistroFormBtn: document.getElementById('closeRegistroFormBtn'),
        closeRegistroFormBackdrop: document.getElementById('closeRegistroFormBackdrop'),
        cancelRegistroFormBtn: document.getElementById('cancelRegistroFormBtn'),
        registroForm: document.getElementById('registroForm'),
        registroFormTitle: document.getElementById('registroFormTitle'),
        registroFormSubtitle: document.getElementById('registroFormSubtitle'),
        registroId: document.getElementById('registro_id'),
        registroCampaniaId: document.getElementById('registro_campania_id'),
        registroNombreApellido: document.getElementById('registro_nombre_apellido'),
        registroNumeroTelefono: document.getElementById('registro_numero_telefono'),
        registroDni: document.getElementById('registro_dni'),
        registroConfirmoCita: document.getElementById('registro_confirmo_cita'),
        registroAsistio: document.getElementById('registro_asistio'),
        registroMonto: document.getElementById('registro_monto'),
        registroQueSeRealizo: document.getElementById('registro_que_se_realizo'),
        registroCamposExtraContainer: document.getElementById('registroCamposExtraContainer'),

        // detalle registro
        registroDetailModal: document.getElementById('registroDetailModal'),
        closeRegistroDetailBtn: document.getElementById('closeRegistroDetailBtn'),
        closeRegistroDetailBackdrop: document.getElementById('closeRegistroDetailBackdrop'),
        registroDetailContent: document.getElementById('registroDetailContent'),

        // toast
        toast: document.getElementById('toast'),
        exportCampaniasBtn: document.getElementById('exportCampaniasBtn'),
    };

    init();

    function init() {
        bindEvents();
        checkExportError();
        loadCampaigns();
    }

    function checkExportError() {
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.get('error_export') === '1') {
            setTimeout(() => {
                showToast('No hay datos para exportar con los filtros seleccionados', true);
            }, 300);
        
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }

    function bindEvents() {
        // campañas
        els.closeConfirmBackdrop?.addEventListener('click', closeConfirmModal);
        els.closeConfirmModalBtn?.addEventListener('click', closeConfirmModal);
        els.cancelConfirmBtn?.addEventListener('click', closeConfirmModal);
        els.acceptConfirmBtn?.addEventListener('click', handleConfirmAccept);

        document.addEventListener('keydown', handleGlobalKeydown);
        if (CAN_WRITE_CAMPAIGNS) els.openCampaignFormBtn?.addEventListener('click', openCreateCampaignModal);
        els.closeCampaignFormBtn?.addEventListener('click', closeCampaignModal);
        els.closeCampaignFormBackdrop?.addEventListener('click', closeCampaignModal);
        els.cancelCampaignFormBtn?.addEventListener('click', closeCampaignModal);
        els.campaignForm?.addEventListener('submit', handleCampaignSubmit);

        // filtros campañas
        els.toggleCampaignFiltersBtn?.addEventListener('click', toggleCampaignFilters);
        els.clearCampaignFiltersBtn?.addEventListener('click', clearCampaignFilters);
        els.campaignSearchInput?.addEventListener('input', debounce(loadCampaigns, 300));
        els.campaignEstadoFilter?.addEventListener('change', loadCampaigns);
        els.campaignMesFilter?.addEventListener('change', loadCampaigns);

        // extras campaña
        els.addCampoExtraBtn?.addEventListener('click', () => {
            addCampaignExtraField();
        });

        // detalle campaña
        els.backToCampaignsBtn?.addEventListener('click', backToCampaignsList);
        els.editCampaignBtn?.addEventListener('click', openEditCurrentCampaign);
        if (CAN_WRITE_CAMPAIGNS) els.openRegistroFormBtn?.addEventListener('click', openCreateRegistroModal);

        // filtros registros
        els.registroSearchInput?.addEventListener('input', debounce(loadRegistros, 300));
        els.registroConfirmoFilter?.addEventListener('change', loadRegistros);
        els.registroAsistioFilter?.addEventListener('change', loadRegistros);

        // registros
        els.closeRegistroFormBtn?.addEventListener('click', closeRegistroModal);
        els.closeRegistroFormBackdrop?.addEventListener('click', closeRegistroModal);
        els.cancelRegistroFormBtn?.addEventListener('click', closeRegistroModal);
        els.registroForm?.addEventListener('submit', handleRegistroSubmit);

        // detalle registro
        els.closeRegistroDetailBtn?.addEventListener('click', closeRegistroDetailModal);
        els.closeRegistroDetailBackdrop?.addEventListener('click', closeRegistroDetailModal);

        // EXPORTAR CAMPAÑAS
        els.exportCampaniasBtn?.addEventListener('click', () => {
            if (!state.campaigns.length) {
                showToast('No hay datos para exportar con los filtros seleccionados', true);
                return;
            }

            const params = new URLSearchParams();

            const search = els.campaignSearchInput?.value.trim() || '';
            const estado = els.campaignEstadoFilter?.value || '';
            const mes = els.campaignMesFilter?.value || '';

            if (search) params.append('search', search);
            if (estado) params.append('estado', estado);
            if (mes) params.append('mes', mes);

            showToast('Generando Excel...');

            window.location.href = `modules/exportacion/export-campanias.php?${params.toString()}`;
        });

        document.addEventListener('click', handleDelegatedClicks);
    }

    function handleDelegatedClicks(event) {
        const action = event.target.closest('[data-action]');
        if (!action) return;

        const actionType = action.dataset.action;
        const id = action.dataset.id ? Number(action.dataset.id) : null;

        if (actionType === 'view-campaign' && id) {
            openCampaignDetail(id);
        }

        if (!CAN_WRITE_CAMPAIGNS && ['edit-campaign', 'delete-campaign', 'edit-registro', 'delete-registro', 'remove-extra-row'].includes(actionType)) {
            showToast('Modo solo lectura.', true);
            return;
        }

        if (actionType === 'edit-campaign' && id) {
            openEditCampaignModal(id);
        }

        if (actionType === 'delete-campaign' && id) {
            deleteCampaign(id);
        }

        if (actionType === 'view-registro' && id) {
            openRegistroDetail(id);
        }

        if (actionType === 'edit-registro' && id) {
            openEditRegistroModal(id);
        }

        if (actionType === 'delete-registro' && id) {
            deleteRegistro(id);
        }

        if (actionType === 'remove-extra-row') {
            action.closest('.extra-row')?.remove();
            refreshExtraRowIndexes();
        }
    }

    async function loadCampaigns() {
        try {
            const params = new URLSearchParams();

            const search = els.campaignSearchInput?.value.trim() || '';
            const estado = els.campaignEstadoFilter?.value || '';
            const mes = els.campaignMesFilter?.value || '';
            if (mes) params.append('mes', mes);

            if (search) params.append('search', search);
            if (estado) params.append('estado', estado);
            if (mes) params.append('mes', mes);

            const response = await fetch(`modules/campanias/list.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudieron cargar las campañas', true);
                return;
            }

            state.campaigns = Array.isArray(result.data) ? result.data : [];
            renderCampaigns();
            renderCampaignStats();
            renderCampaignFilterSummary();
        } catch (error) {
            console.error(error);
            showToast('Error al cargar campañas', true);
        }
    }

    function renderCampaigns() {
        const rows = state.campaigns;

        if (!rows.length) {
            els.campaignsTableBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="6">
                        No se encontraron campañas.<br>
                        <small>Ajusta filtros o crea una nueva campaña</small>
                    </td>
                </tr>
            `;
            els.campaignsTotalLabel.textContent = '0 campañas';
            return;
        }

        els.campaignsTableBody.innerHTML = rows.map(row => `
            <tr>
                <td>${escapeHtml(row.nombre || '')}</td>
                <td>${escapeHtml(row.descripcion || '-')}</td>
                <td>${formatDate(row.fecha_inicio)}</td>
                <td>${renderEstadoBadge(row.estado)}</td>
                <td>${Number(row.total_registros || 0)}</td>
                <td>
                    <div class="table-actions">
                        <button class="btn-secondary btn-sm" data-action="view-campaign" data-id="${row.id}" type="button">Ver</button>
                        ${CAN_WRITE_CAMPAIGNS ? `<button class="btn-secondary btn-sm" data-action="edit-campaign" data-id="${row.id}" type="button">Editar</button>
                        <button class="btn-secondary btn-sm" data-action="delete-campaign" data-id="${row.id}" type="button">Eliminar</button>` : ''}
                    </div>
                </td>
            </tr>
        `).join('');

        els.campaignsTotalLabel.textContent = `${rows.length} campaña${rows.length === 1 ? '' : 's'}`;
    }

    function renderCampaignStats() {
        const total = state.campaigns.length;
        const activas = state.campaigns.filter(item => item.estado === 'activa').length;
        const borrador = state.campaigns.filter(item => item.estado === 'borrador').length;
        const finalizadas = state.campaigns.filter(item => item.estado === 'finalizada').length;

        els.statCampaniasTotal.textContent = total;
        els.statCampaniasActivas.textContent = activas;
        els.statCampaniasBorrador.textContent = borrador;
        els.statCampaniasFinalizadas.textContent = finalizadas;
    }

    function toggleCampaignFilters() {
        state.campaignFiltersOpen = !state.campaignFiltersOpen;
        els.campaignFiltersPanel.classList.toggle('hidden', !state.campaignFiltersOpen);
    }

    function clearCampaignFilters() {
        els.campaignSearchInput.value = '';
        els.campaignEstadoFilter.value = '';
        els.campaignMesFilter.value = '';
        loadCampaigns();
    }

    function renderCampaignFilterSummary() {
        const parts = [];

        if (els.campaignSearchInput.value.trim()) parts.push(`búsqueda: "${els.campaignSearchInput.value.trim()}"`);
        if (els.campaignEstadoFilter.value) parts.push(`estado: ${els.campaignEstadoFilter.value}`);
        if (els.campaignMesFilter.value) parts.push(`mes: ${els.campaignMesFilter.value}`);

        els.campaignFiltersSummary.textContent = parts.length ? parts.join(' · ') : 'Sin filtros activos';
    }

    function openCreateCampaignModal() {
        resetCampaignFormState();
        els.campaignFormTitle.textContent = 'Nueva campaña';
        els.campaignFormSubtitle.textContent = 'Completa los datos generales de la campaña.';
        setCampaignExtrasLocked(false);
        addCampaignExtraField();
        openModal(els.campaignFormModal);
        focusElement(els.campaignNombre);
    }

    async function openEditCampaignModal(id) {
        try {
            const [campaignRes, extrasRes] = await Promise.all([
                fetch(`modules/campanias/get.php?id=${id}`),
                fetch(`modules/campanias/campos-extra-list.php?campania_id=${id}`)
            ]);

            const campaignResult = await campaignRes.json();
            const extrasResult = await extrasRes.json();

            if (!campaignResult.success) {
                showToast(campaignResult.message || 'No se pudo cargar la campaña', true);
                return;
            }

            const data = campaignResult.data;
            const extras = extrasResult.success ? (extrasResult.data || []) : [];
            const bloqueoCamposExtra = Number(data.total_registros || 0) > 0;

            els.campaignId.value = data.id || '';
            els.campaignNombre.value = data.nombre || '';
            els.campaignDescripcion.value = data.descripcion || '';
            els.campaignFechaInicio.value = data.fecha_inicio || '';
            els.campaignEstado.value = data.estado || 'borrador';

            els.campaignFormTitle.textContent = 'Editar campaña';
            els.campaignFormSubtitle.textContent = 'Actualiza la información general de la campaña.';
            els.camposExtraContainer.innerHTML = '';

            if (extras.length) {
                extras.forEach(extra => addCampaignExtraField(extra));
            } else {
                addCampaignExtraField();
            }

            setCampaignExtrasLocked(bloqueoCamposExtra);

            openModal(els.campaignFormModal);
            focusElement(els.campaignNombre);
        } catch (error) {
            console.error(error);
            showToast('Error al cargar campaña', true);
        }
    }

    function openEditCurrentCampaign() {
        if (!state.currentCampaignId) return;
        openEditCampaignModal(state.currentCampaignId);
    }

    async function handleCampaignSubmit(event) {
        event.preventDefault();
        if (!CAN_WRITE_CAMPAIGNS) {
            showToast('Modo supervisión: solo lectura.', 'error');
            return;
        }

        setButtonLoading(els.submitCampaignFormBtn, 'Guardando campaña...');

        try {
            const id = els.campaignId.value.trim();
            const endpoint = id ? 'modules/campanias/update.php' : 'modules/campanias/create.php';

            const payload = {
                id: id || undefined,
                nombre: els.campaignNombre.value.trim(),
                descripcion: els.campaignDescripcion.value.trim(),
                fecha_inicio: els.campaignFechaInicio.value,
                estado: els.campaignEstado.value,
                campos_extra: collectCampaignExtraFields()
            };

            const result = await postJSON(endpoint, payload);

            if (!result.success) {
                showToast(result.message || 'No se pudo guardar la campaña', true);
                return;
            }

            closeCampaignModal();
            showToast(result.message || 'Campaña guardada correctamente');
            await loadCampaigns();

            const newId = result.data?.id || Number(id);
            if (newId) {
                await openCampaignDetail(newId);
            }
        } catch (error) {
            console.error(error);
            showToast('Error al guardar campaña', true);
        } finally {
            resetButtonLoading(els.submitCampaignFormBtn);
        }
    }

    function closeCampaignModal() {
        closeModal(els.campaignFormModal);
        resetCampaignFormState();
    }

    function collectCampaignExtraFields() {
        const rows = [...els.camposExtraContainer.querySelectorAll('.extra-row')];

        return rows
            .map((row, index) => {
                const extraId = row.querySelector('[data-field="id"]')?.value || '';
                const etiqueta = row.querySelector('[data-field="etiqueta"]')?.value.trim() || '';
                const nombreInterno = row.querySelector('[data-field="nombre_interno"]')?.value.trim() || '';
                const tipo = row.querySelector('[data-field="tipo"]')?.value || '';
                const requerido = row.querySelector('[data-field="requerido"]')?.checked ? 1 : 0;
                const opciones = row.querySelector('[data-field="opciones"]')?.value.trim() || '';

                if (!etiqueta && !nombreInterno && !tipo) return null;

                return {
                    id: extraId || undefined,
                    etiqueta,
                    nombre_interno: nombreInterno || slugify(etiqueta),
                    tipo,
                    requerido,
                    orden: index + 1,
                    opciones: tipo === 'select' ? opciones.split(',').map(item => item.trim()).filter(Boolean) : []
                };
            })
            .filter(Boolean);
    }

    function addCampaignExtraField(extra = {}) {
        const row = document.createElement('div');
        row.className = 'extra-row field-group field-group-full';
        row.innerHTML = `
            <input type="hidden" data-field="id" value="${escapeAttribute(extra.id || '')}">
            <div class="form-grid">
                <div class="field-group">
                    <label>Etiqueta</label>
                    <input type="text" data-field="etiqueta" value="${escapeAttribute(extra.etiqueta || '')}" placeholder="Ej. Sucursal">
                </div>

                <div class="field-group">
                    <label>Nombre interno</label>
                    <input type="text" data-field="nombre_interno" value="${escapeAttribute(extra.nombre_interno || '')}" placeholder="Ej. sucursal">
                </div>

                <div class="field-group">
                    <label>Tipo</label>
                    <select data-field="tipo">
                        ${renderExtraTypeOptions(extra.tipo || '')}
                    </select>
                </div>

                <div class="field-group">
                    <label class="inline-check inline-check--boxed">
                        <input type="checkbox" data-field="requerido" ${Number(extra.requerido) ? 'checked' : ''}>
                        <span>Obligatorio</span>
                    </label>
                </div>

                <div class="field-group field-group-full">
                    <label>Opciones (solo select, separadas por coma)</label>
                    <input type="text" data-field="opciones" value="${escapeAttribute(normalizeExtraOptions(extra.opciones_json || extra.opciones || []))}" placeholder="Opción 1, Opción 2">
                </div>

                <div class="field-group field-group-full">
                    <button class="btn-secondary" type="button" data-action="remove-extra-row">Quitar campo</button>
                </div>
            </div>
        `;

        els.camposExtraContainer.appendChild(row);
        refreshExtraRowIndexes();
    }

    function refreshExtraRowIndexes() {
        [...els.camposExtraContainer.querySelectorAll('.extra-row')].forEach((row, idx) => {
            row.dataset.index = String(idx);
        });
    }

    function setCampaignExtrasLocked(locked) {
        if (els.addCampoExtraBtn) {
            els.addCampoExtraBtn.disabled = locked;
            els.addCampoExtraBtn.classList.toggle('is-disabled', locked);
        }

        const rows = [...els.camposExtraContainer.querySelectorAll('.extra-row')];

        rows.forEach(row => {
            row.querySelectorAll('input, select, textarea, button').forEach(field => {
                if (field.dataset.action === 'remove-extra-row') {
                    field.style.display = locked ? 'none' : '';
                    return;
                }

                field.disabled = locked;
            });
        });

        let notice = document.getElementById('campaignExtrasLockNotice');

        if (locked) {
            if (!notice) {
                notice = document.createElement('div');
                notice.id = 'campaignExtrasLockNotice';
                notice.className = 'form-lock-notice';
                notice.innerHTML = `
                    <strong>Campos bloqueados:</strong>
                    No se pueden modificar porque ya existen registros.
                `;
                els.camposExtraContainer.parentNode.insertBefore(notice, els.camposExtraContainer);
            }
        } else if (notice) {
            notice.remove();
        }
    }

    async function openCampaignDetail(id) {
        try {
            const [campaignRes, extrasRes] = await Promise.all([
                fetch(`modules/campanias/get.php?id=${id}`),
                fetch(`modules/campanias/campos-extra-list.php?campania_id=${id}`)
            ]);

            const campaignResult = await campaignRes.json();
            const extrasResult = await extrasRes.json();

            if (!campaignResult.success) {
                showToast(campaignResult.message || 'No se pudo cargar la campaña', true);
                return;
            }

            state.currentCampaignId = id;
            state.currentCampaign = campaignResult.data;
            state.campaignExtras = extrasResult.success ? (extrasResult.data || []) : [];

            els.campaignDetailTitle.textContent = state.currentCampaign.nombre || 'Detalle de campaña';
            els.campaignDetailMeta.textContent = [
                `Estado: ${capitalize(state.currentCampaign.estado || '-')}`,
                `Fecha inicio: ${formatDate(state.currentCampaign.fecha_inicio)}`
            ].join(' · ');

            els.campaignsSection.classList.add('hidden');
            els.campaignDetailSection.classList.remove('hidden');

            await loadRegistros();
        } catch (error) {
            console.error(error);
            showToast('Error al abrir la campaña', true);
        }
    }

    function backToCampaignsList() {
        state.currentCampaignId = null;
        state.currentCampaign = null;
        state.campaignExtras = [];
        state.registros = [];

        els.campaignDetailSection.classList.add('hidden');
        els.campaignsSection.classList.remove('hidden');
    }

    async function loadRegistros() {
        if (!state.currentCampaignId) return;

        try {
            const params = new URLSearchParams();
            params.append('campania_id', state.currentCampaignId);

            const search = els.registroSearchInput.value.trim();
            const confirmo = els.registroConfirmoFilter.value;
            const asistio = els.registroAsistioFilter.value;

            if (search) params.append('search', search);
            if (confirmo) params.append('confirmo_cita', confirmo);
            if (asistio) params.append('asistio', asistio);

            const response = await fetch(`modules/campanias/registros-list.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudieron cargar los registros', true);
                return;
            }

            state.registros = Array.isArray(result.data) ? result.data : [];
            renderRegistros();
            renderRegistroStats();
        } catch (error) {
            console.error(error);
            showToast('Error al cargar registros', true);
        }
    }

    function renderRegistros() {
        if (!state.registros.length) {
            els.registrosTableBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="8">
                        No hay registros cargados en esta campaña.<br>
                        <small>Crea el primero con "Nuevo registro"</small>
                    </td>
                </tr>
            `;
            return;
        }

        els.registrosTableBody.innerHTML = state.registros.map(row => `
            <tr>
                <td>${escapeHtml(row.nombre_apellido || '')}</td>
                <td>${escapeHtml(row.numero_telefono || '')}</td>
                <td>${escapeHtml(row.dni || '-')}</td>
                <td>${renderConfirmoBadge(row.confirmo_cita)}</td>
                <td>${renderAsistioBadge(row.asistio)}</td>
                <td>${formatMoney(row.monto)}</td>
                <td>${escapeHtml(row.que_se_realizo || '-')}</td>
                <td>
                    <div class="table-actions">
                        <button class="btn-secondary btn-sm" data-action="view-registro" data-id="${row.id}" type="button">Ver</button>
                        ${CAN_WRITE_CAMPAIGNS ? `<button class="btn-secondary btn-sm" data-action="edit-registro" data-id="${row.id}" type="button">Editar</button>
                        <button class="btn-secondary btn-sm" data-action="delete-registro" data-id="${row.id}" type="button">Eliminar</button>` : ''}
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function renderRegistroStats() {
        const total = state.registros.length;
        const confirmados = state.registros.filter(item => item.confirmo_cita === 'si').length;
        const pendientes = state.registros.filter(item => item.confirmo_cita === 'pendiente').length;
        const asistieron = state.registros.filter(item => item.asistio === 'asistio').length;

        els.detailStatTotal.textContent = total;
        els.detailStatConfirmados.textContent = confirmados;
        els.detailStatPendientes.textContent = pendientes;
        els.detailStatAsistieron.textContent = asistieron;
    }

    function openCreateRegistroModal() {
        if (!state.currentCampaignId) {
            showToast('Primero selecciona una campaña', true);
            return;
        }

        resetRegistroFormState();
        els.registroCampaniaId.value = String(state.currentCampaignId);
        els.registroConfirmoCita.value = 'pendiente';

        renderRegistroExtraFields(state.campaignExtras);
        els.registroFormTitle.textContent = 'Nuevo registro';
        els.registroFormSubtitle.textContent = 'Completa la información base y adicional de la campaña.';
        openModal(els.registroFormModal);
        focusElement(els.registroNombreApellido);
    }

    async function openEditRegistroModal(id) {
        try {
            const response = await fetch(`modules/campanias/registros-get.php?id=${id}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudo cargar el registro', true);
                return;
            }

            const data = result.data || {};
            const base = data.base || data;
            const extrasValues = data.campos_extra || [];

            els.registroId.value = base.id || '';
            els.registroCampaniaId.value = base.campania_id || state.currentCampaignId || '';
            els.registroNombreApellido.value = base.nombre_apellido || '';
            els.registroNumeroTelefono.value = base.numero_telefono || '';
            els.registroDni.value = base.dni || '';
            els.registroConfirmoCita.value = base.confirmo_cita || 'pendiente';
            els.registroAsistio.value = base.asistio || '';
            els.registroMonto.value = base.monto ?? '';
            els.registroQueSeRealizo.value = base.que_se_realizo || '';

            els.registroCamposExtraContainer.innerHTML = '';
            renderRegistroExtraFields(state.campaignExtras, extrasValues);

            els.registroFormTitle.textContent = 'Editar registro';
            els.registroFormSubtitle.textContent = 'Actualiza la información del registro.';

            openModal(els.registroFormModal);
            focusElement(els.registroNombreApellido);
        } catch (error) {
            console.error(error);
            showToast('Error al cargar registro', true);
        }
    }

    async function handleRegistroSubmit(event) {
        event.preventDefault();
        if (!CAN_WRITE_CAMPAIGNS) {
            showToast('Modo supervisión: solo lectura.', 'error');
            return;
        }

        setButtonLoading(els.submitRegistroFormBtn, 'Guardando registro...');

        try {
            const id = els.registroId.value.trim();
            const endpoint = id ? 'modules/campanias/registros-update.php' : 'modules/campanias/registros-create.php';

            const payload = {
                id: id || undefined,
                campania_id: els.registroCampaniaId.value,
                nombre_apellido: els.registroNombreApellido.value.trim(),
                numero_telefono: els.registroNumeroTelefono.value.trim(),
                dni: els.registroDni.value.trim(),
                confirmo_cita: els.registroConfirmoCita.value || 'pendiente',
                asistio: els.registroAsistio.value || '',
                monto: els.registroMonto.value,
                que_se_realizo: els.registroQueSeRealizo.value.trim(),
                campos_extra_valores: collectRegistroExtraValues()
            };

            const result = await postJSON(endpoint, payload);

            if (!result.success) {
                showToast(result.message || 'No se pudo guardar el registro', true);
                return;
            }

            closeRegistroModal();
            showToast(result.message || 'Registro guardado correctamente');
            await loadRegistros();
            await loadCampaigns();
        } catch (error) {
            console.error(error);
            showToast('Error al guardar registro', true);
        } finally {
            resetButtonLoading(els.submitRegistroFormBtn);
        }
    }

    function closeRegistroModal() {
        closeModal(els.registroFormModal);
        resetRegistroFormState();
    }

    function renderRegistroExtraFields(extras = [], values = []) {
        if (!extras.length) {
            els.registroCamposExtraContainer.innerHTML = `<p class="text-muted">Esta campaña no tiene campos extra configurados.</p>`;
            return;
        }

        const valuesMap = new Map();
        values.forEach(item => {
            const key = String(item.campo_extra_id || item.id || '');
            valuesMap.set(key, item.valor ?? '');
        });

        els.registroCamposExtraContainer.innerHTML = extras.map(extra => {
            const fieldId = `extra_${extra.id}`;
            const value = valuesMap.get(String(extra.id)) ?? '';
            const required = Number(extra.requerido) ? 'required' : '';

            return `
                <div class="field-group ${extra.tipo === 'textarea' ? 'field-group-full' : ''}">
                    <label for="${fieldId}">
                        ${escapeHtml(extra.etiqueta || '')}
                        ${Number(extra.requerido) ? '<span>*</span>' : ''}
                    </label>
                    ${renderDynamicField(extra, fieldId, value, required)}
                </div>
            `;
        }).join('');
    }

    function renderDynamicField(extra, fieldId, value, required) {
        const type = extra.tipo || 'texto';

        if (type === 'textarea') {
            return `<textarea id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" rows="3" ${required}>${escapeHtml(value)}</textarea>`;
        }

        if (type === 'numero') {
            return `<input type="number" id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" value="${escapeAttribute(value)}" ${required}>`;
        }

        if (type === 'fecha') {
            return `<input type="date" id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" value="${escapeAttribute(value)}" ${required}>`;
        }

        if (type === 'booleano') {
            return `
                <select id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" ${required}>
                    <option value="">Seleccionar</option>
                    <option value="si" ${value === 'si' ? 'selected' : ''}>Sí</option>
                    <option value="no" ${value === 'no' ? 'selected' : ''}>No</option>
                </select>
            `;
        }

        if (type === 'select') {
            const options = parseOptions(extra.opciones_json);
            return `
                <select id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" ${required}>
                    <option value="">Seleccionar</option>
                    ${options.map(option => `
                        <option value="${escapeAttribute(option)}" ${String(value) === String(option) ? 'selected' : ''}>
                            ${escapeHtml(option)}
                        </option>
                    `).join('')}
                </select>
            `;
        }

        return `<input type="text" id="${fieldId}" data-extra-id="${extra.id}" data-extra-tipo="${type}" value="${escapeAttribute(value)}" ${required}>`;
    }

    function collectRegistroExtraValues() {
        return [...els.registroCamposExtraContainer.querySelectorAll('[data-extra-id]')]
            .map(input => ({
                campo_extra_id: Number(input.dataset.extraId),
                valor: input.value ?? ''
            }));
    }

    async function openRegistroDetail(id) {
        try {
            const response = await fetch(`modules/campanias/registros-get.php?id=${id}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudo cargar el detalle', true);
                return;
            }

            const data = result.data || {};
            const base = data.base || data;
            const extras = data.campos_extra || [];

            const baseHtml = `
                <div class="detail-item"><strong>Nombre y Apellido</strong><span>${escapeHtml(base.nombre_apellido || '-')}</span></div>
                <div class="detail-item"><strong>Número Teléfono</strong><span>${escapeHtml(base.numero_telefono || '-')}</span></div>
                <div class="detail-item"><strong>DNI</strong><span>${escapeHtml(base.dni || '-')}</span></div>
                <div class="detail-item"><strong>¿Confirmó Cita?</strong><span>${formatConfirmo(base.confirmo_cita)}</span></div>
                <div class="detail-item"><strong>¿Asistió?</strong><span>${formatAsistio(base.asistio)}</span></div>
                <div class="detail-item"><strong>Monto</strong><span>${formatMoney(base.monto)}</span></div>
                <div class="detail-item"><strong>¿Qué se realizó?</strong><span>${escapeHtml(base.que_se_realizo || '-')}</span></div>
            `;

            const extraHtml = extras.length
                ? extras.map(item => `
                    <div class="detail-item">
                        <strong>${escapeHtml(item.etiqueta || item.nombre_interno || 'Campo extra')}</strong>
                        <span>${escapeHtml(item.valor || '-')}</span>
                    </div>
                `).join('')
                : `<div class="detail-item"><strong>Campos extra</strong><span>Sin información adicional</span></div>`;

            els.registroDetailContent.innerHTML = baseHtml + extraHtml;
            openModal(els.registroDetailModal);
        } catch (error) {
            console.error(error);
            showToast('Error al cargar detalle del registro', true);
        }
    }

    function closeRegistroDetailModal() {
        closeModal(els.registroDetailModal);
    }

        async function deleteCampaign(id) {
        openConfirmModal({
            title: 'Eliminar campaña',
            text: '¿Seguro que deseas eliminar esta campaña? Esta acción no se puede deshacer.',
            confirmText: 'Eliminar campaña',
            onConfirm: async () => {
                try {
                    const result = await postJSON('modules/campanias/delete.php', { id });

                    if (!result.success) {
                        showToast(result.message || 'No se pudo eliminar la campaña', true);
                        return;
                    }

                    showToast(result.message || 'Campaña eliminada correctamente');

                    if (state.currentCampaignId === id) {
                        backToCampaignsList();
                    }

                    await loadCampaigns();
                } catch (error) {
                    console.error(error);
                    showToast('Error al eliminar campaña', true);
                }
            }
        });
    }

    async function deleteRegistro(id) {
        openConfirmModal({
            title: 'Eliminar registro',
            text: '¿Seguro que deseas eliminar este registro? Esta acción no se puede deshacer.',
            confirmText: 'Eliminar registro',
            onConfirm: async () => {
                try {
                    const result = await postJSON('modules/campanias/registros-delete.php', { id });

                    if (!result.success) {
                        showToast(result.message || 'No se pudo eliminar el registro', true);
                        return;
                    }

                    showToast(result.message || 'Registro eliminado correctamente');
                    await loadRegistros();
                    await loadCampaigns();
                } catch (error) {
                    console.error(error);
                    showToast('Error al eliminar registro', true);
                }
            }
        });
    }

    async function postJSON(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.APP_CSRF_TOKEN || ''
            },
            body: JSON.stringify(payload)
        });

        return response.json();
    }

    function openModal(element) {
        element?.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeModal(element) {
        element?.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    function showToast(message, isError = false) {
        if (!els.toast) return;

        els.toast.textContent = message;
        els.toast.classList.remove('hidden');
        els.toast.classList.add('show');
        els.toast.style.background = isError ? '#a61b1b' : '#111';

        clearTimeout(showToast._timer);
        showToast._timer = setTimeout(() => {
            els.toast.classList.remove('show');
            setTimeout(() => {
                els.toast.classList.add('hidden');
            }, 250);
        }, 2500);
    }

    function renderEstadoBadge(value) {
        const label = capitalize(value || '-');
        return `<span class="status-chip status-chip--${escapeAttribute(value || 'default')}">${escapeHtml(label)}</span>`;
    }

    function renderConfirmoBadge(value) {
        return `<span class="status-chip status-chip--${escapeAttribute(value || 'default')}">${escapeHtml(formatConfirmo(value))}</span>`;
    }

    function renderAsistioBadge(value) {
        return `<span class="status-chip status-chip--${escapeAttribute(value || 'default')}">${escapeHtml(formatAsistio(value))}</span>`;
    }

    function formatConfirmo(value) {
        if (value === 'si') return 'Sí';
        if (value === 'no') return 'No';
        if (value === 'pendiente') return 'Pendiente';
        return '-';
    }

    function formatAsistio(value) {
        if (value === 'asistio') return 'Asistió';
        if (value === 'no_asistio') return 'No asistió';
        return '-';
    }

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(`${value}T00:00:00`);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString('es-PE');
    }

    function formatMoney(value) {
        if (value === null || value === undefined || value === '') return '-';
        const number = Number(value);
        if (Number.isNaN(number)) return value;
        return number.toLocaleString('es-PE', {
            style: 'currency',
            currency: 'PEN'
        });
    }

    function capitalize(value) {
        if (!value) return '';
        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    function slugify(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    function renderExtraTypeOptions(selected) {
        const options = [
            { value: '', label: 'Seleccionar' },
            { value: 'texto', label: 'Texto' },
            { value: 'textarea', label: 'Textarea' },
            { value: 'numero', label: 'Número' },
            { value: 'fecha', label: 'Fecha' },
            { value: 'select', label: 'Select' },
            { value: 'booleano', label: 'Sí/No' }
        ];

        return options.map(option => `
            <option value="${option.value}" ${option.value === selected ? 'selected' : ''}>
                ${option.label}
            </option>
        `).join('');
    }

    function parseOptions(raw) {
        if (Array.isArray(raw)) return raw;
        if (!raw) return [];

        try {
            const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return String(raw)
                .split(',')
                .map(item => item.trim())
                .filter(Boolean);
        }
    }

    function normalizeExtraOptions(raw) {
        const options = parseOptions(raw);
        return options.join(', ');
    }

    function debounce(fn, delay = 300) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function openConfirmModal({ title, text, confirmText = 'Eliminar', onConfirm, danger = true }) {
        state.confirmAction = typeof onConfirm === 'function' ? onConfirm : null;

        els.confirmModalTitle.textContent = title || 'Confirmar acción';
        els.confirmModalText.textContent = text || '¿Deseas continuar?';
        els.acceptConfirmBtn.textContent = confirmText;

        els.acceptConfirmBtn.classList.remove('btn-danger');
        if (danger) {
            els.acceptConfirmBtn.classList.add('btn-danger');
        }

        openModal(els.confirmModal);
    }

    function closeConfirmModal() {
        state.confirmAction = null;
        closeModal(els.confirmModal);
    }

    async function handleConfirmAccept() {
        if (typeof state.confirmAction !== 'function') {
            closeConfirmModal();
            return;
        }

        const action = state.confirmAction;
        closeConfirmModal();
        await action();
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Escape') {
            if (!els.confirmModal?.classList.contains('hidden')) closeConfirmModal();
            if (!els.registroDetailModal?.classList.contains('hidden')) closeRegistroDetailModal();
            if (!els.registroFormModal?.classList.contains('hidden')) closeRegistroModal();
            if (!els.campaignFormModal?.classList.contains('hidden')) closeCampaignModal();
        }
    }

    function setButtonLoading(button, loadingText) {
        if (!button) return;

        if (!button.dataset.originalText) {
            button.dataset.originalText = button.textContent;
        }

        button.disabled = true;
        button.textContent = loadingText;
        button.classList.add('is-loading');
    }

    function resetButtonLoading(button) {
        if (!button) return;

        button.disabled = false;
        button.textContent = button.dataset.originalText || button.textContent;
        button.classList.remove('is-loading');
    }

    function focusElement(element) {
    if (!element) return;
    setTimeout(() => element.focus(), 80);
    }

    function resetCampaignFormState() {
        els.campaignForm?.reset();
        els.campaignId.value = '';
        els.camposExtraContainer.innerHTML = '';

        const notice = document.getElementById('campaignExtrasLockNotice');
        if (notice) notice.remove();

        setCampaignExtrasLocked(false);
    }

    function resetRegistroFormState() {
        els.registroForm?.reset();
        els.registroId.value = '';
        els.registroCamposExtraContainer.innerHTML = '';
    }
});