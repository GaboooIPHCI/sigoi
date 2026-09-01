<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/_doctor_directory.php';

auth_require_module_modify('pacientes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_json_response([
        'success' => false,
        'message' => 'Método no permitido.'
    ], 405);
}

auth_validate_csrf();

function jsonError(string $message): void
{
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit;
}

try {

    $allowedCanales = [
        'Redes',
        'Fachada',
        'Web',
        'Referido Drs',
        'Whatsapp',
        'Seguimiento',
        'Interno'
    ];

    $allowedTiposAtencion = [
        'Centro Médico',
        'Procedimiento',
        'Convenios'
    ];

    $allowedServicios = [
        'Consulta',
        'Ecografía',
        'Laboratorio',
        'Vitaminas',
        '2da opinión',
        'Perdido',
        'Alquiler',
        'Paquete Ads'
    ];

    $allowedStatus = [
        'Confirmado',
        'No Confirmado',
        'Canceló',
        'Reprogramó',
        'Por Confirmar',
        'No Asistió'
    ];

    $allowedConvenioServicios = [
        'Servimovil',
        'Resocentro'
    ];


    /* =========================
       ID
    ========================= */

    $id =
        isset($_POST['record_id'])
            ? (int) $_POST['record_id']
            : 0;

    if ($id <= 0) {
        jsonError(
            'ID inválido para actualizar'
        );
    }


    /* =========================
       DATOS PRINCIPALES
    ========================= */

    $fecha_contacto =
        $_POST['fecha_contacto'] ?? null;

    $paciente =
        trim($_POST['paciente'] ?? '');

    $telefono =
        trim($_POST['telefono'] ?? '');

    $dni =
        trim($_POST['dni'] ?? '');

    $canal =
        trim($_POST['canal'] ?? '');

    $tipo_atencion =
        trim($_POST['tipo_atencion'] ?? '');

    $servicio =
        trim($_POST['servicio'] ?? '');

    $nombre_referido =
        trim($_POST['nombre_referido'] ?? '');

    $detalle_consulta =
        trim($_POST['detalle_consulta'] ?? '');

    $status_cita =
        trim($_POST['status_cita'] ?? '');

    $fecha_cita =
        $_POST['fecha_cita'] ?? null;

    $doctor_cita =
        trim($_POST['doctor_cita'] ?? '');


    /* =========================
       VALIDACIONES
    ========================= */

    if (
        $canal === '' ||
        $tipo_atencion === '' ||
        $servicio === '' ||
        $detalle_consulta === '' ||
        $status_cita === ''
    ) {
        jsonError(
            'Faltan campos obligatorios'
        );
    }

    if (
        !in_array(
            $canal,
            $allowedCanales,
            true
        )
    ) {
        jsonError(
            'Canal inválido'
        );
    }

    if (
        !in_array(
            $tipo_atencion,
            $allowedTiposAtencion,
            true
        )
    ) {
        jsonError(
            'Tipo de atención inválido'
        );
    }

    if (
        !in_array(
            $servicio,
            $allowedServicios,
            true
        )
    ) {
        jsonError(
            'Servicio inválido'
        );
    }

    if (
        !in_array(
            $status_cita,
            $allowedStatus,
            true
        )
    ) {
        jsonError(
            'Status de cita inválido'
        );
    }


    /* =========================
       CAMPOS DINÁMICOS
    ========================= */

    $canalesConReferido = [
        'Interno',
        'Referido Drs'
    ];

    $requiereDerivadoPor =
        in_array(
            $canal,
            $canalesConReferido,
            true
        );

    if (
        $requiereDerivadoPor &&
        $nombre_referido === ''
    ) {
        jsonError(
            'El campo Derivado por es obligatorio para este canal'
        );
    }

    if (!$requiereDerivadoPor) {
        $nombre_referido = '';
    } elseif (!atenciones_is_registered_doctor($pdo, $nombre_referido)) {
        jsonError(
            'Selecciona el doctor que refiere desde el directorio de Médicos'
        );
    }

    if (
        $status_cita === 'Confirmado' &&
        $doctor_cita === ''
    ) {
        jsonError(
            'El doctor que atenderá es obligatorio cuando la cita está confirmada'
        );
    }

    if (
        $status_cita !== 'Confirmado'
    ) {
        $doctor_cita = '';
    } elseif (!atenciones_is_valid_appointment_provider($pdo, $doctor_cita)) {
        jsonError(
            'Selecciona un médico registrado o Personal asistencial'
        );
    }


    /* =========================
       MÓDULO
    ========================= */

    $modulo =
        (
            $tipo_atencion ===
            'Convenios'
        )
            ? 'Convenios'
            : 'General';


    /* =========================
       TRANSACCIÓN
    ========================= */

    $pdo->beginTransaction();


    /* =========================
       VALIDAR ATENCIÓN
    ========================= */

    $checkAtencionSql = "
        SELECT id
        FROM atenciones
        WHERE id = :id
        LIMIT 1
    ";

    $checkAtencionStmt =
        $pdo->prepare(
            $checkAtencionSql
        );

    $checkAtencionStmt->execute([
        ':id' => $id
    ]);

    $existingAtencion =
        $checkAtencionStmt->fetch();

    if (!$existingAtencion) {
        throw new RuntimeException(
            'La atención no existe'
        );
    }


    /* =========================
       UPDATE ATENCIÓN
    ========================= */

    $sql = "
        UPDATE atenciones SET
            fecha_contacto = :fecha_contacto,
            paciente = :paciente,
            telefono = :telefono,
            dni = :dni,
            canal = :canal,
            tipo_atencion = :tipo_atencion,
            servicio = :servicio,
            nombre_referido = :nombre_referido,
            detalle_consulta = :detalle_consulta,
            status_cita = :status_cita,
            fecha_cita = :fecha_cita,
            doctor_cita = :doctor_cita,
            modulo = :modulo
        WHERE id = :id
    ";

    $stmt =
        $pdo->prepare($sql);

    $stmt->execute([
        ':fecha_contacto' =>
            $fecha_contacto,

        ':paciente' =>
            $paciente,

        ':telefono' =>
            $telefono,

        ':dni' =>
            $dni !== ''
                ? $dni
                : null,

        ':canal' =>
            $canal,

        ':tipo_atencion' =>
            $tipo_atencion,

        ':servicio' =>
            $servicio,

        ':nombre_referido' =>
            $nombre_referido !== ''
                ? $nombre_referido
                : null,

        ':detalle_consulta' =>
            $detalle_consulta,

        ':status_cita' =>
            $status_cita,

        ':fecha_cita' =>
            $fecha_cita
                ? $fecha_cita
                : null,

        ':doctor_cita' =>
            $doctor_cita !== ''
                ? $doctor_cita
                : null,

        ':modulo' =>
            $modulo,

        ':id' =>
            $id
    ]);


    /* =========================
       CONVENIOS
    ========================= */

    if (
        $tipo_atencion ===
        'Convenios'
    ) {

        $conv_fecha_realizar =
            $_POST['conv_fecha_realizar'] ?? null;

        $conv_derivado_a =
            trim(
                $_POST['conv_derivado_a']
                ?? ''
            );

        $conv_estudio =
            trim(
                $_POST['conv_estudio']
                ?? ''
            );

        $conv_medico_derivado =
            trim(
                $_POST['conv_medico_derivado']
                ?? ''
            );


        if (
            $conv_derivado_a !== '' &&
            !in_array(
                $conv_derivado_a,
                $allowedConvenioServicios,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Derivado a inválido'
            );
        }


        $checkSql = "
            SELECT id
            FROM convenios
            WHERE atencion_id = :atencion_id
            LIMIT 1
        ";

        $checkStmt =
            $pdo->prepare(
                $checkSql
            );

        $checkStmt->execute([
            ':atencion_id' =>
                $id
        ]);

        $existingConvenio =
            $checkStmt->fetch();


        if ($existingConvenio) {

            $sqlConvenio = "
                UPDATE convenios SET
                    fecha_realizar = :fecha_realizar,
                    derivado_a = :derivado_a,
                    estudio = :estudio,
                    medico_derivado = :medico_derivado
                WHERE atencion_id = :atencion_id
            ";

            $stmtConvenio =
                $pdo->prepare(
                    $sqlConvenio
                );

            $stmtConvenio->execute([
                ':fecha_realizar' =>
                    $conv_fecha_realizar
                        ? $conv_fecha_realizar
                        : null,

                ':derivado_a' =>
                    $conv_derivado_a !== ''
                        ? $conv_derivado_a
                        : null,

                ':estudio' =>
                    $conv_estudio !== ''
                        ? $conv_estudio
                        : null,

                ':medico_derivado' =>
                    $conv_medico_derivado !== ''
                        ? $conv_medico_derivado
                        : null,

                ':atencion_id' =>
                    $id
            ]);

        } else {

            $sqlConvenio = "
                INSERT INTO convenios (
                    atencion_id,
                    fecha_realizar,
                    derivado_a,
                    estudio,
                    medico_derivado
                )
                VALUES (
                    :atencion_id,
                    :fecha_realizar,
                    :derivado_a,
                    :estudio,
                    :medico_derivado
                )
            ";

            $stmtConvenio =
                $pdo->prepare(
                    $sqlConvenio
                );

            $stmtConvenio->execute([
                ':atencion_id' =>
                    $id,

                ':fecha_realizar' =>
                    $conv_fecha_realizar
                        ? $conv_fecha_realizar
                        : null,

                ':derivado_a' =>
                    $conv_derivado_a !== ''
                        ? $conv_derivado_a
                        : null,

                ':estudio' =>
                    $conv_estudio !== ''
                        ? $conv_estudio
                        : null,

                ':medico_derivado' =>
                    $conv_medico_derivado !== ''
                        ? $conv_medico_derivado
                        : null
            ]);
        }

    } else {

        $deleteConvenioSql = "
            DELETE FROM convenios
            WHERE atencion_id = :atencion_id
        ";

        $deleteConvenioStmt =
            $pdo->prepare(
                $deleteConvenioSql
            );

        $deleteConvenioStmt->execute([
            ':atencion_id' =>
                $id
        ]);
    }


    /* =========================
       FINALIZAR
    ========================= */

    $pdo->commit();


    echo json_encode([
        'success' => true,
        'message' =>
            'Registro actualizado correctamente'
    ]);


} catch (Throwable $e) {

    if (
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }


    echo json_encode([
        'success' => false,
        'message' =>
            'Error al actualizar registro'
    ]);
}
