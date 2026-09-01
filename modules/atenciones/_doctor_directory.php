<?php

declare(strict_types=1);

function atenciones_doctors(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nombre
        FROM medicos_iphci
        WHERE activo = 1
        ORDER BY nombre ASC
    ");

    return array_map(static function (array $record): array {
        return [
            'id' => (int) $record['id'],
            'nombre' => trim((string) $record['nombre'])
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function atenciones_is_registered_doctor(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM medicos_iphci
        WHERE activo = 1
          AND TRIM(nombre) = :nombre
        LIMIT 1
    ");
    $stmt->execute([':nombre' => trim($name)]);

    return (bool) $stmt->fetchColumn();
}

function atenciones_is_valid_appointment_provider(PDO $pdo, string $name): bool
{
    if (strcasecmp(trim($name), 'Personal asistencial') === 0) {
        return true;
    }

    return atenciones_is_registered_doctor($pdo, $name);
}
