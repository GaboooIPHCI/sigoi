<?php
require_once __DIR__ . '/templates/header.php';

$whatsappPermissions = auth_whatsapp_permissions();
$canInboxView = !empty($whatsappPermissions['bandeja_ver']);
$canReply = !empty($whatsappPermissions['bandeja_responder']);
$canChannelWhatsApp = !empty($whatsappPermissions['canal_whatsapp']);
$canChannelInstagram = !empty($whatsappPermissions['canal_instagram']);
$canManageInbox = !empty($whatsappPermissions['bandeja_gestionar']);
$canAutomationView = !empty($whatsappPermissions['automatizacion_ver']);
$canAutomationModify = !empty($whatsappPermissions['automatizacion_modificar']);
$canLibraryView = !empty($whatsappPermissions['plantillas_ver']);
$canLibraryManage = !empty($whatsappPermissions['plantillas_gestionar']);
$canAnalyticsView = !empty($whatsappPermissions['analitica_ver']);

$visibleWhatsappTabs = [];
if ($canInboxView) $visibleWhatsappTabs[] = 'inbox';
if ($canAutomationView) $visibleWhatsappTabs[] = 'automation';
if ($canLibraryView) $visibleWhatsappTabs[] = 'library';
if ($canAnalyticsView) $visibleWhatsappTabs[] = 'analytics';
$initialWhatsappTab = $visibleWhatsappTabs[0] ?? null;
?>
<main class="container whatsapp-page whatsapp-v2 <?= $initialWhatsappTab === 'inbox' ? 'is-inbox-view' : '' ?>">
    <section class="panel wa-workspace-hero">
        <div class="wa-workspace-hero__copy">
            <span class="dashboard-label">Centro de conversaciones</span>
            <h1>WhatsApp</h1>
            <p>Atiende conversaciones, comparte archivos, administra la automatización y analiza la atención desde un solo lugar.</p>
        </div>

        <?php if ($visibleWhatsappTabs): ?>
        <nav class="wa-tabs" aria-label="Secciones de WhatsApp">
            <?php if ($canInboxView): ?>
            <button type="button" class="wa-tab <?= $initialWhatsappTab === 'inbox' ? 'is-active' : '' ?>" data-wa-tab="inbox">
                <span>Bandeja</span>
                <b class="wa-tab-badge hidden" id="waInboxUnreadBadge">0</b>
            </button>
            <?php endif; ?>
            <?php if ($canAutomationView): ?>
            <button type="button" class="wa-tab <?= $initialWhatsappTab === 'automation' ? 'is-active' : '' ?>" data-wa-tab="automation">Automatización</button>
            <?php endif; ?>
            <?php if ($canLibraryView): ?>
            <button type="button" class="wa-tab <?= $initialWhatsappTab === 'library' ? 'is-active' : '' ?>" data-wa-tab="library">Plantillas y respuestas</button>
            <?php endif; ?>
            <?php if ($canAnalyticsView): ?>
            <button type="button" class="wa-tab <?= $initialWhatsappTab === 'analytics' ? 'is-active' : '' ?>" data-wa-tab="analytics">Analítica</button>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <div class="wa-workspace-hero__meta">
            <div class="wa-live-status" id="waLiveStatus">
                <span class="wa-status-dot" id="waStatusDot"></span>
                <div>
                    <small>Integración</small>
                    <strong id="waIntegrationStatus">Comprobando conexión...</strong>
                    <span id="waIntegrationNumber"></span>
                    <span class="wa-cron-status" id="waCronStatus">Procesamiento 24/7: pendiente</span>
                </div>
            </div>
        </div>
    </section>

    <!-- =====================================================
         BANDEJA
    ====================================================== -->
    <?php if ($canInboxView): ?>
    <section class="wa-tab-panel <?= $initialWhatsappTab === 'inbox' ? 'is-active' : '' ?>" data-wa-panel="inbox">
        <div class="wa-inbox-shell panel">
            <aside class="wa-inbox-list-pane">
                <div class="wa-inbox-pane-head">
                    <div>
                        <span class="wa-kicker">Conversaciones</span>
                        <h2>Bandeja de entrada</h2>
                        <div class="wa-sync-line"><span class="wa-live-mini-dot"></span><span id="waSyncState">En vivo</span></div>
                    </div>
                    <div class="wa-pane-head-actions">
                        <button type="button" class="wa-refresh-button" id="waManualRefreshBtn" title="Actualizar conversaciones">↻</button>
                        <span class="wa-count-pill" id="waInboxCount">0</span>
                    </div>
                </div>

                <div class="wa-inbox-search">
                    <span class="wa-search-icon">⌕</span>
                    <input id="waInboxSearch" type="search" placeholder="Buscar nombre, usuario, teléfono o mensaje" autocomplete="off">
                </div>

                <?php if ($canChannelWhatsApp || $canChannelInstagram): ?>
                <div class="wa-channel-filter-row <?= ($canChannelWhatsApp && $canChannelInstagram) ? '' : 'is-single-channel' ?>" id="waInboxChannels" aria-label="Filtrar por canal">
                    <?php if ($canChannelWhatsApp && $canChannelInstagram): ?>
                    <button type="button" class="is-active" data-wa-channel="all"><span class="wa-channel-filter-icon">◎</span>Todos</button>
                    <?php endif; ?>
                    <?php if ($canChannelWhatsApp): ?>
                    <button type="button" class="<?= (!$canChannelInstagram) ? 'is-active' : '' ?>" data-wa-channel="whatsapp"><span class="wa-channel-filter-icon is-whatsapp">W</span>WhatsApp</button>
                    <?php endif; ?>
                    <?php if ($canChannelInstagram): ?>
                    <button type="button" class="<?= (!$canChannelWhatsApp) ? 'is-active' : '' ?>" data-wa-channel="instagram"><span class="wa-channel-filter-icon is-instagram">IG</span>Instagram</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="wa-filter-row" id="waInboxFilters">
                    <button type="button" class="is-active" data-wa-filter="all">Todas</button>
                    <button type="button" data-wa-filter="pending">Pendientes</button>
                    <button type="button" data-wa-filter="unread">No leídas</button>
                    <button type="button" data-wa-filter="resolved">Resueltas</button>
                </div>

                <div class="wa-conversation-list" id="waConversationList">
                    <div class="wa-list-loading">Cargando conversaciones...</div>
                </div>
            </aside>

            <section class="wa-chat-pane" id="waChatPane">
                <div class="wa-chat-empty" id="waChatEmpty">
                    <div class="wa-chat-empty__icon">W</div>
                    <h2>Selecciona una conversación</h2>
                    <p>Aquí podrás ver el historial completo y responder desde S.I.G.O.I.</p>
                </div>

                <div class="wa-chat-view hidden" id="waChatView">
                    <header class="wa-chat-header">
                        <div class="wa-chat-contact">
                            <div class="wa-avatar" id="waChatAvatar">?</div>
                            <div>
                                <div class="wa-chat-name-row">
                                    <h2 id="waChatName">Contacto</h2>
                                    <span class="wa-chat-state" id="waChatState">Abierta</span>
                                </div>
                                <div class="wa-chat-subline">
                                    <span class="wa-chat-channel-badge is-whatsapp" id="waChatChannelBadge">WhatsApp</span>
                                    <span id="waChatPhone"></span>
                                    <span class="wa-dot-separator">•</span>
                                    <span id="waChatAssignment">Sin asignar</span>
                                </div>
                            </div>
                        </div>
                        <div class="wa-chat-header-actions">
                            <button type="button" class="wa-soft-button wa-contact-toggle-button" id="waContactToggleBtn" aria-expanded="false">Contacto</button>
                            <?php if ($canManageInbox): ?>
                                <button type="button" class="wa-icon-button" id="waMarkPendingBtn" title="Marcar pendiente">!</button>
                                <button type="button" class="wa-soft-button" id="waResolveBtn">Marcar resuelta</button>
                            <?php endif; ?>
                        </div>
                    </header>

                    <div class="wa-window-alert hidden" id="waWindowAlert">
                        <div class="wa-window-alert__copy">
                            <strong id="waWindowAlertTitle">Conversación fuera de ventana</strong>
                            <span id="waWindowAlertText">El historial sigue disponible. Para volver a escribir se necesita una plantilla oficial aprobada.</span>
                        </div>
                        <?php if ($canReply): ?><button type="button" class="wa-window-alert__button" id="waRetakeBtn">Retomar conversación</button><?php endif; ?>
                    </div>

                    <div class="wa-chat-messages" id="waChatMessages" tabindex="0" aria-label="Historial de mensajes"></div>
                    <button type="button" class="wa-jump-latest hidden" id="waJumpLatestBtn">↓ Ir al último mensaje</button>

                    <?php if ($canReply): ?>
                    <div class="wa-composer" id="waComposer">
                        <div class="wa-composer-head">
                            <div>
                                <strong>Responder como <?= htmlspecialchars(auth_user_display_name(), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>Respuesta manual desde S.I.G.O.I.</span>
                            </div>
                            <span class="wa-composer-window" id="waComposerWindow">Ventana de 24 h activa</span>
                        </div>
                        <div class="wa-file-chip hidden" id="waFileChip">
                            <span class="wa-file-chip__icon">📎</span>
                            <div>
                                <strong id="waFileName">archivo</strong>
                                <small id="waFileSize"></small>
                            </div>
                            <button type="button" id="waClearFileBtn" aria-label="Quitar archivo">×</button>
                        </div>
                        <div class="wa-quick-picker hidden" id="waQuickPicker">
                            <div class="wa-quick-picker__head">
                                <div><strong>Respuestas rápidas</strong><small>Inserta una respuesta y edítala antes de enviar.</small></div>
                                <button type="button" id="waQuickPickerClose" aria-label="Cerrar">×</button>
                            </div>
                            <div class="wa-quick-picker__search"><span>⌕</span><input type="search" id="waQuickPickerSearch" placeholder="Buscar respuesta o /atajo"></div>
                            <div class="wa-quick-picker__list" id="waQuickPickerList"><div class="wa-empty-mini">Cargando...</div></div>
                        </div>
                        <div class="wa-composer-row">
                            <input type="file" id="waFileInput" hidden accept="image/jpeg,image/png,video/mp4,video/3gpp,audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                            <button type="button" class="wa-attach-button" id="waAttachBtn" title="Adjuntar archivo">＋</button>
                            <button type="button" class="wa-quick-button" id="waQuickReplyBtn" title="Respuestas rápidas">⚡</button>
                            <textarea id="waComposerText" rows="1" maxlength="4000" placeholder="Escribe un mensaje..."></textarea>
                            <button type="button" class="wa-send-button" id="waSendBtn">Enviar</button>
                        </div>
                        <div class="wa-composer-hint">
                            <span id="waComposerHint">Enter para enviar · Shift+Enter para salto de línea</span>
                            <span id="waComposerChars">0/4000</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="wa-contact-pane" id="waContactPane">
                <div class="wa-contact-empty" id="waContactEmpty">
                    <span>Información de contacto</span>
                    <p>Selecciona una conversación para ver detalles.</p>
                </div>
                <div class="wa-contact-view hidden" id="waContactView">
                    <div class="wa-contact-profile">
                        <button type="button" class="wa-contact-close-button" id="waCloseContactBtn" aria-label="Cerrar detalles">×</button>
                        <div class="wa-avatar wa-avatar--large" id="waDetailAvatar">?</div>
                        <h3 id="waDetailName">Contacto</h3>
                        <p id="waDetailPhone"></p>
                        <span id="waDetailUsername" class="wa-username hidden"></span>
                        <small class="wa-whatsapp-profile-name" id="waDetailWhatsappName"></small>
                    </div>

                    <?php if ($canManageInbox): ?>
                    <div class="wa-detail-section wa-contact-editor is-collapsed" id="waContactEditor">
                        <button type="button" class="wa-contact-editor-toggle" id="waContactEditorToggle" aria-expanded="false">
                            <span>
                                <strong>Ficha del contacto</strong>
                                <small>Nombre personalizado y notas internas</small>
                            </span>
                            <b aria-hidden="true">⌄</b>
                        </button>
                        <div class="wa-contact-editor-body" id="waContactEditorBody">
                            <div class="wa-mini-help">Visible solo en S.I.G.O.I.</div>
                            <label class="wa-contact-edit-field">
                                <span>Nombre personalizado</span>
                                <input type="text" id="waContactCustomName" maxlength="140" placeholder="Ej. Daniel Sara - Campaña Agosto">
                            </label>
                            <label class="wa-contact-edit-field">
                                <span>Notas internas</span>
                                <textarea id="waContactNotes" rows="3" maxlength="4000" placeholder="Información útil para recepción, seguimiento, sede, interés, etc."></textarea>
                            </label>
                            <button type="button" class="wa-contact-save-button" id="waSaveContactBtn">Guardar contacto</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="wa-detail-section wa-origin-section hidden" id="waOriginSection">
                        <span class="wa-detail-label">Origen del contacto</span>
                        <div class="wa-origin-card">
                            <img id="waOriginImage" class="hidden" alt="Vista previa del anuncio">
                            <div>
                                <strong id="waOriginTitle">Meta Ads</strong>
                                <small id="waOriginMeta">Anuncio de Meta</small>
                                <a id="waOriginLink" class="hidden" target="_blank" rel="noopener">Ver origen ↗</a>
                            </div>
                        </div>
                    </div>

                    <div class="wa-detail-section">
                        <span class="wa-detail-label">Estado</span>
                        <div class="wa-detail-statuses">
                            <span class="wa-detail-badge" id="waDetailState">Abierta</span>
                            <span class="wa-detail-badge is-warning hidden" id="waDetailPending">Pendiente humano</span>
                        </div>
                    </div>

                    <?php if ($canManageInbox): ?>
                    <label class="wa-detail-section wa-detail-field">
                        <span class="wa-detail-label">Responsable</span>
                        <select id="waAssigneeSelect">
                            <option value="0">Sin asignar</option>
                        </select>
                    </label>
                    <?php endif; ?>

                    <div class="wa-detail-section wa-detail-grid">
                        <div><span>Primer contacto</span><strong id="waDetailFirst">—</strong></div>
                        <div><span>Último mensaje</span><strong id="waDetailLast">—</strong></div>
                        <div><span>Mensajes</span><strong id="waDetailMessages">0</strong></div>
                        <div><span>1ª resp. humana</span><strong id="waDetailResponse">—</strong></div>
                    </div>

                    <div class="wa-detail-section">
                        <span class="wa-detail-label">Canal</span>
                        <div class="wa-channel-card" id="waDetailChannelCard">
                            <strong id="waDetailChannelTitle">WhatsApp Business + S.I.G.O.I.</strong>
                            <small id="waDetailChannelText">Coexistence activo mediante YCloud</small>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <?php endif; ?>

    <!-- =====================================================
         AUTOMATIZACIÓN
    ====================================================== -->
    <?php if ($canAutomationView): ?>
    <section class="wa-tab-panel <?= $initialWhatsappTab === 'automation' ? 'is-active' : '' ?>" data-wa-panel="automation">
        <div class="wa-automation-wrap">
            <section class="panel wa-section-intro">
                <div><span class="wa-kicker">Configuración operativa</span><h2>Automatización</h2><p>Define cuándo responde el sistema, qué puede contestar y qué debe pasar a una persona.</p></div>
                <div class="wa-section-intro__hint"><strong>Orden recomendado</strong><span>1. Horario · 2. Mensajes base · 3. Reglas · 4. Probar</span></div>
            </section>
        <section class="wa-stats-grid">
            <article class="wa-stat-card">
                <span>Reglas activas</span><strong id="waStatRules">0</strong><small>Respuestas configuradas</small>
            </article>
            <article class="wa-stat-card">
                <span>Mensajes hoy</span><strong id="waStatMessages">0</strong><small>Entrantes y salientes</small>
            </article>
            <article class="wa-stat-card">
                <span>Respuestas automáticas</span><strong id="waStatReplies">0</strong><small>Enviadas hoy</small>
            </article>
            <article class="wa-stat-card">
                <span>Pendientes para humano</span><strong id="waStatPending">0</strong><small>Necesitan seguimiento</small>
            </article>
        </section>

        <section class="wa-layout">
            <div class="wa-main-column">
                <section class="panel wa-section">
                    <div class="wa-section__header">
                        <div>
                            <span class="wa-kicker">Comportamiento</span>
                            <h2>Horario y mensajes base</h2>
                            <p>La automatización responde únicamente fuera del horario humano configurado.</p>
                        </div>
                        <?php if (!$canAutomationModify): ?><span class="readonly-notice">Modo solo lectura</span><?php endif; ?>
                    </div>

                    <form id="waSettingsForm">
                        <div class="wa-activation-card">
                            <div>
                                <strong>Motor de respuestas automáticas</strong>
                                <p>Cuando está activo, las reglas se aplican fuera del horario humano.</p>
                            </div>
                            <label class="wa-switch" title="Activar o desactivar automatización">
                                <input type="checkbox" id="waAutomationActive" <?= $canAutomationModify ? '' : 'disabled' ?>>
                                <span></span>
                            </label>
                        </div>

                        <div class="wa-subsection">
                            <div class="wa-subsection__title">
                                <h3>Horario de atención humana</h3>
                                <p>Fuera de estas horas responde el sistema. Si un día no hay atención humana, desactiva ese día.</p>
                            </div>
                            <div class="wa-schedule" id="waSchedule"></div>
                        </div>

                        <div class="wa-form-grid">
                            <label class="wa-field">
                                <span>Mensaje de bienvenida fuera de horario</span>
                                <textarea id="waWelcomeMessage" rows="4" maxlength="4000" <?= $canAutomationModify ? '' : 'readonly' ?>></textarea>
                                <small>Se antepone a la primera respuesta automática reciente.</small>
                            </label>
                            <label class="wa-field">
                                <span>Cuando ninguna regla coincide</span>
                                <textarea id="waFallbackMessage" rows="4" maxlength="4000" <?= $canAutomationModify ? '' : 'readonly' ?>></textarea>
                                <small>Evita respuestas inventadas y deriva el caso a humano.</small>
                            </label>
                        </div>

                        <?php if ($canAutomationModify): ?>
                        <div class="wa-form-actions">
                            <span class="wa-save-note">Zona horaria: America/Lima</span>
                            <button class="btn-primary" type="submit">Guardar configuración</button>
                        </div>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="panel wa-section">
                    <div class="wa-section__header">
                        <div>
                            <span class="wa-kicker">Respuestas</span>
                            <h2>Reglas inteligentes</h2>
                            <p>La prioridad más baja se evalúa primero. El sistema responde exactamente con el texto configurado.</p>
                        </div>
                        <?php if ($canAutomationModify): ?><button class="btn-primary" type="button" id="waNewRuleBtn">+ Nueva regla</button><?php endif; ?>
                    </div>
                    <div class="wa-rules-list" id="waRulesList"><div class="wa-empty">Cargando reglas...</div></div>
                </section>
            </div>

            <aside class="wa-side-column">
                <section class="panel wa-tester">
                    <span class="wa-kicker">Simulador</span>
                    <h2>Probar reglas</h2>
                    <p>Comprueba qué respondería el sistema sin enviar nada por WhatsApp.</p>
                    <label class="wa-field">
                        <span>Mensaje de prueba</span>
                        <textarea id="waTestMessage" rows="4" placeholder="Ej. Hola, quisiera agendar una cita"></textarea>
                    </label>
                    <button class="btn-secondary wa-full-button" type="button" id="waTestBtn">Probar mensaje</button>
                    <div class="wa-test-result is-empty" id="waTestResult"><small>Resultado</small><p>La respuesta aparecerá aquí.</p></div>
                </section>
                <section class="panel wa-help-card">
                    <span class="wa-kicker">Lógica</span>
                    <h3>Controlado y predecible</h3>
                    <ol>
                        <li>Llega un mensaje fuera de horario.</li>
                        <li>Se comparan las reglas por prioridad.</li>
                        <li>Si coincide, se usa la respuesta aprobada.</li>
                        <li>Si no coincide, queda pendiente para humano.</li>
                    </ol>
                </section>
            </aside>
        </section>
        </div>
    </section>

    <?php endif; ?>

    <!-- =====================================================
         PLANTILLAS Y RESPUESTAS
    ====================================================== -->
    <?php if ($canLibraryView): ?>
    <section class="wa-tab-panel <?= $initialWhatsappTab === 'library' ? 'is-active' : '' ?>" data-wa-panel="library">
        <div class="wa-library-wrap">
            <section class="panel wa-section-intro wa-library-intro">
                <div>
                    <span class="wa-kicker">Mensajería preparada</span>
                    <h2>Plantillas y respuestas</h2>
                    <p>Administra por separado las plantillas oficiales de WhatsApp y los textos rápidos internos del equipo.</p>
                </div>
                <div class="wa-section-intro__hint"><strong>Regla simple</strong><span>Fuera de 24 h → Plantilla WhatsApp · Dentro de 24 h → Respuesta rápida o texto libre</span></div>
            </section>

            <section class="wa-library-grid">
                <article class="panel wa-library-card wa-library-card--official">
                    <div class="wa-library-card__head">
                        <div>
                            <span class="wa-kicker">Oficial · Meta / WhatsApp</span>
                            <h3>Plantillas WhatsApp</h3>
                            <p>Sirven para retomar o iniciar contacto cuando la conversación está fuera de la ventana de 24 horas.</p>
                        </div>
                        <div class="wa-library-actions">
                            <button type="button" class="wa-soft-button" id="waTemplateRefreshBtn">↻ Actualizar</button>
                            <?php if ($canLibraryManage): ?><button type="button" class="btn-primary" id="waNewTemplateBtn">+ Nueva plantilla</button><?php endif; ?>
                        </div>
                    </div>
                    <div class="wa-template-summary">
                        <div><span>Aprobadas</span><strong id="waTplApproved">0</strong><small>Listas para enviar</small></div>
                        <div><span>Pendientes</span><strong id="waTplPending">0</strong><small>Esperando revisión</small></div>
                        <div><span>Rechazadas</span><strong id="waTplRejected">0</strong><small>Conviene revisar contenido</small></div>
                    </div>
                    <div class="wa-library-note"><strong>Importante:</strong> crearla desde S.I.G.O.I. la envía a revisión. Solo cuando aparezca como <b>Aprobada</b> podrá usarse para retomar un chat. Una plantilla <b>Pendiente</b> todavía no puede editarse; cuando Meta termine la revisión podrás corregirla conservando el mismo nombre.</div>
                    <div class="wa-official-template-list" id="waOfficialTemplateList"><div class="wa-empty-mini">Cargando plantillas de WhatsApp...</div></div>
                </article>

                <article class="panel wa-library-card wa-library-card--quick">
                    <div class="wa-library-card__head">
                        <div>
                            <span class="wa-kicker">Internas · S.I.G.O.I.</span>
                            <h3>Respuestas rápidas</h3>
                            <p>Textos reutilizables para responder más rápido dentro de una conversación activa.</p>
                        </div>
                        <?php if ($canLibraryManage): ?><button type="button" class="btn-primary" id="waNewQuickBtn">+ Nueva respuesta</button><?php endif; ?>
                    </div>
                    <div class="wa-quick-summary"><strong id="waQuickCount">0</strong><span>respuestas disponibles</span><small>En el chat aparecen con el botón ⚡.</small></div>
                    <div class="wa-quick-admin-list" id="waQuickAdminList"><div class="wa-empty-mini">Cargando respuestas rápidas...</div></div>
                </article>
            </section>
        </div>
    </section>

    <?php endif; ?>

    <!-- =====================================================
         ANALÍTICA
    ====================================================== -->
    <?php if ($canAnalyticsView): ?>
    <section class="wa-tab-panel <?= $initialWhatsappTab === 'analytics' ? 'is-active' : '' ?>" data-wa-panel="analytics">
        <div class="wa-analytics-wrap">
            <section class="panel wa-analytics-head">
                <div>
                    <span class="wa-kicker">Datos para decidir</span>
                    <h2>Analítica de WhatsApp</h2>
                    <p>Lo importante para saber cuánto llega, cuánto demora la atención y qué necesita seguimiento.</p>
                </div>
                <label class="wa-range-select">Periodo
                    <select id="waAnalyticsRange">
                        <option value="7">Últimos 7 días</option>
                        <option value="30" selected>Últimos 30 días</option>
                        <option value="90">Últimos 90 días</option>
                    </select>
                </label>
            </section>

            <section class="panel wa-analytics-insight wa-analytics-insight--primary">
                <div class="wa-analytics-insight__icon">i</div>
                <div>
                    <strong>Qué está pasando</strong>
                    <p id="waAnalyticsInsight">Cargando una lectura sencilla de los datos...</p>
                </div>
            </section>

            <section class="wa-analytics-primary-cards" aria-label="Indicadores principales">
                <article class="wa-an-card wa-an-card--primary">
                    <span>Conversaciones activas</span>
                    <strong id="waAnConversations">0</strong>
                    <small id="waAnContactsMix">Nuevos y recurrentes del periodo.</small>
                </article>
                <article class="wa-an-card wa-an-card--primary">
                    <span>Respuesta humana típica</span>
                    <strong id="waAnResponse">—</strong>
                    <small id="waAnResponseMeta">Mediana de primera respuesta humana.</small>
                </article>
                <article class="wa-an-card wa-an-card--primary">
                    <span>Resueltas</span>
                    <strong id="waAnResolved">0</strong>
                    <small id="waAnResolutionMeta">Conversaciones cerradas en el periodo.</small>
                </article>
                <article class="wa-an-card wa-an-card--attention">
                    <span>Pendientes ahora</span>
                    <strong id="waAnPending">0</strong>
                    <small id="waAnPendingMeta">Conversaciones que todavía necesitan atención.</small>
                </article>
            </section>

            <section class="wa-analytics-health" aria-label="Salud operativa">
                <article><span>Mensajes recibidos</span><strong id="waAnInbound">0</strong><small>Mensajes enviados por clientes.</small></article>
                <article><span>Fuera de horario</span><strong id="waAnOutside">0</strong><small>Demanda cuando no había atención humana.</small></article>
                <article><span>Cobertura automática</span><strong id="waAnAutomation">0%</strong><small>Conversaciones con respuesta del bot.</small></article>
                <article><span>Lectura de salientes</span><strong id="waAnReadRate">—</strong><small>Porcentaje de mensajes salientes marcados como leídos.</small></article>
            </section>

            <section class="panel wa-attention-panel">
                <div class="wa-attention-panel__head">
                    <div>
                        <span class="wa-kicker">Prioridad operativa</span>
                        <h3>Qué requiere atención</h3>
                        <p>Señales rápidas para que recepción sepa dónde actuar primero.</p>
                    </div>
                    <span class="wa-attention-status" id="waAttentionStatus">Revisando...</span>
                </div>
                <div class="wa-attention-grid">
                    <div><span>Esperando +15 min</span><strong id="waAnPending15">0</strong><small>Casos pendientes con espera relevante.</small></div>
                    <div><span>Esperando +60 min</span><strong id="waAnPending60">0</strong><small>Casos que conviene revisar primero.</small></div>
                    <div><span>Mensajes fallidos</span><strong id="waAnFailed">0</strong><small>Envíos que no pudieron completarse.</small></div>
                    <div><span>Tiempo medio de resolución</span><strong id="waAnResolution">—</strong><small>Desde el primer mensaje hasta cerrar el caso.</small></div>
                </div>
            </section>

            <section class="wa-analytics-grid wa-analytics-grid--balanced">
                <article class="panel wa-chart-card wa-chart-card--demand">
                    <div class="wa-chart-card__head">
                        <div><span class="wa-kicker">Demanda</span><h3>Horas con más mensajes</h3><p>Ayuda a decidir cuándo conviene reforzar la atención humana.</p></div>
                        <strong class="wa-chart-summary" id="waHourSummary">—</strong>
                    </div>
                    <div class="wa-hour-chart" id="waHourChart"></div>
                    <div class="wa-chart-axis-note"><span>00:00</span><span>12:00</span><span>23:00</span></div>
                </article>

                <article class="panel wa-chart-card wa-chart-card--activity">
                    <div class="wa-chart-card__head">
                        <div><span class="wa-kicker">Actividad</span><h3>Evolución diaria</h3><p>Compara lo que escriben los clientes con lo que responde el equipo y el sistema.</p></div>
                        <strong class="wa-chart-summary" id="waDailySummary">—</strong>
                    </div>
                    <div class="wa-chart-legend"><span><i class="is-in"></i> Recibidos</span><span><i class="is-out"></i> Enviados</span></div>
                    <div class="wa-daily-chart" id="waDailyChart"></div>
                </article>

                <article class="panel wa-chart-card">
                    <div class="wa-chart-card__head"><div><span class="wa-kicker">Automatización</span><h3>Consultas más frecuentes</h3><p>Reglas automáticas que más veces ayudaron durante el periodo.</p></div></div>
                    <div class="wa-ranking" id="waTopRules"><div class="wa-empty-mini">Sin datos todavía.</div></div>
                </article>

                <article class="panel wa-chart-card">
                    <div class="wa-chart-card__head"><div><span class="wa-kicker">Equipo</span><h3>Respuestas humanas</h3><p>Cuántas respuestas salieron desde S.I.G.O.I., WhatsApp Business o YCloud.</p></div></div>
                    <div class="wa-ranking" id="waAgentRanking"><div class="wa-empty-mini">Sin datos todavía.</div></div>
                </article>

                <article class="panel wa-chart-card wa-chart-card--sources">
                    <div class="wa-chart-card__head"><div><span class="wa-kicker">Origen</span><h3>De dónde llegan los contactos</h3><p>Distingue tráfico orgánico y campañas Click-to-WhatsApp cuando existe esa información.</p></div></div>
                    <div class="wa-ranking" id="waSourceRanking"><div class="wa-empty-mini">Sin datos todavía.</div></div>
                </article>
            </section>

            <details class="panel wa-metric-guide wa-metric-guide--details">
                <summary>Cómo interpretar estas métricas</summary>
                <div class="wa-metric-guide__grid">
                    <div><strong>Respuesta humana típica</strong><span>Usamos la mediana para que una conversación muy demorada no distorsione todo el resultado.</span></div>
                    <div><strong>Cobertura automática</strong><span>Porcentaje de conversaciones donde alguna regla automática llegó a responder.</span></div>
                    <div><strong>Pendiente</strong><span>Conversación abierta que todavía necesita seguimiento humano.</span></div>
                    <div><strong>Lectura de salientes</strong><span>Qué parte de los mensajes enviados registra confirmación de lectura de WhatsApp.</span></div>
                </div>
            </details>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!$visibleWhatsappTabs): ?>
    <section class="panel wa-permission-empty-state">
        <strong>No tienes apartados de WhatsApp asignados.</strong>
        <span>Un administrador puede habilitar Bandeja, Automatización, Plantillas y respuestas o Analítica desde Permisos.</span>
    </section>
    <?php endif; ?>
</main>

<?php if ($canAutomationModify): ?>
<div class="wa-modal hidden" id="waRuleModal">
    <div class="wa-modal__backdrop" data-wa-close-rule></div>
    <form class="wa-modal__dialog" id="waRuleForm">
        <div class="wa-modal__header">
            <div><span class="wa-kicker">Regla de respuesta</span><h2 id="waRuleModalTitle">Nueva regla</h2></div>
            <button type="button" class="wa-modal__close" data-wa-close-rule>×</button>
        </div>
        <input type="hidden" id="waRuleId">
        <div class="wa-modal__grid">
            <label class="wa-field wa-span-2"><span>Nombre de la regla *</span><input id="waRuleName" maxlength="120" required placeholder="Ej. Citas"></label>
            <label class="wa-field"><span>Prioridad *</span><input id="waRulePriority" type="number" min="1" max="9999" value="100" required><small>1 se evalúa antes que 100.</small></label>
            <label class="wa-check-field"><input id="waRuleActive" type="checkbox" checked><span>Regla activa</span></label>
            <label class="wa-field wa-span-2"><span>Palabras o frases clave *</span><textarea id="waRuleKeywords" rows="4" required placeholder="cita&#10;agendar&#10;reservar"></textarea><small>Una por línea o separadas por coma.</small></label>
            <label class="wa-field wa-span-2"><span>Respuesta *</span><textarea id="waRuleResponse" rows="6" required></textarea></label>
        </div>
        <div class="wa-modal__actions"><button type="button" class="btn-secondary" data-wa-close-rule>Cancelar</button><button type="submit" class="btn-primary">Guardar regla</button></div>
    </form>
</div>
<?php endif; ?>

<?php if ($canLibraryManage): ?>
<div class="wa-modal hidden" id="waCreateTemplateModal">
    <div class="wa-modal__backdrop" data-wa-close-template-create></div>
    <form class="wa-modal__dialog wa-template-create-dialog" id="waCreateTemplateForm">
        <div class="wa-modal__header">
            <div><span class="wa-kicker" id="waTplModalKicker">Plantilla oficial</span><h2 id="waTplModalTitle">Nueva plantilla WhatsApp</h2><p id="waTplModalDescription">Se enviará a Meta/WhatsApp para revisión.</p></div>
            <button type="button" class="wa-modal__close" data-wa-close-template-create>×</button>
        </div>
        <input type="hidden" id="waTplMode" value="create">
        <div class="wa-template-create-grid">
            <div class="wa-template-create-fields">
                <label class="wa-field"><span>Nombre interno *</span><input id="waTplName" maxlength="120" placeholder="retomar_consulta" required><small id="waTplNameHelp">Minúsculas, números y guion bajo. S.I.G.O.I. lo normaliza automáticamente.</small></label>
                <div class="wa-form-grid wa-form-grid--compact">
                    <label class="wa-field"><span>Categoría *</span><select id="waTplCategory"><option value="UTILITY">Utilidad</option><option value="MARKETING">Marketing</option></select></label>
                    <label class="wa-field"><span>Idioma *</span><select id="waTplLanguage"><option value="es">Español</option><option value="en_US">English (US)</option></select></label>
                </div>
                <label class="wa-field"><span>Mensaje *</span><textarea id="waTplBody" rows="7" maxlength="1024" required placeholder="Hola {{1}}, retomamos tu consulta sobre {{2}}. Si aún necesitas ayuda, respóndenos a este mensaje."></textarea><small>Usa {{1}}, {{2}}, {{3}}... para los datos que cambiarán en cada envío.</small></label>
                <label class="wa-field"><span>Pie opcional</span><input id="waTplFooter" maxlength="60" placeholder="IPHCI · Ejecutiva de Citas"></label>
                <div class="wa-template-edit-note hidden" id="waTplEditNote"></div>
                <div class="wa-template-examples" id="waTplExamples"><div class="wa-empty-mini">Agrega variables al mensaje para completar ejemplos.</div></div>
            </div>
            <aside class="wa-template-live-preview">
                <span class="wa-kicker">Vista previa</span>
                <h3>Así se verá</h3>
                <div class="wa-template-phone-preview"><div class="wa-template-phone-bubble" id="waTplPreview">Escribe el mensaje para ver una vista previa.</div></div>
                <div class="wa-template-category-help" id="waTplCategoryHelp"><strong>Utilidad</strong><span>Ideal para dar seguimiento a una solicitud o gestión que el paciente ya inició.</span></div>
            </aside>
        </div>
        <div class="wa-modal__actions"><button type="button" class="btn-secondary" data-wa-close-template-create>Cancelar</button><button type="submit" class="btn-primary" id="waTplCreateSubmit">Enviar a revisión</button></div>
    </form>
</div>

<div class="wa-modal hidden" id="waQuickEditModal">
    <div class="wa-modal__backdrop" data-wa-close-quick></div>
    <form class="wa-modal__dialog wa-quick-edit-dialog" id="waQuickEditForm">
        <div class="wa-modal__header">
            <div><span class="wa-kicker">Respuesta interna</span><h2 id="waQuickEditTitle">Nueva respuesta rápida</h2></div>
            <button type="button" class="wa-modal__close" data-wa-close-quick>×</button>
        </div>
        <input type="hidden" id="waQuickId">
        <div class="wa-modal__grid">
            <label class="wa-field"><span>Nombre *</span><input id="waQuickTitle" maxlength="120" required placeholder="Ubicación de la sede"></label>
            <label class="wa-field"><span>Atajo</span><div class="wa-shortcut-input"><b>/</b><input id="waQuickShortcut" maxlength="80" placeholder="ubicacion"></div><small>Opcional. Si lo dejas vacío se crea desde el nombre.</small></label>
            <label class="wa-field wa-span-2"><span>Categoría</span><input id="waQuickCategory" maxlength="80" placeholder="Información" value="General"></label>
            <label class="wa-field wa-span-2"><span>Mensaje *</span><textarea id="waQuickContent" rows="7" maxlength="4000" required placeholder="{{saludo}} 👋&#10;&#10;Estamos ubicados en..."></textarea><div class="wa-quick-dynamic-tools"><button type="button" class="wa-quick-token-btn" id="waQuickInsertGreeting">＋ Insertar saludo automático</button><code>{{saludo}}</code></div><small><strong>{{saludo}}</strong> se reemplaza al usar la respuesta por Buenos días, Buenas tardes o Buenas noches según la hora de Lima. El texto se inserta en el chat y todavía podrás editarlo antes de enviarlo.</small></label>
            <label class="wa-check-field"><input id="waQuickActive" type="checkbox" checked><span>Disponible en el chat</span></label>
        </div>
        <div class="wa-modal__actions"><button type="button" class="btn-secondary" data-wa-close-quick>Cancelar</button><button type="submit" class="btn-primary">Guardar respuesta</button></div>
    </form>
</div>
<?php endif; ?>

<?php if ($canReply): ?>
<div class="wa-modal hidden" id="waSendTemplateModal">
    <div class="wa-modal__backdrop" data-wa-close-template-send></div>
    <form class="wa-modal__dialog wa-send-template-dialog" id="waSendTemplateForm">
        <div class="wa-modal__header">
            <div>
                <span class="wa-kicker">Retomar conversación</span>
                <h2 id="waSendTemplateTitle">Seleccionar plantilla</h2>
                <p id="waSendTemplateSubtitle">Elige una plantilla aprobada según lo que necesites comunicar al paciente.</p>
            </div>
            <button type="button" class="wa-modal__close" data-wa-close-template-send>×</button>
        </div>

        <section class="wa-template-picker-stage" id="waTemplatePickerStage">
            <div class="wa-template-picker-toolbar">
                <label class="wa-template-picker-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="waSendTemplateSearch" autocomplete="off" placeholder="Buscar por nombre o contenido...">
                </label>
                <div class="wa-template-picker-count" id="waSendTemplateCount">Cargando plantillas...</div>
            </div>
            <div class="wa-template-picker-list" id="waSendTemplatePickerList">
                <div class="wa-empty-mini">Cargando plantillas aprobadas...</div>
            </div>
        </section>

        <section class="wa-template-prepare-stage hidden" id="waTemplatePrepareStage">
            <button type="button" class="wa-template-picker-back" id="waSendTemplateBack">← Elegir otra plantilla</button>
            <div class="wa-template-selected-summary" id="waSendTemplateSelectedSummary"></div>
            <div class="wa-send-template-layout">
                <div class="wa-send-template-vars" id="waSendTemplateVariables"><div class="wa-empty-mini">Selecciona una plantilla para continuar.</div></div>
                <aside class="wa-template-live-preview wa-template-live-preview--send">
                    <span class="wa-kicker">Vista previa</span>
                    <div class="wa-template-phone-preview"><div class="wa-template-phone-bubble" id="waSendTemplatePreview">Selecciona una plantilla.</div></div>
                </aside>
            </div>
            <div class="wa-send-template-note">La plantilla puede enviarse aunque hayan pasado más de 24 horas. El chat libre volverá a habilitarse cuando el paciente responda.</div>
        </section>

        <div class="wa-modal__actions">
            <button type="button" class="btn-secondary" data-wa-close-template-send>Cancelar</button>
            <button type="submit" class="btn-primary hidden" id="waSendTemplateSubmit">Enviar plantilla</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="wa-lightbox hidden" id="waLightbox">
    <button type="button" class="wa-lightbox__close" id="waLightboxClose">×</button>
    <img id="waLightboxImage" alt="Vista previa">
</div>

<script>
window.WHATSAPP_PERMISSIONS = <?= json_encode($whatsappPermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.WHATSAPP_INITIAL_TAB = <?= json_encode($initialWhatsappTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.WHATSAPP_CURRENT_USER = <?= json_encode([
    'id' => auth_user_id(),
    'nombre' => auth_user_display_name(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/js/whatsapp.js?v=7.2"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
