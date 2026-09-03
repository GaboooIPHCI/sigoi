<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

function sigoi_test_files(string $root, string $extension): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $path = str_replace('\\', '/', $file->getPathname());
        if (strpos($path, '/vendor/') !== false || strpos($path, '/.git/') !== false) {
            continue;
        }
        if (strtolower($file->getExtension()) === $extension) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);
    return $files;
}

$php8OnlyFunctions = [
    'str_contains',
    'str_starts_with',
    'str_ends_with',
    'array_is_list',
];

foreach (sigoi_test_files($root, 'php') as $file) {
    $code = file_get_contents($file);
    if (!is_string($code)) {
        $errors[] = 'No se pudo leer: ' . $file;
        continue;
    }

    $tokens = token_get_all($code);
    foreach ($tokens as $token) {
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($token[1]);
        if (in_array($name, $php8OnlyFunctions, true)) {
            $errors[] = 'Función no compatible con PHP 7.4: ' . $token[1] . ' en ' . $file . ':' . $token[2];
        }
    }
}

$forbiddenPrivate = [
    'config/db.php',
    'config/auth.php',
    'config/whatsapp_meta.php',
    'config/instagram_meta.php',
    'config/messenger_meta.php',
];

if (is_dir($root . '/.git') && function_exists('shell_exec')) {
    $tracked = (string)shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files 2>/dev/null');
    $trackedFiles = array_flip(array_filter(preg_split('/\r?\n/', trim($tracked)) ?: []));
    foreach ($forbiddenPrivate as $relative) {
        if (isset($trackedFiles[$relative])) {
            $errors[] = 'Archivo privado rastreado por Git: ' . $relative;
        }
    }
}

$jsPatterns = [
    '/window\s*\.\s*fetch\s*=/' => 'No se permite reemplazar window.fetch.',
    '/window\s*\.\s*setInterval\s*=/' => 'No se permite reemplazar window.setInterval.',
    '/new\s+MutationObserver\s*\(/' => 'No se permite reintroducir MutationObserver como parche de la bandeja.',
];

foreach (sigoi_test_files($root . '/assets/js', 'js') as $file) {
    $code = file_get_contents($file);
    if (!is_string($code)) {
        continue;
    }
    foreach ($jsPatterns as $pattern => $message) {
        if (preg_match($pattern, $code)) {
            $errors[] = $message . ' Archivo: ' . $file;
        }
    }
}

$versionFile = $root . '/config/app_version.php';
$versionCode = is_file($versionFile) ? file_get_contents($versionFile) : '';
if (!is_string($versionCode) || strpos($versionCode, "SIGOI_VERSION', '1.6.0'") === false) {
    $errors[] = 'La versión final debe ser exactamente 1.6.0.';
}

if ($errors) {
    fwrite(STDERR, "S.I.G.O.I. static checks: FAIL\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "S.I.G.O.I. static checks: OK\n");
