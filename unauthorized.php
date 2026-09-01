<?php require_once __DIR__ . '/templates/header.php'; ?>

<main class="container">
    <section class="panel auth-denied-panel">
        <span class="dashboard-label">Acceso restringido</span>
        <h1>No tienes permiso para ver este apartado</h1>
        <p>Tu usuario está activo, pero tu rol no tiene acceso a esta sección.</p>
        <div class="dashboard-hero__actions">
            <a href="<?= htmlspecialchars(auth_url(auth_first_allowed_page()), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">Ir a mi panel</a>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
