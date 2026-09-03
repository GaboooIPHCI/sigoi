<?php
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../config/messenger_schema.php';
auth_validate_csrf();

try {
    global $pdo;

    $data = usuarios_request();

    $usuarioId = (int) ($data['usuario_id'] ?? 0);
    $permisos = $data['permisos'] ?? [];
    $whatsappPermisos = $data['whatsapp_permisos'] ?? null;

    if ($usuarioId <= 0) {
        usuarios_json(false, 'Usuario inválido.', [], 422);
    }

    if (!is_array($permisos)) {
        usuarios_json(false, 'Formato de permisos inválido.', [], 422);
    }

    if ($whatsappPermisos !== null && !is_array($whatsappPermisos)) {
        usuarios_json(false, 'Formato de permisos de WhatsApp inválido.', [], 422);
    }

    $stmtUsuario = $pdo->prepare("
        SELECT id, nombre, usuario, rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");
    $stmtUsuario->execute([':id' => $usuarioId]);
    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        usuarios_json(false, 'La cuenta seleccionada no existe.', [], 404);
    }

    if ($usuario['rol'] === 'admin') {
        usuarios_json(false, 'Los permisos de la cuenta Administrador están protegidos.', [], 403);
    }

    $stmtModulos = $pdo->query("
        SELECT id, clave
        FROM modulos_sistema
        WHERE activo = 1
    ");
    $modulosDisponibles = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);
    $modulosMap = [];
    foreach ($modulosDisponibles as $modulo) {
        $modulosMap[(int) $modulo['id']] = $modulo['clave'];
    }

    $waNormalized = null;

    if (is_array($whatsappPermisos)) {
        auth_whatsapp_ensure_permissions_schema($pdo);
        messenger_ensure_permissions_schema($pdo);

        $waNormalized = [
            'acceso' => !empty($whatsappPermisos['acceso']) ? 1 : 0,
            'canal_whatsapp' => array_key_exists('canal_whatsapp', $whatsappPermisos) ? (!empty($whatsappPermisos['canal_whatsapp']) ? 1 : 0) : 1,
            'canal_instagram' => array_key_exists('canal_instagram', $whatsappPermisos) ? (!empty($whatsappPermisos['canal_instagram']) ? 1 : 0) : 1,
            'canal_messenger' => array_key_exists('canal_messenger', $whatsappPermisos) ? (!empty($whatsappPermisos['canal_messenger']) ? 1 : 0) : 0,
            'bandeja_ver' => !empty($whatsappPermisos['bandeja_ver']) ? 1 : 0,
            'bandeja_responder' => !empty($whatsappPermisos['bandeja_responder']) ? 1 : 0,
            'bandeja_gestionar' => !empty($whatsappPermisos['bandeja_gestionar']) ? 1 : 0,
            'automatizacion_ver' => !empty($whatsappPermisos['automatizacion_ver']) ? 1 : 0,
            'automatizacion_modificar' => !empty($whatsappPermisos['automatizacion_modificar']) ? 1 : 0,
            'plantillas_ver' => !empty($whatsappPermisos['plantillas_ver']) ? 1 : 0,
            'plantillas_gestionar' => !empty($whatsappPermisos['plantillas_gestionar']) ? 1 : 0,
            'analitica_ver' => !empty($whatsappPermisos['analitica_ver']) ? 1 : 0,
        ];

        if ($waNormalized['acceso'] === 0) {
            foreach (array_keys($waNormalized) as $key) {
                if ($key !== 'acceso') {
                    $waNormalized[$key] = 0;
                }
            }
        }

        if ($waNormalized['bandeja_ver'] === 0) {
            $waNormalized['bandeja_responder'] = 0;
            $waNormalized['bandeja_gestionar'] = 0;
        }

        if ($waNormalized['automatizacion_ver'] === 0) {
            $waNormalized['automatizacion_modificar'] = 0;
        }

        if ($waNormalized['plantillas_ver'] === 0) {
            $waNormalized['plantillas_gestionar'] = 0;
        }
    }

    $pdo->beginTransaction();

    $stmtPermiso = $pdo->prepare("
        INSERT INTO usuarios_permisos
        (usuario_id, modulo_id, puede_ver, puede_modificar)
        VALUES (:usuario_id, :modulo_id, :puede_ver, :puede_modificar)
        ON DUPLICATE KEY UPDATE
            puede_ver = VALUES(puede_ver),
            puede_modificar = VALUES(puede_modificar)
    ");

    foreach ($permisos as $permiso) {
        $moduloId = (int) ($permiso['modulo_id'] ?? 0);

        if ($moduloId <= 0 || !isset($modulosMap[$moduloId])) {
            continue;
        }

        $puedeVer = !empty($permiso['puede_ver']) ? 1 : 0;
        $puedeModificar = !empty($permiso['puede_modificar']) ? 1 : 0;

        if ($modulosMap[$moduloId] === 'whatsapp' && is_array($waNormalized)) {
            $puedeVer = $waNormalized['acceso'];
            $puedeModificar = (
                $puedeVer === 1
                && (
                    $waNormalized['bandeja_responder'] === 1
                    || $waNormalized['bandeja_gestionar'] === 1
                    || $waNormalized['automatizacion_modificar'] === 1
                    || $waNormalized['plantillas_gestionar'] === 1
                )
            ) ? 1 : 0;
        }

        if ($puedeVer === 0) {
            $puedeModificar = 0;
        }

        $stmtPermiso->execute([
            ':usuario_id' => $usuarioId,
            ':modulo_id' => $moduloId,
            ':puede_ver' => $puedeVer,
            ':puede_modificar' => $puedeModificar,
        ]);
    }

    if (is_array($waNormalized)) {
        $stmtWhatsapp = $pdo->prepare("
            INSERT INTO usuarios_whatsapp_permisos
            (
                usuario_id,
                canal_whatsapp,
                canal_instagram,
                canal_messenger,
                bandeja_ver,
                bandeja_responder,
                bandeja_gestionar,
                automatizacion_ver,
                automatizacion_modificar,
                plantillas_ver,
                plantillas_gestionar,
                analitica_ver
            )
            VALUES
            (
                :usuario_id,
                :canal_whatsapp,
                :canal_instagram,
                :canal_messenger,
                :bandeja_ver,
                :bandeja_responder,
                :bandeja_gestionar,
                :automatizacion_ver,
                :automatizacion_modificar,
                :plantillas_ver,
                :plantillas_gestionar,
                :analitica_ver
            )
            ON DUPLICATE KEY UPDATE
                canal_whatsapp = VALUES(canal_whatsapp),
                canal_instagram = VALUES(canal_instagram),
                canal_messenger = VALUES(canal_messenger),
                bandeja_ver = VALUES(bandeja_ver),
                bandeja_responder = VALUES(bandeja_responder),
                bandeja_gestionar = VALUES(bandeja_gestionar),
                automatizacion_ver = VALUES(automatizacion_ver),
                automatizacion_modificar = VALUES(automatizacion_modificar),
                plantillas_ver = VALUES(plantillas_ver),
                plantillas_gestionar = VALUES(plantillas_gestionar),
                analitica_ver = VALUES(analitica_ver)
        ");

        $stmtWhatsapp->execute([
            ':usuario_id' => $usuarioId,
            ':canal_whatsapp' => $waNormalized['canal_whatsapp'],
            ':canal_instagram' => $waNormalized['canal_instagram'],
            ':canal_messenger' => $waNormalized['canal_messenger'],
            ':bandeja_ver' => $waNormalized['bandeja_ver'],
            ':bandeja_responder' => $waNormalized['bandeja_responder'],
            ':bandeja_gestionar' => $waNormalized['bandeja_gestionar'],
            ':automatizacion_ver' => $waNormalized['automatizacion_ver'],
            ':automatizacion_modificar' => $waNormalized['automatizacion_modificar'],
            ':plantillas_ver' => $waNormalized['plantillas_ver'],
            ':plantillas_gestionar' => $waNormalized['plantillas_gestionar'],
            ':analitica_ver' => $waNormalized['analitica_ver'],
        ]);
    }

    $pdo->commit();

    auth_audit(
        $pdo,
        'permisos_actualizados',
        auth_user_id(),
        auth_username(),
        'Permisos actualizados para: ' . $usuario['usuario']
    );

    usuarios_json(true, 'Permisos guardados correctamente.');

} catch (Throwable $e) {
    if (
        isset($pdo)
        && $pdo instanceof PDO
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log('Usuarios permisos-save: ' . $e->getMessage());
    usuarios_json(false, 'No se pudieron guardar los permisos.', [], 500);
}
