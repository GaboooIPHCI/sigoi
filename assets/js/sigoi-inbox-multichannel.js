(function () {
    'use strict';

    const $ = id => document.getElementById(id);
    const page = document.querySelector('.whatsapp-page');
    const list = $('waConversationList');
    if (!page || !list) return;

    const permissions = window.SIGOI_INBOX_PERMISSIONS || window.WHATSAPP_PERMISSIONS || {};
    const canInboxView = !!permissions.bandeja_ver;
    if (!canInboxView) return;

    const canReply = !!permissions.bandeja_responder;
    const canManage = !!permissions.bandeja_gestionar;
    const canWhatsApp = permissions.canal_whatsapp !== false && permissions.canal_whatsapp !== 0;
    const canInstagram = permissions.canal_instagram !== false && permissions.canal_instagram !== 0;

    const channelMeta = {
        whatsapp: { label: 'WhatsApp', short: 'WA' },
        instagram: { label: 'Instagram', short: 'IG' },
        messenger: { label: 'Messenger', short: 'M' }
    };

    const state = {
        tab: 'inbox',
        filter: 'all',
        channel: 'all',
        query: '',
        rows: [],
        selectedId: null,
        selectedChannel: '',
        selected: null,
        selectedFile: null,
        polling: null,
        busy: false,
        searchTimer: null,
        quickRows: [],
        quickLoaded: false,
        messenger: { allowed: false, configured: false, sendEnabled: false, pageId: '' },
        metaSyncInFlight: new Set(),
        metaSyncAt: new Map(),
        lastMessageId: 0,
        messageSignature: '',
        listSignature: '',
        conversationVisible: 50,
        conversationPageSize: 50,
        conversationServerPage: 1,
        conversationServerHasMore: false,
        conversationLoadingMore: false,
        messagePageSize: 60,
        messageHasMore: false,
        messageCursor: null,
        messageLoadingOlder: false,
        lastConversationRefreshAt: 0,
        conversationRefreshIntervalMs: 15000,
        pollIntervalMs: 4000,
        requestTimeoutMs: 12000
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function toast(message, error) {
        const box = $('toast');
        if (!box) return;
        box.textContent = String(message || '');
        box.className = 'toast ' + (error ? 'toast-error' : 'toast-success');
        box.classList.remove('hidden');
        clearTimeout(box._sigoiInboxTimer);
        box._sigoiInboxTimer = setTimeout(() => box.classList.add('hidden'), 3400);
    }

    async function requestJson(url, options, fallbackMessage) {
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const requestOptions = Object.assign({
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        }, options || {});

        if (controller && !requestOptions.signal) {
            requestOptions.signal = controller.signal;
        }

        const timeout = controller
            ? window.setTimeout(function () { controller.abort(); }, state.requestTimeoutMs)
            : null;

        try {
            const response = await fetch(url, requestOptions);
            let data;
            try { data = await response.json(); }
            catch (_) { throw new Error(fallbackMessage || 'El servidor devolvió una respuesta no válida.'); }
            if (!response.ok || !data.success) throw new Error(data.message || fallbackMessage || 'No se pudo completar la operación.');
            return data;
        } catch (error) {
            if (error && error.name === 'AbortError') {
                throw new Error('La consulta tardó demasiado. Intenta actualizar nuevamente.');
            }
            throw error;
        } finally {
            if (timeout) window.clearTimeout(timeout);
        }
    }

    function channelBase(channel) {
        return 'modules/' + channel + '/';
    }

    function api(channel, path, options) {
        return requestJson(channelBase(channel) + path, options, 'No se pudo consultar ' + (channelMeta[channel]?.label || 'el canal') + '.');
    }

    function post(channel, path, payload) {
        return api(channel, path, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': window.APP_CSRF_TOKEN || ''
            },
            body: JSON.stringify(payload || {})
        });
    }

    function parseDate(value) {
        if (!value) return null;
        const d = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function formatDateTime(value, compact) {
        const d = parseDate(value);
        if (!d) return '—';
        const now = new Date();
        if (compact && d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' });
        }
        return d.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: compact ? undefined : 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    }

    function formatDayLabel(value) {
        const d = parseDate(value);
        if (!d) return '';
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
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

    function formatWhatsappPhone(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (!digits) return '';
        if (digits.length === 11 && digits.startsWith('51')) {
            const local = digits.slice(2);
            return '+51 ' + local.slice(0, 3) + ' ' + local.slice(3, 6) + ' ' + local.slice(6, 9);
        }
        if (digits.length === 9 && digits.startsWith('9')) {
            return '+51 ' + digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6, 9);
        }
        return '+' + digits;
    }

    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
        return (parts.slice(0, 2).map(p => p.charAt(0)).join('') || '?').toUpperCase();
    }

    function channelAllowed(channel) {
        if (channel === 'whatsapp') return canWhatsApp;
        if (channel === 'instagram') return canInstagram;
        if (channel === 'messenger') return state.messenger.allowed;
        return false;
    }

    function allowedChannels() {
        return ['whatsapp', 'instagram', 'messenger'].filter(channelAllowed);
    }

    function rowChannel(row) {
        const value = String(row?.canal || row?.channel || 'whatsapp').toLowerCase();
        return ['whatsapp', 'instagram', 'messenger'].includes(value) ? value : 'whatsapp';
    }

    function conversationName(row) {
        const channel = rowChannel(row);
        const custom = String(row?.nombre_personalizado || '').trim();
        const contact = String(row?.nombre_contacto || '').trim();
        if (custom) return custom;
        if (contact) return contact;
        if (channel === 'whatsapp') return formatWhatsappPhone(row?.telefono) || 'WhatsApp';
        if (channel === 'instagram') {
            const username = String(row?.username_whatsapp || row?.username || '').replace(/^@+/, '');
            return username ? '@' + username : (row?.igsid ? 'Instagram ' + String(row.igsid).slice(-6) : 'Instagram');
        }
        return row?.psid ? 'Messenger ' + String(row.psid).slice(-6) : 'Messenger';
    }

    function conversationKey(channel, id) {
        return String(channel) + ':' + Number(id || 0);
    }

    function setSyncState(loading, label) {
        const line = $('waSyncState')?.closest('.wa-sync-line');
        line?.classList.toggle('is-loading', !!loading);
        const target = $('waSyncState');
        if (target) {
            if (label) target.textContent = label;
            else if (loading) target.textContent = 'Actualizando...';
            else target.textContent = 'En vivo · ' + new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        $('waManualRefreshBtn')?.classList.toggle('is-loading', !!loading);
    }

    function updateUnreadBadge() {
        const total = state.rows.reduce((sum, row) => sum + Number(row.no_leidos || 0), 0);
        const badge = $('waInboxUnreadBadge');
        if (badge) {
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.classList.toggle('hidden', total <= 0);
        }
    }

    async function refreshMessengerCapabilities() {
        try {
            const data = await api('messenger', 'capabilities.php');
            state.messenger.allowed = !!data.allowed;
            state.messenger.configured = !!data.configured;
            state.messenger.sendEnabled = !!data.send_enabled;
            state.messenger.pageId = String(data.page_id || '');
        } catch (_) {
            state.messenger.allowed = false;
            state.messenger.configured = false;
            state.messenger.sendEnabled = false;
        }
    }

    function ensureChannelButtons() {
        let row = $('waInboxChannels');
        if (!row) {
            const filters = $('waInboxFilters');
            if (!filters?.parentNode) return;
            row = document.createElement('div');
            row.id = 'waInboxChannels';
            row.className = 'wa-channel-filter-row';
            filters.parentNode.insertBefore(row, filters);
        }

        const channels = allowedChannels();
        const desired = [];
        if (channels.length > 1) desired.push('all');
        desired.push(...channels);

        row.innerHTML = desired.map(channel => {
            const label = channel === 'all' ? 'Todos' : channelMeta[channel].label;
            return '<button type="button" data-wa-channel="' + channel + '">' + escapeHtml(label) + '</button>';
        }).join('');

        if (!desired.includes(state.channel)) {
            state.channel = desired.includes('all') ? 'all' : (channels[0] || 'all');
        }
        row.querySelectorAll('[data-wa-channel]').forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.waChannel === state.channel);
        });
        row.classList.toggle('is-single-channel', channels.length <= 1);
    }

    async function fetchChannelRows(channel, pageNumber) {
        const qs = new URLSearchParams({
            q: state.query,
            filter: state.filter,
            page: String(Math.max(1, Number(pageNumber || 1))),
            per_page: String(state.conversationPageSize)
        });
        const data = await api(channel, 'inbox-list.php?' + qs.toString());
        return {
            rows: (data.conversaciones || []).map(row => Object.assign({}, row, { canal: channel })),
            hasMore: !!data.has_more
        };
    }

    function mergeConversationRows(baseRows, incomingRows) {
        const map = new Map();
        (baseRows || []).forEach(row => map.set(conversationKey(rowChannel(row), row.id), row));
        (incomingRows || []).forEach(row => map.set(conversationKey(rowChannel(row), row.id), row));
        const rows = Array.from(map.values());
        rows.sort((a, b) => {
            const at = parseDate(a.ultimo_mensaje_en || a.creado_en)?.getTime() || 0;
            const bt = parseDate(b.ultimo_mensaje_en || b.creado_en)?.getTime() || 0;
            return bt - at;
        });
        return rows;
    }

    async function fetchRows(pageNumber) {
        let channels = allowedChannels();
        if (state.channel !== 'all') channels = channels.filter(ch => ch === state.channel);
        const results = await Promise.allSettled(channels.map(channel => fetchChannelRows(channel, pageNumber)));
        let rows = [];
        let firstError = null;
        let hasMore = false;
        results.forEach(result => {
            if (result.status === 'fulfilled') {
                rows = rows.concat(result.value.rows || []);
                hasMore = hasMore || !!result.value.hasMore;
            } else if (!firstError) firstError = result.reason;
        });
        if (!rows.length && firstError && state.channel !== 'all') throw firstError;
        rows.sort((a, b) => {
            const at = parseDate(a.ultimo_mensaje_en || a.creado_en)?.getTime() || 0;
            const bt = parseDate(b.ultimo_mensaje_en || b.creado_en)?.getTime() || 0;
            return bt - at;
        });
        return { rows: rows, hasMore: hasMore };
    }

    function rowActivitySignature(row) {
        if (!row) return '';
        return [
            rowChannel(row),
            Number(row.id || 0),
            row.ultimo_mensaje_en || '',
            row.ultimo_mensaje_preview || '',
            row.ultimo_mensaje_direccion || '',
            Number(row.no_leidos || 0),
            Number(row.requiere_humano || 0),
            row.estado || '',
            row.asignado_a || '',
            row.asignado_nombre || ''
        ].join('|');
    }

    function rowsSignature(rows) {
        return (rows || []).map(rowActivitySignature).join('~');
    }

    function selectedRowSignature(rows) {
        const row = (rows || []).find(item =>
            Number(item.id) === Number(state.selectedId)
            && rowChannel(item) === state.selectedChannel
        );
        return rowActivitySignature(row);
    }

    function renderConversationList(force) {
        const visibleCount = Math.min(state.conversationVisible, state.rows.length);
        const visibleRows = state.rows.slice(0, visibleCount);
        const selectedKey = conversationKey(state.selectedChannel, state.selectedId);
        const signature = [
            rowsSignature(visibleRows),
            selectedKey,
            visibleCount,
            state.rows.length
        ].join('||');

        if (!force && signature === state.listSignature) {
            $('waInboxCount') && ($('waInboxCount').textContent = String(state.rows.length));
            return;
        }

        state.listSignature = signature;
        $('waInboxCount') && ($('waInboxCount').textContent = String(state.rows.length));

        if (!state.rows.length) {
            list.innerHTML = '<div class="wa-list-empty"><strong>No hay conversaciones</strong><span>Prueba otro filtro o espera nuevos mensajes.</span></div>';
            return;
        }

        let html = visibleRows.map(row => {
            const channel = rowChannel(row);
            const name = conversationName(row);
            const selected = conversationKey(channel, row.id) === selectedKey;
            const preview = row.match_preview || row.busqueda_coincidencia || row.ultimo_mensaje_preview || '[Mensaje]';
            const outgoing = row.ultimo_mensaje_direccion === 'saliente';
            const ad = channel === 'messenger' && (String(row.origen_fuente || '').toUpperCase() === 'ADS' || String(row.origen_ad_id || '').trim());
            return '<button type="button" class="wa-conversation-item ' + (selected ? 'is-selected ' : '') + (Number(row.no_leidos) > 0 ? 'has-unread' : '') + '" data-wa-conversation="' + Number(row.id) + '" data-wa-channel="' + channel + '">' +
                '<div class="wa-avatar wa-avatar--channel">' + escapeHtml(initials(name)) + '<span class="wa-channel-mark is-' + channel + '" title="' + escapeHtml(channelMeta[channel].label) + '">' + escapeHtml(channelMeta[channel].short) + '</span></div>' +
                '<div class="wa-conversation-copy"><div class="wa-conversation-top"><strong>' + escapeHtml(name) + '</strong><time>' + escapeHtml(formatDateTime(row.ultimo_mensaje_en, true)) + '</time></div>' +
                '<div class="wa-conversation-preview"><span>' + (outgoing ? 'Tú: ' : '') + escapeHtml(preview) + '</span>' + (Number(row.no_leidos) > 0 ? '<b>' + (Number(row.no_leidos) > 99 ? '99+' : Number(row.no_leidos)) + '</b>' : '') + '</div>' +
                '<div class="wa-conversation-flags"><span class="wa-conversation-channel is-' + channel + '">' + escapeHtml(channelMeta[channel].label) + '</span>' +
                (ad ? '<span class="is-ad-origin">Anuncio</span>' : '') +
                (Number(row.requiere_humano) === 1 ? '<span class="is-pending">Pendiente</span>' : '') +
                (row.asignado_nombre ? '<span>' + escapeHtml(row.asignado_nombre) + '</span>' : '') +
                (row.estado === 'cerrada' ? '<span class="is-resolved">Resuelta</span>' : '') + '</div></div></button>';
        }).join('');

        if (visibleCount < state.rows.length || state.conversationServerHasMore) {
            const loadedRemaining = Math.max(0, state.rows.length - visibleCount);
            const amount = Math.min(state.conversationPageSize, loadedRemaining || state.conversationPageSize);
            const verb = loadedRemaining > 0 ? 'Mostrar ' : 'Cargar ';
            html += '<div class="sigoi-inbox-load-more-wrap"><button type="button" class="sigoi-inbox-load-more" data-sigoi-load-more-conversations>' + verb +
                amount + ' conversaciones más</button><small>' + visibleCount + ' visibles · ' + state.rows.length +
                (state.conversationServerHasMore ? '+ cargadas por páginas' : ' cargadas') + '</small></div>';
        }

        list.innerHTML = html;
    }

    function showEmptyChat() {
        $('waChatEmpty')?.classList.remove('hidden');
        $('waChatView')?.classList.add('hidden');
        $('waContactEmpty')?.classList.remove('hidden');
        $('waContactView')?.classList.add('hidden');
        state.selected = null;
        state.selectedId = null;
        state.selectedChannel = '';
        state.lastMessageId = 0;
        state.messageSignature = '';
        state.messageHasMore = false;
        state.messageCursor = null;
        state.listSignature = '';
    }

    function statusLabel(status, channel) {
        const value = String(status || '').toLowerCase();
        const map = { sent: 'Enviado', accepted: 'Aceptado', delivered: 'Entregado', read: channel === 'instagram' ? 'Visto' : 'Leído', failed: 'Falló', enviado: 'Enviado', leido: channel === 'instagram' ? 'Visto' : 'Leído', error: 'Falló', recibido: 'Recibido' };
        return map[value] || String(status || '');
    }

    function renderMedia(message) {
        if (!message.media) return '';
        const url = escapeHtml(message.media.url || '');
        const filename = escapeHtml(message.media.filename || 'Archivo');
        const mime = String(message.media.mime || '');
        if (message.tipo === 'image' || mime.indexOf('image/') === 0) return '<button type="button" class="wa-media-image" data-wa-image="' + url + '"><img src="' + url + '" alt="' + filename + '" loading="lazy"></button>';
        if (message.tipo === 'video' || mime.indexOf('video/') === 0) return '<video class="wa-media-video" controls preload="metadata"><source src="' + url + '" type="' + escapeHtml(mime) + '"></video>';
        if (message.tipo === 'audio' || mime.indexOf('audio/') === 0) return '<audio class="wa-media-audio" controls preload="metadata"><source src="' + url + '" type="' + escapeHtml(mime) + '"></audio>';
        return '<a class="wa-document-card" href="' + url + '" target="_blank" rel="noopener"><span class="wa-document-icon">DOC</span><span><strong>' + filename + '</strong><small>' + escapeHtml(formatFileSize(message.media.size)) + (mime ? ' · ' + escapeHtml(mime) : '') + '</small></span><b>↗</b></a>';
    }

    function originLabel(message, channel) {
        if (message.origen_label) return message.origen_label;
        if (message.origen === 'automatizacion') return 'Automático';
        if (message.origen === 'meta_sync') return 'Meta / Página';
        if (message.origen === 'messenger_app') return 'Messenger';
        if (message.origen === 'instagram_app') return 'Instagram';
        if (message.origen === 'whatsapp_app') return 'WhatsApp';
        if (message.origen === 'ycloud') return 'YCloud';
        return 'S.I.G.O.I.';
    }

    function renderMessage(message, channel) {
        const incoming = message.direccion === 'entrante';
        const auto = message.origen === 'automatizacion';
        const appOrigin = ['whatsapp_app', 'messenger_app'].includes(message.origen);
        const provider = ['ycloud', 'meta_sync'].includes(message.origen);
        const social = ['instagram_app', 'messenger_app', 'meta_sync'].includes(message.origen);
        const body = String(message.contenido || '').replace(/^\[(Imagen|Video|Audio|Documento)\]\s*/i, '').trim();
        const status = incoming ? '' : statusLabel(message.estado_envio, channel);
        return '<div class="wa-message-row ' + (incoming ? 'is-incoming' : 'is-outgoing') + '"><article class="wa-message-bubble ' + (auto ? 'is-auto ' : '') + (appOrigin ? 'is-app ' : '') + (provider ? 'is-ycloud ' : '') + (social ? 'is-' + channel : '') + '">' +
            (!incoming ? '<div class="wa-message-origin">' + escapeHtml(originLabel(message, channel)) + (message.plantilla ? '<span class="wa-message-template-tag">Plantilla WhatsApp · ' + escapeHtml(message.plantilla.name || '') + '</span>' : '') + '</div>' : '') +
            renderMedia(message) +
            (body ? '<div class="wa-message-text">' + escapeHtml(body).replace(/\n/g, '<br>') + '</div>' : '') +
            '<div class="wa-message-meta"><time>' + escapeHtml(formatDateTime(message.creado_en, true)) + '</time>' + (message.editado_veces ? '<span>Editado</span>' : '') + (message.reaccion_emoji ? '<span>' + escapeHtml(message.reaccion_emoji) + '</span>' : '') + (message.fuera_horario ? '<span title="Fuera de horario">🌙</span>' : '') + (status ? '<span class="wa-message-status">' + escapeHtml(status) + '</span>' : '') + '</div>' +
            (message.error_envio ? '<div class="wa-message-error">' + escapeHtml(message.error_envio) + '</div>' : '') + '</article></div>';
    }

    function renderMessages(messages, channel) {
        const box = $('waChatMessages');
        if (!box) return;
        if (!messages.length) {
            box.innerHTML = '<div class="wa-chat-no-messages">No hay mensajes en esta conversación.</div>';
            return;
        }

        let lastDay = '';
        let html = '';
        if (state.messageHasMore) {
            html += '<div class="sigoi-message-history-more"><button type="button" data-sigoi-load-older-messages>Cargar ' +
                state.messagePageSize + ' mensajes anteriores</button><small>' + messages.length + ' mensajes cargados</small></div>';
        }
        messages.forEach(message => {
            const day = formatDayLabel(message.creado_en);
            if (day !== lastDay) {
                html += '<div class="wa-date-divider"><span>' + escapeHtml(day) + '</span></div>';
                lastDay = day;
            }
            html += renderMessage(message, channel);
        });
        box.innerHTML = html;
    }

    function renderOrigin(c, channel) {
        const section = $('waOriginSection');
        if (!section) return;
        let has = false;
        let title = '';
        let meta = '';
        let link = '';
        let image = '';
        if (channel === 'whatsapp') {
            has = !!(c.origen_fuente || c.origen_id || c.origen_url || c.origen_titulo);
            title = c.origen_titulo || (c.origen_fuente === 'meta_ad' ? 'Facebook / Instagram Ads' : 'Origen del contacto');
            meta = c.origen_id ? (c.origen_fuente === 'meta_ad' ? 'Anuncio de Meta · ID ' : 'Origen · ID ') + c.origen_id : (c.origen_fuente || '');
            link = c.origen_url || '';
            image = c.origen_media_url || '';
        } else if (channel === 'messenger') {
            has = !!(c.origen_fuente || c.origen_ad_id || c.origen_ref || c.origen_referer_uri);
            title = c.origen_ad_id ? 'Anuncio de Meta' : 'Origen de Messenger';
            meta = c.origen_ad_id ? 'Ad ID ' + c.origen_ad_id : (c.origen_tipo || c.origen_fuente || c.origen_ref || 'Meta Messenger');
            link = c.origen_referer_uri || '';
        }
        section.classList.toggle('hidden', !has);
        if (!has) return;
        $('waOriginTitle') && ($('waOriginTitle').textContent = title);
        $('waOriginMeta') && ($('waOriginMeta').textContent = meta);
        if ($('waOriginLink')) {
            $('waOriginLink').classList.toggle('hidden', !link);
            if (link) $('waOriginLink').href = link;
        }
        if ($('waOriginImage')) {
            $('waOriginImage').classList.toggle('hidden', !image);
            if (image) $('waOriginImage').src = image;
        }
    }

    function renderConversation(data, preserveScroll) {
        const c = data.conversacion || {};
        const channel = rowChannel(Object.assign({}, c, { canal: data.canal || state.selectedChannel }));
        const box = $('waChatMessages');
        const nearBottom = !box || box.scrollHeight - box.scrollTop - box.clientHeight < 140;
        const messages = data.mensajes || [];
        const newest = messages.length ? Math.max(...messages.map(m => Number(m.id || 0))) : 0;
        const hasNew = state.lastMessageId > 0 && newest > state.lastMessageId;
        state.lastMessageId = newest;
        state.selected = data;
        state.selectedChannel = channel;
        state.selectedId = Number(c.id || state.selectedId || 0);
        state.messageHasMore = !!data.historial?.has_more;
        state.messageCursor = data.historial?.cursor || null;

        $('waChatEmpty')?.classList.add('hidden');
        $('waChatView')?.classList.remove('hidden');
        $('waContactEmpty')?.classList.add('hidden');
        $('waContactView')?.classList.remove('hidden');

        const name = conversationName(Object.assign({}, c, { canal: channel }));
        $('waChatAvatar') && ($('waChatAvatar').textContent = initials(name));
        $('waDetailAvatar') && ($('waDetailAvatar').textContent = initials(name));
        $('waChatName') && ($('waChatName').textContent = name);
        $('waDetailName') && ($('waDetailName').textContent = name);

        let secondary = channelMeta[channel].label;
        let detailMain = channelMeta[channel].label;
        let username = String(c.username_whatsapp || c.username || '').replace(/^@+/, '');
        if (channel === 'whatsapp') { secondary = formatWhatsappPhone(c.telefono); detailMain = secondary; }
        else if (channel === 'instagram') { secondary = username ? '@' + username : 'Instagram'; }
        else { secondary = 'Messenger'; }
        $('waChatPhone') && ($('waChatPhone').textContent = secondary);
        $('waDetailPhone') && ($('waDetailPhone').textContent = detailMain);
        if ($('waChatChannelBadge')) {
            $('waChatChannelBadge').textContent = channelMeta[channel].label;
            $('waChatChannelBadge').className = 'wa-chat-channel-badge is-' + channel;
        }

        $('waChatState') && ($('waChatState').textContent = c.estado === 'cerrada' ? 'Resuelta' : (Number(c.requiere_humano) === 1 ? 'Pendiente' : 'Abierta'));
        $('waChatAssignment') && ($('waChatAssignment').textContent = c.asignado_nombre || 'Sin asignar');
        if ($('waDetailUsername')) {
            const value = channel === 'instagram' && username ? '@' + username : '';
            $('waDetailUsername').textContent = value;
            $('waDetailUsername').classList.toggle('hidden', !value);
        }
        if ($('waDetailWhatsappName')) {
            const detected = String(c.nombre_contacto || '').trim();
            const prefix = channel === 'whatsapp' ? 'Perfil de WhatsApp: ' : (channel === 'instagram' ? 'Perfil de Instagram: ' : 'Perfil de Messenger: ');
            $('waDetailWhatsappName').textContent = detected ? prefix + detected : '';
            $('waDetailWhatsappName').classList.toggle('hidden', !detected);
        }
        $('waContactCustomName') && ($('waContactCustomName').value = c.nombre_personalizado || '');
        $('waContactNotes') && ($('waContactNotes').value = c.notas_contacto || '');
        renderOrigin(c, channel);

        $('waDetailState') && ($('waDetailState').textContent = c.estado === 'cerrada' ? 'Resuelta' : 'Abierta');
        $('waDetailPending')?.classList.toggle('hidden', Number(c.requiere_humano) !== 1);
        $('waDetailFirst') && ($('waDetailFirst').textContent = formatDateTime(c.primer_mensaje_en || c.creado_en, false));
        $('waDetailLast') && ($('waDetailLast').textContent = formatDateTime(c.ultimo_mensaje_en, false));
        $('waDetailMessages') && ($('waDetailMessages').textContent = Number(c.total_mensajes || 0));
        $('waDetailResponse') && ($('waDetailResponse').textContent = formatDuration(c.tiempo_primera_respuesta_humana_seg));

        if ($('waDetailChannelTitle')) {
            $('waDetailChannelTitle').textContent = channelMeta[channel].label + ' + S.I.G.O.I.';
            const descriptions = {
                whatsapp: 'WhatsApp Business mediante YCloud',
                instagram: 'Mensajes directos mediante la API oficial de Meta',
                messenger: 'Mensajes de la página de Facebook mediante Meta Messenger'
            };
            $('waDetailChannelText').textContent = descriptions[channel];
            $('waDetailChannelCard')?.classList.remove('is-instagram', 'is-messenger');
            if (channel !== 'whatsapp') $('waDetailChannelCard')?.classList.add('is-' + channel);
        }

        if ($('waAssigneeSelect')) {
            $('waAssigneeSelect').innerHTML = '<option value="0">Sin asignar</option>' + (data.usuarios || []).map(u => '<option value="' + Number(u.id) + '">' + escapeHtml(u.nombre) + ' · ' + escapeHtml(u.rol) + '</option>').join('');
            $('waAssigneeSelect').value = String(c.asignado_a || 0);
        }
        $('waResolveBtn') && ($('waResolveBtn').textContent = c.estado === 'cerrada' ? 'Reabrir' : 'Marcar resuelta');

        const windowActive = !!data.ventana_24h?.active;
        const sendEnabled = channel === 'whatsapp' ? true : !!data.send_enabled;
        const canCompose = canReply && windowActive && sendEnabled;
        const canMedia = canCompose && (channel === 'whatsapp' || !!data.can_send_media);

        if ($('waRetakeBtn')) $('waRetakeBtn').classList.toggle('hidden', channel !== 'whatsapp');
        if ($('waWindowAlert')) {
            const show = !windowActive || (channel !== 'whatsapp' && !sendEnabled);
            $('waWindowAlert').classList.toggle('hidden', !show);
            if (show) {
                if ($('waWindowAlertTitle')) $('waWindowAlertTitle').textContent = !sendEnabled && channel !== 'whatsapp' ? channelMeta[channel].label + ' conectado en modo recepción' : 'Conversación fuera de ventana';
                if ($('waWindowAlertText')) $('waWindowAlertText').textContent = !sendEnabled && channel !== 'whatsapp' ? 'La recepción está activa. El envío desde S.I.G.O.I. no está habilitado para este canal.' : (channel === 'whatsapp' ? 'El historial sigue disponible. Para volver a escribir usa una plantilla aprobada.' : 'El historial sigue disponible, pero esta conversación está fuera de la ventana estándar de mensajería.');
            }
        }
        $('waComposerText') && ($('waComposerText').disabled = !canCompose);
        $('waSendBtn') && ($('waSendBtn').disabled = !canCompose);
        if ($('waAttachBtn')) { $('waAttachBtn').disabled = !canMedia; $('waAttachBtn').classList.toggle('hidden', !canMedia); }
        if ($('waFileInput')) $('waFileInput').accept = channel === 'whatsapp' ? 'image/jpeg,image/png,video/mp4,video/3gpp,audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt' : 'image/*,video/*,audio/*';
        if ($('waQuickReplyBtn')) { $('waQuickReplyBtn').disabled = !canCompose; $('waQuickReplyBtn').classList.toggle('hidden', !canCompose); }
        if ($('waComposerWindow')) { $('waComposerWindow').textContent = windowActive ? 'Ventana de 24 h activa' : 'Ventana de 24 h cerrada'; $('waComposerWindow').classList.toggle('is-closed', !canCompose); }
        if ($('waComposerHint')) $('waComposerHint').textContent = canCompose ? 'Enter para enviar · Shift+Enter para salto de línea · ⚡ para respuestas rápidas' : (channel === 'whatsapp' ? 'Texto libre bloqueado. Usa “Retomar conversación” con una plantilla aprobada.' : 'No se puede responder en este momento.');

        const messageSignature = messages.map(message => [
            message.id,
            message.estado_envio,
            message.entregado_en,
            message.leido_en,
            message.editado_veces,
            message.reaccion_emoji,
            message.error_envio,
            message.contenido,
            message.media?.url
        ].join('|')).join('~');

        if (!preserveScroll || messageSignature !== state.messageSignature) {
            renderMessages(messages, channel);
            state.messageSignature = messageSignature;
            if (box) {
                if (!preserveScroll || nearBottom) { box.scrollTop = box.scrollHeight; $('waJumpLatestBtn')?.classList.add('hidden'); }
                else if (hasNew) $('waJumpLatestBtn')?.classList.remove('hidden');
            }
        }
        renderConversationList();
    }

    async function syncMessengerMeta(id) {
        const last = state.metaSyncAt.get(Number(id)) || 0;
        if (Date.now() - last < 300000 || state.metaSyncInFlight.has(Number(id))) return;
        state.metaSyncAt.set(Number(id), Date.now());
        state.metaSyncInFlight.add(Number(id));
        try {
            const data = await api('messenger', 'conversation.php?id=' + encodeURIComponent(id) + '&sync_meta=1');
            if (state.selectedChannel === 'messenger' && Number(state.selectedId) === Number(id)) renderConversation(Object.assign({}, data, { canal: 'messenger' }), true);
        } catch (_) {
        } finally {
            state.metaSyncInFlight.delete(Number(id));
        }
    }

    function mergeMessages(existing, incoming) {
        const map = new Map();
        (existing || []).forEach(message => map.set(Number(message.id || 0), message));
        (incoming || []).forEach(message => map.set(Number(message.id || 0), message));
        return Array.from(map.values()).sort((a, b) => {
            const at = parseDate(a.creado_en)?.getTime() || 0;
            const bt = parseDate(b.creado_en)?.getTime() || 0;
            if (at !== bt) return at - bt;
            return Number(a.id || 0) - Number(b.id || 0);
        });
    }

    async function loadConversation(id, channel, preserveScroll, backgroundSync) {
        if (!id || !channelAllowed(channel)) return;
        let path = 'conversation.php?id=' + encodeURIComponent(id) + '&limit=' + state.messagePageSize;
        if (channel === 'messenger') path += '&sync_meta=0';
        if (channel === 'instagram' && !preserveScroll) path += '&repair=1';
        const data = await api(channel, path);
        if (Number(id) !== Number(state.selectedId) || channel !== state.selectedChannel) return;

        if (preserveScroll && state.selected && Number(state.selected.conversacion?.id || 0) === Number(id)) {
            data.mensajes = mergeMessages(state.selected.mensajes || [], data.mensajes || []);
            /* El cursor más antiguo debe seguir siendo el ya cargado, no el de la página reciente. */
            if (state.messageCursor) {
                data.historial = Object.assign({}, data.historial || {}, {
                    has_more: state.messageHasMore,
                    cursor: state.messageCursor
                });
            }
        }
        renderConversation(Object.assign({}, data, { canal: channel }), preserveScroll);
        state.lastConversationRefreshAt = Date.now();
        if (channel === 'messenger' && backgroundSync !== false) setTimeout(() => syncMessengerMeta(id), 350);
    }

    async function loadOlderMessages() {
        if (state.messageLoadingOlder || !state.messageHasMore || !state.messageCursor || !state.selectedId || !state.selectedChannel) return;
        state.messageLoadingOlder = true;
        const box = $('waChatMessages');
        const previousHeight = box ? box.scrollHeight : 0;
        const previousTop = box ? box.scrollTop : 0;
        try {
            const cursor = state.messageCursor;
            let path = 'conversation.php?id=' + encodeURIComponent(state.selectedId) + '&limit=' + state.messagePageSize +
                '&before_id=' + encodeURIComponent(cursor.id || 0) + '&before_time=' + encodeURIComponent(cursor.creado_en || '');
            if (state.selectedChannel === 'messenger') path += '&sync_meta=0';
            const data = await api(state.selectedChannel, path);
            if (!state.selected) return;
            const merged = mergeMessages(state.selected.mensajes || [], data.mensajes || []);
            data.mensajes = merged;
            data.conversacion = state.selected.conversacion || data.conversacion;
            data.ventana_24h = state.selected.ventana_24h || data.ventana_24h;
            data.usuarios = state.selected.usuarios || data.usuarios;
            renderConversation(Object.assign({}, state.selected, data, { canal: state.selectedChannel }), true);
            if (box) box.scrollTop = Math.max(0, previousTop + (box.scrollHeight - previousHeight));
        } catch (error) {
            toast(error.message, true);
        } finally {
            state.messageLoadingOlder = false;
        }
    }

    async function selectConversation(id, channel, preserveScroll) {
        state.selectedId = Number(id);
        state.selectedChannel = channel;
        state.lastMessageId = 0;
        state.messageSignature = '';
        state.messageHasMore = false;
        state.messageCursor = null;
        state.lastConversationRefreshAt = 0;
        renderConversationList(true);
        try { await loadConversation(id, channel, !!preserveScroll, true); }
        catch (error) { toast(error.message, true); }
    }

    async function refreshInbox(preserveSelection, options) {
        options = options || {};
        if (state.busy && !options.poll) return;
        if (!options.poll) state.busy = true;
        setSyncState(true);

        try {
            const previousSelectedSignature = selectedRowSignature(state.rows);
            const previousRowsSignature = rowsSignature(state.rows);
            const pageResult = await fetchRows(1);
            const keepLoadedPages = state.conversationServerPage > 1 && !!preserveSelection;
            const nextRows = keepLoadedPages
                ? mergeConversationRows(state.rows, pageResult.rows)
                : pageResult.rows;
            const nextRowsSignature = rowsSignature(nextRows);

            state.rows = nextRows;
            if (!options.poll || state.conversationServerPage <= 1) state.conversationServerHasMore = !!pageResult.hasMore;
            updateUnreadBadge();

            const stillExists = state.selectedId && state.rows.some(row =>
                Number(row.id) === Number(state.selectedId)
                && rowChannel(row) === state.selectedChannel
            );

            if (!preserveSelection || !stillExists) {
                const first = state.rows[0] || null;
                if (first) {
                    state.selectedId = Number(first.id);
                    state.selectedChannel = rowChannel(first);
                    state.lastMessageId = 0;
                    state.messageSignature = '';
                    state.messageHasMore = false;
                    state.messageCursor = null;
                    state.lastConversationRefreshAt = 0;
                } else {
                    showEmptyChat();
                    renderConversationList(true);
                    setSyncState(false);
                    return;
                }
            }

            renderConversationList(previousRowsSignature !== nextRowsSignature);

            const nextSelectedSignature = selectedRowSignature(state.rows);
            const selectedRowChanged = previousSelectedSignature !== nextSelectedSignature;
            const conversationStale = Date.now() - state.lastConversationRefreshAt >= state.conversationRefreshIntervalMs;
            const shouldReloadConversation =
                !!state.selectedId
                && (
                    !options.poll
                    || options.forceConversation
                    || !state.selected
                    || selectedRowChanged
                    || conversationStale
                );

            if (shouldReloadConversation) {
                await loadConversation(
                    state.selectedId,
                    state.selectedChannel,
                    !!preserveSelection,
                    !options.poll
                );
            }

            setSyncState(false);
        } catch (error) {
            setSyncState(false, options.poll ? 'Reconectando...' : 'Error al actualizar');
            if (!options.poll) toast(error.message, true);
        } finally {
            if (!options.poll) state.busy = false;
        }
    }

    function startPolling() {
        stopPolling();
        state.polling = window.setInterval(async function () {
            if (document.hidden || state.tab !== 'inbox' || state.busy) return;
            state.busy = true;
            try { await refreshInbox(true, { poll: true }); }
            finally { state.busy = false; }
        }, state.pollIntervalMs);
    }

    function stopPolling() {
        if (state.polling) window.clearInterval(state.polling);
        state.polling = null;
    }

    function setInboxTab() {
        state.tab = 'inbox';
        page.classList.add('is-inbox-view');
        document.querySelectorAll('[data-wa-tab]').forEach(btn => btn.classList.toggle('is-active', btn.dataset.waTab === 'inbox'));
        document.querySelectorAll('[data-wa-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.waPanel === 'inbox'));
        refreshInbox(true);
        startPolling();
    }

    async function conversationAction(action, extra) {
        if (!state.selectedId || !state.selectedChannel) return;
        try {
            const data = await post(state.selectedChannel, 'conversation-update.php', Object.assign({ id: state.selectedId, action: action }, extra || {}));
            toast(data.message || 'Actualizado.');
            await refreshInbox(true);
        } catch (error) { toast(error.message, true); }
    }

    async function saveContact() {
        if (!state.selectedId) return;
        const btn = $('waSaveContactBtn');
        if (btn) btn.disabled = true;
        try {
            const data = await post(state.selectedChannel, 'conversation-update.php', {
                id: state.selectedId,
                action: 'contact_save',
                nombre_personalizado: $('waContactCustomName')?.value || '',
                notas_contacto: $('waContactNotes')?.value || ''
            });
            toast(data.message || 'Contacto guardado.');
            await refreshInbox(true);
        } catch (error) { toast(error.message, true); }
        finally { if (btn) btn.disabled = false; }
    }

    function clearSelectedFile() {
        state.selectedFile = null;
        if ($('waFileInput')) $('waFileInput').value = '';
        $('waFileChip')?.classList.add('hidden');
    }

    function resizeComposer() {
        const box = $('waComposerText');
        if (!box) return;
        box.style.height = 'auto';
        box.style.height = Math.min(box.scrollHeight, 140) + 'px';
        $('waComposerChars') && ($('waComposerChars').textContent = box.value.length + '/4000');
    }

    async function sendComposer() {
        if (!canReply || !state.selectedId || !state.selectedChannel) return;
        const text = String($('waComposerText')?.value || '').trim();
        const file = state.selectedFile || $('waFileInput')?.files?.[0] || null;
        if (!text && !file) return;
        const btn = $('waSendBtn');
        if (btn) btn.disabled = true;
        try {
            if (file) {
                const form = new FormData();
                form.append('conversation_id', String(state.selectedId));
                form.append('caption', text);
                form.append('file', file);
                const data = await api(state.selectedChannel, 'send-media.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': window.APP_CSRF_TOKEN || '' },
                    body: form
                });
                toast(data.message || 'Archivo enviado.');
                clearSelectedFile();
            } else {
                const data = await post(state.selectedChannel, 'send-text.php', { conversation_id: state.selectedId, body: text });
                toast(data.message || 'Mensaje enviado.');
            }
            if ($('waComposerText')) $('waComposerText').value = '';
            resizeComposer();
            await refreshInbox(true);
        } catch (error) { toast(error.message, true); }
        finally {
            const active = !!state.selected?.ventana_24h?.active;
            const sendEnabled = state.selectedChannel === 'whatsapp' || !!state.selected?.send_enabled;
            if (btn) btn.disabled = !(canReply && active && sendEnabled);
        }
    }

    function getLimaGreeting(now) {
        now = now || new Date();
        let hour = null;
        try {
            const part = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Lima', hour: '2-digit', hourCycle: 'h23' }).formatToParts(now).find(p => p.type === 'hour');
            if (part) hour = Number(part.value);
        } catch (_) {}
        if (!Number.isFinite(hour)) hour = (now.getUTCHours() + 19) % 24;
        return hour >= 5 && hour <= 11 ? 'Buenos días' : (hour >= 12 && hour <= 18 ? 'Buenas tardes' : 'Buenas noches');
    }

    function resolveQuickText(text) {
        return String(text || '').replace(/\{\{\s*saludo\s*\}\}/gi, getLimaGreeting());
    }

    async function loadQuickReplies() {
        if (state.quickLoaded) return state.quickRows;
        const data = await requestJson('modules/whatsapp/quick-replies.php?active=1', { headers: { 'Accept': 'application/json' }, cache: 'no-store' }, 'No se pudieron cargar las respuestas rápidas.');
        state.quickRows = Array.isArray(data.respuestas) ? data.respuestas : [];
        state.quickLoaded = true;
        return state.quickRows;
    }

    function renderQuickPicker() {
        const target = $('waQuickPickerList');
        if (!target) return;
        const q = String($('waQuickPickerSearch')?.value || '').trim().toLowerCase().replace(/^\//, '');
        const rows = state.quickRows.filter(row => Number(row.activa) === 1).filter(row => !q || [row.titulo, row.atajo, row.categoria, row.contenido].some(v => String(v || '').toLowerCase().includes(q)));
        if (!rows.length) { target.innerHTML = '<div class="wa-empty-mini">No hay respuestas que coincidan.</div>'; return; }
        let category = '';
        let html = '';
        rows.forEach(row => {
            const cat = row.categoria || 'General';
            if (cat !== category) { html += '<div class="wa-quick-picker-category">' + escapeHtml(cat) + '</div>'; category = cat; }
            html += '<button type="button" class="wa-quick-picker-item" data-sigoi-use-quick="' + Number(row.id) + '"><div><strong>' + escapeHtml(row.titulo) + '</strong><code>/' + escapeHtml(row.atajo) + '</code></div><span>' + escapeHtml(resolveQuickText(row.contenido)) + '</span></button>';
        });
        target.innerHTML = html;
    }

    async function openQuickPicker() {
        if (!state.selected?.ventana_24h?.active) return;
        try { await loadQuickReplies(); renderQuickPicker(); $('waQuickPicker')?.classList.remove('hidden'); $('waQuickPickerSearch')?.focus(); }
        catch (error) { toast(error.message, true); }
    }

    function closeQuickPicker() { $('waQuickPicker')?.classList.add('hidden'); }

    function templatePreview(body, values) {
        let output = String(body || '');
        (values || []).forEach((value, index) => { output = output.replace(new RegExp('\\{\\{\\s*' + (index + 1) + '\\s*\\}\\}', 'g'), String(value || '')); });
        return output;
    }

    function friendlyTemplateName(name) {
        const text = String(name || '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
        return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'Plantilla WhatsApp';
    }

    async function loadApprovedTemplates() {
        const data = await requestJson('modules/whatsapp/templates.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' }, 'No se pudieron cargar las plantillas.');
        return (data.plantillas || []).filter(row => String(row.status || '').toUpperCase() === 'APPROVED' && row.send_supported !== false);
    }

    async function openTemplateModal() {
        if (state.selectedChannel !== 'whatsapp' || !state.selectedId || !$('waSendTemplateModal')) return;
        $('waSendTemplateModal').classList.remove('hidden');
        $('waTemplatePickerStage')?.classList.remove('hidden');
        $('waTemplatePrepareStage')?.classList.add('hidden');
        $('waSendTemplateSubmit')?.classList.add('hidden');
        if ($('waSendTemplatePickerList')) $('waSendTemplatePickerList').innerHTML = '<div class="wa-empty-mini">Cargando plantillas aprobadas...</div>';
        try {
            const rows = await loadApprovedTemplates();
            window.__SIGOI_RC3_TEMPLATES = rows;
            renderTemplatePicker(rows);
        } catch (error) {
            if ($('waSendTemplatePickerList')) $('waSendTemplatePickerList').innerHTML = '<div class="wa-library-error"><strong>No se pudieron cargar las plantillas.</strong><span>' + escapeHtml(error.message) + '</span></div>';
        }
    }

    function renderTemplatePicker(rows) {
        const target = $('waSendTemplatePickerList');
        const count = $('waSendTemplateCount');
        if (!target) return;
        if (count) count.textContent = rows.length + (rows.length === 1 ? ' plantilla disponible' : ' plantillas disponibles');
        if (!rows.length) { target.innerHTML = '<div class="wa-library-empty"><strong>No hay plantillas aprobadas.</strong></div>'; return; }
        target.innerHTML = rows.map((row, index) => '<article class="wa-template-pick-card"><div class="wa-template-pick-main"><div class="wa-template-pick-heading"><div><strong>' + escapeHtml(friendlyTemplateName(row.name)) + '</strong><code>' + escapeHtml(row.name) + '</code></div><span class="wa-template-status is-approved">Aprobada</span></div><div class="wa-template-pick-preview">' + escapeHtml(row.body || '').replace(/\n/g, '<br>') + '</div></div><button type="button" class="wa-template-pick-use" data-sigoi-template-index="' + index + '">Usar plantilla <span>→</span></button></article>').join('');
    }

    function prepareTemplate(row) {
        window.__SIGOI_RC3_SELECTED_TEMPLATE = row;
        $('waTemplatePickerStage')?.classList.add('hidden');
        $('waTemplatePrepareStage')?.classList.remove('hidden');
        $('waSendTemplateSubmit')?.classList.remove('hidden');
        if ($('waSendTemplateTitle')) $('waSendTemplateTitle').textContent = 'Preparar mensaje';
        const count = Number(row.variable_count || 0);
        const target = $('waSendTemplateVariables');
        if (target) {
            target.innerHTML = count ? Array.from({ length: count }, (_, i) => {
                const n = i + 1;
                const automatic = row.automatic_variables?.[String(n)] || row.automatic_variables?.[n] || null;
                const value = automatic?.type === 'greeting' ? getLimaGreeting() : (automatic?.value || (i === 0 ? conversationName(Object.assign({}, state.selected?.conversacion || {}, { canal: 'whatsapp' })) : (row.examples?.[i] || '')));
                return '<label class="wa-field"><span>{{' + n + '}}</span><input data-sigoi-template-var="' + i + '" maxlength="500" value="' + escapeHtml(value) + '" ' + (automatic ? 'readonly' : '') + '></label>';
            }).join('') : '<div class="wa-no-template-vars"><strong>Lista para enviar</strong><span>Esta plantilla no tiene variables.</span></div>';
        }
        updateTemplatePreview();
        document.querySelectorAll('[data-sigoi-template-var]').forEach(input => input.addEventListener('input', updateTemplatePreview));
    }

    function updateTemplatePreview() {
        const row = window.__SIGOI_RC3_SELECTED_TEMPLATE;
        if (!row || !$('waSendTemplatePreview')) return;
        const values = Array.from(document.querySelectorAll('[data-sigoi-template-var]')).map(input => input.value || '');
        let text = templatePreview(row.body || '', values);
        if (row.footer) text += '\n\n' + row.footer;
        $('waSendTemplatePreview').textContent = text || row.name;
    }

    async function sendSelectedTemplate(event) {
        event?.preventDefault();
        event?.stopImmediatePropagation();
        if (state.selectedChannel !== 'whatsapp' || !state.selectedId) return;
        const row = window.__SIGOI_RC3_SELECTED_TEMPLATE;
        if (!row) return toast('Selecciona una plantilla aprobada.', true);
        const values = Array.from(document.querySelectorAll('[data-sigoi-template-var]')).map(input => input.value.trim());
        if (values.some(v => !v)) return toast('Completa todos los campos personalizables.', true);
        const btn = $('waSendTemplateSubmit');
        if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
        try {
            const data = await post('whatsapp', 'send-template.php', { conversation_id: state.selectedId, name: row.name, language: row.language, variables: values });
            toast(data.message || 'Plantilla enviada.');
            $('waSendTemplateModal')?.classList.add('hidden');
            await refreshInbox(true);
        } catch (error) { toast(error.message, true); }
        finally { if (btn) { btn.disabled = false; btn.textContent = 'Enviar plantilla'; } }
    }

    function bindEvents() {
        document.addEventListener('click', function (event) {
            const tab = event.target.closest('[data-wa-tab]');
            if (tab) {
                if (tab.dataset.waTab === 'analytics') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    stopPolling();
                    window.location.href = 'analitica-multicanal.php';
                    return;
                }
                if (tab.dataset.waTab === 'inbox') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    setInboxTab();
                    return;
                }
                state.tab = tab.dataset.waTab || '';
                stopPolling();
            }

            const conv = event.target.closest('[data-wa-conversation]');
            if (conv && conv.closest('#waConversationList')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                selectConversation(Number(conv.dataset.waConversation), conv.dataset.waChannel, false);
                return;
            }

            const channel = event.target.closest('#waInboxChannels [data-wa-channel]');
            if (channel) {
                event.preventDefault();
                event.stopImmediatePropagation();
                state.channel = channel.dataset.waChannel;
                document.querySelectorAll('#waInboxChannels [data-wa-channel]').forEach(btn => btn.classList.toggle('is-active', btn === channel));
                state.selectedId = null; state.selectedChannel = ''; state.lastMessageId = 0; state.messageSignature = '';
                state.conversationVisible = state.conversationPageSize;
                state.conversationServerPage = 1;
                state.conversationServerHasMore = false;
                state.messageHasMore = false;
                state.messageCursor = null;
                state.listSignature = '';
                try { sessionStorage.setItem('sigoi_inbox_active_channel', state.channel); } catch (_) {}
                refreshInbox(false);
                return;
            }

            const filter = event.target.closest('#waInboxFilters [data-wa-filter]');
            if (filter) {
                event.preventDefault();
                event.stopImmediatePropagation();
                state.filter = filter.dataset.waFilter;
                $('waInboxFilters')?.querySelectorAll('[data-wa-filter]').forEach(btn => btn.classList.toggle('is-active', btn === filter));
                state.selectedId = null; state.selectedChannel = ''; state.lastMessageId = 0; state.messageSignature = '';
                state.conversationVisible = state.conversationPageSize;
                state.conversationServerPage = 1;
                state.conversationServerHasMore = false;
                state.messageHasMore = false;
                state.messageCursor = null;
                state.listSignature = '';
                refreshInbox(false);
                return;
            }

            const loadMoreConversations = event.target.closest('[data-sigoi-load-more-conversations]');
            if (loadMoreConversations) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (state.conversationLoadingMore) return;
                state.conversationLoadingMore = true;
                loadMoreConversations.disabled = true;
                const revealNext = async function () {
                    try {
                        if (state.conversationServerHasMore) {
                            const nextPage = state.conversationServerPage + 1;
                            const result = await fetchRows(nextPage);
                            state.rows = mergeConversationRows(state.rows, result.rows);
                            state.conversationServerPage = nextPage;
                            state.conversationServerHasMore = !!result.hasMore;
                            updateUnreadBadge();
                        }
                        state.conversationVisible += state.conversationPageSize;
                        state.listSignature = '';
                        renderConversationList(true);
                    } catch (error) { toast(error.message, true); }
                    finally { state.conversationLoadingMore = false; }
                };
                revealNext();
                return;
            }

            const loadOlderMessagesButton = event.target.closest('[data-sigoi-load-older-messages]');
            if (loadOlderMessagesButton) {
                event.preventDefault();
                event.stopImmediatePropagation();
                loadOlderMessages();
                return;
            }

            if (event.target.closest('#waManualRefreshBtn')) { event.preventDefault(); event.stopImmediatePropagation(); refreshInbox(true, { forceConversation: true }); return; }
            if (event.target.closest('#waResolveBtn')) { event.preventDefault(); event.stopImmediatePropagation(); const c = state.selected?.conversacion; if (c) conversationAction(c.estado === 'cerrada' ? 'reopen' : 'resolve'); return; }
            if (event.target.closest('#waMarkPendingBtn')) { event.preventDefault(); event.stopImmediatePropagation(); conversationAction('pending'); return; }
            if (event.target.closest('#waSaveContactBtn')) { event.preventDefault(); event.stopImmediatePropagation(); saveContact(); return; }
            if (event.target.closest('#waAttachBtn')) { event.preventDefault(); event.stopImmediatePropagation(); $('waFileInput')?.click(); return; }
            if (event.target.closest('#waClearFileBtn')) { event.preventDefault(); event.stopImmediatePropagation(); clearSelectedFile(); return; }
            if (event.target.closest('#waSendBtn')) { event.preventDefault(); event.stopImmediatePropagation(); sendComposer(); return; }
            if (event.target.closest('#waQuickReplyBtn')) { event.preventDefault(); event.stopImmediatePropagation(); if ($('waQuickPicker')?.classList.contains('hidden')) openQuickPicker(); else closeQuickPicker(); return; }
            if (event.target.closest('#waQuickPickerClose')) { event.preventDefault(); event.stopImmediatePropagation(); closeQuickPicker(); return; }
            const quick = event.target.closest('[data-sigoi-use-quick]');
            if (quick) {
                event.preventDefault(); event.stopImmediatePropagation();
                const row = state.quickRows.find(item => Number(item.id) === Number(quick.dataset.sigoiUseQuick));
                if (row && $('waComposerText')) { $('waComposerText').value = resolveQuickText(row.contenido); resizeComposer(); closeQuickPicker(); $('waComposerText').focus(); }
                return;
            }
            if (event.target.closest('#waRetakeBtn')) { event.preventDefault(); event.stopImmediatePropagation(); openTemplateModal(); return; }
            const tpl = event.target.closest('[data-sigoi-template-index]');
            if (tpl) { event.preventDefault(); event.stopImmediatePropagation(); const row = (window.__SIGOI_RC3_TEMPLATES || [])[Number(tpl.dataset.sigoiTemplateIndex)]; if (row) prepareTemplate(row); return; }
            if (event.target.closest('[data-wa-close-template-send]')) { event.preventDefault(); event.stopImmediatePropagation(); $('waSendTemplateModal')?.classList.add('hidden'); return; }
            const image = event.target.closest('[data-wa-image]');
            if (image) { event.preventDefault(); event.stopImmediatePropagation(); if ($('waLightboxImage')) $('waLightboxImage').src = image.dataset.waImage; $('waLightbox')?.classList.remove('hidden'); return; }
            if (event.target.closest('#waLightboxClose') || event.target === $('waLightbox')) { $('waLightbox')?.classList.add('hidden'); return; }
            if (event.target.closest('#waJumpLatestBtn')) { const box = $('waChatMessages'); if (box) box.scrollTop = box.scrollHeight; $('waJumpLatestBtn')?.classList.add('hidden'); }
        }, true);

        $('waInboxSearch')?.addEventListener('input', function (event) {
            event.stopImmediatePropagation();
            state.query = event.target.value.trim();
            clearTimeout(state.searchTimer);
            state.searchTimer = setTimeout(() => {
                state.selectedId = null;
                state.selectedChannel = '';
                state.messageSignature = '';
                state.conversationVisible = state.conversationPageSize;
                state.conversationServerPage = 1;
                state.conversationServerHasMore = false;
                state.messageHasMore = false;
                state.messageCursor = null;
                state.listSignature = '';
                refreshInbox(false);
            }, 420);
        }, true);

        $('waComposerText')?.addEventListener('input', function (event) { event.stopImmediatePropagation(); resizeComposer(); }, true);
        $('waComposerText')?.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); event.stopImmediatePropagation(); sendComposer(); }
        }, true);
        $('waQuickPickerSearch')?.addEventListener('input', function (event) { event.stopImmediatePropagation(); renderQuickPicker(); }, true);
        $('waAssigneeSelect')?.addEventListener('change', function (event) { event.stopImmediatePropagation(); conversationAction('assign', { usuario_id: Number(event.target.value || 0) }); }, true);
        $('waFileInput')?.addEventListener('change', function (event) {
            event.stopImmediatePropagation();
            const file = event.target.files?.[0] || null;
            state.selectedFile = file;
            if (!file) return clearSelectedFile();
            $('waFileName') && ($('waFileName').textContent = file.name);
            $('waFileSize') && ($('waFileSize').textContent = formatFileSize(file.size));
            $('waFileChip')?.classList.remove('hidden');
        }, true);
        $('waChatMessages')?.addEventListener('scroll', function () {
            const box = $('waChatMessages');
            if (box && box.scrollHeight - box.scrollTop - box.clientHeight < 100) $('waJumpLatestBtn')?.classList.add('hidden');
        });
        $('waSendTemplateForm')?.addEventListener('submit', sendSelectedTemplate, true);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state.tab === 'inbox') {
            refreshInbox(true, { forceConversation: true });
        }
    });

    async function init() {
        bindEvents();
        await refreshMessengerCapabilities();
        ensureChannelButtons();
        try {
            const stored = sessionStorage.getItem('sigoi_inbox_active_channel');
            if (stored && (stored === 'all' || channelAllowed(stored))) state.channel = stored;
        } catch (_) {}
        ensureChannelButtons();

        const requested = String(new URLSearchParams(window.location.search).get('tab') || '').toLowerCase();
        if (requested && requested !== 'inbox') {
            state.tab = requested;
            stopPolling();
            setTimeout(() => {
                const btn = document.querySelector('[data-wa-tab="' + requested + '"]');
                if (btn && !btn.classList.contains('is-active')) btn.click();
            }, 0);
            return;
        }
        setInboxTab();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
