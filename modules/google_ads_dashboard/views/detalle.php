<main class="ads-shell">
<?php if (!$detalle['campana']): ?>

    <section class="ads-empty ads-empty--standalone">
        <div class="ads-empty__icon">!</div>
        <h1>No encontramos esta campaña</h1>
        <p>No hay registros para la campaña y el mes seleccionados.</p>
        <a class="ads-btn ads-btn--primary" href="google-ads.php?mes=<?= urlencode($mes) ?>">Volver a Google Ads</a>
    </section>

<?php else: ?>

    <?php
    $campana = $detalle['campana'];
    $keywords = $detalle['keywords'];
    $devices = $detalle['devices'];
    $grupos = $detalle['grupos_anuncios'];
    $anuncios = $detalle['anuncios'];
    $landings = $detalle['landings'];

    $nombre = adsNombreCampanaVisible($campana['campaign_name']);
    $visitas = (int) $campana['visitas'];
    $contactos = (int) $campana['sesiones_whatsapp'];
    $clicsWhatsapp = (int) $campana['clics_whatsapp'];
    $tasa = adsTasaContacto($visitas, $contactos);

    $principalDevice = $devices[0] ?? null;
    $principalDeviceLabel = $principalDevice
        ? adsDispositivoVisible((string) $principalDevice['valor'])
        : 'Sin datos';
    $principalDevicePct = ($principalDevice && $visitas > 0)
        ? ((int) $principalDevice['visitas'] / $visitas) * 100
        : 0;

    $maxKeyword = $keywords ? max(array_map(fn($r) => (int) $r['visitas'], $keywords)) : 0;
    $maxDevice = $devices ? max(array_map(fn($r) => (int) $r['visitas'], $devices)) : 0;
    ?>

    <section class="ads-detail-hero">
        <a class="ads-back" href="google-ads.php?mes=<?= urlencode($mes) ?>">← Volver a campañas</a>

        <div class="ads-detail-hero__row">
            <div>
                <span class="ads-eyebrow">Google Ads · <?= htmlspecialchars($mesVisible) ?></span>
                <h1><?= htmlspecialchars($nombre) ?></h1>
                <p>ID Google: <?= htmlspecialchars($campanaId) ?></p>
            </div>
        </div>
    </section>

    <section class="ads-kpis">
        <article class="ads-kpi">
            <div class="ads-kpi__icon">↗</div>
            <div>
                <span class="ads-kpi__label">Visitas</span>
                <strong><?= number_format($visitas) ?></strong>
                <small>Entradas detectadas desde Ads</small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">WA</div>
            <div>
                <span class="ads-kpi__label">Contactos por WhatsApp</span>
                <strong><?= number_format($contactos) ?></strong>
                <small><?= number_format($clicsWhatsapp) ?> clics registrados</small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">%</div>
            <div>
                <span class="ads-kpi__label">Tasa de contacto</span>
                <strong><?= number_format($tasa, 1) ?>%</strong>
                <small><?= number_format($contactos) ?> de <?= number_format($visitas) ?> visitas</small>
            </div>
        </article>

        <article class="ads-kpi">
            <div class="ads-kpi__icon">▣</div>
            <div>
                <span class="ads-kpi__label">Dispositivo principal</span>
                <strong><?= htmlspecialchars($principalDeviceLabel) ?></strong>
                <small><?= number_format($principalDevicePct, 1) ?>% de las visitas</small>
            </div>
        </article>
    </section>

    <div class="google-ads-detail-grid">

    <div class="google-ads-detail-card">
        <div class="google-ads-detail-card__header">
            <span>Búsquedas</span>
            <h2>Palabras clave</h2>
            <p>Qué keywords están generando visitas y contactos.</p>
        </div>

        <?php if (empty($keywords)): ?>

            <div class="google-ads-detail-empty">
                Sin palabras clave disponibles.
            </div>

        <?php else: ?>

            <div class="google-ads-ranking">

                <?php foreach ($keywords as $row): ?>

                    <?php
                    $keywordVisitas = (int) $row['visitas'];
                    $keywordContactos = (int) $row['contactos'];
                    $keywordTasa = adsTasaContacto(
                        $keywordVisitas,
                        $keywordContactos
                    );
                    ?>

                    <div class="google-ads-ranking__item">

                        <div class="google-ads-ranking__top">
                            <strong>
                                <?= htmlspecialchars((string) $row['valor']) ?>
                            </strong>

                            <span>
                                <?= number_format($keywordVisitas) ?> visitas
                            </span>
                        </div>

                        <div class="google-ads-ranking__meta">
                            <span>
                                <?= number_format($keywordContactos) ?> WhatsApp
                            </span>

                            <span>
                                <?= number_format($keywordTasa, 1) ?>% contacto
                            </span>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </div>


    <div class="google-ads-detail-card">
        <div class="google-ads-detail-card__header">
            <span>Audiencia</span>
            <h2>Dispositivos</h2>
            <p>Desde qué dispositivo llegaron las visitas.</p>
        </div>

        <?php if (empty($devices)): ?>

            <div class="google-ads-detail-empty">
                Sin información de dispositivos.
            </div>

        <?php else: ?>

            <div class="google-ads-device-list">

                <?php foreach ($devices as $row): ?>

                    <?php
                    $deviceVisitas = (int) $row['visitas'];
                    $deviceContactos = (int) $row['contactos'];

                    $devicePorcentaje = $visitas > 0
                        ? ($deviceVisitas / $visitas) * 100
                        : 0;
                    ?>

                    <div class="google-ads-device">

                        <div class="google-ads-device__top">
                            <strong>
                                <?= htmlspecialchars(
                                    adsDispositivoVisible(
                                        (string) $row['valor']
                                    )
                                ) ?>
                            </strong>

                            <span>
                                <?= number_format($devicePorcentaje, 1) ?>%
                            </span>
                        </div>

                        <div class="google-ads-device__meta">
                            <?= number_format($deviceVisitas) ?> visitas
                            ·
                            <?= number_format($deviceContactos) ?> WhatsApp
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </div>


    <div class="google-ads-detail-card">
        <div class="google-ads-detail-card__header">
            <span>Estructura</span>
            <h2>Grupos de anuncios</h2>
            <p>Rendimiento identificado por grupo de anuncios.</p>
        </div>

        <?php if (empty($grupos)): ?>

            <div class="google-ads-detail-empty">
                Sin grupos de anuncios detectados.
            </div>

        <?php else: ?>

            <div class="google-ads-entity-list">

                <?php foreach ($grupos as $row): ?>

                    <div class="google-ads-entity">

                        <div>
                            <span>ID Google</span>
                            <strong>
                                <?= htmlspecialchars((string) $row['valor']) ?>
                            </strong>
                        </div>

                        <div class="google-ads-entity__stats">
                            <span>
                                <?= number_format((int) $row['visitas']) ?>
                                visitas
                            </span>

                            <span>
                                <?= number_format((int) $row['contactos']) ?>
                                WhatsApp
                            </span>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </div>


    <div class="google-ads-detail-card">
        <div class="google-ads-detail-card__header">
            <span>Creatividades</span>
            <h2>Anuncios</h2>
            <p>Qué anuncios están generando las visitas registradas.</p>
        </div>

        <?php if (empty($anuncios)): ?>

            <div class="google-ads-detail-empty">
                Sin anuncios detectados.
            </div>

        <?php else: ?>

            <div class="google-ads-entity-list">

                <?php foreach ($anuncios as $row): ?>

                    <div class="google-ads-entity">

                        <div>
                            <span>ID anuncio</span>
                            <strong>
                                <?= htmlspecialchars((string) $row['valor']) ?>
                            </strong>
                        </div>

                        <div class="google-ads-entity__stats">
                            <span>
                                <?= number_format((int) $row['visitas']) ?>
                                visitas
                            </span>

                            <span>
                                <?= number_format((int) $row['contactos']) ?>
                                WhatsApp
                            </span>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </div>


    <div class="google-ads-detail-card google-ads-detail-card--wide">
        <div class="google-ads-detail-card__header">
            <span>Destino</span>
            <h2>Landing pages</h2>
            <p>Páginas donde aterrizaron las visitas desde Google Ads.</p>
        </div>

        <?php if (empty($landings)): ?>

            <div class="google-ads-detail-empty">
                Sin landing pages detectadas.
            </div>

        <?php else: ?>

            <div class="google-ads-landing-list">

                <?php foreach ($landings as $row): ?>

                    <?php
                    $landingVisitas = (int) $row['visitas'];
                    $landingContactos = (int) $row['contactos'];

                    $landingTasa = adsTasaContacto(
                        $landingVisitas,
                        $landingContactos
                    );
                    ?>

                    <div class="google-ads-landing">

                        <div>
                            <strong>
                                <?= htmlspecialchars(
                                    adsLandingVisible(
                                        (string) $row['valor']
                                    )
                                ) ?>
                            </strong>

                            <span>
                                <?= number_format($landingVisitas) ?> visitas
                            </span>
                        </div>

                        <div class="google-ads-landing__result">
                            <strong>
                                <?= number_format($landingContactos) ?>
                            </strong>

                            <span>
                                WhatsApp ·
                                <?= number_format($landingTasa, 1) ?>%
                            </span>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </div>

</div>
    
<?php endif; ?>
</main>
