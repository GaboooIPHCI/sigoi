<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

auth_bootstrap_remember($pdo);

if (auth_is_logged_in()) {
    header('Location: ' . auth_url(auth_first_allowed_page()));
    exit;
}

$error = '';
$usuario = '';
$remember = false;
$next = $_GET['next'] ?? ($_POST['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']) && (string) $_POST['remember'] === '1';

    if ($usuario === '' || $password === '') {
        $error = 'Ingresa usuario y contraseña.';
    } else {
        $stmt = $pdo->prepare("SELECT id, nombre, usuario, correo, password_hash, rol, activo
            FROM usuarios_sistema
            WHERE usuario = :usuario
            LIMIT 1");
        $stmt->execute([':usuario' => $usuario]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(int) $user['activo'] || !password_verify($password, $user['password_hash'])) {
            $error = 'Usuario o contraseña incorrectos.';
            auth_audit($pdo, 'login_fallido', $user['id'] ?? null, $usuario, 'Credenciales inválidas.');
        } else {
            session_regenerate_id(true);
            auth_create_session_from_user($user);

            $pdo->prepare("UPDATE usuarios_sistema SET ultimo_acceso = NOW() WHERE id = :id")
                ->execute([':id' => (int) $user['id']]);

            if ($remember) {
                auth_remember_create($pdo, (int) $user['id']);
            } else {
                auth_remember_clear($pdo);
            }

            auth_audit($pdo, 'login_correcto', (int) $user['id'], $user['usuario'] ?: $usuario, $remember ? 'Inicio de sesión con permanecer activo.' : 'Inicio de sesión correcto.');

            $destination = auth_url(auth_first_allowed_page($user['rol']));
            if ($next !== '') {
                $parsed = parse_url($next);
                $path = basename($parsed['path'] ?? '');
                if ($path && auth_can_access_page($path)) {
                    $destination = $next;
                }
            }

            header('Location: ' . $destination);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | Sistema IPHCI</title>
    <link rel="shortcut icon" href="assets/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <span class="auth-brand__mark">IPHCI</span>
                <h1>Acceso al sistema</h1>
                <p>Ingresa con tu usuario y contraseña.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="auth-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" class="auth-form" autocomplete="off">
                <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">

                <label for="usuario">Usuario</label>
                <input type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($usuario, ENT_QUOTES, 'UTF-8') ?>" required autofocus autocomplete="username" placeholder="Usuario">

                <label for="password">Contraseña</label>
                <div class="auth-password-wrap">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <button type="button" id="togglePasswordBtn" aria-label="Mostrar contraseña">Ver</button>
                </div>

                <label class="auth-remember" for="remember">
                    <input type="checkbox" id="remember" name="remember" value="1" <?= $remember ? 'checked' : '' ?>>
                    <span>
                        <strong>Mantener sesión activa</strong>
                        <small>Actívalo solo en un equipo seguro.</small>
                    </span>
                </label>

                <button type="submit" class="auth-submit">Ingresar</button>
            </form>
        </section>
    </main>

    <script>
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('password');
        toggleBtn?.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleBtn.textContent = isPassword ? 'Ocultar' : 'Ver';
        });
    </script>
</body>
</html>
