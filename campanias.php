<?php include 'templates/header.php'; ?>

<main class="container">
    <section class="dashboard-hero panel">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Módulo independiente</span>
            <h1>Campañas</h1>
            <p>Gestión de campañas, registros y seguimiento en un apartado separado del módulo principal.</p>
        </div>

        <div class="dashboard-hero__actions">
            <?php if (auth_can_write_campaigns()): ?>
                <button id="openCampaignFormBtn" class="btn-primary" type="button">Nueva campaña</button>
            <?php else: ?>
                <span class="readonly-notice">Modo solo lectura</span>
            <?php endif; ?>
        </div>
    </section>

    <section class="stats-grid">
        <article class="stat-card">
            <span class="stat-card__label">Total campañas</span>
            <strong id="statCampaniasTotal">0</strong>
            <small>Campañas registradas</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">Activas</span>
            <strong id="statCampaniasActivas">0</strong>
            <small>Campañas en curso</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">Borrador</span>
            <strong id="statCampaniasBorrador">0</strong>
            <small>Campañas aún no iniciadas</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">Finalizadas</span>
            <strong id="statCampaniasFinalizadas">0</strong>
            <small>Campañas cerradas</small>
        </article>
    </section>

    <section class="panel filters-shell">
        <div class="filters-toolbar">
            <div class="filters-toolbar__left">
                <button type="button" id="toggleCampaignFiltersBtn" class="btn-secondary filters-toggle-btn">
                    Filtros
                </button>
                <span id="campaignFiltersSummary" class="filters-summary">Sin filtros activos</span>
            </div>

            <button type="button" id="clearCampaignFiltersBtn" class="btn-secondary filters-clear-btn">
                Limpiar
            </button>
        </div>

        <div id="campaignFiltersPanel" class="filters-panel hidden">
            <div class="filters-grid">
                <div class="field-group">
                    <label for="campaignSearchInput">Buscar</label>
                    <div class="input-icon">
                        <span class="input-icon__icon">🔍</span>
                        <input type="text" id="campaignSearchInput" placeholder="Buscar por nombre o descripción">
                    </div>
                </div>

                <div class="field-group">
                    <label for="campaignEstadoFilter">Estado</label>
                    <select id="campaignEstadoFilter">
                        <option value="">Todos</option>
                        <option value="borrador">Borrador</option>
                        <option value="activa">Activa</option>
                        <option value="finalizada">Finalizada</option>
                    </select>
                </div>

                <div class="field-group">
                    <label for="campaignMesFilter">Mes</label>
                    <input type="month" id="campaignMesFilter">
                </div>
            </div>
        </div>
    </section>

    <section class="panel table-panel" id="campaignsSection">
        <div class="table-header">
            <h2>Campañas</h2>

            <div style="display:flex; gap:10px; align-items:center;">
                <span id="campaignsTotalLabel">0 campañas</span>

                <button id="exportCampaniasBtn" class="btn-secondary" type="button">
                    Exportar Excel
                </button>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Fecha Inicio</th>
                        <th>Estado</th>
                        <th>Total Registros</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="campaignsTableBody">
                    <tr class="empty-row">
                        <td colspan="6">
                            No hay campañas aún.<br>
                            <small>Crea la primera con "Nueva campaña"</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel hidden campaign-detail" id="campaignDetailSection">
        <div class="table-header campaign-detail__header">
            <div>
                <h2 id="campaignDetailTitle">Detalle de campaña</h2>
                <span id="campaignDetailMeta">-</span>
            </div>

            <div class="dashboard-hero__actions">
                <button id="backToCampaignsBtn" class="btn-secondary" type="button">Volver</button>

                <?php if (auth_can_write_campaigns()): ?>
                    <button id="editCampaignBtn" class="btn-secondary" type="button">Editar campaña</button>
                    <button id="openRegistroFormBtn" class="btn-primary" type="button">Nuevo registro</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="stats-grid campaign-detail__stats">
            <article class="stat-card">
                <span class="stat-card__label">Total registros</span>
                <strong id="detailStatTotal">0</strong>
                <small>Registros de la campaña</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Confirmados</span>
                <strong id="detailStatConfirmados">0</strong>
                <small>Con cita confirmada</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Pendientes</span>
                <strong id="detailStatPendientes">0</strong>
                <small>Por confirmar</small>
            </article>

            <article class="stat-card">
                <span class="stat-card__label">Asistieron</span>
                <strong id="detailStatAsistieron">0</strong>
                <small>Asistencia registrada</small>
            </article>
        </div>

        <section class="campaign-detail__filters">
            <div class="campaign-detail__filters-heading">
                <div>
                    <span>Filtros</span>
                    <h3>Buscar dentro de la campaña</h3>
                </div>
                <small>Los resultados se actualizan automáticamente.</small>
            </div>

            <div class="filters-grid campaign-detail__filter-grid">
                <div class="field-group">
                    <label for="registroSearchInput">Buscar registro</label>
                    <input type="text" id="registroSearchInput" placeholder="Nombre o teléfono">
                </div>

                <div class="field-group">
                    <label for="registroConfirmoFilter">¿Confirmó Cita?</label>
                    <select id="registroConfirmoFilter">
                        <option value="">Todos</option>
                        <option value="si">Sí</option>
                        <option value="no">No</option>
                        <option value="pendiente">Pendiente</option>
                    </select>
                </div>

                <div class="field-group">
                    <label for="registroAsistioFilter">¿Asistió?</label>
                    <select id="registroAsistioFilter">
                        <option value="">Todos</option>
                        <option value="asistio">Asistió</option>
                        <option value="no_asistio">No asistió</option>
                    </select>
                </div>
            </div>
        </section>

        <div class="campaign-detail__records">
            <div class="campaign-detail__records-heading">
                <div>
                    <span>Registros</span>
                    <h3>Pacientes de la campaña</h3>
                </div>
                <small>Consulta, edita o revisa cada registro.</small>
            </div>

        <div class="table-wrapper campaign-detail__table-wrap">
            <table class="records-table">
                <thead>
                    <tr>
                        <th>Nombre y Apellido</th>
                        <th>Número Teléfono</th>
                        <th>DNI</th>
                        <th>¿Confirmó Cita?</th>
                        <th>¿Asistió?</th>
                        <th>Monto</th>
                        <th>¿Qué se realizó?</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="registrosTableBody">
                    <tr class="empty-row">
                        <td colspan="7">
                            No hay registros cargados en esta campaña.<br>
                            <small>Crea el primero con "Nuevo registro"</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        </div>
    </section>
</main>

<div id="campaignFormModal" class="modal hidden">
    <div class="modal-backdrop" id="closeCampaignFormBackdrop"></div>

    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2 id="campaignFormTitle">Nueva campaña</h2>
                <p id="campaignFormSubtitle">Completa los datos generales de la campaña.</p>
            </div>
            <button id="closeCampaignFormBtn" class="btn-close" type="button">×</button>
        </div>

        <form id="campaignForm" class="form-layout">
            <input type="hidden" id="campaign_id" name="campaign_id">

            <section class="form-section">
                <h3>Datos generales</h3>
                <br>
                <div class="form-grid">
                    <div class="field-group">
                        <label for="campaign_nombre">Nombre de la campaña</label>
                        <input type="text" id="campaign_nombre" name="nombre" required>
                    </div>

                    <div class="field-group">
                        <label for="campaign_fecha_inicio">Fecha de inicio</label>
                        <input type="date" id="campaign_fecha_inicio" name="fecha_inicio" required>
                    </div>

                    <div class="field-group">
                        <label for="campaign_estado">Estado</label>
                        <select id="campaign_estado" name="estado" required>
                            <option value="borrador">Borrador</option>
                            <option value="activa">Activa</option>
                            <option value="finalizada">Finalizada</option>
                        </select>
                    </div>

                    <div class="field-group field-group-full">
                        <label for="campaign_descripcion">Descripción</label>
                        <textarea id="campaign_descripcion" name="descripcion" rows="3" placeholder="Descripción breve de la campaña"></textarea>
                    </div>
                </div>
            </section>

            <section class="form-section">
                <div class="table-header">
                    <h3>Campos base fijos</h3>
                </div>

                <div class="campaign-base-grid">
                    <div class="campaign-base-item">Nombre y Apellido</div>
                    <div class="campaign-base-item">Número Teléfono</div>
                    <div class="campaign-base-item">DNI</div>
                    <div class="campaign-base-item">¿Confirmó Cita?</div>
                    <div class="campaign-base-item">¿Asistió?</div>
                    <div class="campaign-base-item">Monto</div>
                    <div class="campaign-base-item">¿Qué se realizó?</div>
                </div>
            </section>

            <section class="form-section">
                <div class="table-header">
                    <h3>Campos extra</h3>
                    <button type="button" id="addCampoExtraBtn" class="btn-secondary">+ Agregar campo</button>
                </div>

                <div id="camposExtraContainer" class="form-grid"></div>
            </section>

            <div class="form-actions">
                <button type="button" id="cancelCampaignFormBtn" class="btn-secondary">Cancelar</button>
                <button type="submit" id="submitCampaignFormBtn" class="btn-primary">Guardar campaña</button>
            </div>
        </form>
    </div>
</div>

<div id="registroFormModal" class="modal hidden">
    <div class="modal-backdrop" id="closeRegistroFormBackdrop"></div>

    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2 id="registroFormTitle">Nuevo registro</h2>
                <p id="registroFormSubtitle">Completa la información base y adicional de la campaña.</p>
            </div>
            <button id="closeRegistroFormBtn" class="btn-close" type="button">×</button>
        </div>

        <form id="registroForm" class="form-layout">
            <input type="hidden" id="registro_id" name="registro_id">
            <input type="hidden" id="registro_campania_id" name="campania_id">

            <section class="form-section">
                <h3>Datos base</h3>
                <br>
                <div class="form-grid">
                    <div class="field-group">
                        <label for="registro_nombre_apellido">Nombre y Apellido</label>
                        <input type="text" id="registro_nombre_apellido" name="nombre_apellido" required>
                    </div>

                    <div class="field-group">
                        <label for="registro_numero_telefono">Número Teléfono</label>
                        <input type="text" id="registro_numero_telefono" name="numero_telefono" required>
                    </div>

                    <div class="field-group">
                        <label for="registro_dni">DNI</label>
                        <input type="text" id="registro_dni" name="dni" placeholder="DNI">
                    </div>

                    <div class="field-group">
                        <label for="registro_confirmo_cita">¿Confirmó Cita?</label>
                        <select id="registro_confirmo_cita" name="confirmo_cita">
                            <option value="pendiente" selected>Pendiente</option>
                            <option value="si">Sí</option>
                            <option value="no">No</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="registro_asistio">¿Asistió?</label>
                        <select id="registro_asistio" name="asistio">
                            <option value="">Seleccionar</option>
                            <option value="asistio">Asistió</option>
                            <option value="no_asistio">No asistió</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="registro_monto">Monto</label>
                        <input type="number" step="0.01" id="registro_monto" name="monto" placeholder="0.00">
                    </div>

                    <div class="field-group field-group-full">
                        <label for="registro_que_se_realizo">¿Qué se realizó?</label>
                        <input type="text" id="registro_que_se_realizo" name="que_se_realizo">
                    </div>
                </div>
            </section>

            <section class="form-section">
                <h3>Campos extra</h3>
                <br>
                <div id="registroCamposExtraContainer" class="form-grid"></div>
            </section>

            <div class="form-actions">
                <button type="button" id="cancelRegistroFormBtn" class="btn-secondary">Cancelar</button>
                <button type="submit" id="submitRegistroFormBtn" class="btn-primary">Guardar registro</button>
            </div>
        </form>
    </div>
</div>

<div id="registroDetailModal" class="modal hidden">
    <div class="modal-backdrop" id="closeRegistroDetailBackdrop"></div>

    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Detalle del registro</h2>
                <p>Vista completa de la información cargada.</p>
            </div>
            <button id="closeRegistroDetailBtn" class="btn-close" type="button">×</button>
        </div>

        <div id="registroDetailContent" class="detail-grid"></div>
    </div>
</div>

<div id="confirmModal" class="modal hidden">
    <div class="modal-backdrop" id="closeConfirmBackdrop"></div>

    <div class="modal-content modal-content--sm">
        <div class="modal-header">
            <div>
                <h2 id="confirmModalTitle">Confirmar acción</h2>
                <p id="confirmModalText">¿Deseas continuar?</p>
            </div>
            <button id="closeConfirmModalBtn" class="btn-close" type="button">×</button>
        </div>

        <div class="form-actions">
            <button type="button" id="cancelConfirmBtn" class="btn-secondary">Cancelar</button>
            <button type="button" id="acceptConfirmBtn" class="btn-primary btn-danger">Eliminar</button>
        </div>
    </div>
</div>

<script>
window.APP_PERMISSIONS = {
    canWriteCampaigns: <?= auth_can_write_campaigns() ? 'true' : 'false' ?>
};
</script>

<script src="assets/js/campanias.js?v=4.1"></script>
<?php include 'templates/footer.php'; ?>
