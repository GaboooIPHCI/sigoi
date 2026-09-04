<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/queue-worker-lib.php';
require_once __DIR__ . '/queue-maintenance.php';

function messenger_cron_write_heartbeat(array $payload): void
{
    $root = messenger_storage_root();
    if (!is_dir($root)) @mkdir($root, 0750, true);
    @file_put_contents(
        $root . '/cron-heartbeat.json',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @chmod($root . '/cron-heartbeat.json', 0640);
}

try {
    global $pdo;
    $result = messenger_process_queue_rc5($pdo, 50);
    $history = messenger_sync_recent_histories_rc5($pdo, 5);

    messenger_cron_write_heartbeat([
        'ran_at' => date('c'),
        'ok' => true,
        'result' => [
            'queue' => $result,
            'history' => $history,
        ],
    ]);

    fwrite(STDOUT, sprintf(
        "[%s] Messenger: procesados=%d errores=%d pendientes=%d sync=%d omitidos=%d importados=%d sync_errores=%d limpieza=%d\n",
        date('Y-m-d H:i:s'),
        (int)$result['processed'],
        (int)$result['failed'],
        (int)$result['pending'],
        (int)$history['synced'],
        (int)$history['skipped'],
        (int)$history['imported'],
        (int)$history['errors'],
        (int)$result['cleaned_processed'] + (int)$result['cleaned_failed']
    ));
    exit(0);
} catch (Throwable $e) {
    messenger_cron_write_heartbeat([
        'ran_at' => date('c'),
        'ok' => false,
        'result' => [],
    ]);
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Messenger cron: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
