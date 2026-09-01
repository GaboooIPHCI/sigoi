document.addEventListener('DOMContentLoaded', () => {
    const els = {
        filtroMes: document.getElementById('webFiltroMes'),
        filtroMesComparacion: document.getElementById('webFiltroMesComparacion'),
        grupoMesComparacion: document.getElementById('webMesComparacionGroup'),
        activarComparacion: document.getElementById('webActivarComparacion'),

        btnVistaNumerica: document.getElementById('webBtnVistaNumerica'),
        btnVistaGrafica: document.getElementById('webBtnVistaGrafica'),
        vistaNumerica: document.getElementById('webVistaNumerica'),
        vistaGrafica: document.getElementById('webVistaGrafica'),

        totalClicks: document.getElementById('webTotalClicks'),
        totalGeneral: document.getElementById('webTotalGeneral'),
        totalRedes: document.getElementById('webTotalRedes'),
        totalEspecialidades: document.getElementById('webTotalEspecialidades'),
        totalProcedimientos: document.getElementById('webTotalProcedimientos'),
        totalServicios: document.getElementById('webTotalServicios'),

        mesAnteriorLabel: document.getElementById('webMesAnteriorLabel'),
        totalMesAnterior: document.getElementById('webTotalMesAnterior'),
        comparacionTexto: document.getElementById('webComparacionTexto'),

        topBody: document.getElementById('webTopBody'),
        categoriasBody: document.getElementById('webCategoriasBody'),
        detalleBody: document.getElementById('webDetalleBody'),

        chartCategorias: document.getElementById('webChartCategorias'),
        chartTop: document.getElementById('webChartTop'),
        chartComparacion: document.getElementById('webChartComparacion'),
        chartEvolucion: document.getElementById('webChartEvolucion'),
        chartComparacionPanel: document.getElementById('webChartComparacionPanel')
    };

    const chartColors = [
        '#7A2E58',
        '#A64D79',
        '#4F6D8C',
        '#2F9EAA',
        '#D39C3F',
        '#6A4C93',
        '#5E6472',
        '#C96C6C'
    ];

    let currentData = null;

    let charts = {
        categorias: null,
        top: null,
        comparacion: null,
        evolucion: null
    };

    init();

    function init() {
        setDefaultMonths();
        bindEvents();
        loadMetricasWeb();
    }

    function setDefaultMonths() {
        const now = new Date();
        const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        const previousDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const previousMonth = `${previousDate.getFullYear()}-${String(previousDate.getMonth() + 1).padStart(2, '0')}`;

        if (els.filtroMes) els.filtroMes.value = currentMonth;
        if (els.filtroMesComparacion) els.filtroMesComparacion.value = previousMonth;
    }

    function bindEvents() {
        els.filtroMes?.addEventListener('change', loadMetricasWeb);
        els.filtroMesComparacion?.addEventListener('change', loadMetricasWeb);

        els.activarComparacion?.addEventListener('change', () => {
            const activo = els.activarComparacion.checked;

            els.grupoMesComparacion?.classList.toggle('hidden', !activo);
            els.chartComparacionPanel?.classList.toggle('hidden', !activo);

            loadMetricasWeb();
        });

        els.btnVistaNumerica?.addEventListener('click', () => setVista('numerica'));
        els.btnVistaGrafica?.addEventListener('click', () => setVista('grafica'));
    }

    function setVista(vista) {
        const esGrafica = vista === 'grafica';

        els.vistaNumerica?.classList.toggle('hidden', esGrafica);
        els.vistaGrafica?.classList.toggle('hidden', !esGrafica);

        els.btnVistaNumerica?.classList.toggle('is-active', !esGrafica);
        els.btnVistaGrafica?.classList.toggle('is-active', esGrafica);

        if (esGrafica && currentData) {
            renderCharts(currentData);
        }
    }

    async function loadMetricasWeb() {
        try {
            const params = new URLSearchParams();

            if (els.filtroMes?.value) {
                params.append('mes', els.filtroMes.value);
            }

            const response = await fetch(`modules/web_metrics/resumen.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) return;

            currentData = result.data;
            renderData(result.data);

            if (!els.vistaGrafica?.classList.contains('hidden')) {
                renderCharts(result.data);
            }

        } catch (error) {
            console.error(error);
        }
    }

    function renderData(data) {
        const principal = data.principal || {};
        const categorias = principal.por_categoria || {};

        setText(els.totalClicks, principal.total || 0);
        setText(els.totalGeneral, categorias['General'] || 0);
        setText(els.totalRedes, categorias['Red Social'] || 0);
        setText(els.totalEspecialidades, categorias['Especialidad'] || 0);
        setText(els.totalProcedimientos, categorias['Procedimiento'] || 0);
        setText(els.totalServicios, categorias['Servicio'] || 0);

        renderComparacion(data);
        renderTop(principal.top5 || []);
        renderCategorias(categorias);
        renderDetalle(principal.tabla || []);
    }

    function renderComparacion(data) {
        if (!els.activarComparacion?.checked) {
            setText(els.mesAnteriorLabel, '-');
            setText(els.totalMesAnterior, '0');
            setText(els.comparacionTexto, 'Activa la comparación para ver resultados.');
            return;
        }

        loadComparisonData(data);
    }

    async function loadComparisonData(data) {
        try {
            const mesComparacion = els.filtroMesComparacion?.value;
            if (!mesComparacion) return;

            const params = new URLSearchParams();
            params.append('mes', mesComparacion);

            const response = await fetch(`modules/web_metrics/resumen.php?${params.toString()}`);
            const result = await response.json();

            if (!result.success) return;

            const totalPrincipal = Number(data.principal?.total || 0);
            const totalComparado = Number(result.data.principal?.total || 0);
            const diferencia = totalPrincipal - totalComparado;

            let porcentaje = 0;

            if (totalComparado > 0) {
                porcentaje = ((diferencia / totalComparado) * 100).toFixed(1);
            }

            setText(els.mesAnteriorLabel, getMonthLabel(mesComparacion));
            setText(els.totalMesAnterior, totalComparado);

            let texto = 'Sin variación respecto al mes comparado';

            if (diferencia > 0) {
                texto = `+${diferencia} clics (${porcentaje}%) respecto al mes comparado`;
            } else if (diferencia < 0) {
                texto = `${diferencia} clics (${porcentaje}%) respecto al mes comparado`;
            }

            setText(els.comparacionTexto, texto);

            currentData.comparacionPersonalizada = {
                mes: mesComparacion,
                total: totalComparado,
                diferencia,
                porcentaje,
                principal: totalPrincipal
            };

            if (!els.vistaGrafica?.classList.contains('hidden')) {
                renderCharts(currentData);
            }

        } catch (error) {
            console.error(error);
        }
    }

    function renderTop(rows) {
        if (!els.topBody) return;

        if (!rows.length) {
            els.topBody.innerHTML = emptyRow(3, 'No hubo botones destacados en este mes.');
            return;
        }

        els.topBody.innerHTML = rows.map((row, index) => `
            <tr>
                <td>
                    <span class="web-rank-badge">#${index + 1}</span>
                    ${escapeHtml(row.nombre)}
                </td>
                <td>${escapeHtml(row.categoria)}</td>
                <td>${Number(row.total || 0)}</td>
            </tr>
        `).join('');
    }

    function renderCategorias(categorias) {
        if (!els.categoriasBody) return;

        const rows = Object.entries(categorias);

        if (!rows.length) {
            els.categoriasBody.innerHTML = emptyRow(2, 'No hubo clics por categoría en este mes.');
            return;
        }

        els.categoriasBody.innerHTML = rows.map(([categoria, total]) => `
            <tr>
                <td>${escapeHtml(categoria)}</td>
                <td>${Number(total || 0)}</td>
            </tr>
        `).join('');
    }

    function renderDetalle(rows) {
        if (!els.detalleBody) return;

        if (!rows.length) {
            els.detalleBody.innerHTML = emptyRow(4, 'No hubo interacciones registradas en este mes.');
            return;
        }

        els.detalleBody.innerHTML = rows.map(row => `
            <tr>
                <td>${escapeHtml(row.nombre)}</td>
                <td>${escapeHtml(row.link_key)}</td>
                <td>${escapeHtml(row.categoria)}</td>
                <td>${Number(row.total || 0)}</td>
            </tr>
        `).join('');
    }

    function renderCharts(data) {
        if (typeof Chart === 'undefined') return;

        renderChartEvolucion(data);
        renderChartCategorias(data);
        renderChartTop(data);

        if (els.activarComparacion?.checked) {
            renderChartComparacion(data);
        } else {
            destroyChart('comparacion');
            els.chartComparacionPanel?.classList.add('hidden');
        }
    }

    function renderChartEvolucion(data) {
        if (!els.chartEvolucion) return;

        const evolucion = data.evolucion || [];
        const labels = evolucion.map(item => getMonthLabel(item.mes));
        const values = evolucion.map(item => Number(item.total || 0));

        destroyChart('evolucion');

        charts.evolucion = new Chart(els.chartEvolucion, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Clics registrados',
                    data: values,
                    borderColor: '#7A2E58',
                    backgroundColor: 'rgba(122, 46, 88, 0.12)',
                    pointBackgroundColor: '#7A2E58',
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    borderWidth: 3,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: getChartOptions(false)
        });
    }

    function renderChartCategorias(data) {
        const categorias = data.principal?.por_categoria || {};
        const labels = Object.keys(categorias);
        const values = Object.values(categorias);

        if (!els.chartCategorias) return;

        destroyChart('categorias');

        if (!labels.length) {
            renderEmptyChart(els.chartCategorias, 'Sin categorías registradas');
            return;
        }

        charts.categorias = new Chart(els.chartCategorias, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    label: 'Clics registrados',
                    data: values,
                    backgroundColor: labels.map((_, index) => chartColors[index % chartColors.length]),
                    hoverBackgroundColor: labels.map((_, index) => chartColors[index % chartColors.length]),
                    borderColor: '#FFFFFF',
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 14,
                            boxHeight: 14,
                            padding: 16,
                            color: '#334155',
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` ${context.label}: ${context.raw} clics registrados`;
                            }
                        }
                    }
                }
            }
        });
    }

    function renderChartTop(data) {
        const rows = data.principal?.top5 || [];
        const labels = rows.map((row, index) => `#${index + 1} ${row.nombre}`);
        const values = rows.map(row => Number(row.total || 0));
        const colors = rows.map((_, index) => chartColors[index % chartColors.length]);

        if (!els.chartTop) return;

        destroyChart('top');

        if (!rows.length) {
            renderEmptyChart(els.chartTop, 'Sin botones destacados');
            return;
        }

        charts.top = new Chart(els.chartTop, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Clics registrados',
                    data: values,
                    backgroundColor: colors,
                    hoverBackgroundColor: colors,
                    borderRadius: 10,
                    borderSkipped: false,
                    barThickness: 28,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` ${context.raw} clics registrados`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            color: '#64748B'
                        },
                        grid: {
                            color: 'rgba(15, 23, 42, 0.08)'
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: '#334155',
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
    }

    function renderChartComparacion(data) {
        if (!els.chartComparacion) return;

        const comparacion = data.comparacionPersonalizada;
        if (!comparacion) return;

        els.chartComparacionPanel?.classList.remove('hidden');

        destroyChart('comparacion');

        charts.comparacion = new Chart(els.chartComparacion, {
            type: 'bar',
            data: {
                labels: [
                    getMonthLabel(els.filtroMes?.value),
                    getMonthLabel(comparacion.mes)
                ],
                datasets: [{
                    label: 'Clics registrados',
                    data: [
                        Number(comparacion.principal || 0),
                        Number(comparacion.total || 0)
                    ],
                    backgroundColor: ['#7A2E58', '#2F9EAA'],
                    hoverBackgroundColor: ['#A64D79', '#4F6D8C'],
                    borderRadius: 10,
                    borderSkipped: false,
                    borderWidth: 0
                }]
            },
            options: getChartOptions(false)
        });
    }

    function renderEmptyChart(canvas, message) {
        const ctx = canvas.getContext('2d');

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#64748B';
        ctx.font = '14px Arial';
        ctx.fillText(message, canvas.width / 2, canvas.height / 2);
        ctx.restore();
    }

    function getChartOptions(showLegend = true) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: showLegend,
                    labels: {
                        color: '#334155',
                        font: { size: 12 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ` ${context.raw} clics registrados`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#64748B' },
                    grid: { color: 'rgba(15, 23, 42, 0.08)' }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        color: '#64748B'
                    },
                    grid: { color: 'rgba(15, 23, 42, 0.08)' }
                }
            }
        };
    }

    function emptyRow(colspan, message) {
        return `
            <tr class="empty-row">
                <td colspan="${colspan}">
                    <div class="web-empty-state">
                        <span>—</span>
                        <strong>${escapeHtml(message)}</strong>
                    </div>
                </td>
            </tr>
        `;
    }

    function destroyChart(key) {
        if (charts[key]) {
            charts[key].destroy();
            charts[key] = null;
        }
    }

    function setText(element, value) {
        if (element) element.textContent = value;
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

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
});