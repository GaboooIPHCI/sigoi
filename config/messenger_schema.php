<?php

declare(strict_types=1);

/**
 * Esquema idempotente para Messenger dentro de la bandeja multicanal.
 * No contiene credenciales.
 */

function messenger_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function messenger_schema_add_column(
    PDO $pdo,
    string $table,
    string $column,
    string $definition
): void {
    if (!messenger_schema_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function messenger_schema_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':index_name' => $index,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function messenger_schema_add_index(
    PDO $pdo,
    string $table,
    string $index,
    string $definition
): void {
    if (!messenger_schema_index_exists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    }
}

function messenger_ensure_permissions_schema(PDO $pdo): void
{
    if (function_exists('auth_whatsapp_ensure_permissions_schema')) {
        auth_whatsapp_ensure_permissions_schema($pdo);
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios_whatsapp_permisos'
    ");
    $stmt->execute();

    if ((int)$stmt->fetchColumn() <= 0) {
        return;
    }

    messenger_schema_add_column(
        $pdo,
        'usuarios_whatsapp_permisos',
        'canal_messenger',
        "TINYINT(1) NOT NULL DEFAULT 0 AFTER canal_instagram"
    );
}

function messenger_channel_permission_for_user(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $roleStmt = $pdo->prepare("
        SELECT rol
        FROM usuarios_sistema
        WHERE id = :id
        LIMIT 1
    ");
    $roleStmt->execute([':id' => $userId]);
    $role = (string)($roleStmt->fetchColumn() ?: '');

    if ($role === 'admin') {
        return true;
    }

    messenger_ensure_permissions_schema($pdo);

    if (!messenger_schema_column_exists(
        $pdo,
        'usuarios_whatsapp_permisos',
        'canal_messenger'
    )) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT canal_messenger
        FROM usuarios_whatsapp_permisos
        WHERE usuario_id = :usuario_id
        LIMIT 1
    ");
    $stmt->execute([':usuario_id' => $userId]);

    return (int)($stmt->fetchColumn() ?: 0) === 1;
}

function messenger_ensure_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messenger_conversaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id VARCHAR(80) NOT NULL,
            psid VARCHAR(120) NOT NULL,
            nombre_contacto VARCHAR(160) DEFAULT NULL,
            nombre_personalizado VARCHAR(160) DEFAULT NULL,
            foto_perfil_url TEXT DEFAULT NULL,
            notas_contacto TEXT DEFAULT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'abierta',
            requiere_humano TINYINT(1) NOT NULL DEFAULT 1,
            no_leidos INT UNSIGNED NOT NULL DEFAULT 0,
            asignado_usuario_id INT UNSIGNED DEFAULT NULL,
            primer_mensaje_en DATETIME DEFAULT NULL,
            ultimo_mensaje_en DATETIME DEFAULT NULL,
            ultimo_mensaje_direccion ENUM('entrante','saliente') DEFAULT NULL,
            ultimo_mensaje_tipo VARCHAR(40) DEFAULT NULL,
            ultimo_mensaje_preview VARCHAR(300) DEFAULT NULL,
            ultimo_mensaje_origen VARCHAR(40) DEFAULT NULL,
            ultimo_mensaje_id VARCHAR(255) DEFAULT NULL,
            resuelto_en DATETIME DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_messenger_conversacion (page_id, psid),
            KEY idx_messenger_conv_estado (estado, no_leidos),
            KEY idx_messenger_conv_ultimo (ultimo_mensaje_en),
            KEY idx_messenger_conv_psid (psid)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messenger_mensajes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversacion_id BIGINT UNSIGNED NOT NULL,
            mid VARCHAR(255) NOT NULL,
            direccion ENUM('entrante','saliente') NOT NULL,
            origen VARCHAR(40) NOT NULL DEFAULT 'cliente',
            usuario_id INT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(40) NOT NULL DEFAULT 'text',
            contenido TEXT DEFAULT NULL,
            media_url TEXT DEFAULT NULL,
            media_path TEXT DEFAULT NULL,
            media_mime VARCHAR(160) DEFAULT NULL,
            media_filename VARCHAR(255) DEFAULT NULL,
            media_size BIGINT UNSIGNED DEFAULT NULL,
            reply_to_mid VARCHAR(255) DEFAULT NULL,
            estado_envio VARCHAR(30) DEFAULT NULL,
            entregado_en DATETIME DEFAULT NULL,
            leido_en DATETIME DEFAULT NULL,
            error_envio TEXT DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_messenger_mensaje_mid (mid),
            KEY idx_messenger_msg_conv (conversacion_id, creado_en),
            KEY idx_messenger_msg_estado (estado_envio),
            KEY idx_messenger_msg_origen (origen, creado_en),
            CONSTRAINT fk_messenger_msg_conv
                FOREIGN KEY (conversacion_id)
                REFERENCES messenger_conversaciones(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messenger_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_key VARCHAR(190) NOT NULL,
            payload LONGTEXT NOT NULL,
            estado ENUM('pendiente','procesando','procesado','error')
                NOT NULL DEFAULT 'pendiente',
            intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            error TEXT DEFAULT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            procesado_en DATETIME DEFAULT NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_messenger_event_key (event_key),
            KEY idx_messenger_event_estado (estado, creado_en)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messenger_outbox_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(64) NOT NULL,
            media_path VARCHAR(600) NOT NULL,
            mime VARCHAR(160) DEFAULT NULL,
            filename VARCHAR(255) DEFAULT NULL,
            expira_en DATETIME NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_messenger_outbox_token (token),
            KEY idx_messenger_outbox_expira (expira_en)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    messenger_ensure_permissions_schema($pdo);

    $done = true;
}
