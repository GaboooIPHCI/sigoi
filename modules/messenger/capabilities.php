<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;

    messenger_ensure_schema($pdo);

    $allowed = messenger_channel_permission_for_user(
        $pdo,
        (int)auth_user_id()
    );

    messenger_json(true, '', [
        'allowed' => $allowed,
        'configured' => messenger_receive_configured(),
        'send_enabled' => messenger_send_enabled(),
        'page_id' => messenger_page_id(),
        'webhook_url' => messenger_public_base_url() !== ''
            ? messenger_public_base_url()
                . '/modules/messenger/webhook.php'
            : null,
    ]);

} catch (Throwable $e) {
    error_log('Messenger capabilities: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo comprobar Messenger.',
        [],
        500
    );
}
