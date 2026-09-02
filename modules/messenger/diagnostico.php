<?php

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

auth_require_whatsapp_permission('bandeja_ver');

try {
    global $pdo;

    messenger_ensure_schema($pdo);
    messenger_require_channel_permission($pdo);

    $connection = null;

    if (
        messenger_page_id() !== ''
        && messenger_page_token() !== ''
        && messenger_api_version() !== ''
    ) {
        $connection = messenger_graph_request(
            rawurlencode(messenger_page_id())
            . '?fields=id,name',
            'GET',
            null,
            10
        );
    }

    messenger_json(true, '', [
        'receive_configured' => messenger_receive_configured(),
        'send_enabled' => messenger_send_enabled(),
        'page_id' => messenger_page_id(),
        'api_version' => messenger_api_version(),
        'public_base_url' => messenger_public_base_url(),
        'webhook_url' => messenger_public_base_url() !== ''
            ? messenger_public_base_url()
                . '/modules/messenger/webhook.php'
            : null,
        'connection' => $connection,
        'pending_events' => (int)$pdo->query("
            SELECT COUNT(*)
            FROM messenger_eventos
            WHERE estado IN ('pendiente','error')
        ")->fetchColumn(),
        'conversations' => (int)$pdo->query("
            SELECT COUNT(*)
            FROM messenger_conversaciones
        ")->fetchColumn(),
        'messages' => (int)$pdo->query("
            SELECT COUNT(*)
            FROM messenger_mensajes
        ")->fetchColumn(),
    ]);

} catch (Throwable $e) {
    error_log('Messenger diagnostico: ' . $e->getMessage());

    messenger_json(
        false,
        'No se pudo ejecutar el diagnóstico de Messenger.',
        [],
        500
    );
}
