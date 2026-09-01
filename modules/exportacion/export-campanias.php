<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

/*
|--------------------------------------------------------------------------
| Protección de exportación de campañas
|--------------------------------------------------------------------------
| Solo usuarios autorizados pueden descargar información de campañas.
|--------------------------------------------------------------------------
*/
auth_require_module_modify('exportar_excel');
auth_require_module_view('campanias');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function crearHoja($spreadsheet, $titulo, $headers, $records)
{
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($titulo);

    $col = 'A';

    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $col++;
    }

    $row = 2;

    foreach ($records as $record) {
        $col = 'A';
        foreach (array_keys($headers) as $key) {
            $sheet->setCellValue($col . $row, $record[$key] ?? '');
            $col++;
        }
        $row++;
    }

    foreach (range('A', chr(64 + count($headers))) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
}

try {

    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);

    // =========================
    // FILTRO (por si luego lo usas)
    // =========================
    $search = $_GET['search'] ?? '';
    $estado = $_GET['estado'] ?? '';
    $mes = $_GET['mes'] ?? '';

    // =========================
    // HOJA: CAMPAÑAS
    // =========================
    $sqlCampanias = "SELECT 
                        c.id,
                        c.nombre,
                        c.descripcion,
                        c.fecha_inicio,
                        c.estado,
                        COUNT(r.id) AS total_registros,
                        COALESCE(SUM(r.monto), 0) AS monto_total
                    FROM campanias c
                    LEFT JOIN campania_registros r ON r.campania_id = c.id
                    WHERE 1=1";

    $params = [];

    if (!empty($search)) {
        $sqlCampanias .= " AND c.nombre LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    if (!empty($estado)) {
        $sqlCampanias .= " AND c.estado = :estado";
        $params[':estado'] = $estado;
    }

    if (!empty($mes)) {
        $sqlCampanias .= " AND DATE_FORMAT(c.fecha_inicio, '%Y-%m') = :mes";
        $params[':mes'] = $mes;
    }

    $sqlCampanias .= " GROUP BY c.id ORDER BY c.id DESC";

    $stmt = $pdo->prepare($sqlCampanias);
    $stmt->execute($params);
    $campanias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($campanias)) {
        header("Location: ../../campanias.php?error_export=1");
        exit;
    }

    crearHoja($spreadsheet, 'Campañas', [
        'id' => 'ID',
        'nombre' => 'Nombre',
        'descripcion' => 'Descripción',
        'fecha_inicio' => 'Fecha inicio',
        'estado' => 'Estado',
        'total_registros' => 'Total registros',
        'monto_total' => 'Monto total'
    ], $campanias);

    // =========================
    // HOJA: REGISTROS CAMPAÑAS
    // =========================
    $sqlRegistros = "SELECT 
                        r.id,
                        c.nombre AS campania,
                        r.nombre_apellido,
                        r.numero_telefono,
                        r.dni,
                        r.confirmo_cita,
                        r.asistio,
                        r.monto,
                        r.que_se_realizo
                    FROM campania_registros r
                    LEFT JOIN campanias c ON r.campania_id = c.id
                    WHERE 1=1";

    if (!empty($search)) {
        $sqlRegistros .= " AND c.nombre LIKE :search";
    }

    if (!empty($estado)) {
        $sqlRegistros .= " AND c.estado = :estado";
    }

    if (!empty($mes)) {
        $sqlRegistros .= " AND DATE_FORMAT(c.fecha_inicio, '%Y-%m') = :mes";
    }

    $sqlRegistros .= " ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sqlRegistros);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    crearHoja($spreadsheet, 'Registros Campañas', [
        'id' => 'ID',
        'campania' => 'Campaña',
        'nombre_apellido' => 'Nombre',
        'numero_telefono' => 'Teléfono',
        'dni' => 'DNI',
        'confirmo_cita' => 'Confirmó',
        'asistio' => 'Asistió',
        'monto' => 'Monto',
        'que_se_realizo' => 'Servicio'
    ], $registros);

    // =========================
    // DESCARGA
    // =========================
    $spreadsheet->setActiveSheetIndex(0);

    $nombreFiltro = 'vista';

    if (!empty($mes)) {
        $nombreFiltro = '_' . $mes;
    }

    if (!empty($estado)) {
        $nombreFiltro .= '_' . strtolower($estado);
    }

    if (!empty($search)) {
        $nombreFiltro .= '_';
    }

    $type = $_GET['type'] ?? 'all';

    if ($type === 'month' && !empty($_GET['mes'])) {
        $filename = 'campanias_mes_' . $_GET['mes'] . '.xlsx';
    } else {
        $filename = 'campanias_todo_' . date('Y-m-d') . '.xlsx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo generar la exportación de campañas. Inténtalo nuevamente.');
}