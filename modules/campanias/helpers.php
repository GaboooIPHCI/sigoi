<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(bool $success, string $message = '', $data = null, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requestData(): array
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $json = getJsonInput();
        if (!empty($json)) {
            return $json;
        }
        return $_POST;
    }

    return $_GET;
}

function normalizeText(?string $value): string
{
    return trim((string) $value);
}

function validateEstadoCampania(string $estado): bool
{
    return in_array($estado, ['borrador', 'activa', 'finalizada'], true);
}

function normalizeCamposExtra($camposExtra): array
{
    if (!is_array($camposExtra)) {
        return [];
    }

    $normalized = [];

    foreach ($camposExtra as $index => $campo) {
        if (!is_array($campo)) {
            continue;
        }

        $etiqueta = trim((string)($campo['etiqueta'] ?? ''));
        $nombreInterno = trim((string)($campo['nombre_interno'] ?? ''));
        $tipo = trim((string)($campo['tipo'] ?? ''));
        $requerido = !empty($campo['requerido']) ? 1 : 0;
        $orden = isset($campo['orden']) ? (int)$campo['orden'] : ($index + 1);
        $opciones = $campo['opciones'] ?? [];

        if ($etiqueta === '' || $tipo === '') {
            continue;
        }

        if ($nombreInterno === '') {
            $nombreInterno = slugify($etiqueta);
        }

        if (!in_array($tipo, ['texto', 'textarea', 'numero', 'fecha', 'select', 'booleano'], true)) {
            continue;
        }

        if ($tipo === 'select') {
            if (!is_array($opciones)) {
                $opciones = [];
            }
            $opciones = array_values(array_filter(array_map('trim', $opciones), fn($v) => $v !== ''));
        } else {
            $opciones = [];
        }

        $normalized[] = [
            'etiqueta' => $etiqueta,
            'nombre_interno' => $nombreInterno,
            'tipo' => $tipo,
            'requerido' => $requerido,
            'orden' => $orden,
            'opciones_json' => !empty($opciones) ? json_encode($opciones, JSON_UNESCAPED_UNICODE) : null
        ];
    }

    return $normalized;
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '_', $text);
    $text = trim($text, '_');

    return $text !== '' ? $text : 'campo';
}