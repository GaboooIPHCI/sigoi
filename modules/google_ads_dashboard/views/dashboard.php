<main class="ads-shell">

    <section class="ads-hero">
        <div>
            <span class="ads-eyebrow">Marketing · Google Ads</span>
            <h1>Rendimiento de campañas</h1>
            <p>Una lectura simple de las visitas que llegan desde Google Ads y de cuántas terminan contactando por WhatsApp.</p>
        </div>

        <form class="ads-filters" method="GET" action="google-ads.php">
            <label>
                <span>Mes</span>
                <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>">
            </label>

            <label>
                <span>Campaña</span>
                <select name="campana">
                    <option value="">Todas las campañas</option>
                    <?php foreach ($campanasFiltro as $filtro): ?>
                        <?php $nombreFiltro = adsNombreCampanaVisible($filtro['campaign_name']); ?>
                        <option
                            value="<?= htmlspecialchars($filtro['campaign_id']) ?>"
                            <?= $campanaFiltro !== '' && $campanaFiltro === (string) $filtro['campaign_id'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($nombreFiltro) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit" class="ads-btn ads-btn--primary">Aplicar</button>
        </form>
    </section>

    <section class="ads-kpis" aria-label="Resumen del periodo">
        <article class="ads-kpi">
            <div class="ads-kpi__icon">↗</div>
            <div>
                <span class="ads-kpi__label">Visitas desde Ads</span>
                <strong><?= number_format($totales['visitas']) ?></strong>
                <small><?= htmlspecialchars($mesVisible) ?></small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">WA</div>
            <div>
                <span class="ads-kpi__label">Contactos por WhatsApp</span>
                <strong><?= number_format($totales['whatsapp']) ?></strong>
                <small>Personas que llegaron a WhatsApp</small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">%</div>
            <div>
                <span class="ads-kpi__label">Tasa de contacto</span>
                <strong><?= number_format($totales['tasa'], 1) ?>%</strong>
                <small><?= number_format($totales['whatsapp']) ?> de <?= number_format($totales['visitas']) ?> visitas</small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">#</div>
            <div>
                <span class="ads-kpi__label">Campañas detectadas</span>
                <strong><?= number_format($totales['campanas']) ?></strong>
                <small>En el periodo seleccionado</small>
            </div>
        </article>
    </section>

    <div class="google-ads-campaigns">

    <div class="google-ads-campaigns__header">
        <span>Campañas</span>
        <h2>Comparación rápida</h2>
        <p>Ordenadas por cantidad de visitas detectadas desde Google Ads.</p>
    </div>

    <?php if (empty($campanas)): ?>
    
            <div class="google-ads-campaigns__empty">
                No hay campañas para este periodo.
            </div>
    
        <?php else: ?>
    
            <div class="google-ads-campaigns__list">
    
                <?php foreach ($campanas as $campana): ?>
    
                    <?php
                    $visitas = (int) $campana['visitas'];
                    $contactos = (int) $campana['sesiones_whatsapp'];
                    $tasa = adsTasaContacto($visitas, $contactos);
                    $nombre = adsNombreCampanaVisible($campana['campaign_name']);
                    ?>
    
                    <a href="google-ads-detalle.php?campana=<?= urlencode($campana['campaign_id']) ?>&mes=<?= urlencode($mes) ?>"
                        class="google-ads-campaign">
                        <div class="google-ads-campaign__name">
                            <strong><?= htmlspecialchars($nombre) ?></strong>
                    
                            <?php if (!empty($campana['campaign_id'])): ?>
                                <small>
                                    ID Google:
                                    <?= htmlspecialchars((string) $campana['campaign_id']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
    
                        <div class="google-ads-campaign__metric">
                            <span>Visitas</span>
                            <strong><?= number_format($visitas) ?></strong>
                        </div>
    
                        <div class="google-ads-campaign__metric">
                            <span>WhatsApp</span>
                            <strong><?= number_format($contactos) ?></strong>
                        </div>
    
                        <div class="google-ads-campaign__metric">
                            <span>Contacto</span>
                            <strong><?= number_format($tasa, 1) ?>%</strong>
                        </div>
    
                        <div class="google-ads-campaign__arrow">
                            →
                        </div>
                    </a>
    
                <?php endforeach; ?>
    
            </div>
    
        <?php endif; ?>
    
    </div>
    
    <section class="ads-info-note">
        <strong>Qué mide este panel:</strong>
        <span>visitas identificadas por el tracking de Google Ads y acciones de WhatsApp registradas dentro de la web de IPHCI.</span>
    </section>

</main>
