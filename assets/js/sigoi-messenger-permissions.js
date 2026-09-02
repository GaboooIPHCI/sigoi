(function () {
    'use strict';

    if (!document.getElementById('permissionsPanel')) {
        return;
    }

    const nativeFetch = window.fetch.bind(window);

    function csrfHeaders(extra) {
        return Object.assign({
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': window.APP_CSRF_TOKEN
        }, extra || {});
    }

    async function fetchPermission(userId) {
        if (!userId) return 0;

        const response = await nativeFetch(
            'modules/messenger/permission.php?usuario_id='
            + encodeURIComponent(userId),
            {
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message
                || 'No se pudo consultar el permiso de Messenger.'
            );
        }

        return Number(data.canal_messenger || 0);
    }

    async function savePermission(userId, enabled) {
        if (!userId) return;

        const response = await nativeFetch(
            'modules/messenger/permission.php',
            {
                method: 'POST',
                headers: csrfHeaders(),
                body: JSON.stringify({
                    usuario_id: Number(userId),
                    canal_messenger: enabled ? 1 : 0
                })
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.message
                || 'No se pudo guardar el permiso de Messenger.'
            );
        }
    }

    function selectedUserId() {
        return Number(
            document.getElementById('permissionsUserSelect')?.value
            || 0
        );
    }

    async function injectMessengerPermission() {
        const container = document.querySelector(
            '.permission-row--whatsapp .wa-permission-channel-options'
        );

        if (!container) return;

        let input = container.querySelector(
            '[data-wa-permission="canal_messenger"]'
        );

        if (!input) {
            const label = document.createElement('label');

            label.className = 'wa-permission-channel-messenger';
            label.innerHTML = `
                <input
                    type="checkbox"
                    data-wa-permission="canal_messenger"
                >
                <span>Messenger</span>
            `;

            container.appendChild(label);

            input = label.querySelector('input');
        }

        if (!input) return;

        const master = document.querySelector(
            '.permission-row--whatsapp .wa-permission-master-input'
        );

        const userId = selectedUserId();

        input.disabled =
            !master?.checked
            || !!master?.disabled;

        if (!userId) {
            input.checked = false;
            return;
        }

        const requestId = String(userId);

        input.dataset.loadingUser = requestId;

        try {
            const enabled = await fetchPermission(userId);

            if (input.dataset.loadingUser !== requestId) {
                return;
            }

            input.checked = enabled === 1;

        } catch (_) {
            /*
             * No bloqueamos el resto de permisos si Messenger
             * todavía no tiene su migración lista.
             */
            input.checked = false;
        }
    }

    /*
     * Interceptamos únicamente el guardado de permisos ya existente.
     * El controlador principal sigue guardando todos sus campos y,
     * antes de devolver la respuesta al UI, guardamos el canal Messenger.
     */
    window.fetch = async function (input, init) {
        const url = typeof input === 'string'
            ? input
            : String(input?.url || '');

        if (
            !url.includes('modules/usuarios/permisos-save.php')
            || String(init?.method || 'GET').toUpperCase() !== 'POST'
        ) {
            return nativeFetch(input, init);
        }

        let payload = null;

        try {
            payload = JSON.parse(String(init?.body || '{}'));
        } catch (_) {}

        const messengerInput = document.querySelector(
            '[data-wa-permission="canal_messenger"]'
        );

        const messengerEnabled =
            !!messengerInput?.checked;

        if (
            payload
            && payload.whatsapp_permisos
            && typeof payload.whatsapp_permisos === 'object'
        ) {
            payload.whatsapp_permisos.canal_messenger =
                messengerEnabled ? 1 : 0;

            init = Object.assign({}, init, {
                body: JSON.stringify(payload)
            });
        }

        const response = await nativeFetch(input, init);

        let coreSaved = response.ok;

        try {
            const snapshot = await response.clone().json();
            coreSaved = response.ok && !!snapshot.success;
        } catch (_) {}

        if (coreSaved && payload?.usuario_id) {
            await savePermission(
                Number(payload.usuario_id),
                messengerEnabled
            );
        }

        return response;
    };

    const groups = document.getElementById(
        'permissionsGroups'
    );

    if (groups) {
        const observer = new MutationObserver(function () {
            injectMessengerPermission();
        });

        observer.observe(groups, {
            childList: true,
            subtree: true
        });
    }

    document.getElementById('permissionsUserSelect')
        ?.addEventListener('change', function () {
            setTimeout(
                injectMessengerPermission,
                50
            );
        });

    document.addEventListener('change', function (event) {
        if (
            event.target.matches(
                '.wa-permission-master-input'
            )
        ) {
            setTimeout(
                injectMessengerPermission,
                0
            );
        }
    });

    injectMessengerPermission();
})();
