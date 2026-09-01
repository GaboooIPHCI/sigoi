<?php

/*
|--------------------------------------------------------------------------
| IPHCI - Tracking de clics web
|--------------------------------------------------------------------------
| Registra clics reales y redirige al destino correspondiente.
| Mantiene noindex para que estas URLs técnicas no aparezcan en Google.
|--------------------------------------------------------------------------
*/

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
header('Pragma: no-cache', true);
header('Expires: 0', true);

session_name('PHPSESSID');
session_start();

require_once __DIR__ . '/../../config/db.php';

function iphciredirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

$link = isset($_GET['link']) ? strtolower(trim((string) $_GET['link'])) : '';

if ($link === '' || !preg_match('/^[a-z0-9_]{2,80}$/', $link)) {
    iphciredirect('/');
}

$linksPermitidos = [

    /*
    |--------------------------------------------------------------------------
    | WHATSAPP GENERALES
    |--------------------------------------------------------------------------
    */

    'whatsapp_home' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, vengo de la web de IPHCI y quiero información.')
    ],

    'whatsapp_flotante' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, vengo de la web de IPHCI y quisiera información sobre sus servicios.')
    ],

    /*
    |--------------------------------------------------------------------------
    | REDES SOCIALES
    |--------------------------------------------------------------------------
    */

    'facebook_flotante' => [
        'url' => 'https://www.facebook.com/iphci'
    ],

    'instagram_flotante' => [
        'url' => 'https://www.instagram.com/iphcisalud/'
    ],

    'youtube_flotante' => [
        'url' => 'https://www.youtube.com/@iphcisalud107'
    ],

    'linkedin_flotante' => [
        'url' => 'https://www.linkedin.com/company/iphci-salud/'
    ],

    /*
    |--------------------------------------------------------------------------
    | TIPOS DE ATENCIÓN
    |--------------------------------------------------------------------------
    */

    'atencion_presencial' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una atención médica presencial.')
    ],

    'atencion_domicilio' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre atención médica a domicilio.')
    ],

    'atencion_virtual' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre atención médica virtual.')
    ],

    /*
    |--------------------------------------------------------------------------
    | ESPECIALIDADES
    |--------------------------------------------------------------------------
    */

    'cardiologia_intervencionista' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Cardiología Intervencionista.')
    ],

    'cardiologia_cardiovascular' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Cardiología Cardiovascular.')
    ],

    'cardiologia_clinica' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Cardiología Clínica.')
    ],

    'cardiologia_pediatrica' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Cardiología Pediátrica.')
    ],

    'medicina_familiar' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Medicina Familiar.')
    ],

    'medicina_general' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Medicina General.')
    ],

    'medicina_interna' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Medicina Interna.')
    ],

    'pediatria' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Pediatría.')
    ],

    'pediatria_colorrectal' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Pediatría Colorrectal.')
    ],

    'endocrinologia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Endocrinología.')
    ],

    'neurologia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Neurología.')
    ],

    'gastroenterologia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Gastroenterología.')
    ],

    'oftalmologia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Oftalmología.')
    ],

    'urologia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera agendar una cita para Urología.')
    ],

    /*
    |--------------------------------------------------------------------------
    | PROCEDIMIENTOS
    |--------------------------------------------------------------------------
    */

    'angioplastia' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Angioplastía / Stent.')
    ],

    'cateterismo' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Cateterismo.')
    ],

    'cierre_foramen' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Cierre de Foramen / CIA.')
    ],

    'cierre_orejuela' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Cierre de Orejuela.')
    ],

    'denervacion_renal' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Denervación Renal.')
    ],

    'enfermedad_carotidea' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Enfermedad Carotídea.')
    ],

    'marcapasos' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Marcapasos.')
    ],

    'mitraclip' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre MitraClip.')
    ],

    'tavi' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre TAVI.')
    ],

    /*
    |--------------------------------------------------------------------------
    | SERVICIOS
    |--------------------------------------------------------------------------
    */

    'segunda_opinion' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Segunda Opinión.')
    ],

    'laboratorio' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Laboratorio.')
    ],

    'ecografias' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Ecografías.')
    ],

    'arco_c' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Alquiler del Arco en C.')
    ],

    'vivid_3' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre Compra del Ecógrafo Vivid 3.')
    ],


    'alquiler_equipos' => [
        'url' => 'https://wa.me/51997907441?text=' . urlencode('Hola, quisiera información sobre alquiler de equipos médicos.')
    ],

];

if (!isset($linksPermitidos[$link])) {
    iphciredirect('/');
}

/*
|--------------------------------------------------------------------------
| FILTROS DE MEDICIÓN
|--------------------------------------------------------------------------
| - No registra prefetch, prerender ni previews de redes/bots.
| - Evita doble clic del mismo botón en pocos segundos.
| - No bloquea clics legítimos a botones distintos.
|--------------------------------------------------------------------------
*/

$ahora = time();
$debeRegistrar = true;

$purpose = strtolower($_SERVER['HTTP_PURPOSE'] ?? '');
$secPurpose = strtolower($_SERVER['HTTP_SEC_PURPOSE'] ?? '');
$secFetchMode = strtolower($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

if (
    strpos($purpose, 'prefetch') !== false ||
    strpos($secPurpose, 'prefetch') !== false ||
    strpos($secPurpose, 'prerender') !== false ||
    $secFetchMode === 'prefetch'
) {
    $debeRegistrar = false;
}

$botsYPreviews = [
    'bot',
    'crawl',
    'spider',
    'slurp',
    'facebookexternalhit',
    'facebot',
    'whatsapp',
    'telegrambot',
    'linkedinbot',
    'twitterbot',
    'discordbot'
];

foreach ($botsYPreviews as $patronBot) {
    if ($userAgent !== '' && strpos($userAgent, $patronBot) !== false) {
        $debeRegistrar = false;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| PROTECCIÓN ANTI DOBLE CLIC
|--------------------------------------------------------------------------
| Antes había un bloqueo global que podía impedir contar dos botones distintos
| si el paciente hacía clic muy rápido. Ahora se bloquea solo el mismo link.
|--------------------------------------------------------------------------
*/

$limiteMismoLinkSegundos = 8;
$sessionKeyLink = 'last_web_click_' . $link;
$cookieKeyLink = 'iphci_wc_' . sha1($link);

if ($debeRegistrar && isset($_SESSION[$sessionKeyLink])) {
    $ultimoClickLink = (int) $_SESSION[$sessionKeyLink];

    if (($ahora - $ultimoClickLink) < $limiteMismoLinkSegundos) {
        $debeRegistrar = false;
    }
}

if ($debeRegistrar && isset($_COOKIE[$cookieKeyLink])) {
    $ultimoClickCookie = (int) $_COOKIE[$cookieKeyLink];

    if (($ahora - $ultimoClickCookie) < $limiteMismoLinkSegundos) {
        $debeRegistrar = false;
    }
}

if ($debeRegistrar) {
    try {

        $googleAdsSessionId = null;

        if (!empty($_COOKIE['iphci_google_ads_session'])) {
            $googleAdsSessionId = $_COOKIE['iphci_google_ads_session'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO web_clicks (
                link_key,
                google_ads_session_id
            ) VALUES (?, ?)
        ");

        $stmt->execute([
            $link,
            $googleAdsSessionId
        ]);

        $_SESSION[$sessionKeyLink] = $ahora;

        setcookie($cookieKeyLink, (string) $ahora, [
            'expires' => $ahora + 60,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } catch (Exception $e) {
        // Si falla el registro, igual redirige para no perder al paciente.
    }
}

iphciredirect($linksPermitidos[$link]['url']);
