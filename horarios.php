<?php require_once __DIR__ . '/templates/header.php'; $canModify = auth_can_modify_module('horarios'); ?>
<link rel="stylesheet" href="assets/css/horarios-search.css?v=1.3">
<main class="container schedule-page">
  <section class="schedule-hero panel">
    <div><span class="dashboard-label">Centro médico</span><h1>Horarios médicos</h1><p>Consulta la disponibilidad de profesionales y organiza sus horarios por día, semana o mes.</p></div>
    <?php if ($canModify): ?><button class="btn-primary" id="newScheduleBtn" type="button">+ Nuevo horario</button><?php endif; ?>
  </section>

  <section class="schedule-stats" aria-label="Resumen">
    <article><span>Horarios visibles</span><strong id="statSchedules">0</strong></article>
    <article><span>Profesionales</span><strong id="statDoctors">0</strong></article>
    <article><span>Especialidades</span><strong id="statSpecialties">0</strong></article>
    <article><span>Publicados</span><strong id="statPublished">0</strong></article>
  </section>

  <section class="panel schedule-controls">
    <div class="schedule-main-tabs" role="tablist">
      <button class="schedule-tab is-active" data-section="list" type="button">Vista práctica</button>
      <button class="schedule-tab" data-section="calendar" type="button">Calendario</button>
    </div>
    <div class="schedule-filters">
      <label class="schedule-smart-search" id="scheduleSmartSearch"><span>Buscar profesional o especialidad</span><input type="search" id="scheduleSearchFilter" placeholder="Escribe un nombre o especialidad" autocomplete="off" aria-autocomplete="list" aria-controls="scheduleSearchResults"><div class="schedule-smart-search__results hidden" id="scheduleSearchResults" role="listbox"></div></label>
      <label><span>Estado</span><select id="statusFilter"><option value="">Todos</option><option>Disponible</option><option>Por confirmar</option><option>No disponible</option><option>Cancelado</option></select></label>
      <?php if ($canModify): ?><label><span>Visualización</span><select id="publicationFilter"><option value="Publicado">Vista publicada</option><option value="Todos">Modo edición</option><option value="Borrador">Solo borradores</option></select></label><?php endif; ?>
      <button class="btn-secondary" id="clearFiltersBtn" type="button">Limpiar</button>
    </div>
  </section>

  <section id="listSection" class="panel schedule-section">
    <div class="section-heading"><div><h2 id="listSectionTitle">Disponibilidad del día</h2><p id="listSectionSubtitle">Solo se muestran profesionales con horario en la fecha seleccionada.</p></div><div class="list-date-controls"><label class="date-jump"><span id="listDateLabel">Mostrar fecha</span><input type="date" id="listDate"></label><button class="btn-secondary list-month-toggle" id="listMonthToggle" type="button">Ver todo el mes</button></div></div>
    <div class="schedule-doctor-groups" id="scheduleDoctorGroups"></div>
  </section>

  <section id="calendarSection" class="panel schedule-section hidden">
    <div class="calendar-toolbar">
      <div class="calendar-nav"><button id="calendarPrev" type="button" aria-label="Anterior">‹</button><button id="calendarToday" type="button">Hoy</button><button id="calendarNext" type="button" aria-label="Siguiente">›</button><h2 id="calendarTitle"></h2></div>
      <div class="calendar-view-tabs"><button data-view="month" class="is-active" type="button">Mes</button><button data-view="week" type="button">Semana</button><button data-view="day" type="button">Día</button></div>
      <label class="date-jump compact"><span>Fecha</span><input type="date" id="calendarDate"></label>
    </div>
    <div id="calendarCanvas" class="calendar-canvas"></div>
  </section>
</main>

<?php if ($canModify): ?>
<div class="schedule-modal hidden" id="scheduleModal" aria-hidden="true">
  <div class="schedule-modal__backdrop" data-close-modal></div>
  <div class="schedule-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="scheduleModalTitle">
    <div class="schedule-modal__header"><div><p class="schedule-modal__kicker">Gestión de horarios</p><h2 id="scheduleModalTitle">Nuevo horario</h2></div><button type="button" class="icon-btn" data-close-modal aria-label="Cerrar">×</button></div>
    <form id="scheduleForm">
      <input type="hidden" id="scheduleId">
      <div class="schedule-form-grid">
        <label class="form-wide edit-scope-field hidden" id="editScopeField"><span>¿Qué deseas modificar?</span><select id="scheduleEditScope"><option value="single">Solo este horario</option><option value="weekday">Este mismo día y turno en todo el grupo</option><option value="future">Este turno desde esta fecha en adelante</option><option value="series">Toda la programación</option></select><small id="editScopeHelp">Selecciona el alcance antes de guardar.</small></label>
        <label class="linked-search"><span>Profesional *</span><input type="hidden" id="scheduleDoctorId"><input id="scheduleDoctor" maxlength="140" placeholder="Escribe para buscar" autocomplete="off" required><div class="linked-search__results" id="scheduleDoctorResults"></div></label>
        <label class="linked-search"><span>Especialidad o rama *</span><input type="hidden" id="scheduleSpecialtyId"><input id="scheduleSpecialty" maxlength="120" placeholder="Selecciona primero al profesional" autocomplete="off" required><div class="linked-search__results" id="scheduleSpecialtyResults"></div></label>
        <input type="hidden" id="scheduleBranch">
        <label><span>Modalidad</span><select id="scheduleModality"><option>Presencial</option><option>Virtual</option><option>Ambas</option></select></label>
        <label><span>Color del profesional</span><span class="schedule-color-field"><input type="color" id="scheduleColor" value="#5b21b6"><output id="scheduleColorValue">#5b21b6</output></span></label>
        <label><span>Fecha inicial *</span><input type="date" id="scheduleStartDate" required></label>
        <label id="endDateField"><span>Repetir hasta</span><input type="date" id="scheduleEndDate"></label>
        <fieldset id="weeklyScheduleField" class="weekly-schedule form-wide">
          <legend>Días y horas de atención</legend>
          <p>Activa los días de atención. Si un día tiene dos turnos, agrega otro bloque de horas.</p>
          <div class="schedule-quick-config">
            <div class="schedule-day-presets" aria-label="Selección rápida de días">
              <span>Selección rápida</span>
              <div><button type="button" data-day-preset="1,2,3,4,5">Lunes a viernes</button><button type="button" data-day-preset="6,7">Fin de semana</button><button type="button" data-day-preset="1,2,3,4,5,6,7">Toda la semana</button><button type="button" data-day-preset="">Limpiar días</button></div>
            </div>
            <label class="same-time-toggle"><input type="checkbox" id="sameTimeToggle"><span><strong>Misma hora para todos</strong><small>Usa un solo horario en los días seleccionados.</small></span></label>
          </div>
          <div class="shared-time-panel hidden" id="sharedTimePanel">
            <label><span>Desde</span><input type="time" id="sharedTimeStart" value="09:00"></label>
            <label><span>Hasta</span><input type="time" id="sharedTimeEnd" value="10:00"></label>
          </div>
          <div id="weekdayRows" class="weekday-rows">
            <?php foreach ([1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'] as $dayNumber=>$dayLabel): ?>
              <div class="weekday-row" data-day="<?= $dayNumber ?>">
                <button class="weekday-toggle" type="button" aria-pressed="false"><span class="weekday-toggle__mark">✓</span><?= $dayLabel ?></button>
                <div class="weekday-time-area">
                  <div class="weekday-time-slots">
                    <div class="weekday-time-slot">
                      <label><span>Desde</span><input type="time" class="weekday-start" value="09:00" disabled></label>
                      <label><span>Hasta</span><input type="time" class="weekday-end" value="10:00" disabled></label>
                      <button class="weekday-remove-slot" type="button" aria-label="Quitar bloque" title="Quitar bloque">×</button>
                    </div>
                  </div>
                  <button class="weekday-add-slot" type="button" disabled>+ Agregar otro horario</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div id="singleTimeFields" class="single-time-fields form-wide hidden">
          <label><span>Hora de inicio</span><input type="time" id="scheduleStartTime"></label>
          <label><span>Hora de término</span><input type="time" id="scheduleEndTime"></label>
        </div>
        <label><span>Disponibilidad</span><select id="scheduleStatus"><option>Disponible</option><option>Por confirmar</option><option>No disponible</option><option>Cancelado</option></select></label>
        <label><span>Publicación</span><select id="schedulePublication"><option>Borrador</option><option>Publicado</option></select></label>
        <label class="form-wide"><span>Observaciones internas</span><textarea id="scheduleNotes" rows="3" maxlength="2000" placeholder="Ej. Previa coordinación o ausencia temporal"></textarea></label>
      </div>
      <div class="schedule-modal__actions"><button type="button" class="btn-secondary" data-close-modal>Cancelar</button><button type="submit" class="btn-primary" id="saveScheduleBtn">Guardar horario</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<script>window.SCHEDULES_CAN_MODIFY = <?= $canModify ? 'true' : 'false' ?>;</script>
<script src="assets/js/horarios.js?v=8.5"></script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
