<?php

/*
 * EJEMPLO DE CONFIGURACIÓN PRIVADA PARA MESSENGER.
 *
 * 1) Copia este archivo como:
 *      config/messenger_meta.php
 * 2) Completa los valores reales.
 * 3) messenger_meta.php NO debe subirse a GitHub.
 */

define('MESSENGER_META_PAGE_ID', '');
define('MESSENGER_META_PAGE_ACCESS_TOKEN', '');
define('MESSENGER_META_APP_SECRET', '');
define('MESSENGER_META_VERIFY_TOKEN', 'cambia-este-token-largo-y-aleatorio');

/*
 * Usa la versión de Graph API que esté configurada/aprobada
 * actualmente en tu aplicación de Meta.
 */
define('MESSENGER_META_API_VERSION', 'vXX.X');

/*
 * URL pública de S.I.G.O.I. sin slash final.
 */
define('MESSENGER_PUBLIC_BASE_URL', 'https://sigoi.sophie.com.pe');

/*
 * Déjalo en false mientras verificamos recepción.
 * Después de validar el token y el webhook puedes cambiarlo a true.
 */
define('MESSENGER_META_SEND_ENABLED', false);
