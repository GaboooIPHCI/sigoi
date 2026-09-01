(function () {
    'use strict';

    const permissions = window.WHATSAPP_PERMISSIONS || {};
    const canInboxView = !!permissions.bandeja_ver;
    const canReply = !!permissions.bandeja_responder;
    const canChannelWhatsApp = permissions.canal_whatsapp !== false && permissions.canal_whatsapp !== 0;
    const canChannelInstagram = permissions.canal_instagram !== false && permissions.canal_instagram !== 0;
    const initialInboxChannel = canChannelWhatsApp && canChannelInstagram ? 'all' : (canChannelInstagram ? 'instagram' : 'whatsapp');
    const initialSelectedChannel = canChannelWhatsApp ? 'whatsapp' : (canChannelInstagram ? 'instagram' : 'whatsapp');
    const canManageInbox = !!permissions.bandeja_gestionar;
    const canAutomationView = !!permissions.automatizacion_ver;
    const canAutomationModify = !!permissions.automatizacion_modificar;
    const canLibraryView = !!permissions.plantillas_ver;
    const canLibraryManage = !!permissions.plantillas_gestionar;
    const canAnalyticsView = !!permissions.analitica_ver;
    const initialTab = window.WHATSAPP_INITIAL_TAB || null;
    const $ = (id) => document.getElementById(id);
    const days = [
        [1, 'Lunes'], [2, 'Martes'], [3, 'Miércoles'], [4, 'Jueves'],
        [5, 'Viernes'], [6, 'Sábado'], [7, 'Domingo']
    ];

    const app = {
        tab: initialTab,
        config: {}, horarios: [], reglas: [], stats: {}, integracion: {},
        inbox: { filter: 'all', channel: initialInboxChannel, query: '', rows: [], selectedId: null, selectedChannel: initialSelectedChannel, selected: null, polling: null, pollingBusy: false, queueBusy: false, instagramQueueBusy: false, lastMessageId: 0 },
        analyticsLoaded: false,
        selectedFile: null,
        templates: { rows: [], loaded: false, busy: false, editing: null, sendSelectedKey: null },
        quickReplies: { rows: [], loaded: false }
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }


    // Variables dinámicas de respuestas rápidas. Por ahora solo {{saludo}}.
    // Se calcula justo al insertar la respuesta en el chat.
    function getLimaGreeting(now = new Date()) {
        let hour = null;
        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: 'America/Lima',
                hour: '2-digit',
                hourCycle: 'h23'
            }).formatToParts(now);
            const hourPart = parts.find(part => part.type === 'hour');
            if (hourPart) hour = Number(hourPart.value);
        } catch (_) {}

        // Respaldo para navegadores antiguos. Lima usa UTC-5 sin horario de verano actual.
        if (!Number.isFinite(hour)) hour = (now.getUTCHours() + 19) % 24;

        if (hour >= 5 && hour <= 11) return 'Buenos días';
        if (hour >= 12 && hour <= 18) return 'Buenas tardes';
        return 'Buenas noches';
    }

    function resolveQuickReplyDynamicText(content, now = new Date()) {
        return String(content || '').replace(/\{\{\s*saludo\s*\}\}/gi, getLimaGreeting(now));
    }

    function hasQuickGreetingToken(content) {
        return /\{\{\s*saludo\s*\}\}/i.test(String(content || ''));
    }

    function toast(message, error) {
        const t = $('toast');
        if (!t) return;
        t.textContent = message;
        t.className = 'toast ' + (error ? 'toast-error' : 'toast-success');
        t.classList.remove('hidden');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.classList.add('hidden'), 3400);
    }

    async function api(path, options) {
        const response = await fetch('modules/whatsapp/' + path, options);
        let data;
        try { data = await response.json(); }
        catch (e) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'Ocurrió un error.');
        return data;
    }

    function post(path, payload) {
        return api(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.APP_CSRF_TOKEN },
            body: JSON.stringify(payload)
        });
    }

    async function instagramApi(path, options) {
        const response = await fetch('modules/instagram/' + path, options);
        let data;
        try { data = await response.json(); }
        catch (e) { throw new Error('Instagram devolvió una respuesta no válida.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'No se pudo consultar Instagram.');
        return data;
    }

    function instagramPost(path, payload) {
        return instagramApi(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.APP_CSRF_TOKEN },
            body: JSON.stringify(payload)
        });
    }

    function channelApi(channel, path, options) {
        return channel === 'instagram' ? instagramApi(path, options) : api(path, options);
    }

    function channelPost(channel, path, payload) {
        return channel === 'instagram' ? instagramPost(path, payload) : post(path, payload);
    }

    function parseDate(value) {
        if (!value) return null;
        const normalized = String(value).replace(' ', 'T');
        const d = new Date(normalized);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function formatDateTime(value, compact) {
        const d = parseDate(value);
        if (!d) return '—';
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        if (compact && sameDay) return d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: compact ? undefined : 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function formatDayLabel(value) {
        const d = parseDate(value);
        if (!d) return '';
        const today = new Date();
        const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Hoy';
        if (d.toDateString() === yesterday.toDateString()) return 'Ayer';
        return d.toLocaleDateString('es-PE', { weekday: 'short', day: '2-digit', month: 'short' });
    }

    function formatDuration(seconds) {
        if (seconds == null || Number.isNaN(Number(seconds))) return '—';
        seconds = Math.max(0, Number(seconds));
        if (seconds < 60) return Math.round(seconds) + ' s';
        if (seconds < 3600) return Math.round(seconds / 60) + ' min';
        const h = Math.floor(seconds / 3600);
        const m = Math.round((seconds % 3600) / 60);
        return h + ' h' + (m ? ' ' + m + ' min' : '');
    }

    function formatFileSize(bytes) {
        bytes = Number(bytes || 0);
        if (!bytes) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? Math.round(bytes) : bytes.toFixed(bytes >= 10 ? 1 : 2)) + ' ' + units[i];
    }

    function formatWhatsAppPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (!digits) return '';

        // Números de Perú: +51 seguido de 9 dígitos.
        // Ejemplo: 51901952781 -> +51 901 952 781
        if (digits.length === 11 && digits.startsWith('51')) {
            const local = digits.slice(2);
            return `+51 ${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6, 9)}`;
        }

        // Si algún registro antiguo conserva solo los 9 dígitos locales.
        if (digits.length === 9 && digits.startsWith('9')) {
            return `+51 ${digits.slice(0, 3)} ${digits.slice(3, 6)} ${digits.slice(6, 9)}`;
        }

        // No adivinamos códigos de país de otros números: conservamos el valor.
        return '+' + digits;
    }

    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
        return (parts.slice(0, 2).map(p => p.charAt(0)).join('') || '?').toUpperCase();
    }

    function statusLabel(status) {
        const map = { sent: 'Enviado', accepted: 'Aceptado', delivered: 'Entregado', read: 'Leído', failed: 'Falló', enviado: 'Enviado', leido: 'Leído', error: 'Falló', recibido: 'Recibido' };
        return map[String(status || '').toLowerCase()] || String(status || '');
    }

    function botIntervals(start, end) {
        if (!start || !end) return 'Bot: todo el día';
        if (start < end) return `Bot: 00:00–${start} y ${end}–23:59`;
        if (start > end) return `Bot: ${end}–${start}`;
        return 'Bot: todo el día';
    }

    // =====================================================
    // Tabs
    // =====================================================
    function tabAllowed(tab) {
        if (tab === 'inbox') return canInboxView;
        if (tab === 'automation') return canAutomationView;
        if (tab === 'library') return canLibraryView;
        if (tab === 'analytics') return canAnalyticsView;
        return false;
    }

    function setTab(tab) {
        if (!tabAllowed(tab)) return;

        app.tab = tab;
        document.querySelector('.whatsapp-page')?.classList.toggle('is-inbox-view', tab === 'inbox');
        document.querySelectorAll('[data-wa-tab]').forEach(btn => btn.classList.toggle('is-active', btn.dataset.waTab === tab));
        document.querySelectorAll('[data-wa-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.waPanel === tab));

        if (tab === 'inbox') {
            loadInbox(true);
            startPolling();
        } else {
            stopPolling();
        }

        if (tab === 'analytics' && !app.analyticsLoaded) loadAnalytics();
        if (tab === 'library') loadMessageLibrary();
    }


    function setContactDrawer(open) {
        const shell = document.querySelector('.wa-inbox-shell');
        if (!shell) return;
        shell.classList.toggle('is-contact-open', !!open);
        if ($('waContactToggleBtn')) {
            $('waContactToggleBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
            $('waContactToggleBtn').textContent = open ? 'Ocultar contacto' : 'Contacto';
        }
    }

    function setContactEditor(open) {
        const editor = $('waContactEditor');
        if (!editor) return;
        editor.classList.toggle('is-collapsed', !open);
        if ($('waContactEditorToggle')) {
            $('waContactEditorToggle').setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    $('waContactToggleBtn')?.addEventListener('click', () => {
        const shell = document.querySelector('.wa-inbox-shell');
        setContactDrawer(!shell?.classList.contains('is-contact-open'));
    });

    $('waCloseContactBtn')?.addEventListener('click', () => setContactDrawer(false));

    $('waContactEditorToggle')?.addEventListener('click', () => {
        const editor = $('waContactEditor');
        setContactEditor(editor?.classList.contains('is-collapsed'));
    });

    // La ficha queda plegada por defecto para no ocupar espacio innecesario.
    setContactEditor(false);

    document.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-wa-tab]');
        if (tab) setTab(tab.dataset.waTab);
    });

    // =====================================================
    // Status / Automatización
    // =====================================================
    function renderSchedule() {
        if (!$('waSchedule')) return;
        const map = new Map((app.horarios || []).map(r => [Number(r.dia_semana), r]));
        $('waSchedule').innerHTML = days.map(([number, label]) => {
            const row = map.get(number) || { atencion_humana_activa: 0, hora_inicio: '', hora_fin: '' };
            const active = Number(row.atencion_humana_activa) === 1;
            const disabled = !active || !canAutomationModify;
            const mode = active ? `Humano ${row.hora_inicio || '--:--'}–${row.hora_fin || '--:--'} · ${botIntervals(row.hora_inicio, row.hora_fin)}` : 'Sin atención humana · Bot disponible todo el día';
            return `
                <div class="wa-schedule-row" data-day="${number}">
                    <div class="wa-schedule-day">
                        <strong>${label}</strong>
                        <label class="wa-mini-switch"><input type="checkbox" class="wa-day-active" ${active ? 'checked' : ''} ${canAutomationModify ? '' : 'disabled'}><span></span></label>
                    </div>
                    <div class="wa-schedule-hours ${active ? '' : 'is-disabled'}">
                        <label><span>Desde</span><input type="time" class="wa-day-start" value="${escapeHtml(row.hora_inicio || '')}" ${disabled ? 'disabled' : ''}></label>
                        <span class="wa-schedule-separator">a</span>
                        <label><span>Hasta</span><input type="time" class="wa-day-end" value="${escapeHtml(row.hora_fin || '')}" ${disabled ? 'disabled' : ''}></label>
                    </div>
                    <span class="wa-schedule-mode">${escapeHtml(mode)}</span>
                </div>`;
        }).join('');
    }

    function renderRules() {
        const list = $('waRulesList');
        if (!list) return;
        const rules = app.reglas || [];
        if (!rules.length) {
            list.innerHTML = `<div class="wa-empty"><strong>Aún no hay reglas.</strong><span>${canAutomationModify ? 'Crea la primera regla para comenzar.' : 'No hay reglas configuradas.'}</span></div>`;
            return;
        }
        list.innerHTML = rules.map(rule => {
            const keywords = String(rule.palabras_clave || '').split(/\r?\n/).filter(Boolean);
            return `<article class="wa-rule-card ${Number(rule.activa) === 1 ? '' : 'is-off'}">
                <div class="wa-rule-card__top">
                    <div>
                        <div class="wa-rule-card__title-row"><strong>${escapeHtml(rule.nombre)}</strong><span class="wa-priority">Prioridad ${Number(rule.prioridad)}</span><span class="wa-rule-state ${Number(rule.activa) === 1 ? 'is-on' : ''}">${Number(rule.activa) === 1 ? 'Activa' : 'Inactiva'}</span></div>
                        <div class="wa-keywords">${keywords.slice(0, 8).map(k => `<span>${escapeHtml(k)}</span>`).join('')}${keywords.length > 8 ? `<span>+${keywords.length - 8}</span>` : ''}</div>
                    </div>
                    ${canAutomationModify ? `<div class="wa-rule-actions"><button type="button" data-wa-edit-rule="${Number(rule.id)}">Editar</button><button type="button" data-wa-toggle-rule="${Number(rule.id)}">${Number(rule.activa) === 1 ? 'Desactivar' : 'Activar'}</button><button type="button" class="is-danger" data-wa-delete-rule="${Number(rule.id)}">Eliminar</button></div>` : ''}
                </div>
                <div class="wa-rule-response"><small>Respuesta</small><p>${escapeHtml(rule.respuesta)}</p></div>
            </article>`;
        }).join('');
    }

    function renderStatus(data) {
        app.config = data.config || {};
        app.horarios = data.horarios || [];
        app.reglas = data.reglas || [];
        app.stats = data.stats || {};
        app.integracion = data.integracion || {};

        if (canAutomationView) {
            if ($('waAutomationActive')) $('waAutomationActive').checked = Number(app.config.automatizacion_activa) === 1;
            if ($('waWelcomeMessage')) $('waWelcomeMessage').value = app.config.mensaje_bienvenida || '';
            if ($('waFallbackMessage')) $('waFallbackMessage').value = app.config.mensaje_no_reconocido || '';
        }
        if ($('waIntegrationStatus')) $('waIntegrationStatus').textContent = app.integracion.texto || 'YCloud';
        if ($('waIntegrationNumber')) $('waIntegrationNumber').textContent = formatWhatsAppPhone(app.integracion.numero || '');
        if ($('waCronStatus')) {
            const cronOn = !!app.integracion.cron_24h_activo;
            $('waCronStatus').textContent = cronOn
                ? '24/7 activo · cola procesándose aun con S.I.G.O.I. cerrado'
                : '24/7 pendiente · falta activar el cron';
            $('waCronStatus').classList.toggle('is-active', cronOn);
        }
        if ($('waStatusDot')) $('waStatusDot').className = 'wa-status-dot ' + (app.integracion.estado === 'connected' ? 'is-connected' : 'is-pending');

        if ($('waStatRules')) $('waStatRules').textContent = Number(app.stats.reglas_activas || 0);
        if ($('waStatMessages')) $('waStatMessages').textContent = Number(app.stats.mensajes_hoy || 0);
        if ($('waStatReplies')) $('waStatReplies').textContent = Number(app.stats.respuestas_hoy || 0);
        if ($('waStatPending')) $('waStatPending').textContent = Number(app.stats.pendientes_humano || 0);
        updateUnreadBadge(Number(app.stats.no_leidos || 0));
        if (canAutomationView) {
            renderSchedule();
            renderRules();
        }
    }

    async function loadStatus() {
        try { renderStatus(await api('status.php')); }
        catch (e) { toast(e.message, true); }
    }

    function collectSchedules() {
        return Array.from(document.querySelectorAll('.wa-schedule-row')).map(row => {
            const active = row.querySelector('.wa-day-active').checked;
            return { dia_semana: Number(row.dataset.day), atencion_humana_activa: active, hora_inicio: active ? row.querySelector('.wa-day-start').value : '', hora_fin: active ? row.querySelector('.wa-day-end').value : '' };
        });
    }

    function findRule(id) { return (app.reglas || []).find(r => Number(r.id) === Number(id)); }
    function openRule(rule) {
        if (!canAutomationModify) return;
        $('waRuleForm').reset();
        $('waRuleId').value = rule ? rule.id : '';
        $('waRuleName').value = rule ? rule.nombre : '';
        $('waRulePriority').value = rule ? rule.prioridad : 100;
        $('waRuleActive').checked = rule ? Number(rule.activa) === 1 : true;
        $('waRuleKeywords').value = rule ? rule.palabras_clave : '';
        $('waRuleResponse').value = rule ? rule.respuesta : '';
        $('waRuleModalTitle').textContent = rule ? 'Editar regla' : 'Nueva regla';
        $('waRuleModal').classList.remove('hidden');
        setTimeout(() => $('waRuleName').focus(), 20);
    }
    function closeRule() { if ($('waRuleModal')) $('waRuleModal').classList.add('hidden'); }

    document.addEventListener('change', (e) => {
        const checkbox = e.target.closest('.wa-day-active');
        if (!checkbox) return;
        const row = checkbox.closest('.wa-schedule-row');
        const active = checkbox.checked;
        const start = row.querySelector('.wa-day-start');
        const end = row.querySelector('.wa-day-end');
        row.querySelector('.wa-schedule-hours').classList.toggle('is-disabled', !active);
        start.disabled = !active; end.disabled = !active;
        if (active && !start.value) start.value = '09:00';
        if (active && !end.value) end.value = '18:00';
        row.querySelector('.wa-schedule-mode').textContent = active ? `Humano ${start.value}–${end.value} · ${botIntervals(start.value, end.value)}` : 'Sin atención humana · Bot disponible todo el día';
    });

    document.addEventListener('input', (e) => {
        if (e.target.matches('.wa-day-start,.wa-day-end')) {
            const row = e.target.closest('.wa-schedule-row');
            const start = row.querySelector('.wa-day-start').value;
            const end = row.querySelector('.wa-day-end').value;
            row.querySelector('.wa-schedule-mode').textContent = `Humano ${start || '--:--'}–${end || '--:--'} · ${botIntervals(start, end)}`;
        }
    });

    document.addEventListener('click', async (e) => {
        const edit = e.target.closest('[data-wa-edit-rule]');
        const toggle = e.target.closest('[data-wa-toggle-rule]');
        const del = e.target.closest('[data-wa-delete-rule]');
        const close = e.target.closest('[data-wa-close-rule]');
        if (edit) openRule(findRule(edit.dataset.waEditRule));
        if (close) closeRule();
        if (toggle) {
            const rule = findRule(toggle.dataset.waToggleRule); if (!rule) return;
            try { toast((await post('rules-toggle.php', { id: rule.id, activa: Number(rule.activa) !== 1 })).message); await loadStatus(); }
            catch (err) { toast(err.message, true); }
        }
        if (del) {
            const rule = findRule(del.dataset.waDeleteRule); if (!rule || !confirm(`¿Eliminar la regla "${rule.nombre}"?`)) return;
            try { toast((await post('rules-delete.php', { id: rule.id })).message); await loadStatus(); }
            catch (err) { toast(err.message, true); }
        }
    });

    if ($('waNewRuleBtn')) $('waNewRuleBtn').addEventListener('click', () => openRule(null));
    if (canAutomationModify) {
        $('waSettingsForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await post('settings-save.php', { automatizacion_activa: $('waAutomationActive').checked, mensaje_bienvenida: $('waWelcomeMessage').value, mensaje_no_reconocido: $('waFallbackMessage').value, horarios: collectSchedules() });
                toast(data.message); await loadStatus();
            } catch (err) { toast(err.message, true); }
        });
        $('waRuleForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await post('rules-save.php', { id: Number($('waRuleId').value || 0), nombre: $('waRuleName').value, prioridad: Number($('waRulePriority').value || 100), activa: $('waRuleActive').checked, palabras_clave: $('waRuleKeywords').value, respuesta: $('waRuleResponse').value });
                toast(data.message); closeRule(); await loadStatus();
            } catch (err) { toast(err.message, true); }
        });
    }

    $('waTestBtn')?.addEventListener('click', async () => {
        const message = $('waTestMessage').value.trim();
        if (!message) return toast('Escribe un mensaje para probar.', true);
        $('waTestBtn').disabled = true;
        try {
            const data = await post('test.php', { mensaje: message });
            const box = $('waTestResult');
            if (data.coincidencia) {
                box.className = 'wa-test-result is-match';
                box.innerHTML = `<small>Coincidencia encontrada</small><strong>${escapeHtml(data.regla.nombre)}</strong><span>Detectó: “${escapeHtml(data.regla.palabra)}”</span><p>${escapeHtml(data.regla.respuesta)}</p>`;
            } else {
                box.className = 'wa-test-result is-fallback';
                box.innerHTML = `<small>Sin coincidencia</small><strong>Respuesta de respaldo</strong><p>${escapeHtml(data.respuesta)}</p>`;
            }
        } catch (err) { toast(err.message, true); }
        finally { $('waTestBtn').disabled = false; }
    });

    // =====================================================
    // Inbox
    // =====================================================
    function updateUnreadBadge(count) {
        const badge = $('waInboxUnreadBadge');
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : count;
        badge.classList.toggle('hidden', count <= 0);
    }

    function setSyncState(loading, label) {
        const line = $('waSyncState')?.closest('.wa-sync-line');
        if (line) line.classList.toggle('is-loading', !!loading);
        const target = $('waSyncState');
        if (target) {
            if (label) target.textContent = label;
            else if (loading) target.textContent = 'Actualizando...';
            else target.textContent = 'En vivo · ' + new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        const btn = $('waManualRefreshBtn');
        if (btn) btn.classList.toggle('is-loading', !!loading);
    }

    function conversationChannel(row) {
        return String(row?.canal || row?.channel || 'whatsapp').toLowerCase() === 'instagram' ? 'instagram' : 'whatsapp';
    }

    function conversationName(row) {
        const channel = conversationChannel(row);
        const username = String(row?.username_whatsapp || row?.username || '').replace(/^@/, '');
        if (row?.nombre_personalizado) return row.nombre_personalizado;
        if (row?.nombre_contacto) return row.nombre_contacto;
        if (username) return '@' + username;
        if (channel === 'instagram') return row?.igsid ? `Instagram ${String(row.igsid).slice(-6)}` : 'Instagram';
        return formatWhatsAppPhone(row?.telefono) || 'Sin nombre';
    }

    function conversationKey(rowOrChannel, id) {
        if (typeof rowOrChannel === 'object') return `${conversationChannel(rowOrChannel)}:${Number(rowOrChannel.id || 0)}`;
        return `${String(rowOrChannel || 'whatsapp')}:${Number(id || 0)}`;
    }

    function renderConversationList() {
        const list = $('waConversationList');
        const rows = app.inbox.rows || [];
        $('waInboxCount').textContent = rows.length;
        if (!rows.length) {
            list.innerHTML = '<div class="wa-list-empty"><strong>No hay conversaciones</strong><span>Prueba otro filtro o espera nuevos mensajes.</span></div>';
            return;
        }
        list.innerHTML = rows.map(row => {
            const channel = conversationChannel(row);
            const name = conversationName(row);
            const selected = Number(row.id) === Number(app.inbox.selectedId) && channel === app.inbox.selectedChannel;
            const preview = row.ultimo_mensaje_preview || '[Mensaje]';
            const outgoing = row.ultimo_mensaje_direccion === 'saliente';
            const channelMark = channel === 'instagram'
                ? '<span class="wa-channel-mark is-instagram" title="Instagram">IG</span>'
                : '<span class="wa-channel-mark is-whatsapp" title="WhatsApp">W</span>';
            return `<button type="button" class="wa-conversation-item ${selected ? 'is-selected' : ''} ${Number(row.no_leidos) > 0 ? 'has-unread' : ''}" data-wa-conversation="${Number(row.id)}" data-wa-channel="${channel}">
                <div class="wa-avatar wa-avatar--channel">${escapeHtml(initials(name))}${channelMark}</div>
                <div class="wa-conversation-copy">
                    <div class="wa-conversation-top"><strong>${escapeHtml(name)}</strong><time>${escapeHtml(formatDateTime(row.ultimo_mensaje_en, true))}</time></div>
                    <div class="wa-conversation-preview"><span>${outgoing ? 'Tú: ' : ''}${escapeHtml(preview)}</span>${Number(row.no_leidos) > 0 ? `<b>${Number(row.no_leidos) > 99 ? '99+' : Number(row.no_leidos)}</b>` : ''}</div>
                    <div class="wa-conversation-flags"><span class="wa-conversation-channel is-${channel}">${channel === 'instagram' ? 'Instagram' : 'WhatsApp'}</span>${Number(row.requiere_humano) === 1 ? '<span class="is-pending">Pendiente</span>' : ''}${row.asignado_nombre ? `<span>${escapeHtml(row.asignado_nombre)}</span>` : ''}${row.estado === 'cerrada' ? '<span class="is-resolved">Resuelta</span>' : ''}</div>
                </div>
            </button>`;
        }).join('');
    }

    async function processWhatsappQueue() {
        if (app.inbox.queueBusy) return;
        app.inbox.queueBusy = true;
        try { await api('queue-process.php'); }
        catch (e) { /* La bandeja sigue funcionando aunque el worker falle temporalmente. */ }
        finally { app.inbox.queueBusy = false; }
    }

    async function processInstagramQueue() {
        if (app.inbox.instagramQueueBusy) return;
        app.inbox.instagramQueueBusy = true;
        try { await instagramApi('queue-process.php'); }
        catch (e) { /* Mientras el cron no esté activo, la bandeja intenta procesar la cola al abrirse. */ }
        finally { app.inbox.instagramQueueBusy = false; }
    }

    async function processInboxQueues() {
        const jobs = [];
        if (canChannelWhatsApp && app.inbox.channel !== 'instagram') jobs.push(processWhatsappQueue());
        if (canChannelInstagram && app.inbox.channel !== 'whatsapp') jobs.push(processInstagramQueue());
        await Promise.all(jobs);
    }

    async function fetchInboxRows() {
        const qs = new URLSearchParams({ q: app.inbox.query, filter: app.inbox.filter });
        const jobs = [];
        const channels = [];
        if (canChannelWhatsApp && app.inbox.channel !== 'instagram') {
            channels.push('whatsapp');
            jobs.push(api('inbox-list.php?' + qs.toString()));
        }
        if (canChannelInstagram && app.inbox.channel !== 'whatsapp') {
            channels.push('instagram');
            jobs.push(instagramApi('inbox-list.php?' + qs.toString()));
        }

        const results = await Promise.allSettled(jobs);
        let rows = [];
        let firstError = null;
        results.forEach((result, index) => {
            const channel = channels[index];
            if (result.status === 'fulfilled') {
                rows = rows.concat((result.value.conversaciones || []).map(row => Object.assign({}, row, { canal: channel })));
            } else if (!firstError) {
                firstError = result.reason;
            }
        });

        if (!rows.length && firstError && app.inbox.channel !== 'all') throw firstError;
        rows.sort((a, b) => {
            const ad = parseDate(a.ultimo_mensaje_en || a.creado_en)?.getTime() || 0;
            const bd = parseDate(b.ultimo_mensaje_en || b.creado_en)?.getTime() || 0;
            return bd - ad;
        });
        return rows;
    }

    async function loadInbox(preserveSelection) {
        await processInboxQueues();
        setSyncState(true);
        try {
            app.inbox.rows = await fetchInboxRows();
            const totalUnread = app.inbox.rows.reduce((sum, r) => sum + Number(r.no_leidos || 0), 0);
            updateUnreadBadge(totalUnread);

            const selectedStillExists = app.inbox.selectedId && app.inbox.rows.some(r =>
                Number(r.id) === Number(app.inbox.selectedId) && conversationChannel(r) === app.inbox.selectedChannel
            );
            if (!preserveSelection || !selectedStillExists) {
                const first = app.inbox.rows[0] || null;
                app.inbox.selectedId = first ? Number(first.id) : null;
                app.inbox.selectedChannel = first ? conversationChannel(first) : (app.inbox.channel === 'instagram' ? 'instagram' : (app.inbox.channel === 'whatsapp' ? 'whatsapp' : initialSelectedChannel));
                app.inbox.lastMessageId = 0;
            }

            renderConversationList();
            if (app.inbox.selectedId) await loadConversation(app.inbox.selectedId, true, app.inbox.selectedChannel);
            else showEmptyChat();
            setSyncState(false);
        } catch (e) {
            setSyncState(false, 'Error al actualizar');
            toast(e.message, true);
        }
    }

    function showEmptyChat() {
        $('waChatEmpty').classList.remove('hidden'); $('waChatView').classList.add('hidden');
        $('waContactEmpty').classList.remove('hidden'); $('waContactView').classList.add('hidden');
        app.inbox.selected = null;
    }

    function renderMedia(message) {
        if (!message.media) return '';
        const url = escapeHtml(message.media.url);
        const filename = escapeHtml(message.media.filename || 'Archivo');
        const mime = String(message.media.mime || '');
        if (message.tipo === 'image' || mime.indexOf('image/') === 0) {
            return `<button type="button" class="wa-media-image" data-wa-image="${url}"><img src="${url}" alt="${filename}" loading="lazy"></button>`;
        }
        if (message.tipo === 'video' || mime.indexOf('video/') === 0) {
            return `<video class="wa-media-video" controls preload="metadata"><source src="${url}" type="${escapeHtml(mime)}"></video>`;
        }
        if (message.tipo === 'audio' || mime.indexOf('audio/') === 0) {
            return `<audio class="wa-media-audio" controls preload="metadata"><source src="${url}" type="${escapeHtml(mime)}"></audio>`;
        }
        return `<a class="wa-document-card" href="${url}" target="_blank" rel="noopener"><span class="wa-document-icon">DOC</span><span><strong>${filename}</strong><small>${escapeHtml(formatFileSize(message.media.size))}${mime ? ' · ' + escapeHtml(mime) : ''}</small></span><b>↗</b></a>`;
    }

    function renderMessage(message) {
        const incoming = message.direccion === 'entrante';
        const auto = message.origen === 'automatizacion';
        const appOrigin = message.origen === 'whatsapp_app';
        const ycloudOrigin = message.origen === 'ycloud';
        const instagramOrigin = message.origen === 'instagram_app';
        const body = String(message.contenido || '').replace(/^\[(Imagen|Video|Audio|Documento)\]\s*/i, '').trim();
        const media = renderMedia(message);
        let status = incoming ? '' : statusLabel(message.estado_envio);
        if (!incoming && app.inbox.selectedChannel === 'instagram' && String(message.estado_envio || '').toLowerCase() === 'leido') status = 'Visto';
        const statusClass = String(message.estado_envio || '').toLowerCase() === 'failed' ? 'is-failed' : '';
        return `<div class="wa-message-row ${incoming ? 'is-incoming' : 'is-outgoing'}">
            <article class="wa-message-bubble ${auto ? 'is-auto' : ''} ${appOrigin ? 'is-app' : ''} ${ycloudOrigin ? 'is-ycloud' : ''} ${instagramOrigin ? 'is-instagram' : ''}">
                ${!incoming ? `<div class="wa-message-origin">${escapeHtml(message.origen_label || 'S.I.G.O.I.')}${message.plantilla ? `<span class="wa-message-template-tag">Plantilla WhatsApp · ${escapeHtml(message.plantilla.name || '')}</span>` : ''}</div>` : ''}
                ${media}
                ${body ? `<div class="wa-message-text">${escapeHtml(body).replace(/\n/g, '<br>')}</div>` : ''}
                <div class="wa-message-meta"><time>${escapeHtml(formatDateTime(message.creado_en, true))}</time>${message.editado_veces ? '<span>Editado</span>' : ''}${message.reaccion_emoji ? `<span title="Reacción">${escapeHtml(message.reaccion_emoji)}</span>` : ''}${message.fuera_horario ? '<span title="Recibido fuera de horario">🌙</span>' : ''}${status ? `<span class="wa-message-status ${statusClass}">${escapeHtml(status)}</span>` : ''}</div>
                ${message.error_envio ? `<div class="wa-message-error">${escapeHtml(message.error_envio)}</div>` : ''}
            </article>
        </div>`;
    }

    function renderMessages(messages) {
        const box = $('waChatMessages');
        if (!messages.length) { box.innerHTML = '<div class="wa-chat-no-messages">No hay mensajes en esta conversación.</div>'; return; }
        let lastDay = '';
        let html = '';
        messages.forEach(msg => {
            const day = formatDayLabel(msg.creado_en);
            if (day !== lastDay) { html += `<div class="wa-date-divider"><span>${escapeHtml(day)}</span></div>`; lastDay = day; }
            html += renderMessage(msg);
        });
        box.innerHTML = html;
    }

    function renderConversation(data, preserveScroll) {
        const c = data.conversacion;
        const channel = String(data.canal || c.canal || app.inbox.selectedChannel || 'whatsapp').toLowerCase() === 'instagram' ? 'instagram' : 'whatsapp';
        app.inbox.selectedChannel = channel;
        const previousLastId = Number(app.inbox.lastMessageId || 0);
        app.inbox.selected = data;
        const name = conversationName(Object.assign({}, c, { canal: channel }));
        const messagesBox = $('waChatMessages');
        const nearBottom = messagesBox.scrollHeight - messagesBox.scrollTop - messagesBox.clientHeight < 140;
        const rows = data.mensajes || [];
        const newestId = rows.length ? Math.max(...rows.map(m => Number(m.id || 0))) : 0;
        const hasNewMessages = previousLastId > 0 && newestId > previousLastId;
        app.inbox.lastMessageId = newestId;

        $('waChatEmpty').classList.add('hidden'); $('waChatView').classList.remove('hidden');
        $('waContactEmpty').classList.add('hidden'); $('waContactView').classList.remove('hidden');
        $('waChatAvatar').textContent = initials(name); $('waDetailAvatar').textContent = initials(name);
        $('waChatName').textContent = name; $('waDetailName').textContent = name;

        const username = String(c.username_whatsapp || c.username || '').replace(/^@/, '');
        if (channel === 'instagram') {
            $('waChatPhone').textContent = username ? '@' + username : 'Instagram';
            $('waDetailPhone').textContent = 'Instagram';
        } else {
            $('waChatPhone').textContent = formatWhatsAppPhone(c.telefono);
            $('waDetailPhone').textContent = formatWhatsAppPhone(c.telefono);
        }

        if ($('waChatChannelBadge')) {
            $('waChatChannelBadge').textContent = channel === 'instagram' ? 'Instagram' : 'WhatsApp';
            $('waChatChannelBadge').className = 'wa-chat-channel-badge is-' + channel;
        }

        $('waChatState').textContent = c.estado === 'cerrada' ? 'Resuelta' : (Number(c.requiere_humano) === 1 ? 'Pendiente' : 'Abierta');
        $('waChatState').className = 'wa-chat-state ' + (c.estado === 'cerrada' ? 'is-resolved' : (Number(c.requiere_humano) === 1 ? 'is-pending' : ''));
        $('waChatAssignment').textContent = c.asignado_nombre || 'Sin asignar';
        $('waDetailUsername').textContent = username ? '@' + username : '';
        $('waDetailUsername').classList.toggle('hidden', !username);

        if ($('waDetailWhatsappName')) {
            const detected = String(c.nombre_contacto || '').trim();
            if (channel === 'instagram') {
                $('waDetailWhatsappName').textContent = detected ? `Perfil de Instagram: ${detected}` : '';
            } else {
                $('waDetailWhatsappName').textContent = c.nombre_personalizado && detected
                    ? `Nombre de WhatsApp: ${detected}`
                    : (detected ? `Perfil de WhatsApp: ${detected}` : '');
            }
            $('waDetailWhatsappName').classList.toggle('hidden', !detected);
        }
        if ($('waContactCustomName')) $('waContactCustomName').value = c.nombre_personalizado || '';
        if ($('waContactNotes')) $('waContactNotes').value = c.notas_contacto || '';

        if ($('waOriginSection')) {
            const hasOrigin = channel === 'whatsapp' && !!(c.origen_fuente || c.origen_id || c.origen_url || c.origen_titulo);
            $('waOriginSection').classList.toggle('hidden', !hasOrigin);
            if (hasOrigin) {
                const sourceLabel = c.origen_fuente === 'meta_ad' ? 'Anuncio de Meta' : (c.origen_fuente || 'Origen detectado');
                $('waOriginTitle').textContent = c.origen_titulo || (c.origen_fuente === 'meta_ad' ? 'Facebook / Instagram Ads' : 'Origen del contacto');
                $('waOriginMeta').textContent = c.origen_id ? `${sourceLabel} · ID ${c.origen_id}` : sourceLabel;
                if ($('waOriginLink')) {
                    $('waOriginLink').classList.toggle('hidden', !c.origen_url);
                    if (c.origen_url) $('waOriginLink').href = c.origen_url;
                }
                if ($('waOriginImage')) {
                    $('waOriginImage').classList.toggle('hidden', !c.origen_media_url);
                    if (c.origen_media_url) $('waOriginImage').src = c.origen_media_url;
                }
            }
        }

        $('waDetailState').textContent = c.estado === 'cerrada' ? 'Resuelta' : 'Abierta';
        $('waDetailPending').classList.toggle('hidden', Number(c.requiere_humano) !== 1);
        $('waDetailFirst').textContent = formatDateTime(c.primer_mensaje_en || c.creado_en, false);
        $('waDetailLast').textContent = formatDateTime(c.ultimo_mensaje_en, false);
        $('waDetailMessages').textContent = Number(c.total_mensajes || 0);
        $('waDetailResponse').textContent = formatDuration(c.tiempo_primera_respuesta_humana_seg);

        if ($('waDetailChannelTitle')) {
            $('waDetailChannelTitle').textContent = channel === 'instagram' ? 'Instagram + S.I.G.O.I.' : 'WhatsApp Business + S.I.G.O.I.';
            $('waDetailChannelText').textContent = channel === 'instagram'
                ? 'Mensajes directos mediante la API oficial de Meta'
                : 'Coexistence activo mediante YCloud';
            $('waDetailChannelCard')?.classList.toggle('is-instagram', channel === 'instagram');
        }

        if ($('waAssigneeSelect')) {
            $('waAssigneeSelect').innerHTML = '<option value="0">Sin asignar</option>' + (data.usuarios || []).map(u => `<option value="${Number(u.id)}">${escapeHtml(u.nombre)} · ${escapeHtml(u.rol)}</option>`).join('');
            $('waAssigneeSelect').value = String(c.asignado_a || 0);
        }
        if ($('waResolveBtn')) $('waResolveBtn').textContent = c.estado === 'cerrada' ? 'Reabrir' : 'Marcar resuelta';

        const windowActive = !!data.ventana_24h?.active;
        const sendEnabled = channel === 'whatsapp' ? true : !!data.send_enabled;
        const canCompose = canReply && windowActive && sendEnabled;

        if ($('waRetakeBtn')) $('waRetakeBtn').classList.toggle('hidden', channel !== 'whatsapp');

        if (channel === 'instagram' && !sendEnabled) {
            $('waWindowAlert').classList.remove('hidden');
            if ($('waWindowAlertTitle')) $('waWindowAlertTitle').textContent = 'Instagram preparado para pruebas';
            if ($('waWindowAlertText')) $('waWindowAlertText').textContent = 'La recepción ya está integrada. El envío desde S.I.G.O.I. se activará después de completar la prueba final con Meta.';
        } else if (!windowActive) {
            $('waWindowAlert').classList.remove('hidden');
            if ($('waWindowAlertTitle')) $('waWindowAlertTitle').textContent = channel === 'instagram' ? 'Ventana de Instagram cerrada' : 'Conversación fuera de ventana';
            if ($('waWindowAlertText')) {
                const lastInbound = data.ventana_24h?.last_inbound ? formatDateTime(data.ventana_24h.last_inbound, false) : 'sin fecha disponible';
                $('waWindowAlertText').textContent = channel === 'instagram'
                    ? `Último mensaje del usuario: ${lastInbound}. El historial sigue disponible, pero esta conversación está fuera de la ventana estándar de mensajería.`
                    : `Último mensaje del paciente: ${lastInbound}. El historial no se pierde; usa una plantilla aprobada para volver a escribir.`;
            }
        } else {
            $('waWindowAlert').classList.add('hidden');
        }

        if ($('waComposerText')) $('waComposerText').disabled = !canCompose;
        if ($('waSendBtn')) $('waSendBtn').disabled = !canCompose;
        if ($('waAttachBtn')) {
            const mediaAllowed = canCompose && (channel === 'whatsapp' || !!data.can_send_media);
            $('waAttachBtn').disabled = !mediaAllowed;
            $('waAttachBtn').classList.toggle('hidden', !mediaAllowed);
        }
        if ($('waFileInput')) {
            $('waFileInput').accept = channel === 'instagram'
                ? 'image/*,video/*,audio/*'
                : 'image/jpeg,image/png,video/mp4,video/3gpp,audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt';
        }
        if ($('waQuickReplyBtn')) {
            $('waQuickReplyBtn').disabled = !canCompose;
            $('waQuickReplyBtn').classList.toggle('hidden', !canCompose);
        }
        if (!canCompose) closeQuickPicker();
        if ($('waComposerHint')) {
            if (channel === 'instagram' && !sendEnabled) $('waComposerHint').textContent = 'Recepción activa · el envío desde S.I.G.O.I. aún está desactivado para la fase de prueba.';
            else if (windowActive) $('waComposerHint').textContent = 'Enter para enviar · Shift+Enter para salto de línea · ⚡ para respuestas rápidas';
            else $('waComposerHint').textContent = channel === 'instagram' ? 'La conversación está fuera de la ventana de mensajería de Instagram.' : 'Texto libre bloqueado. Usa “Retomar conversación” con una plantilla aprobada.';
        }
        if ($('waComposerWindow')) {
            $('waComposerWindow').textContent = channel === 'instagram' && !sendEnabled
                ? 'Envío pendiente de activación'
                : (windowActive ? 'Ventana de 24 h activa' : 'Ventana de 24 h cerrada');
            $('waComposerWindow').classList.toggle('is-closed', !canCompose);
        }

        renderMessages(rows);
        if (!preserveScroll || nearBottom) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
            $('waJumpLatestBtn')?.classList.add('hidden');
        } else if (hasNewMessages) {
            $('waJumpLatestBtn')?.classList.remove('hidden');
        }
    }

    async function loadConversation(id, preserveScroll, channel) {
        if (!id) return;
        channel = channel === 'instagram' ? 'instagram' : 'whatsapp';
        if ((channel === 'instagram' && !canChannelInstagram) || (channel === 'whatsapp' && !canChannelWhatsApp)) return;
        try {
            const data = await channelApi(channel, 'conversation.php?id=' + encodeURIComponent(id));
            app.inbox.selectedId = Number(id);
            app.inbox.selectedChannel = channel;
            renderConversation(Object.assign({}, data, { canal: channel }), preserveScroll);
            renderConversationList();
        } catch (e) { toast(e.message, true); }
    }

    document.addEventListener('click', (e) => {
        const item = e.target.closest('[data-wa-conversation]');
        if (item) {
            app.inbox.selectedId = Number(item.dataset.waConversation);
            app.inbox.selectedChannel = item.dataset.waChannel === 'instagram' ? 'instagram' : 'whatsapp';
            app.inbox.lastMessageId = 0;
            renderConversationList();
            loadConversation(app.inbox.selectedId, false, app.inbox.selectedChannel);
        }
        const image = e.target.closest('[data-wa-image]');
        if (image) { $('waLightboxImage').src = image.dataset.waImage; $('waLightbox').classList.remove('hidden'); }
    });

    if ($('waJumpLatestBtn')) $('waJumpLatestBtn').addEventListener('click', () => {
        const box = $('waChatMessages');
        if (!box) return;
        box.scrollTop = box.scrollHeight;
        $('waJumpLatestBtn').classList.add('hidden');
    });
    if ($('waChatMessages')) $('waChatMessages').addEventListener('scroll', () => {
        const box = $('waChatMessages');
        const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 100;
        if (nearBottom) $('waJumpLatestBtn')?.classList.add('hidden');
    });
    if ($('waManualRefreshBtn')) $('waManualRefreshBtn').addEventListener('click', async () => {
        if (app.inbox.pollingBusy) return;
        app.inbox.pollingBusy = true;
        try { await loadInbox(true); } finally { app.inbox.pollingBusy = false; }
    });

    let searchTimer;
    $('waInboxSearch')?.addEventListener('input', (e) => {
        app.inbox.query = e.target.value.trim(); clearTimeout(searchTimer); searchTimer = setTimeout(() => loadInbox(false), 280);
    });
    $('waInboxFilters')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-wa-filter]'); if (!btn) return;
        app.inbox.filter = btn.dataset.waFilter;
        $('waInboxFilters').querySelectorAll('button').forEach(b => b.classList.toggle('is-active', b === btn));
        loadInbox(false);
    });
    $('waInboxChannels')?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-wa-channel]'); if (!btn) return;
        const requestedChannel = ['whatsapp', 'instagram'].includes(btn.dataset.waChannel) ? btn.dataset.waChannel : 'all';
        if (requestedChannel === 'whatsapp' && !canChannelWhatsApp) return;
        if (requestedChannel === 'instagram' && !canChannelInstagram) return;
        if (requestedChannel === 'all' && !(canChannelWhatsApp && canChannelInstagram)) return;
        app.inbox.channel = requestedChannel;
        $('waInboxChannels').querySelectorAll('button').forEach(b => b.classList.toggle('is-active', b === btn));
        app.inbox.selectedId = null;
        app.inbox.lastMessageId = 0;
        loadInbox(false);
    });

    async function conversationAction(action, extra) {
        if (!app.inbox.selectedId) return;
        const channel = app.inbox.selectedChannel || 'whatsapp';
        try {
            const data = await channelPost(channel, 'conversation-update.php', Object.assign({ id: app.inbox.selectedId, action }, extra || {}));
            toast(data.message);
            await loadInbox(true);
            await loadConversation(app.inbox.selectedId, true, channel);
        } catch (e) { toast(e.message, true); }
    }

    if ($('waResolveBtn')) $('waResolveBtn').addEventListener('click', () => {
        const c = app.inbox.selected?.conversacion; if (!c) return;
        conversationAction(c.estado === 'cerrada' ? 'reopen' : 'resolve');
    });
    if ($('waMarkPendingBtn')) $('waMarkPendingBtn').addEventListener('click', () => conversationAction('pending'));
    if ($('waAssigneeSelect')) $('waAssigneeSelect').addEventListener('change', (e) => conversationAction('assign', { usuario_id: Number(e.target.value || 0) }));
    if ($('waSaveContactBtn')) $('waSaveContactBtn').addEventListener('click', async () => {
        if (!app.inbox.selectedId) return;
        const btn = $('waSaveContactBtn');
        btn.disabled = true;
        try {
            const channel = app.inbox.selectedChannel || 'whatsapp';
            const data = await channelPost(channel, 'conversation-update.php', {
                id: app.inbox.selectedId,
                action: 'contact_save',
                nombre_personalizado: $('waContactCustomName')?.value || '',
                notas_contacto: $('waContactNotes')?.value || ''
            });
            toast(data.message || 'Contacto guardado.');
            await loadInbox(true);
            await loadConversation(app.inbox.selectedId, true, channel);
        } catch (e) {
            toast(e.message, true);
        } finally {
            btn.disabled = false;
        }
    });

    function clearSelectedFile() {
        app.selectedFile = null;
        if ($('waFileInput')) $('waFileInput').value = '';
        $('waFileChip').classList.add('hidden');
    }

    if ($('waAttachBtn')) $('waAttachBtn').addEventListener('click', () => $('waFileInput').click());
    if ($('waClearFileBtn')) $('waClearFileBtn').addEventListener('click', clearSelectedFile);
    if ($('waFileInput')) $('waFileInput').addEventListener('change', (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return clearSelectedFile();
        app.selectedFile = file;
        $('waFileName').textContent = file.name;
        $('waFileSize').textContent = formatFileSize(file.size);
        $('waFileChip').classList.remove('hidden');
    });

    function resizeComposer() {
        const box = $('waComposerText'); if (!box) return;
        box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 140) + 'px';
        $('waComposerChars').textContent = box.value.length + '/4000';
    }
    if ($('waComposerText')) {
        $('waComposerText').addEventListener('input', resizeComposer);
        $('waComposerText').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendComposer(); }
        });
    }
    if ($('waSendBtn')) $('waSendBtn').addEventListener('click', sendComposer);

    async function sendComposer() {
        if (!canReply || !app.inbox.selectedId) return;
        const channel = app.inbox.selectedChannel || 'whatsapp';
        const text = $('waComposerText').value.trim();
        if (!text && !app.selectedFile) return;
        $('waSendBtn').disabled = true;
        try {
            if (app.selectedFile) {
                const form = new FormData();
                form.append('conversation_id', String(app.inbox.selectedId));
                form.append('caption', text);
                form.append('file', app.selectedFile);
                const data = channel === 'instagram'
                    ? await instagramApi('send-media.php', { method: 'POST', headers: { 'X-CSRF-Token': window.APP_CSRF_TOKEN }, body: form })
                    : await api('send-media.php', { method: 'POST', headers: { 'X-CSRF-Token': window.APP_CSRF_TOKEN }, body: form });
                toast(data.message);
                clearSelectedFile();
            } else {
                const data = await channelPost(channel, 'send-text.php', { conversation_id: app.inbox.selectedId, body: text });
                toast(data.message);
            }
            $('waComposerText').value = ''; resizeComposer();
            await loadConversation(app.inbox.selectedId, false, channel);
            await loadInbox(true);
        } catch (e) { toast(e.message, true); }
        finally {
            const sendEnabled = channel === 'whatsapp' || !!app.inbox.selected?.send_enabled;
            if ($('waSendBtn') && app.inbox.selected?.ventana_24h?.active && sendEnabled) $('waSendBtn').disabled = false;
        }
    }

    $('waLightboxClose').addEventListener('click', () => $('waLightbox').classList.add('hidden'));
    $('waLightbox').addEventListener('click', (e) => { if (e.target === $('waLightbox')) $('waLightbox').classList.add('hidden'); });

    function startPolling() {
        stopPolling();
        app.inbox.polling = setInterval(async () => {
            if (document.hidden || app.tab !== 'inbox' || app.inbox.pollingBusy) return;
            app.inbox.pollingBusy = true;
            setSyncState(true);
            try {
                await processInboxQueues();
                app.inbox.rows = await fetchInboxRows();
                renderConversationList();
                updateUnreadBadge(app.inbox.rows.reduce((sum, row) => sum + Number(row.no_leidos || 0), 0));

                const selectedStillExists = app.inbox.selectedId && app.inbox.rows.some(row =>
                    Number(row.id) === Number(app.inbox.selectedId) && conversationChannel(row) === app.inbox.selectedChannel
                );
                if (!selectedStillExists && app.inbox.rows.length) {
                    app.inbox.selectedId = Number(app.inbox.rows[0].id);
                    app.inbox.selectedChannel = conversationChannel(app.inbox.rows[0]);
                    app.inbox.lastMessageId = 0;
                }
                if (app.inbox.selectedId) await loadConversation(app.inbox.selectedId, true, app.inbox.selectedChannel);
                else showEmptyChat();
                setSyncState(false);
            } catch (e) {
                setSyncState(false, 'Reconectando...');
            } finally {
                app.inbox.pollingBusy = false;
            }
        }, 2000);
    }
    function stopPolling() { if (app.inbox.polling) clearInterval(app.inbox.polling); app.inbox.polling = null; }

    // =====================================================
    // Analítica
    // =====================================================
    function renderBars(container, rows, labelFn, valueFn) {
        if (!rows.length) { container.innerHTML = '<div class="wa-empty-mini">Sin datos todavía.</div>'; return; }
        const max = Math.max(...rows.map(valueFn), 1);
        container.innerHTML = rows.map(row => {
            const value = valueFn(row); const pct = Math.max(2, Math.round((value / max) * 100));
            return `<div class="wa-ranking-row"><div class="wa-ranking-label"><span>${escapeHtml(labelFn(row))}</span><strong>${value}</strong></div><div class="wa-ranking-track"><i style="width:${pct}%"></i></div></div>`;
        }).join('');
    }

    async function loadAnalytics() {
        try {
            const range = Number($('waAnalyticsRange').value || 30);
            const data = await api('analytics.php?range=' + range);
            const s = data.summary || {};

            $('waAnConversations').textContent = Number(s.conversaciones || 0);
            $('waAnInbound').textContent = Number(s.mensajes_entrantes || 0);
            $('waAnResponse').textContent = formatDuration(s.respuesta_humana_mediana_seg);
            $('waAnResolved').textContent = Number(s.resueltas || 0);
            $('waAnPending').textContent = Number(s.pendientes || 0);
            $('waAnOutside').textContent = Number(s.fuera_horario || 0);
            $('waAnAutomation').textContent = Number(s.cobertura_automatica_pct || 0).toFixed(1) + '%';
            $('waAnReadRate').textContent = s.tasa_lectura_pct == null ? '—' : Number(s.tasa_lectura_pct).toFixed(1) + '%';
            $('waAnPending15').textContent = Number(s.pendientes_15m || 0);
            $('waAnPending60').textContent = Number(s.pendientes_60m || 0);
            $('waAnFailed').textContent = Number(s.fallidos || 0);
            $('waAnResolution').textContent = formatDuration(s.tiempo_resolucion_promedio_seg);

            if ($('waAnContactsMix')) {
                const n = Number(s.contactos_nuevos || 0);
                const r = Number(s.contactos_recurrentes || 0);
                $('waAnContactsMix').textContent = `${n} nuevos · ${r} recurrentes`;
            }
            if ($('waAnResponseMeta')) {
                const samples = Number(s.muestras_respuesta_humana || 0);
                $('waAnResponseMeta').textContent = samples
                    ? `Promedio ${formatDuration(s.respuesta_humana_promedio_seg)} · ${samples} ${samples === 1 ? 'respuesta medida' : 'respuestas medidas'}`
                    : 'Todavía no hay respuestas humanas suficientes para medir.';
            }
            if ($('waAnResolutionMeta')) {
                $('waAnResolutionMeta').textContent = Number(s.resueltas || 0)
                    ? `Tiempo medio ${formatDuration(s.tiempo_resolucion_promedio_seg)}`
                    : 'Todavía no hay cierres en este periodo.';
            }
            if ($('waAnPendingMeta')) {
                $('waAnPendingMeta').textContent = Number(s.pendientes_60m || 0)
                    ? `${Number(s.pendientes_60m)} llevan más de 1 hora esperando.`
                    : 'Sin casos con más de 1 hora de espera.';
            }

            const pending60 = Number(s.pendientes_60m || 0);
            const pending15 = Number(s.pendientes_15m || 0);
            const failed = Number(s.fallidos || 0);
            if ($('waAttentionStatus')) {
                const needsAttention = pending60 > 0 || failed > 0;
                $('waAttentionStatus').textContent = needsAttention ? 'Requiere revisión' : (pending15 > 0 ? 'Seguimiento recomendado' : 'Sin alertas críticas');
                $('waAttentionStatus').classList.toggle('is-warning', needsAttention || pending15 > 0);
            }

            const hours = data.hours || [];
            const maxHour = Math.max(...hours.map(r => Number(r.total || 0)), 1);
            const peak = hours.reduce((best, row) => Number(row.total || 0) > Number(best.total || 0) ? row : best, { hora: 0, total: 0 });
            $('waHourChart').innerHTML = hours.map(r => {
                const total = Number(r.total || 0);
                const label = `${String(r.hora).padStart(2,'0')}:00 · ${total} ${total === 1 ? 'mensaje' : 'mensajes'}`;
                return `<div class="wa-hour-col" data-label="${escapeHtml(label)}"><i style="height:${total ? Math.max(4, Math.round((total/maxHour)*100)) : 2}%"></i><span>${Number(r.hora) % 3 === 0 ? String(r.hora).padStart(2,'0') : ''}</span></div>`;
            }).join('');
            $('waHourSummary').textContent = Number(peak.total || 0) ? `Pico ${String(peak.hora).padStart(2,'0')}:00 · ${Number(peak.total)}` : 'Sin mensajes';

            renderBars($('waTopRules'), data.top_rules || [], r => r.nombre, r => Number(r.total || 0));
            renderBars($('waAgentRanking'), data.agents || [], r => r.nombre, r => Number(r.total || 0));
            renderBars($('waSourceRanking'), data.sources || [], r => r.nombre, r => Number(r.total || 0));

            const daily = data.daily || [];
            const maxDay = Math.max(...daily.map(r => Number(r.entrantes || 0) + Number(r.salientes || 0)), 1);
            let dailyIn = 0, dailyOut = 0;
            $('waDailyChart').innerHTML = daily.length ? daily.map(r => {
                const incoming = Number(r.entrantes || 0);
                const outgoing = Number(r.salientes || 0);
                dailyIn += incoming; dailyOut += outgoing;
                const total = incoming + outgoing;
                return `<div class="wa-day-col" title="${escapeHtml(r.fecha)} · ${incoming} recibidos · ${outgoing} enviados"><div class="wa-day-total">${total || ''}</div><div class="wa-day-stack" style="height:${total ? Math.max(6, Math.round((total/maxDay)*100)) : 2}%"><i class="is-in" style="height:${total ? Math.round((incoming/total)*100) : 0}%"></i><i class="is-out" style="height:${total ? Math.round((outgoing/total)*100) : 0}%"></i></div><span>${escapeHtml(String(r.fecha).slice(5).replace('-', '/'))}</span></div>`;
            }).join('') : '<div class="wa-empty-mini">Sin datos todavía.</div>';
            $('waDailySummary').textContent = `${dailyIn} recibidos · ${dailyOut} enviados`;

            const parts = [];
            const conv = Number(s.conversaciones || 0);
            const inbound = Number(s.mensajes_entrantes || 0);
            parts.push(`Hubo ${conv} conversaciones activas y ${inbound} mensajes recibidos.`);
            if (Number(peak.total || 0) > 0) parts.push(`La hora con más demanda fue alrededor de las ${String(peak.hora).padStart(2,'0')}:00.`);
            if (s.respuesta_humana_mediana_seg != null) parts.push(`La respuesta humana típica fue de ${formatDuration(s.respuesta_humana_mediana_seg)}.`);
            if (pending60 > 0) parts.push(`${pending60} ${pending60 === 1 ? 'caso lleva' : 'casos llevan'} más de una hora pendiente.`);
            else if (pending15 > 0) parts.push(`${pending15} ${pending15 === 1 ? 'caso supera' : 'casos superan'} 15 minutos de espera.`);
            else parts.push('No hay esperas prolongadas pendientes en este momento.');
            if (Number(s.cobertura_automatica_pct || 0) > 0) parts.push(`La automatización participó en el ${Number(s.cobertura_automatica_pct).toFixed(1)}% de las conversaciones.`);
            $('waAnalyticsInsight').textContent = parts.join(' ');

            app.analyticsLoaded = true;
        } catch (e) { toast(e.message, true); }
    }
    $('waAnalyticsRange')?.addEventListener('change', () => { app.analyticsLoaded = false; loadAnalytics(); });


    // =====================================================
    // Plantillas oficiales + respuestas rápidas
    // =====================================================
    function templateStatusLabel(status) {
        const map = {
            APPROVED: 'Aprobada', PENDING: 'Pendiente', REJECTED: 'Rechazada',
            PAUSED: 'Pausada', DISABLED: 'Deshabilitada', ARCHIVED: 'Archivada',
            IN_APPEAL: 'En apelación', DELETED: 'Eliminada'
        };
        return map[String(status || '').toUpperCase()] || (status || 'Sin estado');
    }

    function templateCategoryLabel(category) {
        const map = { UTILITY: 'Utilidad', MARKETING: 'Marketing', AUTHENTICATION: 'Autenticación' };
        return map[String(category || '').toUpperCase()] || category || '—';
    }

    function templatePreview(body, values) {
        let output = String(body || '');
        (values || []).forEach((value, i) => {
            const safe = String(value || `{{${i + 1}}}`);
            output = output.replace(new RegExp('\\{\\{\\s*' + (i + 1) + '\\s*\\}\\}', 'g'), safe);
        });
        return output;
    }

    async function loadTemplates(force) {
        if (app.templates.busy) return;
        if (app.templates.loaded && !force) {
            renderOfficialTemplates();
            return;
        }
        app.templates.busy = true;
        if ($('waOfficialTemplateList')) $('waOfficialTemplateList').innerHTML = '<div class="wa-empty-mini">Consultando WhatsApp...</div>';
        try {
            const data = await api('templates.php');
            app.templates.rows = data.plantillas || [];
            app.templates.loaded = true;
            renderOfficialTemplates();
            renderSendTemplatePicker();
        } catch (e) {
            if ($('waOfficialTemplateList')) $('waOfficialTemplateList').innerHTML = `<div class="wa-library-error"><strong>No se pudieron cargar las plantillas.</strong><span>${escapeHtml(e.message)}</span></div>`;
            if (force) toast(e.message, true);
        } finally {
            app.templates.busy = false;
        }
    }

    function renderOfficialTemplates() {
        const rows = app.templates.rows || [];
        if ($('waTplApproved')) $('waTplApproved').textContent = rows.filter(r => String(r.status).toUpperCase() === 'APPROVED').length;
        if ($('waTplPending')) $('waTplPending').textContent = rows.filter(r => String(r.status).toUpperCase() === 'PENDING').length;
        if ($('waTplRejected')) $('waTplRejected').textContent = rows.filter(r => String(r.status).toUpperCase() === 'REJECTED').length;
        const list = $('waOfficialTemplateList');
        if (!list) return;

        const visibleRows = rows.filter(r => String(r.status || '').toUpperCase() !== 'DELETED');
        if (!visibleRows.length) {
            list.innerHTML = '<div class="wa-library-empty"><strong>Aún no hay plantillas activas.</strong><span>Crea la primera y S.I.G.O.I. la enviará a revisión.</span></div>';
            return;
        }

        list.innerHTML = visibleRows.map(row => {
            const status = String(row.status || '').toUpperCase();
            const vars = Number(row.variable_count || 0);
            const automaticCount = Object.keys(row.automatic_variables || {}).length;
            const editable = row.editable === true || (['APPROVED', 'REJECTED', 'PAUSED'].includes(status) && row.send_supported !== false);
            const deletable = row.deletable !== false && status !== 'DELETED';
            const editLabel = status === 'REJECTED' ? 'Corregir' : 'Editar';
            const pendingHelp = status === 'PENDING'
                ? '<div class="wa-template-pending-note">En revisión: Meta todavía no permite editarla. Puedes esperar el resultado para conservar el mismo nombre.</div>'
                : '';
            const actions = canLibraryManage && (editable || deletable)
                ? `<div class="wa-template-admin-actions">${editable ? `<button type="button" data-wa-edit-template="${escapeHtml(row.name)}" data-wa-template-language="${escapeHtml(row.language || '')}">${editLabel}</button>` : ''}${deletable ? `<button type="button" class="is-danger" data-wa-delete-template="${escapeHtml(row.name)}" data-wa-template-language="${escapeHtml(row.language || '')}">Eliminar</button>` : ''}</div>`
                : '';

            return `<article class="wa-official-template-item">
                <div class="wa-template-item-top">
                    <div><strong>${escapeHtml(row.name)}</strong><span>${escapeHtml(templateCategoryLabel(row.category))} · ${escapeHtml(row.language || '—')}</span></div>
                    <div class="wa-template-item-status-actions"><span class="wa-template-status is-${escapeHtml(status.toLowerCase())}">${escapeHtml(templateStatusLabel(status))}</span>${actions}</div>
                </div>
                <p>${escapeHtml(row.body || 'Sin cuerpo disponible').replace(/\n/g, '<br>')}</p>
                <div class="wa-template-item-meta"><span>${vars ? `${vars} ${vars === 1 ? 'variable' : 'variables'}` : 'Sin variables'}</span>${automaticCount ? `<span>${automaticCount === 1 ? 'Saludo automático activo' : `${automaticCount} variables automáticas`}</span>` : ''}${row.footer ? `<span>Pie: ${escapeHtml(row.footer)}</span>` : ''}${row.send_supported === false ? '<span class="is-limited">Uso avanzado</span>' : ''}</div>
                ${pendingHelp}
                ${status === 'REJECTED' && row.rejected_reason ? `<div class="wa-template-rejection">${escapeHtml(row.rejected_reason)}</div>` : ''}
                ${row.send_supported === false && row.unsupported_reason ? `<div class="wa-template-limit-note">${escapeHtml(row.unsupported_reason)}. Puedes verla aquí, pero el botón “Retomar conversación” solo muestra plantillas de texto compatibles.</div>` : ''}
            </article>`;
        }).join('');
    }

    async function loadQuickReplies(force) {
        if (app.quickReplies.loaded && !force) {
            renderQuickAdmin();
            renderQuickPicker();
            return;
        }
        try {
            const data = await api('quick-replies.php');
            app.quickReplies.rows = data.respuestas || [];
            app.quickReplies.loaded = true;
            renderQuickAdmin();
            renderQuickPicker();
        } catch (e) {
            if ($('waQuickAdminList')) $('waQuickAdminList').innerHTML = `<div class="wa-library-error"><strong>No se pudieron cargar las respuestas.</strong><span>${escapeHtml(e.message)}</span></div>`;
        }
    }

    async function loadMessageLibrary() {
        await Promise.all([loadTemplates(true), loadQuickReplies(true)]);
    }

    function renderQuickAdmin() {
        const list = $('waQuickAdminList');
        const rows = app.quickReplies.rows || [];
        if ($('waQuickCount')) $('waQuickCount').textContent = rows.filter(r => Number(r.activa) === 1).length;
        if (!list) return;
        if (!rows.length) {
            list.innerHTML = '<div class="wa-library-empty"><strong>Aún no hay respuestas rápidas.</strong><span>Crea textos frecuentes para que el equipo responda con menos pasos.</span></div>';
            return;
        }
        list.innerHTML = rows.map(row => `<article class="wa-quick-admin-item ${Number(row.activa) === 1 ? '' : 'is-off'}">
            <div class="wa-quick-admin-top">
                <div><span class="wa-quick-category">${escapeHtml(row.categoria || 'General')}</span><strong>${escapeHtml(row.titulo)}</strong><code>/${escapeHtml(row.atajo)}</code></div>
                ${canLibraryManage ? `<div class="wa-quick-admin-actions"><button type="button" data-wa-edit-quick="${Number(row.id)}">Editar</button><button type="button" class="is-danger" data-wa-delete-quick="${Number(row.id)}">Eliminar</button></div>` : ''}
            </div>
            <p>${escapeHtml(row.contenido).replace(/\n/g, '<br>')}</p>
            ${hasQuickGreetingToken(row.contenido) ? '<span class="wa-quick-dynamic-badge">Saludo automático · Lima</span>' : ''}
            ${Number(row.activa) !== 1 ? '<span class="wa-quick-off-label">No disponible en el chat</span>' : ''}
        </article>`).join('');
    }

    function filteredQuickReplies() {
        const q = String($('waQuickPickerSearch')?.value || '').trim().toLowerCase().replace(/^\//, '');
        return (app.quickReplies.rows || []).filter(row => Number(row.activa) === 1).filter(row => {
            if (!q) return true;
            return [row.titulo, row.atajo, row.categoria, row.contenido].some(v => String(v || '').toLowerCase().includes(q));
        });
    }

    function renderQuickPicker() {
        const list = $('waQuickPickerList');
        if (!list) return;
        const rows = filteredQuickReplies();
        if (!rows.length) {
            list.innerHTML = '<div class="wa-empty-mini">No hay respuestas que coincidan.</div>';
            return;
        }
        let currentCategory = null;
        let html = '';
        rows.forEach(row => {
            const cat = row.categoria || 'General';
            if (cat !== currentCategory) {
                html += `<div class="wa-quick-picker-category">${escapeHtml(cat)}</div>`;
                currentCategory = cat;
            }
            const previewContent = resolveQuickReplyDynamicText(row.contenido);
            html += `<button type="button" class="wa-quick-picker-item" data-wa-use-quick="${Number(row.id)}"><div><strong>${escapeHtml(row.titulo)}</strong><code>/${escapeHtml(row.atajo)}</code></div><span>${escapeHtml(previewContent)}</span>${hasQuickGreetingToken(row.contenido) ? '<small class="wa-quick-picker-dynamic">Saludo automático</small>' : ''}</button>`;
        });
        list.innerHTML = html;
    }

    function openQuickPicker() {
        if (!app.inbox.selected?.ventana_24h?.active) return;
        loadQuickReplies(false);
        $('waQuickPicker')?.classList.remove('hidden');
        if ($('waQuickPickerSearch')) {
            $('waQuickPickerSearch').value = '';
            renderQuickPicker();
            setTimeout(() => $('waQuickPickerSearch')?.focus(), 20);
        }
    }

    function closeQuickPicker() {
        $('waQuickPicker')?.classList.add('hidden');
    }

    $('waQuickReplyBtn')?.addEventListener('click', () => {
        if ($('waQuickPicker')?.classList.contains('hidden')) openQuickPicker();
        else closeQuickPicker();
    });
    $('waQuickPickerClose')?.addEventListener('click', closeQuickPicker);
    $('waQuickPickerSearch')?.addEventListener('input', renderQuickPicker);

    document.addEventListener('click', (e) => {
        const use = e.target.closest('[data-wa-use-quick]');
        if (use) {
            const row = (app.quickReplies.rows || []).find(r => Number(r.id) === Number(use.dataset.waUseQuick));
            if (row && $('waComposerText')) {
                $('waComposerText').value = resolveQuickReplyDynamicText(row.contenido);
                resizeComposer();
                closeQuickPicker();
                $('waComposerText').focus();
            }
        }
    });

    $('waQuickInsertGreeting')?.addEventListener('click', () => {
        const textarea = $('waQuickContent');
        if (!textarea) return;
        const token = '{{saludo}}';
        const start = Number.isInteger(textarea.selectionStart) ? textarea.selectionStart : textarea.value.length;
        const end = Number.isInteger(textarea.selectionEnd) ? textarea.selectionEnd : start;
        const before = textarea.value.slice(0, start);
        const after = textarea.value.slice(end);
        textarea.value = before + token + after;
        const cursor = start + token.length;
        textarea.focus();
        textarea.setSelectionRange(cursor, cursor);
    });

    function openQuickEdit(row) {
        if (!canLibraryManage || !$('waQuickEditModal')) return;
        $('waQuickId').value = row ? String(row.id) : '';
        $('waQuickTitle').value = row?.titulo || '';
        $('waQuickShortcut').value = row?.atajo || '';
        $('waQuickCategory').value = row?.categoria || 'General';
        $('waQuickContent').value = row?.contenido || '';
        $('waQuickActive').checked = row ? Number(row.activa) === 1 : true;
        $('waQuickEditTitle').textContent = row ? 'Editar respuesta rápida' : 'Nueva respuesta rápida';
        $('waQuickEditModal').classList.remove('hidden');
        setTimeout(() => $('waQuickTitle')?.focus(), 20);
    }
    function closeQuickEdit() { $('waQuickEditModal')?.classList.add('hidden'); }
    $('waNewQuickBtn')?.addEventListener('click', () => openQuickEdit(null));
    document.querySelectorAll('[data-wa-close-quick]').forEach(el => el.addEventListener('click', closeQuickEdit));
    $('waQuickEditForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submit = e.submitter;
        if (submit) submit.disabled = true;
        try {
            const data = await post('quick-replies.php', {
                action: 'save',
                id: Number($('waQuickId').value || 0),
                titulo: $('waQuickTitle').value,
                atajo: $('waQuickShortcut').value,
                categoria: $('waQuickCategory').value,
                contenido: $('waQuickContent').value,
                activa: $('waQuickActive').checked
            });
            toast(data.message || 'Respuesta guardada.');
            closeQuickEdit();
            app.quickReplies.loaded = false;
            await loadQuickReplies(true);
        } catch (err) { toast(err.message, true); }
        finally { if (submit) submit.disabled = false; }
    });

    document.addEventListener('click', async (e) => {
        const edit = e.target.closest('[data-wa-edit-quick]');
        if (edit) {
            const row = (app.quickReplies.rows || []).find(r => Number(r.id) === Number(edit.dataset.waEditQuick));
            if (row) openQuickEdit(row);
        }
        const del = e.target.closest('[data-wa-delete-quick]');
        if (del) {
            const row = (app.quickReplies.rows || []).find(r => Number(r.id) === Number(del.dataset.waDeleteQuick));
            if (!row) return;
            if (!window.confirm(`¿Eliminar la respuesta rápida “${row.titulo}”?`)) return;
            try {
                const data = await post('quick-replies.php', { action: 'delete', id: Number(row.id) });
                toast(data.message || 'Respuesta eliminada.');
                app.quickReplies.loaded = false;
                await loadQuickReplies(true);
            } catch (err) { toast(err.message, true); }
        }
    });

    function templateNumbers(text) {
        const found = [];
        const re = /\{\{\s*(\d+)\s*\}\}/g;
        let m;
        while ((m = re.exec(String(text || ''))) !== null) {
            const n = Number(m[1]);
            if (n > 0 && !found.includes(n)) found.push(n);
        }
        return found.sort((a,b) => a-b);
    }

    function templateAutomaticRule(row, number) {
        if (!row || !row.automatic_variables) return null;
        return row.automatic_variables[String(number)] || row.automatic_variables[number] || null;
    }

    function templateVariableMode(row, number) {
        const raw = row?.variable_modes?.[String(number)] ?? row?.variable_modes?.[number];
        if (raw === 'greeting') return 'greeting';
        if (templateAutomaticRule(row, number)?.type === 'greeting') return 'greeting';
        return 'manual';
    }

    function applyTemplateVariableMode(number) {
        const select = document.querySelector(`[data-wa-tpl-mode="${number}"]`);
        const input = document.querySelector(`[data-wa-tpl-example="${number}"]`);
        const help = document.querySelector(`[data-wa-tpl-mode-help="${number}"]`);
        if (!select || !input) return;

        const greeting = select.value === 'greeting';
        input.readOnly = greeting;
        if (greeting) {
            input.value = 'Buenos días';
            input.placeholder = 'Buenos días';
            if (help) help.textContent = '“Buenos días” es solo el ejemplo para Meta. Al enviar, S.I.G.O.I. calculará Buenos días, Buenas tardes o Buenas noches según America/Lima.';
        } else {
            input.placeholder = number === 1 ? 'Gabriel' : 'Ej. Cardiología';
            if (help) help.textContent = 'Manual: este valor se completará al momento de enviar la plantilla.';
        }
        updateCreateTemplateVisualOnly();
    }

    function updateCreateTemplatePreview() {
        const body = $('waTplBody')?.value || '';
        const previous = {};
        const previousModes = {};
        document.querySelectorAll('[data-wa-tpl-example]').forEach(input => previous[input.dataset.waTplExample] = input.value);
        document.querySelectorAll('[data-wa-tpl-mode]').forEach(select => previousModes[select.dataset.waTplMode] = select.value);
        const nums = templateNumbers(body);
        const examples = $('waTplExamples');
        if (examples) {
            if (!nums.length) {
                examples.innerHTML = '<div class="wa-empty-mini">Agrega variables al mensaje para completar ejemplos.</div>';
            } else {
                examples.innerHTML = `<div class="wa-template-examples-title"><strong>Variables y ejemplos para aprobación</strong><span>Decide si cada variable será manual o si S.I.G.O.I. la completará automáticamente.</span></div>` + nums.map(n => {
                    const automatic = templateAutomaticRule(app.templates.editing, n);
                    const savedMode = previousModes[String(n)] || templateVariableMode(app.templates.editing, n);
                    const mode = savedMode === 'greeting' ? 'greeting' : 'manual';
                    const currentValue = previous[String(n)] || (app.templates.editing?.examples?.[n - 1] || '');
                    const value = mode === 'greeting' ? 'Buenos días' : currentValue;
                    const readonly = mode === 'greeting' ? ' readonly' : '';
                    const help = mode === 'greeting'
                        ? '“Buenos días” es solo el ejemplo para Meta. Al enviar, S.I.G.O.I. calculará el saludo según la hora de Lima.'
                        : 'Manual: este valor se completará al momento de enviar la plantilla.';
                    return `<div class="wa-field">
                        <span>{{${n}}} · Comportamiento en S.I.G.O.I.</span>
                        <select data-wa-tpl-mode="${n}">
                            <option value="manual"${mode === 'manual' ? ' selected' : ''}>Valor manual</option>
                            <option value="greeting"${mode === 'greeting' ? ' selected' : ''}>Saludo automático · Lima</option>
                        </select>
                        <span>Ejemplo para aprobación de {{${n}}}</span>
                        <input data-wa-tpl-example="${n}" maxlength="120" value="${escapeHtml(value)}" placeholder="${escapeHtml(mode === 'greeting' ? 'Buenos días' : (n === 1 ? 'Gabriel' : 'Ej. Cardiología'))}"${readonly}>
                        <small data-wa-tpl-mode-help="${n}">${escapeHtml(help)}</small>
                    </div>`;
                }).join('');
                examples.querySelectorAll('[data-wa-tpl-example]').forEach(input => input.addEventListener('input', updateCreateTemplateVisualOnly));
                examples.querySelectorAll('[data-wa-tpl-mode]').forEach(select => select.addEventListener('change', () => applyTemplateVariableMode(Number(select.dataset.waTplMode))));
            }
        }
        updateCreateTemplateVisualOnly();
        updateTemplateCategoryHelp();
    }

    function updateCreateTemplateVisualOnly() {
        const body = $('waTplBody')?.value || '';
        const footer = $('waTplFooter')?.value || '';
        const nums = templateNumbers(body);
        const values = nums.map(n => document.querySelector(`[data-wa-tpl-example="${n}"]`)?.value || `{{${n}}}`);
        let preview = templatePreview(body, values);
        if (footer) preview += `\n\n${footer}`;
        if ($('waTplPreview')) $('waTplPreview').textContent = preview || 'Escribe el mensaje para ver una vista previa.';
    }

    function updateTemplateCategoryHelp() {
        const cat = $('waTplCategory')?.value || 'UTILITY';
        const box = $('waTplCategoryHelp');
        if (!box) return;
        if (cat === 'MARKETING') box.innerHTML = '<strong>Marketing</strong><span>Para promociones, invitaciones o mensajes que no califican como una gestión solicitada por el paciente.</span>';
        else box.innerHTML = '<strong>Utilidad</strong><span>Para seguimiento de citas, solicitudes, recordatorios o gestiones que el paciente ya inició.</span>';
    }

    function setTemplateIdentityLocked(locked) {
        ['waTplName', 'waTplCategory', 'waTplLanguage'].forEach(id => {
            const field = $(id);
            if (field) field.disabled = !!locked;
        });
        if ($('waTplNameHelp')) {
            $('waTplNameHelp').textContent = locked
                ? 'Nombre, categoría e idioma no se pueden modificar en una plantilla existente. Si necesitas cambiar alguno, crea una plantilla nueva.'
                : 'Minúsculas, números y guion bajo. S.I.G.O.I. lo normaliza automáticamente.';
        }
    }

    function openCreateTemplate() {
        if (!canLibraryManage || !$('waCreateTemplateModal')) return;
        app.templates.editing = null;
        $('waCreateTemplateForm').reset();
        $('waTplMode').value = 'create';
        setTemplateIdentityLocked(false);
        $('waTplCategory').value = 'UTILITY';
        $('waTplLanguage').value = 'es';
        $('waTplModalTitle').textContent = 'Nueva plantilla WhatsApp';
        $('waTplModalDescription').textContent = 'Se enviará a Meta/WhatsApp para revisión.';
        $('waTplCreateSubmit').textContent = 'Enviar a revisión';
        $('waTplEditNote')?.classList.add('hidden');
        if ($('waTplEditNote')) $('waTplEditNote').textContent = '';
        $('waTplExamples').innerHTML = '<div class="wa-empty-mini">Agrega variables al mensaje para completar ejemplos.</div>';
        updateCreateTemplatePreview();
        $('waCreateTemplateModal').classList.remove('hidden');
        setTimeout(() => $('waTplName')?.focus(), 20);
    }

    function openEditTemplate(row) {
        if (!canLibraryManage || !row || !$('waCreateTemplateModal')) return;
        const status = String(row.status || '').toUpperCase();
        const editable = row.editable === true || (['APPROVED', 'REJECTED', 'PAUSED'].includes(status) && row.send_supported !== false);
        if (!editable) return toast(row.edit_block_reason || 'Esta plantilla todavía no puede editarse.', true);

        app.templates.editing = row;
        $('waCreateTemplateForm').reset();
        $('waTplMode').value = 'edit';
        $('waTplName').value = row.name || '';
        $('waTplCategory').value = String(row.category || 'UTILITY').toUpperCase();
        $('waTplLanguage').value = row.language || 'es';
        $('waTplBody').value = row.body || '';
        $('waTplFooter').value = row.footer || '';
        setTemplateIdentityLocked(true);
        $('waTplModalTitle').textContent = status === 'REJECTED' ? 'Corregir plantilla WhatsApp' : 'Editar plantilla WhatsApp';
        $('waTplModalDescription').textContent = 'Al guardar, WhatsApp reemplaza el contenido anterior de esta plantilla y puede volver a revisarlo.';
        $('waTplCreateSubmit').textContent = 'Guardar cambios';

        const automaticNumbers = Object.keys(row.automatic_variables || {});
        if (automaticNumbers.length) {
            $('waTplEditNote').innerHTML = `<strong>Variables automáticas activas</strong><span>${automaticNumbers.map(n => `{{${escapeHtml(n)}}} = Saludo automático`).join(' · ')}. Puedes cambiar el comportamiento de cada variable en la sección de ejemplos.</span>`;
            $('waTplEditNote').classList.remove('hidden');
        } else {
            $('waTplEditNote').classList.add('hidden');
            $('waTplEditNote').textContent = '';
        }

        $('waTplExamples').innerHTML = '<div class="wa-empty-mini">Preparando ejemplos...</div>';
        updateCreateTemplatePreview();
        (row.examples || []).forEach((value, index) => {
            const number = index + 1;
            const input = document.querySelector(`[data-wa-tpl-example="${number}"]`);
            const mode = document.querySelector(`[data-wa-tpl-mode="${number}"]`)?.value || 'manual';
            if (input) input.value = mode === 'greeting' ? 'Buenos días' : (value || '');
        });
        updateCreateTemplateVisualOnly();
        $('waCreateTemplateModal').classList.remove('hidden');
        setTimeout(() => $('waTplBody')?.focus(), 20);
    }

    function closeCreateTemplate() {
        $('waCreateTemplateModal')?.classList.add('hidden');
        app.templates.editing = null;
    }
    $('waNewTemplateBtn')?.addEventListener('click', openCreateTemplate);
    document.querySelectorAll('[data-wa-close-template-create]').forEach(el => el.addEventListener('click', closeCreateTemplate));
    $('waTplBody')?.addEventListener('input', updateCreateTemplatePreview);
    $('waTplFooter')?.addEventListener('input', updateCreateTemplateVisualOnly);
    $('waTplCategory')?.addEventListener('change', updateTemplateCategoryHelp);
    $('waTplName')?.addEventListener('input', (e) => {
        if ($('waTplMode')?.value === 'edit') return;
        const v = String(e.target.value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9_\s-]/g, '').replace(/[\s-]+/g, '_').replace(/_+/g, '_');
        if (v !== e.target.value) e.target.value = v;
    });
    $('waCreateTemplateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nums = templateNumbers($('waTplBody').value);
        if (nums.length && nums.some((n, i) => n !== i + 1)) return toast('Usa variables correlativas: {{1}}, {{2}}, {{3}}...', true);
        const examples = nums.map(n => document.querySelector(`[data-wa-tpl-example="${n}"]`)?.value || '');
        const variableModes = {};
        nums.forEach(n => {
            variableModes[String(n)] = document.querySelector(`[data-wa-tpl-mode="${n}"]`)?.value === 'greeting' ? 'greeting' : 'manual';
        });
        const mode = $('waTplMode')?.value === 'edit' ? 'edit' : 'create';
        const btn = $('waTplCreateSubmit');
        btn.disabled = true;
        btn.textContent = mode === 'edit' ? 'Guardando...' : 'Enviando...';
        try {
            const data = await post('templates.php', {
                action: mode,
                name: $('waTplName').value,
                category: $('waTplCategory').value,
                language: $('waTplLanguage').value,
                body: $('waTplBody').value,
                footer: $('waTplFooter').value,
                examples,
                variable_modes: variableModes
            });
            toast(data.message || (mode === 'edit' ? 'Plantilla actualizada.' : 'Plantilla enviada a revisión.'));
            closeCreateTemplate();
            app.templates.loaded = false;
            await loadTemplates(true);
        } catch (err) { toast(err.message, true); }
        finally {
            btn.disabled = false;
            btn.textContent = mode === 'edit' ? 'Guardar cambios' : 'Enviar a revisión';
        }
    });

    document.addEventListener('click', async (e) => {
        const edit = e.target.closest('[data-wa-edit-template]');
        if (edit) {
            const row = (app.templates.rows || []).find(r => String(r.name) === String(edit.dataset.waEditTemplate) && String(r.language || '') === String(edit.dataset.waTemplateLanguage || ''));
            if (row) openEditTemplate(row);
            return;
        }

        const del = e.target.closest('[data-wa-delete-template]');
        if (!del) return;
        const row = (app.templates.rows || []).find(r => String(r.name) === String(del.dataset.waDeleteTemplate) && String(r.language || '') === String(del.dataset.waTemplateLanguage || ''));
        if (!row) return;

        const status = String(row.status || '').toUpperCase();
        const extra = status === 'PENDING'
            ? '\n\nEstá pendiente de revisión. Si quieres conservar este mismo nombre, es mejor esperar a que Meta termine la revisión y luego editarla.'
            : '';
        const confirmed = window.confirm(`¿Eliminar la plantilla “${row.name}” (${row.language || 'sin idioma'})?\n\nEsta acción se envía a Meta/WhatsApp y el mismo nombre puede quedar bloqueado durante 30 días.${extra}`);
        if (!confirmed) return;

        del.disabled = true;
        try {
            const data = await post('templates.php', { action: 'delete', name: row.name, language: row.language });
            toast(data.message || 'Plantilla eliminada.');
            app.templates.loaded = false;
            await loadTemplates(true);
        } catch (err) { toast(err.message, true); }
        finally { del.disabled = false; }
    });

    $('waTemplateRefreshBtn')?.addEventListener('click', async () => {
        const btn = $('waTemplateRefreshBtn');
        btn.disabled = true;
        try { app.templates.loaded = false; await loadTemplates(true); toast('Estados de plantillas actualizados.'); }
        finally { btn.disabled = false; }
    });

    function approvedTemplates() {
        return (app.templates.rows || []).filter(r => String(r.status || '').toUpperCase() === 'APPROVED' && r.send_supported !== false);
    }

    function templateFriendlyName(name) {
        const text = String(name || '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Plantilla WhatsApp';
    }

    function templateSendKey(row) {
        return `${String(row?.name || '')}::${String(row?.language || '')}`;
    }

    function selectedSendTemplate() {
        const key = app.templates.sendSelectedKey;
        if (!key) return null;
        return approvedTemplates().find(row => templateSendKey(row) === key) || null;
    }

    function templatePickerValues(row) {
        const count = Number(row?.variable_count || 0);
        return Array.from({ length: count }, (_, i) => {
            const number = i + 1;
            const automatic = row?.automatic_variables?.[String(number)] || row?.automatic_variables?.[number] || null;
            if (automatic?.type === 'greeting') return getLimaGreeting();
            if (automatic?.value) return String(automatic.value);
            if (row?.examples?.[i]) return String(row.examples[i]);
            return `{{${number}}}`;
        });
    }

    function filteredApprovedTemplates() {
        const query = String($('waSendTemplateSearch')?.value || '').trim().toLowerCase();
        const rows = approvedTemplates();
        if (!query) return rows;
        return rows.filter(row => [
            row.name,
            templateFriendlyName(row.name),
            row.body,
            row.footer,
            templateCategoryLabel(row.category),
            row.language
        ].some(value => String(value || '').toLowerCase().includes(query)));
    }

    function renderSendTemplatePicker() {
        const list = $('waSendTemplatePickerList');
        const countBox = $('waSendTemplateCount');
        if (!list || !countBox) return;

        const allRows = approvedTemplates();
        const rows = filteredApprovedTemplates();
        countBox.textContent = allRows.length === 1 ? '1 plantilla disponible' : `${allRows.length} plantillas disponibles`;

        if (!allRows.length) {
            list.innerHTML = '<div class="wa-library-error"><strong>No hay plantillas aprobadas para enviar.</strong><span>Ve a “Plantillas y respuestas”, crea una plantilla y espera a que WhatsApp la apruebe.</span></div>';
            return;
        }
        if (!rows.length) {
            list.innerHTML = '<div class="wa-library-empty"><strong>No encontramos coincidencias.</strong><span>Prueba buscando por el nombre o por una frase del mensaje.</span></div>';
            return;
        }

        list.innerHTML = rows.map(row => {
            const vars = Number(row.variable_count || 0);
            const automaticCount = Object.keys(row.automatic_variables || {}).length;
            const values = templatePickerValues(row);
            const preview = templatePreview(row.body || '', values);
            const friendly = templateFriendlyName(row.name);
            const technical = String(row.name || '');
            return `<article class="wa-template-pick-card">
                <div class="wa-template-pick-main">
                    <div class="wa-template-pick-heading">
                        <div>
                            <strong>${escapeHtml(friendly)}</strong>
                            <code>${escapeHtml(technical)}</code>
                        </div>
                        <span class="wa-template-status is-approved">Aprobada</span>
                    </div>
                    <div class="wa-template-pick-preview">${escapeHtml(preview || 'Sin contenido disponible').replace(/\n/g, '<br>')}</div>
                    <div class="wa-template-pick-meta">
                        <span>${escapeHtml(templateCategoryLabel(row.category))}</span>
                        <span>${vars ? `${vars} ${vars === 1 ? 'variable' : 'variables'}` : 'Sin variables'}</span>
                        ${automaticCount ? `<span class="is-auto">${automaticCount === 1 ? 'Saludo automático' : `${automaticCount} automáticas`}</span>` : ''}
                    </div>
                </div>
                <button type="button" class="wa-template-pick-use" data-wa-choose-template="${escapeHtml(templateSendKey(row))}">Usar plantilla <span>→</span></button>
            </article>`;
        }).join('');
    }

    function showSendTemplatePicker(resetSearch = false) {
        app.templates.sendSelectedKey = null;
        $('waTemplatePickerStage')?.classList.remove('hidden');
        $('waTemplatePrepareStage')?.classList.add('hidden');
        $('waSendTemplateSubmit')?.classList.add('hidden');
        if ($('waSendTemplateTitle')) $('waSendTemplateTitle').textContent = 'Seleccionar plantilla';
        if ($('waSendTemplateSubtitle')) $('waSendTemplateSubtitle').textContent = 'Elige una plantilla aprobada según lo que necesites comunicar al paciente.';
        if (resetSearch && $('waSendTemplateSearch')) $('waSendTemplateSearch').value = '';
        renderSendTemplatePicker();
        if (resetSearch) setTimeout(() => $('waSendTemplateSearch')?.focus(), 20);
    }

    function showSendTemplatePreparation(row) {
        if (!row) return;
        app.templates.sendSelectedKey = templateSendKey(row);
        $('waTemplatePickerStage')?.classList.add('hidden');
        $('waTemplatePrepareStage')?.classList.remove('hidden');
        $('waSendTemplateSubmit')?.classList.remove('hidden');
        if ($('waSendTemplateTitle')) $('waSendTemplateTitle').textContent = 'Preparar mensaje';
        if ($('waSendTemplateSubtitle')) $('waSendTemplateSubtitle').textContent = 'Revisa los datos y la vista previa antes de enviarla.';
        if ($('waSendTemplateSelectedSummary')) {
            const vars = Number(row.variable_count || 0);
            $('waSendTemplateSelectedSummary').innerHTML = `<div><span>Plantilla seleccionada</span><strong>${escapeHtml(templateFriendlyName(row.name))}</strong><code>${escapeHtml(row.name)}</code></div><div class="wa-template-selected-tags"><span>${escapeHtml(templateCategoryLabel(row.category))}</span><span>${vars ? `${vars} ${vars === 1 ? 'variable' : 'variables'}` : 'Sin variables'}</span></div>`;
        }
        renderSendTemplateFields();
    }

    function renderSendTemplateFields() {
        const row = selectedSendTemplate();
        const box = $('waSendTemplateVariables');
        const preview = $('waSendTemplatePreview');
        if (!box || !preview) return;
        if (!row) {
            box.innerHTML = '<div class="wa-empty-mini">Selecciona una plantilla para continuar.</div>';
            preview.textContent = 'Selecciona una plantilla.';
            return;
        }
        const count = Number(row.variable_count || 0);
        if (!count) {
            box.innerHTML = '<div class="wa-no-template-vars"><strong>Lista para enviar</strong><span>Esta plantilla no tiene campos variables y se enviará exactamente como fue aprobada.</span></div>';
        } else {
            box.innerHTML = `<div class="wa-send-vars-head"><strong>Datos del mensaje</strong><span>Revisa los campos. Los automáticos se recalculan justo antes de enviar.</span></div>` + Array.from({length: count}, (_, i) => {
                const number = i + 1;
                const automatic = row.automatic_variables?.[String(number)] || row.automatic_variables?.[number] || null;
                if (automatic) {
                    const value = automatic.type === 'greeting' ? getLimaGreeting() : (automatic.value || '');
                    const label = automatic.label || 'Valor automático';
                    return `<label class="wa-field"><span>{{${number}}} · ${escapeHtml(label)}</span><input data-wa-send-var="${i}" maxlength="500" value="${escapeHtml(value)}" readonly><small>Automático · se vuelve a calcular según ${escapeHtml(automatic.timezone || 'America/Lima')} justo al enviar.</small></label>`;
                }
                const suggested = i === 0 ? (app.inbox.selected?.conversacion ? conversationName(app.inbox.selected.conversacion) : '') : (row.examples?.[i] || '');
                return `<label class="wa-field"><span>{{${number}}}</span><input data-wa-send-var="${i}" maxlength="500" value="${escapeHtml(suggested || '')}" placeholder="${escapeHtml(row.examples?.[i] || 'Escribe el valor')}"></label>`;
            }).join('');
            box.querySelectorAll('[data-wa-send-var]').forEach(input => input.addEventListener('input', updateSendTemplatePreview));
        }
        updateSendTemplatePreview();
    }

    function updateSendTemplatePreview() {
        const row = selectedSendTemplate();
        if (!row || !$('waSendTemplatePreview')) return;
        const count = Number(row.variable_count || 0);
        const values = Array.from({length: count}, (_, i) => document.querySelector(`[data-wa-send-var="${i}"]`)?.value || `{{${i + 1}}}`);
        let text = templatePreview(row.body || '', values);
        if (row.footer) text += `\n\n${row.footer}`;
        $('waSendTemplatePreview').textContent = text || row.name;
    }

    async function openSendTemplateModal() {
        if (!canReply || !$('waSendTemplateModal') || !app.inbox.selectedId || app.inbox.selectedChannel !== 'whatsapp') return;
        $('waSendTemplateModal').classList.remove('hidden');
        app.templates.sendSelectedKey = null;
        if ($('waSendTemplateSearch')) $('waSendTemplateSearch').value = '';
        $('waTemplatePickerStage')?.classList.remove('hidden');
        $('waTemplatePrepareStage')?.classList.add('hidden');
        $('waSendTemplateSubmit')?.classList.add('hidden');
        if ($('waSendTemplatePickerList')) $('waSendTemplatePickerList').innerHTML = '<div class="wa-empty-mini">Cargando plantillas aprobadas...</div>';
        if ($('waSendTemplateCount')) $('waSendTemplateCount').textContent = 'Consultando WhatsApp...';
        await loadTemplates(true);
        showSendTemplatePicker(false);
    }

    function closeSendTemplateModal() {
        $('waSendTemplateModal')?.classList.add('hidden');
        app.templates.sendSelectedKey = null;
    }

    $('waRetakeBtn')?.addEventListener('click', openSendTemplateModal);
    document.querySelectorAll('[data-wa-close-template-send]').forEach(el => el.addEventListener('click', closeSendTemplateModal));
    $('waSendTemplateSearch')?.addEventListener('input', renderSendTemplatePicker);
    $('waSendTemplateBack')?.addEventListener('click', () => showSendTemplatePicker(false));

    document.addEventListener('click', (e) => {
        const choose = e.target.closest('[data-wa-choose-template]');
        if (!choose) return;
        const row = approvedTemplates().find(item => templateSendKey(item) === String(choose.dataset.waChooseTemplate || ''));
        if (row) showSendTemplatePreparation(row);
    });

    $('waSendTemplateForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const row = selectedSendTemplate();
        if (!row) return toast('Selecciona una plantilla aprobada.', true);
        const values = Array.from(document.querySelectorAll('[data-wa-send-var]')).map(input => input.value.trim());
        if (values.some(v => !v)) return toast('Completa todos los campos personalizables.', true);
        const btn = $('waSendTemplateSubmit');
        btn.disabled = true;
        btn.textContent = 'Enviando...';
        try {
            const data = await post('send-template.php', {
                conversation_id: app.inbox.selectedId,
                name: row.name,
                language: row.language,
                variables: values
            });
            toast(data.message || 'Plantilla enviada.');
            closeSendTemplateModal();
            await loadConversation(app.inbox.selectedId, false, 'whatsapp');
            await loadInbox(true);
        } catch (err) { toast(err.message, true); }
        finally { btn.disabled = false; btn.textContent = 'Enviar plantilla'; }
    });

    // =====================================================
    // Inicio
    // =====================================================
    const startup = [loadStatus()];

    if (canInboxView) {
        startup.push(loadInbox(false));
    }

    Promise.all(startup).then(() => {
        if (initialTab && tabAllowed(initialTab)) {
            setTab(initialTab);
        }
    });
})();
