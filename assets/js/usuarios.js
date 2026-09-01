(function () {

    const state = {
        users: [],
        permissionsUser: null,
        permissionGroups: []
    };


    const els = {

        /* =========================
           GESTIÓN DE CUENTA
        ========================= */

        form:
            document.getElementById(
                'userAdminForm'
            ),

        id:
            document.getElementById(
                'userId'
            ),

        accountSelect:
            document.getElementById(
                'accountSelect'
            ),

        accountTypeInfo:
            document.getElementById(
                'accountTypeInfo'
            ),

        accountTypeLabel:
            document.getElementById(
                'accountTypeLabel'
            ),

        nombre:
            document.getElementById(
                'nombre'
            ),

        usuario:
            document.getElementById(
                'usuario'
            ),

        saveBtn:
            document.getElementById(
                'saveUserBtn'
            ),

        changeAccountPasswordBtn:
            document.getElementById(
                'changeAccountPasswordBtn'
            ),

        refreshBtn:
            document.getElementById(
                'refreshUsersBtn'
            ),

        tableBody:
            document.getElementById(
                'usersTableBody'
            ),


        /* =========================
           PERMISOS
        ========================= */

        permissionsPanel:
            document.getElementById(
                'permissionsPanel'
            ),

        permissionsUserSelect:
            document.getElementById(
                'permissionsUserSelect'
            ),

        permissionsEmptyState:
            document.getElementById(
                'permissionsEmptyState'
            ),

        permissionsContent:
            document.getElementById(
                'permissionsContent'
            ),

        permissionsAccountInfo:
            document.getElementById(
                'permissionsAccountInfo'
            ),

        permissionsGroups:
            document.getElementById(
                'permissionsGroups'
            ),

        savePermissionsBtn:
            document.getElementById(
                'savePermissionsBtn'
            )
    };


    /* =========================
       INICIO
    ========================= */

    init();


    async function init() {

        bindEvents();

        await loadUsers();

    }


    /* =========================
       EVENTOS
    ========================= */

    function bindEvents() {

        if (els.refreshBtn) {

            els.refreshBtn.addEventListener(
                'click',
                async function () {

                    await loadUsers();

                }
            );

        }


        if (els.accountSelect) {

            els.accountSelect.addEventListener(
                'change',
                function () {

                    const userId =
                        Number(
                            els.accountSelect.value
                        );

                    if (!userId) {

                        clearAccountForm();

                        return;
                    }

                    selectAccount(
                        userId,
                        true
                    );

                }
            );

        }


        if (els.form) {

            els.form.addEventListener(
                'submit',
                async function (event) {

                    event.preventDefault();

                    await saveAccount();

                }
            );

        }


        if (els.changeAccountPasswordBtn) {

            els.changeAccountPasswordBtn.addEventListener(
                'click',
                async function () {

                    await changeSelectedAccountPassword();

                }
            );

        }


        if (els.permissionsUserSelect) {

            els.permissionsUserSelect.addEventListener(
                'change',
                async function () {

                    const userId =
                        Number(
                            els.permissionsUserSelect.value
                        );

                    if (!userId) {

                        clearPermissions();

                        return;
                    }

                    if (
                        els.accountSelect &&
                        String(
                            els.accountSelect.value
                        ) !== String(userId)
                    ) {

                        els.accountSelect.value =
                            String(userId);

                        selectAccount(
                            userId,
                            false
                        );

                    }

                    await loadPermissions(
                        userId
                    );

                }
            );

        }


        if (els.savePermissionsBtn) {

            els.savePermissionsBtn.addEventListener(
                'click',
                async function () {

                    await savePermissions();

                }
            );

        }

    }


    /* =========================
       USUARIOS
    ========================= */

    async function loadUsers() {

        setTableLoading();

        try {

            const result =
                await getJSON(
                    'modules/usuarios/list.php'
                );

            state.users =
                Array.isArray(result.records)
                    ? result.records
                    : [];

            renderUsers();

            fillAccountSelectors();

            restoreSelection();

        } catch (error) {

            showTableError(
                error.message
            );

        }

    }


    function renderUsers() {

        if (!els.tableBody) {
            return;
        }


        if (!state.users.length) {

            els.tableBody.innerHTML = `
                <tr class="empty-row">
                    <td colspan="6">
                        No hay usuarios registrados.
                    </td>
                </tr>
            `;

            return;
        }


        els.tableBody.innerHTML =
            state.users.map(
                function (user) {

                    const id =
                        Number(
                            user.id
                        );

                    const active =
                        Number(
                            user.activo
                        ) === 1;

                    const isAdmin =
                        String(
                            user.rol || ''
                        ) === 'admin';

                    const lastAccess =
                        formatDateTime(
                            user.ultimo_acceso
                        );

                    const statusClass =
                        active
                            ? 'status-active'
                            : 'status-inactive';

                    const statusText =
                        active
                            ? 'Activo'
                            : 'Inactivo';

                    const toggleText =
                        active
                            ? 'Desactivar'
                            : 'Activar';

                    const toggleClass =
                        active
                            ? 'btn-danger-soft'
                            : 'btn-success-soft';


                    return `
                        <tr>
                            <td>
                                <strong>
                                    ${escapeHtml(
                                        user.nombre || '-'
                                    )}
                                </strong>
                            </td>

                            <td>
                                ${escapeHtml(
                                    user.usuario || '-'
                                )}
                            </td>

                            <td>
                                ${escapeHtml(
                                    roleLabel(
                                        user.rol
                                    )
                                )}
                            </td>

                            <td>
                                <span class="
                                    status-badge
                                    ${statusClass}
                                ">
                                    ${statusText}
                                </span>
                            </td>

                            <td>
                                ${lastAccess}
                            </td>

                            <td>
                                <div class="table-actions">

                                    <button
                                        type="button"
                                        class="btn-table"
                                        data-action="edit"
                                        data-id="${id}"
                                    >
                                        Editar
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-table"
                                        data-action="password"
                                        data-id="${id}"
                                    >
                                        Contraseña
                                    </button>

                                    <button
                                        type="button"
                                        class="btn-table"
                                        data-action="permissions"
                                        data-id="${id}"
                                    >
                                        Permisos
                                    </button>

                                    ${
                                        isAdmin
                                            ? `
                                                <span class="
                                                    account-protected-badge
                                                ">
                                                    Protegida
                                                </span>
                                            `
                                            : `
                                                <button
                                                    type="button"
                                                    class="
                                                        btn-table
                                                        ${toggleClass}
                                                    "
                                                    data-action="toggle"
                                                    data-id="${id}"
                                                >
                                                    ${toggleText}
                                                </button>
                                            `
                                    }

                                </div>
                            </td>
                        </tr>
                    `;

                }
            ).join('');


        els.tableBody
            .querySelectorAll(
                '[data-action]'
            )
            .forEach(
                function (button) {

                    button.addEventListener(
                        'click',
                        async function () {

                            const action =
                                button.dataset.action;

                            const id =
                                Number(
                                    button.dataset.id
                                );

                            if (!id) {
                                return;
                            }


                            if (action === 'edit') {

                                selectAccount(
                                    id,
                                    true
                                );

                                scrollToAccountForm();

                                return;

                            }


                            if (action === 'password') {

                                selectAccount(
                                    id,
                                    true
                                );

                                await changeSelectedAccountPassword();

                                return;

                            }


                            if (action === 'permissions') {

                                selectAccount(
                                    id,
                                    false
                                );

                                if (
                                    els.permissionsUserSelect
                                ) {

                                    els.permissionsUserSelect.value =
                                        String(id);

                                }

                                await loadPermissions(
                                    id
                                );

                                scrollToPermissions();

                                return;

                            }


                            if (action === 'toggle') {

                                await toggleUser(
                                    id
                                );

                            }

                        }
                    );

                }
            );

    }


    function fillAccountSelectors() {

        const currentAccount =
            els.accountSelect
                ? els.accountSelect.value
                : '';

        const currentPermission =
            els.permissionsUserSelect
                ? els.permissionsUserSelect.value
                : '';


        const options =
            state.users.map(
                function (user) {

                    return `
                        <option value="${Number(user.id)}">
                            ${escapeHtml(
                                user.nombre || user.usuario || ''
                            )}
                            —
                            ${escapeHtml(
                                roleLabel(
                                    user.rol
                                )
                            )}
                        </option>
                    `;

                }
            ).join('');


        if (els.accountSelect) {

            els.accountSelect.innerHTML = `
                <option value="">
                    Seleccionar cuenta
                </option>
                ${options}
            `;

            if (
                currentAccount &&
                state.users.some(
                    function (user) {
                        return String(user.id) ===
                            String(currentAccount);
                    }
                )
            ) {

                els.accountSelect.value =
                    currentAccount;

            }

        }


        if (els.permissionsUserSelect) {

            els.permissionsUserSelect.innerHTML = `
                <option value="">
                    Seleccionar cuenta
                </option>
                ${options}
            `;

            if (
                currentPermission &&
                state.users.some(
                    function (user) {
                        return String(user.id) ===
                            String(currentPermission);
                    }
                )
            ) {

                els.permissionsUserSelect.value =
                    currentPermission;

            }

        }

    }


    function restoreSelection() {

        if (
            els.accountSelect &&
            els.accountSelect.value
        ) {

            selectAccount(
                Number(
                    els.accountSelect.value
                ),
                false
            );

        } else {

            clearAccountForm();

        }


        if (
            els.permissionsUserSelect &&
            els.permissionsUserSelect.value
        ) {

            loadPermissions(
                Number(
                    els.permissionsUserSelect.value
                )
            );

        }

    }


    function selectAccount(
        userId,
        syncPermissions
    ) {

        const user =
            state.users.find(
                function (item) {

                    return Number(item.id) ===
                        Number(userId);

                }
            );


        if (!user) {

            clearAccountForm();

            return;

        }


        if (els.id) {

            els.id.value =
                user.id;

        }


        if (els.accountSelect) {

            els.accountSelect.value =
                String(user.id);

        }


        if (els.nombre) {

            els.nombre.disabled =
                false;

            els.nombre.value =
                user.nombre || '';

        }


        if (els.usuario) {

            els.usuario.disabled =
                false;

            els.usuario.value =
                user.usuario || '';

        }


        if (els.saveBtn) {

            els.saveBtn.disabled =
                false;

        }


        if (els.changeAccountPasswordBtn) {

            els.changeAccountPasswordBtn.disabled =
                false;

        }


        if (els.accountTypeInfo) {

            els.accountTypeInfo.classList.remove(
                'hidden'
            );

        }


        if (els.accountTypeLabel) {

            els.accountTypeLabel.textContent =
                roleLabel(
                    user.rol
                );

        }


        if (
            syncPermissions &&
            els.permissionsUserSelect
        ) {

            els.permissionsUserSelect.value =
                String(user.id);

            loadPermissions(
                Number(
                    user.id
                )
            );

        }

    }


    function clearAccountForm() {

        if (els.id) {

            els.id.value = '';

        }


        if (els.nombre) {

            els.nombre.value = '';

            els.nombre.disabled =
                true;

        }


        if (els.usuario) {

            els.usuario.value = '';

            els.usuario.disabled =
                true;

        }


        if (els.saveBtn) {

            els.saveBtn.disabled =
                true;

        }


        if (els.changeAccountPasswordBtn) {

            els.changeAccountPasswordBtn.disabled =
                true;

        }


        if (els.accountTypeInfo) {

            els.accountTypeInfo.classList.add(
                'hidden'
            );

        }


        if (els.accountTypeLabel) {

            els.accountTypeLabel.textContent =
                '-';

        }

    }


    async function saveAccount() {

        const id =
            Number(
                els.id
                    ? els.id.value
                    : 0
            );

        const nombre =
            els.nombre
                ? els.nombre.value.trim()
                : '';

        const usuario =
            els.usuario
                ? els.usuario.value.trim()
                : '';


        if (!id) {

            showMessage(
                'Selecciona una cuenta.'
            );

            return;

        }


        if (!nombre) {

            showMessage(
                'Ingresa el nombre visible.'
            );

            return;

        }


        if (!usuario) {

            showMessage(
                'Ingresa el usuario de acceso.'
            );

            return;

        }


        try {

            setButtonLoading(
                els.saveBtn,
                true,
                'Guardando...'
            );


            await postJSON(
                'modules/usuarios/update.php',
                {
                    id: id,
                    nombre: nombre,
                    usuario: usuario
                }
            );


            showMessage(
                'Cuenta actualizada correctamente.',
                'success'
            );


            await loadUsers();


            if (els.accountSelect) {

                els.accountSelect.value =
                    String(id);

            }


            selectAccount(
                id,
                false
            );

        } catch (error) {

            showMessage(
                error.message
            );

        } finally {

            setButtonLoading(
                els.saveBtn,
                false,
                'Guardar cambios'
            );

        }

    }


    async function changeSelectedAccountPassword() {

        const id =
            Number(
                els.id
                    ? els.id.value
                    : 0
            );


        if (!id) {

            showMessage(
                'Selecciona una cuenta.'
            );

            return;

        }


        const user =
            state.users.find(
                function (item) {

                    return Number(item.id) ===
                        Number(id);

                }
            );


        if (!user) {

            showMessage(
                'No se encontró la cuenta seleccionada.'
            );

            return;

        }


        const password =
            window.prompt(
                'Nueva contraseña para "' +
                (
                    user.nombre ||
                    user.usuario
                ) +
                '":'
            );


        if (password === null) {
            return;
        }


        const cleanPassword =
            String(password);


        if (
            cleanPassword.length < 4
        ) {

            showMessage(
                'La contraseña debe tener mínimo 4 caracteres.'
            );

            return;

        }


        const confirmPassword =
            window.prompt(
                'Repite la nueva contraseña:'
            );


        if (confirmPassword === null) {
            return;
        }


        if (
            cleanPassword !==
            String(confirmPassword)
        ) {

            showMessage(
                'Las contraseñas no coinciden.'
            );

            return;

        }


        try {

            setButtonLoading(
                els.changeAccountPasswordBtn,
                true,
                'Actualizando...'
            );


            await postJSON(
                'modules/usuarios/reset-password.php',
                {
                    id: id,
                    password: cleanPassword
                }
            );


            showMessage(
                'Contraseña actualizada correctamente.',
                'success'
            );

        } catch (error) {

            showMessage(
                error.message
            );

        } finally {

            setButtonLoading(
                els.changeAccountPasswordBtn,
                false,
                'Cambiar contraseña'
            );

        }

    }


    async function toggleUser(userId) {

        const user =
            state.users.find(
                function (item) {

                    return Number(item.id) ===
                        Number(userId);

                }
            );


        if (!user) {

            showMessage(
                'No se encontró el usuario.'
            );

            return;

        }


        const active =
            Number(
                user.activo
            ) === 1;


        const message =
            active
                ? '¿Deseas desactivar esta cuenta?'
                : '¿Deseas activar esta cuenta?';


        if (
            !window.confirm(message)
        ) {

            return;

        }


        try {

            await postJSON(
                'modules/usuarios/toggle.php',
                {
                    id: Number(
                        user.id
                    )
                }
            );


            showMessage(
                active
                    ? 'Cuenta desactivada correctamente.'
                    : 'Cuenta activada correctamente.',
                'success'
            );


            await loadUsers();

        } catch (error) {

            showMessage(
                error.message
            );

        }

    }


    /* =========================
       PERMISOS
    ========================= */

    async function loadPermissions(
        userId
    ) {

        if (!userId) {

            clearPermissions();

            return;

        }


        try {

            if (els.permissionsEmptyState) {

                els.permissionsEmptyState.textContent =
                    'Cargando permisos...';

                els.permissionsEmptyState.classList.remove(
                    'hidden'
                );

            }


            if (els.permissionsContent) {

                els.permissionsContent.classList.add(
                    'hidden'
                );

            }


            const result =
                await getJSON(
                    'modules/usuarios/permisos-list.php?usuario_id=' +
                    encodeURIComponent(
                        userId
                    )
                );


            state.permissionsUser =
                result.usuario || null;

            state.permissionGroups =
                Array.isArray(result.grupos)
                    ? result.grupos
                    : [];


            renderPermissions();

        } catch (error) {

            clearPermissions();

            if (els.permissionsEmptyState) {

                els.permissionsEmptyState.textContent =
                    error.message;

            }

        }

    }



    function renderWhatsAppPermissionModule(module, isAdmin) {

        const moduleId = Number(module.id);
        const wa = module.whatsapp || {};
        const access = !!wa.acceso;
        const disabled = isAdmin ? 'disabled' : '';

        const checked = key => wa[key] ? 'checked' : '';
        const subDisabled = key => {
            if (isAdmin || !access) return 'disabled';

            if (
                (key === 'bandeja_responder' || key === 'bandeja_gestionar')
                && !wa.bandeja_ver
            ) return 'disabled';

            if (key === 'automatizacion_modificar' && !wa.automatizacion_ver) {
                return 'disabled';
            }

            if (key === 'plantillas_gestionar' && !wa.plantillas_ver) {
                return 'disabled';
            }

            return '';
        };

        return `
            <div
                class="permission-row permission-row--whatsapp"
                data-module-id="${moduleId}"
                data-special="whatsapp"
            >
                <div class="wa-permission-head">
                    <div>
                        <strong>WhatsApp</strong>
                        <span>Controla el acceso al módulo y qué puede hacer dentro de cada apartado.</span>
                    </div>

                    <label class="wa-permission-master">
                        <input
                            type="checkbox"
                            class="wa-permission-master-input"
                            ${access ? 'checked' : ''}
                            ${disabled}
                        >
                        <span>Acceso al módulo</span>
                    </label>
                </div>

                <div class="wa-permission-sections ${access ? '' : 'is-disabled'}">

                    <article class="wa-permission-section wa-permission-section--channels" data-wa-permission-section="canales">
                        <div class="wa-permission-section__copy">
                            <strong>Canales disponibles</strong>
                            <span>Define qué conversaciones puede ver este usuario dentro de la bandeja multicanal.</span>
                        </div>
                        <div class="wa-permission-options wa-permission-channel-options">
                            <label>
                                <input type="checkbox" data-wa-permission="canal_whatsapp" ${checked('canal_whatsapp')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>WhatsApp</span>
                            </label>
                            <label>
                                <input type="checkbox" data-wa-permission="canal_instagram" ${checked('canal_instagram')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>Instagram</span>
                            </label>
                        </div>
                    </article>

                    <article class="wa-permission-section" data-wa-permission-section="bandeja">
                        <div class="wa-permission-section__copy">
                            <strong>Bandeja</strong>
                            <span>Conversaciones y atención directa a pacientes.</span>
                        </div>
                        <div class="wa-permission-options">
                            <label>
                                <input type="checkbox" data-wa-permission="bandeja_ver" ${checked('bandeja_ver')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>Ver</span>
                            </label>
                            <label>
                                <input type="checkbox" data-wa-permission="bandeja_responder" ${checked('bandeja_responder')} ${subDisabled('bandeja_responder')}>
                                <span>Responder</span>
                            </label>
                            <label>
                                <input type="checkbox" data-wa-permission="bandeja_gestionar" ${checked('bandeja_gestionar')} ${subDisabled('bandeja_gestionar')}>
                                <span>Gestionar</span>
                            </label>
                        </div>
                    </article>

                    <article class="wa-permission-section" data-wa-permission-section="automatizacion">
                        <div class="wa-permission-section__copy">
                            <strong>Automatización</strong>
                            <span>Horarios, mensajes base, reglas y pruebas.</span>
                        </div>
                        <div class="wa-permission-options">
                            <label>
                                <input type="checkbox" data-wa-permission="automatizacion_ver" ${checked('automatizacion_ver')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>Ver</span>
                            </label>
                            <label>
                                <input type="checkbox" data-wa-permission="automatizacion_modificar" ${checked('automatizacion_modificar')} ${subDisabled('automatizacion_modificar')}>
                                <span>Modificar</span>
                            </label>
                        </div>
                    </article>

                    <article class="wa-permission-section" data-wa-permission-section="plantillas">
                        <div class="wa-permission-section__copy">
                            <strong>Plantillas y respuestas</strong>
                            <span>Plantillas oficiales y respuestas rápidas del equipo.</span>
                        </div>
                        <div class="wa-permission-options">
                            <label>
                                <input type="checkbox" data-wa-permission="plantillas_ver" ${checked('plantillas_ver')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>Ver</span>
                            </label>
                            <label>
                                <input type="checkbox" data-wa-permission="plantillas_gestionar" ${checked('plantillas_gestionar')} ${subDisabled('plantillas_gestionar')}>
                                <span>Gestionar</span>
                            </label>
                        </div>
                    </article>

                    <article class="wa-permission-section" data-wa-permission-section="analitica">
                        <div class="wa-permission-section__copy">
                            <strong>Analítica</strong>
                            <span>Métricas de atención, demanda y rendimiento.</span>
                        </div>
                        <div class="wa-permission-options">
                            <label>
                                <input type="checkbox" data-wa-permission="analitica_ver" ${checked('analitica_ver')} ${isAdmin || !access ? 'disabled' : ''}>
                                <span>Ver</span>
                            </label>
                        </div>
                    </article>

                </div>

                <div class="wa-permission-help">
                    <strong>Uso de plantillas desde Bandeja:</strong>
                    una persona con permiso para <b>Responder</b> puede usar plantillas aprobadas para retomar conversaciones, aunque no pueda administrarlas.
                </div>
            </div>
        `;
    }


    function syncWhatsAppPermissionDependencies() {

        if (!els.permissionsGroups) return;

        const row = els.permissionsGroups.querySelector(
            '.permission-row--whatsapp'
        );

        if (!row) return;

        const master = row.querySelector('.wa-permission-master-input');
        const sections = row.querySelector('.wa-permission-sections');
        const isAdmin = String(state.permissionsUser?.rol || '') === 'admin';
        const enabled = !!master?.checked;

        if (sections) {
            sections.classList.toggle('is-disabled', !enabled);
        }

        const get = key => row.querySelector(`[data-wa-permission="${key}"]`);
        const all = Array.from(row.querySelectorAll('[data-wa-permission]'));

        if (!enabled && !isAdmin) {
            all.forEach(input => {
                input.checked = false;
                input.disabled = true;
            });
            return;
        }

        if (isAdmin) {
            all.forEach(input => input.disabled = true);
            return;
        }

        all.forEach(input => input.disabled = false);

        const inboxView = get('bandeja_ver');
        const reply = get('bandeja_responder');
        const manage = get('bandeja_gestionar');
        if (!inboxView?.checked) {
            if (reply) { reply.checked = false; reply.disabled = true; }
            if (manage) { manage.checked = false; manage.disabled = true; }
        }

        const autoView = get('automatizacion_ver');
        const autoModify = get('automatizacion_modificar');
        if (!autoView?.checked && autoModify) {
            autoModify.checked = false;
            autoModify.disabled = true;
        }

        const libraryView = get('plantillas_ver');
        const libraryManage = get('plantillas_gestionar');
        if (!libraryView?.checked && libraryManage) {
            libraryManage.checked = false;
            libraryManage.disabled = true;
        }
    }

    function renderPermissions() {

        if (
            !state.permissionsUser ||
            !els.permissionsGroups
        ) {

            clearPermissions();

            return;

        }


        const user =
            state.permissionsUser;

        const isAdmin =
            String(
                user.rol || ''
            ) === 'admin';


        if (els.permissionsEmptyState) {

            els.permissionsEmptyState.classList.add(
                'hidden'
            );

        }


        if (els.permissionsContent) {

            els.permissionsContent.classList.remove(
                'hidden'
            );

        }


        if (els.permissionsAccountInfo) {

            els.permissionsAccountInfo.innerHTML = `
                <div>
                    <strong>
                        ${escapeHtml(
                            user.nombre || ''
                        )}
                    </strong>

                    <span>
                        ${escapeHtml(
                            user.usuario || ''
                        )}
                        ·
                        ${escapeHtml(
                            roleLabel(
                                user.rol
                            )
                        )}
                    </span>
                </div>

                ${
                    isAdmin
                        ? `
                            <span class="
                                account-protected-badge
                            ">
                                Acceso total protegido
                            </span>
                        `
                        : ''
                }
            `;

        }


        const groups =
            Array.isArray(
                state.permissionGroups
            )
                ? state.permissionGroups
                : [];


        if (!groups.length) {

            els.permissionsGroups.innerHTML = `
                <div class="permissions-empty">
                    No hay módulos disponibles.
                </div>
            `;

            return;

        }


        els.permissionsGroups.innerHTML =
            groups.map(
                function (group) {

                    const modules =
                        Array.isArray(
                            group.modulos
                        )
                            ? group.modulos
                            : [];


                    return `
                        <article class="permissions-group">

                            <button
                                type="button"
                                class="permissions-group__header"
                            >
                                <span>
                                    ${escapeHtml(
                                        group.nombre || ''
                                    )}
                                </span>

                                <span class="
                                    permissions-group__chevron
                                ">
                                    ▾
                                </span>
                            </button>

                            <div class="
                                permissions-group__body
                            ">

                                ${
                                    modules.map(
                                        function (module) {

                                            if (module.tipo === 'whatsapp_detallado') {
                                                return renderWhatsAppPermissionModule(
                                                    module,
                                                    isAdmin
                                                );
                                            }

                                            const moduleId =
                                                Number(
                                                    module.id
                                                );

                                            const canView =
                                                Number(
                                                    module.puede_ver
                                                ) === 1;

                                            const canModify =
                                                Number(
                                                    module.puede_modificar
                                                ) === 1;

                                            const disabled =
                                                isAdmin
                                                    ? 'disabled'
                                                    : '';

                                            const modifyDisabled =
                                                (
                                                    isAdmin ||
                                                    !canView
                                                )
                                                    ? 'disabled'
                                                    : '';


                                            return `
                                                <div
                                                    class="permission-row"
                                                    data-module-id="${moduleId}"
                                                >

                                                    <div
                                                        class="
                                                            permission-row__module
                                                        "
                                                    >
                                                        <strong>
                                                            ${escapeHtml(
                                                                module.nombre ||
                                                                ''
                                                            )}
                                                        </strong>
                                                    </div>

                                                    <label
                                                        class="
                                                            permission-check
                                                        "
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            class="
                                                                permission-view
                                                            "
                                                            data-module-id="
                                                                ${moduleId}
                                                            "
                                                            ${
                                                                canView
                                                                    ? 'checked'
                                                                    : ''
                                                            }
                                                            ${disabled}
                                                        >

                                                        <span>
                                                            Ver
                                                        </span>
                                                    </label>

                                                    <label
                                                        class="
                                                            permission-check
                                                        "
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            class="
                                                                permission-modify
                                                            "
                                                            data-module-id="
                                                                ${moduleId}
                                                            "
                                                            ${
                                                                canModify
                                                                    ? 'checked'
                                                                    : ''
                                                            }
                                                            ${modifyDisabled}
                                                        >

                                                        <span>
                                                            Modificar
                                                        </span>
                                                    </label>

                                                </div>
                                            `;

                                        }
                                    ).join('')
                                }

                            </div>

                        </article>
                    `;

                }
            ).join('');


        els.permissionsGroups
            .querySelectorAll(
                '.permissions-group__header'
            )
            .forEach(
                function (button) {

                    button.addEventListener(
                        'click',
                        function () {

                            const group =
                                button.closest(
                                    '.permissions-group'
                                );

                            if (!group) {
                                return;
                            }

                            group.classList.toggle(
                                'collapsed'
                            );

                        }
                    );

                }
            );


        if (!isAdmin) {

            els.permissionsGroups
                .querySelectorAll(
                    '.permission-view'
                )
                .forEach(
                    function (checkbox) {

                        checkbox.addEventListener(
                            'change',
                            function () {

                                const moduleId =
                                    checkbox.dataset.moduleId;

                                const modify =
                                    els.permissionsGroups
                                        .querySelector(
                                            '.permission-modify' +
                                            '[data-module-id="' +
                                            moduleId +
                                            '"]'
                                        );


                                if (!modify) {
                                    return;
                                }


                                if (!checkbox.checked) {

                                    modify.checked =
                                        false;

                                    modify.disabled =
                                        true;

                                } else {

                                    modify.disabled =
                                        false;

                                }

                            }
                        );

                    }
                );

        }


        if (!isAdmin) {
            const waRow = els.permissionsGroups.querySelector(
                '.permission-row--whatsapp'
            );

            if (waRow) {
                waRow.addEventListener('change', function (event) {
                    if (
                        event.target.matches('.wa-permission-master-input')
                        || event.target.matches('[data-wa-permission]')
                    ) {
                        syncWhatsAppPermissionDependencies();
                    }
                });
            }
        }

        syncWhatsAppPermissionDependencies();

        if (els.savePermissionsBtn) {

            els.savePermissionsBtn.disabled =
                isAdmin;

            els.savePermissionsBtn.textContent =
                isAdmin
                    ? 'Permisos protegidos'
                    : 'Guardar permisos';

        }

    }


    function clearPermissions() {

        state.permissionsUser =
            null;

        state.permissionGroups =
            [];


        if (els.permissionsEmptyState) {

            els.permissionsEmptyState.textContent =
                'Selecciona una cuenta para configurar sus permisos.';

            els.permissionsEmptyState.classList.remove(
                'hidden'
            );

        }


        if (els.permissionsContent) {

            els.permissionsContent.classList.add(
                'hidden'
            );

        }


        if (els.permissionsAccountInfo) {

            els.permissionsAccountInfo.innerHTML =
                '';

        }


        if (els.permissionsGroups) {

            els.permissionsGroups.innerHTML =
                '';

        }


        if (els.savePermissionsBtn) {

            els.savePermissionsBtn.disabled =
                false;

            els.savePermissionsBtn.textContent =
                'Guardar permisos';

        }

    }


    async function savePermissions() {

        if (
            !state.permissionsUser ||
            !els.permissionsGroups
        ) {

            showMessage(
                'Selecciona una cuenta.'
            );

            return;

        }


        const userId =
            Number(
                state.permissionsUser.id
            );


        if (!userId) {

            showMessage(
                'Cuenta no válida.'
            );

            return;

        }


        if (
            String(
                state.permissionsUser.rol
            ) === 'admin'
        ) {

            showMessage(
                'Los permisos del Administrador están protegidos.'
            );

            return;

        }


        const permissions =
            [];

        let whatsappDetailed = null;


        els.permissionsGroups
            .querySelectorAll(
                '.permission-row'
            )
            .forEach(
                function (row) {

                    const moduleId =
                        Number(
                            row.dataset.moduleId
                        );

                    if (row.dataset.special === 'whatsapp') {
                        const master = row.querySelector(
                            '.wa-permission-master-input'
                        );
                        const read = key => {
                            const input = row.querySelector(
                                `[data-wa-permission="${key}"]`
                            );
                            return input && input.checked ? 1 : 0;
                        };

                        const access = master && master.checked ? 1 : 0;

                        whatsappDetailed = {
                            acceso: access,
                            canal_whatsapp: access ? read('canal_whatsapp') : 0,
                            canal_instagram: access ? read('canal_instagram') : 0,
                            bandeja_ver: access ? read('bandeja_ver') : 0,
                            bandeja_responder: access ? read('bandeja_responder') : 0,
                            bandeja_gestionar: access ? read('bandeja_gestionar') : 0,
                            automatizacion_ver: access ? read('automatizacion_ver') : 0,
                            automatizacion_modificar: access ? read('automatizacion_modificar') : 0,
                            plantillas_ver: access ? read('plantillas_ver') : 0,
                            plantillas_gestionar: access ? read('plantillas_gestionar') : 0,
                            analitica_ver: access ? read('analitica_ver') : 0
                        };

                        const hasWrite = access && (
                            whatsappDetailed.bandeja_responder
                            || whatsappDetailed.bandeja_gestionar
                            || whatsappDetailed.automatizacion_modificar
                            || whatsappDetailed.plantillas_gestionar
                        );

                        permissions.push({
                            modulo_id: moduleId,
                            puede_ver: access,
                            puede_modificar: hasWrite ? 1 : 0
                        });

                        return;
                    }

                    const view =
                        row.querySelector(
                            '.permission-view'
                        );

                    const modify =
                        row.querySelector(
                            '.permission-modify'
                        );


                    if (!moduleId) {
                        return;
                    }


                    const canView =
                        view &&
                        view.checked
                            ? 1
                            : 0;

                    const canModify =
                        (
                            canView &&
                            modify &&
                            modify.checked
                        )
                            ? 1
                            : 0;


                    permissions.push({
                        modulo_id:
                            moduleId,

                        puede_ver:
                            canView,

                        puede_modificar:
                            canModify
                    });

                }
            );


        try {

            setButtonLoading(
                els.savePermissionsBtn,
                true,
                'Guardando...'
            );


            await postJSON(
                'modules/usuarios/permisos-save.php',
                {
                    usuario_id:
                        userId,

                    permisos:
                        permissions,

                    whatsapp_permisos:
                        whatsappDetailed
                }
            );


            showMessage(
                'Permisos actualizados correctamente.',
                'success'
            );


            await loadPermissions(
                userId
            );

        } catch (error) {

            showMessage(
                error.message
            );

        } finally {

            setButtonLoading(
                els.savePermissionsBtn,
                false,
                'Guardar permisos'
            );

        }

    }


    /* =========================
       PETICIONES
    ========================= */

    async function getJSON(url) {

        const response =
            await fetch(
                url,
                {
                    method: 'GET',

                    headers: {
                        'Accept':
                            'application/json'
                    },

                    cache:
                        'no-store'
                }
            );


        const text =
            await response.text();


        let json;


        try {

            json =
                JSON.parse(text);

        } catch (e) {

            throw new Error(
                text ||
                'Respuesta no válida'
            );

        }


        if (
            !response.ok ||
            !json.success
        ) {

            throw new Error(
                json.message ||
                'No se pudo completar la consulta.'
            );

        }


        return json;

    }


    async function postJSON(
        url,
        data
    ) {

        const response =
            await fetch(
                url,
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json',

                        'Accept':
                            'application/json',

                        'X-CSRF-Token':
                            window.APP_CSRF_TOKEN || ''
                    },

                    body:
                        JSON.stringify(
                            data || {}
                        )
                }
            );


        const text =
            await response.text();


        let json;


        try {

            json =
                JSON.parse(text);

        } catch (e) {

            throw new Error(
                text ||
                'Respuesta no válida'
            );

        }


        if (
            !response.ok ||
            !json.success
        ) {

            throw new Error(
                json.message ||
                'No se pudo completar la acción.'
            );

        }


        return json;

    }


    /* =========================
       UTILIDADES
    ========================= */

    function roleLabel(role) {

        switch (
            String(role || '')
        ) {

            case 'admin':
                return 'Administrador';

            case 'marketing':
                return 'Marketing';

            case 'centro_medico':
                return 'Centro Médico';

            case 'recepcion':
                return 'Recepción';

            default:
                return role || '-';

        }

    }


    function formatDateTime(value) {

        if (!value) {
            return 'Nunca';
        }


        const normalized =
            String(value)
                .replace(
                    ' ',
                    'T'
                );


        const date =
            new Date(
                normalized
            );


        if (
            Number.isNaN(
                date.getTime()
            )
        ) {

            return escapeHtml(
                String(value)
            );

        }


        const fecha =
            date.toLocaleDateString(
                'es-PE',
                {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                }          
            );

        const hora =
            date.toLocaleTimeString(
                'es-PE',
                {
                    hour: '2-digit',
                    minute: '2-digit'
                }
            );

        return `
            <span class="last-access">
                <span>${fecha}</span>
                <small>${hora}</small>
            </span>
        `;

    }


    function setTableLoading() {

        if (!els.tableBody) {
            return;
        }


        els.tableBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="6">
                    Cargando usuarios...
                </td>
            </tr>
        `;

    }


    function showTableError(
        message
    ) {

        if (!els.tableBody) {
            return;
        }


        els.tableBody.innerHTML = `
            <tr class="empty-row">
                <td colspan="6">
                    ${escapeHtml(
                        message ||
                        'No se pudieron cargar los usuarios.'
                    )}
                </td>
            </tr>
        `;

    }


    function setButtonLoading(
        button,
        loading,
        text
    ) {

        if (!button) {
            return;
        }


        if (loading) {

            if (
                !button.dataset.originalText
            ) {

                button.dataset.originalText =
                    button.textContent;

            }

            button.disabled =
                true;

            button.textContent =
                text;

            return;

        }


        button.disabled =
            false;

        button.textContent =
            text ||
            button.dataset.originalText ||
            button.textContent;

    }


    function showMessage(
        message,
        type
    ) {

        const text =
            String(
                message ||
                'Ocurrió un error.'
            );


        if (
            type === 'success'
        ) {

            window.alert(
                text
            );

            return;

        }


        window.alert(
            text
        );

    }


    function scrollToAccountForm() {

        if (!els.form) {
            return;
        }


        els.form.scrollIntoView({
            behavior:
                'smooth',

            block:
                'start'
        });

    }


    function scrollToPermissions() {

        if (!els.permissionsPanel) {
            return;
        }


        els.permissionsPanel.scrollIntoView({
            behavior:
                'smooth',

            block:
                'start'
        });

    }


    function escapeHtml(value) {

        return String(
            value ?? ''
        )
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );

    }

})();