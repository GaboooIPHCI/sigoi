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

    fwrite(
        STDOUT,
        sprintf(
            "[%s] Messenger: procesados=%d errores=%d pendientes=%d\n",
            date('Y-m-d H:i:s'),
            (int)$result['processed'],
            (int)$result['failed'],
            (int)$result['pending']
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
