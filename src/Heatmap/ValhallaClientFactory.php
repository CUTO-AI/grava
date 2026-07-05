<?php
declare(strict_types=1);

namespace App\Heatmap;

use App\Config\Config;

/**
 * Baut den passenden {@see ValhallaClient} aus der Konfiguration:
 *  - nur `VALHALLA_URL_EU`/`VALHALLA_BASE_URL` gesetzt → Einzel-Instanz (bisher).
 *  - zusätzlich `VALHALLA_URL_US` gesetzt → {@see RegionalValhallaClient}, der pro
 *    Fahrt anhand der Koordinaten Europa- bzw. Nordamerika-Instanz wählt.
 *
 * So bleibt Europa-only ein reiner .env-Zustand; USA wird durch Setzen einer
 * zweiten URL aktiv, ohne Code-Änderung. Weitere Kontinente später analog.
 */
final class ValhallaClientFactory
{
    /** Grobe, disjunkte Kontinent-Boxen [minLon, minLat, maxLon, maxLat]. */
    private const BBOX_EU = [-32.0, 27.0, 69.0, 82.0];
    private const BBOX_US = [-172.0, 5.0, -52.0, 84.0];

    public static function fromConfig(Config $config): ValhallaClient
    {
        $costing = (string)($config->get('VALHALLA_COSTING', 'bicycle') ?? 'bicycle');

        // EU = Primär/Fallback: neue VALHALLA_URL_EU bevorzugt, sonst die bisherigen
        // Schlüssel (Rückwärtskompatibilität mit dem Einzel-Instanz-Setup).
        $euUrl = (string)($config->get(
            'VALHALLA_URL_EU',
            $config->get('VALHALLA_BASE_URL', $config->get('VALHALLA_URL', 'http://localhost:8002'))
        ) ?? 'http://localhost:8002');
        $usUrl = trim((string)($config->get('VALHALLA_URL_US', '') ?? ''));

        $eu = new ValhallaClient($euUrl, $costing);
        if ($usUrl === '') {
            return $eu;
        }

        $us = new ValhallaClient($usUrl, $costing);
        $regions = [
            ['name' => 'eu', 'bbox' => self::BBOX_EU, 'client' => $eu],
            ['name' => 'us', 'bbox' => self::BBOX_US, 'client' => $us],
        ];
        return new RegionalValhallaClient($regions, $eu, $euUrl, $costing);
    }
}
