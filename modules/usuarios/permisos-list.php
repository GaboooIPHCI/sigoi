<?php
require_once __DIR__ . '/_helpers.php';

try {
    global $pdo;

    $usuarioId = (int)($_GET['usuario_id'] ?? 0);

    if ($usuarioId <= 0) {
        usuarios_json(false, 'Usuario inválido.', [], 422);
    }

    $stmtUsuario = $pdo->prepare("
        SELECT id, nombre, usuario, rol, activo
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");
    $stmtUsuario->execute([':id' => $usuarioId]);
    $usuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        usuarios_json(false, 'La cuenta seleccionada no existe.', [], 404);
    }

    $stmt = $pdo->prepare("
        SELECT
            ms.id AS modulo_id,
            ms.clave,
            ms.nombre,
            ms.grupo,
            ms.orden,
            ms.activo,
            COALESCE(up.puede_ver, 0) AS puede_ver,
            COALESCE(up.puede_modificar, 0) AS puede_modificar
        FROM modulos_sistema ms
        LEFT JOIN usuarios_permisos up
            ON up.modulo_id = ms.id
           AND up.usuario_id = :usuario_id
        WHERE ms.activo = 1
        ORDER BY
            FIELD(
                ms.grupo,
                'gestion',
                'analitica',
                'administracion',
                'acciones'
            ),
            ms.orden ASC,
            ms.nombre ASC
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);
    $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grupos = [
        'gestion' => [
            'clave' => 'gestion',
            'nombre' => 'Gestión',
            'modulos' => [],
        ],
        'whatsapp' => [
            'clave' => 'whatsapp',
            'nombre' => 'WhatsApp',
            'modulos' => [],
        ],
        'analitica' => [
            'clave' => 'analitica',
            'nombre' => 'Analítica',
            'modulos' => [],
        ],
        'administracion' => [
            'clave' => 'administracion',
            'nombre' => 'Administración',
            'modulos' => [],
        ],
        'acciones' => [
            'clave' => 'acciones',
            'nombre' => 'Otras acciones',
            'modulos' => [],
        ],
    ];

    foreach ($modulos as $modulo) {
        /*
         * Históricamente WhatsApp fue creado con grupo=gestion en BD.
         * La navegación actual ya lo trata como módulo principal propio,
         * por lo que aquí normalizamos solo su presentación de permisos.
         * No requiere alterar la base de datos existente.
         */
        $grupo = (string)$modulo['grupo'];
        if ((string)$modulo['clave'] === 'whatsapp') {
            $grupo = 'whatsapp';
        }

        if (!isset($grupos[$grupo])) {
            continue;
        }

        if ($usuario['rol'] === 'admin') {
            $modulo['puede_ver'] = 1;
            $modulo['puede_modificar'] = 1;
        }

        $modulePayload = [
            'id' => (int)$modulo['modulo_id'],
            'clave' => $modulo['clave'],
            'nombre' => $modulo['nombre'],
            'puede_ver' => (int)$modulo['puede_ver'],
            'puede_modificar' => (int)$modulo['puede_modificar'],
            'tipo' => 'normal',
        ];

        if ((string)$modulo['clave'] === 'whatsapp') {
            $modulePayload['tipo'] = 'whatsapp_detallado';
            $modulePayload['whatsapp'] = auth_whatsapp_permissions_for_user(
                $pdo,
                $usuarioId,
                (string)$usuario['rol'],
                (int)$modulo['puede_ver'] === 1,
                (int)$modulo['puede_modificar'] === 1
            );
        }

        $grupos[$grupo]['modulos'][] = $modulePayload;
    }

    $grupos = array_values(
        array_filter(
            $grupos,
            static function (array $grupo): bool {
                return !empty($grupo['modulos']);
            }
        )
    );

    usuarios_json(
        true,
        '',
        [
            'usuario' => [
                'id' => (int)$usuario['id'],
                'nombre' => $usuario['nombre'],
                'usuario' => $usuario['usuario'],
                'rol' => $usuario['rol'],
                'activo' => (int)$usuario['activo'],
                'es_admin' => $usuario['rol'] === 'admin',
            ],
            'grupos' => $grupos,
        ]
    );

} catch (Throwable $e) {
    error_log('Usuarios permisos-list: ' . $e->getMessage());

    usuarios_json(
        false,
        'No se pudieron cargar los permisos.',
        [],
        500
    );
}
