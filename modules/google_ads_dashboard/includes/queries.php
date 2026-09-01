<?php

function adsObtenerCampanasFiltro(PDO $pdo, string $inicio, string $fin): array
{
    $sql = "
        SELECT
            campaign_id,
            COALESCE(
                MAX(NULLIF(campaign_name, '')),
                CONCAT('Campaña ', campaign_id)
            ) AS campaign_name
        FROM google_ads_visits
        WHERE fecha_visita >= :inicio
          AND fecha_visita < :fin
        GROUP BY campaign_id
        ORDER BY campaign_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':inicio' => $inicio,
        ':fin' => $fin,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function adsObtenerResumenCampanas(
    PDO $pdo,
    string $inicio,
    string $fin,
    string $campanaFiltro = ''
): array {
    $sql = "
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
        WHERE gav.fecha_visita >= :inicio
          AND gav.fecha_visita < :fin
          AND (
                :campana_vacia = ''
                OR gav.campaign_id = :campana_id
          )
        GROUP BY gav.campaign_id
        ORDER BY visitas DESC, campaign_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':inicio' => $inicio,
        ':fin' => $fin,
        ':campana_vacia' => $campanaFiltro,
        ':campana_id' => $campanaFiltro,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
