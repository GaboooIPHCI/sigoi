<?php require_once __DIR__ . '/templates/header.php'; ?>

<main class="container metricas-page">
    <section class="panel dashboard-hero metricas-dashboard__hero">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Módulo analítico</span>
            <h1>Métricas</h1>
            <p>
                Visualiza el comportamiento general del sistema, el origen de leads,
                convenios vinculados y el rendimiento base de campañas.
            </p>
        </div>
    </section>

    <section class="panel metricas-view-switcher metricas-dashboard__switcher">
        <div class="metricas-view-switcher__inner">
            <span class="metricas-view-switcher__label">Vista</span>

            <div class="metricas-view-switcher__buttons">
                <button type="button" class="metricas-view-btn is-active" id="btnVistaNumerica" data-view="numerica">
                    Numérica
                </button>
                <button type="button" class="metricas-view-btn" id="btnVistaGrafica" data-view="grafica">
                    Gráfica
                </button>
            </div>
        </div>
    </section>

    <section class="panel metricas-filtros metricas-dashboard__filters">
        <div class="metricas-section-heading">
            <div>
                <span class="metricas-section-heading__eyebrow">Periodo de análisis</span>
                <h2>Filtra la información</h2>
            </div>
            <p>Selecciona un mes o activa la comparación para revisar cambios.</p>
        </div>
        <div class="metricas-filtros__grid">
            <div class="field-group">
                <label for="filtroMesPrincipal">Mes principal</label>
                <input type="month" id="filtroMesPrincipal">
            </div>

            <div class="field-group metricas-filtros__toggle">
                <label for="toggleCompararMeses">Comparar con otro mes</label>
                <div class="metricas-switch-row">
                    <input type="checkbox" id="toggleCompararMeses">
                    <span>Activar comparación</span>
                </div>
            </div>

            <div class="field-group hidden" id="bloqueMesComparacion">
                <label for="filtroMesComparacion">Mes de comparación</label>
                <input type="month" id="filtroMesComparacion">
            </div>
        </div>
    </section>

    <section id="metricasVistaNumerica" class="metricas-view-section">
        <div class="metricas-section-heading metricas-section-heading--outside">
            <div>
                <span class="metricas-section-heading__eyebrow">Resumen general</span>
                <h2>Indicadores principales</h2>
            </div>
            <p>Una lectura rápida del periodo seleccionado.</p>
        </div>

        <section class="stats-grid metricas-dashboard__stats" id="metricasResumenCards">
            <article class="stat-card metricas-stat-card metricas-stat-card--primary">
                <span class="stat-card__label">Total atenciones</span>
                <strong id="metricasTotalAtenciones">0</strong>
                <small>Registros generales</small>
            </article>

            <article class="stat-card metricas-stat-card metricas-stat-card--success">
                <span class="stat-card__label">Atenciones confirmadas</span>
                <strong id="metricasAtencionesConfirmadas">0</strong>
                <small>Status confirmado</small>
            </article>

            <article class="stat-card metricas-stat-card metricas-stat-card--warning">
                <span class="stat-card__label">Atenciones no confirmadas</span>
                <strong id="metricasAtencionesNoConfirmadas">0</strong>
                <small>Status no confirmado</small>
            </article>

            <article class="stat-card metricas-stat-card metricas-stat-card--info">
                <span class="stat-card__label">Total convenios</span>
                <strong id="metricasTotalConvenios">0</strong>
                <small>Convenios vinculados</small>
            </article>

            <article class="stat-card metricas-stat-card metricas-stat-card--campaign">
                <span class="stat-card__label">Total campañas</span>
                <strong id="metricasTotalCampanias">0</strong>
                <small>Campañas registradas</small>
            </article>

            <article class="stat-card metricas-stat-card metricas-stat-card--leads">
                <span class="stat-card__label">Registros campañas</span>
                <strong id="metricasTotalRegistrosCampanias">0</strong>
                <small>Leads captados</small>
            </article>
        </section>

        <section class="metricas-dual-grid">
            <section class="panel metricas-breakdown-card metricas-breakdown-card--channel">
                <div class="table-header">
                    <div>
                        <h2>Atenciones por canal</h2>
                        <p class="section-subtitle">Origen actual de los leads registrados.</p>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Canal</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody id="metricasCanalBody">
                            <tr class="empty-row">
                                <td colspan="2">Cargando información...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel metricas-breakdown-card metricas-breakdown-card--type">
                <div class="table-header">
                    <div>
                        <h2>Atenciones por tipo</h2>
                        <p class="section-subtitle">Distribución por tipo de atención.</p>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Tipo de atención</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody id="metricasTipoBody">
                            <tr class="empty-row">
                                <td colspan="2">Cargando información...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <section class="panel metricas-section-card hidden" id="panelComparacionConfirmados">
            <div class="table-header">
                <div>
                    <h2>Confirmados vs no confirmados</h2>
                    <p class="section-subtitle">Comparación mensual del status de atención.</p>
                </div>
            </div>

            <div id="metricasConfirmadosCompareContent"></div>
        </section>

        <section class="panel metricas-section-card metricas-section-card--convenios">
            <div class="table-header">
                <div>
                    <h2>Convenios por derivación</h2>
                    <p class="section-subtitle">Resumen de derivaciones entre Resocentro y Servimovil.</p>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>Derivado a</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody id="metricasConveniosBody">
                        <tr class="empty-row">
                            <td colspan="2">Cargando información...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel metricas-section-card metricas-section-card--campaigns">
            <div class="table-header">
                <div>
                    <h2>Campañas</h2>
                    <p class="section-subtitle">Monto generado por cada campaña.</p>
                </div>
            </div>

            <div class="stats-grid stats-grid--campaigns">
                <article class="stat-card metricas-stat-card metricas-stat-card--campaign">
                    <span class="stat-card__label">Total campañas</span>
                    <strong id="metricasCampaniasTotal">0</strong>
                    <small>Campañas registradas</small>
                </article>

                <article class="stat-card metricas-stat-card metricas-stat-card--money">
                    <span class="stat-card__label">Monto total</span>
                    <strong id="metricasCampaniasMontoGlobal">S/ 0.00</strong>
                    <small>Total acumulado</small>
                </article>
            </div>

            <div class="table-wrapper">
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>Campaña</th>
                            <th>Estado</th>
                            <th>Registros</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody id="metricasCampaniasBody">
                        <tr class="empty-row">
                            <td colspan="4">Cargando campañas...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </section>

    <section id="metricasVistaGrafica" class="metricas-view-section hidden">
        <div class="metricas-section-heading metricas-section-heading--outside">
            <div>
                <span class="metricas-section-heading__eyebrow">Vista gráfica</span>
                <h2>Distribución de resultados</h2>
            </div>
            <p>Compara visualmente el comportamiento del periodo elegido.</p>
        </div>

        <div class="metricas-charts-grid">
        <section class="panel metricas-chart-card">
            <div class="table-header">
                <div>
                    <h2>Atenciones por canal</h2>
                    <p class="section-subtitle">Comparación visual por canal.</p>
                </div>
            </div>
            <div class="metricas-chart-box">
                <canvas id="chartCanal"></canvas>
            </div>
        </section>

        <section class="panel metricas-chart-card">
            <div class="table-header">
                <div>
                    <h2>Atenciones por tipo</h2>
                    <p class="section-subtitle">Comparación visual por tipo de atención.</p>
                </div>
            </div>
            <div class="metricas-chart-box">
                <canvas id="chartTipo"></canvas>
            </div>
        </section>

        <section class="panel metricas-chart-card metricas-chart-card--wide">
            <div class="table-header">
                <div>
                    <h2>Confirmados vs no confirmados</h2>
                    <p class="section-subtitle">Resumen visual del status de atención.</p>
                </div>
            </div>
            <div class="metricas-chart-box">
                <canvas id="chartConfirmados"></canvas>
            </div>
        </section>
        </div>
    </section>

    <div class="toast hidden" id="toast"></div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/metricas.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
