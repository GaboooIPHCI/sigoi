<?php
$SIGOI_ACCESS_PAGE = 'whatsapp.php';
require_once __DIR__ . '/templates/header.php';

require_once __DIR__ . '/config/messenger_schema.php';

$permissions = auth_whatsapp_permissions();
$canAnalytics = !empty($permissions['analitica_ver']);
$canInbox = !empty($permissions['bandeja_ver']);
$canAutomation = !empty($permissions['automatizacion_ver']);
$canLibrary = !empty($permissions['plantillas_ver']);
$canWhatsApp = !empty($permissions['canal_whatsapp']);
$canInstagram = !empty($permissions['canal_instagram']);
$canMessenger = messenger_channel_permission_for_user(
    $pdo,
    (int)auth_user_id()
);
$enabledChannelCount = (int)$canWhatsApp + (int)$canInstagram + (int)$canMessenger;

if (!$canAnalytics) {
    http_response_code(403);
}
?>
<main class="container mc-page">
    <?php if (!$canAnalytics): ?>
        <section class="panel mc-denied">
            <h1>Analítica multicanal</h1>
            <p>No tienes permiso para visualizar esta sección.</p>
        </section>
    <?php else: ?>
        <section class="panel mc-hero mc-hero--workspace">
            <div class="mc-hero__copy">
                <span class="dashboard-label">Centro de conversaciones</span>
                <h1>Analítica multicanal</h1>
                <p>Vista global de conversaciones, mensajes y tiempos de respuesta de los canales habilitados.</p>
            </div>

            <div class="mc-hero__workspace-actions">
                <nav class="wa-tabs mc-analytics-tabs" aria-label="Secciones del centro de conversaciones">
                    <?php if ($canInbox): ?>
                        <a class="wa-tab" href="whatsapp.php?tab=inbox">Bandeja</a>
                    <?php endif; ?>
                    <?php if ($canAutomation): ?>
                        <a class="wa-tab" href="whatsapp.php?tab=automation">Automatización</a>
                    <?php endif; ?>
                    <?php if ($canLibrary): ?>
                        <a class="wa-tab" href="whatsapp.php?tab=library">Plantillas y respuestas</a>
                    <?php endif; ?>
                    <a class="wa-tab is-active" href="analitica-multicanal.php" aria-current="page">Analítica</a>
                </nav>

                <div class="mc-period-caption" id="mcPeriodCaption">Últimos 30 días</div>
            </div>
        </section>

        <section class="panel mc-filters">
            <div class="mc-filter-group">
                <span>Canal</span>
                <div class="mc-channel-buttons" id="mcChannelButtons">
                    <?php if ($enabledChannelCount > 1): ?>
                        <button type="button" class="is-active" data-mc-channel="all">Todos</button>
                    <?php endif; ?>
                    <?php if ($canWhatsApp): ?>
                        <button type="button" class="<?= $enabledChannelCount === 1 ? 'is-active' : '' ?>" data-mc-channel="whatsapp">WhatsApp</button>
                    <?php endif; ?>
                    <?php if ($canInstagram): ?>
                        <button type="button" class="<?= $enabledChannelCount === 1 ? 'is-active' : '' ?>" data-mc-channel="instagram">Instagram</button>
                    <?php endif; ?>
                    <?php if ($canMessenger): ?>
                        <button type="button" class="<?= $enabledChannelCount === 1 ? 'is-active' : '' ?>" data-mc-channel="messenger">Messenger</button>
                    <?php endif; ?>
                </div>
            </div>

            <label class="mc-select-field">
                <span>Periodo</span>
                <select id="mcPeriod">
                    <option value="today">Hoy</option>
                    <option value="yesterday">Ayer</option>
                    <option value="7d">Últimos 7 días</option>
                    <option value="30d" selected>Últimos 30 días</option>
                    <option value="90d">Últimos 90 días</option>
                    <option value="custom">Personalizado</option>
                </select>
            </label>

            <div class="mc-custom-range hidden" id="mcCustomRange">
                <label><span>Desde</span><input type="date" id="mcFrom"></label>
                <label><span>Hasta</span><input type="date" id="mcTo"></label>
                <button type="button" id="mcApplyCustom">Aplicar</button>
            </div>

            <button type="button" class="mc-refresh" id="mcRefresh"><span class="mc-refresh-icon" aria-hidden="true">↻</span><span class="mc-refresh-label">Actualizar</span></button>
        </section>

        <section class="mc-status-line" aria-live="polite">
            <span class="mc-live-dot"></span>
            <span id="mcStatus">Cargando analítica...</span>
        </section>

        <section class="mc-card-grid mc-card-grid--primary">
            <article class="panel mc-card">
                <span>Conversaciones</span>
                <strong id="mcConversations">0</strong>
                <small>Total con actividad en el periodo.</small>
            </article>
            <article class="panel mc-card">
                <span>Nuevas</span>
                <strong id="mcNew">0</strong>
                <small>Primer contacto dentro del periodo.</small>
            </article>
            <article class="panel mc-card">
                <span>Atendidas</span>
                <strong id="mcAttended">0</strong>
                <small>Con respuesta humana registrada.</small>
            </article>
            <article class="panel mc-card mc-card--attention">
                <span>Pendientes</span>
                <strong id="mcPending">0</strong>
                <small>Requieren seguimiento humano.</small>
            </article>
            <article class="panel mc-card">
                <span>Resueltas</span>
                <strong id="mcResolved">0</strong>
                <small>Marcadas como resueltas en el periodo.</small>
            </article>
        </section>

        <section class="mc-section-head">
            <div>
                <span class="dashboard-label">Mensajería</span>
                <h2>Actividad de mensajes</h2>
            </div>
        </section>

        <section class="mc-card-grid mc-card-grid--secondary">
            <article class="panel mc-card">
                <span>Recibidos</span>
                <strong id="mcIncoming">0</strong>
                <small>Mensajes enviados por contactos.</small>
            </article>
            <article class="panel mc-card">
                <span>Enviados</span>
                <strong id="mcOutgoing">0</strong>
                <small>Salientes completados sin fallo.</small>
            </article>
            <article class="panel mc-card">
                <span>Vistos</span>
                <strong id="mcRead">0</strong>
                <small>Salientes con confirmación de lectura.</small>
            </article>
            <article class="panel mc-card">
                <span>No vistos</span>
                <strong id="mcUnread">0</strong>
                <small>Salientes sin confirmación de lectura.</small>
            </article>
            <article class="panel mc-card">
                <span>Tasa de lectura</span>
                <strong id="mcReadRate">—</strong>
                <small>Vistos sobre mensajes enviados.</small>
            </article>
        </section>

        <section class="mc-two-columns">
            <article class="panel mc-panel">
                <div class="mc-panel-head">
                    <div>
                        <span class="dashboard-label">Velocidad</span>
                        <h2>Tiempos de respuesta</h2>
                        <p>Medición global, sin métricas individuales por agente.</p>
                    </div>
                </div>
                <div class="mc-time-grid">
                    <div>
                        <span>1ª respuesta promedio</span>
                        <strong id="mcFirstResponse">—</strong>
                        <small id="mcFirstResponseSamples">Sin muestras</small>
                    </div>
                    <div>
                        <span>Respuesta promedio</span>
                        <strong id="mcAvgResponse">—</strong>
                        <small id="mcResponseSamples">Sin muestras</small>
                    </div>
                    <div>
                        <span>Respuesta mediana</span>
                        <strong id="mcMedianResponse">—</strong>
                        <small>Reduce el efecto de casos extremos.</small>
                    </div>
                </div>
            </article>

            <article class="panel mc-panel">
                <div class="mc-panel-head">
                    <div>
                        <span class="dashboard-label">Canales</span>
                        <h2>Distribución</h2>
                        <p>Participación de cada canal sobre el total de conversaciones.</p>
                    </div>
                </div>
                <div class="mc-channel-distribution" id="mcDistribution">
                    <div class="mc-empty">Cargando...</div>
                </div>
            </article>
        </section>

        <section class="panel mc-panel">
            <div class="mc-panel-head mc-panel-head--row">
                <div>
                    <span class="dashboard-label">Evolución</span>
                    <h2>Actividad diaria</h2>
                    <p>Conversaciones y mensajes recibidos/enviados por fecha.</p>
                </div>
                <strong id="mcDailySummary">—</strong>
            </div>
            <div class="mc-legend">
                <span><i class="is-conversations"></i> Conversaciones</span>
                <span><i class="is-incoming"></i> Recibidos</span>
                <span><i class="is-outgoing"></i> Enviados</span>
            </div>
            <div class="mc-daily-chart" id="mcDailyChart"></div>
        </section>

        <section class="panel mc-note">
            <strong>Analítica multicanal activa</strong>
            <p>WhatsApp, Instagram y Messenger se agregan sobre la misma estructura. Cada usuario solo ve los canales que tiene habilitados.</p>
        </section>
    <?php endif; ?>
</main>

<?php if ($canAnalytics): ?>
<script>
<?php
$defaultAnalyticsChannel = 'all';
if ($enabledChannelCount === 1) {
    if ($canMessenger) {
        $defaultAnalyticsChannel = 'messenger';
    } elseif ($canInstagram) {
        $defaultAnalyticsChannel = 'instagram';
    } else {
        $defaultAnalyticsChannel = 'whatsapp';
    }
}
?>
window.SIGOI_MC_DEFAULT_CHANNEL = <?= json_encode(
    $defaultAnalyticsChannel,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;
</script>
<link rel="stylesheet" href="assets/css/analytics-multicanal.css?v=6.0">
<script src="assets/js/analytics-multicanal.js?v=6.0"></script>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
