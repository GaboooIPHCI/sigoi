<?php

/*
|--------------------------------------------------------------------------
| IPHCI - Captura de visitas desde Google Ads
|--------------------------------------------------------------------------
| Recibe parámetros enviados por Google Ads / ValueTrack
| y los guarda en google_ads_visits.
|--------------------------------------------------------------------------
*/

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);

session_name('PHPSESSID');
session_start();

require_once __DIR__ . '/../../config/db.php';


/*
|--------------------------------------------------------------------------
| FUNCIÓN PARA LIMPIAR PARÁMETROS
|--------------------------------------------------------------------------
*/

function cleanParam(string $key, int $maxLength = 255): ?string
{
    if (!isset($_GET[$key])) {
        return null;
    }

    $value = trim((string) $_GET[$key]);

    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $maxLength);
}


/*
|--------------------------------------------------------------------------
| CREAR IDENTIFICADOR DE SESIÓN
|--------------------------------------------------------------------------
| Nos servirá posteriormente para relacionar:
|
| Visita Google Ads
|       ↓
| navegación
|       ↓
| WhatsApp / llamada / formulario / etc.
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['google_ads_session_id'])) {
    $_SESSION['google_ads_session_id'] = bin2hex(random_bytes(16));
}

$sessionId = $_SESSION['google_ads_session_id'];
setcookie(
    'iphci_google_ads_session',
    $sessionId,
    [
        'expires'  => time() + (60 * 60 * 24),
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]
);


/*
|--------------------------------------------------------------------------
| RECIBIR DATOS
|--------------------------------------------------------------------------
*/

$gclid       = cleanParam('gclid', 255);

$campaignId  = cleanParam('campaign_id', 50);
$campaignName = cleanParam('campaign_name', 255);
$adgroupId   = cleanParam('adgroup_id', 50);
$creativeId  = cleanParam('creative_id', 50);

$keyword     = cleanParam('keyword', 255);
$matchType   = cleanParam('matchtype', 20);
$device      = cleanParam('device', 30);
$network     = cleanParam('network', 30);

$utmSource   = cleanParam('utm_source', 100);
$utmMedium   = cleanParam('utm_medium', 100);
$utmCampaign = cleanParam('utm_campaign', 255);
$utmContent  = cleanParam('utm_content', 255);
$utmTerm     = cleanParam('utm_term', 255);
/*
|--------------------------------------------------------------------------
| GUARDAR ATRIBUCIÓN DE GOOGLE ADS EN LA SESIÓN
|--------------------------------------------------------------------------
*/

if ($gclid !== null || $campaignId !== null) {

    $_SESSION['google_ads_attribution'] = [
        'gclid'        => $gclid,
        'campaign_id'  => $campaignId,
        'adgroup_id'   => $adgroupId,
        'creative_id'  => $creativeId,
        'keyword'      => $keyword,
        'match_type'   => $matchType,
        'device'       => $device,
        'network'      => $network,
        'utm_source'   => $utmSource,
        'utm_medium'   => $utmMedium
    ];

}


/*
|--------------------------------------------------------------------------
| LANDING Y REFERENCIA
|--------------------------------------------------------------------------
*/

$landingPage = cleanParam('landing_page', 500);

$referrer = isset($_SERVER['HTTP_REFERER'])
    ? mb_substr((string) $_SERVER['HTTP_REFERER'], 0, 500)
    : null;


/*
|--------------------------------------------------------------------------
| GUARDAR EN BASE DE DATOS
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        INSERT INTO google_ads_visits (
            session_id,
            gclid,
            campaign_id,
            campaign_name,
            adgroup_id,
            creative_id,
            keyword,
            match_type,
            device,
            network,
            utm_source,
            utm_medium,
            utm_campaign,
            utm_content,
            utm_term,
            landing_page,
            referrer
        ) VALUES (
            :session_id,
            :gclid,
            :campaign_id,
            :campaign_name,
            :adgroup_id,
            :creative_id,
            :keyword,
            :match_type,
            :device,
            :network,
            :utm_source,
            :utm_medium,
            :utm_campaign,
            :utm_content,
            :utm_term,
            :landing_page,
            :referrer
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':session_id'   => $sessionId,
        ':gclid'        => $gclid,
        ':campaign_id'  => $campaignId,
        ':campaign_name' => $campaignName,
        ':adgroup_id'   => $adgroupId,
        ':creative_id'  => $creativeId,
        ':keyword'      => $keyword,
        ':match_type'   => $matchType,
        ':device'       => $device,
        ':network'      => $network,
        ':utm_source'   => $utmSource,
        ':utm_medium'   => $utmMedium,
        ':utm_campaign' => $utmCampaign,
        ':utm_content'  => $utmContent,
        ':utm_term'     => $utmTerm,
        ':landing_page' => $landingPage,
        ':referrer'     => $referrer
    ]);

} catch (PDOException $e) {

    // 23000 = violación de índice UNIQUE
    // Significa que ese GCLID ya fue registrado.
    if ($e->getCode() === '23000') {

        header('Content-Type: text/plain; charset=utf-8');

        echo "OK\n";
        echo "Visita ya registrada anteriormente.";

        exit;
    }

    http_response_code(500);
    exit('ERROR_DB');

} catch (Throwable $e) {

    http_response_code(500);
    exit('ERROR_DB');

}


/*
|--------------------------------------------------------------------------
| RESPUESTA DE PRUEBA
|--------------------------------------------------------------------------
| Esto es temporal.
| Después no mostraremos esta pantalla al visitante.
|--------------------------------------------------------------------------
*/

header('Content-Type: text/plain; charset=utf-8');

echo "OK\n";
echo "Visita Google Ads registrada correctamente.\n";
echo "Session ID: " . $sessionId;
