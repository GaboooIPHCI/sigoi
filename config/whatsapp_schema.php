<?php

declare(strict_types=1);

/**
 * Esquema idempotente del módulo WhatsApp.
 *
 * Esta versión conserva lo ya existente y añade la infraestructura necesaria
 * para bandeja multiusuario, multimedia, estados de entrega y analítica.
 */
function whatsapp_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // Evita ejecutar migraciones pesadas en cada request/webhook.
    $migrationKey = 'whatsapp_inbox_v4_2_runtime_20260829';
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS iphci_schema_migrations (
            clave VARCHAR(80) NOT NULL,
            aplicado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $checkMigration = $pdo->prepare("SELECT 1 FROM iphci_schema_migrations WHERE clave = :clave LIMIT 1");
        $checkMigration->execute([':clave' => $migrationKey]);
        if ($checkMigration->fetchColumn()) {
            $done = true;
            return;
        }
    } catch (Throwable $e) {
        // Si todavía no puede leerse la marca, continúa con la instalación normal.
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_configuracion (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            automatizacion_activa TINYINT(1) NOT NULL DEFAULT 0,
            mensaje_bienvenida TEXT NOT NULL,
            mensaje_no_reconocido TEXT NOT NULL,
            zona_horaria VARCHAR(60) NOT NULL DEFAULT 'America/Lima',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_horarios (
            id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dia_semana TINYINT UNSIGNED NOT NULL,
            atencion_humana_activa TINYINT(1) NOT NULL DEFAULT 1,
            hora_inicio TIME DEFAULT NULL,
            hora_fin TIME DEFAULT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_whatsapp_horario_dia (dia_semana)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_reglas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            palabras_clave TEXT NOT NULL,
            respuesta TEXT NOT NULL,
            prioridad SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            activa TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT UNSIGNED DEFAULT NULL,
            actualizado_por INT UNSIGNED DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_whatsapp_reglas_activas (activa, prioridad)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_conversaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            telefono VARCHAR(32) NOT NULL,
            nombre_contacto VARCHAR(140) DEFAULT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'abierta',
            requiere_humano TINYINT(1) NOT NULL DEFAULT 0,
            ultimo_mensaje_en DATETIME DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_whatsapp_conversacion_telefono (telefono),
            KEY idx_whatsapp_conversaciones_estado (estado, requiere_humano),
            KEY idx_whatsapp_conversaciones_ultimo (ultimo_mensaje_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_mensajes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversacion_id BIGINT UNSIGNED NOT NULL,
            wamid VARCHAR(180) DEFAULT NULL,
            direccion ENUM('entrante','saliente') NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'texto',
            contenido TEXT DEFAULT NULL,
            regla_id INT UNSIGNED DEFAULT NULL,
            respuesta_automatica TINYINT(1) NOT NULL DEFAULT 0,
            estado_envio VARCHAR(30) DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_whatsapp_mensaje_wamid (wamid),
            KEY idx_whatsapp_mensajes_conversacion (conversacion_id, creado_en),
            KEY idx_whatsapp_mensajes_regla (regla_id),
            CONSTRAINT fk_whatsapp_mensajes_conversacion
                FOREIGN KEY (conversacion_id) REFERENCES whatsapp_conversaciones(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_whatsapp_mensajes_regla
                FOREIGN KEY (regla_id) REFERENCES whatsapp_reglas(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(140) NOT NULL,
            tipo VARCHAR(80) NOT NULL,
            procesado TINYINT(1) NOT NULL DEFAULT 0,
            error TEXT DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_whatsapp_eventos_event (event_id),
            KEY idx_whatsapp_eventos_tipo (tipo, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ---------- Migración incremental de conversaciones ----------
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'nombre_personalizado', "VARCHAR(140) DEFAULT NULL AFTER nombre_contacto");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'notas_contacto', "TEXT DEFAULT NULL AFTER nombre_personalizado");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'bsuid', "VARCHAR(140) DEFAULT NULL AFTER notas_contacto");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'username_whatsapp', "VARCHAR(140) DEFAULT NULL AFTER bsuid");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'asignado_a', "INT DEFAULT NULL AFTER requiere_humano");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'no_leidos', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER asignado_a");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'primer_mensaje_en', "DATETIME DEFAULT NULL AFTER no_leidos");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'primera_respuesta_auto_en', "DATETIME DEFAULT NULL AFTER primer_mensaje_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'primera_respuesta_humana_en', "DATETIME DEFAULT NULL AFTER primera_respuesta_auto_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'ultima_respuesta_humana_en', "DATETIME DEFAULT NULL AFTER primera_respuesta_humana_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'resuelto_en', "DATETIME DEFAULT NULL AFTER ultima_respuesta_humana_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'ultimo_mensaje_direccion', "VARCHAR(12) DEFAULT NULL AFTER ultimo_mensaje_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'ultimo_mensaje_tipo', "VARCHAR(30) DEFAULT NULL AFTER ultimo_mensaje_direccion");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'ultimo_mensaje_preview', "VARCHAR(255) DEFAULT NULL AFTER ultimo_mensaje_tipo");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'ultimo_mensaje_origen', "VARCHAR(30) DEFAULT NULL AFTER ultimo_mensaje_preview");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_fuente', "VARCHAR(40) DEFAULT NULL AFTER ultimo_mensaje_origen");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_id', "VARCHAR(180) DEFAULT NULL AFTER origen_fuente");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_url', "TEXT DEFAULT NULL AFTER origen_id");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_titulo', "VARCHAR(255) DEFAULT NULL AFTER origen_url");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_media_url', "TEXT DEFAULT NULL AFTER origen_titulo");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_conversaciones', 'origen_ctwa_clid', "VARCHAR(255) DEFAULT NULL AFTER origen_media_url");

        whatsapp_add_index_if_missing($pdo, 'whatsapp_conversaciones', 'idx_whatsapp_conversaciones_asignado', 'KEY idx_whatsapp_conversaciones_asignado (asignado_a, estado)');
        whatsapp_add_index_if_missing($pdo, 'whatsapp_conversaciones', 'idx_whatsapp_conversaciones_no_leidos', 'KEY idx_whatsapp_conversaciones_no_leidos (no_leidos, ultimo_mensaje_en)');

        // ---------- Migración incremental de mensajes ----------
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'ycloud_id', "VARCHAR(140) DEFAULT NULL AFTER wamid");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'evento_id', "VARCHAR(140) DEFAULT NULL AFTER ycloud_id");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'origen', "VARCHAR(30) NOT NULL DEFAULT 'cliente' AFTER direccion");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'usuario_id', "INT DEFAULT NULL AFTER origen");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'fuera_horario', "TINYINT(1) NOT NULL DEFAULT 0 AFTER respuesta_automatica");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_id', "VARCHAR(180) DEFAULT NULL AFTER contenido");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_url', "TEXT DEFAULT NULL AFTER media_id");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_local_path', "VARCHAR(500) DEFAULT NULL AFTER media_url");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_mime', "VARCHAR(140) DEFAULT NULL AFTER media_local_path");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_filename', "VARCHAR(255) DEFAULT NULL AFTER media_mime");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'media_size', "BIGINT UNSIGNED DEFAULT NULL AFTER media_filename");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'reply_to_wamid', "VARCHAR(180) DEFAULT NULL AFTER media_size");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'external_id', "VARCHAR(140) DEFAULT NULL AFTER reply_to_wamid");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'enviado_en', "DATETIME DEFAULT NULL AFTER estado_envio");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'entregado_en', "DATETIME DEFAULT NULL AFTER enviado_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'leido_en', "DATETIME DEFAULT NULL AFTER entregado_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'fallido_en', "DATETIME DEFAULT NULL AFTER leido_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'error_envio', "TEXT DEFAULT NULL AFTER fallido_en");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'precio', "DECIMAL(12,6) DEFAULT NULL AFTER error_envio");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'moneda', "VARCHAR(12) DEFAULT NULL AFTER precio");
        whatsapp_add_column_if_missing($pdo, 'whatsapp_mensajes', 'pricing_category', "VARCHAR(100) DEFAULT NULL AFTER moneda");

        whatsapp_add_index_if_missing($pdo, 'whatsapp_mensajes', 'idx_whatsapp_mensajes_ycloud', 'KEY idx_whatsapp_mensajes_ycloud (ycloud_id)');
        whatsapp_add_index_if_missing($pdo, 'whatsapp_mensajes', 'idx_whatsapp_mensajes_external', 'KEY idx_whatsapp_mensajes_external (external_id)');
        whatsapp_add_index_if_missing($pdo, 'whatsapp_mensajes', 'idx_whatsapp_mensajes_origen', 'KEY idx_whatsapp_mensajes_origen (origen, creado_en)');
        whatsapp_add_index_if_missing($pdo, 'whatsapp_mensajes', 'idx_whatsapp_mensajes_fuera_horario', 'KEY idx_whatsapp_mensajes_fuera_horario (fuera_horario, creado_en)');

        // Datos base.
        $pdo->exec("INSERT INTO whatsapp_configuracion
            (id, automatizacion_activa, mensaje_bienvenida, mensaje_no_reconocido, zona_horaria)
            SELECT 1, 0,
                '¡Hola! Gracias por comunicarte con IPHCI. En este momento nuestro equipo no se encuentra disponible, pero podemos ayudarte con algunas consultas frecuentes.',
                'Gracias por escribirnos. En este momento nuestro equipo se encuentra fuera del horario de atención y no contamos con una respuesta automática para esa consulta. Un asesor continuará contigo cuando retomemos la atención.',
                'America/Lima'
            WHERE NOT EXISTS (SELECT 1 FROM whatsapp_configuracion WHERE id = 1)");

        $defaultSchedule = [
            1 => [1, '08:00:00', '17:00:00'],
            2 => [1, '08:00:00', '17:00:00'],
            3 => [1, '08:00:00', '17:00:00'],
            4 => [1, '08:00:00', '17:00:00'],
            5 => [1, '08:00:00', '17:00:00'],
            6 => [0, null, null],
            7 => [0, null, null],
        ];

        $scheduleStmt = $pdo->prepare("INSERT INTO whatsapp_horarios
            (dia_semana, atencion_humana_activa, hora_inicio, hora_fin)
            SELECT :dia, :activa, :inicio, :fin
            WHERE NOT EXISTS (SELECT 1 FROM whatsapp_horarios WHERE dia_semana = :dia_check)");

        foreach ($defaultSchedule as $day => $row) {
            $scheduleStmt->execute([
                ':dia' => $day,
                ':activa' => $row[0],
                ':inicio' => $row[1],
                ':fin' => $row[2],
                ':dia_check' => $day,
            ]);
        }

        $pdo->exec("INSERT INTO modulos_sistema (clave, nombre, grupo, orden, activo)
            SELECT 'whatsapp', 'WhatsApp', 'gestion', 40, 1
            WHERE NOT EXISTS (SELECT 1 FROM modulos_sistema WHERE clave = 'whatsapp')");

        // Marketing y Recepción obtienen acceso por defecto si todavía no poseen una regla explícita.
        $pdo->exec("INSERT INTO usuarios_permisos (usuario_id, modulo_id, puede_ver, puede_modificar)
            SELECT u.id, m.id, 1, 1
            FROM usuarios_sistema u
            INNER JOIN modulos_sistema m ON m.clave = 'whatsapp'
            LEFT JOIN usuarios_permisos p ON p.usuario_id = u.id AND p.modulo_id = m.id
            WHERE u.rol IN ('marketing','recepcion') AND p.usuario_id IS NULL");

        // Backfill mínimo para que la nueva analítica aproveche los datos previos.
        $pdo->exec("UPDATE whatsapp_mensajes
            SET origen = CASE
                WHEN direccion = 'entrante' THEN 'cliente'
                WHEN respuesta_automatica = 1 THEN 'automatizacion'
                ELSE 'sigoi'
            END
            WHERE origen IS NULL OR origen = '' OR origen = 'cliente' AND direccion = 'saliente'");

        $pdo->exec("UPDATE whatsapp_conversaciones c
            SET primer_mensaje_en = COALESCE(primer_mensaje_en, (
                SELECT MIN(m.creado_en) FROM whatsapp_mensajes m
                WHERE m.conversacion_id = c.id AND m.direccion = 'entrante'
            )),
            primera_respuesta_auto_en = COALESCE(primera_respuesta_auto_en, (
                SELECT MIN(m.creado_en) FROM whatsapp_mensajes m
                WHERE m.conversacion_id = c.id AND m.direccion = 'saliente' AND m.respuesta_automatica = 1
            )),
            primera_respuesta_humana_en = COALESCE(primera_respuesta_humana_en, (
                SELECT MIN(m.creado_en) FROM whatsapp_mensajes m
                WHERE m.conversacion_id = c.id AND m.direccion = 'saliente' AND m.respuesta_automatica = 0
            ))");

        $markMigration = $pdo->prepare("INSERT IGNORE INTO iphci_schema_migrations (clave) VALUES (:clave)");
        $markMigration->execute([':clave' => $migrationKey]);

        $done = true;
    } catch (Throwable $e) {
        error_log('No se pudo verificar el esquema de WhatsApp: ' . $e->getMessage());
    }
}

function whatsapp_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla AND COLUMN_NAME = :columna");
    $stmt->execute([':tabla' => $table, ':columna' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function whatsapp_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!whatsapp_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function whatsapp_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla AND INDEX_NAME = :indice");
    $stmt->execute([':tabla' => $table, ':indice' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function whatsapp_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!whatsapp_index_exists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    }
}
