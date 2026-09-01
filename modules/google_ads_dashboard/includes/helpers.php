<?php

function adsObtenerRangoMes(string $mes): array
{
    $inicio = $mes . '-01 00:00:00';
    $fechaInicio = new DateTime($inicio);
    $fechaFin = clone $fechaInicio;
    $fechaFin->modify('+1 month');

    return [$inicio, $fechaFin->format('Y-m-d H:i:s')];
}

function adsNombreCampanaVisible(?string $nombre): string
{
    $nombre = trim((string) $nombre);

    if ($nombre === '' || strpos($nombre, 'Campaña ') === 0) {
        return 'Campaña sin nombre';
    }

    $nombre = str_replace('_', ' ', $nombre);
    return mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8');
}

function adsMesVisible(string $mes): string
{
    $meses = [
        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
        '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
        '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
        '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
    ];

    [$anio, $numeroMes] = array_pad(explode('-', $mes, 2), 2, '');
    return ($meses[$numeroMes] ?? $numeroMes) . ' ' . $anio;
}

function adsTasaContacto(int $visitas, int $contactos): float
{
    return $visitas > 0 ? ($contactos / $visitas) * 100 : 0.0;
}

function adsCalcularTotales(array $campanas): array
{
    $visitas = 0;
    $contactos = 0;
    $clics = 0;

    foreach ($campanas as $campana) {
        $visitas += (int) $campana['visitas'];
        $contactos += (int) $campana['sesiones_whatsapp'];
        $clics += (int) $campana['clics_whatsapp'];
    }

    return [
        'campanas' => count($campanas),
        'visitas' => $visitas,
        'whatsapp' => $contactos,
        'clics_whatsapp' => $clics,
        'tasa' => adsTasaContacto($visitas, $contactos),
    ];
}

function adsDispositivoVisible(string $valor): string
{
    $mapa = [
        'm' => 'Móvil',
        'c' => 'Computadora',
        't' => 'Tablet',
    ];

    return $mapa[$valor] ?? ($valor !== '' ? $valor : 'Sin dato');
}

function adsLandingVisible(string $valor): string
{
    $ruta = parse_url($valor, PHP_URL_PATH);

    if (!$ruta || $ruta === '/') {
        return 'Inicio';
    }

    $ruta = '/' . ltrim($ruta, '/');
    return $ruta;
}

function adsPorcentajeBarra(int $valor, int $maximo): float
{
    if ($maximo <= 0) {
        return 0;
    }

    return min(100, max(0, ($valor / $maximo) * 100));
}
