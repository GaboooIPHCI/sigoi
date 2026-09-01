<?php
declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
require_once __DIR__ . '/queue-worker-lib.php';
auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;
    $result = wa_queue_process_pending($pdo, 25);
    whatsapp_json(true, 'Sincronización ejecutada.', ['queue' => $result]);
} catch (Throwable $e) {
    error_log('WhatsApp queue-process: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo procesar la cola de WhatsApp.', [], 500);
}
