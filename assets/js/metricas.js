document.addEventListener('DOMContentLoaded', () => {
    const els = {
        totalAtenciones: document.getElementById('metricasTotalAtenciones'),
        atencionesConfirmadas: document.getElementById('metricasAtencionesConfirmadas'),
        atencionesNoConfirmadas: document.getElementById('metricasAtencionesNoConfirmadas'),
        totalConvenios: document.getElementById('metricasTotalConvenios'),
        totalCampanias: document.getElementById('metricasTotalCampanias'),
        totalRegistrosCampanias: document.getElementById('metricasTotalRegistrosCampanias'),
        campaniasTotal: document.getElementById('metricasCampaniasTotal'),
        campaniasMontoGlobal: document.getElementById('metricasCampaniasMontoGlobal'),
        campaniasBody: document.getElementById('metricasCampaniasBody'),

        canalWrapper: document.getElementById('metricasCanalBody')?.closest('.table-wrapper'),
        tipoWrapper: document.getElementById('metricasTipoBody')?.closest('.table-wrapper'),
        conveniosBody: document.getElementById('metricasConveniosBody'),

        panelComparacionConfirmados: document.getElementById('panelComparacionConfirmados'),
        confirmadosCompareContent: document.getElementById('metricasConfirmadosCompareContent'),

        btnVistaNumerica: document.getElementById('btnVistaNumerica'),
        btnVistaGrafica: document.getElementById('btnVistaGrafica'),
        vistaNumerica: document.getElementById('metricasVistaNumerica'),
        vistaGrafica: document.getElementById('metricasVistaGrafica'),

        filtroMesPrincipal: document.getElementById('filtroMesPrincipal'),
        toggleCompararMeses: document.getElementById('toggleCompararMeses'),
        bloqueMesComparacion: document.getElementById('bloqueMesComparacion'),
        filtroMesComparacion: document.getElementById('filtroMesComparacion'),

        chartCanal: document.getElementById('chartCanal'),
        chartTipo: document.getElementById('chartTipo'),
        chartConfirmados: document.getElementById('chartConfirmados'),

        toast: document.getElementById('toast')
    };

    const state = {
        resumen: null,
        charts: {
            canal: null,
            tipo: null,
            confirmados: null
        }
    };

    init();

    async function init() {
        setDefaultMonths();
        bindViewEvents();
        bindFilterEvents();
        await loadResumen();
    }

    function setDefaultMonths() {
        const now = new Date();
        const currentMonth = formatMonth(now);
        const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const previousMonth = formatMonth(previous);

        if (els.filtroMesPrincipal && !els.filtroMesPrincipal.value) {
            els.filtroMesPrincipal.value = currentMonth;
        }

        if (els.filtroMesComparacion && !els.filtroMesComparacion.value) {
            els.filtroMesComparacion.value = previousMonth;
        }
    }

    function bindViewEvents() {
        els.btnVistaNumerica?.addEventListener('click', () => setVista('numerica'));
        els.btnVistaGrafica?.addEventListener('click', () => setVista('grafica'));
    }

    function bindFilterEvents() {
        els.filtroMesPrincipal?.addEventListener('change', loadResumen);
        els.filtroMesComparacion?.addEventListener('change', loadResumen);

        els.toggleCompararMeses?.addEventListener('change', () => {
            const active = els.toggleCompararMeses.checked;
            els.bloqueMesComparacion?.classList.toggle('hidden', !active);
            loadResumen();
        });
    }

    async function loadResumen() {
        try {
            const params = buildMetricasParams();
            const response = await fetch(`modules/metricas/resumen.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) {
                showToast(result.message || 'No se pudo cargar el resumen de métricas', true);
                return;
            }

            const data = result.data || {};
            state.resumen = data;

            renderTotales(data.totales || {});
            renderResumenAtenciones(data.atenciones_resumen || {});
            renderCanales(data);
            renderTipos(data);
            renderComparacionConfirmados(data);
            renderConvenios(data.convenios_por_derivado || []);
            renderCampaniasDetalle(data.campanias_detalle || []);

            if (!els.vistaGrafica?.classList.contains('hidden')) {
                renderCharts(data);
            }
        } catch (error) {
            console.error(error);
            showToast('Error al cargar métricas', true);
        }
    }

    function buildMetricasParams() {
        const params = new URLSearchParams();

        const mes = els.filtroMesPrincipal?.value || '';
        const comparar = els.toggleCompararMeses?.checked || false;
        const mesComparacion = els.filtroMesComparacion?.value || '';

        if (mes) params.append('mes', mes);

        if (comparar && mesComparacion) {
            params.append('comparar', '1');
            params.append('mes_comparacion', mesComparacion);
        }

        return params;
    }

    function isComparing() {
        return Boolean(els.toggleCompararMeses?.checked && state.resumen?.comparacion);
    }

    function setVista(vista) {
        const esNumerica = vista === 'numerica';

        els.vistaNumerica?.classList.toggle('hidden', !esNumerica);
        els.vistaGrafica?.classList.toggle('hidden', esNumerica);

        els.btnVistaNumerica?.classList.toggle('is-active', esNumerica);
        els.btnVistaGrafica?.classList.toggle('is-active', !esNumerica);

        if (!esNumerica && state.resumen) {
            renderCharts(state.resumen);
        }
    }

    function renderTotales(totales) {
        if (els.totalAtenciones) els.totalAtenciones.textContent = totales.atenciones ?? 0;
        if (els.totalConvenios) els.totalConvenios.textContent = totales.convenios ?? 0;
        if (els.totalCampanias) els.totalCampanias.textContent = totales.campanias ?? 0;
        if (els.totalRegistrosCampanias) els.totalRegistrosCampanias.textContent = totales.registros_campanias ?? 0;
    }

    function renderResumenAtenciones(resumen) {
        if (els.atencionesConfirmadas) els.atencionesConfirmadas.textContent = Number(resumen.confirmados || 0);
        if (els.atencionesNoConfirmadas) els.atencionesNoConfirmadas.textContent = Number(resumen.no_confirmados || 0);
    }

    function renderCanales(data) {
        const principal = data.atenciones_por_canal || [];
        const comparacion = data.comparacion?.atenciones_por_canal || [];

        if (isComparing()) {
            renderCompareTables(els.canalWrapper, {
                title: 'Canal',
                labelPrincipal: getMonthLabel(els.filtroMesPrincipal?.value),
                labelComparacion: getMonthLabel(els.filtroMesComparacion?.value),
                rowsPrincipal: principal,
                rowsComparacion: comparacion,
                keyName: 'canal'
            });
            return;
        }

        renderSimpleTable(els.canalWrapper, {
            headers: ['Canal', 'Total'],
            rows: principal,
            keyName: 'canal',
            emptyText: 'No hay datos de canales.'
        });
    }

    function renderTipos(data) {
        const principal = data.atenciones_por_tipo || [];
        const comparacion = data.comparacion?.atenciones_por_tipo || [];

        if (isComparing()) {
            renderCompareTables(els.tipoWrapper, {
                title: 'Tipo de atención',
                labelPrincipal: getMonthLabel(els.filtroMesPrincipal?.value),
                labelComparacion: getMonthLabel(els.filtroMesComparacion?.value),
                rowsPrincipal: principal,
                rowsComparacion: comparacion,
                keyName: 'tipo_atencion'
            });
            return;
        }

        renderSimpleTable(els.tipoWrapper, {
            headers: ['Tipo de atención', 'Total'],
            rows: principal,
            keyName: 'tipo_atencion',
            emptyText: 'No hay datos de tipos de atención.'
        });
    }

    function renderComparacionConfirmados(data) {
        if (!els.panelComparacionConfirmados || !els.confirmadosCompareContent) return;

        if (!isComparing()) {
            els.panelComparacionConfirmados.classList.add('hidden');
            els.confirmadosCompareContent.innerHTML = '';
            return;
        }

        els.panelComparacionConfirmados.classList.remove('hidden');

        const principal = resumenToRows(data.atenciones_resumen || {});
        const comparacion = resumenToRows(data.comparacion?.atenciones_resumen || {});

        renderCompareTables(els.confirmadosCompareContent, {
            title: 'Estado',
            labelPrincipal: getMonthLabel(els.filtroMesPrincipal?.value),
            labelComparacion: getMonthLabel(els.filtroMesComparacion?.value),
            rowsPrincipal: principal,
            rowsComparacion: comparacion,
            keyName: 'nombre'
        });
    }

    function resumenToRows(resumen) {
        return [
            { nombre: 'Confirmados', total: Number(resumen.confirmados || 0) },
            { nombre: 'No confirmados', total: Number(resumen.no_confirmados || 0) }
        ];
    }

    function renderSimpleTable(wrapper, config) {
        if (!wrapper) return;

        const { headers, rows, keyName, emptyText } = config;

        if (!rows.length) {
            wrapper.innerHTML = `
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(headers[0])}</th>
                            <th>${escapeHtml(headers[1])}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="empty-row">
                            <td colspan="2">${escapeHtml(emptyText)}</td>
                        </tr>
                    </tbody>
                </table>
            `;
            return;
        }

        const maxTotal = Math.max(...rows.map(row => Number(row.total || 0)), 1);

        wrapper.innerHTML = `
            <table class="records-table">
                <thead>
                    <tr>
                        <th>${escapeHtml(headers[0])}</th>
                        <th>${escapeHtml(headers[1])}</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.map(row => `
                        <tr>
                            <td class="metricas-label-cell">
                                <span>${escapeHtml(row[keyName] || '-')}</span>
                                <span class="metricas-row-bar" style="--metric-value: ${Math.round((Number(row.total || 0) / maxTotal) * 100)}%"></span>
                            </td>
                            <td class="metricas-value-cell"><span>${Number(row.total || 0)}</span></td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    function renderCompareTables(wrapper, config) {
        if (!wrapper) return;

        const {
            title,
            labelPrincipal,
            labelComparacion,
            rowsPrincipal,
            rowsComparacion,
            keyName
        } = config;

        wrapper.innerHTML = `
            <div class="metricas-compare-grid">
                ${renderCompareCard(title, labelPrincipal, rowsPrincipal, keyName)}
                ${renderCompareCard(title, labelComparacion, rowsComparacion, keyName)}
            </div>
        `;
    }

    function renderCompareCard(title, label, rows, keyName) {
        return `
            <div class="metricas-compare-card">
                <h3 class="metricas-compare-title">
                    ${escapeHtml(title)}
                    <span>${escapeHtml(label || '-')}</span>
                </h3>

                <table class="records-table">
                    <thead>
                        <tr>
                            <th>${escapeHtml(title)}</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${
                            rows.length
                                ? rows.map(row => `
                                    <tr>
                                        <td>${escapeHtml(row[keyName] || '-')}</td>
                                        <td>${Number(row.total || 0)}</td>
                                    </tr>
                                `).join('')
                                : `
                                    <tr class="empty-row">
                                        <td colspan="2">Sin datos</td>
                                    </tr>
                                `
                        }
                    </tbody>
                </table>
            </div>
        `;
    }

    function renderConvenios(rows) {
        if (!els.conveniosBody) return;

        if (!rows.length) {
            els.conveniosBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="2">No hay datos de convenios.</td>
                </tr>
            `;
            return;
        }

        els.conveniosBody.innerHTML = rows.map(row => `
            <tr>
                <td>${escapeHtml(row.derivado_a || '-')}</td>
                <td>${Number(row.total || 0)}</td>
            </tr>
        `).join('');
    }

    function renderCampaniasDetalle(rows) {
        if (!els.campaniasBody) return;

        if (!rows.length) {
            els.campaniasBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="4">No hay campañas registradas aún.</td>
                </tr>
            `;

            if (els.campaniasTotal) els.campaniasTotal.textContent = '0';
            if (els.campaniasMontoGlobal) els.campaniasMontoGlobal.textContent = formatMoney(0);
            return;
        }

        let totalMonto = 0;

        els.campaniasBody.innerHTML = rows.map(row => {
            const monto = Number(row.monto_total || 0);
            totalMonto += monto;

            return `
                <tr>
                    <td><strong>${escapeHtml(row.nombre)}</strong></td>
                    <td>${renderEstado(row.estado)}</td>
                    <td><strong>${Number(row.total_registros || 0)}</strong></td>
                    <td><strong>${formatMoney(monto)}</strong></td>
                </tr>
            `;
        }).join('');

        if (els.campaniasTotal) els.campaniasTotal.textContent = rows.length;
        if (els.campaniasMontoGlobal) els.campaniasMontoGlobal.textContent = formatMoney(totalMonto);
    }

    function renderEstado(estado) {
        const map = {
            borrador: 'badge-neutral',
            activa: 'badge-success',
            finalizada: 'badge-primary'
        };

        const cls = map[estado] || 'badge-neutral';
        return `<span class="badge ${cls}">${escapeHtml(estado || '-')}</span>`;
    }

    function renderCharts(data) {
        renderChartCanal(data);
        renderChartTipo(data);
        renderChartConfirmados(data);
    }

    function destroyChart(chartInstance) {
        if (chartInstance) chartInstance.destroy();
    }

    function renderChartCanal(data) {
        if (!els.chartCanal) return;

        destroyChart(state.charts.canal);

        const principal = data.atenciones_por_canal || [];
        const comparacion = data.comparacion?.atenciones_por_canal || [];

        const labels = buildMergedLabels(principal, comparacion, 'canal');

        state.charts.canal = new Chart(els.chartCanal, {
            type: 'bar',
            data: {
                labels,
                datasets: buildCompareDatasets(principal, comparacion, 'canal')
            },
            options: chartBarOptions()
        });
    }

    function renderChartTipo(data) {
        if (!els.chartTipo) return;

        destroyChart(state.charts.tipo);

        const principal = data.atenciones_por_tipo || [];
        const comparacion = data.comparacion?.atenciones_por_tipo || [];

        const labels = buildMergedLabels(principal, comparacion, 'tipo_atencion');

        state.charts.tipo = new Chart(els.chartTipo, {
            type: 'bar',
            data: {
                labels,
                datasets: buildCompareDatasets(principal, comparacion, 'tipo_atencion')
            },
            options: chartBarOptions()
        });
    }

    function renderChartConfirmados(data) {
        if (!els.chartConfirmados) return;

        destroyChart(state.charts.confirmados);

        const principal = data.atenciones_resumen || {};
        const comparacion = data.comparacion?.atenciones_resumen || null;

        if (!isComparing()) {
            state.charts.confirmados = new Chart(els.chartConfirmados, {
                type: 'doughnut',
                data: {
                    labels: ['Confirmados', 'No confirmados'],
                    datasets: [{
                        data: [
                            Number(principal.confirmados || 0),
                            Number(principal.no_confirmados || 0)
                        ],
                        backgroundColor: ['#d1fae5', '#e9d5ff'],
                        borderColor: ['#065f46', '#6b21a8'],
                        borderWidth: 1.5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

        return;
        }

        state.charts.confirmados = new Chart(els.chartConfirmados, {
            type: 'bar',
            data: {
                labels: ['Confirmados', 'No confirmados'],
                datasets: [
                    {
                        label: getMonthLabel(els.filtroMesPrincipal?.value),
                        data: [
                            Number(principal.confirmados || 0),
                            Number(principal.no_confirmados || 0)
                        ],
                        backgroundColor: '#dbeafe',
                        borderColor: '#1d4ed8',
                        borderWidth: 1.5,
                        borderRadius: 8
                    },
                    {
                        label: getMonthLabel(els.filtroMesComparacion?.value),
                        data: [
                            Number(comparacion?.confirmados || 0),
                            Number(comparacion?.no_confirmados || 0)
                        ],
                        backgroundColor: '#e9d5ff',
                        borderColor: '#6b21a8',
                        borderWidth: 1.5,
                        borderRadius: 8
                    }
                ]
            },
            options: chartBarOptions(true)
        });
    }

    function chartBarOptions(showLegend = false) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: showLegend
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        precision: 0,
                        callback: value => Number.isInteger(value) ? value : ''
                    }
                }
            }
        };
    }

    function buildMergedLabels(principal, comparacion, keyName) {
    const labels = new Set();

    principal.forEach(row => labels.add(row[keyName] || '-'));

    if (isComparing()) {
        comparacion.forEach(row => labels.add(row[keyName] || '-'));
    }

    return Array.from(labels);
}

    function buildCompareDatasets(principal, comparacion, keyName) {
        const labels = buildMergedLabels(principal, comparacion, keyName);

        const principalMap = rowsToMap(principal, keyName);
        const comparacionMap = rowsToMap(comparacion, keyName);

        if (!isComparing()) {
            return [{
                label: 'Atenciones',
                data: labels.map(label => Number(principalMap[label] || 0)),
                backgroundColor: [
                    '#dbeafe',
                    '#e9d5ff',
                    '#cfeff7',
                    '#fecaca',
                    '#d9f99d',
                    '#fde68a'
                ],
                borderColor: [
                    '#1d4ed8',
                    '#6b21a8',
                    '#155e75',
                    '#b91c1c',
                    '#166534',
                    '#92400e'
                ],
                borderWidth: 1.5,
                borderRadius: 8
            }];
        }

        return [
            {
                label: getMonthLabel(els.filtroMesPrincipal?.value),
                data: labels.map(label => Number(principalMap[label] || 0)),
                backgroundColor: '#dbeafe',
                borderColor: '#1d4ed8',
                borderWidth: 1.5,
                borderRadius: 8
            },
            {
                label: getMonthLabel(els.filtroMesComparacion?.value),
                data: labels.map(label => Number(comparacionMap[label] || 0)),
                backgroundColor: '#e9d5ff',
                borderColor: '#6b21a8',
                borderWidth: 1.5,
                borderRadius: 8
            }
        ];
    }

    function rowsToMap(rows, keyName) {
        return rows.reduce((acc, row) => {
            const key = row[keyName] || '-';
            acc[key] = Number(row.total || 0);
            return acc;
        }, {});
    }

    function formatMonth(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        return `${year}-${month}`;
    }

    function getMonthLabel(month) {
        if (!month) return '-';

        const [year, monthNumber] = month.split('-');
        const date = new Date(Number(year), Number(monthNumber) - 1, 1);

        return date.toLocaleDateString('es-PE', {
            month: 'long',
            year: 'numeric'
        });
    }

    function formatMoney(value) {
        const number = Number(value || 0);
        return number.toLocaleString('es-PE', {
            style: 'currency',
            currency: 'PEN'
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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
});
