<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>

<?php if ($currentPage === 'index.php'): ?>
    <script src="assets/js/app.js?v=4.8.1"></script>
<?php endif; ?>

<?php if ($currentPage === 'google-ads.php' || $currentPage === 'google-ads-detalle.php'): ?>
    <script src="modules/google_ads_dashboard/assets/js/google-ads.js?v=3.0"></script>
<?php endif; ?>

    <div id="toast" class="toast hidden"></div>
</body>
</html>
