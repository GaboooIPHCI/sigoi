<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/queue-worker-lib.php';

try {
    $result = wa_queue_process_pending($pdo, 100);

    $heartbeatDir = dirname(__DIR__, 2) . '/storage/whatsapp_queue';
    if (!is_dir($heartbeatDir)) @mkdir($heartbeatDir, 0750, true);
    @file_put_contents(
        $heartbeatDir . '/cron-heartbeat.json',
        json_encode([
            'ran_at' => date('c'),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'WhatsApp queue cron: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
