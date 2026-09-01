(function () {
    'use strict';

    const SIGOI_MC_BUILD = '3.3';

    const $ = (id) => document.getElementById(id);
    const state = {
        channel: window.SIGOI_MC_DEFAULT_CHANNEL || 'all',
        period: '30d',
        loading: false
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function formatDuration(seconds) {
        if (seconds == null || Number.isNaN(Number(seconds))) return '—';
        seconds = Math.max(0, Number(seconds));
        if (seconds < 60) return Math.round(seconds) + ' s';
        if (seconds < 3600) return Math.round(seconds / 60) + ' min';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.round((seconds % 3600) / 60);
        return hours + ' h' + (minutes ? ' ' + minutes + ' min' : '');
    }

    function setText(id, value) {
        const node = $(id);
        if (node) node.textContent = value;
    }

    function setStatus(message, error) {
        setText('mcStatus', message);
        const line = $('mcStatus')?.closest('.mc-status-line');
        if (line) line.classList.toggle('is-error', !!error);
    }

    function periodLabel(filters) {
        const period = filters?.period || state.period;
        const labels = {
            today: 'Hoy',
            yesterday: 'Ayer',
            '7d': 'Últimos 7 días',
            '30d': 'Últimos 30 días',
            '90d': 'Últimos 90 días',
            custom: 'Periodo personalizado'
        };
        if (period === 'custom' && filters?.from && filters?.to) {
            return `${filters.from} → ${filters.to}`;
        }
        return labels[period] || labels['30d'];
    }

    async function fetchAnalytics() {
        const params = new URLSearchParams({
            channel: state.channel,
            period: state.period
        });

        if (state.period === 'custom') {
            const from = $('mcFrom')?.value || '';
            const to = $('mcTo')?.value || '';
            if (!from || !to) {
                setStatus('Selecciona las dos fechas del periodo personalizado.', true);
                return null;
            }
            params.set('from', from);
            params.set('to', to);
        }

        const response = await fetch('modules/metricas/multicanal.php?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });

        const raw = await response.text();
        let payload;

        try {
            payload = JSON.parse(raw);
        } catch (_) {
            throw new Error(`El servidor no devolvió JSON válido (HTTP ${response.status}).`);
        }

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'No se pudo cargar la analítica.');
        }

        return payload.data || {};
    }

    function renderSummary(summary) {
        setText('mcConversations', Number(summary.conversaciones || 0));
        setText('mcNew', Number(summary.nuevas || 0));
        setText('mcAttended', Number(summary.atendidas || 0));
        setText('mcPending', Number(summary.pendientes || 0));
        setText('mcResolved', Number(summary.resueltas || 0));

        setText('mcIncoming', Number(summary.mensajes_entrantes || 0));
        setText('mcOutgoing', Number(summary.mensajes_salientes || 0));
        setText('mcRead', Number(summary.vistos || 0));
        setText('mcUnread', Number(summary.no_vistos || 0));
        setText(
            'mcReadRate',
            summary.tasa_lectura_pct == null
                ? '—'
                : Number(summary.tasa_lectura_pct).toFixed(1) + '%'
        );

        setText('mcFirstResponse', formatDuration(summary.primera_respuesta_promedio_seg));
        setText('mcAvgResponse', formatDuration(summary.respuesta_promedio_seg));
        setText('mcMedianResponse', formatDuration(summary.respuesta_mediana_seg));

        const firstSamples = Number(summary.muestras_primera_respuesta || 0);
        const responseSamples = Number(summary.muestras_respuesta || 0);

        setText(
            'mcFirstResponseSamples',
            firstSamples
                ? `${firstSamples} ${firstSamples === 1 ? 'conversación medida' : 'conversaciones medidas'}`
                : 'Sin muestras suficientes'
        );

        setText(
            'mcResponseSamples',
            responseSamples
                ? `${responseSamples} ${responseSamples === 1 ? 'respuesta medida' : 'respuestas medidas'}`
                : 'Sin muestras suficientes'
        );
    }

    function renderDistribution(rows) {
        const box = $('mcDistribution');
        if (!box) return;

        if (!rows || !rows.length) {
            box.innerHTML = '<div class="mc-empty">Sin datos de canales para este periodo.</div>';
            return;
        }

        box.innerHTML = rows.map(row => {
            const pct = Number(row.distribucion_pct || 0);
            const channel = String(row.channel || '');
            return `
                <div class="mc-channel-row">
                    <div class="mc-channel-row__top">
                        <div>
                            <span class="mc-channel-badge is-${escapeHtml(channel)}">${escapeHtml(row.label || channel)}</span>
                            <small>${Number(row.conversaciones || 0)} conversaciones</small>
                        </div>
                        <strong>${pct.toFixed(1)}%</strong>
                    </div>
                    <div class="mc-progress"><i style="width:${Math.max(0, Math.min(100, pct))}%"></i></div>
                    <div class="mc-channel-row__meta">
                        <span>${Number(row.mensajes_entrantes || 0)} recibidos</span>
                        <span>${Number(row.mensajes_salientes || 0)} enviados</span>
                        <span>${row.tasa_lectura_pct == null ? 'Lectura —' : 'Lectura ' + Number(row.tasa_lectura_pct).toFixed(1) + '%'}</span>
                    </div>
                </div>`;
        }).join('');

    }

    function renderDaily(rows) {
        const chart = $('mcDailyChart');
        if (!chart) return;

        if (!rows || !rows.length) {
            chart.innerHTML = '<div class="mc-empty">Sin actividad en el periodo seleccionado.</div>';
            setText('mcDailySummary', 'Sin actividad');
            return;
        }

        const totals = rows.map(row => {
            const total = row.total || {};
            return Number(total.conversaciones || 0)
                + Number(total.mensajes_entrantes || 0)
                + Number(total.mensajes_salientes || 0);
        });

        const max = Math.max(...totals, 1);
        let conversations = 0;
        let incoming = 0;
        let outgoing = 0;

        chart.innerHTML = rows.map((row, index) => {
            const total = row.total || {};
            const conv = Number(total.conversaciones || 0);
            const inc = Number(total.mensajes_entrantes || 0);
            const out = Number(total.mensajes_salientes || 0);
            conversations += conv;
            incoming += inc;
            outgoing += out;

            const sum = conv + inc + out;
            const height = sum ? Math.max(8, Math.round((sum / max) * 100)) : 3;
            const convPct = sum ? (conv / sum) * 100 : 0;
            const incPct = sum ? (inc / sum) * 100 : 0;
            const outPct = sum ? (out / sum) * 100 : 0;
            const showLabel = rows.length <= 14 || index % Math.ceil(rows.length / 12) === 0;

            return `
                <div class="mc-day-col" title="${escapeHtml(row.fecha)} · ${conv} conversaciones · ${inc} recibidos · ${out} enviados">
                    <div class="mc-day-value">${sum || ''}</div>
                    <div class="mc-day-stack" style="height:${height}%">
                        <i class="is-conversations" style="height:${convPct}%"></i>
                        <i class="is-incoming" style="height:${incPct}%"></i>
                        <i class="is-outgoing" style="height:${outPct}%"></i>
                    </div>
                    <span>${showLabel ? escapeHtml(String(row.fecha).slice(5).replace('-', '/')) : ''}</span>
                </div>`;
        }).join('');

        setText(
            'mcDailySummary',
            `${conversations} conversaciones · ${incoming} recibidos · ${outgoing} enviados`
        );
    }

    function syncChannelButtons() {
        document.querySelectorAll('[data-mc-channel]').forEach(button => {
            button.classList.toggle('is-active', button.dataset.mcChannel === state.channel);
        });
    }

    async function load() {
        if (state.loading) return;
        state.loading = true;
        $('mcRefresh')?.classList.add('is-loading');
        setStatus('Actualizando analítica...', false);

        try {
            const data = await fetchAnalytics();
            if (!data) return;

            renderSummary(data.summary || {});
            renderDistribution(data.by_channel || []);
            renderDaily(data.daily || []);

            const filters = data.filters || {};
            setText('mcPeriodCaption', periodLabel(filters));

            const availableNames = (data.available_channels || [])
                .map(row => row.label)
                .join(' + ');

            setStatus(
                availableNames
                    ? `Datos cargados: ${availableNames}.`
                    : 'Datos cargados.',
                false
            );
        } catch (error) {
            setStatus(error.message || 'No se pudo cargar la analítica.', true);
        } finally {
            state.loading = false;
            $('mcRefresh')?.classList.remove('is-loading');
        }
    }

    document.addEventListener('click', event => {
        const channel = event.target.closest('[data-mc-channel]');
        if (channel) {
            state.channel = channel.dataset.mcChannel || 'all';
            syncChannelButtons();
            load();
        }
    });

    $('mcPeriod')?.addEventListener('change', event => {
        state.period = event.target.value || '30d';
        $('mcCustomRange')?.classList.toggle('hidden', state.period !== 'custom');
        if (state.period !== 'custom') load();
    });

    $('mcApplyCustom')?.addEventListener('click', load);
    $('mcRefresh')?.addEventListener('click', load);

    syncChannelButtons();
    load();
})();
