(function () {
    'use strict';

    function closeDropdowns(except) {
        document.querySelectorAll('[data-nav-dropdown].is-open').forEach(function (node) {
            if (node === except) return;
            node.classList.remove('is-open');
            var trigger = node.querySelector('.nav-dropdown__trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.nav-dropdown__trigger');

        if (trigger && window.matchMedia('(max-width: 640px)').matches) {
            var dropdown = trigger.closest('[data-nav-dropdown]');
            if (!dropdown) return;

            event.preventDefault();
            var willOpen = !dropdown.classList.contains('is-open');
            closeDropdowns(dropdown);
            dropdown.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            return;
        }

        if (!event.target.closest('[data-nav-dropdown]')) {
            closeDropdowns(null);
        }
    });

    /*
     * La analítica oficial ya es multicanal.
     * Si el usuario pulsa la pestaña antigua dentro del centro,
     * redirigimos a la nueva vista en vez de cargar dos analíticas distintas.
     */
    document.addEventListener('click', function (event) {
        var analyticsTab = event.target.closest('[data-wa-tab="analytics"]');
        if (!analyticsTab) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        window.location.href = 'analitica-multicanal.php';
    }, true);

    function applyRequestedWhatsAppTab() {
        if (!/\/whatsapp\.php$/i.test(window.location.pathname)) return;

        var requested = new URLSearchParams(window.location.search).get('tab');
        if (!['inbox', 'automation', 'library'].includes(requested)) return;

        var attempts = 0;
        var stable = 0;

        var timer = window.setInterval(function () {
            attempts++;

            var button = document.querySelector('[data-wa-tab="' + requested + '"]');
            if (!button) {
                if (attempts > 24) window.clearInterval(timer);
                return;
            }

            if (!button.classList.contains('is-active')) {
                button.click();
                stable = 0;
            } else {
                stable++;
            }

            /*
             * whatsapp.js termina varias cargas asíncronas al iniciar.
             * Esperamos a que el tab solicitado permanezca estable para
             * evitar que el arranque lo regrese a la primera pestaña.
             */
            if (stable >= 4 || attempts > 24) {
                window.clearInterval(timer);
            }
        }, 250);
    }



    // =====================================================
    // Bandeja · resaltado de coincidencias de búsqueda
    // =====================================================
    function clearSearchMarks(root) {
        if (!root) return;
        root.querySelectorAll('mark.sigoi-search-match').forEach(function (mark) {
            var parent = mark.parentNode;
            if (!parent) return;
            parent.replaceChild(document.createTextNode(mark.textContent || ''), mark);
            parent.normalize();
        });
    }

    function highlightTextNodes(root, query) {
        if (!root || !query) return;

        var needle = String(query).toLocaleLowerCase('es');
        if (!needle) return;

        var walker = document.createTreeWalker(
            root,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function (node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    var parent = node.parentElement;
                    if (!parent) return NodeFilter.FILTER_REJECT;
                    if (parent.closest('mark.sigoi-search-match, script, style')) return NodeFilter.FILTER_REJECT;
                    return node.nodeValue.toLocaleLowerCase('es').includes(needle)
                        ? NodeFilter.FILTER_ACCEPT
                        : NodeFilter.FILTER_REJECT;
                }
            }
        );

        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);

        nodes.forEach(function (node) {
            var original = node.nodeValue || '';
            var lower = original.toLocaleLowerCase('es');
            var cursor = 0;
            var fragment = document.createDocumentFragment();

            while (cursor < original.length) {
                var index = lower.indexOf(needle, cursor);
                if (index === -1) {
                    fragment.appendChild(document.createTextNode(original.slice(cursor)));
                    break;
                }

                if (index > cursor) {
                    fragment.appendChild(document.createTextNode(original.slice(cursor, index)));
                }

                var mark = document.createElement('mark');
                mark.className = 'sigoi-search-match';
                mark.textContent = original.slice(index, index + query.length);
                fragment.appendChild(mark);
                cursor = index + query.length;
            }

            node.parentNode.replaceChild(fragment, node);
        });
    }

    function applyInboxSearchHighlight() {
        if (!/\/whatsapp\.php$/i.test(window.location.pathname)) return;

        var input = document.getElementById('waInboxSearch');
        if (!input) return;

        var query = String(input.value || '').trim();
        var list = document.getElementById('waConversationList');
        var chat = document.getElementById('waChatMessages');

        [list, chat].forEach(function (root) {
            if (!root) return;
            clearSearchMarks(root);
            if (!query) return;

            root.querySelectorAll(
                '.wa-conversation-top strong, .wa-conversation-preview > span, .wa-message-text'
            ).forEach(function (target) {
                highlightTextNodes(target, query);
            });
        });
    }

    function setupInboxSearchHighlight() {
        if (!/\/whatsapp\.php$/i.test(window.location.pathname)) return;

        var input = document.getElementById('waInboxSearch');
        var list = document.getElementById('waConversationList');
        var chat = document.getElementById('waChatMessages');
        if (!input || !list) return;

        var scheduled = false;
        var observer = null;
        var observerOptions = { childList: true, subtree: true };

        function observeRoots() {
            if (!observer) return;
            observer.observe(list, observerOptions);
            if (chat) observer.observe(chat, observerOptions);
        }

        function schedule() {
            if (scheduled) return;
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;

                // Evita que los <mark> creados por el propio resaltado
                // vuelvan a disparar el observer en un bucle.
                if (observer) observer.disconnect();
                applyInboxSearchHighlight();
                if (observer) {
                    observer.takeRecords();
                    observeRoots();
                }
            });
        }

        input.addEventListener('input', schedule);

        observer = new MutationObserver(function () {
            schedule();
        });

        observeRoots();
        schedule();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var legacyAnalytics = document.querySelector('[data-wa-tab="analytics"]');
        if (legacyAnalytics) {
            legacyAnalytics.setAttribute('title', 'Abrir analítica multicanal');

            if (
                /\/whatsapp\.php$/i.test(window.location.pathname)
                && legacyAnalytics.classList.contains('is-active')
            ) {
                window.location.replace('analitica-multicanal.php');
                return;
            }
        }

        setupInboxSearchHighlight();
        applyRequestedWhatsAppTab();
    });
})();
