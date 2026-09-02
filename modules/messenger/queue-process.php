<?php

declare(strict_types=1);

require_once __DIR__ . '/queue-worker-lib.php';

auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;

    messenger_require_channel_permission($pdo);

    $result = messenger_process_queue($pdo, 25);

    messenger_json(true, '', $result);

} catch (Throwable $e) {
    error_log('Messenger queue-process: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo procesar la cola de Messenger.',
        [],
        500
    );
}
