<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

/*
|--------------------------------------------------------------------------
| Protección de exportación de atenciones
|--------------------------------------------------------------------------
| Solo usuarios autorizados pueden descargar información de pacientes.
|--------------------------------------------------------------------------
*/
auth_require_module_modify('exportar_excel');
auth_require_module_view('pacientes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    $search = $_GET['search'] ?? '';
    $canal = $_GET['canal'] ?? '';
    $tipo = $_GET['tipo'] ?? '';
    $servicio = $_GET['servicio'] ?? '';
    $status = $_GET['status'] ?? '';
    $fechaDesde = $_GET['fecha_desde'] ?? '';
    $fechaHasta = $_GET['fecha_hasta'] ?? '';

    $sql = "SELECT 
                id,
                fecha_contacto,
                paciente,
                telefono,
                dni,
                canal,
                tipo_atencion,
                servicio,
                detalle_consulta,
                status_cita,
                fecha_cita,
                modulo
            FROM atenciones
            WHERE 1=1";

    $params = [];

    if (!empty($search)) {
        // Con PDO::ATTR_EMULATE_PREPARES desactivado no se debe reutilizar
        // el mismo marcador con nombre varias veces en una consulta MySQL.
        $sql .= " AND (
            paciente LIKE :searchPaciente OR 
            telefono LIKE :searchTelefono OR
            dni LIKE :searchDni OR
            detalle_consulta LIKE :searchDetalle
        )";

        $searchTerm = '%' . $search . '%';
        $params[':searchPaciente'] = $searchTerm;
        $params[':searchTelefono'] = $searchTerm;
        $params[':searchDni'] = $searchTerm;
        $params[':searchDetalle'] = $searchTerm;
    }

    if (!empty($canal)) {
        $sql .= " AND canal = :canal";
        $params[':canal'] = $canal;
    }

    if (!empty($tipo)) {
        $sql .= " AND tipo_atencion = :tipo";
        $params[':tipo'] = $tipo;
    }

    if (!empty($servicio)) {
        $sql .= " AND servicio = :servicio";
        $params[':servicio'] = $servicio;
    }

    if (!empty($status)) {
        $sql .= " AND status_cita = :status";
        $params[':status'] = $status;
    }

    if (!empty($fechaDesde)) {
        $sql .= " AND fecha_contacto >= :fechaDesde";
        $params[':fechaDesde'] = $fechaDesde;
    }

    if (!empty($fechaHasta)) {
        $sql .= " AND fecha_contacto <= :fechaHasta";
        $params[':fechaHasta'] = $fechaHasta;
    }

    $sql .= " ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        header("Location: ../../index.php?error_export=1");
        exit;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Atenciones');

    $headers = [
        'ID',
        'Fecha contacto',
        'Paciente',
        'Teléfono',
        'DNI',
        'Canal',
        'Tipo atención',
        'Servicio',
        'Detalle consulta',
        'Status cita',
        'Fecha cita',
        'Módulo'
    ];

    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $col++;
    }

    $row = 2;

    foreach ($records as $record) {
        $sheet->setCellValue('A' . $row, $record['id']);
        $sheet->setCellValue('B' . $row, $record['fecha_contacto']);
        $sheet->setCellValue('C' . $row, $record['paciente']);
        $sheet->setCellValue('D' . $row, $record['telefono']);
        $sheet->setCellValue('E' . $row, $record['dni']);
        $sheet->setCellValue('F' . $row, $record['canal']);
        $sheet->setCellValue('G' . $row, $record['tipo_atencion']);
        $sheet->setCellValue('H' . $row, $record['servicio']);
        $sheet->setCellValue('I' . $row, $record['detalle_consulta']);
        $sheet->setCellValue('J' . $row, $record['status_cita']);
        $sheet->setCellValue('K' . $row, $record['fecha_cita']);
        $sheet->setCellValue('L' . $row, $record['modulo']);
        $row++;
    }

    foreach (range('A', 'L') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
    
    $nombreFiltro = 'vista';

    if (!empty($fechaDesde) && !empty($fechaHasta)) {
        $nombreFiltro = 'del_' . $fechaDesde . '_al_' . $fechaHasta;
    } elseif (!empty($fechaDesde)) {
        $nombreFiltro = 'desde_' . $fechaDesde;
    } elseif (!empty($fechaHasta)) {
        $nombreFiltro = 'hasta_' . $fechaHasta;
    }

    if (!empty($canal)) {
        $nombreFiltro .= '_' . strtolower(str_replace(' ', '_', $canal));
    }

    if (!empty($tipo)) {
        $nombreFiltro .= '_' . strtolower(str_replace(' ', '_', $tipo));
    }

    if (!empty($servicio)) {
        $nombreFiltro .= '_' . strtolower(str_replace(' ', '_', $servicio));
    }

    if (!empty($status)) {
        $nombreFiltro .= '_' . strtolower(str_replace(' ', '_', $status));
    }

    $tieneFiltros = !empty($search)
        || !empty($canal)
        || !empty($tipo)
        || !empty($servicio)
        || !empty($status)
        || !empty($fechaDesde)
        || !empty($fechaHasta);

    $filename = $tieneFiltros
        ? 'atenciones_filtradas_' . date('Y-m-d') . '.xlsx'
        : 'atenciones_todo_' . date('Y-m-d') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    error_log('Error al exportar atenciones: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo generar la exportación de atenciones. Inténtalo nuevamente.');
}