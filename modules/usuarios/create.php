<?php

require_once __DIR__ . '/_helpers.php';

usuarios_json(
    false,
    'La creación de nuevas cuentas está deshabilitada. Las cuentas del sistema son fijas.',
    [],
    403
);