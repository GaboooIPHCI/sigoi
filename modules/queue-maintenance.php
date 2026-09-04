<?php

declare(strict_types=1);

/**
 * Utilidades de mantenimiento para las colas basadas en archivos.
 * No modifica el formato existente de los webhooks: solo respeta una espera
 * progresiva después de fallos y limpia diagnósticos muy antiguos.
 */
function sigoi_queue_retry_delay_seconds(int $attempts): int
{
    if ($attempts <= 0) return 0;
    if ($attempts === 1) return 60;
    if ($attempts === 2) return 180;
    if ($attempts === 3) return 600;
    return 1800;
}

function sigoi_queue_restore_deferred(string $root): int
{
    $deferred = rtrim($root, '/\\') . '/deferred';
    $pending = rtrim($root, '/\\') . '/pending';
    if (!is_dir($deferred)) return 0;
    if (!is_dir($pending)) @mkdir($pending, 0750, true);

    $restored = 0;
    foreach (glob($deferred . '/*.json') ?: [] as $file) {
        $target = $pending . '/' . basename($file);
        if (is_file($target)) {
            @unlink($file);
            continue;
        }
        if (@rename($file, $target)) $restored++;
    }
    return $restored;
}

function sigoi_queue_defer_not_due(string $root): int
{
    $root = rtrim($root, '/\\');
    $pending = $root . '/pending';
    $deferred = $root . '/deferred';
    if (!is_dir($pending)) return 0;
    if (!is_dir($deferred)) @mkdir($deferred, 0750, true);

    $now = time();
    $moved = 0;
    foreach (glob($pending . '/*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $wrapper = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($wrapper)) continue;

        $attempts = (int)($wrapper['attempts'] ?? 0);
        $lastAttempt = trim((string)($wrapper['last_attempt_at'] ?? ''));
        if ($attempts <= 0 || $lastAttempt === '') continue;

        $last = strtotime($lastAttempt);
        if ($last === false) continue;
        $dueAt = $last + sigoi_queue_retry_delay_seconds($attempts);
        if ($dueAt <= $now) continue;

        if (@rename($file, $deferred . '/' . basename($file))) $moved++;
    }
    return $moved;
}

function sigoi_queue_cleanup_old_failed(string $root, int $days = 60): int
{
    $failed = rtrim($root, '/\\') . '/failed';
    if (!is_dir($failed)) return 0;
    $threshold = time() - max(7, $days) * 86400;
    $removed = 0;
    foreach (glob($failed . '/*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $threshold && @unlink($file)) $removed++;
    }
    return $removed;
}

function sigoi_queue_pending_count(string $root): int
{
    return count(glob(rtrim($root, '/\\') . '/pending/*.json') ?: []);
}
