<?php include 'templates/header.php'; ?>

<main class="container">
    <section class="dashboard-hero panel">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Panel principal</span>
            <h1>Data de Pacientes</h1>
            <p>Registro, seguimiento y control de leads de atención en una sola vista.</p>
        </div>

        <div class="dashboard-hero__actions">
            <?php if (auth_can_write_patients()): ?>
                <button id="openFormBtn" class="btn-primary" type="button">Nuevo registro</button>
            <?php else: ?>
                <span class="readonly-notice">Modo solo lectura</span>
            <?php endif; ?>
        </div>
    </section>

    <section class="stats-grid">
        <article class="stat-card">
            <span class="stat-card__label">Total registros</span>
            <strong id="statTotal">0</strong>
            <small>Registros cargados en el sistema</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">Confirmados</span>
            <strong id="statConfirmados">0</strong>
            <small>Citas con estado confirmado</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">No Confirmados</span>
            <strong id="statNoConfirmados">0</strong>
            <small>Registros con estado no confirmado</small>
        </article>

        <article class="stat-card">
            <span class="stat-card__label">Convenios</span>
            <strong id="statConvenios">0</strong>
            <small>Registros del módulo convenios</small>
        </article>
    </section>

    <section class="panel filters-shell">

        <!-- CABECERA -->
        <div class="filters-toolbar">
            <div class="filters-toolbar__left">

                <!-- COLUMNAS VISIBLES -->
                <div class="columns-control" id="columnsControl">
                    <button
                        type="button"
                        id="columnsToggleBtn"
                        class="btn-secondary columns-toggle-btn"
                        aria-expanded="false"
                        aria-controls="columnsMenu"
                    >
                        Columnas
                    </button>

                    <div id="columnsMenu" class="columns-menu hidden">
                        <div class="columns-menu__header">
                            <strong>Columnas visibles</strong>
                            <small>Elige qué información mostrar.</small>
                        </div>

                        <div class="columns-menu__options">
                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="fecha_contacto" checked>
                                <span>Fecha contacto</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="paciente" checked>
                                <span>Paciente</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="telefono" checked>
                                <span>Teléfono</span>
                            </label>
                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="dni" checked>
                                <span>DNI</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="canal" checked>
                                <span>Canal</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="tipo_atencion" checked>
                                <span>Tipo atención</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="servicio" checked>
                                <span>Servicio</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="detalle_consulta" checked>
                                <span>Detalle consulta</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="status_cita" checked>
                                <span>Status</span>
                            </label>

                            <label class="column-option">
                                <input type="checkbox" data-column-toggle="fecha_cita" checked>
                                <span>Fecha cita</span>
                            </label>
                        </div>

                        <div class="columns-menu__footer">
                            <button type="button" id="resetColumnsBtn" class="columns-reset-btn">
                                Restaurar columnas
                            </button>
                        </div>
                    </div>
                </div>

                <!-- FILTROS -->
                <button type="button" id="toggleFiltersBtn" class="btn-secondary filters-toggle-btn">
                    Filtros
                </button>

                <!-- RESUMEN -->
                <span id="filtersSummary" class="filters-summary">
                    Sin filtros activos
                </span>
            </div>

            <button type="button" id="clearFiltersBtn" class="btn-secondary filters-clear-btn">
                Limpiar
            </button>
        </div>


        <!-- PANEL DE FILTROS -->
        <div id="filtersPanel" class="filters-panel hidden">

            <!-- FILTROS PRINCIPALES -->
            <div class="filters-main-grid">

                <div class="field-group filter-search">
                    <label for="searchInput">Buscar</label>

                    <div class="input-icon">
                        <span class="input-icon__icon">🔍</span>

                        <input type="text" id="searchInput" placeholder="Buscar por nombre, teléfono o detalle">
                    </div>
                </div>

                <div class="field-group">
                    <label for="filterCanal">Canal</label>

                    <select id="filterCanal">
                        <option value="">Todos</option>
                        <option value="Redes">Redes</option>
                        <option value="Web">Web</option>
                        <option value="Whatsapp">Whatsapp</option>
                        <option value="Fachada">Fachada</option>
                        <option value="Referido Drs">Referido Dr.</option>
                        <option value="Seguimiento">Seguimiento</option>
                        <option value="Interno">Interno</option>
                    </select>
                </div>

                <div class="field-group">
                    <label for="filterTipo">Tipo de Atención</label>

                    <select id="filterTipo">
                        <option value="">Todos</option>
                        <option value="Centro Médico">Centro Médico</option>
                        <option value="Procedimiento">Procedimiento</option>
                        <option value="Convenios">Convenios</option>
                    </select>
                </div>

                <div class="field-group">
                    <label for="filterServicio">Servicio</label>

                    <select id="filterServicio">
                        <option value="">Todos</option>
                        <option value="Consulta">Consulta</option>
                        <option value="Ecografía">Ecografía</option>
                        <option value="Laboratorio">Laboratorio</option>
                        <option value="Vitaminas">Vitaminas</option>
                        <option value="2da opinión">2da opinión</option>
                        <option value="Perdido">Perdido</option>
                        <option value="Alquiler">Alquiler</option>
                        <option value="Paquete Ads">Paquete Ads</option>
                    </select>
                </div>

            </div>


            <!-- FILTROS DE SEGUIMIENTO -->
            <div class="filters-secondary-grid">

                <div class="field-group filter-status">
                    <label for="filterStatus">Status de Cita</label>

                    <select id="filterStatus">
                        <option value="">Todos</option>
                        <option value="Confirmado">Confirmado</option>
                        <option value="No Confirmado">No Confirmado</option>
                        <option value="Canceló">Canceló</option>
                        <option value="Reprogramó">Reprogramó</option>
                        <option value="Por Confirmar">Por Confirmar</option>
                        <option value="No Asistió">No Asistió</option>
                    </select>
                </div>


                <div class="field-group filter-period">
                    <label>Período rápido</label>

                    <div class="quick-dates">
                        <button type="button" class="quick-btn" data-range="today">
                            Hoy
                        </button>

                        <button type="button" class="quick-btn" data-range="7">
                            7 días
                        </button>

                        <button type="button" class="quick-btn" data-range="30">
                            30 días
                        </button>

                        <button type="button" class="quick-btn" data-range="month">
                            Este mes
                        </button>
                    </div>
                </div>


                <div class="field-group">
                    <label for="filterFechaDesde">Desde</label>

                    <input type="date" id="filterFechaDesde">
                </div>


                <div class="field-group">
                    <label for="filterFechaHasta">Hasta</label>

                    <input type="date" id="filterFechaHasta">
                </div>

            </div>

        </div>

    </section>

    <section class="panel table-panel">
        <div class="table-header">
            <h2>Tabla de registros</h2>

            <div class="table-header-actions">
                <span id="totalRecords">0 registros</span>
                <label class="page-size-control" for="recordsPerPage">
                    <span>Mostrar</span>
                    <select id="recordsPerPage">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span>por página</span>
                </label>
                <button type="button" id="exportAtencionesBtn" class="btn-secondary">
                    Exportar Excel
                </button>
            </div>
        </div>

        <div class="table-scroll-top" id="tableScrollTop" aria-label="Desplazamiento horizontal de la tabla">
            <div id="tableScrollTopInner"></div>
        </div>

        <div class="table-wrapper" id="recordsTableWrapper">
            <table class="records-table">
                <thead>
                    <tr>
                        <th data-column="fecha_contacto">Fecha Contacto</th>
                        <th data-column="paciente">Paciente</th>
                        <th data-column="telefono">Teléfono</th>
                        <th data-column="dni">DNI</th>
                        <th data-column="canal">Canal</th>
                        <th data-column="tipo_atencion">Tipo Atención</th>
                        <th data-column="servicio">Servicio</th>
                        <th data-column="detalle_consulta">Detalle Consulta</th>
                        <th data-column="status_cita">Status</th>
                        <th data-column="fecha_cita">Fecha Cita</th>
                        <th data-column="acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody id="recordsTableBody">
                    <tr class="empty-row">
                        <td colspan="10">
                            No hay registros aún.<br>
                            <small>Crea el primero con "Nuevo registro"</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="table-pagination" id="tablePagination">
            <span id="recordsRange">Sin registros</span>
            <div class="pagination-actions">
                <button type="button" id="previousPageBtn" class="pagination-btn">Anterior</button>
                <div id="paginationPages" class="pagination-pages"></div>
                <button type="button" id="nextPageBtn" class="pagination-btn">Siguiente</button>
            </div>
        </div>
    </section>
</main>

<!-- MODAL FORMULARIO -->
<div id="formModal" class="modal hidden">
    <div class="modal-backdrop" id="closeModalBackdrop"></div>

    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2 id="formTitle">Nuevo registro</h2>
                <p id="formSubtitle">Completa la información del lead.</p>
            </div>
            <button id="closeFormBtn" class="btn-close" type="button">×</button>
        </div>

        <form id="patientForm" novalidate class="form-layout">
            <input type="hidden" id="record_id" name="record_id">

            <section class="form-section form-section--patient">
                <div class="form-section__header">
                    <div>
                        <h3>Datos del paciente</h3>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field-group">
                        <label for="fecha_contacto">Fecha de contacto</label>
                        <input type="date" id="fecha_contacto" name="fecha_contacto" required>
                    </div>

                    <div class="field-group">
                        <label for="paciente">Paciente</label>
                        <input type="text" id="paciente" name="paciente" placeholder="Nombre del paciente">
                    </div>

                    <div class="field-group">
                        <label for="telefono">Teléfono</label>
                        <input type="text" id="telefono" name="telefono" placeholder="Número de contacto">
                    </div>

                    <div class="field-group">
                        <label for="dni">DNI</label>

                        <input type="text" id="dni" name="dni" maxlength="20" placeholder="Número de documento">
                    </div>
                </div>
            </section>


            <section class="form-section form-section--attention">
                <div class="form-section__header">
                    <div>
                        <h3>Datos de atención</h3>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field-group">
                        <label for="canal">Canal</label>

                        <select id="canal" name="canal" required>
                            <option value="">Seleccionar</option>
                            <option value="Redes">Redes</option>
                            <option value="Fachada">Fachada</option>
                            <option value="Web">Web</option>
                            <option value="Referido Drs">Referido Dr.</option>
                            <option value="Whatsapp">Whatsapp</option>
                            <option value="Seguimiento">Seguimiento</option>
                            <option value="Interno">Interno</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="tipo_atencion">Tipo de atención</label>

                        <select id="tipo_atencion" name="tipo_atencion" required>
                            <option value="">Seleccionar</option>
                            <option value="Centro Médico">Centro Médico</option>
                            <option value="Procedimiento">Procedimiento</option>
                            <option value="Convenios">Convenios</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="servicio">Servicio</label>

                        <select id="servicio" name="servicio" required>
                            <option value="">Seleccionar</option>
                            <option value="Consulta">Consulta</option>
                            <option value="Ecografía">Ecografía</option>
                            <option value="Laboratorio">Laboratorio</option>
                            <option value="Vitaminas">Vitaminas</option>
                            <option value="2da opinión">2da opinión</option>
                            <option value="Perdido">Perdido</option>
                            <option value="Alquiler">Alquiler</option>
                            <option value="Paquete Ads">Paquete Ads</option>
                        </select>
                    </div>

                    <div class="field-group hidden" id="nombreReferidoGroup">
                        <label for="nombre_referido">Doctor que refiere</label>

                        <div class="doctor-autocomplete">
                            <input type="text" id="nombre_referido" name="nombre_referido"
                                autocomplete="off" aria-autocomplete="list"
                                aria-controls="nombreReferidoResults"
                                placeholder="Escribe para buscar un doctor">
                            <div id="nombreReferidoResults"
                                class="doctor-autocomplete__results hidden"></div>
                        </div>
                        <small class="field-hint">Los nombres provienen del directorio de Médicos.</small>
                    </div>

                    <div class="field-group field-group-full">
                        <label for="detalle_consulta">Detalle de consulta</label>

                        <textarea id="detalle_consulta" name="detalle_consulta" rows="3" required
                            placeholder="Describe brevemente la consulta"></textarea>
                    </div>
                </div>
            </section>


            <section class="form-section form-section--appointment">
                <div class="form-section__header">
                    <div>
                        <h3>Seguimiento de la cita</h3>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field-group">
                        <label for="status_cita">Status de cita</label>

                        <select id="status_cita" name="status_cita" required>
                            <option value="">Seleccionar</option>
                            <option value="Confirmado">Confirmado</option>
                            <option value="No Confirmado">No Confirmado</option>
                            <option value="Canceló">Canceló</option>
                            <option value="Reprogramó">Reprogramó</option>
                            <option value="Por Confirmar">Por Confirmar</option>
                            <option value="No Asistió">No Asistió</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="fecha_cita">Fecha de cita</label>

                        <input type="date" id="fecha_cita" name="fecha_cita">
                    </div>

                    <div class="field-group hidden" id="doctorCitaGroup">
                        <label for="doctor_cita">Profesional que atenderá</label>

                        <div class="doctor-autocomplete">
                            <input type="text" id="doctor_cita" name="doctor_cita"
                                autocomplete="off" aria-autocomplete="list"
                                aria-controls="doctorCitaResults"
                                placeholder="Escribe un médico o personal asistencial">
                            <div id="doctorCitaResults"
                                class="doctor-autocomplete__results hidden"></div>
                        </div>
                        <small class="field-hint">Puedes seleccionar un médico registrado o Personal asistencial.</small>
                    </div>
                </div>
            </section>

            <section id="conveniosSection" class="form-section hidden">
                <h3>Datos de Convenios</h3>

                <div class="form-grid">
                    <div class="field-group">
                        <label for="conv_fecha">Fecha a realizar</label>
                        <input type="date" id="conv_fecha" name="conv_fecha_realizar">
                    </div>

                    <div class="field-group">
                        <label for="conv_servicio">Derivado a</label>
                        <select id="conv_servicio" name="conv_derivado_a">
                            <option value="">Seleccionar</option>
                            <option value="Servimovil">Servimovil</option>
                            <option value="Resocentro">Resocentro</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="conv_estudio">Estudio</label>
                        <input type="text" id="conv_estudio" name="conv_estudio" placeholder="Estudio">
                    </div>

                    <div class="field-group">
                        <label for="conv_medico">Médico derivado</label>
                        <input type="text" id="conv_medico" name="conv_medico_derivado" placeholder="Médico derivado">
                    </div>
                </div>
            </section>

            <div class="form-actions">
                <button type="button" id="cancelFormBtn" class="btn-secondary">Cancelar</button>
                <button type="submit" id="submitFormBtn" class="btn-primary">Guardar registro</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETALLE -->
<div id="detailModal" class="modal hidden">
    <div class="modal-backdrop" id="closeDetailBackdrop"></div>

    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h2>Detalle del registro</h2>
                <p>Información completa del lead.</p>
            </div>

            <div class="detail-actions-top">
                <?php if (auth_can_write_patients()): ?>
                    <button id="editRecordBtn" class="btn-action btn-edit" type="button"
                        onclick="editCurrentRecord()">Editar</button>
                    <button id="deleteRecordBtn" class="btn-action btn-delete" type="button"
                        onclick="deleteCurrentRecord()">Eliminar</button>
                <?php else: ?>
                    <span class="readonly-notice">Solo lectura</span>
                <?php endif; ?>
                <button id="closeDetailBtn" class="btn-close" type="button">×</button>
            </div>
        </div>

        <div id="detailContent" class="detail-layout">
            <p>Cargando información...</p>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>
<?php include 'templates/footer.php'; ?>
