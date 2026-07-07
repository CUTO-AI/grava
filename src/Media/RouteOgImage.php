<?php
declare(strict_types=1);

namespace App\Media;

/**
 * Gebrandetes Routen-Vorschaubild (og:image) — 1200×630 PNG im CYBERRIDE-Look.
 *
 * Zeichnet die stilisierte Streckenlinie aus der gespeicherten Geometrie
 * (KEIN Karten-Tile-Hintergrund → kein Drittanbieter, kein Datenschutz-Thema),
 * dazu Wortmarke, Titel und Distanz/Höhenmeter. Nutzt die Marken-TTFs unter
 * resources/fonts. Fällt bei fehlender GD/Schrift auf eine schlichte Variante
 * zurück (nie ein harter Fehler — Crawler sollen immer ein Bild bekommen).
 */
final class RouteOgImage
{
    private const W = 1200;
    private const H = 630;

    // Zeichenfläche für die Streckenlinie (lässt oben Platz für die Wortmarke,
    // unten für Titel + Stats).
    private const MAP_TOP    = 96;
    private const MAP_BOTTOM = 452;
    private const PAD_X      = 64;

    public function __construct(
        private readonly string $fontDir,
    ) {}

    /**
     * @param list<array{0:float,1:float}> $coords [lon, lat]-Paare (bereits dezimiert)
     * @return string PNG-Bytes
     */
    public function render(array $coords, string $title, ?float $distanceM, ?int $elevM): string
    {
        if (!\function_exists('imagecreatetruecolor')) {
            return $this->fallback();
        }

        $img = imagecreatetruecolor(self::W, self::H);
        imagealphablending($img, true);

        // Farben (CYBERRIDE-Palette).
        $bg     = imagecolorallocate($img, 4, 6, 11);      // #04060B
        $grid   = imagecolorallocate($img, 15, 23, 38);    // #0F1726
        $cyan   = imagecolorallocate($img, 0, 229, 255);   // #00E5FF
        $cyanGlow = imagecolorallocatealpha($img, 0, 229, 255, 92);
        $green  = imagecolorallocate($img, 120, 255, 170);
        $magenta = imagecolorallocate($img, 255, 60, 170);
        $white  = imagecolorallocate($img, 235, 245, 250);
        $muted  = imagecolorallocate($img, 150, 170, 185);

        imagefilledrectangle($img, 0, 0, self::W, self::H, $bg);

        // Feines Grid als Hintergrund.
        for ($x = 0; $x <= self::W; $x += 48) {
            imageline($img, $x, 0, $x, self::H, $grid);
        }
        for ($y = 0; $y <= self::H; $y += 48) {
            imageline($img, 0, $y, self::W, $y, $grid);
        }

        // Streckenlinie.
        $this->drawTrack($img, $coords, $cyan, $cyanGlow, $green, $magenta);

        // Untere Scrim, damit Text auf der Linie lesbar bleibt.
        $scrim = imagecolorallocatealpha($img, 4, 6, 11, 40);
        imagefilledrectangle($img, 0, self::MAP_BOTTOM - 20, self::W, self::H, $scrim);

        // Texte.
        $chakra   = $this->fontDir . '/ChakraPetch-Bold.ttf';
        $rajdhani = $this->fontDir . '/Rajdhani-SemiBold.ttf';
        $hasFonts = is_file($chakra) && is_file($rajdhani) && \function_exists('imagettftext');

        if ($hasFonts) {
            // Wortmarke oben links: „CYBER" weiß + „RIDE" cyan.
            $x = self::PAD_X;
            $x = $this->ttf($img, 26, self::PAD_X, 62, $white, $chakra, 'CYBER');
            $this->ttf($img, 26, $x + 2, 62, $cyan, $chakra, 'RIDE');

            // Titel (unten), auf Breite gekürzt.
            $title = $title !== '' ? $title : 'Route';
            $fitted = $this->fit($chakra, 46, $title, self::W - 2 * self::PAD_X);
            $this->ttf($img, 46, self::PAD_X, 512, $white, $chakra, $fitted);

            // Stats-Zeile.
            $this->ttf($img, 28, self::PAD_X, 566, $cyan, $rajdhani, $this->statsLine($distanceM, $elevM));

            // Domain unten rechts.
            $dom = 'cyberride.world';
            $w = $this->textWidth($rajdhani, 22, $dom);
            $this->ttf($img, 22, self::W - self::PAD_X - $w, 588, $muted, $rajdhani, $dom);
        } else {
            // Bitmap-Fallback (ohne TTF).
            imagestring($img, 5, self::PAD_X, 40, 'CYBERRIDE', $cyan);
            imagestring($img, 5, self::PAD_X, 520, substr($title !== '' ? $title : 'Route', 0, 80), $white);
            imagestring($img, 4, self::PAD_X, 560, $this->statsLine($distanceM, $elevM), $muted);
        }

        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /**
     * Projiziert [lon,lat] äquirektangulär (mit cos-lat-Korrektur) in die
     * Zeichenfläche und zeichnet die Linie mit Glow + Start-/Endpunkt.
     *
     * @param \GdImage $img
     * @param list<array{0:float,1:float}> $coords
     */
    private function drawTrack($img, array $coords, int $cyan, int $cyanGlow, int $green, int $magenta): void
    {
        $pts = [];
        foreach ($coords as $c) {
            if (isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                $pts[] = [(float)$c[0], (float)$c[1]];
            }
        }
        if (count($pts) < 2) {
            return;
        }

        $lons = array_column($pts, 0);
        $lats = array_column($pts, 1);
        $minLon = min($lons); $maxLon = max($lons);
        $minLat = min($lats); $maxLat = max($lats);
        $midLat = ($minLat + $maxLat) / 2;
        $kx = cos(deg2rad($midLat)); // Längengrad-Stauchung

        $spanX = max(1e-9, ($maxLon - $minLon) * $kx);
        $spanY = max(1e-9, ($maxLat - $minLat));

        $rectX0 = self::PAD_X;
        $rectX1 = self::W - self::PAD_X;
        $rectY0 = self::MAP_TOP;
        $rectY1 = self::MAP_BOTTOM;
        $rw = $rectX1 - $rectX0;
        $rh = $rectY1 - $rectY0;

        $scale = min($rw / $spanX, $rh / $spanY);
        // Zentrieren.
        $offX = $rectX0 + ($rw - $spanX * $scale) / 2;
        $offY = $rectY0 + ($rh - $spanY * $scale) / 2;

        $screen = [];
        foreach ($pts as [$lon, $lat]) {
            $x = $offX + (($lon - $minLon) * $kx) * $scale;
            $y = $offY + ($maxLat - $lat) * $scale; // y invertiert
            $screen[] = [(int)round($x), (int)round($y)];
        }

        // Glow (dick, transparent) unter der hellen Linie.
        imagesetthickness($img, 12);
        for ($i = 1; $i < count($screen); $i++) {
            imageline($img, $screen[$i - 1][0], $screen[$i - 1][1], $screen[$i][0], $screen[$i][1], $cyanGlow);
        }
        // Helle Linie.
        imagesetthickness($img, 5);
        for ($i = 1; $i < count($screen); $i++) {
            imageline($img, $screen[$i - 1][0], $screen[$i - 1][1], $screen[$i][0], $screen[$i][1], $cyan);
        }
        imagesetthickness($img, 1);

        // Start-/Endpunkt.
        $s = $screen[0];
        $e = $screen[count($screen) - 1];
        imagefilledellipse($img, $s[0], $s[1], 22, 22, $green);
        imagefilledellipse($img, $e[0], $e[1], 22, 22, $magenta);
    }

    private function statsLine(?float $distanceM, ?int $elevM): string
    {
        $parts = [];
        if ($distanceM !== null && $distanceM > 0) {
            $parts[] = number_format($distanceM / 1000, 1) . ' km';
        }
        if ($elevM !== null && $elevM > 0) {
            $parts[] = '+' . $elevM . ' hm';
        }
        return $parts === [] ? 'GRAVEL · BIKEPACKING' : implode('   ·   ', $parts);
    }

    /** imagettftext-Wrapper; gibt die End-X-Position zurück. */
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

    /** Kürzt Text mit „…", bis er in maxWidth passt. */
    private function fit(string $font, int $size, string $text, int $maxWidth): string
    {
        if ($this->textWidth($font, $size, $text) <= $maxWidth) {
            return $text;
        }
        $ell = '…';
        while ($text !== '' && $this->textWidth($font, $size, $text . $ell) > $maxWidth) {
            $text = function_exists('mb_substr')
                ? mb_substr($text, 0, mb_strlen($text) - 1)
                : substr($text, 0, -1);
        }
        return $text . $ell;
    }

    /** Minimales 1×1-PNG, falls GD komplett fehlt. */
    private function fallback(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}
