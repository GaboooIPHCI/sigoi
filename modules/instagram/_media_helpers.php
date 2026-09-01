<?php

declare(strict_types=1);

function ig_media_project_root(): string
{
    return dirname(__DIR__, 2);
}

function ig_media_storage_root(): string
{
    return ig_media_project_root() . '/storage/instagram_media';
}

function ig_media_outbox_root(): string
{
    return ig_media_project_root() . '/storage/instagram_outbox';
}

function ig_media_ensure_directories(): void
{
    foreach ([ig_media_storage_root(), ig_media_outbox_root()] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    $outbox = ig_media_outbox_root();
    if (is_dir($outbox)) {
        $htaccess = $outbox . '/.htaccess';
        if (!is_file($htaccess)) @file_put_contents($htaccess, "Require all denied\n");
        $index = $outbox . '/index.php';
        if (!is_file($index)) @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n");
    }
}

function ig_media_safe_filename(string $name, string $fallback = 'archivo'): string
{
    $name = trim(basename($name));
    if ($name === '') $name = $fallback;
    $clean = preg_replace('/[^\pL\pN._ -]+/u', '_', $name);
    return trim((string)$clean) !== '' ? (string)$clean : $fallback;
}

function ig_media_extension(string $mime, string $originalName = ''): string
{
    $map = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/3gpp' => '3gp',
        'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/amr' => 'amr',
    ];
    $mime = strtolower(trim($mime));
    if (isset($map[$mime])) return $map[$mime];

    $candidate = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $candidate) ? $candidate : 'bin';
}

function ig_media_store_uploaded_file(string $tmpPath, string $mime, string $originalName): array
{
    ig_media_ensure_directories();

    $relativeDir = 'storage/instagram_media/' . date('Y/m');
    $absoluteDir = ig_media_project_root() . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de Instagram.');
    }

    $ext = ig_media_extension($mime, $originalName);
    $basename = bin2hex(random_bytes(18)) . '.' . $ext;
    $relative = $relativeDir . '/' . $basename;
    $absolute = ig_media_project_root() . '/' . $relative;

    $moved = is_uploaded_file($tmpPath)
        ? @move_uploaded_file($tmpPath, $absolute)
        : @rename($tmpPath, $absolute);

    if (!$moved || !is_file($absolute)) {
        throw new RuntimeException('No se pudo guardar el archivo de Instagram.');
    }

    return [
        'path' => $relative,
        'absolute' => $absolute,
        'mime' => $mime,
        'filename' => ig_media_safe_filename($originalName, 'instagram.' . $ext),
        'size' => (int)@filesize($absolute),
    ];
}

function ig_media_resolve_path(string $relative): ?string
{
    $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
    if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, 'storage/instagram_media/') !== 0) {
        return null;
    }

    $path = ig_media_project_root() . '/' . $relative;
    if (!is_file($path)) return null;

    $root = realpath(ig_media_storage_root());
    $real = realpath($path);
    if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return null;
    return $real;
}

function ig_outbox_cleanup(int $now = 0): void
{
    ig_media_ensure_directories();
    if ($now <= 0) $now = time();
    foreach (glob(ig_media_outbox_root() . '/*.json') ?: [] as $file) {
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $expires = is_array($data) ? (int)($data['expires'] ?? 0) : 0;
        if ($expires <= 0 || $expires < $now) @unlink($file);
    }
}

function ig_outbox_create_share(string $relativePath, string $mime, string $filename, int $ttlSeconds = 3600): string
{
    ig_media_ensure_directories();
    ig_outbox_cleanup();

    if (!ig_media_resolve_path($relativePath)) {
        throw new RuntimeException('No se pudo preparar el archivo para Meta.');
    }

    $token = bin2hex(random_bytes(24));
    $meta = [
        'path' => $relativePath,
        'mime' => trim($mime) !== '' ? trim($mime) : 'application/octet-stream',
        'filename' => ig_media_safe_filename($filename),
        'expires' => time() + max(300, min(7200, $ttlSeconds)),
    ];

    $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || @file_put_contents(ig_media_outbox_root() . '/' . $token . '.json', $encoded, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear el enlace temporal para Instagram.');
    }

    return $token;
}

function ig_outbox_read_share(string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;

    $file = ig_media_outbox_root() . '/' . $token . '.json';
    if (!is_file($file)) return null;

    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int)($data['expires'] ?? 0) < time()) {
        @unlink($file);
        return null;
    }

    $path = ig_media_resolve_path((string)($data['path'] ?? ''));
    if (!$path) return null;
    $data['absolute'] = $path;
    return $data;
}

function ig_outbox_public_url(string $token): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'sigoi.sophie.com.pe'));
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) $host = 'sigoi.sophie.com.pe';
    return 'https://' . $host . '/modules/instagram/outbox-media.php?t=' . rawurlencode($token);
}
