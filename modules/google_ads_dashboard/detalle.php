<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('google_ads');

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/detail_queries.php';

$campanaId = trim((string) ($_GET['campana'] ?? ''));
$mes = $_GET['mes'] ?? date('Y-m');

if ($campanaId === '') {
    header('Location: google-ads.php');
    exit;
}

if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

[$inicio, $fin] = adsObtenerRangoMes($mes);
$detalle = adsObtenerDetalleCampana($pdo, $campanaId, $inicio, $fin);

if (!$detalle['campana']) {
    http_response_code(404);
}

$mesVisible = adsMesVisible($mes);

require __DIR__ . '/views/detalle.php';
