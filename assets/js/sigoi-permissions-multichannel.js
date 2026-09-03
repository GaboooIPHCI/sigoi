(function () {
    'use strict';

    const groups = document.getElementById('permissionsGroups');
    const select = document.getElementById('permissionsUserSelect');
    const save = document.getElementById('savePermissionsBtn');
    if (!groups || !select || !save) return;

    let loadToken = 0;
    let saving = false;
    let lastDecoratedRow = null;
    let lastUserId = 0;
    let bootstrapTimer = null;

    function toast(message, error) {
        const box = document.getElementById('toast');
        if (!box) return;
        box.textContent = String(message || '');
        box.className = 'toast ' + (error ? 'toast-error' : 'toast-success');
        box.classList.remove('hidden');
        clearTimeout(box._rc31PermTimer);
        box._rc31PermTimer = setTimeout(function () {
            box.classList.add('hidden');
        }, 3400);
    }

    function userId() {
        return Number(select.value || 0);
    }

    function permissionRow() {
        return groups.querySelector('.permission-row--whatsapp');
    }

    function removeLegacyMarks(container) {
        if (!container) return;

        container
            .querySelectorAll('.sigoi-channel-icon,.wa-permission-channel-icon,[data-messenger-channel-badge]')
            .forEach(function (node) { node.remove(); });

        container.querySelectorAll('label').forEach(function (label) {
            const spans = Array.from(label.children).filter(function (node) {
                return node.tagName === 'SPAN';
            });

            if (spans.length <= 1) return;

            spans.forEach(function (span) {
                const text = String(span.textContent || '').trim().toUpperCase();
                if (['W', 'WA', 'IG', 'M', 'MSG'].indexOf(text) !== -1) {
                    span.remove();
                }
            });
        });
    }

    function decorate(target) {
        if (!target) return;

        const group = target.closest('.permissions-group');
        const groupTitle = group ? group.querySelector('.permissions-group__header > span:first-child') : null;
        if (groupTitle) groupTitle.textContent = 'Conversaciones';

        const title = target.querySelector('.wa-permission-head strong');
        if (title) title.textContent = 'Centro de conversaciones';

        const copy = target.querySelector('.wa-permission-head strong + span');
        if (copy) copy.textContent = 'Controla el acceso a la bandeja multicanal y sus herramientas.';

        const section = target.querySelector('[data-wa-permission-section="canales"]');
        if (section) {
            const sectionTitle = section.querySelector('.wa-permission-section__copy strong');
            const sectionCopy = section.querySelector('.wa-permission-section__copy span');
            if (sectionTitle) sectionTitle.textContent = 'Canales disponibles';
            if (sectionCopy) sectionCopy.textContent = 'Define qué canales puede ver y atender esta cuenta.';
        }

        const container = target.querySelector('.wa-permission-channel-options');
        removeLegacyMarks(container);

        if (container) {
            container.querySelectorAll('label').forEach(function (label) {
                const input = label.querySelector('[data-wa-permission]');
                if (!input) return;

                const key = String(input.dataset.waPermission || '');
                if (['canal_whatsapp', 'canal_instagram', 'canal_messenger'].indexOf(key) === -1) return;

                label.classList.add('sigoi-channel-permission-card');
                label.dataset.sigoiChannel = key.replace('canal_', '');
            });
        }
    }

    function ensureMessenger(target, enabled) {
        if (!target) return null;

        const container = target.querySelector('.wa-permission-channel-options');
        if (!container) return null;

        let input = container.querySelector('[data-wa-permission="canal_messenger"]');

        if (!input) {
            const label = document.createElement('label');
            label.className = 'sigoi-channel-permission-card';
            label.dataset.sigoiChannel = 'messenger';
            label.innerHTML = '<input type="checkbox" data-wa-permission="canal_messenger"><span>Messenger</span>';
            container.appendChild(label);
            input = label.querySelector('input');
        }

        input.checked = !!enabled;

        const master = target.querySelector('.wa-permission-master-input');
        input.disabled = !master || !master.checked || !!master.disabled;

        decorate(target);
        return input;
    }

    async function readMessenger(selectedId, token, target) {
        // El canal debe ser visible inmediatamente aunque la lectura falle.
        ensureMessenger(target, false);

        try {
            const response = await fetch(
                'modules/usuarios/messenger-permission-read.php?usuario_id=' + encodeURIComponent(selectedId),
                {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                }
            );

            const data = await response.json();

            if (
                token !== loadToken
                || userId() !== selectedId
                || permissionRow() !== target
            ) {
                return;
            }

            if (response.ok && data && data.success) {
                ensureMessenger(target, Number(data.canal_messenger || 0) === 1);
            }
        } catch (_) {
            // La lectura de Messenger nunca bloquea los demás permisos.
        }
    }

    function applyToFreshRow(selectedId, previousRow, token, attempt) {
        if (!selectedId || selectedId !== userId() || token !== loadToken) return;

        const current = permissionRow();
        const content = document.getElementById('permissionsContent');
        const ready = !!current && (!previousRow || current !== previousRow || !content || !content.classList.contains('hidden'));

        if (ready && current !== lastDecoratedRow) {
            lastDecoratedRow = current;
            ensureMessenger(current, false);
            readMessenger(selectedId, token, current);
            return;
        }

        if (ready && current === lastDecoratedRow) {
            // El mismo nodo puede corresponder a una carga ya terminada. Reaplicamos
            // solo si Messenger desapareció por un render posterior del núcleo.
            if (!current.querySelector('[data-wa-permission="canal_messenger"]')) {
                ensureMessenger(current, false);
                readMessenger(selectedId, token, current);
            }
            return;
        }

        if (attempt >= 80) return;

        setTimeout(function () {
            applyToFreshRow(selectedId, previousRow, token, attempt + 1);
        }, 100);
    }

    function scheduleForSelection(previousRow) {
        const selectedId = userId();
        loadToken += 1;
        const token = loadToken;
        lastUserId = selectedId;

        if (!selectedId) return;

        applyToFreshRow(selectedId, previousRow || null, token, 0);
    }

    function collectPayload() {
        const selectedId = userId();
        if (!selectedId) throw new Error('Selecciona una cuenta.');

        const permisos = [];
        let whatsapp = null;

        groups.querySelectorAll('.permission-row').forEach(function (item) {
            const moduleId = Number(item.dataset.moduleId || 0);
            if (!moduleId) return;

            if (item.dataset.special === 'whatsapp') {
                const master = item.querySelector('.wa-permission-master-input');
                const access = master && master.checked ? 1 : 0;
                const read = function (key) {
                    const input = item.querySelector('[data-wa-permission="' + key + '"]');
                    return input && input.checked ? 1 : 0;
                };

                whatsapp = {
                    acceso: access,
                    canal_whatsapp: access ? read('canal_whatsapp') : 0,
                    canal_instagram: access ? read('canal_instagram') : 0,
                    canal_messenger: access ? read('canal_messenger') : 0,
                    bandeja_ver: access ? read('bandeja_ver') : 0,
                    bandeja_responder: access ? read('bandeja_responder') : 0,
                    bandeja_gestionar: access ? read('bandeja_gestionar') : 0,
                    automatizacion_ver: access ? read('automatizacion_ver') : 0,
                    automatizacion_modificar: access ? read('automatizacion_modificar') : 0,
                    plantillas_ver: access ? read('plantillas_ver') : 0,
                    plantillas_gestionar: access ? read('plantillas_gestionar') : 0,
                    analitica_ver: access ? read('analitica_ver') : 0
                };

                permisos.push({
                    modulo_id: moduleId,
                    puede_ver: access,
                    puede_modificar: access && (
                        whatsapp.bandeja_responder
                        || whatsapp.bandeja_gestionar
                        || whatsapp.automatizacion_modificar
                        || whatsapp.plantillas_gestionar
                    ) ? 1 : 0
                });
                return;
            }

            const view = item.querySelector('.permission-view');
            const modify = item.querySelector('.permission-modify');
            const canView = view && view.checked ? 1 : 0;
            const canModify = canView && modify && modify.checked ? 1 : 0;

            permisos.push({
                modulo_id: moduleId,
                puede_ver: canView,
                puede_modificar: canModify
            });
        });

        return {
            usuario_id: selectedId,
            permisos: permisos,
            whatsapp_permisos: whatsapp
        };
    }

    async function saveAll(event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        if (saving || save.disabled) return;

        let payload;
        try {
            payload = collectPayload();
        } catch (error) {
            toast(error.message, true);
            return;
        }

        saving = true;
        save.disabled = true;
        save.textContent = 'Guardando...';

        try {
            const response = await fetch('modules/usuarios/permisos-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': window.APP_CSRF_TOKEN || ''
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            if (!response.ok || !data || !data.success) {
                throw new Error(data && data.message ? data.message : 'No se pudieron guardar los permisos.');
            }

            toast('Permisos actualizados correctamente.');

            // Dejamos que el controlador original vuelva a cargar toda la ficha.
            const previous = permissionRow();
            select.dispatchEvent(new Event('change', { bubbles: true }));
            scheduleForSelection(previous);
        } catch (error) {
            toast(error.message, true);
        } finally {
            saving = false;
            save.disabled = false;
            save.textContent = 'Guardar permisos';
        }
    }

    // Capture=true garantiza que el guardado multicanal sustituya al guardado
    // antiguo sin modificar window.fetch ni otros APIs globales.
    save.addEventListener('click', saveAll, true);

    select.addEventListener('change', function () {
        const previous = permissionRow();
        // El listener original ya inició su fetch; esperamos un nodo nuevo.
        scheduleForSelection(previous);
    });

    groups.addEventListener('change', function (event) {
        if (!event.target.matches('.wa-permission-master-input')) return;

        const messenger = groups.querySelector('[data-wa-permission="canal_messenger"]');
        if (!messenger) return;

        messenger.disabled = !event.target.checked || !!event.target.disabled;
        if (!event.target.checked) messenger.checked = false;
    });

    // Cubre la selección restaurada automáticamente por usuarios.js al entrar
    // a la página. Es un sondeo corto y acotado; no observa el DOM ni toca APIs
    // globales y se detiene por sí solo.
    let bootstrapAttempts = 0;
    function bootstrap() {
        bootstrapAttempts += 1;

        const selectedId = userId();
        const current = permissionRow();

        if (selectedId && current && (selectedId !== lastUserId || current !== lastDecoratedRow)) {
            scheduleForSelection(null);
        }

        if (bootstrapAttempts < 40) {
            bootstrapTimer = setTimeout(bootstrap, 250);
        } else if (bootstrapTimer) {
            clearTimeout(bootstrapTimer);
            bootstrapTimer = null;
        }
    }

    bootstrap();
})();
