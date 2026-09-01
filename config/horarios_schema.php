<?php
declare(strict_types=1);

/** Instalación idempotente del módulo. Si el hosting no permite DDL, ejecutar database/horarios.sql. */
function horarios_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    // Evita ejecutar CREATE/INFORMATION_SCHEMA/backfills en cada request.
    $migrationKey = 'horarios_runtime_v2_20260829';
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS iphci_schema_migrations (
            clave VARCHAR(80) NOT NULL,
            aplicado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $checkMigration = $pdo->prepare("SELECT 1 FROM iphci_schema_migrations WHERE clave = :clave LIMIT 1");
        $checkMigration->execute([':clave' => $migrationKey]);
        if ($checkMigration->fetchColumn()) {
            $done = true;
            return;
        }
    } catch (Throwable $e) {
        // Continúa con la instalación normal si aún no puede leerse la marca.
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS horarios_medicos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            serie_id VARCHAR(40) DEFAULT NULL,
            medico VARCHAR(140) NOT NULL,
            especialidad VARCHAR(120) NOT NULL,
            rama VARCHAR(140) DEFAULT NULL,
            fecha DATE NOT NULL,
            hora_inicio TIME DEFAULT NULL,
            hora_fin TIME DEFAULT NULL,
            modalidad VARCHAR(30) NOT NULL DEFAULT 'Presencial',
            tipo_atencion VARCHAR(40) NOT NULL DEFAULT 'Horario fijo',
            estado VARCHAR(40) NOT NULL DEFAULT 'Disponible',
            publicacion VARCHAR(20) NOT NULL DEFAULT 'Borrador',
            cupos_totales SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            cupos_ocupados SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            observaciones TEXT DEFAULT NULL,
            color VARCHAR(16) NOT NULL DEFAULT '#5b21b6',
            creado_por INT DEFAULT NULL,
            actualizado_por INT DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_horarios_fecha (fecha),
            KEY idx_horarios_medico (medico),
            KEY idx_horarios_especialidad (especialidad),
            KEY idx_horarios_publicacion (publicacion),
            KEY idx_horarios_serie (serie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS especialidades_medicas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(120) NOT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_especialidad_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS medicos_iphci (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(140) NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            observaciones TEXT DEFAULT NULL,
            color VARCHAR(16) NOT NULL DEFAULT '#5b21b6',
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id), UNIQUE KEY uq_medico_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS medicos_especialidades (
            medico_id INT UNSIGNED NOT NULL,
            especialidad_id INT UNSIGNED NOT NULL,
            principal TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (medico_id, especialidad_id), KEY idx_me_especialidad (especialidad_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS iphci_schema_migrations (
            clave VARCHAR(80) NOT NULL,
            aplicado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (clave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'horarios_medicos'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('medico_id', $columns, true)) $pdo->exec("ALTER TABLE horarios_medicos ADD medico_id INT UNSIGNED DEFAULT NULL, ADD KEY idx_horarios_medico_id (medico_id)");
        if (!in_array('especialidad_id', $columns, true)) $pdo->exec("ALTER TABLE horarios_medicos ADD especialidad_id INT UNSIGNED DEFAULT NULL, ADD KEY idx_horarios_especialidad_id (especialidad_id)");
        $doctorColumns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medicos_iphci'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('color', $doctorColumns, true)) $pdo->exec("ALTER TABLE medicos_iphci ADD color VARCHAR(16) NOT NULL DEFAULT '#5b21b6' AFTER observaciones");

        $pdo->exec("INSERT INTO modulos_sistema (clave, nombre, grupo, orden, activo)
            SELECT 'horarios', 'Horarios médicos', 'administracion', 20, 1
            WHERE NOT EXISTS (SELECT 1 FROM modulos_sistema WHERE clave = 'horarios')");

        $pdo->exec("INSERT INTO modulos_sistema (clave, nombre, grupo, orden, activo)
            SELECT 'medicos', 'Médicos y especialidades', 'administracion', 15, 1
            WHERE NOT EXISTS (SELECT 1 FROM modulos_sistema WHERE clave = 'medicos')");

        $pdo->exec("INSERT INTO usuarios_permisos (usuario_id, modulo_id, puede_ver, puede_modificar)
            SELECT u.id, m.id, 1, 0
            FROM usuarios_sistema u
            INNER JOIN modulos_sistema m ON m.clave = 'horarios'
            LEFT JOIN usuarios_permisos p ON p.usuario_id = u.id AND p.modulo_id = m.id
            WHERE u.rol IN ('marketing', 'centro_medico', 'recepcion') AND p.usuario_id IS NULL");

        $pdo->exec("INSERT INTO usuarios_permisos (usuario_id, modulo_id, puede_ver, puede_modificar)
            SELECT u.id, m.id, 1, 0 FROM usuarios_sistema u
            INNER JOIN modulos_sistema m ON m.clave = 'medicos'
            LEFT JOIN usuarios_permisos p ON p.usuario_id = u.id AND p.modulo_id = m.id
            WHERE u.rol = 'centro_medico' AND p.usuario_id IS NULL");

        $seeded = $pdo->query("SELECT 1 FROM iphci_schema_migrations WHERE clave='medicos_base_2026' LIMIT 1")->fetchColumn();
        if (!$seeded) {
            horarios_seed_medicos($pdo);
            $pdo->exec("INSERT IGNORE INTO iphci_schema_migrations (clave) VALUES ('medicos_base_2026')");
        }
        $colorsSeeded = $pdo->query("SELECT 1 FROM iphci_schema_migrations WHERE clave='medicos_colores_2026' LIMIT 1")->fetchColumn();
        if (!$colorsSeeded) {
            horarios_seed_doctor_colors($pdo);
            $pdo->exec("INSERT IGNORE INTO iphci_schema_migrations (clave) VALUES ('medicos_colores_2026')");
        }
        $uniqueColorsSeeded = $pdo->query("SELECT 1 FROM iphci_schema_migrations WHERE clave='medicos_colores_unicos_2026' LIMIT 1")->fetchColumn();
        if (!$uniqueColorsSeeded) {
            horarios_seed_unique_doctor_colors($pdo);
            $pdo->exec("INSERT IGNORE INTO iphci_schema_migrations (clave) VALUES ('medicos_colores_unicos_2026')");
        }
        horarios_link_existing_records($pdo);
        $markMigration = $pdo->prepare("INSERT IGNORE INTO iphci_schema_migrations (clave) VALUES (:clave)");
        $markMigration->execute([':clave' => $migrationKey]);
        $done = true;
    } catch (Throwable $e) {
        error_log('No se pudo verificar el esquema de horarios: ' . $e->getMessage());
    }
}

function horarios_seed_medicos(PDO $pdo): void
{
    $catalog = [
        'Medicina general'=>['Dr. Marco Mejía'],
        'Cardiología clínica'=>['Dr. German Rodríguez','Dr. José Delgado','Dr. Bryan Obando','Dr. Aníbal Monge','Dr. Ricardo Arce','Dr. Andrés Ramón'],
        'Cardiología intervencionista'=>['Dr. Emilio Choy','Dr. Luis Mejía','Dr. José Añorga'],
        'Colorectal'=>['Dr. Porfirio Rivera'], 'Endocrinología'=>['Dra. Karina Maique','Dr. Carlos Collantes'],
        'Urología'=>['Dra. Yenny Gomez'], 'Ecografía'=>['Dr. Richard Mejía','Dr. José Colmenares'],
        'Oftalmología'=>['Dra. Nalia Entenza'], 'Cardiología pediátrica'=>['Dra. Gabriela Morales','Dra. Flor Mamani'],
        'Cirugía cardiovascular'=>['Dr. Oscar Fernandez','Dra. Karla Bautista'], 'Terapia física'=>['Lic. Karen Saenz'],
        'Neurología'=>['Dr. Joel Hurtado'], 'Neumología'=>['Dr. Barnaby Yabar','Dr. Henry Martínez','Dra. Anny Vidal'],
        'Gastroenterología'=>['Dr. Roberto Tafur','Dra. Joyce Monzón'], 'Geriatría'=>['Dra. Ana Ayon'], 'Reumatología'=>[]
    ];
    $specialty = $pdo->prepare("INSERT INTO especialidades_medicas (nombre) VALUES (:nombre) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)");
    $doctor = $pdo->prepare("INSERT INTO medicos_iphci (nombre) VALUES (:nombre) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)");
    $link = $pdo->prepare("INSERT IGNORE INTO medicos_especialidades (medico_id,especialidad_id,principal) VALUES (:medico,:especialidad,1)");
    foreach ($catalog as $specialtyName => $doctors) {
        $specialty->execute([':nombre'=>$specialtyName]);
        $sid=(int)$pdo->query("SELECT id FROM especialidades_medicas WHERE nombre=".$pdo->quote($specialtyName))->fetchColumn();
        foreach ($doctors as $doctorName) {
            $doctor->execute([':nombre'=>$doctorName]);
            $did=(int)$pdo->query("SELECT id FROM medicos_iphci WHERE nombre=".$pdo->quote($doctorName))->fetchColumn();
            $link->execute([':medico'=>$did,':especialidad'=>$sid]);
        }
    }
    $referenceNotes = [
        'Dr. Bryan Obando'=>['Temporalmente no disponible','A la espera de su titulación.'],
        'Dr. Aníbal Monge'=>['Activo','Horario sujeto a disponibilidad.'],
        'Dr. Ricardo Arce'=>['Activo','Previa coordinación por teléfono o Telegram.'],
        'Dr. Emilio Choy'=>['Activo','Previa coordinación.'],
        'Dra. Yenny Gomez'=>['Temporalmente no disponible','No disponible hasta septiembre por temas de certificación.'],
        'Dr. Richard Mejía'=>['Activo','Horario en coordinación.'],
        'Dra. Gabriela Morales'=>['Temporalmente no disponible','No disponible por temas familiares.'],
        'Dra. Karla Bautista'=>['Activo','Previa coordinación.'],
        'Dr. Roberto Tafur'=>['Activo','Previa coordinación.'],
        'Dra. Joyce Monzón'=>['Activo','Horario en coordinación.'],
        'Dra. Ana Ayon'=>['Activo','Previa coordinación.'],
    ];
    $details = $pdo->prepare("UPDATE medicos_iphci SET estado=:estado,observaciones=:observaciones WHERE nombre=:nombre");
    foreach ($referenceNotes as $name => [$status,$notes]) {
        $details->execute([':estado'=>$status,':observaciones'=>$notes,':nombre'=>$name]);
    }
}

function horarios_link_existing_records(PDO $pdo): void
{
    $pdo->exec("UPDATE horarios_medicos h JOIN medicos_iphci m ON m.nombre=h.medico SET h.medico_id=m.id WHERE h.medico_id IS NULL");
    $pdo->exec("UPDATE horarios_medicos h JOIN especialidades_medicas e ON e.nombre=h.especialidad SET h.especialidad_id=e.id WHERE h.especialidad_id IS NULL");
}

function horarios_seed_doctor_colors(PDO $pdo): void
{
    $palette = ['#6d28d9','#2563eb','#0891b2','#059669','#65a30d','#d97706','#dc2626','#db2777','#7c3aed','#0f766e','#9333ea','#ea580c'];
    $ids = $pdo->query("SELECT id FROM medicos_iphci ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $update = $pdo->prepare("UPDATE medicos_iphci SET color=:color WHERE id=:id");
    foreach ($ids as $index => $id) $update->execute([':color'=>$palette[$index % count($palette)],':id'=>(int)$id]);
}

/**
 * Paleta categórica de alto contraste para el calendario.
 *
 * A diferencia de la paleta inicial, estos colores no se reciclan entre los
 * profesionales actuales y alternan matiz/luminosidad para evitar tonos
 * visualmente cercanos. El orden por nombre hace que la asignación sea estable
 * aunque los identificadores cambien entre instalaciones.
 */
function horarios_seed_unique_doctor_colors(PDO $pdo): void
{
    $palette = [
        '#0000FF', '#FF0000', '#00A83B', '#000033', '#FF00B6',
        '#005300', '#C99F00', '#009FFF', '#9A4D42', '#00B887',
        '#783FC1', '#1F9698', '#D66AC7', '#78943D', '#F1085C',
        '#FE8F42', '#B000D4', '#201A01', '#720055', '#766C95',
        '#02AD24', '#8FAF00', '#886C00', '#E87963', '#5F6151',
        '#A10300', '#00AFC1', '#00479E', '#DC5E93', '#3685A8',
        '#4B32C3', '#9B8700', '#C40086', '#007A70', '#3B5D00',
        '#7A001E', '#483D8B', '#00A676', '#E0522D', '#4D7A3A'
    ];

    $doctors = $pdo->query("SELECT id FROM medicos_iphci ORDER BY nombre, id")->fetchAll(PDO::FETCH_COLUMN);
    $updateDoctor = $pdo->prepare("UPDATE medicos_iphci SET color=:color WHERE id=:id");
    $updateSchedules = $pdo->prepare("UPDATE horarios_medicos SET color=:color WHERE medico_id=:id");

    foreach ($doctors as $index => $id) {
        if (isset($palette[$index])) {
            $color = $palette[$index];
        } else {
            // Respaldo para catálogos futuros de más de 40 profesionales.
            $hue = fmod(($index * 137.508), 360.0);
            $color = horarios_hsl_to_hex($hue, 72.0, ($index % 2 === 0) ? 38.0 : 48.0);
        }
        $updateDoctor->execute([':color'=>$color, ':id'=>(int)$id]);
        $updateSchedules->execute([':color'=>$color, ':id'=>(int)$id]);
    }
}

function horarios_hsl_to_hex(float $hue, float $saturation, float $lightness): string
{
    $saturation /= 100;
    $lightness /= 100;
    $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
    $segment = $hue / 60;
    $secondary = $chroma * (1 - abs(fmod($segment, 2) - 1));
    switch ((int) floor($segment) % 6) {
        case 0: [$red, $green, $blue] = [$chroma, $secondary, 0]; break;
        case 1: [$red, $green, $blue] = [$secondary, $chroma, 0]; break;
        case 2: [$red, $green, $blue] = [0, $chroma, $secondary]; break;
        case 3: [$red, $green, $blue] = [0, $secondary, $chroma]; break;
        case 4: [$red, $green, $blue] = [$secondary, 0, $chroma]; break;
        default: [$red, $green, $blue] = [$chroma, 0, $secondary];
    }
    $offset = $lightness - $chroma / 2;
    return sprintf('#%02X%02X%02X',
        (int) round(($red + $offset) * 255),
        (int) round(($green + $offset) * 255),
        (int) round(($blue + $offset) * 255)
    );
}
