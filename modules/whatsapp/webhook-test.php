<?php

declare(strict_types=1);

/*
 * Endpoint de diagnóstico retirado después de validar la integración
 * de producción. Se conserva el archivo para que una URL antigua no
 * provoque errores de aplicación, pero ya no acepta pruebas públicas.
 */
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');

echo json_encode([
    'ok' => false,
    'message' => 'Endpoint no disponible.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
