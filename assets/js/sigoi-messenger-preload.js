(function () {
    'use strict';

    window.SIGOI_MESSENGER_RUNTIME = window.SIGOI_MESSENGER_RUNTIME || {
        active: false,
        mode: 'all'
    };

    window.SIGOI_NATIVE_SET_INTERVAL = window.SIGOI_NATIVE_SET_INTERVAL
        || window.setInterval.bind(window);

    const nativeSetInterval = window.SIGOI_NATIVE_SET_INTERVAL;

    /*
     * whatsapp.js registra su polling de bandeja cada 2 segundos.
     * Cuando el usuario está dentro de Messenger, evitamos que ese
     * polling reemplace el DOM con WhatsApp/Instagram.
     */
    window.setInterval = function (callback, delay, ...args) {
        if (
            Number(delay) === 2000
            && typeof callback === 'function'
        ) {
            return nativeSetInterval(function () {
                if (window.SIGOI_MESSENGER_RUNTIME?.active) {
                    return;
                }

                callback(...args);
            }, delay);
        }

        return nativeSetInterval(callback, delay, ...args);
    };
})();
