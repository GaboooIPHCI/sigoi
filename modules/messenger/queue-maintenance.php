<?php

declare(strict_types=1);

function messenger_retry_delay_seconds_rc5(int $attempts): int
{
    if ($attempts <= 0) return 0;
    if ($attempts === 1) return 60;
    if ($attempts === 2) return 180;
    if ($attempts === 3) return 600;
    return 1800;
}

function messenger_process_queue_rc5(PDO $pdo, int $limit = 25): array
{
    messenger_ensure_schema($pdo);
    $limit = max(1, min(100, $limit));

    $pdo->exec("UPDATE messenger_eventos
        SET estado = 'pendiente'
        WHERE estado = 'procesando'
          AND actualizado_en < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    /*
     * Backoff sin columnas nuevas. actualizado_en guarda el instante del último
     * intento y el CASE decide cuándo el evento vuelve a ser elegible.
     */
    $stmt = $pdo->query("SELECT id, payload, intentos
        FROM messenger_eventos
        WHERE estado IN ('pendiente','error')
          AND intentos < 5
          AND (
              intentos = 0
              OR (intentos = 1 AND actualizado_en <= DATE_SUB(NOW(), INTERVAL 1 MINUTE))
              OR (intentos = 2 AND actualizado_en <= DATE_SUB(NOW(), INTERVAL 3 MINUTE))
              OR (intentos = 3 AND actualizado_en <= DATE_SUB(NOW(), INTERVAL 10 MINUTE))
              OR (intentos >= 4 AND actualizado_en <= DATE_SUB(NOW(), INTERVAL 30 MINUTE))
          )
        ORDER BY id ASC
        LIMIT " . (int)$limit);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $processed = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $claim = $pdo->prepare("UPDATE messenger_eventos
            SET estado = 'procesando', intentos = intentos + 1, error = NULL
            WHERE id = :id AND estado IN ('pendiente','error')");
        $claim->execute([':id' => $id]);
        if ($claim->rowCount() <= 0) continue;

        try {
            $event = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($event)) throw new RuntimeException('Evento Messenger inválido.');
            messenger_process_event($pdo, $event);
            $pdo->prepare("UPDATE messenger_eventos
                SET estado = 'procesado', procesado_en = NOW(), error = NULL
                WHERE id = :id")->execute([':id' => $id]);
            $processed++;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE messenger_eventos
                SET estado = 'error', error = :error
                WHERE id = :id")->execute([
                    ':error' => messenger_clean_text($e->getMessage(), 4000),
                    ':id' => $id,
                ]);
            error_log('Messenger queue #' . $id . ': ' . $e->getMessage());
            $failed++;
        }
    }

    $pdo->exec("DELETE FROM messenger_outbox_media WHERE expira_en < NOW()");
    $cleanProcessed = $pdo->exec("DELETE FROM messenger_eventos
        WHERE estado = 'procesado'
          AND procesado_en < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $cleanFailed = $pdo->exec("DELETE FROM messenger_eventos
        WHERE estado = 'error'
          AND intentos >= 5
          AND actualizado_en < DATE_SUB(NOW(), INTERVAL 60 DAY)");

    $pending = (int)$pdo->query("SELECT COUNT(*) FROM messenger_eventos
        WHERE estado IN ('pendiente','error') AND intentos < 5")->fetchColumn();

    return [
        'processed' => $processed,
        'failed' => $failed,
        'pending' => $pending,
        'cleaned_processed' => (int)$cleanProcessed,
        'cleaned_failed' => (int)$cleanFailed,
    ];
}

function messenger_history_sync_state_file_rc5(): string
{
    return messenger_storage_root() . '/history-sync-state.json';
}

function messenger_history_sync_state_read_rc5(): array
{
    $file = messenger_history_sync_state_file_rc5();
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function messenger_history_sync_state_write_rc5(array $state): void
{
    $root = messenger_storage_root();
    if (!is_dir($root)) @mkdir($root, 0750, true);
    @file_put_contents(
        messenger_history_sync_state_file_rc5(),
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function messenger_sync_recent_histories_rc5(PDO $pdo, int $conversationLimit = 5): array
{
    messenger_ensure_schema($pdo);
    $conversationLimit = max(1, min(10, $conversationLimit));
    $rows = $pdo->query("SELECT * FROM messenger_conversaciones
        WHERE ultimo_mensaje_en >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY ultimo_mensaje_en DESC
        LIMIT " . (int)$conversationLimit)->fetchAll(PDO::FETCH_ASSOC);

    $state = messenger_history_sync_state_read_rc5();
    $now = time();
    $synced = 0;
    $imported = 0;
    $errors = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $last = (int)($state[(string)$id] ?? 0);
        if ($last > 0 && ($now - $last) < 300) {
            $skipped++;
            continue;
        }

        $state[(string)$id] = $now;
        $result = messenger_sync_conversation_history($pdo, $row, 20);
        if ($result['ok'] ?? false) {
            $synced++;
            $imported += (int)($result['imported'] ?? 0);
        } else {
            $errors++;
            error_log('Messenger history sync: ' . (string)($result['error'] ?? 'Error desconocido'));
        }
    }

    foreach ($state as $id => $timestamp) {
        if ((int)$timestamp < $now - 7 * 86400) unset($state[$id]);
    }
    messenger_history_sync_state_write_rc5($state);

    return ['synced' => $synced, 'imported' => $imported, 'errors' => $errors, 'skipped' => $skipped];
}
