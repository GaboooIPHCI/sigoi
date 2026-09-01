<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_view('whatsapp');

function whatsapp_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function whatsapp_request(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function whatsapp_clean_text($value, int $max = 10000): string
{
    $value = trim((string) ($value ?? ''));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
    return substr($value, 0, $max);
}

function whatsapp_normalize(string $text): string
{
    $text = trim($text);
    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function whatsapp_keywords_array(string $raw): array
{
    $parts = preg_split('/[,;\n\r]+/u', $raw) ?: [];
    $seen = [];
    $result = [];

    foreach ($parts as $part) {
        $display = trim($part);
        $normalized = whatsapp_normalize($display);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $result[] = $display;
    }

    return $result;
}

function whatsapp_rule_matches(string $message, string $keywordsRaw): ?string
{
    $message = whatsapp_normalize($message);
    if ($message === '') {
        return null;
    }

    foreach (whatsapp_keywords_array($keywordsRaw) as $keyword) {
        $normalizedKeyword = whatsapp_normalize($keyword);
        if ($normalizedKeyword === '') {
            continue;
        }

        $pattern = '/(?<![a-z0-9])' . preg_quote($normalizedKeyword, '/') . '(?![a-z0-9])/u';
        if (preg_match($pattern, $message)) {
            return $keyword;
        }
    }

    return null;
}

function whatsapp_find_rule(PDO $pdo, string $message): ?array
{
    $stmt = $pdo->query("SELECT id, nombre, palabras_clave, respuesta, prioridad
        FROM whatsapp_reglas
        WHERE activa = 1
        ORDER BY prioridad ASC, id ASC");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
        $keyword = whatsapp_rule_matches($message, (string) $rule['palabras_clave']);
        if ($keyword !== null) {
            $rule['palabra_coincidente'] = $keyword;
            return $rule;
        }
    }

    return null;
}
