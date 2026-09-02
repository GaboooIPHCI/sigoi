(function () {
    'use strict';

    window.SIGOI_MESSENGER_RUNTIME = window.SIGOI_MESSENGER_RUNTIME || {
        active: false,
        mode: 'core'
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
                const messengerRuntime = window.SIGOI_MESSENGER_RUNTIME;

                /*
                 * Messenger 5.5:
                 * - Messenger exclusivo controla su propio polling.
                 * - En "Todos" también usamos una única carga combinada
                 *   WhatsApp + Instagram + Messenger.
                 *
                 * Si dejamos correr el polling original aquí, whatsapp.js
                 * reconstruye la lista sin Messenger cada 2 segundos y
                 * produce el efecto aparecer/desaparecer.
                 */
                if (
                    messengerRuntime?.active
                    || messengerRuntime?.mode === 'all'
                ) {
                    return;
                }

                callback(...args);
            }, delay);
        }

        return nativeSetInterval(callback, delay, ...args);
    };
})();
