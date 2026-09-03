<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/queue-worker-lib.php';
require_once __DIR__ . '/../queue-maintenance.php';

$queueRoot = wa_queue_root();
sigoi_queue_restore_deferred($queueRoot);
$deferred = sigoi_queue_defer_not_due($queueRoot);

$fatal = null;
try {
    $result = wa_queue_process_pending($pdo, 100);
    $result['deferred_backoff'] = $deferred;
    $result['failed_cleaned'] = sigoi_queue_cleanup_old_failed($queueRoot, 60);
} catch (Throwable $e) {
    $fatal = $e;
    $result = ['processed' => 0, 'failed' => 0, 'deferred_backoff' => $deferred];
} finally {
    sigoi_queue_restore_deferred($queueRoot);
}

$heartbeatDir = dirname(__DIR__, 2) . '/storage/whatsapp_queue';
if (!is_dir($heartbeatDir)) @mkdir($heartbeatDir, 0750, true);

if ($fatal) {
    @file_put_contents(
        $heartbeatDir . '/cron-heartbeat.json',
        json_encode(['ran_at' => date('c'), 'ok' => false, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    fwrite(STDERR, 'WhatsApp queue cron: ' . $fatal->getMessage() . PHP_EOL);
    exit(1);
}

$result['pending_total'] = sigoi_queue_pending_count($queueRoot);
@file_put_contents(
    $heartbeatDir . '/cron-heartbeat.json',
    json_encode(['ran_at' => date('c'), 'ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
