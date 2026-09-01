<?php include 'templates/header.php'; ?>

<main class="container">

    <section class="dashboard-hero panel">
        <div class="dashboard-hero__content">
            <span class="dashboard-label">Administración</span>

            <h1>Usuarios del sistema</h1>

            <p>
                Administra las cuentas principales del sistema
                y configura qué apartados puede ver o modificar cada una.
            </p>
        </div>
    </section>


    <!-- =====================================================
         CUENTAS
    ====================================================== -->

    <section class="users-grid">

        <!-- GESTIÓN DE CUENTA -->
        <article class="user-form-card">

            <h2>
                Gestión de cuenta
            </h2>

            <p>
                Selecciona una cuenta para modificar
                su nombre visible, usuario de acceso o contraseña.
            </p>


            <form
                id="userAdminForm"
                class="user-admin-form"
                autocomplete="off"
            >

                <input
                    type="hidden"
                    id="userId"
                    name="id"
                    value=""
                >


                <div class="field-group">

                    <label for="accountSelect">
                        Cuenta
                    </label>

                    <select
                        id="accountSelect"
                        required
                    >
                        <option value="">
                            Seleccionar cuenta
                        </option>
                    </select>

                </div>


                <div
                    class="account-type-info hidden"
                    id="accountTypeInfo"
                >
                    <span>
                        Tipo de cuenta
                    </span>

                    <strong id="accountTypeLabel">
                        -
                    </strong>
                </div>


                <div class="field-group">

                    <label for="nombre">
                        Nombre visible
                    </label>

                    <input
                        type="text"
                        id="nombre"
                        name="nombre"
                        placeholder="Selecciona una cuenta"
                        disabled
                        required
                    >

                </div>


                <div class="field-group">

                    <label for="usuario">
                        Usuario de acceso
                    </label>

                    <input
                        type="text"
                        id="usuario"
                        name="usuario"
                        placeholder="Selecciona una cuenta"
                        disabled
                        required
                    >

                    <small>
                        Usa letras, números, punto,
                        guion o guion bajo. Sin espacios.
                    </small>

                </div>


                <div class="account-management-actions">

                    <button
                        class="btn-primary"
                        type="submit"
                        id="saveUserBtn"
                        disabled
                    >
                        Guardar cambios
                    </button>


                    <button
                        class="btn-secondary"
                        type="button"
                        id="changeAccountPasswordBtn"
                        disabled
                    >
                        Cambiar contraseña
                    </button>

                </div>

            </form>

        </article>


        <!-- LISTADO -->
        <article class="users-list-card">

            <div
                class="table-header"
                style="
                    padding:0;
                    margin-bottom:14px;
                "
            >

                <div>

                    <h2>
                        Cuentas del sistema
                    </h2>

                    <p>
                        Cada tipo dispone de una única cuenta.
                        Puedes editar sus datos, cambiar la contraseña,
                        gestionar permisos y controlar su estado.
                    </p>

                </div>


                <button
                    class="btn-secondary"
                    id="refreshUsersBtn"
                    type="button"
                >
                    Actualizar
                </button>

            </div>


            <div class="table-wrapper">

                <table class="records-table users-table">

                    <thead>

                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Acceso</th>
                            <th>Acciones</th>
                        </tr>

                    </thead>


                    <tbody id="usersTableBody">

                        <tr class="empty-row">
                            <td colspan="6">
                                Cargando usuarios...
                            </td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </article>

    </section>


    <!-- =====================================================
         PERMISOS
    ====================================================== -->

    <section
        class="panel permissions-panel"
        id="permissionsPanel"
    >

        <div class="permissions-header">

            <div>

                <span class="dashboard-label">
                    Accesos
                </span>

                <h2>
                    Permisos de acceso
                </h2>

                <p>
                    Selecciona una cuenta y define qué módulos
                    puede ver y cuáles puede modificar.
                </p>

            </div>


            <div class="permissions-account-selector">

                <label for="permissionsUserSelect">
                    Cuenta
                </label>

                <select id="permissionsUserSelect">
                    <option value="">
                        Seleccionar cuenta
                    </option>
                </select>

            </div>

        </div>


        <div
            id="permissionsEmptyState"
            class="permissions-empty"
        >
            Selecciona una cuenta para configurar sus permisos.
        </div>


        <div
            id="permissionsContent"
            class="permissions-content hidden"
        >

            <div
                id="permissionsAccountInfo"
                class="permissions-account-info"
            ></div>


            <div
                id="permissionsGroups"
                class="permissions-groups"
            ></div>


            <div class="permissions-actions">

                <button
                    class="btn-primary"
                    type="button"
                    id="savePermissionsBtn"
                >
                    Guardar permisos
                </button>

            </div>

        </div>

    </section>

</main>


<script src="assets/js/usuarios.js"></script>
<?php include 'templates/footer.php'; ?>