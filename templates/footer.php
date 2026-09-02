<?php
$currentPage = basename($_SERVER['PHP_SELF']);

$sigoiWhatsappMenuPermissions = [
    'bandeja_ver' => false,
    'automatizacion_ver' => false,
    'plantillas_ver' => false,
    'analitica_ver' => false,
];

if (
    function_exists('auth_can_access_page')
    && auth_can_access_page('whatsapp.php')
    && function_exists('auth_whatsapp_permissions')
) {
    $waMenuPermissions = auth_whatsapp_permissions();

    $sigoiWhatsappMenuPermissions = [
        'bandeja_ver' => !empty($waMenuPermissions['bandeja_ver']),
        'automatizacion_ver' => !empty($waMenuPermissions['automatizacion_ver']),
        'plantillas_ver' => !empty($waMenuPermissions['plantillas_ver']),
        'analitica_ver' => !empty($waMenuPermissions['analitica_ver']),
    ];
}
?>

<?php if ($currentPage === 'index.php'): ?>
    <script src="assets/js/app.js?v=4.8.1"></script>
<?php endif; ?>

<?php if ($currentPage === 'google-ads.php' || $currentPage === 'google-ads-detalle.php'): ?>
    <script src="modules/google_ads_dashboard/assets/js/google-ads.js?v=3.0"></script>
<?php endif; ?>

<div id="toast" class="toast hidden"></div>

<script>
window.SIGOI_WHATSAPP_MENU_PERMISSIONS = <?= json_encode(
    $sigoiWhatsappMenuPermissions,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;
</script>

<script src="assets/js/sigoi-whatsapp-guard.js?v=4.3"></script>

<?php if ($currentPage === 'whatsapp.php'): ?>
    <script src="assets/js/sigoi-messenger-channel.js?v=5.1"></script>
<?php endif; ?>

<?php if ($currentPage === 'usuarios.php'): ?>
    <script src="assets/js/sigoi-messenger-permissions.js?v=5.0"></script>
<?php endif; ?>

</body>
</html>
