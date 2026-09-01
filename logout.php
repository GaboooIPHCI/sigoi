<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$user = auth_user();
if ($user) {
    auth_audit($pdo, 'logout', (int) $user['id'], $user['usuario'] ?? ($user['correo'] ?? null), 'Cierre de sesión.');
}

auth_remember_clear($pdo);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
