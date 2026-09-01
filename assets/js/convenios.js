document.addEventListener('DOMContentLoaded', () => {
    const state = {
        convenios: [],
        confirmAction: null,
    };

    const els = {
        convenioSearchInput: document.getElementById('convenioSearchInput'),
        convenioDerivadoFilter: document.getElementById('convenioDerivadoFilter'),
        conveniosTableBody: document.getElementById('conveniosTableBody'),

        statConveniosTotal: document.getElementById('statConveniosTotal'),
        statConveniosResocentro: document.getElementById('statConveniosResocentro'),
        statConveniosServimovil: document.getElementById('statConveniosServimovil'),

        convenioFormModal: document.getElementById('convenioFormModal'),
        closeConvenioFormBackdrop: document.getElementById('closeConvenioFormBackdrop'),
        closeConvenioFormBtn: document.getElementById('closeConvenioFormBtn'),
        cancelConvenioFormBtn: document.getElementById('cancelConvenioFormBtn'),
        convenioForm: document.getElementById('convenioForm'),
        convenioFormTitle: document.getElementById('convenioFormTitle'),
        convenioFormSubtitle: document.getElementById('convenioFormSubtitle'),
        convenioId: document.getElementById('convenio_id'),
        convenioAtencionId: document.getElementById('convenio_atencion_id'),
        convenioPaciente: document.getElementById('convenio_paciente'),
        convenioFechaRealizar: document.getElementById('convenio_fecha_realizar'),
        convenioDerivadoA: document.getElementById('convenio_derivado_a'),
        convenioEstudio: document.getElementById('convenio_estudio'),
        convenioMedicoDerivado: document.getElementById('convenio_medico_derivado'),
        submitConvenioFormBtn: document.getElementById('submitConvenioFormBtn'),

        confirmConvenioModal: document.getElementById('confirmConvenioModal'),
        closeConfirmConvenioBackdrop: document.getElementById('closeConfirmConvenioBackdrop'),
        closeConfirmConvenioModalBtn: document.getElementById('closeConfirmConvenioModalBtn'),
        cancelConfirmConvenioBtn: document.getElementById('cancelConfirmConvenioBtn'),
        acceptConfirmConvenioBtn: document.getElementById('acceptConfirmConvenioBtn'),
        confirmConvenioModalTitle: document.getElementById('confirmConvenioModalTitle'),
        confirmConvenioModalText: document.getElementById('confirmConvenioModalText'),

        toast: document.getElementById('toast')
    };

    init();

    function init() {
        bindEvents();
        loadConvenios();
    }

    function bindEvents() {
        els.convenioSearchInput?.addEventListener('input', debounce(loadConvenios, 300));
        els.convenioDerivadoFilter?.addEventListener('change', loadConvenios);

        els.closeConvenioFormBackdrop?.addEventListener('click', closeConvenioModal);
        els.closeConvenioFormBtn?.addEventListener('click', closeConvenioModal);
        els.cancelConvenioFormBtn?.addEventListener('click', closeConvenioModal);
        els.convenioForm?.addEventListener('submit', handleConvenioSubmit);

        els.closeConfirmConvenioBackdrop?.addEventListener('click', closeConfirmModal);
        els.closeConfirmConvenioModalBtn?.addEventListener('click', closeConfirmModal);
        els.cancelConfirmConvenioBtn?.addEventListener('click', closeConfirmModal);
        els.acceptConfirmConvenioBtn?.addEventListener('click', handleConfirmAccept);

        document.addEventListener('click', handleDelegatedClicks);
        document.addEventListener('keydown', handleGlobalKeydown);
    }

    function handleDelegatedClicks(event) {
        const action = event.target.closest('[data-action]');
        if (!action) return;

        const actionType = action.dataset.action;
        const id = action.dataset.id ? Number(action.dataset.id) : null;

        if (actionType === 'edit-convenio' && id) {
            openEditConvenioModal(id);
        }

        if (actionType === 'delete-convenio' && id) {
            deleteConvenio(id);
        }
    }

    async function loadConvenios() {
        try {
            const params = new URLSearchParams();

            const search = els.convenioSearchInput?.value.trim() || '';
            const derivadoA = els.convenioDerivadoFilter?.value || '';

            if (search) params.append('search', search);
            if (derivadoA) params.append('derivado_a', derivadoA);

            const response = await fetch(`modules/convenios/list.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudieron cargar los convenios', true);
                return;
            }

            state.convenios = Array.isArray(result.records) ? result.records : [];
            renderConvenios();
            renderConvenioStats();
        } catch (error) {
            console.error(error);
            showToast('Error al cargar convenios', true);
        }
    }

    function renderConvenios() {
        if (!state.convenios.length) {
            els.conveniosTableBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="6">
                        No hay convenios registrados aún.<br>
                        <small>Ajusta filtros o registra convenios desde pacientes.</small>
                    </td>
                </tr>
            `;
            return;
        }

        els.conveniosTableBody.innerHTML = state.convenios.map(row => `
            <tr>
                <td>${escapeHtml(row.paciente || '')}</td>
                <td>${formatDate(row.fecha_realizar)}</td>
                <td>${renderDerivadoBadge(row.derivado_a)}</td>
                <td>${escapeHtml(row.estudio || '-')}</td>
                <td>${escapeHtml(row.medico_derivado || '-')}</td>
                ${window.CONVENIOS_CAN_WRITE ? `
                    <td>
                        <div class="table-actions">
                            <button class="btn-secondary btn-sm" data-action="edit-convenio" data-id="${row.id}" type="button">Editar</button>
                            <button class="btn-secondary btn-sm" data-action="delete-convenio" data-id="${row.id}" type="button">Eliminar</button>
                        </div>
                    </td>
                ` : ''}
            </tr>
        `).join('');
    }

    function renderConvenioStats() {
        const total = state.convenios.length;
        const resocentro = state.convenios.filter(item => item.derivado_a === 'Resocentro').length;
        const servimovil = state.convenios.filter(item => item.derivado_a === 'Servimovil').length;

        els.statConveniosTotal.textContent = total;
        els.statConveniosResocentro.textContent = resocentro;
        els.statConveniosServimovil.textContent = servimovil;
    }

    async function openEditConvenioModal(id) {
        try {
            const response = await fetch(`modules/convenios/get.php?id=${id}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudo cargar el convenio', true);
                return;
            }

            const data = result.data || {};

            els.convenioId.value = data.id || '';
            els.convenioAtencionId.value = data.atencion_id || '';
            els.convenioPaciente.value = data.paciente || '';
            els.convenioFechaRealizar.value = data.fecha_realizar || '';
            els.convenioDerivadoA.value = data.derivado_a || '';
            els.convenioEstudio.value = data.estudio || '';
            els.convenioMedicoDerivado.value = data.medico_derivado || '';

            openModal(els.convenioFormModal);
            focusElement(els.convenioFechaRealizar);
        } catch (error) {
            console.error(error);
            showToast('Error al cargar convenio', true);
        }
    }

    async function handleConvenioSubmit(event) {
        event.preventDefault();

        setButtonLoading(els.submitConvenioFormBtn, 'Guardando cambios...');

        try {
            const payload = {
                id: els.convenioId.value.trim(),
                atencion_id: els.convenioAtencionId.value.trim(),
                fecha_realizar: els.convenioFechaRealizar.value,
                derivado_a: els.convenioDerivadoA.value,
                estudio: els.convenioEstudio.value.trim(),
                medico_derivado: els.convenioMedicoDerivado.value.trim()
            };

            const result = await postJSON('modules/convenios/update.php', payload);

            if (!result.success) {
                showToast(result.message || 'No se pudo actualizar el convenio', true);
                return;
            }

            closeConvenioModal();
            showToast(result.message || 'Convenio actualizado correctamente');
            await loadConvenios();
        } catch (error) {
            console.error(error);
            showToast('Error al actualizar convenio', true);
        } finally {
            resetButtonLoading(els.submitConvenioFormBtn);
        }
    }

    function deleteConvenio(id) {
        openConfirmModal({
            title: 'Eliminar convenio',
            text: '¿Seguro que deseas eliminar este convenio? La atención seguirá existiendo en pacientes.',
            confirmText: 'Eliminar convenio',
            onConfirm: async () => {
                try {
                    const result = await postJSON('modules/convenios/delete.php', { id });

                    if (!result.success) {
                        showToast(result.message || 'No se pudo eliminar el convenio', true);
                        return;
                    }

                    showToast(result.message || 'Convenio eliminado correctamente');
                    await loadConvenios();
                } catch (error) {
                    console.error(error);
                    showToast('Error al eliminar convenio', true);
                }
            }
        });
    }

    function closeConvenioModal() {
        closeModal(els.convenioFormModal);
        resetConvenioFormState();
    }

    function resetConvenioFormState() {
        els.convenioForm?.reset();
        els.convenioId.value = '';
        els.convenioAtencionId.value = '';
        els.convenioPaciente.value = '';
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

    function openConfirmModal({ title, text, confirmText = 'Eliminar', onConfirm }) {
        state.confirmAction = typeof onConfirm === 'function' ? onConfirm : null;

        els.confirmConvenioModalTitle.textContent = title || 'Confirmar acción';
        els.confirmConvenioModalText.textContent = text || '¿Deseas continuar?';
        els.acceptConfirmConvenioBtn.textContent = confirmText;

        openModal(els.confirmConvenioModal);
    }

    function closeConfirmModal() {
        state.confirmAction = null;
        closeModal(els.confirmConvenioModal);
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

    function openModal(element) {
        element?.classList.remove('hidden');
        document.body.classList.add('modal-open');
    }

    function closeModal(element) {
        element?.classList.add('hidden');
        document.body.classList.remove('modal-open');
    }

    function handleGlobalKeydown(event) {
        if (event.key === 'Escape') {
            if (!els.confirmConvenioModal?.classList.contains('hidden')) closeConfirmModal();
            if (!els.convenioFormModal?.classList.contains('hidden')) closeConvenioModal();
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

    function formatDate(value) {
        if (!value) return '-';
        const date = new Date(`${value}T00:00:00`);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString('es-PE');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function debounce(fn, delay = 300) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
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

    function renderDerivadoBadge(value) {
        if (!value) return '-';

        const map = {
            'Resocentro': 'badge-primary',
            'Servimovil': 'badge-success'
        };

        const cls = map[value] || 'badge-neutral';

        return `<span class="badge ${cls}">${value}</span>`;
    }
});