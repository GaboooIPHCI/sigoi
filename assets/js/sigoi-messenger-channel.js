(function () {
    'use strict';

    const runtime = window.SIGOI_MESSENGER_RUNTIME || {
        active: false,
        mode: 'core'
    };

    window.SIGOI_MESSENGER_RUNTIME = runtime;

    const nativeInterval = window.SIGOI_NATIVE_SET_INTERVAL
        || window.setInterval.bind(window);

    const $ = id => document.getElementById(id);

    const permissions = window.WHATSAPP_PERMISSIONS || {};

    const canWhatsApp = permissions.canal_whatsapp !== false
        && permissions.canal_whatsapp !== 0;
    const canInstagram = permissions.canal_instagram !== false
        && permissions.canal_instagram !== 0;

    const storageKeys = {
        channel: 'sigoi_inbox_active_channel',
        messengerConversation: 'sigoi_messenger_selected_conversation'
    };

    function storageGet(key) {
        try {
            return window.sessionStorage.getItem(key) || '';
        } catch (_) {
            return '';
        }
    }

    function storageSet(key, value) {
        try {
            if (value === null || value === undefined || value === '') {
                window.sessionStorage.removeItem(key);
                return;
            }
            window.sessionStorage.setItem(key, String(value));
        } catch (_) {}
    }

    const state = {
        allowed: false,
        configured: false,
        sendEnabled: false,
        pageId: '',
        mode: 'all',
        selectedId: null,
        selected: null,
        rows: [],
        busy: false,
        selectedFile: null,
        quickRows: [],
        quickLoaded: false,
        hybridSelectedKey: '',
        hybridMergedCache: [],
        hybridCacheAt: 0,
        lastConversationId: null,
        lastConversationSignature: '',
        metaSyncInFlight: new Set(),
        metaSyncAt: new Map()
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function toast(message, error) {
        const box = $('toast');

        if (!box) {
            return;
        }

        box.textContent = message;
        box.className = 'toast ' + (error ? 'toast-error' : 'toast-success');
        box.classList.remove('hidden');

        clearTimeout(box._messengerTimer);

        box._messengerTimer = setTimeout(
            () => box.classList.add('hidden'),
            3400
        );
    }

    async function messengerApi(path, options) {
        const response = await fetch(
            'modules/messenger/' + path,
            Object.assign({
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            }, options || {})
        );

        let data;

        try {
            data = await response.json();
        } catch (_) {
            throw new Error(
                'Messenger devolvió una respuesta no válida.'
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'No se pudo consultar Messenger.'
            );
        }

        return data;
    }

    function messengerPost(path, payload) {
        return messengerApi(path, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.APP_CSRF_TOKEN
            },
            body: JSON.stringify(payload)
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

        if (
            compact
            && d.toDateString() === now.toDateString()
        ) {
            return d.toLocaleTimeString('es-PE', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        return d.toLocaleString('es-PE', {
            day: '2-digit',
            month: '2-digit',
            year: compact ? undefined : 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatDayLabel(value) {
        const d = parseDate(value);

        if (!d) return '';

        const today = new Date();
        const yesterday = new Date();

        yesterday.setDate(today.getDate() - 1);

        if (d.toDateString() === today.toDateString()) {
            return 'Hoy';
        }

        if (d.toDateString() === yesterday.toDateString()) {
            return 'Ayer';
        }

        return d.toLocaleDateString('es-PE', {
            weekday: 'short',
            day: '2-digit',
            month: 'short'
        });
    }

    function formatDuration(seconds) {
        if (
            seconds == null
            || Number.isNaN(Number(seconds))
        ) {
            return '—';
        }

        seconds = Math.max(0, Number(seconds));

        if (seconds < 60) {
            return Math.round(seconds) + ' s';
        }

        if (seconds < 3600) {
            return Math.round(seconds / 60) + ' min';
        }

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.round((seconds % 3600) / 60);

        return hours
            + ' h'
            + (minutes ? ' ' + minutes + ' min' : '');
    }

    function formatFileSize(bytes) {
        bytes = Number(bytes || 0);

        if (!bytes) return '';

        const units = ['B', 'KB', 'MB', 'GB'];
        let index = 0;

        while (
            bytes >= 1024
            && index < units.length - 1
        ) {
            bytes /= 1024;
            index++;
        }

        return (
            index === 0
                ? Math.round(bytes)
                : bytes.toFixed(bytes >= 10 ? 1 : 2)
        ) + ' ' + units[index];
    }

    function initials(name) {
        const parts = String(name || '?')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        return (
            parts
                .slice(0, 2)
                .map(part => part.charAt(0))
                .join('')
            || '?'
        ).toUpperCase();
    }

    function messengerName(row) {
        if (row?.nombre_personalizado) {
            return row.nombre_personalizado;
        }

        if (row?.nombre_contacto) {
            return row.nombre_contacto;
        }

        const psid = String(row?.psid || '');

        return psid
            ? 'Messenger ' + psid.slice(-6)
            : 'Messenger';
    }

    function highlight(text, query) {
        text = String(text || '');

        query = String(query || '').trim();

        if (!query) {
            return escapeHtml(text);
        }

        const lower = text.toLowerCase();
        const q = query.toLowerCase();
        const index = lower.indexOf(q);

        if (index < 0) {
            return escapeHtml(text);
        }

        return escapeHtml(text.slice(0, index))
            + '<mark class="wa-search-highlight">'
            + escapeHtml(text.slice(index, index + query.length))
            + '</mark>'
            + escapeHtml(text.slice(index + query.length));
    }

    function currentQuery() {
        return String($('waInboxSearch')?.value || '').trim();
    }

    function currentFilter() {
        const active = document.querySelector(
            '#waInboxFilters [data-wa-filter].is-active'
        );

        return active?.dataset.waFilter || 'all';
    }


    function setMessengerSyncState(loading, label) {
        const target = $('waSyncState');
        const line = target?.closest('.wa-sync-line');
        const button = $('waManualRefreshBtn');

        line?.classList.toggle('is-loading', !!loading);
        button?.classList.toggle('is-loading', !!loading);

        if (button) {
            button.disabled = !!loading;
        }

        if (!target) return;

        if (label) {
            target.textContent = label;
            return;
        }

        if (loading) {
            target.textContent = 'Actualizando...';
            return;
        }

        target.textContent = 'En vivo · '
            + new Date().toLocaleTimeString('es-PE', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
    }

    function currentCoreChannel() {
        const active = document.querySelector(
            '#waInboxChannels [data-wa-channel].is-active'
        );

        return active?.dataset.waChannel || 'all';
    }


    function isHybridAllMode() {
        return state.mode === 'all'
            || runtime.mode === 'all'
            || currentCoreChannel() === 'all';
    }

    function scheduleHybridAppend(delay) {
        clearTimeout(state.hybridAppendTimer);
        state.hybridAppendTimer = setTimeout(
            () => appendMessengerToAll(),
            Number(delay || 0)
        );
    }

    function ensureChannelRow() {
        let row = $('waInboxChannels');

        if (!row) {
            const search = document.querySelector('.wa-inbox-search');
            const filters = $('waInboxFilters');

            if (!search && !filters) {
                return null;
            }

            row = document.createElement('div');
            row.id = 'waInboxChannels';
            row.className = 'wa-channel-filter-row';

            if (filters?.parentNode) {
                filters.parentNode.insertBefore(row, filters);
            } else if (search?.parentNode) {
                search.parentNode.appendChild(row);
            }
        }

        return row;
    }

    function ensureChannelButtons() {
        const row = ensureChannelRow();

        if (!row) return;

        let messengerButton = row.querySelector(
            '[data-wa-channel="messenger"]'
        );

        if (!messengerButton) {
            messengerButton = document.createElement('button');
            messengerButton.type = 'button';
            messengerButton.dataset.waChannel = 'messenger';
            messengerButton.innerHTML =
                '<span class="wa-channel-filter-icon is-messenger">M</span>'
                + 'Messenger';

            row.appendChild(messengerButton);
        }

        const totalChannels = Number(canWhatsApp)
            + Number(canInstagram)
            + 1;

        let allButton = row.querySelector(
            '[data-wa-channel="all"]'
        );

        if (totalChannels > 1 && !allButton) {
            allButton = document.createElement('button');
            allButton.type = 'button';
            allButton.dataset.waChannel = 'all';
            allButton.innerHTML =
                '<span class="wa-channel-filter-icon">◎</span>Todos';

            row.insertBefore(allButton, row.firstChild);
        }

        row.classList.toggle(
            'is-single-channel',
            totalChannels <= 1
        );
    }

    async function fetchMessengerRows() {
        const qs = new URLSearchParams({
            q: currentQuery(),
            filter: currentFilter()
        });

        const data = await messengerApi(
            'inbox-list.php?' + qs.toString()
        );

        return Array.isArray(data.conversaciones)
            ? data.conversaciones
            : [];
    }

    function rowHtml(row, selected) {
        const name = messengerName(row);

        const search = currentQuery();

        const preview = row.match_preview
            || row.ultimo_mensaje_preview
            || '[Mensaje]';

        const previewPrefix = row.match_preview
            ? 'Coincidencia: '
            : (
                row.ultimo_mensaje_direccion === 'saliente'
                    ? 'Tú: '
                    : ''
            );

        const isAdOrigin = String(row.origen_fuente || '').toUpperCase() === 'ADS'
            || String(row.origen_ad_id || '').trim() !== '';

        return `
            <button
                type="button"
                class="wa-conversation-item wa-messenger-row
                    ${selected ? 'is-selected' : ''}
                    ${Number(row.no_leidos) > 0 ? 'has-unread' : ''}"
                data-wa-conversation="${Number(row.id)}"
                data-wa-messenger-conversation="${Number(row.id)}"
                data-wa-channel="messenger"
            >
                <div class="wa-avatar wa-avatar--channel">
                    ${escapeHtml(initials(name))}
                    <span class="wa-channel-mark is-messenger" title="Messenger">M</span>
                </div>

                <div class="wa-conversation-copy">
                    <div class="wa-conversation-top">
                        <strong>${highlight(name, search)}</strong>
                        <time>${escapeHtml(formatDateTime(row.ultimo_mensaje_en, true))}</time>
                    </div>

                    <div class="wa-conversation-preview">
                        <span>${escapeHtml(previewPrefix)}${highlight(preview, search)}</span>
                        ${
                            Number(row.no_leidos) > 0
                                ? `<b>${Number(row.no_leidos) > 99 ? '99+' : Number(row.no_leidos)}</b>`
                                : ''
                        }
                    </div>

                    <div class="wa-conversation-flags">
                        <span class="wa-conversation-channel is-messenger">Messenger</span>
                        ${isAdOrigin ? '<span class="is-ad-origin">Anuncio</span>' : ''}
                        ${
                            Number(row.requiere_humano) === 1
                                ? '<span class="is-pending">Pendiente</span>'
                                : ''
                        }
                        ${
                            row.asignado_nombre
                                ? `<span>${escapeHtml(row.asignado_nombre)}</span>`
                                : ''
                        }
                        ${
                            row.estado === 'cerrada'
                                ? '<span class="is-resolved">Resuelta</span>'
                                : ''
                        }
                    </div>
                </div>
            </button>
        `;
    }

    function updateCombinedIndicators() {
        const list = $('waConversationList');

        if (!list) return;

        const buttons = list.querySelectorAll(
            '.wa-conversation-item'
        );

        if ($('waInboxCount')) {
            $('waInboxCount').textContent = String(buttons.length);
        }

        let unread = 0;

        buttons.forEach(button => {
            const badge = button.querySelector(
                '.wa-conversation-preview > b'
            );

            if (!badge) return;

            const raw = badge.textContent.trim();

            unread += raw === '99+'
                ? 100
                : Number(raw || 0);
        });

        const topBadge = $('waInboxUnreadBadge');

        if (topBadge) {
            topBadge.textContent = unread > 99
                ? '99+'
                : String(unread);

            topBadge.classList.toggle(
                'hidden',
                unread <= 0
            );
        }
    }

    async function fetchCoreChannelRows(channel) {
        const qs = new URLSearchParams({
            q: currentQuery(),
            filter: currentFilter()
        });

        const response = await fetch(
            'modules/' + channel + '/inbox-list.php?' + qs.toString(),
            {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            }
        );

        let data;

        try {
            data = await response.json();
        } catch (_) {
            return [];
        }

        if (!response.ok || !data.success) {
            return [];
        }

        const rows = Array.isArray(data.conversaciones)
            ? data.conversaciones
            : [];

        return rows.map(row => Object.assign({}, row, {
            canal: channel
        }));
    }

    function hybridConversationName(row, channel) {
        const custom = String(row?.nombre_personalizado || '').trim();
        const contact = String(row?.nombre_contacto || '').trim();

        if (custom) return custom;
        if (contact) return contact;

        if (channel === 'instagram') {
            const username = String(row?.username_whatsapp || '').trim();
            return username
                ? '@' + username.replace(/^@+/, '')
                : 'Instagram ' + Number(row?.id || 0);
        }

        if (channel === 'whatsapp') {
            return String(row?.telefono || '').trim()
                || 'WhatsApp ' + Number(row?.id || 0);
        }

        return messengerName(row);
    }

    function coreRowHtml(row, channel, selected) {
        const name = hybridConversationName(row, channel);
        const search = currentQuery();
        const preview = row.busqueda_coincidencia
            ? 'Coincidencia: ' + row.busqueda_coincidencia
            : (row.ultimo_mensaje_preview || '[Mensaje]');
        const outgoing = row.ultimo_mensaje_direccion === 'saliente';
        const icon = channel === 'instagram' ? 'IG' : 'W';
        const label = channel === 'instagram' ? 'Instagram' : 'WhatsApp';

        return `
            <button
                type="button"
                class="wa-conversation-item ${selected ? 'is-selected' : ''} ${Number(row.no_leidos) > 0 ? 'has-unread' : ''}"
                data-wa-conversation="${Number(row.id)}"
                data-wa-channel="${channel}"
                data-sigoi-hybrid-row="1"
            >
                <div class="wa-avatar wa-avatar--channel">
                    ${escapeHtml(initials(name))}
                    <span class="wa-channel-mark is-${channel}" title="${label}">${icon}</span>
                </div>
                <div class="wa-conversation-copy">
                    <div class="wa-conversation-top">
                        <strong>${highlight(name, search)}</strong>
                        <time>${escapeHtml(formatDateTime(row.ultimo_mensaje_en, true))}</time>
                    </div>
                    <div class="wa-conversation-preview">
                        <span>${outgoing ? 'Tú: ' : ''}${highlight(preview, search)}</span>
                        ${Number(row.no_leidos) > 0 ? `<b>${Number(row.no_leidos) > 99 ? '99+' : Number(row.no_leidos)}</b>` : ''}
                    </div>
                    <div class="wa-conversation-flags">
                        <span class="wa-conversation-channel is-${channel}">${label}</span>
                        ${Number(row.requiere_humano) === 1 ? '<span class="is-pending">Pendiente</span>' : ''}
                        ${row.asignado_nombre ? `<span>${escapeHtml(row.asignado_nombre)}</span>` : ''}
                        ${row.estado === 'cerrada' ? '<span class="is-resolved">Resuelta</span>' : ''}
                    </div>
                </div>
            </button>
        `;
    }

    function hybridRowKey(channel, id) {
        return String(channel || 'whatsapp') + ':' + Number(id || 0);
    }

    function domHybridRowKey(node) {
        if (!node) return '';

        const channel = node.dataset.waMessengerConversation
            ? 'messenger'
            : String(node.dataset.waChannel || 'whatsapp');

        const id = Number(
            node.dataset.waMessengerConversation
            || node.dataset.waConversation
            || 0
        );

        return hybridRowKey(channel, id);
    }

    function hybridItemSignature(item) {
        const row = item?.row || {};

        return JSON.stringify([
            item?.channel || '',
            Number(row.id || 0),
            row.nombre_personalizado || '',
            row.nombre_contacto || '',
            row.telefono || '',
            row.username_whatsapp || '',
            row.psid || '',
            row.estado || '',
            Number(row.requiere_humano || 0),
            Number(row.no_leidos || 0),
            row.ultimo_mensaje_en || '',
            row.ultimo_mensaje_direccion || '',
            row.ultimo_mensaje_preview || '',
            row.busqueda_coincidencia || row.match_preview || '',
            row.asignado_nombre || '',
            row.origen_fuente || '',
            row.origen_ad_id || '',
            row.origen_ref || ''
        ]);
    }

    function createHybridNode(item, selected) {
        const html = item.channel === 'messenger'
            ? rowHtml(item.row, selected)
            : coreRowHtml(item.row, item.channel, selected);

        const holder = document.createElement('div');
        holder.innerHTML = html.trim();

        const node = holder.firstElementChild;
        if (node) {
            node.dataset.sigoiHybridSignature = hybridItemSignature(item);
        }

        return node;
    }

    function currentHybridSelectedKey(list) {
        if (state.hybridSelectedKey) {
            return state.hybridSelectedKey;
        }

        return domHybridRowKey(
            list?.querySelector('.wa-conversation-item.is-selected')
        );
    }

    function applyHybridSelection(list, selectedKey) {
        if (!list) return;

        list.querySelectorAll('.wa-conversation-item').forEach(node => {
            node.classList.toggle(
                'is-selected',
                domHybridRowKey(node) === selectedKey
            );
        });
    }

    function syncHybridList(list, merged) {
        if (!list) return;

        const previousScrollTop = list.scrollTop;
        const selectedKey = currentHybridSelectedKey(list);
        const wantedKeys = new Set();
        const existing = new Map();

        list.querySelectorAll('.wa-conversation-item').forEach(node => {
            const key = domHybridRowKey(node);
            if (key) existing.set(key, node);
        });

        const desiredNodes = [];

        merged.forEach(item => {
            const key = hybridRowKey(item.channel, item.row.id);
            wantedKeys.add(key);

            const signature = hybridItemSignature(item);
            const selected = key === selectedKey;
            let node = existing.get(key) || null;

            if (!node || node.dataset.sigoiHybridSignature !== signature) {
                const replacement = createHybridNode(item, selected);
                if (!replacement) return;

                if (node && node.parentNode === list) {
                    node.replaceWith(replacement);
                }

                node = replacement;
                existing.set(key, node);
            } else {
                node.classList.toggle('is-selected', selected);
            }

            desiredNodes.push(node);
        });

        existing.forEach((node, key) => {
            if (!wantedKeys.has(key) && node.parentNode === list) {
                node.remove();
            }
        });

        list.querySelectorAll('.wa-list-loading,.wa-list-empty').forEach(node => node.remove());

        desiredNodes.forEach((node, index) => {
            const current = list.children[index];
            if (current !== node) {
                list.insertBefore(node, current || null);
            }
        });

        applyHybridSelection(list, selectedKey);
        list.scrollTop = previousScrollTop;
    }

    function restoreHybridFromCache() {
        if (!isHybridAllMode()) return;

        const list = $('waConversationList');
        const merged = Array.isArray(state.hybridMergedCache)
            ? state.hybridMergedCache
            : [];

        if (!list || !merged.length) return;

        syncHybridList(list, merged);
        updateCombinedIndicators();
        setAllFilterUi();
    }

    function restoreHybridAfterCorePaint() {
        /*
         * El click de WhatsApp/Instagram es procesado también por
         * whatsapp.js. Ese controlador puede reconstruir la lista solo
         * con los canales base. Restauramos la lista combinada desde
         * memoria al terminar el mismo evento, sin esperar una consulta
         * de red. Así Messenger no desaparece durante el cambio de chat.
         */
        queueMicrotask(() => {
            restoreHybridFromCache();

            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(() => restoreHybridFromCache());
            }
        });

        scheduleHybridAppend(120);
    }

    function messengerConversationSignature(data) {
        const c = data?.conversacion || {};
        const messages = Array.isArray(data?.mensajes) ? data.mensajes : [];

        return JSON.stringify({
            id: Number(c.id || 0),
            nombre: c.nombre_personalizado || c.nombre_contacto || '',
            estado: c.estado || '',
            pendiente: Number(c.requiere_humano || 0),
            asignado: Number(c.asignado_a || 0),
            notas: c.notas_contacto || '',
            ultimo: c.ultimo_mensaje_en || '',
            total: Number(c.total_mensajes || messages.length),
            window: data?.ventana_24h?.active ? 1 : 0,
            send: data?.send_enabled ? 1 : 0,
            messages: messages.map(message => [
                Number(message.id || 0),
                message.estado || message.status || '',
                message.direccion || '',
                message.tipo || '',
                message.creado_en || '',
                message.contenido || '',
                message.media_url || message.archivo_url || ''
            ])
        });
    }

    async function appendMessengerToAll() {
        if (
            !state.allowed
            || !isHybridAllMode()
        ) {
            return;
        }

        if (state.hybridBusy) return;
        state.hybridBusy = true;

        try {
            const jobs = [];

            if (canWhatsApp) {
                jobs.push(fetchCoreChannelRows('whatsapp'));
            } else {
                jobs.push(Promise.resolve([]));
            }

            if (canInstagram) {
                jobs.push(fetchCoreChannelRows('instagram'));
            } else {
                jobs.push(Promise.resolve([]));
            }

            jobs.push(fetchMessengerRows());

            const [whatsappRows, instagramRows, messengerRows] = await Promise.all(jobs);

            // Conservamos Messenger en memoria para que el filtro cambie al instante.
            state.rows = messengerRows;

            const merged = [
                ...whatsappRows.map(row => ({ row, channel: 'whatsapp' })),
                ...instagramRows.map(row => ({ row, channel: 'instagram' })),
                ...messengerRows.map(row => ({ row, channel: 'messenger' }))
            ];

            merged.sort((a, b) => {
                const ad = parseDate(a.row.ultimo_mensaje_en)?.getTime() || 0;
                const bd = parseDate(b.row.ultimo_mensaje_en)?.getTime() || 0;

                if (bd !== ad) return bd - ad;

                return Number(b.row.id || 0) - Number(a.row.id || 0);
            });

            state.hybridMergedCache = merged;
            state.hybridCacheAt = Date.now();

            const list = $('waConversationList');
            if (!list) return;

            if (!merged.length) {
                list.innerHTML = `
                    <div class="wa-list-empty">
                        <strong>No hay conversaciones</strong>
                        <span>Prueba otro filtro o espera nuevos mensajes.</span>
                    </div>
                `;
                updateCombinedIndicators();
                return;
            }

            /*
             * 5.6: sincronización incremental.
             * Conservamos los nodos que no cambiaron, la selección y el
             * scroll. Así el polling no reconstruye 80+ chats cada ciclo.
             */
            syncHybridList(list, merged);
            updateCombinedIndicators();

        } catch (_) {
            /*
             * Una falla temporal de un canal no debe bloquear
             * la bandeja principal.
             */
        } finally {
            state.hybridBusy = false;
        }
    }

    function setMessengerFilterUi() {
        const row = ensureChannelRow();

        row?.querySelectorAll('[data-wa-channel]')
            .forEach(button => {
                button.classList.toggle(
                    'is-active',
                    button.dataset.waChannel === 'messenger'
                );
            });
    }


    function setAllFilterUi() {
        const row = ensureChannelRow();

        row?.querySelectorAll('[data-wa-channel]')
            .forEach(button => {
                button.classList.toggle(
                    'is-active',
                    button.dataset.waChannel === 'all'
                );
            });
    }

    async function loadMessengerList(
        preserveSelection = true
    ) {
        if (state.busy) return;

        state.busy = true;

        try {
            state.rows = await fetchMessengerRows();

            const stillExists = state.selectedId
                && state.rows.some(
                    row => Number(row.id) === Number(state.selectedId)
                );

            if (!preserveSelection || !stillExists) {
                state.selectedId = state.rows.length
                    ? Number(state.rows[0].id)
                    : null;
            }

            renderMessengerList();

            if (state.selectedId) {
                await loadMessengerConversation(
                    state.selectedId,
                    true
                );
            } else {
                showEmpty();
            }

        } catch (error) {
            toast(error.message, true);
        } finally {
            state.busy = false;
        }
    }

    function renderMessengerList() {
        const list = $('waConversationList');

        if (!list) return;

        if (!state.rows.length) {
            list.innerHTML = `
                <div class="wa-list-empty">
                    <strong>No hay conversaciones de Messenger</strong>
                    <span>
                        ${
                            state.configured
                                ? 'Prueba otro filtro o espera nuevos mensajes.'
                                : 'Primero conecta la página de Facebook con Meta.'
                        }
                    </span>
                </div>
            `;

            if ($('waInboxCount')) {
                $('waInboxCount').textContent = '0';
            }

            return;
        }

        list.innerHTML = state.rows
            .map(
                row => rowHtml(
                    row,
                    Number(row.id) === Number(state.selectedId)
                )
            )
            .join('');

        if ($('waInboxCount')) {
            $('waInboxCount').textContent = String(
                state.rows.length
            );
        }

        updateCombinedIndicators();
    }

    function showEmpty() {
        $('waChatEmpty')?.classList.remove('hidden');
        $('waChatView')?.classList.add('hidden');
        $('waContactEmpty')?.classList.remove('hidden');
        $('waContactView')?.classList.add('hidden');

        const empty = $('waChatEmpty');
        const title = empty?.querySelector('h2');
        const copy = empty?.querySelector('p');
        if (title) title.textContent = 'Selecciona una conversación';
        if (copy) copy.textContent = 'Aquí podrás ver el historial completo y responder desde S.I.G.O.I.';

        state.selected = null;
    }

    function showMessengerSwitchingState() {
        const list = $('waConversationList');

        if (Array.isArray(state.rows) && state.rows.length) {
            const exists = state.selectedId && state.rows.some(
                row => Number(row.id) === Number(state.selectedId)
            );

            if (!exists) {
                state.selectedId = Number(state.rows[0].id || 0) || null;
            }

            renderMessengerList();

            if (
                state.selected
                && Number(state.selected?.conversacion?.id || 0) === Number(state.selectedId || 0)
            ) {
                renderConversation(state.selected, true);
                return;
            }
        } else if (list) {
            list.innerHTML = '<div class="wa-list-loading">Cargando Messenger...</div>';
            if ($('waInboxCount')) $('waInboxCount').textContent = '…';
        }

        $('waChatEmpty')?.classList.remove('hidden');
        $('waChatView')?.classList.add('hidden');
        $('waContactEmpty')?.classList.remove('hidden');
        $('waContactView')?.classList.add('hidden');

        const empty = $('waChatEmpty');
        const title = empty?.querySelector('h2');
        const copy = empty?.querySelector('p');
        if (title) title.textContent = 'Cargando Messenger...';
        if (copy) copy.textContent = 'Actualizando conversaciones del canal.';
    }

    function limaGreeting(now = new Date()) {
        let hour = null;

        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: 'America/Lima',
                hour: '2-digit',
                hourCycle: 'h23'
            }).formatToParts(now);

            const hourPart = parts.find(
                part => part.type === 'hour'
            );

            if (hourPart) {
                hour = Number(hourPart.value);
            }
        } catch (_) {}

        if (!Number.isFinite(hour)) {
            hour = (now.getUTCHours() + 19) % 24;
        }

        if (hour >= 5 && hour <= 11) {
            return 'Buenos días';
        }

        if (hour >= 12 && hour <= 18) {
            return 'Buenas tardes';
        }

        return 'Buenas noches';
    }

    function resolveQuickText(content) {
        return String(content || '').replace(
            /\{\{\s*saludo\s*\}\}/gi,
            limaGreeting()
        );
    }

    async function loadQuickRows() {
        if (state.quickLoaded) {
            return state.quickRows;
        }

        const response = await fetch(
            'modules/whatsapp/quick-replies.php?active=1',
            {
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message
                || 'No se pudieron cargar las respuestas rápidas.'
            );
        }

        state.quickRows = Array.isArray(data.respuestas)
            ? data.respuestas.filter(
                row => Number(row.activa) === 1
            )
            : [];

        state.quickLoaded = true;

        return state.quickRows;
    }

    function renderMessengerQuickPicker() {
        const list = $('waQuickPickerList');

        if (!list) return;

        const query = String(
            $('waQuickPickerSearch')?.value || ''
        )
            .trim()
            .toLowerCase()
            .replace(/^\//, '');

        const rows = state.quickRows.filter(row => {
            if (!query) return true;

            return [
                row.titulo,
                row.atajo,
                row.categoria,
                row.contenido
            ].some(
                value => String(value || '')
                    .toLowerCase()
                    .includes(query)
            );
        });

        if (!rows.length) {
            list.innerHTML =
                '<div class="wa-empty-mini">No hay respuestas que coincidan.</div>';
            return;
        }

        let category = null;
        let html = '';

        rows.forEach(row => {
            const current = row.categoria || 'General';

            if (current !== category) {
                html += `
                    <div class="wa-quick-picker-category">
                        ${escapeHtml(current)}
                    </div>
                `;
                category = current;
            }

            html += `
                <button
                    type="button"
                    class="wa-quick-picker-item"
                    data-messenger-use-quick="${Number(row.id)}"
                >
                    <div>
                        <strong>${escapeHtml(row.titulo)}</strong>
                        <code>/${escapeHtml(row.atajo || '')}</code>
                    </div>
                    <span>${escapeHtml(resolveQuickText(row.contenido))}</span>
                </button>
            `;
        });

        list.innerHTML = html;
    }

    async function openMessengerQuickPicker() {
        try {
            await loadQuickRows();

            $('waQuickPicker')?.classList.remove('hidden');

            if ($('waQuickPickerSearch')) {
                $('waQuickPickerSearch').value = '';
            }

            renderMessengerQuickPicker();

            setTimeout(
                () => $('waQuickPickerSearch')?.focus(),
                20
            );

        } catch (error) {
            toast(error.message, true);
        }
    }

    function closeMessengerQuickPicker() {
        $('waQuickPicker')?.classList.add('hidden');
    }

    function renderMedia(message) {
        if (!message.media) return '';

        const url = escapeHtml(message.media.url || '');
        const filename = escapeHtml(
            message.media.filename || 'Archivo'
        );
        const mime = String(message.media.mime || '');

        if (
            message.tipo === 'image'
            || mime.indexOf('image/') === 0
        ) {
            return `
                <button
                    type="button"
                    class="wa-media-image"
                    data-wa-image="${url}"
                >
                    <img src="${url}" alt="${filename}" loading="lazy">
                </button>
            `;
        }

        if (
            message.tipo === 'video'
            || mime.indexOf('video/') === 0
        ) {
            return `
                <video class="wa-media-video" controls preload="metadata">
                    <source src="${url}" type="${escapeHtml(mime)}">
                </video>
            `;
        }

        if (
            message.tipo === 'audio'
            || mime.indexOf('audio/') === 0
        ) {
            return `
                <audio class="wa-media-audio" controls preload="metadata">
                    <source src="${url}" type="${escapeHtml(mime)}">
                </audio>
            `;
        }

        return `
            <a
                class="wa-document-card"
                href="${url}"
                target="_blank"
                rel="noopener"
            >
                <span class="wa-document-icon">DOC</span>
                <span>
                    <strong>${filename}</strong>
                    <small>
                        ${escapeHtml(formatFileSize(message.media.size))}
                        ${mime ? ' · ' + escapeHtml(mime) : ''}
                    </small>
                </span>
                <b>↗</b>
            </a>
        `;
    }

    function renderMessage(message) {
        const incoming = message.direccion === 'entrante';

        const body = String(message.contenido || '').trim();

        const media = renderMedia(message);

        const statusMap = {
            sent: 'Enviado',
            delivered: 'Entregado',
            read: 'Visto',
            failed: 'Falló',
            error: 'Falló',
            recibido: 'Recibido'
        };

        const rawStatus = String(
            message.estado_envio || ''
        ).toLowerCase();

        const status = incoming
            ? ''
            : (statusMap[rawStatus] || rawStatus);

        const failed = ['failed', 'error'].includes(
            rawStatus
        );

        return `
            <div class="wa-message-row ${incoming ? 'is-incoming' : 'is-outgoing'}">
                <article class="wa-message-bubble ${
                    message.origen === 'automatizacion'
                        ? 'is-auto'
                        : (['messenger_app', 'meta_sync'].includes(message.origen) ? 'is-messenger' : '')
                }">
                    ${
                        !incoming
                            ? `<div class="wa-message-origin">${escapeHtml(message.origen_label || 'S.I.G.O.I.')}</div>`
                            : ''
                    }

                    ${media}

                    ${
                        body
                            ? `<div class="wa-message-text">${escapeHtml(body).replace(/\n/g, '<br>')}</div>`
                            : ''
                    }

                    <div class="wa-message-meta">
                        <time>${escapeHtml(formatDateTime(message.creado_en, true))}</time>
                        ${
                            status
                                ? `<span class="wa-message-status ${failed ? 'is-failed' : ''}">${escapeHtml(status)}</span>`
                                : ''
                        }
                    </div>

                    ${
                        message.error_envio
                            ? `<div class="wa-message-error">${escapeHtml(message.error_envio)}</div>`
                            : ''
                    }
                </article>
            </div>
        `;
    }

    function renderMessages(messages) {
        const box = $('waChatMessages');

        if (!box) return;

        if (!messages.length) {
            box.innerHTML =
                '<div class="wa-chat-no-messages">No hay mensajes en esta conversación.</div>';
            return;
        }

        let lastDay = '';
        let html = '';

        messages.forEach(message => {
            const day = formatDayLabel(message.creado_en);

            if (day !== lastDay) {
                html += `
                    <div class="wa-date-divider">
                        <span>${escapeHtml(day)}</span>
                    </div>
                `;
                lastDay = day;
            }

            html += renderMessage(message);
        });

        box.innerHTML = html;
    }

    function renderConversation(data, preserveScroll) {
        const c = data.conversacion || {};
        const rows = data.mensajes || [];
        const name = messengerName(c);

        state.selected = data;
        state.selectedId = Number(c.id || state.selectedId);

        const messagesBox = $('waChatMessages');

        const nearBottom = messagesBox
            ? (
                messagesBox.scrollHeight
                - messagesBox.scrollTop
                - messagesBox.clientHeight
            ) < 140
            : true;

        $('waChatEmpty')?.classList.add('hidden');
        $('waChatView')?.classList.remove('hidden');
        $('waContactEmpty')?.classList.add('hidden');
        $('waContactView')?.classList.remove('hidden');

        if ($('waChatAvatar')) {
            $('waChatAvatar').textContent = initials(name);
        }

        if ($('waDetailAvatar')) {
            $('waDetailAvatar').textContent = initials(name);
        }

        if ($('waChatName')) {
            $('waChatName').textContent = name;
        }

        if ($('waDetailName')) {
            $('waDetailName').textContent = name;
        }

        if ($('waChatPhone')) {
            $('waChatPhone').textContent = 'Messenger';
        }

        if ($('waDetailPhone')) {
            $('waDetailPhone').textContent = 'Messenger';
        }

        if ($('waDetailUsername')) {
            $('waDetailUsername').textContent = '';
            $('waDetailUsername').classList.add('hidden');
        }

        if ($('waDetailWhatsappName')) {
            const detected = String(
                c.nombre_contacto || ''
            ).trim();

            $('waDetailWhatsappName').textContent = detected
                ? 'Perfil de Facebook: ' + detected
                : '';

            $('waDetailWhatsappName').classList.toggle(
                'hidden',
                !detected
            );
        }

        if ($('waChatChannelBadge')) {
            $('waChatChannelBadge').textContent = 'Messenger';
            $('waChatChannelBadge').className =
                'wa-chat-channel-badge is-messenger';
        }

        if ($('waChatState')) {
            $('waChatState').textContent = c.estado === 'cerrada'
                ? 'Resuelta'
                : (
                    Number(c.requiere_humano) === 1
                        ? 'Pendiente'
                        : 'Abierta'
                );

            $('waChatState').className =
                'wa-chat-state '
                + (
                    c.estado === 'cerrada'
                        ? 'is-resolved'
                        : (
                            Number(c.requiere_humano) === 1
                                ? 'is-pending'
                                : ''
                        )
                );
        }

        if ($('waChatAssignment')) {
            $('waChatAssignment').textContent =
                c.asignado_nombre || 'Sin asignar';
        }

        if ($('waContactCustomName')) {
            $('waContactCustomName').value =
                c.nombre_personalizado || '';
        }

        if ($('waContactNotes')) {
            $('waContactNotes').value =
                c.notas_contacto || '';
        }

        const referralSource = String(c.origen_fuente || '').toUpperCase();
        const referralAdId = String(c.origen_ad_id || '').trim();
        const referralRef = String(c.origen_ref || '').trim();
        const referralUri = String(c.origen_referer_uri || '').trim();
        const isAdOrigin = referralSource === 'ADS' || referralAdId !== '';

        if ($('waOriginSection')) {
            $('waOriginSection').classList.toggle('hidden', !isAdOrigin);
        }

        if (isAdOrigin) {
            $('waOriginImage')?.classList.add('hidden');

            if ($('waOriginTitle')) {
                $('waOriginTitle').textContent = 'Anuncio de Meta';
            }

            if ($('waOriginMeta')) {
                const details = ['Messenger'];
                if (referralAdId) details.push('ID ' + referralAdId);
                else if (referralRef) details.push(referralRef);
                $('waOriginMeta').textContent = details.join(' · ');
            }

            if ($('waOriginLink')) {
                const validUri = /^https?:\/\//i.test(referralUri);
                $('waOriginLink').classList.toggle('hidden', !validUri);
                if (validUri) {
                    $('waOriginLink').href = referralUri;
                    $('waOriginLink').textContent = 'Ver origen ↗';
                }
            }
        }

        if ($('waDetailState')) {
            $('waDetailState').textContent =
                c.estado === 'cerrada'
                    ? 'Resuelta'
                    : 'Abierta';
        }

        $('waDetailPending')?.classList.toggle(
            'hidden',
            Number(c.requiere_humano) !== 1
        );

        if ($('waDetailFirst')) {
            $('waDetailFirst').textContent =
                formatDateTime(
                    c.primer_mensaje_en || c.creado_en,
                    false
                );
        }

        if ($('waDetailLast')) {
            $('waDetailLast').textContent =
                formatDateTime(c.ultimo_mensaje_en, false);
        }

        if ($('waDetailMessages')) {
            $('waDetailMessages').textContent =
                Number(c.total_mensajes || 0);
        }

        if ($('waDetailResponse')) {
            $('waDetailResponse').textContent =
                formatDuration(
                    c.tiempo_primera_respuesta_humana_seg
                );
        }

        if ($('waDetailChannelTitle')) {
            $('waDetailChannelTitle').textContent =
                'Messenger + S.I.G.O.I.';

            $('waDetailChannelText').textContent =
                'Mensajes de la página de Facebook mediante Meta Messenger';

            $('waDetailChannelCard')?.classList.remove(
                'is-instagram'
            );

            $('waDetailChannelCard')?.classList.add(
                'is-messenger'
            );
        }

        if ($('waAssigneeSelect')) {
            $('waAssigneeSelect').innerHTML =
                '<option value="0">Sin asignar</option>'
                + (data.usuarios || [])
                    .map(
                        user => `
                            <option value="${Number(user.id)}">
                                ${escapeHtml(user.nombre)}
                                ·
                                ${escapeHtml(user.rol)}
                            </option>
                        `
                    )
                    .join('');

            $('waAssigneeSelect').value =
                String(c.asignado_a || 0);
        }

        if ($('waResolveBtn')) {
            $('waResolveBtn').textContent =
                c.estado === 'cerrada'
                    ? 'Reabrir'
                    : 'Marcar resuelta';
        }

        const windowActive =
            !!data.ventana_24h?.active;

        const sendEnabled =
            !!data.send_enabled;

        const canReply =
            !!permissions.bandeja_responder;

        const canCompose =
            canReply
            && windowActive
            && sendEnabled;

        $('waRetakeBtn')?.classList.add('hidden');

        if (!sendEnabled) {
            $('waWindowAlert')?.classList.remove('hidden');

            if ($('waWindowAlertTitle')) {
                $('waWindowAlertTitle').textContent =
                    state.configured
                        ? 'Messenger conectado en modo recepción'
                        : 'Messenger todavía no está configurado';
            }

            if ($('waWindowAlertText')) {
                $('waWindowAlertText').textContent =
                    state.configured
                        ? 'Los mensajes ya pueden recibirse. Activa MESSENGER_META_SEND_ENABLED cuando terminemos la prueba de envío.'
                        : 'Completa Page ID, Page Access Token, App Secret y Verify Token en config/messenger_meta.php.';
            }

        } else if (!windowActive) {
            $('waWindowAlert')?.classList.remove('hidden');

            const lastInbound =
                data.ventana_24h?.last_inbound
                    ? formatDateTime(
                        data.ventana_24h.last_inbound,
                        false
                    )
                    : 'sin fecha disponible';

            if ($('waWindowAlertTitle')) {
                $('waWindowAlertTitle').textContent =
                    'Ventana de Messenger cerrada';
            }

            if ($('waWindowAlertText')) {
                $('waWindowAlertText').textContent =
                    'Último mensaje del usuario: '
                    + lastInbound
                    + '. El historial sigue disponible, pero no se enviará texto libre fuera de la ventana.';
            }

        } else {
            $('waWindowAlert')?.classList.add('hidden');
        }

        if ($('waComposerText')) {
            $('waComposerText').disabled = !canCompose;
        }

        if ($('waSendBtn')) {
            $('waSendBtn').disabled = !canCompose;
        }

        if ($('waAttachBtn')) {
            const mediaAllowed =
                canCompose
                && !!data.can_send_media;

            $('waAttachBtn').disabled = !mediaAllowed;
            $('waAttachBtn').classList.toggle(
                'hidden',
                !mediaAllowed
            );
        }

        if ($('waFileInput')) {
            $('waFileInput').accept =
                'image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt';
        }

        if ($('waQuickReplyBtn')) {
            $('waQuickReplyBtn').disabled = !canCompose;
            $('waQuickReplyBtn').classList.toggle(
                'hidden',
                !canCompose
            );
        }

        if (!canCompose) {
            closeMessengerQuickPicker();
        }

        if ($('waComposerHint')) {
            $('waComposerHint').textContent = canCompose
                ? 'Enter para enviar · Shift+Enter para salto de línea'
                : (
                    sendEnabled
                        ? 'La conversación está fuera de la ventana de Messenger.'
                        : 'Recepción activa · envío de Messenger aún desactivado.'
                );
        }

        if ($('waComposerWindow')) {
            $('waComposerWindow').textContent =
                sendEnabled
                    ? (
                        windowActive
                            ? 'Ventana de 24 h activa'
                            : 'Ventana de 24 h cerrada'
                    )
                    : 'Solo recepción';

            $('waComposerWindow').classList.toggle(
                'is-closed',
                !canCompose
            );
        }

        renderMessages(rows);

        if (messagesBox && (!preserveScroll || nearBottom)) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }

        if (state.mode === 'all') {
            state.hybridSelectedKey = hybridRowKey('messenger', state.selectedId);
            applyHybridSelection($('waConversationList'), state.hybridSelectedKey);
        } else {
            renderMessengerList();
        }
    }

    function applyMessengerConversationData(
        id,
        data,
        preserveScroll
    ) {
        state.selectedId = Number(id);
        storageSet(
            storageKeys.messengerConversation,
            state.selectedId
        );

        const signature = messengerConversationSignature(data);
        const sameConversation = Number(state.lastConversationId) === Number(id);

        if (
            preserveScroll
            && sameConversation
            && signature === state.lastConversationSignature
        ) {
            state.selected = data;
            return false;
        }

        state.lastConversationId = Number(id);
        state.lastConversationSignature = signature;
        renderConversation(data, preserveScroll);
        return true;
    }

    function syncMessengerConversationInBackground(
        id,
        force = false
    ) {
        id = Number(id || 0);
        if (!id) return;

        const now = Date.now();
        const last = Number(state.metaSyncAt.get(id) || 0);

        /*
         * La consulta a Meta es un respaldo para respuestas automáticas/
         * ecos faltantes. No debe ejecutarse en cada polling ni bloquear UI.
         */
        if (!force && now - last < 20000) {
            return;
        }

        if (state.metaSyncInFlight.has(id)) {
            return;
        }

        state.metaSyncAt.set(id, now);
        state.metaSyncInFlight.add(id);

        messengerApi(
            'conversation.php?id='
            + encodeURIComponent(id)
            + '&sync_meta=1'
        )
            .then(data => {
                if (Number(state.selectedId) !== id) {
                    return;
                }

                applyMessengerConversationData(
                    id,
                    data,
                    true
                );

                if (Number(data?.history_sync?.imported || 0) > 0) {
                    scheduleHybridAppend(0);
                }
            })
            .catch(() => {
                /* Una falla de Meta nunca debe bloquear el chat local. */
            })
            .finally(() => {
                state.metaSyncInFlight.delete(id);
            });
    }

    async function loadMessengerConversation(
        id,
        preserveScroll,
        options
    ) {
        if (!id) return;

        options = options || {};

        /* Primero BD local: no hace ninguna llamada a Graph API. */
        const data = await messengerApi(
            'conversation.php?id='
            + encodeURIComponent(id)
            + '&sync_meta=0'
        );

        applyMessengerConversationData(
            id,
            data,
            preserveScroll
        );

        if (options.syncMeta !== false) {
            syncMessengerConversationInBackground(
                id,
                !!options.forceMetaSync
            );
        }
    }

    async function refreshMessengerContext(preserveSelection = true) {
        if (state.mode === 'all') {
            await appendMessengerToAll();

            if (runtime.active && state.selectedId) {
                await loadMessengerConversation(
                    state.selectedId,
                    true
                );
            }

            return;
        }

        await loadMessengerList(preserveSelection);
    }

    async function conversationAction(
        action,
        extra
    ) {
        if (!state.selectedId) return;

        try {
            const data = await messengerPost(
                'conversation-update.php',
                Object.assign({
                    id: state.selectedId,
                    action
                }, extra || {})
            );

            toast(data.message || 'Actualizado.');

            await refreshMessengerContext(true);

        } catch (error) {
            toast(error.message, true);
        }
    }

    async function saveContact() {
        if (!state.selectedId) return;

        const button = $('waSaveContactBtn');

        if (button) {
            button.disabled = true;
        }

        try {
            const data = await messengerPost(
                'conversation-update.php',
                {
                    id: state.selectedId,
                    action: 'contact_save',
                    nombre_personalizado:
                        $('waContactCustomName')?.value || '',
                    notas_contacto:
                        $('waContactNotes')?.value || ''
                }
            );

            toast(
                data.message || 'Contacto guardado.'
            );

            await refreshMessengerContext(true);

        } catch (error) {
            toast(error.message, true);
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    function clearFile() {
        state.selectedFile = null;

        if ($('waFileInput')) {
            $('waFileInput').value = '';
        }

        $('waFileChip')?.classList.add('hidden');
    }

    async function sendMessage() {
        if (
            !state.selectedId
            || !permissions.bandeja_responder
        ) {
            return;
        }

        const text = String(
            $('waComposerText')?.value || ''
        ).trim();

        const file = $('waFileInput')?.files?.[0]
            || state.selectedFile;

        if (!text && !file) {
            return;
        }

        const button = $('waSendBtn');

        if (button) {
            button.disabled = true;
        }

        try {
            if (file) {
                const form = new FormData();

                form.append(
                    'conversation_id',
                    String(state.selectedId)
                );
                form.append('caption', text);
                form.append('file', file);

                const response = await fetch(
                    'modules/messenger/send-media.php',
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token':
                                window.APP_CSRF_TOKEN
                        },
                        body: form
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(
                        data.message
                        || 'No se pudo enviar el archivo.'
                    );
                }

                toast(data.message || 'Archivo enviado.');

                clearFile();

            } else {
                const data = await messengerPost(
                    'send-text.php',
                    {
                        conversation_id: state.selectedId,
                        body: text
                    }
                );

                toast(data.message || 'Mensaje enviado.');
            }

            if ($('waComposerText')) {
                $('waComposerText').value = '';
            }

            await refreshMessengerContext(true);

        } catch (error) {
            toast(error.message, true);
        } finally {
            if (button) {
                const active =
                    !!state.selected?.ventana_24h?.active
                    && !!state.selected?.send_enabled;

                button.disabled = !active;
            }
        }
    }

    function activateMessenger(preserveSelection = false) {
        state.hybridSelectedKey = '';
        state.mode = 'messenger';
        runtime.active = true;
        runtime.mode = 'messenger';
        storageSet(storageKeys.channel, 'messenger');

        setMessengerFilterUi();

        // Cambio visual inmediato: nunca dejamos el chat del canal anterior
        // mientras esperamos la petición HTTP de Messenger.
        showMessengerSwitchingState();

        loadMessengerList(!!preserveSelection);
    }

    function activateHybridAll() {
        state.mode = 'all';
        runtime.active = false;
        runtime.mode = 'all';
        storageSet(storageKeys.channel, 'all');

        setAllFilterUi();
        scheduleHybridAppend(0);
    }

    function deactivateMessenger(mode) {
        state.mode = mode || 'all';
        runtime.active = false;
        runtime.mode = state.mode;
        storageSet(storageKeys.channel, state.mode);

        $('waDetailChannelCard')?.classList.remove('is-messenger');
        closeMessengerQuickPicker();

        /*
         * WhatsApp.js volverá a tomar control al propagarse el evento
         * del botón/canal correspondiente.
         */
    }

    async function processQueue() {
        if (!state.allowed) return;

        try {
            await messengerApi('queue-process.php');
        } catch (_) {}
    }


    async function manualRefreshMessenger() {
        if (!state.allowed || state.busy) return;

        setMessengerSyncState(true);

        try {
            await processQueue();
            await loadMessengerList(true);
        } catch (error) {
            toast(error.message, true);
        } finally {
            setMessengerSyncState(false);
        }
    }

    async function refreshCapabilities() {
        const data = await messengerApi(
            'capabilities.php'
        );

        state.allowed = !!data.allowed;
        state.configured = !!data.configured;
        state.sendEnabled = !!data.send_enabled;
        state.pageId = String(data.page_id || '');

        if (!state.allowed) {
            return false;
        }

        ensureChannelButtons();

        return true;
    }

    function isMessengerRow(target) {
        return target.closest(
            '[data-wa-messenger-conversation]'
        );
    }

    document.addEventListener(
        'click',
        function (event) {
            if (!state.allowed) return;

            const messengerRow = isMessengerRow(
                event.target
            );

            if (messengerRow) {
                event.preventDefault();
                event.stopImmediatePropagation();

                state.selectedId = Number(
                    messengerRow.dataset
                        .waMessengerConversation
                );
                state.hybridSelectedKey = hybridRowKey('messenger', state.selectedId);

                storageSet(
                    storageKeys.messengerConversation,
                    state.selectedId
                );

                if (isHybridAllMode()) {
                    state.mode = 'all';
                    runtime.active = true;
                    runtime.mode = 'all';
                    storageSet(storageKeys.channel, 'all');
                    setAllFilterUi();
                    applyHybridSelection($('waConversationList'), state.hybridSelectedKey);
                    loadMessengerConversation(
                        state.selectedId,
                        false
                    );
                    scheduleHybridAppend(80);
                } else {
                    activateMessenger(true);
                }

                return;
            }

            const channelButton = event.target.closest(
                '#waInboxChannels [data-wa-channel]'
            );

            if (channelButton) {
                const channel =
                    channelButton.dataset.waChannel;

                if (channel === 'messenger') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    activateMessenger();
                    return;
                }

                if (channel === 'all') {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    activateHybridAll();
                    return;
                }

                deactivateMessenger(channel || 'all');
                return;
            }

            /*
             * Si estábamos en Messenger y se pulsa una conversación
             * normal de WhatsApp/Instagram, reactivamos su controlador
             * antes de que el listener original procese el click.
             */
            const coreConversation = event.target.closest(
                '[data-wa-conversation]:not([data-wa-messenger-conversation])'
            );

            if (coreConversation) {
                state.hybridSelectedKey = domHybridRowKey(coreConversation);
            }

            if (coreConversation && runtime.active) {
                const wasAll = state.mode === 'all';

                deactivateMessenger(
                    wasAll
                        ? 'all'
                        : (coreConversation.dataset.waChannel || 'all')
                );

                if (wasAll) {
                    setAllFilterUi();
                    applyHybridSelection($('waConversationList'), state.hybridSelectedKey);
                    restoreHybridAfterCorePaint();
                }

                return;
            }

            if (coreConversation && runtime.mode === 'all') {
                applyHybridSelection($('waConversationList'), state.hybridSelectedKey);
                restoreHybridAfterCorePaint();
            }

            if (!runtime.active) {
                if (
                    isHybridAllMode()
                    && event.target.closest('#waManualRefreshBtn')
                ) {
                    scheduleHybridAppend(120);
                    setTimeout(() => scheduleHybridAppend(0), 500);
                }

                return;
            }

            if (event.target.closest('#waManualRefreshBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();

                if (state.mode === 'all') {
                    setMessengerSyncState(true);
                    processQueue()
                        .then(() => appendMessengerToAll())
                        .then(() => {
                            if (state.selectedId) {
                                return loadMessengerConversation(
                                    state.selectedId,
                                    true,
                                    { forceMetaSync: true }
                                );
                            }
                        })
                        .catch(error => toast(error.message, true))
                        .finally(() => setMessengerSyncState(false));
                } else {
                    manualRefreshMessenger();
                }

                return;
            }

            if (event.target.closest('#waSaveContactBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                saveContact();
                return;
            }

            if (event.target.closest('#waResolveBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();

                const c = state.selected?.conversacion;

                if (c) {
                    conversationAction(
                        c.estado === 'cerrada'
                            ? 'reopen'
                            : 'resolve'
                    );
                }

                return;
            }

            if (event.target.closest('#waMarkPendingBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                conversationAction('pending');
                return;
            }

            if (event.target.closest('#waSendBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                sendMessage();
                return;
            }

            if (event.target.closest('#waClearFileBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                clearFile();
                return;
            }

            if (event.target.closest('#waQuickReplyBtn')) {
                event.preventDefault();
                event.stopImmediatePropagation();

                if ($('waQuickPicker')?.classList.contains('hidden')) {
                    openMessengerQuickPicker();
                } else {
                    closeMessengerQuickPicker();
                }

                return;
            }

            if (event.target.closest('#waQuickPickerClose')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                closeMessengerQuickPicker();
                return;
            }

            const quick = event.target.closest(
                '[data-messenger-use-quick]'
            );

            if (quick) {
                event.preventDefault();
                event.stopImmediatePropagation();

                const row = state.quickRows.find(
                    item => Number(item.id)
                        === Number(
                            quick.dataset.messengerUseQuick
                        )
                );

                if (row && $('waComposerText')) {
                    $('waComposerText').value =
                        resolveQuickText(row.contenido);

                    $('waComposerText').dispatchEvent(
                        new Event('input', {
                            bubbles: true
                        })
                    );

                    closeMessengerQuickPicker();
                    $('waComposerText').focus();
                }

                return;
            }
        },
        true
    );

    document.addEventListener(
        'change',
        function (event) {
            if (!runtime.active) return;

            if (event.target.matches('#waAssigneeSelect')) {
                event.stopImmediatePropagation();

                conversationAction(
                    'assign',
                    {
                        usuario_id: Number(
                            event.target.value || 0
                        )
                    }
                );

                return;
            }

            if (event.target.matches('#waFileInput')) {
                event.stopImmediatePropagation();

                const file = event.target.files?.[0]
                    || null;

                state.selectedFile = file;

                if (file) {
                    if ($('waFileName')) {
                        $('waFileName').textContent =
                            file.name;
                    }

                    if ($('waFileSize')) {
                        $('waFileSize').textContent =
                            formatFileSize(file.size);
                    }

                    $('waFileChip')?.classList.remove(
                        'hidden'
                    );
                }
            }
        },
        true
    );

    document.addEventListener(
        'input',
        function (event) {
            if (
                event.target.matches('#waInboxSearch')
                && isHybridAllMode()
            ) {
                scheduleHybridAppend(340);
                return;
            }

            if (!runtime.active) return;

            if (event.target.matches('#waQuickPickerSearch')) {
                event.stopImmediatePropagation();
                renderMessengerQuickPicker();
                return;
            }

            if (event.target.matches('#waInboxSearch')) {
                event.stopImmediatePropagation();

                clearTimeout(
                    state._searchTimer
                );

                state._searchTimer = setTimeout(
                    () => loadMessengerList(false),
                    280
                );
            }
        },
        true
    );

    document.addEventListener(
        'click',
        function (event) {
            if (!event.target.closest('#waInboxFilters')) {
                return;
            }

            const button = event.target.closest(
                '[data-wa-filter]'
            );

            if (!button) return;

            if (isHybridAllMode()) {
                scheduleHybridAppend(120);
                return;
            }

            if (!runtime.active) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            $('waInboxFilters')
                ?.querySelectorAll('[data-wa-filter]')
                .forEach(item => {
                    item.classList.toggle(
                        'is-active',
                        item === button
                    );
                });

            loadMessengerList(false);
        },
        true
    );


    /*
     * Analítica multicanal es la única analítica oficial del módulo.
     * Interceptamos la pestaña interna para evitar mantener dos vistas.
     */
    document.addEventListener(
        'click',
        function (event) {
            const analyticsTab = event.target.closest(
                '[data-wa-tab="analytics"]'
            );

            if (!analyticsTab) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            window.location.href = 'analitica-multicanal.php';
        },
        true
    );

    function applyRequestedWhatsappTab() {
        const params = new URLSearchParams(
            window.location.search
        );

        const requested = String(
            params.get('tab') || ''
        ).toLowerCase();

        if (!['inbox', 'automation', 'library'].includes(requested)) {
            return;
        }

        const button = document.querySelector(
            `[data-wa-tab="${requested}"]`
        );

        if (button && !button.classList.contains('is-active')) {
            button.click();
        }
    }

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                !runtime.active
                || !event.target.matches('#waComposerText')
            ) {
                return;
            }

            if (
                event.key === 'Enter'
                && !event.shiftKey
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                sendMessage();
            }
        },
        true
    );

    async function tick() {
        if (!state.allowed) return;

        if (isHybridAllMode()) {
            await processQueue();
            await appendMessengerToAll();

            if (
                runtime.active
                && state.mode === 'all'
                && state.selectedId
            ) {
                await loadMessengerConversation(
                    state.selectedId,
                    true
                );
            }

            return;
        }

        if (runtime.active) {
            await processQueue();

            if (!state.busy) {
                await loadMessengerList(true);
            }
        }
    }

    async function init() {
        if (
            !document.querySelector('.whatsapp-page')
            || !$('waConversationList')
        ) {
            return;
        }

        applyRequestedWhatsappTab();

        try {
            const allowed = await refreshCapabilities();

            if (!allowed) {
                return;
            }

            installHybridObserver();

            const storedChannel = storageGet(storageKeys.channel);
            const storedConversation = Number(
                storageGet(storageKeys.messengerConversation) || 0
            );

            if (storedConversation > 0) {
                state.selectedId = storedConversation;
            }

            /*
             * El cron será el procesador principal. Mientras se configura,
             * la bandeja también procesa algunos eventos al abrirse.
             */
            await processQueue();

            if (!canWhatsApp && !canInstagram) {
                activateMessenger(true);
            } else if (storedChannel === 'messenger') {
                // Al recargar la página conservamos Messenger y el chat activo.
                activateMessenger(true);
            } else if (
                currentCoreChannel() === 'all'
                || !document.querySelector(
                    '#waInboxChannels [data-wa-channel].is-active'
                )
            ) {
                state.mode = 'all';
                runtime.active = false;
                runtime.mode = 'all';
                storageSet(storageKeys.channel, 'all');
                setAllFilterUi();
                await appendMessengerToAll();
            }

            nativeInterval(
                () => {
                    if (
                        document.hidden
                        || !document.querySelector(
                            '[data-wa-panel="inbox"].is-active'
                        )
                    ) {
                        return;
                    }

                    tick().catch(() => {});
                },
                2500
            );

        } catch (error) {
            console.error('Messenger init:', error);
        }
    }


    function installHybridObserver() {
        const list = $('waConversationList');

        if (!list || state.hybridObserverInstalled) {
            return;
        }

        const observer = new MutationObserver(function () {
            if (!state.allowed || !isHybridAllMode() || state.hybridBusy) {
                return;
            }

            const hasMessenger = !!list.querySelector(
                '[data-wa-messenger-conversation]'
            );

            if (!hasMessenger) {
                restoreHybridFromCache();
                scheduleHybridAppend(120);
            }
        });

        observer.observe(list, {
            childList: true,
            subtree: false
        });

        state.hybridObserverInstalled = true;
        state.hybridObserver = observer;
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            init,
            { once: true }
        );
    } else {
        init();
    }
})();
