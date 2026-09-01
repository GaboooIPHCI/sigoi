<?php

declare(strict_types=1);

/**
 * Esquema idempotente para la integración de Instagram en S.I.G.O.I.
 *
 * Instagram mantiene sus tablas separadas de WhatsApp. La bandeja multicanal
 * combina los datos en la capa de aplicación, sin mezclar identificadores.
 */
function instagram_schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function instagram_schema_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!instagram_schema_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function instagram_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_conversaciones (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cuenta_ig_id VARCHAR(80) NOT NULL,
        igsid VARCHAR(100) NOT NULL,
        nombre_contacto VARCHAR(160) DEFAULT NULL,
        nombre_personalizado VARCHAR(160) DEFAULT NULL,
        username VARCHAR(160) DEFAULT NULL,
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
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_instagram_conversacion (cuenta_ig_id, igsid),
        KEY idx_instagram_conv_estado (estado, no_leidos),
        KEY idx_instagram_conv_ultimo (ultimo_mensaje_en),
        KEY idx_instagram_conv_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_mensajes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        conversacion_id BIGINT UNSIGNED NOT NULL,
        mid VARCHAR(255) NOT NULL,
        direccion ENUM('entrante','saliente') NOT NULL,
        origen VARCHAR(40) NOT NULL DEFAULT 'cliente',
        usuario_id INT UNSIGNED DEFAULT NULL,
        tipo VARCHAR(40) NOT NULL DEFAULT 'texto',
        contenido TEXT DEFAULT NULL,
        media_url TEXT DEFAULT NULL,
        media_path TEXT DEFAULT NULL,
        media_mime VARCHAR(160) DEFAULT NULL,
        media_filename VARCHAR(255) DEFAULT NULL,
        media_size BIGINT UNSIGNED DEFAULT NULL,
        reply_to_mid VARCHAR(255) DEFAULT NULL,
        estado_envio VARCHAR(30) DEFAULT NULL,
        reaccion VARCHAR(40) DEFAULT NULL,
        reaccion_emoji VARCHAR(40) DEFAULT NULL,
        editado_veces SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        editado_en DATETIME DEFAULT NULL,
        leido_en DATETIME DEFAULT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_instagram_mensaje_mid (mid),
        KEY idx_instagram_msg_conv (conversacion_id, creado_en),
        KEY idx_instagram_msg_estado (estado_envio),
        CONSTRAINT fk_instagram_msg_conv
            FOREIGN KEY (conversacion_id) REFERENCES instagram_conversaciones(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Compatibilidad con instalaciones de la Fase 2 ya creadas.
    instagram_schema_add_column($pdo, 'instagram_conversaciones', 'nombre_personalizado', 'VARCHAR(160) DEFAULT NULL AFTER nombre_contacto');
    instagram_schema_add_column($pdo, 'instagram_conversaciones', 'notas_contacto', 'TEXT DEFAULT NULL AFTER foto_perfil_url');
    instagram_schema_add_column($pdo, 'instagram_conversaciones', 'requiere_humano', "TINYINT(1) NOT NULL DEFAULT 1 AFTER estado");
    instagram_schema_add_column($pdo, 'instagram_mensajes', 'usuario_id', 'INT UNSIGNED DEFAULT NULL AFTER origen');
    instagram_schema_add_column($pdo, 'instagram_mensajes', 'media_path', 'TEXT DEFAULT NULL AFTER media_url');
    instagram_schema_add_column($pdo, 'instagram_mensajes', 'media_mime', 'VARCHAR(160) DEFAULT NULL AFTER media_path');
    instagram_schema_add_column($pdo, 'instagram_mensajes', 'media_filename', 'VARCHAR(255) DEFAULT NULL AFTER media_mime');
    instagram_schema_add_column($pdo, 'instagram_mensajes', 'media_size', 'BIGINT UNSIGNED DEFAULT NULL AFTER media_filename');

    $done = true;
}
