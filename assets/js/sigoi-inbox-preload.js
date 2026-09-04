(function () {
    'use strict';

    /*
     * RC3: whatsapp.js sigue atendiendo Automatización / Plantillas,
     * pero la Bandeja pasa a tener un único dueño: sigoi-inbox-multichannel.js.
     *
     * Interceptamos SOLO la asignación inicial de WHATSAPP_PERMISSIONS para
     * entregar al núcleo legado una copia con bandeja_ver=false. No se toca
     * setInterval, fetch, MutationObserver ni APIs globales del navegador.
     */
    if (window.__SIGOI_INBOX_PRELOAD_INSTALLED) return;
    window.__SIGOI_INBOX_PRELOAD_INSTALLED = true;

    try {
        Object.defineProperty(window, 'WHATSAPP_PERMISSIONS', {
            configurable: true,
            enumerable: true,
            get: function () {
                return undefined;
            },
            set: function (value) {
                var original = Object.assign({}, value || {});
                var legacy = Object.assign({}, original, { bandeja_ver: false });

                window.SIGOI_INBOX_PERMISSIONS = original;

                Object.defineProperty(window, 'WHATSAPP_PERMISSIONS', {
                    configurable: true,
                    enumerable: true,
                    writable: true,
                    value: legacy
                });
            }
        });
    } catch (_) {
        window.SIGOI_INBOX_PERMISSIONS = window.WHATSAPP_PERMISSIONS || {};
    }
})();
