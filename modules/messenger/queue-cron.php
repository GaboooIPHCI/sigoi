<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/queue-worker-lib.php';

try {
    global $pdo;

    $result = messenger_process_queue($pdo, 50);
    $history = messenger_sync_recent_histories($pdo, 5);

    fwrite(
        STDOUT,
        sprintf(
            "[%s] Messenger: procesados=%d errores=%d pendientes=%d sync=%d importados=%d sync_errores=%d\n",
            date('Y-m-d H:i:s'),
            (int)$result['processed'],
            (int)$result['failed'],
            (int)$result['pending'],
            (int)$history['synced'],
            (int)$history['imported'],
            (int)$history['errors']
        )
    );

    exit(0);

} catch (Throwable $e) {
    fwrite(
        STDERR,
        '[' . date('Y-m-d H:i:s') . '] Messenger cron: '
        . $e->getMessage()
        . PHP_EOL
    );

    exit(1);
}
