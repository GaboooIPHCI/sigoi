<?php

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('google_ads');

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/queries.php';

$mes = $_GET['mes'] ?? date('Y-m');
$campanaFiltro = trim((string) ($_GET['campana'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

[$inicio, $fin] = adsObtenerRangoMes($mes);

$campanasFiltro = adsObtenerCampanasFiltro($pdo, $inicio, $fin);
$campanas = adsObtenerResumenCampanas($pdo, $inicio, $fin, $campanaFiltro);
$totales = adsCalcularTotales($campanas);
$mesVisible = adsMesVisible($mes);

require __DIR__ . '/views/dashboard.php';
