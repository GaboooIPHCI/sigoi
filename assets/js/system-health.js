(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const refreshBtn = $('systemHealthRefresh');
    const errorBox = $('systemHealthError');
    let busy = false;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatBytes(bytes) {
        let value = Number(bytes || 0);
        if (!Number.isFinite(value) || value <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let index = 0;
        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index++;
        }
        return (index === 0 ? Math.round(value) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[index];
    }

    function formatDate(value) {
        if (!value) return 'Sin actividad registrada';
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('es-PE', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }

    function statusLabel(status) {
        if (status === 'ok') return 'Operativo';
        if (status === 'warning') return 'Revisar';
        return 'Atención';
    }

    function check(value, goodLabel, badLabel) {
        return '<strong class="system-health-check ' + (value ? 'is-ok' : 'is-warning') + '">' +
            escapeHtml(value ? goodLabel : badLabel) + '</strong>';
    }

    function renderOverall(data) {
        const status = data.system_status || 'warning';
        const labels = {
            ok: 'Todos los componentes principales responden correctamente.',
            warning: 'S.I.G.O.I. está operativo, pero hay elementos que conviene revisar.',
            error: 'Hay al menos un componente principal que requiere atención.'
        };
        $('systemHealthOverall').innerHTML =
            '<span class="system-health-status-dot is-' + escapeHtml(status) + '"></span>' +
            '<div><small>Estado general</small><strong>' + escapeHtml(labels[status] || labels.warning) + '</strong></div>';
    }

    function renderChannels(data) {
        const order = ['whatsapp', 'instagram', 'messenger'];
        $('systemHealthChannels').innerHTML = order.map(function (key) {
            const channel = data.channels && data.channels[key] ? data.channels[key] : {};
            const queue = channel.queue || {};
            const cron = channel.cron || {};
            const webhook = channel.webhook || {};
            return '<article class="system-health-card">' +
                '<div class="system-health-card__head"><div><small>CANAL</small><h2>' + escapeHtml(channel.name || key) + '</h2></div>' +
                '<span class="system-health-channel-badge is-' + escapeHtml(channel.status || 'warning') + '"><i></i>' + escapeHtml(statusLabel(channel.status)) + '</span></div>' +
                '<div class="system-health-metrics">' +
                    '<div class="system-health-metric"><span>Webhook</span><strong>' + escapeHtml(webhook.label || 'Sin datos') + '</strong></div>' +
                    '<div class="system-health-metric"><span>Cron</span><strong>' + escapeHtml(cron.label || 'Sin datos') + '</strong></div>' +
                '</div>' +
                '<div class="system-health-queue"><strong>Cola:</strong> ' + Number(queue.pending || 0) + ' pendientes · ' + Number(queue.processing || 0) + ' procesando · ' + Number(queue.failed || 0) + ' fallidos</div>' +
                '<span class="system-health-last">Última actividad: ' + escapeHtml(formatDate(channel.last_activity)) + '</span>' +
                '<span class="system-health-last">Último cron: ' + escapeHtml(formatDate(cron.ran_at)) + '</span>' +
            '</article>';
        }).join('');
    }

    function renderPlatform(data) {
        const platform = data.platform || {};
        const php = platform.php || {};
        const ext = platform.extensions || {};
        const db = platform.database || {};
        const extensionsOk = Object.keys(ext).filter(function (key) { return ext[key]; }).length;
        const extensionsTotal = Object.keys(ext).length;

        $('systemHealthPlatform').innerHTML =
            '<div class="system-health-detail-row"><span>Base de datos</span><strong class="system-health-check is-' + escapeHtml(db.status || 'warning') + '">' + escapeHtml(db.label || 'Sin datos') + '</strong></div>' +
            '<div class="system-health-detail-row"><span>PHP</span><strong>' + escapeHtml(php.version || '—') + ' · ' + escapeHtml(php.sapi || '') + '</strong></div>' +
            '<div class="system-health-detail-row"><span>Extensiones necesarias</span><strong>' + extensionsOk + '/' + extensionsTotal + ' disponibles</strong></div>' +
            '<div class="system-health-detail-row"><span>memory_limit</span><strong>' + escapeHtml(php.memory_limit || '—') + '</strong></div>' +
            '<div class="system-health-detail-row"><span>upload_max_filesize</span><strong>' + escapeHtml(php.upload_max_filesize || '—') + '</strong></div>' +
            '<div class="system-health-detail-row"><span>post_max_size</span><strong>' + escapeHtml(php.post_max_size || '—') + '</strong></div>';
    }

    function renderSecurity(data) {
        const security = data.security || {};
        $('systemHealthSecurity').innerHTML =
            '<div class="system-health-detail-row"><span>Storage privado</span>' + check(!!security.storage_protected, 'Protegido', 'Revisar .htaccess') + '</div>' +
            '<div class="system-health-detail-row"><span>Errores PHP al navegador</span>' + check(!!security.display_errors, 'Ocultos', 'display_errors activo') + '</div>' +
            '<div class="system-health-detail-row"><span>Cabecera de versión PHP</span>' + check(!!security.expose_php, 'Oculta', 'expose_php activo') + '</div>' +
            '<div class="system-health-detail-row"><span>Credenciales</span><strong class="system-health-check is-ok">No se muestran en este panel</strong></div>';
    }

    function renderStorage(data) {
        const storage = data.storage || {};
        const labels = {
            whatsapp_media: 'WhatsApp · multimedia',
            whatsapp_queue: 'WhatsApp · cola',
            instagram_media: 'Instagram · multimedia',
            instagram_queue: 'Instagram · cola',
            messenger: 'Messenger'
        };
        const keys = Object.keys(labels);
        $('systemHealthStorage').innerHTML = keys.map(function (key) {
            const row = storage[key] || {};
            return '<div class="system-health-storage-item"><span>' + escapeHtml(labels[key]) + '</span>' +
                '<strong>' + escapeHtml(formatBytes(row.bytes)) + '</strong>' +
                '<small>' + Number(row.files || 0) + ' archivos' + (row.truncated ? ' · lectura parcial' : '') + '</small></div>';
        }).join('');
    }

    async function loadHealth() {
        if (busy) return;
        busy = true;
        refreshBtn.disabled = true;
        errorBox.classList.add('hidden');

        const controller = new AbortController();
        const timer = setTimeout(function () { controller.abort(); }, 12000);
        try {
            const response = await fetch('modules/system-health.php?t=' + Date.now(), {
                cache: 'no-store',
                signal: controller.signal
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No se pudo consultar el sistema.');
            }
            renderOverall(data);
            renderChannels(data);
            renderPlatform(data);
            renderSecurity(data);
            renderStorage(data);
            $('systemHealthUpdated').textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (error) {
            errorBox.textContent = error.name === 'AbortError' ? 'La consulta de estado tardó demasiado. Intenta actualizar nuevamente.' : (error.message || 'No se pudo consultar el sistema.');
            errorBox.classList.remove('hidden');
            $('systemHealthUpdated').textContent = 'No se pudo actualizar';
        } finally {
            clearTimeout(timer);
            refreshBtn.disabled = false;
            busy = false;
        }
    }

    refreshBtn.addEventListener('click', loadHealth);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) loadHealth();
    });

    loadHealth();
    setInterval(function () {
        if (!document.hidden) loadHealth();
    }, 30000);
})();
