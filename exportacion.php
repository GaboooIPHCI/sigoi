<?php require_once __DIR__ . '/templates/header.php'; ?>

<main class="container">
    <section class="dashboard-hero panel">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Exportación</span>
            <h1>Exportar Excel</h1>
            <p>Descarga información del sistema según los bloques disponibles para tu rol.</p>
        </div>
    </section>

    <section class="panel">
        <form action="modules/exportacion/export.php" method="GET" class="exportacion-form">
            <h3>Selecciona qué deseas exportar</h3>

            <div class="filters-grid">
                <?php if (auth_can_view_module('pacientes')): ?>
                    <label class="checkbox-card">
                        <input type="checkbox" name="bloques[]" value="atenciones" checked>
                        <span>Atenciones / Pacientes</span>
                    </label>
                <?php endif; ?>
            
                <?php if (auth_can_view_module('convenios')): ?>
                    <label class="checkbox-card">
                        <input type="checkbox" name="bloques[]" value="convenios" checked>
                        <span>Convenios</span>
                    </label>
                <?php endif; ?>
                
                <?php if (auth_can_view_module('campanias')): ?>
                    <label class="checkbox-card">
                        <input type="checkbox" name="bloques[]" value="campanias" checked>
                        <span>Campañas</span>
                    </label>
                
                    <label class="checkbox-card">
                        <input type="checkbox" name="bloques[]" value="registros_campanias" checked>
                        <span>Registros de campañas</span>
                    </label>
                <?php endif; ?>
                
                <?php if (auth_can_view_module('metricas')): ?>
                    <label class="checkbox-card">
                        <input type="checkbox" name="bloques[]" value="metricas" checked>
                        <span>Métricas</span>
                    </label>
                <?php endif; ?>
            </div>

            <div class="filters-grid" style="margin-top: 18px;">
                <div class="field-group">
                    <label for="mes">Filtrar por mes</label>
                    <input type="month" name="mes" id="mes">
                </div>
            </div>

            <div class="dashboard-hero__actions" style="margin-top: 20px;">
                <button type="submit" class="btn-primary">Exportar Excel</button>
            </div>
        </form>
    </section>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
