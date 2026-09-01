<?php
require_once __DIR__ . '/_helpers.php';
auth_require_whatsapp_permission('automatizacion_ver');
auth_validate_csrf();

try {
    global $pdo;
    $data = whatsapp_request();
    $message = whatsapp_clean_text($data['mensaje'] ?? '', 2000);

    if ($message === '') {
        whatsapp_json(false, 'Escribe un mensaje para probar las reglas.', [], 422);
    }

    $rule = whatsapp_find_rule($pdo, $message);
    if ($rule) {
        whatsapp_json(true, 'Se encontró una regla.', [
            'coincidencia' => true,
            'regla' => [
                'id' => (int) $rule['id'],
                'nombre' => $rule['nombre'],
                'palabra' => $rule['palabra_coincidente'],
                'respuesta' => $rule['respuesta'],
            ],
        ]);
    }

    $fallback = $pdo->query("SELECT mensaje_no_reconocido FROM whatsapp_configuracion WHERE id = 1")
        ->fetchColumn();

    whatsapp_json(true, 'No coincide con ninguna regla activa.', [
        'coincidencia' => false,
        'regla' => null,
        'respuesta' => (string) $fallback,
    ]);
} catch (Throwable $e) {
    error_log('WhatsApp test: ' . $e->getMessage());
    whatsapp_json(false, 'No se pudo ejecutar la prueba.', [], 500);
}
