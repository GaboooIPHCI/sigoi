<?php
require_once __DIR__ . '/templates/header.php';
?>

<main class="page-shell">
    <section class="page-hero">
        <div>
            <p class="eyebrow">Módulo independiente</p>
            <h1>Convenios</h1>
            <p class="page-lead">
                Consulta y gestiona los convenios vinculados a pacientes sin duplicar información.
            </p>
        </div>
    </section>

    <section class="stats-grid">
        <article class="stat-card">
            <span class="stat-label">Total convenios</span>
            <strong class="stat-value" id="statConveniosTotal">0</strong>
        </article>

        <article class="stat-card">
            <span class="stat-label">Resocentro</span>
            <strong class="stat-value" id="statConveniosResocentro">0</strong>
        </article>

        <article class="stat-card">
            <span class="stat-label">Servimovil</span>
            <strong class="stat-value" id="statConveniosServimovil">0</strong>
        </article>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Listado de convenios</h2>
                <p class="panel-subtitle">Filtra y administra convenios vinculados a pacientes.</p>
            </div>
        </div>

        <div class="filters-grid">
            <div class="field-group">
                <label for="convenioSearchInput">Búsqueda</label>
                <input
                    type="text"
                    id="convenioSearchInput"
                    placeholder="Buscar por paciente o estudio"
                >
            </div>

            <div class="field-group">
                <label for="convenioDerivadoFilter">Derivado a</label>
                <select id="convenioDerivadoFilter">
                    <option value="">Todos</option>
                    <option value="Resocentro">Resocentro</option>
                    <option value="Servimovil">Servimovil</option>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Paciente</th>
                        <th>Fecha a realizar</th>
                        <th>Derivado a</th>
                        <th>Estudio</th>
                        <th>Médico derivado</th>
                        <?php if (auth_can_modify_module('convenios')): ?> 
                            <th>Acciones</th> 
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="conveniosTableBody">
                    <tr class="empty-row">
                        <td colspan="6">Cargando convenios...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="modal hidden" id="convenioFormModal">
        <div class="modal-backdrop" id="closeConvenioFormBackdrop"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <div>
                    <h3 id="convenioFormTitle">Editar convenio</h3>
                    <p id="convenioFormSubtitle">Actualiza la información del convenio.</p>
                </div>
                <button type="button" class="icon-btn" id="closeConvenioFormBtn">×</button>
            </div>

            <form id="convenioForm" class="modal-form">
                <input type="hidden" id="convenio_id">
                <input type="hidden" id="convenio_atencion_id">

                <div class="form-grid">
                    <div class="field-group">
                        <label for="convenio_paciente">Paciente</label>
                        <input type="text" id="convenio_paciente" disabled>
                    </div>

                    <div class="field-group">
                        <label for="convenio_fecha_realizar">Fecha a realizar</label>
                        <input type="date" id="convenio_fecha_realizar" required>
                    </div>

                    <div class="field-group">
                        <label for="convenio_derivado_a">Derivado a</label>
                        <select id="convenio_derivado_a" required>
                            <option value="">Seleccionar</option>
                            <option value="Resocentro">Resocentro</option>
                            <option value="Servimovil">Servimovil</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label for="convenio_estudio">Estudio</label>
                        <input type="text" id="convenio_estudio" required>
                    </div>

                    <div class="field-group">
                        <label for="convenio_medico_derivado">Médico derivado</label>
                        <input type="text" id="convenio_medico_derivado" required>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" id="cancelConvenioFormBtn">Cancelar</button>
                    <button type="submit" class="btn-primary" id="submitConvenioFormBtn">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal hidden" id="confirmConvenioModal">
        <div class="modal-backdrop" id="closeConfirmConvenioBackdrop"></div>
        <div class="modal-dialog modal-dialog-sm">
            <div class="modal-header">
                <h3 id="confirmConvenioModalTitle">Eliminar convenio</h3>
                <button type="button" class="icon-btn" id="closeConfirmConvenioModalBtn">×</button>
            </div>

            <div class="modal-body">
                <p id="confirmConvenioModalText">
                    ¿Seguro que deseas eliminar este convenio? La atención seguirá existiendo en pacientes.
                </p>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="cancelConfirmConvenioBtn">Cancelar</button>
                <button type="button" class="btn-danger" id="acceptConfirmConvenioBtn">Eliminar convenio</button>
            </div>
        </div>
    </div>

    <div class="toast hidden" id="toast"></div>
</main>

<script> 
    window.CONVENIOS_CAN_WRITE = <?= auth_can_modify_module('convenios') ? 'true' : 'false' ?>; 
</script>

<script src="assets/js/convenios.js"></script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>