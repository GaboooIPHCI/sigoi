<?php

declare(strict_types=1);

require_once __DIR__ . '/_inbox_helpers.php';
require_once __DIR__ . '/../../config/whatsapp_templates.php';

function wa_templates_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_respuestas_rapidas (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        titulo VARCHAR(120) NOT NULL,
        atajo VARCHAR(80) NOT NULL,
        categoria VARCHAR(80) NOT NULL DEFAULT 'General',
        contenido TEXT NOT NULL,
        activa TINYINT(1) NOT NULL DEFAULT 1,
        creado_por INT DEFAULT NULL,
        actualizado_por INT DEFAULT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_whatsapp_respuesta_atajo (atajo),
        KEY idx_whatsapp_respuestas_activas (activa, categoria, titulo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_plantilla_envios (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        mensaje_id BIGINT UNSIGNED NOT NULL,
        conversacion_id BIGINT UNSIGNED NOT NULL,
        plantilla_nombre VARCHAR(512) NOT NULL,
        plantilla_idioma VARCHAR(32) NOT NULL,
        plantilla_categoria VARCHAR(32) DEFAULT NULL,
        variables_json TEXT DEFAULT NULL,
        cuerpo_renderizado TEXT DEFAULT NULL,
        creado_por INT DEFAULT NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_whatsapp_plantilla_mensaje (mensaje_id),
        KEY idx_whatsapp_plantilla_conv (conversacion_id, creado_en),
        KEY idx_whatsapp_plantilla_nombre (plantilla_nombre, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_plantilla_variables (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        plantilla_nombre VARCHAR(512) NOT NULL,
        plantilla_idioma VARCHAR(32) NOT NULL,
        variable_numero SMALLINT UNSIGNED NOT NULL,
        modo VARCHAR(32) NOT NULL DEFAULT 'manual',
        actualizado_por INT DEFAULT NULL,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_whatsapp_plantilla_variable (plantilla_nombre, plantilla_idioma, variable_numero),
        KEY idx_whatsapp_plantilla_variables_nombre (plantilla_nombre, plantilla_idioma)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function wa_templates_waba_id(): string
{
    return defined('WHATSAPP_YCLOUD_WABA_ID') ? trim((string)WHATSAPP_YCLOUD_WABA_ID) : '';
}

function wa_templates_ycloud_request(string $method, string $path, ?array $payload = null, array $query = []): array
{
    if (!wa_auto_ycloud_is_configured()) {
        return ['ok' => false, 'error' => 'YCloud no está configurado para realizar esta operación.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL no está disponible en el servidor.'];
    }

    $url = 'https://api.ycloud.com' . $path;
    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = [
        'X-API-Key: ' . WHATSAPP_YCLOUD_API_KEY,
        'Accept: application/json',
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 22,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => $curlError ?: 'No se pudo conectar con YCloud.'];
    }

    $json = json_decode((string)$raw, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = 'YCloud rechazó la operación.';
        if (is_array($json)) {
            $candidate = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') $message = trim($candidate);
        }
        return [
            'ok' => false,
            'error' => $message,
            'http_code' => $httpCode,
            'response' => is_array($json) ? $json : null,
        ];
    }

    return ['ok' => true, 'data' => is_array($json) ? $json : [], 'http_code' => $httpCode];
}

function wa_templates_is_list(array $value): bool
{
    $i = 0;
    foreach ($value as $key => $_) {
        if ($key !== $i++) return false;
    }
    return true;
}

function wa_templates_extract_list(array $data): array
{
    if (wa_templates_is_list($data)) return $data;
    foreach (['items', 'data', 'results', 'templates'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return wa_templates_is_list($data[$key]) ? $data[$key] : [];
        }
    }
    return [];
}

function wa_templates_components(array $template): array
{
    $components = $template['components'] ?? [];
    return is_array($components) ? $components : [];
}

function wa_templates_component(array $template, string $type): ?array
{
    foreach (wa_templates_components($template) as $component) {
        if (!is_array($component)) continue;
        if (strtoupper((string)($component['type'] ?? '')) === strtoupper($type)) return $component;
    }
    return null;
}

function wa_templates_body_text(array $template): string
{
    $body = wa_templates_component($template, 'BODY');
    return $body ? trim((string)($body['text'] ?? '')) : '';
}

function wa_templates_footer_text(array $template): string
{
    $footer = wa_templates_component($template, 'FOOTER');
    return $footer ? trim((string)($footer['text'] ?? '')) : '';
}

function wa_templates_variable_numbers(string $text): array
{
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
    $numbers = array_map('intval', $matches[1] ?? []);
    $numbers = array_values(array_unique(array_filter($numbers, fn($n) => $n > 0)));
    sort($numbers);
    return $numbers;
}

function wa_templates_validate_variables(string $body): array
{
    $numbers = wa_templates_variable_numbers($body);
    if (!$numbers) return [];
    $max = max($numbers);
    $expected = range(1, $max);
    if ($numbers !== $expected) {
        throw new RuntimeException('Las variables deben ser correlativas: {{1}}, {{2}}, {{3}}... sin saltos.');
    }
    return $numbers;
}

function wa_templates_render_body(string $body, array $values): string
{
    foreach ($values as $index => $value) {
        $body = preg_replace('/\{\{\s*' . preg_quote((string)($index + 1), '/') . '\s*\}\}/', (string)$value, $body) ?? $body;
    }
    return $body;
}

/**
 * Devuelve el saludo institucional según la hora de Lima (America/Lima).
 *
 * 05:00 - 11:59 => Buenos días
 * 12:00 - 18:59 => Buenas tardes
 * 19:00 - 04:59 => Buenas noches
 *
 * El parámetro opcional permite reutilizar y probar la función con una hora fija.
 */
function wa_templates_get_greeting(?DateTimeInterface $now = null): string
{
    $timezone = new DateTimeZone('America/Lima');

    if ($now === null) {
        $limaNow = new DateTimeImmutable('now', $timezone);
    } else {
        $limaNow = DateTimeImmutable::createFromInterface($now)->setTimezone($timezone);
    }

    $hour = (int)$limaNow->format('G');

    if ($hour >= 5 && $hour <= 11) return 'Buenos días';
    if ($hour >= 12 && $hour <= 18) return 'Buenas tardes';
    return 'Buenas noches';
}

/**
 * Normaliza texto para comparar ejemplos sin depender de mayúsculas o tildes.
 */
function wa_templates_normalize_compare_text(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return strtr($value, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
    ]);
}

function wa_templates_is_greeting_example(string $value): bool
{
    return in_array(wa_templates_normalize_compare_text($value), [
        'buenos dias', 'buenas tardes', 'buenas noches',
    ], true);
}

/**
 * Modos admitidos por variable. Por ahora solo hay dos:
 * - manual: lo completa el usuario al enviar.
 * - greeting: S.I.G.O.I. calcula Buenos días/tardes/noches en America/Lima.
 */
function wa_templates_normalize_variable_modes(array $rawModes, array $variableNumbers): array
{
    $allowed = ['manual', 'greeting'];
    $normalized = [];

    foreach ($variableNumbers as $number) {
        $candidate = $rawModes[(string)$number] ?? $rawModes[$number] ?? 'manual';
        $candidate = strtolower(trim((string)$candidate));
        $normalized[(int)$number] = in_array($candidate, $allowed, true) ? $candidate : 'manual';
    }

    return $normalized;
}

function wa_templates_load_saved_variable_modes(PDO $pdo, string $templateName, string $language): array
{
    $stmt = $pdo->prepare("SELECT variable_numero, modo
        FROM whatsapp_plantilla_variables
        WHERE plantilla_nombre = :nombre AND plantilla_idioma = :idioma
        ORDER BY variable_numero ASC");
    $stmt->execute([
        ':nombre' => wa_templates_slug($templateName),
        ':idioma' => trim($language),
    ]);

    $modes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $number = (int)($row['variable_numero'] ?? 0);
        if ($number > 0) $modes[$number] = strtolower((string)($row['modo'] ?? 'manual'));
    }
    return $modes;
}

/**
 * Guarda la intención de cada variable localmente. Meta solo recibe {{1}}, {{2}}, etc.;
 * esta tabla indica cómo debe rellenarlas S.I.G.O.I. al momento de enviar.
 */
function wa_templates_save_variable_modes(
    PDO $pdo,
    string $templateName,
    string $language,
    array $variableNumbers,
    array $rawModes,
    ?int $userId = null
): void {
    $name = wa_templates_slug($templateName);
    $language = trim($language);
    $modes = wa_templates_normalize_variable_modes($rawModes, $variableNumbers);

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare("DELETE FROM whatsapp_plantilla_variables
            WHERE plantilla_nombre = :nombre AND plantilla_idioma = :idioma");
        $delete->execute([':nombre' => $name, ':idioma' => $language]);

        if ($variableNumbers) {
            $insert = $pdo->prepare("INSERT INTO whatsapp_plantilla_variables
                (plantilla_nombre, plantilla_idioma, variable_numero, modo, actualizado_por)
                VALUES (:nombre, :idioma, :numero, :modo, :usuario)");
            foreach ($variableNumbers as $number) {
                $insert->execute([
                    ':nombre' => $name,
                    ':idioma' => $language,
                    ':numero' => (int)$number,
                    ':modo' => (string)($modes[(int)$number] ?? 'manual'),
                    ':usuario' => $userId,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function wa_templates_delete_variable_modes(PDO $pdo, string $templateName, string $language): void
{
    $stmt = $pdo->prepare("DELETE FROM whatsapp_plantilla_variables
        WHERE plantilla_nombre = :nombre AND plantilla_idioma = :idioma");
    $stmt->execute([
        ':nombre' => wa_templates_slug($templateName),
        ':idioma' => trim($language),
    ]);
}

/**
 * Resuelve la configuración de las variables de una plantilla.
 *
 * Compatibilidad: las plantillas creadas antes de este cambio no tienen una
 * configuración local. En ese caso, si el ejemplo de una variable es
 * “Buenos días”, “Buenas tardes” o “Buenas noches”, se reconoce como saludo
 * automático. Esto hace que las plantillas actuales sigan funcionando sin
 * tener que recrearlas.
 */
function wa_templates_variable_modes(
    string $templateName,
    string $language,
    string $body,
    array $examples,
    ?PDO $pdo = null
): array {
    $numbers = wa_templates_variable_numbers($body);
    if (!$numbers) return [];

    if ($pdo instanceof PDO) {
        $saved = wa_templates_load_saved_variable_modes($pdo, $templateName, $language);
        if ($saved) return wa_templates_normalize_variable_modes($saved, $numbers);
    }

    $inferred = [];
    foreach ($numbers as $index => $number) {
        $example = (string)($examples[$index] ?? '');
        $inferred[$number] = wa_templates_is_greeting_example($example) ? 'greeting' : 'manual';
    }
    return $inferred;
}

function wa_templates_automatic_variables_from_modes(array $modes, ?DateTimeInterface $now = null): array
{
    $automatic = [];
    foreach ($modes as $number => $mode) {
        if ($mode !== 'greeting') continue;
        $automatic[(int)$number] = [
            'type' => 'greeting',
            'label' => 'Saludo automático',
            'value' => wa_templates_get_greeting($now),
            'timezone' => 'America/Lima',
        ];
    }
    return $automatic;
}

/**
 * Aplica únicamente las variables configuradas como automáticas.
 * Las variables manuales conservan exactamente el valor recibido del usuario.
 */
function wa_templates_apply_automatic_variables(array $template, array $values, ?DateTimeInterface $now = null): array
{
    $automatic = $template['automatic_variables'] ?? [];
    if (!is_array($automatic)) $automatic = [];

    foreach ($automatic as $number => $rule) {
        $index = (int)$number - 1;
        if ($index < 0 || $index >= count($values) || !is_array($rule)) continue;
        if (($rule['type'] ?? '') === 'greeting') {
            $values[$index] = wa_templates_get_greeting($now);
        }
    }

    return array_values($values);
}

function wa_templates_editable_status(string $status): bool
{
    return in_array(strtoupper(trim($status)), ['APPROVED', 'REJECTED', 'PAUSED'], true);
}

function wa_templates_deletable_status(string $status): bool
{
    $status = strtoupper(trim($status));
    return $status !== 'DELETED';
}

function wa_templates_supported_for_simple_editor(array $template): bool
{
    $body = wa_templates_body_text($template);
    if ($body === '') return false;

    foreach (wa_templates_components($template) as $component) {
        if (!is_array($component)) continue;
        $type = strtoupper((string)($component['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['BODY', 'FOOTER'], true)) return false;
    }

    return true;
}

function wa_templates_normalize_template(array $template): array
{
    $body = wa_templates_body_text($template);
    $footer = wa_templates_footer_text($template);
    $variables = wa_templates_variable_numbers($body);
    $examples = [];
    $unsupportedTypes = [];
    foreach (wa_templates_components($template) as $component) {
        if (!is_array($component)) continue;
        $type = strtoupper((string)($component['type'] ?? ''));
        if ($type !== '' && !in_array($type, ['BODY', 'FOOTER'], true)) $unsupportedTypes[] = $type;
    }
    $unsupportedTypes = array_values(array_unique($unsupportedTypes));
    $bodyComponent = wa_templates_component($template, 'BODY');
    if ($bodyComponent && isset($bodyComponent['example']['body_text'][0]) && is_array($bodyComponent['example']['body_text'][0])) {
        $examples = array_values(array_map('strval', $bodyComponent['example']['body_text'][0]));
    }

    $languageRaw = $template['language'] ?? '';
    $language = is_array($languageRaw) ? (string)($languageRaw['code'] ?? '') : (string)$languageRaw;
    $categoryRaw = $template['category'] ?? '';
    $category = is_array($categoryRaw) ? (string)($categoryRaw['name'] ?? '') : (string)$categoryRaw;
    $statusRaw = $template['status'] ?? '';
    $status = is_array($statusRaw) ? (string)($statusRaw['name'] ?? '') : (string)$statusRaw;

    global $pdo;
    $templatePdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
    $variableModes = wa_templates_variable_modes(
        (string)($template['name'] ?? ''),
        $language,
        $body,
        $examples,
        $templatePdo
    );
    $automaticVariables = wa_templates_automatic_variables_from_modes($variableModes);

    return [
        'id' => $template['id'] ?? $template['officialTemplateId'] ?? null,
        'name' => (string)($template['name'] ?? ''),
        'language' => $language,
        'category' => strtoupper($category),
        'status' => strtoupper($status),
        'body' => $body,
        'footer' => $footer,
        'variable_count' => count($variables),
        'examples' => $examples,
        'variable_modes' => $variableModes,
        'automatic_variables' => $automaticVariables,
        'quality' => $template['qualityRating'] ?? $template['quality'] ?? null,
        'rejected_reason' => $template['rejectedReason'] ?? $template['rejected_reason'] ?? null,
        'send_supported' => empty($unsupportedTypes) && $body !== '',
        'unsupported_reason' => $unsupportedTypes ? ('Incluye componentes no compatibles todavía: ' . implode(', ', $unsupportedTypes)) : ($body === '' ? 'No se encontró un cuerpo de texto compatible.' : null),
        'editable' => wa_templates_editable_status($status) && empty($unsupportedTypes) && $body !== '',
        'deletable' => wa_templates_deletable_status($status),
        'edit_block_reason' => !wa_templates_editable_status($status)
            ? ($status === 'PENDING' ? 'Meta no permite editar una plantilla mientras está pendiente de revisión.' : 'Este estado no permite edición desde Meta.')
            : ($unsupportedTypes ? 'Esta plantilla incluye componentes avanzados que el editor simple de S.I.G.O.I. no debe reemplazar.' : ($body === '' ? 'No se encontró un cuerpo editable.' : null)),
    ];
}

function wa_templates_list(?string $status = null): array
{
    $query = [
        'page' => 1,
        'limit' => 100,
        'includeTotal' => 'true',
    ];
    $wabaId = wa_templates_waba_id();
    if ($wabaId !== '') $query['filter.wabaId'] = $wabaId;
    if ($status) $query['filter.status'] = $status;

    $res = wa_templates_ycloud_request('GET', '/v2/whatsapp/templates', null, $query);
    if (!($res['ok'] ?? false)) return $res;

    $data = $res['data'] ?? [];
    $items = wa_templates_extract_list(is_array($data) ? $data : []);
    return [
        'ok' => true,
        'templates' => array_map('wa_templates_normalize_template', $items),
        'total' => is_array($data) ? (int)($data['total'] ?? count($items)) : count($items),
    ];
}

function wa_templates_retrieve(string $name, string $language): array
{
    $wabaId = wa_templates_waba_id();
    if ($wabaId === '') return ['ok' => false, 'error' => 'Falta configurar el WABA ID.'];
    return wa_templates_ycloud_request(
        'GET',
        '/v2/whatsapp/templates/' . rawurlencode($wabaId) . '/' . rawurlencode($name) . '/' . rawurlencode($language)
    );
}

function wa_templates_slug(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $value = preg_replace('/[^a-z0-9_\s-]+/', '', $value) ?? $value;
    $value = preg_replace('/[\s-]+/', '_', $value) ?? $value;
    $value = preg_replace('/_+/', '_', $value) ?? $value;
    return trim($value, '_');
}

function wa_templates_quick_slug(string $value): string
{
    $value = wa_templates_slug($value);
    return substr($value, 0, 80);
}
