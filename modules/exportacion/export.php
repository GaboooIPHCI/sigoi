<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;


/* =========================================================
   SEGURIDAD GENERAL
========================================================= */

auth_require_module_modify('exportar_excel');

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


/* =========================================================
   FUNCIONES
========================================================= */

function mesValido(?string $mes): bool
{
    return $mes === null
        || $mes === ''
        || preg_match('/^\d{4}-\d{2}$/', $mes) === 1;
}


function rangoMes(string $mes): array
{
    $inicio = $mes . '-01';

    $fin = date(
        'Y-m-d',
        strtotime($inicio . ' +1 month')
    );

    return [$inicio, $fin];
}


function crearHoja(
    Spreadsheet $spreadsheet,
    string $titulo,
    array $headers,
    array $records
): void {

    $sheet = $spreadsheet->createSheet();

    $sheet->setTitle(
        mb_substr($titulo, 0, 31)
    );


    $columnIndex = 1;

    foreach ($headers as $header) {

        $column =
            Coordinate::stringFromColumnIndex(
                $columnIndex
            );

        $sheet->setCellValue(
            $column . '1',
            $header
        );

        $sheet
            ->getStyle($column . '1')
            ->getFont()
            ->setBold(true);

        $columnIndex++;
    }


    $row = 2;

    foreach ($records as $record) {

        $columnIndex = 1;

        foreach (
            array_keys($headers)
            as $key
        ) {

            $column =
                Coordinate::stringFromColumnIndex(
                    $columnIndex
                );

            $sheet->setCellValue(
                $column . $row,
                $record[$key] ?? ''
            );

            $columnIndex++;
        }

        $row++;
    }


    for (
        $i = 1;
        $i <= count($headers);
        $i++
    ) {

        $column =
            Coordinate::stringFromColumnIndex(
                $i
            );

        $sheet
            ->getColumnDimension($column)
            ->setAutoSize(true);
    }


    $sheet->freezePane('A2');
}


/* =========================================================
   BLOQUES SOLICITADOS
========================================================= */

$bloquesPermitidos = [
    'atenciones',
    'convenios',
    'campanias',
    'registros_campanias',
    'metricas'
];


$bloques = $_GET['bloques'] ?? [];

if (!is_array($bloques)) {
    $bloques = [];
}


$bloques = array_values(
    array_unique(
        array_intersect(
            $bloques,
            $bloquesPermitidos
        )
    )
);


if (!$bloques) {

    http_response_code(400);

    exit(
        'Selecciona al menos un bloque para exportar.'
    );
}


/* =========================================================
   MES
========================================================= */

$mes = trim(
    (string)($_GET['mes'] ?? '')
);


if (!mesValido($mes)) {

    http_response_code(400);

    exit(
        'El mes seleccionado no es válido.'
    );
}


$inicioMes = null;
$finMes = null;


if ($mes !== '') {

    [$inicioMes, $finMes] =
        rangoMes($mes);
}


/* =========================================================
   VALIDAR PERMISOS DE CADA BLOQUE
========================================================= */

$permisosBloques = [

    'atenciones' => 'pacientes',

    'convenios' => 'convenios',

    'campanias' => 'campanias',

    'registros_campanias' => 'campanias',

    'metricas' => 'metricas'

];


foreach ($bloques as $bloque) {

    $modulo =
        $permisosBloques[$bloque]
        ?? null;


    if (
        $modulo &&
        !auth_can_view_module($modulo)
    ) {

        http_response_code(403);

        exit(
            'No tienes permiso para exportar uno de los bloques seleccionados.'
        );
    }
}


/* =========================================================
   GENERAR EXCEL
========================================================= */

try {

    $spreadsheet =
        new Spreadsheet();


    /*
     * Quitamos la hoja inicial vacía.
     */
    $spreadsheet
        ->removeSheetByIndex(0);



    /* =====================================================
       ATENCIONES / PACIENTES
    ===================================================== */

    if (
        in_array(
            'atenciones',
            $bloques,
            true
        )
    ) {

        $sqlAtenciones = "
            SELECT
                id,
                fecha_contacto,
                paciente,
                telefono,
                canal,
                tipo_atencion,
                servicio,
                detalle_consulta,
                status_cita,
                fecha_cita,
                modulo
            FROM atenciones
            WHERE 1 = 1
        ";


        $params = [];


        if ($mes !== '') {

            $sqlAtenciones .= "
                AND fecha_contacto >= :inicio
                AND fecha_contacto < :fin
            ";

            $params[':inicio'] =
                $inicioMes;

            $params[':fin'] =
                $finMes;
        }


        $sqlAtenciones .= "
            ORDER BY id DESC
        ";


        $stmt =
            $pdo->prepare(
                $sqlAtenciones
            );

        $stmt->execute(
            $params
        );


        $atenciones =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        crearHoja(
            $spreadsheet,
            'Atenciones',
            [
                'id' => 'ID',
                'fecha_contacto' => 'Fecha contacto',
                'paciente' => 'Paciente',
                'telefono' => 'Teléfono',
                'canal' => 'Canal',
                'tipo_atencion' => 'Tipo atención',
                'servicio' => 'Servicio',
                'detalle_consulta' => 'Detalle consulta',
                'status_cita' => 'Status cita',
                'fecha_cita' => 'Fecha cita',
                'modulo' => 'Módulo'
            ],
            $atenciones
        );
    }



    /* =====================================================
       CONVENIOS
    ===================================================== */

    if (
        in_array(
            'convenios',
            $bloques,
            true
        )
    ) {

        $sqlConvenios = "
            SELECT
                c.id,
                c.atencion_id,
                a.paciente,
                c.fecha_realizar,
                c.derivado_a,
                c.estudio,
                c.medico_derivado
            FROM convenios c
            INNER JOIN atenciones a
                ON a.id = c.atencion_id
            WHERE 1 = 1
        ";


        $params = [];


        if ($mes !== '') {

            $sqlConvenios .= "
                AND c.fecha_realizar >= :inicio
                AND c.fecha_realizar < :fin
            ";

            $params[':inicio'] =
                $inicioMes;

            $params[':fin'] =
                $finMes;
        }


        $sqlConvenios .= "
            ORDER BY c.id DESC
        ";


        $stmt =
            $pdo->prepare(
                $sqlConvenios
            );

        $stmt->execute(
            $params
        );


        $convenios =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        crearHoja(
            $spreadsheet,
            'Convenios',
            [
                'id' => 'ID',
                'atencion_id' => 'ID atención',
                'paciente' => 'Paciente',
                'fecha_realizar' => 'Fecha a realizar',
                'derivado_a' => 'Derivado a',
                'estudio' => 'Estudio',
                'medico_derivado' => 'Médico derivado'
            ],
            $convenios
        );
    }



    /* =====================================================
       CAMPAÑAS
    ===================================================== */

    if (
        in_array(
            'campanias',
            $bloques,
            true
        )
    ) {

        $sqlCampanias = "
            SELECT
                c.id,
                c.nombre,
                c.descripcion,
                c.fecha_inicio,
                c.estado,
                COUNT(r.id) AS total_registros,
                COALESCE(
                    SUM(r.monto),
                    0
                ) AS monto_total
            FROM campanias c
            LEFT JOIN campania_registros r
                ON r.campania_id = c.id
            WHERE 1 = 1
        ";


        $params = [];


        if ($mes !== '') {

            $sqlCampanias .= "
                AND c.fecha_inicio >= :inicio
                AND c.fecha_inicio < :fin
            ";

            $params[':inicio'] =
                $inicioMes;

            $params[':fin'] =
                $finMes;
        }


        $sqlCampanias .= "
            GROUP BY
                c.id,
                c.nombre,
                c.descripcion,
                c.fecha_inicio,
                c.estado

            ORDER BY c.id DESC
        ";


        $stmt =
            $pdo->prepare(
                $sqlCampanias
            );

        $stmt->execute(
            $params
        );


        $campanias =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        crearHoja(
            $spreadsheet,
            'Campañas',
            [
                'id' => 'ID',
                'nombre' => 'Nombre',
                'descripcion' => 'Descripción',
                'fecha_inicio' => 'Fecha inicio',
                'estado' => 'Estado',
                'total_registros' => 'Total registros',
                'monto_total' => 'Monto total'
            ],
            $campanias
        );
    }



    /* =====================================================
       REGISTROS DE CAMPAÑAS
    ===================================================== */

    if (
        in_array(
            'registros_campanias',
            $bloques,
            true
        )
    ) {

        $sqlRegistros = "
            SELECT
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
            INNER JOIN campanias c
                ON c.id = r.campania_id
            WHERE 1 = 1
        ";


        $params = [];


        if ($mes !== '') {

            /*
             * Los registros no tienen un filtro
             * mensual independiente en tu sistema.
             * Se toma el mes de la campaña.
             */
            $sqlRegistros .= "
                AND c.fecha_inicio >= :inicio
                AND c.fecha_inicio < :fin
            ";

            $params[':inicio'] =
                $inicioMes;

            $params[':fin'] =
                $finMes;
        }


        $sqlRegistros .= "
            ORDER BY r.id DESC
        ";


        $stmt =
            $pdo->prepare(
                $sqlRegistros
            );

        $stmt->execute(
            $params
        );


        $registros =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        crearHoja(
            $spreadsheet,
            'Registros Campañas',
            [
                'id' => 'ID',
                'campania' => 'Campaña',
                'nombre_apellido' => 'Nombre',
                'numero_telefono' => 'Teléfono',
                'dni' => 'DNI',
                'confirmo_cita' => 'Confirmó cita',
                'asistio' => 'Asistió',
                'monto' => 'Monto',
                'que_se_realizo' => 'Servicio realizado'
            ],
            $registros
        );
    }



    /* =====================================================
       MÉTRICAS
    ===================================================== */

    if (
        in_array(
            'metricas',
            $bloques,
            true
        )
    ) {

        /*
         * -------------------------------------------------
         * RESUMEN GENERAL
         * -------------------------------------------------
         */

        $paramsAtenciones = [];

        $whereAtenciones = '';


        if ($mes !== '') {

            $whereAtenciones = "
                AND fecha_contacto >= :inicio
                AND fecha_contacto < :fin
            ";

            $paramsAtenciones = [
                ':inicio' => $inicioMes,
                ':fin' => $finMes
            ];
        }


        $stmt =
            $pdo->prepare("
                SELECT
                    COUNT(*) AS total_atenciones,

                    SUM(
                        CASE
                            WHEN LOWER(
                                TRIM(status_cita)
                            ) = 'confirmado'
                            THEN 1
                            ELSE 0
                        END
                    ) AS confirmados,

                    SUM(
                        CASE
                            WHEN LOWER(
                                TRIM(status_cita)
                            ) = 'no confirmado'
                            THEN 1
                            ELSE 0
                        END
                    ) AS no_confirmados

                FROM atenciones

                WHERE 1 = 1

                {$whereAtenciones}
            ");


        $stmt->execute(
            $paramsAtenciones
        );


        $resumenAtenciones =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            ) ?: [];


        /*
         * Convenios
         */

        $paramsConvenios = [];

        $whereConvenios = '';


        if ($mes !== '') {

            $whereConvenios = "
                AND fecha_realizar >= :inicio
                AND fecha_realizar < :fin
            ";

            $paramsConvenios = [
                ':inicio' => $inicioMes,
                ':fin' => $finMes
            ];
        }


        $stmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM convenios
                WHERE 1 = 1
                {$whereConvenios}
            ");


        $stmt->execute(
            $paramsConvenios
        );


        $totalConvenios =
            (int)$stmt->fetchColumn();


        /*
         * Campañas
         */

        $paramsCampanias = [];

        $whereCampanias = '';


        if ($mes !== '') {

            $whereCampanias = "
                AND fecha_inicio >= :inicio
                AND fecha_inicio < :fin
            ";

            $paramsCampanias = [
                ':inicio' => $inicioMes,
                ':fin' => $finMes
            ];
        }


        $stmt =
            $pdo->prepare("
                SELECT COUNT(*)
                FROM campanias
                WHERE 1 = 1
                {$whereCampanias}
            ");


        $stmt->execute(
            $paramsCampanias
        );


        $totalCampanias =
            (int)$stmt->fetchColumn();


        $stmt =
            $pdo->prepare("
                SELECT COUNT(r.id)

                FROM campania_registros r

                INNER JOIN campanias c
                    ON c.id = r.campania_id

                WHERE 1 = 1

                " .
                (
                    $mes !== ''
                        ? "
                            AND c.fecha_inicio >= :inicio
                            AND c.fecha_inicio < :fin
                        "
                        : ''
                )
            );


        $stmt->execute(
            $paramsCampanias
        );


        $totalRegistros =
            (int)$stmt->fetchColumn();


        crearHoja(
            $spreadsheet,
            'Métricas Resumen',
            [
                'concepto' => 'Métrica',
                'valor' => 'Valor'
            ],
            [
                [
                    'concepto' => 'Total atenciones',
                    'valor' =>
                        (int)(
                            $resumenAtenciones[
                                'total_atenciones'
                            ] ?? 0
                        )
                ],
                [
                    'concepto' => 'Citas confirmadas',
                    'valor' =>
                        (int)(
                            $resumenAtenciones[
                                'confirmados'
                            ] ?? 0
                        )
                ],
                [
                    'concepto' => 'Citas no confirmadas',
                    'valor' =>
                        (int)(
                            $resumenAtenciones[
                                'no_confirmados'
                            ] ?? 0
                        )
                ],
                [
                    'concepto' => 'Total convenios',
                    'valor' => $totalConvenios
                ],
                [
                    'concepto' => 'Total campañas',
                    'valor' => $totalCampanias
                ],
                [
                    'concepto' =>
                        'Registros de campañas',
                    'valor' => $totalRegistros
                ]
            ]
        );



        /*
         * -------------------------------------------------
         * ATENCIONES POR CANAL
         * -------------------------------------------------
         */

        $stmt =
            $pdo->prepare("
                SELECT
                    canal,
                    COUNT(*) AS total

                FROM atenciones

                WHERE
                    canal IS NOT NULL
                    AND canal <> ''

                    {$whereAtenciones}

                GROUP BY canal

                ORDER BY total DESC
            ");


        $stmt->execute(
            $paramsAtenciones
        );


        crearHoja(
            $spreadsheet,
            'Métricas Canal',
            [
                'canal' => 'Canal',
                'total' => 'Total'
            ],
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        );



        /*
         * -------------------------------------------------
         * ATENCIONES POR TIPO
         * -------------------------------------------------
         */

        $stmt =
            $pdo->prepare("
                SELECT
                    tipo_atencion,
                    COUNT(*) AS total

                FROM atenciones

                WHERE
                    tipo_atencion IS NOT NULL
                    AND tipo_atencion <> ''

                    {$whereAtenciones}

                GROUP BY tipo_atencion

                ORDER BY total DESC
            ");


        $stmt->execute(
            $paramsAtenciones
        );


        crearHoja(
            $spreadsheet,
            'Métricas Tipo',
            [
                'tipo_atencion' =>
                    'Tipo de atención',

                'total' =>
                    'Total'
            ],
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        );



        /*
         * -------------------------------------------------
         * CONVENIOS POR DERIVADO
         * -------------------------------------------------
         */

        $stmt =
            $pdo->prepare("
                SELECT
                    derivado_a,
                    COUNT(*) AS total

                FROM convenios

                WHERE
                    derivado_a IS NOT NULL
                    AND derivado_a <> ''

                    {$whereConvenios}

                GROUP BY derivado_a

                ORDER BY total DESC
            ");


        $stmt->execute(
            $paramsConvenios
        );


        crearHoja(
            $spreadsheet,
            'Métricas Convenios',
            [
                'derivado_a' =>
                    'Derivado a',

                'total' =>
                    'Total'
            ],
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        );



        /*
         * -------------------------------------------------
         * DETALLE DE CAMPAÑAS
         * -------------------------------------------------
         */

        $stmt =
            $pdo->prepare("
                SELECT
                    c.id,
                    c.nombre,
                    c.estado,
                    COUNT(r.id)
                        AS total_registros,

                    ROUND(
                        COALESCE(
                            SUM(r.monto),
                            0
                        ),
                        2
                    ) AS monto_total

                FROM campanias c

                LEFT JOIN campania_registros r
                    ON r.campania_id = c.id

                WHERE 1 = 1

                " .
                (
                    $mes !== ''
                        ? "
                            AND c.fecha_inicio >= :inicio
                            AND c.fecha_inicio < :fin
                        "
                        : ''
                )
                . "

                GROUP BY
                    c.id,
                    c.nombre,
                    c.estado

                ORDER BY c.id DESC
            ");


        $stmt->execute(
            $paramsCampanias
        );


        crearHoja(
            $spreadsheet,
            'Métricas Campañas',
            [
                'id' => 'ID',
                'nombre' => 'Campaña',
                'estado' => 'Estado',
                'total_registros' =>
                    'Total registros',
                'monto_total' =>
                    'Monto total'
            ],
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
        );
    }



    /* =====================================================
       COMPROBACIÓN FINAL
    ===================================================== */

    if (
        $spreadsheet
            ->getSheetCount() === 0
    ) {

        http_response_code(400);

        exit(
            'No hay información disponible para exportar.'
        );
    }



    /* =====================================================
       DESCARGA
    ===================================================== */

    $spreadsheet
        ->setActiveSheetIndex(0);


    $sufijoMes =
        $mes !== ''
            ? '_' . $mes
            : '';


    $filename =
        'exportacion_general'
        . $sufijoMes
        . '_'
        . date('Y-m-d_H-i-s')
        . '.xlsx';


    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header(
        'Cache-Control: max-age=0'
    );


    $writer =
        new Xlsx(
            $spreadsheet
        );


    $writer->save(
        'php://output'
    );


    exit;


} catch (Throwable $e) {

    http_response_code(500);

    exit(
        'No se pudo generar la exportación. Inténtalo nuevamente.'
    );
}