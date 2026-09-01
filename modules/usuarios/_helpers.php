<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

auth_require_module_modify('usuarios');
function usuarios_json($success, $message = '', $data = [], $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function usuarios_request()
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function usuarios_clean($value)
{
    return trim((string) ($value ?? ''));
}

function usuarios_validate_username($usuario)
{
    return (bool) preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $usuario);
}

function usuarios_validate_role($rol)
{
    return in_array($rol, [
        'admin',
        'marketing',
        'centro_medico',
        'recepcion'
    ], true);
}