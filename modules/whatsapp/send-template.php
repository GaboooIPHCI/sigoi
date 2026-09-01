<?php
require_once __DIR__ . '/_template_helpers.php';
auth_require_whatsapp_permission('bandeja_responder');
auth_require_whatsapp_channel('whatsapp');
auth_validate_csrf();

try {
    global $pdo;
    wa_templates_ensure_schema($pdo);

    $data = whatsapp_request();
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $name = wa_templates_slug(whatsapp_clean_text($data['name'] ?? '', 512));
    $language = whatsapp_clean_text($data['language'] ?? 'es', 32);
    $values = $data['variables'] ?? [];
    $values = is_array($values) ? array_values(array_map(fn($v) => whatsapp_clean_text($v, 500), $values)) : [];

    if ($conversationId <= 0 || $name === '' || $language === '') {
        whatsapp_json(false, 'Selecciona una plantilla válida.', [], 422);
    }

    $conversation = wa_inbox_conversation_row($pdo, $conversationId);
    if (!$conversation) {
        whatsapp_json(false, 'La conversación ya no existe.', [], 404);
    }

    $detail = wa_templates_retrieve($name, $language);
    if (!($detail['ok'] ?? false)) {
        whatsapp_json(false, (string)($detail['error'] ?? 'No se pudo validar la plantilla en WhatsApp.'), [], 502);
    }
    $rawTemplate = $detail['data'] ?? [];
    if (!is_array($rawTemplate)) $rawTemplate = [];
    $template = wa_templates_normalize_template($rawTemplate);

    if (strtoupper((string)$template['status']) !== 'APPROVED') {
        whatsapp_json(false, 'Esta plantilla todavía no está aprobada por WhatsApp.', [], 409);
    }

    $body = (string)$template['body'];
    $numbers = wa_templates_validate_variables($body);
    if (count($values) !== count($numbers)) {
        whatsapp_json(false, 'Completa todos los campos personalizables de la plantilla.', [], 422);
    }

    // La plantilla conserva {{1}}, {{2}}, etc. en Meta. Justo antes del envío,
    // S.I.G.O.I. reemplaza únicamente las variables configuradas como automáticas.
    $values = wa_templates_apply_automatic_variables($template, $values);

    foreach ($values as $index => $value) {
        if ($value === '') whatsapp_json(false, 'El valor de {{' . ($index + 1) . '}} no puede quedar vacío.', [], 422);
    }

    $components = [];
    if ($numbers) {
        $components[] = [
            'type' => 'body',
            'parameters' => array_map(fn($value) => ['type' => 'text', 'text' => $value], $values),
        ];
    }

    $payload = [
        'from' => wa_auto_e164((string)WHATSAPP_YCLOUD_SENDER),
        'to' => wa_auto_e164((string)$conversation['telefono']),
        'type' => 'template',
        'template' => [
            'name' => $name,
            'language' => [
                'code' => $language,
                'policy' => 'deterministic',
            ],
        ],
    ];
    if ($components) $payload['template']['components'] = $components;

    $externalId = wa_auto_external_id('template', $conversationId);
    $payload['externalId'] = $externalId;
    $send = wa_auto_send_payload($payload, true);

    $renderedBody = wa_templates_render_body($body, $values);
    if ((string)$template['footer'] !== '') {
        $renderedBody .= "\n\n" . (string)$template['footer'];
    }

    $messageId = wa_auto_store_outgoing(
        $pdo,
        $conversationId,
        $renderedBody,
        null,
        $send,
        'sigoi',
        auth_user_id(),
        ['type' => 'template'],
        $externalId
    );

    $log = $pdo->prepare("INSERT INTO whatsapp_plantilla_envios
        (mensaje_id, conversacion_id, plantilla_nombre, plantilla_idioma, plantilla_categoria, variables_json, cuerpo_renderizado, creado_por)
        VALUES (:mensaje, :conversacion, :nombre, :idioma, :categoria, :variables, :cuerpo, :usuario)");
    $log->execute([
        ':mensaje' => $messageId,
        ':conversacion' => $conversationId,
        ':nombre' => $name,
        ':idioma' => $language,
        ':categoria' => (string)$template['category'] ?: null,
        ':variables' => $values ? (json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null) : null,
        ':cuerpo' => $renderedBody,
        ':usuario' => auth_user_id(),
    ]);

    if (!($send['ok'] ?? false)) {
        whatsapp_json(false, (string)($send['error'] ?? 'No se pudo enviar la plantilla.'), ['message_id' => $messageId], 502);
    }

    auth_audit(
        $pdo,
        'whatsapp_plantilla_enviada',
        auth_user_id(),
        auth_username(),
        json_encode([
            'conversacion_id' => $conversationId,
            'mensaje_id' => $messageId,
            'plantilla' => $name,
            'idioma' => $language,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    whatsapp_json(true, 'Plantilla enviada. Cuando el paciente responda, se abrirá una nueva ventana de 24 h.', [
        'message_id' => $messageId,
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp send-template: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo enviar la plantilla.', [], 500);
}
