<?php

declare(strict_types=1);

/**
 * Endpoint mínimo de diagnóstico para YCloud.
 * No usa base de datos, sesiones, Meta, reglas ni API.
 * Solo sirve para confirmar si YCloud puede recibir un HTTP 200
 * desde este hosting.
 */

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $body = '{"received":true,"test":"ycloud"}';

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$body = '{"ok":true,"test":"YCloud endpoint minimo","post_ready":true}';

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($body));
echo $body;
