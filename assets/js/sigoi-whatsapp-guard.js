(function () {
    'use strict';

    const permissions = window.SIGOI_WHATSAPP_MENU_PERMISSIONS || {};

    function applyWhatsappMenuPermissions() {
        const inboxLink = document.querySelector(
            'a.nav-dropdown__item[href*="whatsapp.php?tab=inbox"]'
        );

        const group = inboxLink
            ? inboxLink.closest('[data-nav-dropdown]')
            : null;

        if (!group) return;

        const rules = [
            {
                selector: 'a[href*="whatsapp.php?tab=inbox"]',
                allowed: !!permissions.bandeja_ver
            },
            {
                selector: 'a[href*="whatsapp.php?tab=automation"]',
                allowed: !!permissions.automatizacion_ver
            },
            {
                selector: 'a[href*="whatsapp.php?tab=library"]',
                allowed: !!permissions.plantillas_ver
            },
            {
                selector: 'a[href*="analitica-multicanal.php"]',
                allowed: !!permissions.analitica_ver
            }
        ];

        let visible = 0;

        rules.forEach(function (rule) {
            const item = group.querySelector(rule.selector);
            if (!item) return;

            item.hidden = !rule.allowed;
            item.style.display = rule.allowed ? '' : 'none';

            if (rule.allowed) {
                visible++;
            }
        });

        group.hidden = visible === 0;
        group.style.display = visible === 0 ? 'none' : '';
    }

    function setupContactDraftProtection() {
        const nameInput = document.getElementById('waContactCustomName');
        const notesInput = document.getElementById('waContactNotes');

        if (!nameInput || !notesInput) return;

        const state = {
            key: '',
            nameDirty: false,
            notesDirty: false,
            nameDraft: '',
            notesDraft: ''
        };

        function selectedConversationKey() {
            const selected = document.querySelector(
                '.wa-conversation-item.is-selected[data-wa-conversation]'
            );

            if (!selected) return '';

            const channel = String(
                selected.dataset.waChannel || 'whatsapp'
            );

            const id = String(
                selected.dataset.waConversation || ''
            );

            return channel + ':' + id;
        }

        function resetForConversation(key) {
            state.key = key || '';
            state.nameDirty = false;
            state.notesDirty = false;
            state.nameDraft = '';
            state.notesDraft = '';
        }

        function syncConversationKey() {
            const key = selectedConversationKey();

            if (key !== state.key) {
                resetForConversation(key);
            }

            return key;
        }

        function protectValue(element, field) {
            const proto = element instanceof HTMLTextAreaElement
                ? HTMLTextAreaElement.prototype
                : HTMLInputElement.prototype;

            const descriptor = Object.getOwnPropertyDescriptor(
                proto,
                'value'
            );

            if (!descriptor || !descriptor.get || !descriptor.set) {
                return;
            }

            Object.defineProperty(element, 'value', {
                configurable: true,
                enumerable: descriptor.enumerable,

                get: function () {
                    return descriptor.get.call(this);
                },

                set: function (nextValue) {
                    syncConversationKey();

                    const text = nextValue == null
                        ? ''
                        : String(nextValue);

                    const dirty = field === 'name'
                        ? state.nameDirty
                        : state.notesDirty;

                    const draft = field === 'name'
                        ? state.nameDraft
                        : state.notesDraft;

                    /*
                     * whatsapp.js refresca la conversación cada 2 s.
                     * Mientras existe un borrador local, ignoramos únicamente
                     * un valor de servidor que intentaría pisar lo escrito.
                     */
                    if (dirty && text !== draft) {
                        return;
                    }

                    descriptor.set.call(this, text);
                }
            });

            element.addEventListener('input', function () {
                syncConversationKey();

                if (field === 'name') {
                    state.nameDraft = descriptor.get.call(element);
                    state.nameDirty = true;
                } else {
                    state.notesDraft = descriptor.get.call(element);
                    state.notesDirty = true;
                }
            });
        }

        protectValue(nameInput, 'name');
        protectValue(notesInput, 'notes');

        /*
         * Al elegir otra conversación descartamos el borrador de la anterior
         * y permitimos que whatsapp.js cargue normalmente la nueva ficha.
         * Captura se usa para ejecutarnos antes del manejador principal.
         */
        document.addEventListener(
            'click',
            function (event) {
                const item = event.target.closest(
                    '[data-wa-conversation]'
                );

                if (!item) return;

                const channel = String(
                    item.dataset.waChannel || 'whatsapp'
                );

                const id = String(
                    item.dataset.waConversation || ''
                );

                resetForConversation(channel + ':' + id);
            },
            true
        );

        /*
         * Los filtros pueden cambiar automáticamente la conversación activa.
         * Observamos la lista para mantener el borrador ligado al chat correcto.
         */
        const list = document.getElementById('waConversationList');

        if (list) {
            const observer = new MutationObserver(function () {
                syncConversationKey();
            });

            observer.observe(list, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class']
            });
        }

        syncConversationKey();
    }

    function init() {
        applyWhatsappMenuPermissions();
        setupContactDraftProtection();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            init,
            { once: true }
        );
    } else {
        init();
    }
})();
