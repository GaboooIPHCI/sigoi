<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/messenger_schema.php';

$privateConfig = __DIR__ . '/../../config/messenger_meta.php';

if (is_file($privateConfig)) {
    require_once $privateConfig;
}

function messenger_webhook_config(string $name): string
{
    return defined($name)
        ? trim((string)constant($name))
        : '';
}

function messenger_webhook_signature_valid(
    string $raw,
    string $secret
): bool {
    if ($secret === '') {
        return false;
    }

    $signature = trim(
        (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')
    );

    if (
        $signature === ''
        || strpos($signature, 'sha256=') !== 0
    ) {
        return false;
    }

    $received = substr($signature, 7);
    $expected = hash_hmac('sha256', $raw, $secret);

    return hash_equals($expected, $received);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string)(
        $_GET['hub_verify_token']
        ?? $_GET['hub.verify_token']
        ?? ''
    );
    $challenge = (string)(
        $_GET['hub_challenge']
        ?? $_GET['hub.challenge']
        ?? ''
    );

    if (
        $mode === 'subscribe'
        && $verifyToken !== ''
        && hash_equals(
            messenger_webhook_config('MESSENGER_META_VERIFY_TOKEN'),
            $verifyToken
        )
    ) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Verification failed';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input') ?: '';

$appSecret = messenger_webhook_config('MESSENGER_META_APP_SECRET');

if (!messenger_webhook_signature_valid($raw, $appSecret)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
    exit;
}

try {
    global $pdo;

    messenger_ensure_schema($pdo);

    $payload = json_decode(
        $raw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (
        !is_array($payload)
        || (string)($payload['object'] ?? '') !== 'page'
    ) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    $insert = $pdo->prepare("
        INSERT IGNORE INTO messenger_eventos (
            event_key,
            payload,
            estado
        )
        VALUES (
            :event_key,
            :payload,
            'pendiente'
        )
    ");

    foreach ((array)($payload['entry'] ?? []) as $entryIndex => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach ((array)($entry['messaging'] ?? []) as $eventIndex => $event) {
            if (!is_array($event)) {
                continue;
            }

            $mid = trim((string)($event['message']['mid'] ?? ''));

            if ($mid !== '') {
                $eventKey = 'mid:' . $mid;
            } else {
                $eventKey = 'evt:' . hash(
                    'sha256',
                    json_encode([
                        'entry' => $entry['id'] ?? '',
                        'time' => $entry['time'] ?? '',
                        'event_index' => $eventIndex,
                        'event' => $event,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            $insert->execute([
                ':event_key' => $eventKey,
                ':payload' => json_encode(
                    $event,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);
        }
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    error_log('Messenger webhook: ' . $e->getMessage());

    /*
     * Meta reintentará cuando no recibe 2xx. Aquí devolvemos 500 solo
     * cuando realmente no pudimos guardar el evento.
     */
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
}
