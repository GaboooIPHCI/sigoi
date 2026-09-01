<?php require_once __DIR__ . '/templates/header.php'; ?>

<main class="container metricas-page metricas-web-page">

    <section class="panel dashboard-hero">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Métricas web</span>
            <h1>Métricas Web</h1>
            <p>
                Revisa los clics generados desde botones de contacto,
                redes sociales, especialidades, procedimientos,
                servicios y tipos de atención.
            </p>
        </div>
    </section>

    <!-- CAMBIO DE VISTA -->
    <section class="panel metricas-view-switcher">
        <div class="metricas-view-switcher__inner">
            <span class="metricas-view-switcher__label">Vista</span>

            <div class="metricas-view-switcher__buttons">
                <button type="button" class="metricas-view-btn is-active" id="webBtnVistaNumerica" data-view="numerica">
                    Numérica
                </button>

                <button type="button" class="metricas-view-btn" id="webBtnVistaGrafica" data-view="grafica">
                    Gráfica
                </button>
            </div>
        </div>
    </section>

    <!-- FILTROS -->
    <section class="panel metricas-filtros">
        <div class="metricas-filtros__grid web-filtros-grid">

            <div class="field-group">
                <label for="webFiltroMes">Mes principal</label>
                <input type="month" id="webFiltroMes">
            </div>

            <div class="field-group web-compare-toggle-group">
                <label>Comparar con otro mes</label>

                <label class="web-toggle-compare">
                    <input type="checkbox" id="webActivarComparacion">
                    <span>Activar comparación</span>
                </label>
            </div>

            <div class="field-group hidden" id="webMesComparacionGroup">
                <label for="webFiltroMesComparacion">Mes de comparación</label>
                <input type="month" id="webFiltroMesComparacion">
            </div>

        </div>
    </section>

    <!-- VISTA NUMÉRICA -->
    <section id="webVistaNumerica" class="metricas-view-section">

        <section class="stats-grid web-stats-grid" id="webMetricasCards">
            <article class="stat-card">
                <span class="stat-card__label">Total clics</span>
                <strong id="webTotalClicks">0</strong>
                <small>Clics del mes seleccionado</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">General</span>
                <strong id="webTotalGeneral">0</strong>
                <small>WhatsApp header y flotante</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Redes sociales</span>
                <strong id="webTotalRedes">0</strong>
                <small>Facebook e Instagram</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Especialidades</span>
                <strong id="webTotalEspecialidades">0</strong>
                <small>Clics médicos principales</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Procedimientos</span>
                <strong id="webTotalProcedimientos">0</strong>
                <small>Procedimientos cardiológicos</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Servicios</span>
                <strong id="webTotalServicios">0</strong>
                <small>Laboratorio, ecografías y otros</small>
            </article>
        </section>

        <section class="panel">
            <div class="table-header">
                <div>
                    <h2>Comparación mensual</h2>
                    <p class="section-subtitle">Comparación de clics entre meses.</p>
                </div>
            </div>

            <div class="metricas-web-compare">
                <article class="metricas-compare-card">
                    <p class="metricas-compare-title">
                        Mes comparado
                        <span id="webMesAnteriorLabel">-</span>
                    </p>

                    <strong id="webTotalMesAnterior">0</strong>

                    <small id="webComparacionTexto">
                        Activa la comparación para ver resultados.
                    </small>
                </article>
            </div>
        </section>

        <section class="metricas-dual-grid">
            <section class="panel">
                <div class="table-header">
                    <div>
                        <h2>Top 5 más clickeados</h2>
                        <p class="section-subtitle">Los botones con mayor intención de contacto.</p>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Botón</th>
                                <th>Categoría</th>
                                <th>Clics</th>
                            </tr>
                        </thead>
                        <tbody id="webTopBody">
                            <tr class="empty-row">
                                <td colspan="3">Cargando información...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel">
                <div class="table-header">
                    <div>
                        <h2>Clics por categoría</h2>
                        <p class="section-subtitle">Resumen agrupado por tipo de interacción.</p>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Categoría</th>
                                <th>Clics</th>
                            </tr>
                        </thead>
                        <tbody id="webCategoriasBody">
                            <tr class="empty-row">
                                <td colspan="2">Cargando información...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <section class="panel">
            <div class="table-header">
                <div>
                    <h2>Detalle completo de clics</h2>
                    <p class="section-subtitle">Todos los botones registrados según el mes seleccionado.</p>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="records-table">
                    <thead>
                        <tr>
                            <th>Botón</th>
                            <th>Link key</th>
                            <th>Categoría</th>
                            <th>Clics</th>
                        </tr>
                    </thead>
                    <tbody id="webDetalleBody">
                        <tr class="empty-row">
                            <td colspan="4">Cargando información...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

    </section>

    <!-- VISTA GRÁFICA -->
    <section id="webVistaGrafica" class="metricas-view-section hidden">

        <!-- EVOLUCIÓN -->
        <section class="panel">
            <div class="table-header">
                <div>
                    <h2>Evolución mensual</h2>
                    <p class="section-subtitle">
                        Evolución de clics de los últimos 6 meses.
                    </p>
                </div>
            </div>

            <div class="metricas-chart-box metricas-chart-box--large">
                <canvas id="webChartEvolucion"></canvas>
            </div>
        </section>

        <!-- GRID PRINCIPAL -->
        <section class="metricas-graficos-grid">

            <!-- CATEGORÍAS -->
            <section class="panel">
                <div class="table-header">
                    <div>
                        <h2>Clics por categoría</h2>
                        <p class="section-subtitle">
                            Distribución visual de interacciones web.
                        </p>
                    </div>
                </div>

                <div class="metricas-chart-box">
                    <canvas id="webChartCategorias"></canvas>
                </div>
            </section>

            <!-- TOP -->
            <section class="panel">
                <div class="table-header">
                    <div>
                        <h2>Top 5 más clickeados</h2>
                        <p class="section-subtitle">
                            Botones con mayor intención de contacto.
                        </p>
                    </div>
                </div>

                <div class="metricas-chart-box">
                    <canvas id="webChartTop"></canvas>
                </div>
            </section>

        </section>

        <!-- COMPARACIÓN -->
        <section class="panel hidden" id="webChartComparacionPanel">

            <div class="table-header">
                <div>
                    <h2>Comparación mensual</h2>
                    <p class="section-subtitle">
                        Mes principal vs mes de comparación.
                </p>
                </div>
            </div>

            <div class="metricas-chart-box">
                <canvas id="webChartComparacion"></canvas>
            </div>

        </section>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/web-metrics.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>