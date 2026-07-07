<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * Rendert CYBERRIDE-Karten (1200×675 PNG) für die automatischen Posts
 * (Twitter_Automation_Concept.md §6/E6). Eigenständig gehalten (nutzt dieselbe
 * Palette/Technik wie {@see \App\Media\RouteOgImage}, ohne diese anzufassen).
 *
 * Best-effort: ohne GD/Schrift liefert render() null → der Post geht dann
 * text-only raus. Wirft nie.
 */
final class SocialCardRenderer
{
    private const W = 1200;
    private const H = 675;
    private const PAD = 64;
    private const ART_TOP = 150;   // Bühne für Grafik/Stats
    private const ART_BOTTOM = 470;

    public function __construct(
        private readonly string $fontDir,
        private readonly ?PDO $pdo = null,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @return string|null PNG-Bytes oder null (nicht renderbar → text-only)
     */
    public function render(string $kind, array $payload): ?string
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagettftext')) {
            return null;
        }
        $chakra   = $this->fontDir . '/ChakraPetch-Bold.ttf';
        $rajdhani = $this->fontDir . '/Rajdhani-SemiBold.ttf';
        if (!is_file($chakra) || !is_file($rajdhani)) {
            return null;
        }

        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        $c = [
            'bg'      => imagecolorallocate($img, 4, 6, 11),
            'grid'    => imagecolorallocate($img, 15, 23, 38),
            'cyan'    => imagecolorallocate($img, 0, 229, 255),
            'magenta' => imagecolorallocate($img, 255, 30, 111),
            'lime'    => imagecolorallocate($img, 182, 255, 46),
            'white'   => imagecolorallocate($img, 235, 245, 250),
            'muted'   => imagecolorallocate($img, 150, 170, 185),
        ];
        $accent = $this->accent($img, $kind, $c);

        // Hintergrund + Grid.
        imagefilledrectangle($img, 0, 0, self::W, self::H, $c['bg']);
        for ($x = 0; $x <= self::W; $x += 48) {
            imageline($img, $x, 0, $x, self::H, $c['grid']);
        }
        for ($y = 0; $y <= self::H; $y += 48) {
            imageline($img, 0, $y, self::W, $y, $c['grid']);
        }

        // Kopf: Wortmarke + Meldungstyp-Label + Akzentlinie.
        $x = $this->ttf($img, 28, self::PAD, 76, $c['white'], $chakra, 'CYBER');
        $this->ttf($img, 28, $x + 2, 76, $c['cyan'], $chakra, 'RIDE');
        $label = $this->kindLabel($kind);
        $lw = $this->textWidth($chakra, 20, $label);
        $this->ttf($img, 20, self::W - self::PAD - $lw, 74, $accent, $chakra, $label);
        imagesetthickness($img, 3);
        imageline($img, self::PAD, 96, self::W - self::PAD, 96, $accent);
        imagesetthickness($img, 1);

        // Inhalt je Meldungstyp.
        switch ($kind) {
            case 'region_taken':    $this->cardRegion($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'rush_result':     $this->cardRush($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'faction_standing':$this->cardFaction($img, $payload, $c, $chakra, $rajdhani); break;
            case 'daily_report':    $this->cardDaily($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'badge_earned':    $this->cardBadge($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'record_beaten':   $this->cardRecord($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'weekly_recap':    $this->cardWeekly($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            case 'community_milestone': $this->cardMilestone($img, $payload, $c, $accent, $chakra, $rajdhani); break;
            default:
                imagedestroy($img);
                return null;
        }

        // Domain unten rechts.
        $dom = 'cyberride.world';
        $w = $this->textWidth($rajdhani, 24, $dom);
        $this->ttf($img, 24, self::W - self::PAD - $w, self::H - 40, $c['muted'], $rajdhani, $dom);

        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    // ---- Karten je Typ ----------------------------------------------------

    /** @param array<string,mixed> $p */
    private function cardRegion($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $glow = imagecolorallocatealpha($img, 255, 30, 111, 96);
        $regionId = (int)($p['region_id'] ?? 0);
        $rings = $regionId > 0 ? $this->regionRings($regionId) : [];
        if ($rings !== []) {
            $this->drawPolygon($img, $rings, $accent, $glow);
        }

        $region = (string)($p['region'] ?? '');
        $owner  = (string)($p['owner'] ?? '');
        $pct    = (int)round(((float)($p['held_fraction'] ?? 0)) * 100);

        $this->ttf($img, 24, self::PAD, self::ART_TOP - 24, $accent, $chakra, 'TERRITORY CAPTURED');
        $head = $this->fit($chakra, 54, $region, self::W - 2 * self::PAD);
        $this->ttf($img, 54, self::PAD, self::ART_BOTTOM + 78, $c['white'], $chakra, $head);
        $sub = $owner . '   ·   ' . $pct . '% held';
        $this->ttf($img, 30, self::PAD, self::ART_BOTTOM + 128, $accent, $rajdhani, $sub);
    }

    /** @param array<string,mixed> $p */
    private function cardRush($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $edges  = (int)($p['edges'] ?? 0);
        $crew   = (string)($p['crew'] ?? '');
        $riders = (int)($p['riders'] ?? 0);
        $mult   = (float)($p['multiplier'] ?? 0);
        $multS  = '×' . rtrim(rtrim(number_format($mult, 1, '.', ''), '0'), '.');

        $this->ttf($img, 26, self::PAD, self::ART_TOP + 20, $accent, $chakra, 'RUSH COMPLETE');
        $this->ttf($img, 150, self::PAD, self::ART_BOTTOM, $accent, $chakra, (string)$edges);
        $this->ttf($img, 34, self::PAD, self::ART_BOTTOM + 60, $c['white'], $rajdhani, 'EDGES CAPTURED');
        $sub = $this->fit($rajdhani, 34, $crew . '   ·   ' . $riders . ' riders   ·   ' . $multS, self::W - 2 * self::PAD);
        $this->ttf($img, 34, self::PAD, self::ART_BOTTOM + 120, $accent, $rajdhani, $sub);
    }

    /** @param array<string,mixed> $p */
    private function cardFaction($img, array $p, array $c, string $chakra, string $rajdhani): void
    {
        $factions = is_array($p['factions'] ?? null) ? array_slice($p['factions'], 0, 2) : [];
        $this->ttf($img, 26, self::PAD, self::ART_TOP + 10, $c['cyan'], $chakra, 'FACTION STANDINGS');

        $barX = self::PAD;
        $barW = self::W - 2 * self::PAD;
        $y = self::ART_TOP + 60;
        foreach ($factions as $f) {
            $share = max(0, min(100, (int)($f['share'] ?? 0)));
            $col   = $this->factionColor($img, (string)($f['key'] ?? ''), $c);
            $fillW = (int)round($barW * $share / 100);
            imagefilledrectangle($img, $barX, $y, $barX + $barW, $y + 54, $c['grid']);
            if ($fillW > 0) {
                imagefilledrectangle($img, $barX, $y, $barX + $fillW, $y + 54, $col);
            }
            $this->ttf($img, 30, $barX + 14, $y + 40, $c['white'], $rajdhani, (string)($f['name'] ?? ''));
            $pctS = $share . '%';
            $pw = $this->textWidth($chakra, 34, $pctS);
            $this->ttf($img, 34, $barX + $barW - $pw - 14, $y + 42, $c['white'], $chakra, $pctS);
            $y += 96;
        }
        $this->ttf($img, 26, self::PAD, self::ART_BOTTOM + 110, $c['muted'], $rajdhani, '#FactionWar');
    }

    /** @param array<string,mixed> $p */
    private function cardDaily($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $this->ttf($img, 26, self::PAD, self::ART_TOP - 10, $accent, $chakra, 'TODAY ON THE GRID');
        $km = (float)($p['distance_km'] ?? 0);
        $stats = [
            [(string)(int)($p['rides'] ?? 0),            'RIDES'],
            [number_format($km, ($km >= 100 ? 0 : 1), '.', ','), 'KM'],
            [(string)(int)($p['edges_taken_over'] ?? 0), 'EDGES TAKEN'],
            [(string)(int)($p['counties_changed'] ?? 0), 'COUNTIES'],
        ];
        $colW = (self::W - 2 * self::PAD) / 2;
        foreach ($stats as $i => [$val, $lbl]) {
            $cx = self::PAD + ($i % 2) * $colW;
            $cy = self::ART_TOP + 60 + intdiv($i, 2) * 150;
            $this->ttf($img, 76, (int)$cx, (int)$cy, $accent, $chakra, $val);
            $this->ttf($img, 26, (int)$cx, (int)$cy + 40, $c['muted'], $rajdhani, $lbl);
        }
        if (($p['rush_crew'] ?? null) !== null) {
            $this->ttf($img, 28, self::PAD, self::H - 44, $c['white'], $rajdhani, 'Rush of the day: ' . (string)$p['rush_crew']);
        }
    }

    /** @param array<string,mixed> $p */
    private function cardBadge($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $fam    = (string)($p['family_label'] ?? ($p['family'] ?? ''));
        $tier   = (string)($p['tier_name'] ?? '');
        $handle = (string)($p['handle'] ?? '');

        $this->ttf($img, 26, self::PAD, self::ART_TOP + 20, $accent, $chakra, 'RARE BADGE UNLOCKED');
        $head = $this->fit($chakra, 100, $fam, self::W - 2 * self::PAD);
        $this->ttf($img, 100, self::PAD, self::ART_BOTTOM - 30, $c['white'], $chakra, $head);
        $this->ttf($img, 40, self::PAD, self::ART_BOTTOM + 46, $accent, $chakra, strtoupper($tier));
        $this->ttf($img, 32, self::PAD, self::ART_BOTTOM + 110, $c['muted'], $rajdhani, $handle);
    }

    /** @param array<string,mixed> $p */
    private function cardRecord($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $handle = (string)($p['handle'] ?? '');
        $region = ($p['region'] ?? null) !== null ? (string)$p['region'] : null;
        $speed  = isset($p['avg_speed_kmh']) && is_numeric($p['avg_speed_kmh']) ? (float)$p['avg_speed_kmh'] : null;

        $this->ttf($img, 26, self::PAD, self::ART_TOP + 20, $accent, $chakra, 'NEW KOM');
        if ($speed !== null) {
            $this->ttf($img, 120, self::PAD, self::ART_BOTTOM, $accent, $chakra, number_format($speed, 1, '.', ',') . ' km/h');
        } else {
            $this->ttf($img, 96, self::PAD, self::ART_BOTTOM - 10, $accent, $chakra, 'FASTEST TIME');
        }
        $sub = $handle . ($region !== null ? '   ·   ' . $region : '');
        $this->ttf($img, 34, self::PAD, self::ART_BOTTOM + 80, $c['white'], $rajdhani, $this->fit($rajdhani, 34, $sub, self::W - 2 * self::PAD));
    }

    /** @param array<string,mixed> $p */
    private function cardWeekly($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $this->ttf($img, 26, self::PAD, self::ART_TOP - 10, $accent, $chakra, 'WEEK ON THE GRID');
        $km = (float)($p['distance_km'] ?? 0);
        $stats = [
            [(string)(int)($p['rides'] ?? 0),            'RIDES'],
            [number_format($km, ($km >= 100 ? 0 : 1), '.', ','), 'KM'],
            [(string)(int)($p['edges_taken_over'] ?? 0), 'EDGES TAKEN'],
            [(string)(int)($p['counties_changed'] ?? 0), 'COUNTIES'],
        ];
        $colW = (self::W - 2 * self::PAD) / 2;
        foreach ($stats as $i => [$val, $lbl]) {
            $cx = self::PAD + ($i % 2) * $colW;
            $cy = self::ART_TOP + 60 + intdiv($i, 2) * 150;
            $this->ttf($img, 76, (int)$cx, (int)$cy, $accent, $chakra, $val);
            $this->ttf($img, 26, (int)$cx, (int)$cy + 40, $c['muted'], $rajdhani, $lbl);
        }
    }

    /** @param array<string,mixed> $p */
    private function cardMilestone($img, array $p, array $c, int $accent, string $chakra, string $rajdhani): void
    {
        $km = (int)($p['threshold_km'] ?? 0);
        $this->ttf($img, 26, self::PAD, self::ART_TOP + 30, $accent, $chakra, 'COMMUNITY MILESTONE');
        $this->ttf($img, 120, self::PAD, self::ART_BOTTOM - 10, $accent, $chakra, number_format($km, 0, '.', ',') . ' km');
        $this->ttf($img, 34, self::PAD, self::ART_BOTTOM + 70, $c['white'], $rajdhani, 'ridden together on gravel');
    }

    // ---- Geometrie --------------------------------------------------------

    /**
     * Lädt die (vereinfachte) Grenze eines Gebiets und liefert eine Liste von
     * Ringen ([[lon,lat],…]). Polygon + MultiPolygon werden unterstützt.
     *
     * @return list<list<array{0:float,1:float}>>
     */
    private function regionRings(int $regionId): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT boundary_geojson FROM game_region WHERE id = ? LIMIT 1');
            $stmt->execute([$regionId]);
            $raw = (string)($stmt->fetchColumn() ?: '');
        } catch (\PDOException $e) {
            error_log('social card: Grenze laden fehlgeschlagen: ' . $e->getMessage());
            return [];
        }
        if ($raw === '') {
            return [];
        }
        $geo = json_decode($raw, true);
        if (!is_array($geo) || !isset($geo['type'], $geo['coordinates'])) {
            return [];
        }
        $rings = [];
        $collect = static function (array $ring) use (&$rings): void {
            $pts = [];
            foreach ($ring as $c) {
                if (isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                    $pts[] = [(float)$c[0], (float)$c[1]];
                }
            }
            if (count($pts) >= 3) {
                $rings[] = $pts;
            }
        };
        if ($geo['type'] === 'Polygon') {
            foreach ($geo['coordinates'] as $ring) {
                if (is_array($ring)) { $collect($ring); }
            }
        } elseif ($geo['type'] === 'MultiPolygon') {
            foreach ($geo['coordinates'] as $poly) {
                if (is_array($poly)) {
                    foreach ($poly as $ring) {
                        if (is_array($ring)) { $collect($ring); }
                    }
                }
            }
        }
        return $rings;
    }

    /**
     * Projiziert alle Ringe gemeinsam (äquirektangulär, cos-lat) in die Bühne,
     * füllt den größten Ring translucent und zeichnet alle Umrisse mit Glow.
     *
     * @param list<list<array{0:float,1:float}>> $rings
     */
    private function drawPolygon($img, array $rings, int $accent, int $glow): void
    {
        $all = array_merge(...$rings);
        $lons = array_column($all, 0);
        $lats = array_column($all, 1);
        $minLon = min($lons); $maxLon = max($lons);
        $minLat = min($lats); $maxLat = max($lats);
        $midLat = ($minLat + $maxLat) / 2;
        $kx = cos(deg2rad($midLat));
        $spanX = max(1e-9, ($maxLon - $minLon) * $kx);
        $spanY = max(1e-9, ($maxLat - $minLat));

        $rw = self::W - 2 * self::PAD;
        $rh = self::ART_BOTTOM - self::ART_TOP;
        $scale = min($rw / $spanX, $rh / $spanY);
        $offX = self::PAD + ($rw - $spanX * $scale) / 2;
        $offY = self::ART_TOP + ($rh - $spanY * $scale) / 2;

        $project = function (array $ring) use ($offX, $offY, $minLon, $maxLat, $kx, $scale): array {
            $flat = [];
            foreach ($ring as [$lon, $lat]) {
                $flat[] = (int)round($offX + (($lon - $minLon) * $kx) * $scale);
                $flat[] = (int)round($offY + ($maxLat - $lat) * $scale);
            }
            return $flat;
        };

        // Größten Ring füllen (translucent).
        usort($rings, static fn($a, $b) => count($b) <=> count($a));
        $main = $project($rings[0]);
        if (count($main) >= 6) {
            imagefilledpolygon($img, $main, $glow);
        }
        // Alle Umrisse.
        imagesetthickness($img, 4);
        foreach ($rings as $ring) {
            $flat = $project($ring);
            if (count($flat) >= 6) {
                imagepolygon($img, $flat, $accent);
            }
        }
        imagesetthickness($img, 1);
    }

    // ---- Helfer -----------------------------------------------------------

    private function accent($img, string $kind, array $c): int
    {
        return match ($kind) {
            'region_taken' => $c['magenta'],
            'rush_result'  => $c['lime'],
            'badge_earned' => $c['lime'],
            default        => $c['cyan'],
        };
    }

    private function factionColor($img, string $key, array $c): int
    {
        return match ($key) {
            'green' => imagecolorallocate($img, 46, 160, 67),
            'blue'  => imagecolorallocate($img, 31, 111, 235),
            default => $c['muted'],
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'region_taken'     => 'TERRITORY',
            'rush_result'      => 'RUSH',
            'faction_standing' => 'FACTIONS',
            'daily_report'     => 'DAILY',
            'badge_earned'     => 'BADGE',
            'record_beaten'    => 'KOM',
            'weekly_recap'     => 'WEEKLY',
            'community_milestone' => 'MILESTONE',
            default            => 'CYBERRIDE',
        };
    }

    private function ttf($img, int $size, int $x, int $y, int $color, string $font, string $text): int
    {
        $bbox = imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
        return is_array($bbox) ? (int)$bbox[2] : $x;
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $bbox = imagettfbbox($size, 0, $font, $text);
        return is_array($bbox) ? (int)abs($bbox[2] - $bbox[0]) : 0;
    }

    private function fit(string $font, int $size, string $text, int $maxWidth): string
    {
        if ($this->textWidth($font, $size, $text) <= $maxWidth) {
            return $text;
        }
        $ell = '…';
        while ($text !== '' && $this->textWidth($font, $size, $text . $ell) > $maxWidth) {
            $text = mb_substr($text, 0, mb_strlen($text) - 1);
        }
        return $text . $ell;
    }
}
