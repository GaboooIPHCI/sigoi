<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('metricas_web');

function isValidMonth($month) {
    return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month);
}

function monthRange($month) {
    $start = $month . '-01 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    return [$start, $end];
}

function getCategoryOrder() {
    return [
        'General',
        'Red Social',
        'Especialidad',
        'Procedimiento',
        'Servicio',
        'Atención',
        'Otros'
    ];
}

function orderCategories($categories) {
    $ordered = [];
    $categoryOrder = getCategoryOrder();

    foreach ($categoryOrder as $category) {
        if (isset($categories[$category])) {
            $ordered[$category] = $categories[$category];
        }
    }

    foreach ($categories as $category => $total) {
        if (!isset($ordered[$category])) {
            $ordered[$category] = $total;
        }
    }

    return $ordered;
}

function getLinkCatalog() {
    return [
        'whatsapp_home' => ['nombre' => 'WhatsApp CTA principal', 'categoria' => 'General'],
        'whatsapp_flotante' => ['nombre' => 'WhatsApp flotante', 'categoria' => 'General'],

        'facebook_flotante' => ['nombre' => 'Facebook flotante', 'categoria' => 'Red Social'],
        'instagram_flotante' => ['nombre' => 'Instagram flotante', 'categoria' => 'Red Social'],
        'youtube_flotante' => ['nombre' => 'YouTube flotante', 'categoria' => 'Red Social'],
        'linkedin_flotante' => ['nombre' => 'LinkedIn flotante', 'categoria' => 'Red Social'],

        'atencion_presencial' => ['nombre' => 'Atención Presencial', 'categoria' => 'Atención'],
        'atencion_domicilio' => ['nombre' => 'Atención a Domicilio', 'categoria' => 'Atención'],
        'atencion_virtual' => ['nombre' => 'Atención Virtual', 'categoria' => 'Atención'],

        'cardiologia_intervencionista' => ['nombre' => 'Cardiología Intervencionista', 'categoria' => 'Especialidad'],
        'cardiologia_cardiovascular' => ['nombre' => 'Cardiología Cardiovascular', 'categoria' => 'Especialidad'],
        'cardiologia_clinica' => ['nombre' => 'Cardiología Clínica', 'categoria' => 'Especialidad'],
        'cardiologia_pediatrica' => ['nombre' => 'Cardiología Pediátrica', 'categoria' => 'Especialidad'],
        'medicina_familiar' => ['nombre' => 'Medicina Familiar', 'categoria' => 'Especialidad'],
        'medicina_general' => ['nombre' => 'Medicina General', 'categoria' => 'Especialidad'],
        'medicina_interna' => ['nombre' => 'Medicina Interna', 'categoria' => 'Especialidad'],
        'pediatria' => ['nombre' => 'Pediatría', 'categoria' => 'Especialidad'],
        'pediatria_colorrectal' => ['nombre' => 'Pediatría Colorrectal', 'categoria' => 'Especialidad'],
        'endocrinologia' => ['nombre' => 'Endocrinología', 'categoria' => 'Especialidad'],
        'neurologia' => ['nombre' => 'Neurología', 'categoria' => 'Especialidad'],
        'gastroenterologia' => ['nombre' => 'Gastroenterología', 'categoria' => 'Especialidad'],
        'oftalmologia' => ['nombre' => 'Oftalmología', 'categoria' => 'Especialidad'],
        'urologia' => ['nombre' => 'Urología', 'categoria' => 'Especialidad'],

        'angioplastia' => ['nombre' => 'Angioplastia coronaria / Stent', 'categoria' => 'Procedimiento'],
        'cateterismo' => ['nombre' => 'Cateterismo', 'categoria' => 'Procedimiento'],
        'cierre_foramen' => ['nombre' => 'Cierre de Foramen / CIA', 'categoria' => 'Procedimiento'],
        'cierre_orejuela' => ['nombre' => 'Cierre de Orejuela', 'categoria' => 'Procedimiento'],
        'denervacion_renal' => ['nombre' => 'Denervación Renal', 'categoria' => 'Procedimiento'],
        'enfermedad_carotidea' => ['nombre' => 'Enfermedad Carotídea', 'categoria' => 'Procedimiento'],
        'marcapasos' => ['nombre' => 'Marcapasos', 'categoria' => 'Procedimiento'],
        'mitraclip' => ['nombre' => 'MitraClip', 'categoria' => 'Procedimiento'],
        'tavi' => ['nombre' => 'TAVI', 'categoria' => 'Procedimiento'],

        'segunda_opinion' => ['nombre' => 'Segunda Opinión', 'categoria' => 'Servicio'],
        'laboratorio' => ['nombre' => 'Laboratorio', 'categoria' => 'Servicio'],
        'ecografias' => ['nombre' => 'Ecografías', 'categoria' => 'Servicio'],
        'arco_c' => ['nombre' => 'Alquiler de Arco en C y Angiógrafo', 'categoria' => 'Servicio'],
        'vivid_3' => ['nombre' => 'Ecógrafo GE Vivid 3', 'categoria' => 'Servicio'],
        'alquiler_equipos' => ['nombre' => 'Alquiler de equipos médicos', 'categoria' => 'Servicio'],
    ];
}

function getClicks(PDO $pdo, $month) {
    $catalog = getLinkCatalog();
    [$start, $end] = monthRange($month);

    $stmt = $pdo->prepare("
        SELECT link_key, COUNT(*) AS total
        FROM web_clicks
        WHERE fecha_click >= :start AND fecha_click < :end
        GROUP BY link_key
        ORDER BY total DESC
    ");

    $stmt->execute([
        ':start' => $start,
        ':end' => $end
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tabla = [];
    $porCategoria = [];
    $sinCatalogo = [];
    $totalGlobal = 0;

    foreach ($rows as $row) {
        $key = (string) $row['link_key'];
        $total = (int) $row['total'];

        $existeEnCatalogo = isset($catalog[$key]);
        $nombre = $existeEnCatalogo ? $catalog[$key]['nombre'] : $key;
        $cat = $existeEnCatalogo ? $catalog[$key]['categoria'] : 'Otros';

        if (!$existeEnCatalogo) {
            $sinCatalogo[] = [
                'link_key' => $key,
                'total' => $total
            ];
        }

        $tabla[] = [
            'link_key' => $key,
            'nombre' => $nombre,
            'categoria' => $cat,
            'total' => $total
        ];

        if (!isset($porCategoria[$cat])) {
            $porCategoria[$cat] = 0;
        }

        $porCategoria[$cat] += $total;
        $totalGlobal += $total;
    }

    return [
        'total' => $totalGlobal,
        'por_categoria' => orderCategories($porCategoria),
        'tabla' => $tabla,
        'top5' => array_slice($tabla, 0, 5),
        'sin_catalogo' => $sinCatalogo
    ];
}

function getEvolucion(PDO $pdo, $month) {
    $meses = [];
    $inicio = date('Y-m-01', strtotime($month . '-01 -5 months'));

    for ($i = 0; $i < 6; $i++) {
        $mes = date('Y-m', strtotime($inicio . " +{$i} months"));
        $meses[$mes] = 0;
    }

    $start = array_key_first($meses) . '-01 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($month . '-01 +1 month'));

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(fecha_click, '%Y-%m') AS mes, COUNT(*) AS total
        FROM web_clicks
        WHERE fecha_click >= :start AND fecha_click < :end
        GROUP BY DATE_FORMAT(fecha_click, '%Y-%m')
        ORDER BY mes ASC
    ");

    $stmt->execute([
        ':start' => $start,
        ':end' => $end
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($meses[$row['mes']])) {
            $meses[$row['mes']] = (int) $row['total'];
        }
    }

    $data = [];

    foreach ($meses as $mes => $total) {
        $data[] = [
            'mes' => $mes,
            'total' => $total
        ];
    }

    return $data;
}

try {
    $month = $_GET['mes'] ?? date('Y-m');

    if (!isValidMonth($month)) {
        $month = date('Y-m');
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'mes' => $month,
            'principal' => getClicks($pdo, $month),
            'evolucion' => getEvolucion($pdo, $month)
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error al cargar métricas web'
    ]);
}