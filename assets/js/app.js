const dom = {
  // Toolbar de filtros / columnas
  columnsToggleBtn: document.getElementById("columnsToggleBtn"),
  columnsMenu: document.getElementById("columnsMenu"),
  columnsControl: document.getElementById("columnsControl"),
  resetColumnsBtn: document.getElementById("resetColumnsBtn"),
  toggleFiltersBtn: document.getElementById("toggleFiltersBtn"),
  clearFiltersBtn: document.getElementById("clearFiltersBtn"),
  filtersPanel: document.getElementById("filtersPanel"),
  filtersSummary: document.getElementById("filtersSummary"),

  // Dashboard stats
  statTotal: document.getElementById("statTotal"),
  statConfirmados: document.getElementById("statConfirmados"),
  statNoConfirmados: document.getElementById("statNoConfirmados"),
  statConvenios: document.getElementById("statConvenios"),

  // Modal formulario
  openFormBtn: document.getElementById("openFormBtn"),
  closeFormBtn: document.getElementById("closeFormBtn"),
  cancelFormBtn: document.getElementById("cancelFormBtn"),
  closeModalBackdrop: document.getElementById("closeModalBackdrop"),
  formModal: document.getElementById("formModal"),
  patientForm: document.getElementById("patientForm"),
  formTitle: document.getElementById("formTitle"),
  formSubtitle: document.getElementById("formSubtitle"),
  submitFormBtn: document.getElementById("submitFormBtn"),
  recordIdInput: document.getElementById("record_id"),

  // Campos formulario principal
  fechaContactoInput: document.getElementById("fecha_contacto"),
  pacienteInput: document.getElementById("paciente"),
  telefonoInput: document.getElementById("telefono"),
  dniInput: document.getElementById("dni"),

  canalInput: document.getElementById("canal"),
  tipoAtencion: document.getElementById("tipo_atencion"),
  servicioInput: document.getElementById("servicio"),

  nombreReferidoGroup: document.getElementById("nombreReferidoGroup"),
  nombreReferidoInput: document.getElementById("nombre_referido"),
  nombreReferidoResults: document.getElementById("nombreReferidoResults"),

  detalleConsultaInput: document.getElementById("detalle_consulta"),

  fechaCitaInput: document.getElementById("fecha_cita"),
  statusCitaInput: document.getElementById("status_cita"),

  doctorCitaGroup: document.getElementById("doctorCitaGroup"),
  doctorCitaInput: document.getElementById("doctor_cita"),
  doctorCitaResults: document.getElementById("doctorCitaResults"),

  // Campos convenios
  conveniosSection: document.getElementById("conveniosSection"),
  convFechaInput: document.getElementById("conv_fecha"),
  convServicioInput: document.getElementById("conv_servicio"),
  convEstudioInput: document.getElementById("conv_estudio"),
  convMedicoInput: document.getElementById("conv_medico"),

  // Tabla
  recordsTableBody: document.getElementById("recordsTableBody"),
  totalRecords: document.getElementById("totalRecords"),
  exportAtencionesBtn: document.getElementById("exportAtencionesBtn"),
  recordsPerPage: document.getElementById("recordsPerPage"),
  recordsRange: document.getElementById("recordsRange"),
  previousPageBtn: document.getElementById("previousPageBtn"),
  nextPageBtn: document.getElementById("nextPageBtn"),
  paginationPages: document.getElementById("paginationPages"),
  recordsTableWrapper: document.getElementById("recordsTableWrapper"),
  tableScrollTop: document.getElementById("tableScrollTop"),
  tableScrollTopInner: document.getElementById("tableScrollTopInner"),

  // Modal detalle
  detailModal: document.getElementById("detailModal"),
  closeDetailBtn: document.getElementById("closeDetailBtn"),
  closeDetailBackdrop: document.getElementById("closeDetailBackdrop"),
  detailContent: document.getElementById("detailContent"),

  // Filtros
  searchInput: document.getElementById("searchInput"),
  filterCanal: document.getElementById("filterCanal"),
  filterTipo: document.getElementById("filterTipo"),
  filterServicio: document.getElementById("filterServicio"),
  filterStatus: document.getElementById("filterStatus"),
  filterFechaDesde: document.getElementById("filterFechaDesde"),
  filterFechaHasta: document.getElementById("filterFechaHasta"),

  // UI
  toast: document.getElementById("toast"),
};

const quickButtons = Array.from(document.querySelectorAll(".quick-btn"));
const columnCheckboxes = Array.from(
  document.querySelectorAll("[data-column-toggle]"),
);

const COLUMN_STORAGE_KEY = "atenciones_visible_columns_v1";
const DEFAULT_VISIBLE_COLUMNS = [
  "fecha_contacto",
  "paciente",
  "telefono",
  "dni",
  "canal",
  "tipo_atencion",
  "servicio",
  "detalle_consulta",
  "status_cita",
  "fecha_cita",
];

let visibleColumns = loadVisibleColumns();

const urlParams = new URLSearchParams(window.location.search);

if (urlParams.get("error_export") === "1") {
  showToast(
    "No hay datos para exportar con los filtros seleccionados",
    "error",
  );
  window.history.replaceState({}, document.title, window.location.pathname);
}

let records = [];
let filteredRecords = [];
let doctorsDirectory = [];
const APPOINTMENT_PROVIDER_OPTIONS = [{ nombre: "Personal asistencial" }];
let currentPage = 1;
let pageSize = 25;
let syncingTableScroll = false;
let currentDetailRecord = null;

const CAN_WRITE_PATIENTS = !!(
  window.APP_PERMISSIONS && window.APP_PERMISSIONS.canWritePatients
);

/* =========================
   HELPERS
========================= */

function debounce(fn, delay = 250) {
  let timeoutId;
  return (...args) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), delay);
  };
}

function formatDate(dateString) {
  if (!dateString) return "-";

  const parts = dateString.split("-");
  if (parts.length !== 3) return dateString;

  const [year, month, day] = parts;
  return `${day}/${month}/${year}`;
}

function formatInputDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

function showToast(message, type = "success") {
  if (!dom.toast) return;

  dom.toast.textContent = message;
  dom.toast.className = `toast toast-${type}`;

  setTimeout(() => {
    dom.toast.classList.add("hidden");
  }, 2500);
}

function setFieldError(field, message) {
  if (!field) return;

  field.classList.add("input-error");

  let error = field.parentElement.querySelector(".field-error");

  if (!error) {
    error = document.createElement("small");
    error.className = "field-error";
    field.parentElement.appendChild(error);
  }

  error.textContent = message;
  field.focus();
}

function clearFormErrors() {
  document.querySelectorAll(".input-error").forEach((field) => {
    field.classList.remove("input-error");
  });

  document.querySelectorAll(".field-error").forEach((error) => {
    error.remove();
  });
}

function formatCanalLabel(text) {
  const value = String(text || "").trim();

  if (value.toLowerCase() === "referido drs") {
    return "Referido Dr.";
  }

  return value || "-";
}

function createBadge(text) {
  const value = (text || "").toLowerCase().trim();
  let extraClass = "badge-no-confirmado";

  if (value === "confirmado") {
    extraClass = "badge-confirmado";
  } else if (value === "no confirmado") {
    extraClass = "badge-no-confirmado";
  } else if (value === "canceló" || value === "cancelo") {
    extraClass = "badge-cancelo";
  } else if (value === "reprogramó" || value === "reprogramo") {
    extraClass = "badge-reprogramo";
  } else if (value === "por confirmar") {
    extraClass = "badge-por-confirmar";
  } else if (value === "no asistió" || value === "no asistio") {
    extraClass = "badge-no-asistio";
  }

  return `<span class="badge ${extraClass}">${text}</span>`;
}

function createServicioBadge(text) {
  const value = String(text || "").trim();

  const slug = value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/\s+/g, "-");

  return `<span class="servicio-badge servicio-${slug}">${value || "-"}</span>`;
}

function createCanalBadge(text) {
  const value = (text || "").toLowerCase().trim();
  let extraClass = "canal-default";

  if (value === "redes") {
    extraClass = "canal-redes";
  } else if (value === "web") {
    extraClass = "canal-web";
  } else if (value === "whatsapp") {
    extraClass = "canal-whatsapp";
  } else if (value === "fachada") {
    extraClass = "canal-fachada";
  } else if (value === "referido drs") {
    extraClass = "canal-referido";
  } else if (value === "seguimiento") {
    extraClass = "canal-seguimiento";
  } else if (value === "interno") {
    extraClass = "canal-interno";
  }

  return `<span class="canal-badge ${extraClass}">${formatCanalLabel(text)}</span>`;
}

function getFilterValues() {
  return {
    search: dom.searchInput ? dom.searchInput.value.toLowerCase().trim() : "",
    canal: dom.filterCanal ? dom.filterCanal.value : "",
    tipo: dom.filterTipo ? dom.filterTipo.value : "",
    servicio: dom.filterServicio ? dom.filterServicio.value : "",
    status: dom.filterStatus ? dom.filterStatus.value : "",
    fechaDesde: dom.filterFechaDesde ? dom.filterFechaDesde.value : "",
    fechaHasta: dom.filterFechaHasta ? dom.filterFechaHasta.value : "",
  };
}

function exportAtenciones() {
  const filters = getFilterValues();

  const params = new URLSearchParams();

  if (filters.search) params.append("search", filters.search);
  if (filters.canal) params.append("canal", filters.canal);
  if (filters.tipo) params.append("tipo", filters.tipo);
  if (filters.servicio) params.append("servicio", filters.servicio);
  if (filters.status) params.append("status", filters.status);
  if (filters.fechaDesde) params.append("fecha_desde", filters.fechaDesde);
  if (filters.fechaHasta) params.append("fecha_hasta", filters.fechaHasta);

  showToast("Generando Excel...", "success");

  window.location.href = `modules/exportacion/export-atenciones.php?${params.toString()}`;
}

function countActiveFilters(filters) {
  let count = 0;

  if (filters.search) count++;
  if (filters.canal) count++;
  if (filters.tipo) count++;
  if (filters.servicio) count++;
  if (filters.status) count++;
  if (filters.fechaDesde || filters.fechaHasta) count++;

  return count;
}

function buildFiltersSummary(filters) {
  const parts = [];

  if (filters.search) parts.push(`Buscar: "${filters.search}"`);
  if (filters.canal) parts.push(`Canal: ${formatCanalLabel(filters.canal)}`);
  if (filters.tipo) parts.push(`Tipo: ${filters.tipo}`);
  if (filters.servicio) parts.push(`Servicio: ${filters.servicio}`);
  if (filters.status) parts.push(`Status: ${filters.status}`);

  if (filters.fechaDesde || filters.fechaHasta) {
    const desde = filters.fechaDesde ? formatDate(filters.fechaDesde) : "...";
    const hasta = filters.fechaHasta ? formatDate(filters.fechaHasta) : "...";

    parts.push(`Fechas: ${desde} — ${hasta}`);
  }

  return parts.length ? parts.join(" · ") : "Sin filtros activos";
}

function updateFiltersSummary() {
  const filters = getFilterValues();
  const count = countActiveFilters(filters);

  if (dom.filtersSummary) {
    dom.filtersSummary.textContent = buildFiltersSummary(filters);
  }

  if (dom.toggleFiltersBtn) {
    dom.toggleFiltersBtn.textContent =
      count > 0 ? `Filtros (${count})` : "Filtros";
  }
}

function syncFiltersPanelState() {
  if (!dom.filtersPanel) return;

  const filters = getFilterValues();

  if (countActiveFilters(filters) > 0) {
    dom.filtersPanel.classList.remove("hidden");
  }
}

function toggleFiltersPanel() {
  if (!dom.filtersPanel) return;

  dom.filtersPanel.classList.toggle("hidden");
}

function clearQuickButtonsState() {
  quickButtons.forEach((btn) => btn.classList.remove("active"));
}

function clearAllFilters() {
  if (dom.searchInput) dom.searchInput.value = "";
  if (dom.filterCanal) dom.filterCanal.value = "";
  if (dom.filterTipo) dom.filterTipo.value = "";
  if (dom.filterServicio) dom.filterServicio.value = "";
  if (dom.filterStatus) dom.filterStatus.value = "";
  if (dom.filterFechaDesde) dom.filterFechaDesde.value = "";
  if (dom.filterFechaHasta) dom.filterFechaHasta.value = "";

  clearQuickButtonsState();
  applyFilters();
}

function matchesSearch(record, search) {
  if (!search) return true;

  return (
    (record.paciente || "").toLowerCase().includes(search) ||
    (record.telefono || "").toLowerCase().includes(search) ||
    (record.dni || "").toLowerCase().includes(search) ||
    (record.detalle_consulta || "").toLowerCase().includes(search)
  );
}

function matchesDateRange(recordFecha, fechaDesde, fechaHasta) {
  const value = recordFecha || "";

  const matchesDesde = !fechaDesde || value >= fechaDesde;
  const matchesHasta = !fechaHasta || value <= fechaHasta;

  return matchesDesde && matchesHasta;
}

/* =========================
   COLUMNAS VISIBLES
========================= */

function loadVisibleColumns() {
  try {
    const saved = JSON.parse(localStorage.getItem(COLUMN_STORAGE_KEY));

    if (!Array.isArray(saved)) {
      return [...DEFAULT_VISIBLE_COLUMNS];
    }

    const valid = saved.filter((column) =>
      DEFAULT_VISIBLE_COLUMNS.includes(column),
    );

    return valid.length ? valid : [...DEFAULT_VISIBLE_COLUMNS];
  } catch (error) {
    return [...DEFAULT_VISIBLE_COLUMNS];
  }
}

function saveVisibleColumns() {
  localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(visibleColumns));
}

function getVisibleColumnCount() {
  return visibleColumns.length;
}

function syncColumnCheckboxes() {
  columnCheckboxes.forEach((checkbox) => {
    checkbox.checked = visibleColumns.includes(checkbox.dataset.columnToggle);
  });
}

function applyColumnVisibility() {
  DEFAULT_VISIBLE_COLUMNS.forEach((column) => {
    const isVisible = visibleColumns.includes(column);

    document.querySelectorAll(`[data-column="${column}"]`).forEach((cell) => {
      cell.classList.toggle("column-hidden", !isVisible);
    });
  });

  syncColumnCheckboxes();

  const emptyCell = dom.recordsTableBody?.querySelector(".empty-row td");
  if (emptyCell) {
    emptyCell.colSpan = getVisibleColumnCount() + 1;
  }

  requestAnimationFrame(updateTopScrollbar);
}

function toggleColumnsMenu() {
  if (!dom.columnsMenu || !dom.columnsToggleBtn) return;

  const willOpen = dom.columnsMenu.classList.contains("hidden");

  dom.columnsMenu.classList.toggle("hidden");
  dom.columnsToggleBtn.setAttribute("aria-expanded", String(willOpen));
}

function closeColumnsMenu() {
  if (!dom.columnsMenu || !dom.columnsToggleBtn) return;

  dom.columnsMenu.classList.add("hidden");
  dom.columnsToggleBtn.setAttribute("aria-expanded", "false");
}

function handleColumnToggle(checkbox) {
  const column = checkbox.dataset.columnToggle;
  if (!column) return;

  if (checkbox.checked) {
    if (!visibleColumns.includes(column)) {
      visibleColumns.push(column);
    }
  } else {
    visibleColumns = visibleColumns.filter((item) => item !== column);
  }

  saveVisibleColumns();
  applyColumnVisibility();
}

function resetVisibleColumns() {
  visibleColumns = [...DEFAULT_VISIBLE_COLUMNS];
  saveVisibleColumns();
  applyColumnVisibility();
}

/* =========================
   FORM MODES
========================= */

function setCreateMode() {
  if (dom.formTitle) {
    dom.formTitle.textContent = "Nuevo registro";
  }

  if (dom.formSubtitle) {
    dom.formSubtitle.textContent = "Completa la información del lead.";
  }

  if (dom.submitFormBtn) {
    dom.submitFormBtn.textContent = "Guardar registro";
  }
}

function setEditMode() {
  if (dom.formTitle) {
    dom.formTitle.textContent = "Editar registro";
  }

  if (dom.formSubtitle) {
    dom.formSubtitle.textContent = "Modifica la información del lead.";
  }

  if (dom.submitFormBtn) {
    dom.submitFormBtn.textContent = "Guardar cambios";
  }
}

/* =========================
   MODALS
========================= */

function openModal() {
  setCreateMode();

  if (dom.patientForm) {
    dom.patientForm.reset();
  }

  if (dom.recordIdInput) {
    dom.recordIdInput.value = "";
  }

  if (dom.conveniosSection) {
    dom.conveniosSection.classList.add("hidden");
  }

  if (dom.nombreReferidoGroup) {
    dom.nombreReferidoGroup.classList.add("hidden");
  }

  if (dom.doctorCitaGroup) {
    dom.doctorCitaGroup.classList.add("hidden");
  }

  if (dom.formModal) {
    dom.formModal.classList.remove("hidden");
  }
}

function closeModal() {
  if (dom.formModal) {
    dom.formModal.classList.add("hidden");
  }

  if (dom.patientForm) {
    dom.patientForm.reset();
  }

  if (dom.conveniosSection) {
    dom.conveniosSection.classList.add("hidden");
  }

  if (dom.recordIdInput) {
    dom.recordIdInput.value = "";
  }

  if (dom.nombreReferidoGroup) {
    dom.nombreReferidoGroup.classList.add("hidden");
  }

  if (dom.doctorCitaGroup) {
    dom.doctorCitaGroup.classList.add("hidden");
  }

  setCreateMode();
}

function openDetailModal() {
  if (dom.detailModal) {
    dom.detailModal.classList.remove("hidden");
  }
}

function closeDetailModal() {
  if (dom.detailModal) {
    dom.detailModal.classList.add("hidden");
  }

  if (dom.detailContent) {
    dom.detailContent.innerHTML = "<p>Cargando información...</p>";
  }
}

function toggleConveniosSection() {
  if (!dom.tipoAtencion || !dom.conveniosSection) return;

  if (dom.tipoAtencion.value === "Convenios") {
    dom.conveniosSection.classList.remove("hidden");
  } else {
    dom.conveniosSection.classList.add("hidden");
  }
}

function toggleNombreReferido() {
  if (!dom.canalInput || !dom.nombreReferidoGroup) return;

  const canal = String(dom.canalInput.value || "")
    .toLowerCase()
    .trim();

  const mostrar = canal === "interno" || canal === "referido drs";

  if (mostrar) {
    dom.nombreReferidoGroup.classList.remove("hidden");

    if (dom.nombreReferidoInput) {
      dom.nombreReferidoInput.required = true;
    }
  } else {
    dom.nombreReferidoGroup.classList.add("hidden");

    if (dom.nombreReferidoInput) {
      dom.nombreReferidoInput.required = false;
      dom.nombreReferidoInput.value = "";
    }
  }
}

function toggleDoctorCita() {
  if (!dom.statusCitaInput || !dom.doctorCitaGroup) return;

  const status = String(dom.statusCitaInput.value || "")
    .toLowerCase()
    .trim();

  const mostrar = status === "confirmado";

  if (mostrar) {
    dom.doctorCitaGroup.classList.remove("hidden");

    if (dom.doctorCitaInput) {
      dom.doctorCitaInput.required = true;
    }
  } else {
    dom.doctorCitaGroup.classList.add("hidden");

    if (dom.doctorCitaInput) {
      dom.doctorCitaInput.required = false;
      dom.doctorCitaInput.value = "";
    }
  }
}

function normalizeDoctorName(value) {
  return String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .trim();
}

function isRegisteredDoctor(value) {
  const normalized = normalizeDoctorName(value);

  return doctorsDirectory.some(
    (doctor) => normalizeDoctorName(doctor.nombre) === normalized,
  );
}

function isValidAppointmentProvider(value) {
  const normalized = normalizeDoctorName(value);

  return [...doctorsDirectory, ...APPOINTMENT_PROVIDER_OPTIONS].some(
    (provider) => normalizeDoctorName(provider.nombre) === normalized,
  );
}

function closeDoctorSuggestions(except = null) {
  [dom.nombreReferidoResults, dom.doctorCitaResults].forEach((results) => {
    if (results && results !== except) {
      results.classList.add("hidden");
      results.replaceChildren();
    }
  });
}

function renderDoctorSuggestions(input, results, additionalOptions = []) {
  if (!input || !results) return;

  const term = normalizeDoctorName(input.value);
  results.replaceChildren();

  if (!term) {
    results.classList.add("hidden");
    return;
  }

  const matches = [...doctorsDirectory, ...additionalOptions]
    .filter((doctor) => normalizeDoctorName(doctor.nombre).includes(term))
    .slice(0, 8);

  if (!matches.length) {
    const empty = document.createElement("span");
    empty.className = "doctor-autocomplete__empty";
    empty.textContent = "No se encontraron coincidencias.";
    results.appendChild(empty);
    results.classList.remove("hidden");
    return;
  }

  matches.forEach((doctor) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "doctor-autocomplete__option";
    button.textContent = doctor.nombre;
    button.addEventListener("click", () => {
      input.value = doctor.nombre;
      results.classList.add("hidden");
      results.replaceChildren();
      input.focus();
    });
    results.appendChild(button);
  });

  closeDoctorSuggestions(results);
  results.classList.remove("hidden");
}

function configureDoctorAutocomplete(input, results, additionalOptions = []) {
  if (!input || !results) return;

  input.addEventListener("input", () =>
    renderDoctorSuggestions(input, results, additionalOptions),
  );
  input.addEventListener("focus", () => {
    if (input.value.trim()) {
      renderDoctorSuggestions(input, results, additionalOptions);
    }
  });
}

async function loadDoctorsDirectory() {
  try {
    const response = await fetch("modules/atenciones/doctors-options.php");
    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(result.message || "No se pudo cargar el directorio.");
    }

    doctorsDirectory = result.records || [];
  } catch (error) {
    console.error("Error en loadDoctorsDirectory:", error);
    doctorsDirectory = [];
  }
}

/* =========================
   TABLE + FILTERS
========================= */

function updateTopScrollbar() {
  if (
    !dom.recordsTableWrapper ||
    !dom.tableScrollTop ||
    !dom.tableScrollTopInner
  ) {
    return;
  }

  const table = dom.recordsTableWrapper.querySelector(".records-table");
  const tableWidth = table ? table.scrollWidth : 0;
  const needsScroll = tableWidth > dom.recordsTableWrapper.clientWidth + 1;

  dom.tableScrollTop.classList.toggle("hidden", !needsScroll);
  dom.tableScrollTopInner.style.width = `${tableWidth}px`;
  dom.tableScrollTop.scrollLeft = dom.recordsTableWrapper.scrollLeft;
}

function renderPagination(totalRecords) {
  const totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));

  currentPage = Math.min(Math.max(1, currentPage), totalPages);

  if (dom.previousPageBtn) {
    dom.previousPageBtn.disabled = currentPage <= 1 || totalRecords === 0;
  }

  if (dom.nextPageBtn) {
    dom.nextPageBtn.disabled = currentPage >= totalPages || totalRecords === 0;
  }

  if (dom.recordsRange) {
    const start = totalRecords ? (currentPage - 1) * pageSize + 1 : 0;
    const end = Math.min(currentPage * pageSize, totalRecords);
    dom.recordsRange.textContent = totalRecords
      ? `Mostrando ${start}–${end} de ${totalRecords}`
      : "Sin registros";
  }

  if (!dom.paginationPages) return;

  const pageNumbers = [];
  const first = Math.max(1, Math.min(currentPage - 2, totalPages - 4));
  const last = Math.min(totalPages, Math.max(currentPage + 2, 5));

  for (let page = first; page <= last; page += 1) {
    pageNumbers.push(`
      <button type="button" class="pagination-btn pagination-number${
        page === currentPage ? " is-active" : ""
      }" data-page="${page}" aria-label="Ir a la página ${page}">
        ${page}
      </button>
    `);
  }

  dom.paginationPages.innerHTML = pageNumbers.join("");
}

function renderTable(data = filteredRecords) {
  if (!dom.recordsTableBody || !dom.totalRecords) return;

  const totalRecords = data.length;
  const totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));

  currentPage = Math.min(Math.max(1, currentPage), totalPages);

  const startIndex = (currentPage - 1) * pageSize;
  const pageRecords = data.slice(startIndex, startIndex + pageSize);

  if (!pageRecords.length) {
    dom.recordsTableBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="${getVisibleColumnCount() + 1}">
                    Aún no hay registros cargados.
                </td>
            </tr>
        `;

    dom.totalRecords.textContent = "0 registros";
    renderPagination(0);
    requestAnimationFrame(updateTopScrollbar);
    return;
  }

  dom.recordsTableBody.innerHTML = pageRecords
    .map(
      (record) => `
        <tr>
            <td data-column="fecha_contacto">${formatDate(record.fecha_contacto)}</td>
            <td data-column="paciente">${record.paciente || "-"}</td>
            <td data-column="telefono">${record.telefono || "-"}</td>
            <td data-column="dni">${record.dni || "-"}</td>
            <td data-column="canal">${createCanalBadge(record.canal || "-")}</td>
            <td data-column="tipo_atencion">${record.tipo_atencion || "-"}</td>
            <td data-column="servicio">${createServicioBadge(record.servicio || "-")}</td>
            <td data-column="detalle_consulta" class="cell-detail">
                <span
                    class="cell-detail__text"
                    title="${record.detalle_consulta || "-"}">
                    ${record.detalle_consulta || "-"}
                </span>
            </td>
            <td data-column="status_cita">${createBadge(record.status_cita || "-")}</td>
            <td data-column="fecha_cita">${formatDate(record.fecha_cita)}</td>

            <td data-column="acciones">
                <div class="actions-group">
                    <button
                        class="btn-action btn-view"
                        onclick="viewRecord(${record.id})"
                    >
                        Ver
                    </button>
                </div>
            </td>
        </tr>
    `,
    )
    .join("");

  applyColumnVisibility();

  dom.totalRecords.textContent = `${totalRecords} registro(s)`;
  renderPagination(totalRecords);
  requestAnimationFrame(updateTopScrollbar);
}

function updateDashboardStats(data = records) {
  if (dom.statTotal) {
    dom.statTotal.textContent = data.length;
  }

  if (dom.statConfirmados) {
    dom.statConfirmados.textContent = data.filter((record) => {
      const value = (record.status_cita || "").toLowerCase().trim();

      return value === "confirmado";
    }).length;
  }

  if (dom.statNoConfirmados) {
    dom.statNoConfirmados.textContent = data.filter((record) => {
      const value = (record.status_cita || "").toLowerCase().trim();

      return value === "no confirmado";
    }).length;
  }

  if (dom.statConvenios) {
    dom.statConvenios.textContent = data.filter((record) => {
      const value = (record.tipo_atencion || "").toLowerCase().trim();

      return value === "convenios";
    }).length;
  }
}

function applyFilters(resetPage = true) {
  const filters = getFilterValues();

  filteredRecords = records.filter((record) => {
    const matchesCanal = !filters.canal || record.canal === filters.canal;

    const matchesTipo = !filters.tipo || record.tipo_atencion === filters.tipo;

    const matchesServicio =
      !filters.servicio || record.servicio === filters.servicio;

    const matchesStatus =
      !filters.status || record.status_cita === filters.status;

    return (
      matchesSearch(record, filters.search) &&
      matchesCanal &&
      matchesTipo &&
      matchesServicio &&
      matchesStatus &&
      matchesDateRange(
        record.fecha_contacto,
        filters.fechaDesde,
        filters.fechaHasta,
      )
    );
  });

  if (resetPage) currentPage = 1;

  renderTable(filteredRecords);
  updateDashboardStats(filteredRecords);
  updateFiltersSummary();
}

const debouncedApplyFilters = debounce(applyFilters, 250);

/* =========================
   DATA LOAD
========================= */

async function loadRecords() {
  try {
    const response = await fetch("modules/atenciones/list.php");

    if (!response.ok) {
      throw new Error("Error servidor");
    }

    const result = await response.json();

    if (!result.success) {
      showToast(
        result.message || "No se pudieron cargar los registros.",
        "error",
      );

      return;
    }

    records = result.records || [];

    applyFilters();
    syncFiltersPanelState();
  } catch (error) {
    console.error("Error en loadRecords:", error);

    showToast("Error al cargar registros", "error");
  }
}

async function viewRecord(id) {
  try {
    const response = await fetch(`modules/atenciones/get.php?id=${id}`);

    if (!response.ok) {
      throw new Error("Error servidor");
    }

    const result = await response.json();

    if (!result.success) {
      showToast(result.message || "No se pudo cargar el detalle.", "error");
      return;
    }

    const record = result.record;
    currentDetailRecord = record;

    if (dom.detailContent) {
      dom.detailContent.innerHTML = `

        <!-- =========================
             PACIENTE
        ========================== -->
        <section class="detail-section detail-section--patient">

            <div class="detail-section__header">
                <div>
                    <h3>Datos del paciente</h3>
                </div>
            </div>

            <div class="detail-grid">

                <div class="detail-item">
                    <strong>Paciente</strong>
                    <span>${record.paciente || "-"}</span>
                </div>

                <div class="detail-item">
                    <strong>Teléfono</strong>
                    <span>${record.telefono || "-"}</span>
                </div>

                <div class="detail-item">
                    <strong>DNI</strong>
                    <span>${record.dni || "-"}</span>
                </div>

                <div class="detail-item">
                    <strong>Fecha de contacto</strong>
                    <span>${formatDate(record.fecha_contacto)}</span>
                </div>

            </div>

        </section>


        <!-- =========================
             ATENCIÓN
        ========================== -->
        <section class="detail-section detail-section--attention">

            <div class="detail-section__header">
                <div>
                    <h3>Información de atención</h3>
                </div>
            </div>

            <div class="detail-grid">

                <div class="detail-item">
                    <strong>Canal</strong>
                    <span>${formatCanalLabel(record.canal)}</span>
                </div>

                <div class="detail-item">
                    <strong>Tipo de atención</strong>
                    <span>${record.tipo_atencion || "-"}</span>
                </div>

                <div class="detail-item">
                    <strong>Servicio</strong>
                    <span>${record.servicio || "-"}</span>
                </div>

                ${
                  record.nombre_referido
                    ? `
                        <div class="detail-item">
                            <strong>Derivado por</strong>
                            <span>${record.nombre_referido}</span>
                        </div>
                    `
                    : ""
                }

                <div class="detail-item detail-item--full">
                    <strong>Detalle de consulta</strong>
                    <span>${record.detalle_consulta || "-"}</span>
                </div>

            </div>

        </section>


        <!-- =========================
             CITA
        ========================== -->
        <section class="detail-section detail-section--appointment">

            <div class="detail-section__header">
                <div>
                    <h3>Seguimiento de la cita</h3>
                </div>
            </div>

            <div class="detail-grid">

                <div class="detail-item">
                    <strong>Status</strong>
                    <span>${record.status_cita || "-"}</span>
                </div>

                <div class="detail-item">
                    <strong>Fecha de cita</strong>
                    <span>${formatDate(record.fecha_cita)}</span>
                </div>

                ${
                  record.doctor_cita
                    ? `
                        <div class="detail-item detail-item--full">
                            <strong>Doctor que atenderá</strong>
                            <span>${record.doctor_cita}</span>
                        </div>
                    `
                    : ""
                }

            </div>

        </section>


        ${
          record.tipo_atencion === "Convenios"
            ? `
                <!-- =========================
                     CONVENIO
                ========================== -->
                <section class="detail-section detail-section--agreement">

                    <div class="detail-section__header">
                        <div>
                            <span class="detail-section__eyebrow">
                                Convenio
                            </span>

                            <h3>Información del convenio</h3>
                        </div>
                    </div>

                    <div class="detail-grid">

                        <div class="detail-item">
                            <strong>Fecha a realizar</strong>
                            <span>${formatDate(record.convenio_fecha_realizar)}</span>
                        </div>

                        <div class="detail-item">
                            <strong>Derivado a</strong>
                            <span>${record.convenio_derivado_a || "-"}</span>
                        </div>

                        <div class="detail-item">
                            <strong>Estudio</strong>
                            <span>${record.convenio_estudio || "-"}</span>
                        </div>

                        <div class="detail-item">
                            <strong>Médico derivado</strong>
                            <span>${record.convenio_medico_derivado || "-"}</span>
                        </div>

                    </div>

                </section>
            `
            : ""
        }

      `;
    }

    openDetailModal();
  } catch (error) {
    console.error(error);
    showToast("Error al cargar detalle", "error");
  }
}

/* =========================
   CREATE / EDIT
========================= */

function fillFormFromCurrentRecord() {
  if (!currentDetailRecord) return;

  if (dom.recordIdInput) {
    dom.recordIdInput.value = currentDetailRecord.id || "";
  }

  if (dom.fechaContactoInput) {
    dom.fechaContactoInput.value = currentDetailRecord.fecha_contacto || "";
  }

  if (dom.pacienteInput) {
    dom.pacienteInput.value = currentDetailRecord.paciente || "";
  }

  if (dom.telefonoInput) {
    dom.telefonoInput.value = currentDetailRecord.telefono || "";
  }

  if (dom.dniInput) {
    dom.dniInput.value = currentDetailRecord.dni || "";
  }

  if (dom.canalInput) {
    dom.canalInput.value = currentDetailRecord.canal || "";
  }

  if (dom.nombreReferidoInput) {
    dom.nombreReferidoInput.value = currentDetailRecord.nombre_referido || "";
  }

  if (dom.tipoAtencion) {
    dom.tipoAtencion.value = currentDetailRecord.tipo_atencion || "";
  }

  if (dom.servicioInput) {
    dom.servicioInput.value = currentDetailRecord.servicio || "";
  }

  if (dom.detalleConsultaInput) {
    dom.detalleConsultaInput.value = currentDetailRecord.detalle_consulta || "";
  }

  if (dom.fechaCitaInput) {
    dom.fechaCitaInput.value = currentDetailRecord.fecha_cita || "";
  }

  if (dom.statusCitaInput) {
    dom.statusCitaInput.value = currentDetailRecord.status_cita || "";
  }

  if (dom.doctorCitaInput) {
    dom.doctorCitaInput.value = currentDetailRecord.doctor_cita || "";
  }

  toggleNombreReferido();
  toggleDoctorCita();

  if (currentDetailRecord.tipo_atencion === "Convenios") {
    if (dom.conveniosSection) {
      dom.conveniosSection.classList.remove("hidden");
    }

    if (dom.convFechaInput) {
      dom.convFechaInput.value =
        currentDetailRecord.convenio_fecha_realizar || "";
    }

    if (dom.convServicioInput) {
      dom.convServicioInput.value =
        currentDetailRecord.convenio_derivado_a || "";
    }

    if (dom.convEstudioInput) {
      dom.convEstudioInput.value = currentDetailRecord.convenio_estudio || "";
    }

    if (dom.convMedicoInput) {
      dom.convMedicoInput.value =
        currentDetailRecord.convenio_medico_derivado || "";
    }
  } else {
    if (dom.conveniosSection) {
      dom.conveniosSection.classList.add("hidden");
    }

    if (dom.convFechaInput) {
      dom.convFechaInput.value = "";
    }

    if (dom.convServicioInput) {
      dom.convServicioInput.value = "";
    }

    if (dom.convEstudioInput) {
      dom.convEstudioInput.value = "";
    }

    if (dom.convMedicoInput) {
      dom.convMedicoInput.value = "";
    }
  }
}

function editCurrentRecord() {
  if (!CAN_WRITE_PATIENTS) {
    showToast("No tienes permiso para editar pacientes.", "error");

    return;
  }

  if (!currentDetailRecord) {
    showToast("No hay registro cargado para editar.", "error");

    return;
  }

  fillFormFromCurrentRecord();
  setEditMode();
  closeDetailModal();

  if (dom.formModal) {
    dom.formModal.classList.remove("hidden");
  }
}

async function saveForm(formData, recordId) {
  const url = recordId
    ? "modules/atenciones/update.php"
    : "modules/atenciones/create.php";

  const response = await fetch(url, {
    method: "POST",
    body: formData,
    credentials: "same-origin",

    headers: {
      "X-Requested-With": "XMLHttpRequest",
    },
  });

  let result;

  try {
    result = await response.json();
  } catch (error) {
    throw new Error("El servidor devolvió una respuesta inválida.");
  }

  if (!response.ok && !result.message) {
    result.message = "No se pudo guardar el registro.";
  }

  return result;
}

if (dom.patientForm) {
  dom.patientForm.addEventListener("submit", async function (e) {
    e.preventDefault();

    if (!CAN_WRITE_PATIENTS) {
      showToast("No tienes permiso para modificar pacientes.", "error");

      return;
    }

    const formData = new FormData(dom.patientForm);

    const recordId = formData.get("record_id");

    const csrfToken =
      typeof window.APP_CSRF_TOKEN === "string"
        ? window.APP_CSRF_TOKEN.trim()
        : "";

    if (!csrfToken) {
      showToast(
        "La sesión de seguridad no está disponible. Recarga la página e inténtalo nuevamente.",
        "error",
      );

      return;
    }

    formData.set("csrf_token", csrfToken);

    const pacienteValue = (formData.get("paciente") || "").trim();

    const telefonoValue = (formData.get("telefono") || "").trim();

    formData.set("paciente", pacienteValue);

    formData.set("telefono", telefonoValue);

    clearFormErrors();

    let hasError = false;

    if (!dom.canalInput.value) {
      setFieldError(dom.canalInput, "Selecciona un canal");

      hasError = true;
    }

    if (!dom.tipoAtencion.value) {
      setFieldError(dom.tipoAtencion, "Selecciona un tipo de atención");

      hasError = true;
    }

    if (!dom.servicioInput.value) {
      setFieldError(dom.servicioInput, "Selecciona un servicio");

      hasError = true;
    }

    if (!dom.statusCitaInput.value) {
      setFieldError(dom.statusCitaInput, "Selecciona el status de la cita");

      hasError = true;
    }

    if (!dom.detalleConsultaInput.value.trim()) {
      setFieldError(
        dom.detalleConsultaInput,
        "El detalle de consulta es obligatorio",
      );

      hasError = true;
    }

    if (
      dom.nombreReferidoInput?.required &&
      !dom.nombreReferidoInput.value.trim()
    ) {
      setFieldError(dom.nombreReferidoInput, "Selecciona el doctor que refiere");
      hasError = true;
    } else if (
      dom.nombreReferidoInput?.required &&
      doctorsDirectory.length &&
      !isRegisteredDoctor(dom.nombreReferidoInput.value)
    ) {
      setFieldError(
        dom.nombreReferidoInput,
        "Selecciona un nombre registrado en Médicos",
      );
      hasError = true;
    }

    if (dom.doctorCitaInput?.required && !dom.doctorCitaInput.value.trim()) {
      setFieldError(dom.doctorCitaInput, "Selecciona el doctor que atenderá");
      hasError = true;
    } else if (
      dom.doctorCitaInput?.required &&
      doctorsDirectory.length &&
      !isValidAppointmentProvider(dom.doctorCitaInput.value)
    ) {
      setFieldError(
        dom.doctorCitaInput,
        "Selecciona un médico o Personal asistencial",
      );
      hasError = true;
    }

    if (hasError) {
      showToast("Revisa los campos obligatorios", "error");

      return;
    }

    try {
      const result = await saveForm(formData, recordId);

      if (!result.success) {
        showToast(result.message || "No se pudo guardar el registro.", "error");

        return;
      }

      closeModal();
      await loadRecords();

      showToast(
        recordId
          ? "Registro actualizado correctamente"
          : "Registro guardado correctamente",
        "success",
      );
    } catch (error) {
      console.error(error);

      showToast("Ocurrió un error al conectar con el servidor.", "error");
    }
  });
}

/* =========================
   DELETE
========================= */

async function deleteCurrentRecord() {
  if (!CAN_WRITE_PATIENTS) {
    showToast("No tienes permiso para eliminar pacientes.", "error");

    return;
  }

  if (!currentDetailRecord || !currentDetailRecord.id) {
    showToast("No hay registro cargado para eliminar.", "error");

    return;
  }

  const csrfToken =
    typeof window.APP_CSRF_TOKEN === "string"
      ? window.APP_CSRF_TOKEN.trim()
      : "";

  if (!csrfToken) {
    showToast(
      "La sesión de seguridad no está disponible. Recarga la página e inténtalo nuevamente.",
      "error",
    );

    return;
  }

  const confirmed = confirm(
    "¿Seguro que deseas eliminar este registro? Esta acción no se puede deshacer.",
  );

  if (!confirmed) return;

  try {
    const formData = new FormData();

    formData.append("id", currentDetailRecord.id);

    formData.append("csrf_token", csrfToken);

    const response = await fetch("modules/atenciones/delete.php", {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    const result = await response.json();

    if (!response.ok || !result.success) {
      showToast(result.message || "No se pudo eliminar el registro.", "error");

      return;
    }

    closeDetailModal();
    await loadRecords();

    showToast(result.message || "Registro eliminado correctamente.", "success");
  } catch (error) {
    console.error("Error al eliminar el registro:", error);

    showToast("Ocurrió un error al eliminar el registro.", "error");
  }
}

/* =========================
   QUICK DATE FILTERS
========================= */

function handleQuickDate(range, button) {
  clearQuickButtonsState();

  const today = new Date();

  let desde = "";
  let hasta = "";

  if (range !== "clear" && button) {
    button.classList.add("active");
  }

  if (range === "today") {
    desde = formatInputDate(today);
    hasta = formatInputDate(today);
  } else if (range === "7") {
    const past = new Date();

    past.setDate(today.getDate() - 7);

    desde = formatInputDate(past);
    hasta = formatInputDate(today);
  } else if (range === "30") {
    const past = new Date();

    past.setDate(today.getDate() - 30);

    desde = formatInputDate(past);
    hasta = formatInputDate(today);
  } else if (range === "month") {
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);

    desde = formatInputDate(firstDay);
    hasta = formatInputDate(today);
  }

  if (dom.filterFechaDesde) {
    dom.filterFechaDesde.value = desde;
  }

  if (dom.filterFechaHasta) {
    dom.filterFechaHasta.value = hasta;
  }

  applyFilters();
}

/* =========================
   EVENTS
========================= */

if (dom.openFormBtn && CAN_WRITE_PATIENTS) {
  dom.openFormBtn.addEventListener("click", openModal);
}

if (dom.closeFormBtn) {
  dom.closeFormBtn.addEventListener("click", closeModal);
}

if (dom.cancelFormBtn) {
  dom.cancelFormBtn.addEventListener("click", closeModal);
}

if (dom.closeModalBackdrop) {
  dom.closeModalBackdrop.addEventListener("click", closeModal);
}

if (dom.closeDetailBtn) {
  dom.closeDetailBtn.addEventListener("click", closeDetailModal);
}

if (dom.closeDetailBackdrop) {
  dom.closeDetailBackdrop.addEventListener("click", closeDetailModal);
}

if (dom.tipoAtencion) {
  dom.tipoAtencion.addEventListener("change", toggleConveniosSection);
}

if (dom.canalInput) {
  dom.canalInput.addEventListener("change", toggleNombreReferido);
}

if (dom.statusCitaInput) {
  dom.statusCitaInput.addEventListener("change", toggleDoctorCita);
}

if (dom.searchInput) {
  dom.searchInput.addEventListener("input", debouncedApplyFilters);
}

if (dom.filterCanal) {
  dom.filterCanal.addEventListener("change", applyFilters);
}

if (dom.filterTipo) {
  dom.filterTipo.addEventListener("change", applyFilters);
}

if (dom.filterServicio) {
  dom.filterServicio.addEventListener("change", applyFilters);
}

if (dom.filterStatus) {
  dom.filterStatus.addEventListener("change", applyFilters);
}

if (dom.filterFechaDesde) {
  dom.filterFechaDesde.addEventListener("change", applyFilters);
}

if (dom.filterFechaHasta) {
  dom.filterFechaHasta.addEventListener("change", applyFilters);
}

if (dom.recordsPerPage) {
  dom.recordsPerPage.addEventListener("change", () => {
    pageSize = Number(dom.recordsPerPage.value) || 25;
    currentPage = 1;
    renderTable(filteredRecords);
  });
}

if (dom.previousPageBtn) {
  dom.previousPageBtn.addEventListener("click", () => {
    if (currentPage <= 1) return;
    currentPage -= 1;
    renderTable(filteredRecords);
  });
}

if (dom.nextPageBtn) {
  dom.nextPageBtn.addEventListener("click", () => {
    const totalPages = Math.max(1, Math.ceil(filteredRecords.length / pageSize));
    if (currentPage >= totalPages) return;
    currentPage += 1;
    renderTable(filteredRecords);
  });
}

if (dom.paginationPages) {
  dom.paginationPages.addEventListener("click", (event) => {
    const button = event.target.closest("[data-page]");
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    renderTable(filteredRecords);
  });
}

function syncHorizontalScroll(source, target) {
  if (!source || !target || syncingTableScroll) return;
  syncingTableScroll = true;
  target.scrollLeft = source.scrollLeft;
  requestAnimationFrame(() => {
    syncingTableScroll = false;
  });
}

if (dom.tableScrollTop && dom.recordsTableWrapper) {
  dom.tableScrollTop.addEventListener("scroll", () =>
    syncHorizontalScroll(dom.tableScrollTop, dom.recordsTableWrapper),
  );
  dom.recordsTableWrapper.addEventListener("scroll", () =>
    syncHorizontalScroll(dom.recordsTableWrapper, dom.tableScrollTop),
  );
  window.addEventListener("resize", updateTopScrollbar);
}

if (dom.columnsToggleBtn) {
  dom.columnsToggleBtn.addEventListener("click", (event) => {
    event.stopPropagation();
    toggleColumnsMenu();
  });
}

if (dom.columnsMenu) {
  dom.columnsMenu.addEventListener("click", (event) => {
    event.stopPropagation();
  });
}

columnCheckboxes.forEach((checkbox) => {
  checkbox.addEventListener("change", () => handleColumnToggle(checkbox));
});

if (dom.resetColumnsBtn) {
  dom.resetColumnsBtn.addEventListener("click", resetVisibleColumns);
}

document.addEventListener("click", (event) => {
  if (dom.columnsControl && !dom.columnsControl.contains(event.target)) {
    closeColumnsMenu();
  }

  if (!event.target.closest(".doctor-autocomplete")) {
    closeDoctorSuggestions();
  }
});

if (dom.toggleFiltersBtn) {
  dom.toggleFiltersBtn.addEventListener("click", toggleFiltersPanel);
}

if (dom.clearFiltersBtn) {
  dom.clearFiltersBtn.addEventListener("click", clearAllFilters);
}

if (dom.exportAtencionesBtn) {
  dom.exportAtencionesBtn.addEventListener("click", exportAtenciones);
}

quickButtons.forEach((btn) => {
  btn.addEventListener("click", () => handleQuickDate(btn.dataset.range, btn));
});

window.viewRecord = viewRecord;

window.editCurrentRecord = editCurrentRecord;

window.deleteCurrentRecord = deleteCurrentRecord;

syncColumnCheckboxes();
applyColumnVisibility();
configureDoctorAutocomplete(
  dom.nombreReferidoInput,
  dom.nombreReferidoResults,
);
configureDoctorAutocomplete(
  dom.doctorCitaInput,
  dom.doctorCitaResults,
  APPOINTMENT_PROVIDER_OPTIONS,
);
loadDoctorsDirectory();
loadRecords();

/* =========================
   EXPORT DROPDOWN
========================= */

const exportBtn = document.getElementById("exportDropdownBtn");

const exportMenu = document.getElementById("exportMenu");

if (exportBtn && exportMenu) {
  exportBtn.addEventListener("click", (e) => {
    e.stopPropagation();

    exportMenu.classList.toggle("hidden");
  });

  document.addEventListener("click", () => {
    exportMenu.classList.add("hidden");
  });

  exportMenu.querySelectorAll("button").forEach((btn) => {
    btn.addEventListener("click", () => {
      const type = btn.dataset.export;

      exportMenu.classList.add("hidden");

      const currentPage = window.location.pathname;

      let url = "";

      if (currentPage.includes("campanias")) {
        url = "modules/exportacion/export-campanias.php";
      } else if (currentPage.includes("index")) {
        url = "modules/exportacion/export-atenciones.php";
      }

      const now = new Date();

      const mes = now.toISOString().slice(0, 7);

      if (type === "month") {
        url += `?type=month&mes=${mes}`;
      } else {
        url += "?type=all";
      }

      window.location.href = url;
    });
  });
}
