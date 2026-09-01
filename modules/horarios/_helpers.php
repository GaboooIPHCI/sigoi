<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

function horarios_json(bool $ok, string $message = '', array $extra = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function horarios_input(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : $_POST;
}

function horarios_text($value, int $max = 255): string
{
    $text = trim((string) ($value ?? ''));
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $max)
        : substr($text, 0, $max);
}

function horarios_date_valid(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function horarios_time_valid(string $time): bool
{
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
}

function horarios_payload(array $data, bool $requireTime = false): array
{
    $row = [
        'medico_id' => (int) ($data['medico_id'] ?? 0),
        'especialidad_id' => (int) ($data['especialidad_id'] ?? 0),
        'medico' => horarios_text($data['medico'] ?? '', 140),
        'especialidad' => horarios_text($data['especialidad'] ?? '', 120),
        'rama' => horarios_text($data['rama'] ?? '', 140),
        'hora_inicio' => horarios_text($data['hora_inicio'] ?? '', 5),
        'hora_fin' => horarios_text($data['hora_fin'] ?? '', 5),
        'modalidad' => horarios_text($data['modalidad'] ?? 'Presencial', 30),
        'tipo_atencion' => 'Previa coordinación',
        'estado' => horarios_text($data['estado'] ?? 'Disponible', 40),
        'publicacion' => horarios_text($data['publicacion'] ?? 'Borrador', 20),
        'cupos_totales' => 0,
        'cupos_ocupados' => 0,
        'observaciones' => horarios_text($data['observaciones'] ?? '', 2000),
        'color' => horarios_text($data['color'] ?? '#5b21b6', 16),
    ];
    if ($row['medico_id'] <= 0 || $row['especialidad_id'] <= 0) horarios_json(false, 'Selecciona un profesional y una especialidad del directorio.', [], 422);
    global $pdo;
    $selection = $pdo->prepare("SELECT m.nombre medico,m.color,e.nombre especialidad FROM medicos_iphci m JOIN medicos_especialidades me ON me.medico_id=m.id JOIN especialidades_medicas e ON e.id=me.especialidad_id WHERE m.id=:m AND e.id=:e AND m.activo=1 AND e.activo=1 LIMIT 1");
    $selection->execute([':m'=>$row['medico_id'],':e'=>$row['especialidad_id']]);
    $selected=$selection->fetch(PDO::FETCH_ASSOC);
    if(!$selected) horarios_json(false,'La especialidad seleccionada no pertenece al profesional.',[],422);
    $row['medico']=$selected['medico'];$row['especialidad']=$selected['especialidad'];$row['rama']=$selected['especialidad'];
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $row['color'])) $row['color'] = $selected['color'] ?: '#5b21b6';
    if (!in_array($row['modalidad'], ['Presencial', 'Virtual', 'Ambas'], true)) horarios_json(false, 'Modalidad inválida.', [], 422);
    if (!in_array($row['estado'], ['Disponible', 'Por confirmar', 'No disponible', 'Cancelado'], true)) horarios_json(false, 'Estado inválido.', [], 422);
    if (!in_array($row['publicacion'], ['Borrador', 'Publicado'], true)) horarios_json(false, 'Estado de publicación inválido.', [], 422);
    if ($requireTime) {
        if (!horarios_time_valid($row['hora_inicio']) || !horarios_time_valid($row['hora_fin']) || $row['hora_fin'] <= $row['hora_inicio']) {
            horarios_json(false, 'Selecciona una hora de inicio y término válidas.', [], 422);
        }
    } else {
        $row['hora_inicio'] = $row['hora_inicio'] !== '' ? $row['hora_inicio'] : null;
        $row['hora_fin'] = $row['hora_fin'] !== '' ? $row['hora_fin'] : null;
    }
    return $row;
}

function horarios_sync_doctor_color(PDO $pdo, array $row): void
{
    $stmt = $pdo->prepare("UPDATE medicos_iphci SET color=:color WHERE id=:id AND color<>:same_color");
    $stmt->execute([':color'=>$row['color'],':id'=>$row['medico_id'],':same_color'=>$row['color']]);
}
