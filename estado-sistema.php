<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

if (!function_exists('auth_can_manage_users') || !auth_can_manage_users()) {
    http_response_code(403);
    exit('No tienes permiso para acceder a esta sección.');
}

$SIGOI_ACCESS_PAGE = 'usuarios.php';
require_once __DIR__ . '/templates/header.php';
?>

<main class="system-health-page">
    <div class="container">
        <section class="system-health-hero">
            <div>
                <span class="system-health-eyebrow">DIAGNÓSTICO</span>
                <h1>Estado del sistema</h1>
                <p>Revisa canales, cron, colas, almacenamiento y componentes del servidor sin exponer credenciales.</p>
            </div>
            <div class="system-health-actions">
                <span id="systemHealthUpdated" class="system-health-updated">Consultando...</span>
                <button type="button" class="system-health-refresh" id="systemHealthRefresh">Actualizar</button>
            </div>
        </section>

        <section class="system-health-summary" aria-live="polite">
            <div class="system-health-overall" id="systemHealthOverall">
                <span class="system-health-status-dot is-loading"></span>
                <div>
                    <small>Estado general</small>
                    <strong>Consultando S.I.G.O.I...</strong>
                </div>
            </div>
        </section>

        <section class="system-health-grid system-health-grid--channels" id="systemHealthChannels">
            <article class="system-health-card is-loading"><div class="system-health-skeleton"></div></article>
            <article class="system-health-card is-loading"><div class="system-health-skeleton"></div></article>
            <article class="system-health-card is-loading"><div class="system-health-skeleton"></div></article>
        </section>

        <section class="system-health-grid system-health-grid--details">
            <article class="system-health-card">
                <div class="system-health-card__head">
                    <div>
                        <small>SERVIDOR</small>
                        <h2>Plataforma</h2>
                    </div>
                </div>
                <div id="systemHealthPlatform" class="system-health-detail-list">
                    <div class="system-health-placeholder">Consultando...</div>
                </div>
            </article>

            <article class="system-health-card">
                <div class="system-health-card__head">
                    <div>
                        <small>PROTECCIÓN</small>
                        <h2>Seguridad operativa</h2>
                    </div>
                </div>
                <div id="systemHealthSecurity" class="system-health-detail-list">
                    <div class="system-health-placeholder">Consultando...</div>
                </div>
            </article>
        </section>

        <section class="system-health-card system-health-storage-card">
            <div class="system-health-card__head">
                <div>
                    <small>ALMACENAMIENTO</small>
                    <h2>Uso de archivos de S.I.G.O.I.</h2>
                </div>
                <span class="system-health-muted">Se recalcula como máximo cada 5 minutos.</span>
            </div>
            <div id="systemHealthStorage" class="system-health-storage-grid">
                <div class="system-health-placeholder">Calculando almacenamiento...</div>
            </div>
        </section>

        <div id="systemHealthError" class="system-health-error hidden"></div>
    </div>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
