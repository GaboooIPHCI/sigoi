<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

auth_require_page_access();

$currentPage = basename($_SERVER['PHP_SELF']);
$currentUser = auth_user();
$currentRole = auth_user_role();

$menuGroups = [
    'gestion' => [
        'label' => 'Gestión',
        'items' => [
            ['page' => 'index.php', 'label' => 'Pacientes'],
            ['page' => 'campanias.php', 'label' => 'Campañas'],
            ['page' => 'convenios.php', 'label' => 'Convenios'],
            ['page' => 'whatsapp.php', 'label' => 'WhatsApp'],
        ],
    ],

    'analitica' => [
        'label' => 'Analítica',
        'items' => [
            ['page' => 'metricas.php', 'label' => 'Métricas'],
            ['page' => 'metricas-web.php', 'label' => 'Métricas Web'],
            ['page' => 'google-ads.php', 'label' => 'Google Ads'],
        ],
    ],

    'administracion' => [
        'label' => 'Administración',
        'items' => [
            ['page' => 'usuarios.php', 'label' => 'Usuarios'],
            ['page' => 'medicos.php', 'label' => 'Médicos'],
            ['page' => 'horarios.php', 'label' => 'Horarios médicos'],
        ],
    ],
];

$userDisplayName = auth_user_display_name();
$userLoginName = auth_username();
$userTitle = auth_role_label() . ' - ' . $userDisplayName;
if ($userLoginName !== '' && $userLoginName !== $userDisplayName) {
    $userTitle .= ' / usuario: ' . $userLoginName;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data de Pacientes</title>
    <link rel="shortcut icon" href="assets/img/favicon.ico" type="image/x-icon">

    <link rel="stylesheet" href="assets/css/styles.css?v=4.8.1">

    <?php if ($currentPage === 'campanias.php'): ?>
        <link rel="stylesheet" href="assets/css/campanias.css">
        <link rel="stylesheet" href="assets/css/campanias-detail.css?v=1.0">
    <?php endif; ?>

    <?php if ($currentPage === 'metricas.php' || $currentPage === 'metricas-web.php'): ?>
        <link rel="stylesheet" href="assets/css/metricas.css">
    <?php endif; ?>

    <?php if ($currentPage === 'metricas.php'): ?>
        <link rel="stylesheet" href="assets/css/metricas-dashboard.css?v=1.0">
    <?php endif; ?>

    <?php if ($currentPage === 'google-ads.php' || $currentPage === 'google-ads-detalle.php'): ?>
        <link rel="stylesheet" href="modules/google_ads_dashboard/assets/css/google-ads.css?v=3.0">
    <?php endif; ?>

    <?php if ($currentPage === 'horarios.php'): ?>
        <link rel="stylesheet" href="assets/css/horarios.css?v=4.1">
        <link rel="stylesheet" href="assets/css/horarios-calendar.css?v=1.0">
        <link rel="stylesheet" href="assets/css/horarios-form.css?v=6.1">
    <?php endif; ?>

    <?php if ($currentPage === 'medicos.php'): ?>
        <link rel="stylesheet" href="assets/css/medicos.css?v=3.0">
    <?php endif; ?>


    <?php if ($currentPage === 'whatsapp.php'): ?>
        <link rel="stylesheet" href="assets/css/whatsapp.css?v=3.0">
    <?php endif; ?>
</head>
<body data-role="<?= htmlspecialchars((string) $currentRole, ENT_QUOTES, 'UTF-8') ?>">
<script>
    window.APP_USER = {
        id: <?= (int) ($currentUser['id'] ?? 0) ?>,
        rol: <?= json_encode($currentRole, JSON_UNESCAPED_UNICODE) ?>,
        usuario: <?= json_encode($userLoginName, JSON_UNESCAPED_UNICODE) ?>,
        nombre: <?= json_encode($userDisplayName, JSON_UNESCAPED_UNICODE) ?>
    };
    window.APP_PERMISSIONS = {
        canWritePatients: <?= auth_can_write_patients() ? 'true' : 'false' ?>,
        canWriteCampaigns: <?= auth_can_write_campaigns() ? 'true' : 'false' ?>,
        canManageUsers: <?= auth_can_manage_users() ? 'true' : 'false' ?>,
        canModifySchedules: <?= auth_can_modify_module('horarios') ? 'true' : 'false' ?>,
    };
    window.APP_CSRF_TOKEN = <?= json_encode(
    auth_csrf_token(),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;
</script>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="site-brand" href="<?= htmlspecialchars(auth_url(auth_first_allowed_page()), ENT_QUOTES, 'UTF-8') ?>" aria-label="Ir al inicio">
            <span class="site-brand__title">S.I.G.O.I.</span>
            <small class="site-brand__subtitle">Sistema Integral de Gestión Operativa e Información</small>
        </a>

        <div class="site-header__right">
            <nav class="site-header__actions" aria-label="Navegación principal">
                <?php foreach ($menuGroups as $group): ?>
            
                    <?php
                    $visibleItems = array_filter(
                        $group['items'],
                        fn($item) => auth_can_access_page($item['page'])
                    );

                    if (!$visibleItems) {
                        continue;
                    }

                    $groupActive = false;

                    foreach ($visibleItems as $item) {
                        if (
                            $currentPage === $item['page']
                            || (
                                $item['page'] === 'google-ads.php'
                                && $currentPage === 'google-ads-detalle.php'
                            )
                        ) {
                            $groupActive = true;
                            break;
                        }
                    }
                    ?>
            
                    <div class="nav-dropdown">
                        <button type="button" class="site-link nav-dropdown__trigger <?= $groupActive ? 'is-active' : '' ?>">
                            <?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?>
                            <span class="nav-dropdown__arrow">▼</span>
                        </button>
            
                        <div class="nav-dropdown__menu">
            
                            <?php foreach ($visibleItems as $item): ?>
            
                                <?php
                                $isActive = $currentPage === $item['page']
                                    || (
                                        $item['page'] === 'google-ads.php'
                                        && $currentPage === 'google-ads-detalle.php'
                                    );
                                ?>
            
                                <a href="<?= htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8') ?>"
                                    class="nav-dropdown__item <?= $isActive ? 'is-active' : '' ?>">
                                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
            
                            <?php endforeach; ?>
            
                        </div>
                    </div>
            
                <?php endforeach; ?>
            
                <?php if (auth_can_access_page('exportacion.php')): ?>
                    <?php if ($currentPage === 'index.php' || $currentPage === 'campanias.php'): ?>
                        <div class="export-dropdown">
                            <button class="site-link export-trigger" id="exportDropdownBtn" type="button">Exportar Excel ▼</button>
                            <div class="export-menu hidden" id="exportMenu">
                                <button data-export="all">Exportar todo</button>
                                <button data-export="month">Exportar mes actual</button>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="exportacion.php" class="site-link <?= $currentPage === 'exportacion.php' ? 'is-active' : '' ?>">Exportar
                            Excel</a>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>

            <div class="user-menu" title="<?= htmlspecialchars($userTitle, ENT_QUOTES, 'UTF-8') ?>">
                <span class="user-menu__role"><?= htmlspecialchars(auth_role_label(), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-menu__username"><?= htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') ?></span>
                <a href="logout.php" class="site-link site-link--logout">Salir</a>
            </div>
        </div>
    </div>
</header>
