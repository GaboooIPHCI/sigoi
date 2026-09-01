<?php

function adsObtenerDetalleCampana(
    PDO $pdo,
    string $campanaId,
    string $inicio,
    string $fin
): array {
    $sqlCampana = "
        SELECT
            gav.campaign_id,
            COALESCE(
                MAX(NULLIF(gav.campaign_name, '')),
                CONCAT('Campaña ', gav.campaign_id)
            ) AS campaign_name,
            COUNT(DISTINCT gav.id) AS visitas,
            COUNT(DISTINCT CASE
                WHEN wc.link_key LIKE 'whatsapp_%'
                THEN gav.session_id
            END) AS sesiones_whatsapp,
            COUNT(DISTINCT CASE
                WHEN wc.link_key LIKE 'whatsapp_%'
                THEN wc.id
            END) AS clics_whatsapp
        FROM google_ads_visits gav
        LEFT JOIN web_clicks wc
            ON wc.google_ads_session_id = gav.session_id
        WHERE gav.campaign_id = :campana
          AND gav.fecha_visita >= :inicio
          AND gav.fecha_visita < :fin
        GROUP BY gav.campaign_id
    ";

    $stmt = $pdo->prepare($sqlCampana);
    $stmt->execute([
        ':campana' => $campanaId,
        ':inicio' => $inicio,
        ':fin' => $fin,
    ]);

    $campana = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'campana' => $campana,
        'keywords' => adsDetalleAgrupado($pdo, 'keyword', $campanaId, $inicio, $fin),
        'devices' => adsDetalleAgrupado($pdo, 'device', $campanaId, $inicio, $fin),
        'grupos_anuncios' => adsDetalleAgrupado($pdo, 'adgroup_id', $campanaId, $inicio, $fin),
        'anuncios' => adsDetalleAgrupado($pdo, 'creative_id', $campanaId, $inicio, $fin),
        'landings' => adsDetalleAgrupado($pdo, 'landing_page', $campanaId, $inicio, $fin),
    ];
}

function adsDetalleAgrupado(
    PDO $pdo,
    string $campo,
    string $campanaId,
    string $inicio,
    string $fin
): array {
    $camposPermitidos = [
        'keyword',
        'device',
        'adgroup_id',
        'creative_id',
        'landing_page',
    ];

    if (!in_array($campo, $camposPermitidos, true)) {
        return [];
    }

    $sql = "
        SELECT
            COALESCE(NULLIF(gav.$campo, ''), 'Sin dato') AS valor,
            COUNT(DISTINCT gav.id) AS visitas,
            COUNT(DISTINCT CASE
                WHEN wc.link_key LIKE 'whatsapp_%'
                THEN gav.session_id
            END) AS contactos
        FROM google_ads_visits gav
        LEFT JOIN web_clicks wc
            ON wc.google_ads_session_id = gav.session_id
        WHERE gav.campaign_id = :campana
          AND gav.fecha_visita >= :inicio
          AND gav.fecha_visita < :fin
        GROUP BY gav.$campo
        ORDER BY visitas DESC, valor ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':campana' => $campanaId,
        ':inicio' => $inicio,
        ':fin' => $fin,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
