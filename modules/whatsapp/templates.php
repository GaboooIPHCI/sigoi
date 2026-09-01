<?php
require_once __DIR__ . '/_template_helpers.php';

function wa_templates_request_text_definition(array $data): array
{
    $body = whatsapp_clean_text($data['body'] ?? '', 1024);
    $footer = whatsapp_clean_text($data['footer'] ?? '', 60);
    $examples = $data['examples'] ?? [];
    $examples = is_array($examples)
        ? array_values(array_map(fn($v) => whatsapp_clean_text($v, 120), $examples))
        : [];

    if ($body === '') {
        whatsapp_json(false, 'Escribe el contenido de la plantilla.', [], 422);
    }

    try {
        $variables = wa_templates_validate_variables($body);
    } catch (RuntimeException $e) {
        whatsapp_json(false, $e->getMessage(), [], 422);
    }

    if (count($examples) < count($variables)) {
        whatsapp_json(false, 'Completa un ejemplo para cada variable de la plantilla.', [], 422);
    }

    $examples = array_slice($examples, 0, count($variables));
    foreach ($examples as $index => $example) {
        if ($example === '') {
            whatsapp_json(false, 'El ejemplo de {{' . ($index + 1) . '}} no puede quedar vacío.', [], 422);
        }
    }

    $bodyComponent = [
        'type' => 'BODY',
        'text' => $body,
    ];
    if ($variables) {
        $bodyComponent['example'] = ['body_text' => [$examples]];
    }

    $components = [$bodyComponent];
    if ($footer !== '') {
        $components[] = ['type' => 'FOOTER', 'text' => $footer];
    }

    return [
        'body' => $body,
        'footer' => $footer,
        'examples' => $examples,
        'variables' => $variables,
        'components' => $components,
    ];
}

try {
    global $pdo;
    wa_templates_ensure_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        auth_require_whatsapp_any(['plantillas_ver', 'bandeja_responder']);

        $canViewLibrary = auth_can_whatsapp('plantillas_ver');
        $status = $canViewLibrary
            ? whatsapp_clean_text($_GET['status'] ?? '', 120)
            : 'APPROVED';

        $result = wa_templates_list($status !== '' ? $status : null);
        if (!($result['ok'] ?? false)) {
            whatsapp_json(false, (string)($result['error'] ?? 'No se pudieron consultar las plantillas.'), [], 502);
        }
        $templates = $result['templates'] ?? [];

        // Si el usuario solo puede responder desde Bandeja, no exponemos
        // plantillas pendientes/rechazadas ni controles administrativos.
        if (!$canViewLibrary) {
            $templates = array_values(array_filter(
                $templates,
                static function ($template) {
                    return strtoupper((string)($template['status'] ?? '')) === 'APPROVED'
                        && ($template['send_supported'] ?? true) !== false;
                }
            ));
        }

        $counts = [];
        foreach ($templates as $template) {
            $key = strtoupper((string)($template['status'] ?? 'UNKNOWN')) ?: 'UNKNOWN';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        whatsapp_json(true, '', [
            'plantillas' => $templates,
            'total' => $canViewLibrary ? (int)($result['total'] ?? count($templates)) : count($templates),
            'estados' => $counts,
            'waba_id' => wa_templates_waba_id(),
        ]);
    }

    auth_require_whatsapp_permission('plantillas_gestionar');
    auth_validate_csrf();
    $data = whatsapp_request();
    $action = strtolower(whatsapp_clean_text($data['action'] ?? 'create', 30));

    $wabaId = wa_templates_waba_id();
    if ($wabaId === '') {
        whatsapp_json(false, 'Falta configurar el WABA ID de WhatsApp.', [], 500);
    }

    if ($action === 'delete') {
        $name = wa_templates_slug(whatsapp_clean_text($data['name'] ?? '', 512));
        $language = whatsapp_clean_text($data['language'] ?? '', 32);
        if ($name === '' || $language === '') {
            whatsapp_json(false, 'Selecciona una plantilla válida para eliminar.', [], 422);
        }

        $result = wa_templates_ycloud_request(
            'DELETE',
            '/v2/whatsapp/templates/' . rawurlencode($wabaId) . '/' . rawurlencode($name) . '/' . rawurlencode($language)
        );
        if (!($result['ok'] ?? false)) {
            whatsapp_json(false, (string)($result['error'] ?? 'YCloud no pudo eliminar la plantilla.'), [
                'ycloud' => $result['response'] ?? null,
            ], (int)($result['http_code'] ?? 502) === 404 ? 404 : 502);
        }

        try {
            wa_templates_delete_variable_modes($pdo, $name, $language);
        } catch (Throwable $localConfigError) {
            error_log('WhatsApp templates local config delete: ' . $localConfigError->getMessage());
        }

        auth_audit(
            $pdo,
            'whatsapp_plantilla_eliminada',
            auth_user_id(),
            auth_username(),
            json_encode(['name' => $name, 'language' => $language], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
        );

        whatsapp_json(true, 'Plantilla eliminada de WhatsApp. Recuerda que Meta puede bloquear la reutilización del mismo nombre durante 30 días.');
    }

    if ($action === 'edit') {
        $name = wa_templates_slug(whatsapp_clean_text($data['name'] ?? '', 512));
        $language = whatsapp_clean_text($data['language'] ?? '', 32);
        if ($name === '' || $language === '') {
            whatsapp_json(false, 'Selecciona una plantilla válida para editar.', [], 422);
        }

        $detail = wa_templates_retrieve($name, $language);
        if (!($detail['ok'] ?? false)) {
            whatsapp_json(false, (string)($detail['error'] ?? 'No se pudo consultar la plantilla antes de editarla.'), [], 502);
        }

        $rawTemplate = $detail['data'] ?? [];
        if (!is_array($rawTemplate)) $rawTemplate = [];
        $current = wa_templates_normalize_template($rawTemplate);
        $status = strtoupper((string)($current['status'] ?? ''));

        if (!wa_templates_editable_status($status)) {
            $message = $status === 'PENDING'
                ? 'Meta no permite editar una plantilla mientras está Pendiente. Puedes esperar la revisión o eliminarla y crear otra con un nombre diferente.'
                : 'La plantilla no puede editarse en su estado actual: ' . (string)($current['status'] ?: 'sin estado') . '.';
            whatsapp_json(false, $message, [], 409);
        }

        if (!wa_templates_supported_for_simple_editor($rawTemplate)) {
            whatsapp_json(false, 'Esta plantilla incluye componentes avanzados. Para evitar perder botones, encabezados u otros elementos, S.I.G.O.I. no la editará desde este formulario simple.', [], 409);
        }

        $definition = wa_templates_request_text_definition($data);
        $result = wa_templates_ycloud_request(
            'PATCH',
            '/v2/whatsapp/templates/' . rawurlencode($wabaId) . '/' . rawurlencode($name) . '/' . rawurlencode($language),
            ['components' => $definition['components']]
        );
        if (!($result['ok'] ?? false)) {
            whatsapp_json(false, (string)($result['error'] ?? 'YCloud no pudo actualizar la plantilla.'), [
                'ycloud' => $result['response'] ?? null,
            ], 502);
        }

        $variableModes = $data['variable_modes'] ?? [];
        $variableModes = is_array($variableModes) ? $variableModes : [];
        try {
            wa_templates_save_variable_modes(
                $pdo,
                $name,
                $language,
                $definition['variables'],
                $variableModes,
                auth_user_id()
            );
        } catch (Throwable $localConfigError) {
            error_log('WhatsApp templates local config edit: ' . $localConfigError->getMessage());
        }

        auth_audit(
            $pdo,
            'whatsapp_plantilla_editada',
            auth_user_id(),
            auth_username(),
            json_encode([
                'name' => $name,
                'language' => $language,
                'status_anterior' => $status,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
        );

        $updated = $result['data'] ?? [];
        whatsapp_json(true, 'Cambios enviados a WhatsApp. Actualiza el estado después para ver el resultado de Meta.', [
            'plantilla' => is_array($updated) && $updated ? wa_templates_normalize_template($updated) : null,
        ]);
    }

    if ($action !== 'create') {
        whatsapp_json(false, 'Acción no reconocida.', [], 422);
    }

    $name = wa_templates_slug(whatsapp_clean_text($data['name'] ?? '', 512));
    $language = whatsapp_clean_text($data['language'] ?? (defined('WHATSAPP_YCLOUD_TEMPLATE_DEFAULT_LANGUAGE') ? WHATSAPP_YCLOUD_TEMPLATE_DEFAULT_LANGUAGE : 'es'), 32);
    $category = strtoupper(whatsapp_clean_text($data['category'] ?? 'UTILITY', 32));

    if ($name === '' || !preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
        whatsapp_json(false, 'El nombre de la plantilla solo puede usar minúsculas, números y guion bajo.', [], 422);
    }
    if (!in_array($category, ['UTILITY', 'MARKETING'], true)) {
        whatsapp_json(false, 'Selecciona una categoría válida: Utilidad o Marketing.', [], 422);
    }
    if ($language === '') {
        whatsapp_json(false, 'Selecciona un idioma.', [], 422);
    }

    $definition = wa_templates_request_text_definition($data);
    $payload = [
        'wabaId' => $wabaId,
        'name' => $name,
        'language' => $language,
        'category' => $category,
        'components' => $definition['components'],
    ];

    $result = wa_templates_ycloud_request('POST', '/v2/whatsapp/templates', $payload);
    if (!($result['ok'] ?? false)) {
        whatsapp_json(false, (string)($result['error'] ?? 'YCloud no pudo crear la plantilla.'), [
            'ycloud' => $result['response'] ?? null,
        ], 502);
    }

    $variableModes = $data['variable_modes'] ?? [];
    $variableModes = is_array($variableModes) ? $variableModes : [];
    try {
        wa_templates_save_variable_modes(
            $pdo,
            $name,
            $language,
            $definition['variables'],
            $variableModes,
            auth_user_id()
        );
    } catch (Throwable $localConfigError) {
        error_log('WhatsApp templates local config create: ' . $localConfigError->getMessage());
    }

    auth_audit(
        $pdo,
        'whatsapp_plantilla_creada',
        auth_user_id(),
        auth_username(),
        json_encode(['name' => $name, 'language' => $language, 'category' => $category], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null
    );

    $created = $result['data'] ?? [];
    whatsapp_json(true, 'Plantilla enviada a WhatsApp para revisión.', [
        'plantilla' => is_array($created) ? wa_templates_normalize_template($created) : null,
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp templates: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo completar la operación de plantillas.', [], 500);
}
