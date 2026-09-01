<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
auth_require_whatsapp_permission('bandeja_ver');
auth_require_whatsapp_channel('instagram');

try {
    global $pdo;
    $result = ig_queue_process_pending($pdo, 25);
    instagram_json(true, 'Sincronización de Instagram ejecutada.', ['queue' => $result]);
} catch (Throwable $e) {
    error_log('Instagram queue-process: ' . $e->getMessage());
    instagram_json(false, 'No se pudo procesar la cola de Instagram.', [], 500);
}
