<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Sprach-keyed Textbausteine für die automatischen Posts (Konzept §7/E4).
 * Go-Live-Sprache ist Englisch; `de` ist bereits hinterlegt und wird per
 * Toggle zuschaltbar (Ausbau F) — kein Code-Umbau nötig.
 *
 * Fakten (Zahlen/Namen) sind deterministisch; hier wird nur formuliert. Der
 * fertige Text wird hart auf {@see PostCopy::MAX_LEN} Zeichen begrenzt.
 */
final class PostCopy
{
    /** X/Twitter-Limit. Links zählen zwar als 23 Zeichen (t.co), aber wir
     *  bleiben konservativ und rechnen sie voll mit. */
    public const MAX_LEN = 280;

    public const SUPPORTED_LANGS = ['en', 'de'];

    public function __construct(private readonly string $publicWebUrl) {}

    /** Fällt bei unbekannter Sprache auf 'en' zurück. */
    public function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return in_array($lang, self::SUPPORTED_LANGS, true) ? $lang : 'en';
    }

    public function dailyReport(DailyReport $r, string $lang): string
    {
        $lang = $this->normalizeLang($lang);
        $url  = rtrim($this->publicWebUrl, '/');

        if ($lang === 'de') {
            $parts = [
                "{$r->rides} Fahrten",
                $this->kmDe($r->distanceKm),
                "{$r->edgesTakenOver} Kanten übernommen",
            ];
            if ($r->countiesChanged > 0) {
                $parts[] = $r->countiesChanged === 1
                    ? '1 Landkreis gewechselt'
                    : "{$r->countiesChanged} Landkreise gewechselt";
            }
            $line = "📊 Tag im Netz — " . implode(' · ', $parts) . '.';
            if ($r->rushCrewName !== null) {
                $line .= " Rush des Tages: {$r->rushCrewName}.";
            }
            $line .= " Morgen mehr. ⚡ {$url}";
            return $this->clamp($line);
        }

        // en (Go-Live)
        $parts = [
            "{$r->rides} rides",
            $this->kmEn($r->distanceKm),
            "{$r->edgesTakenOver} edges taken over",
        ];
        if ($r->countiesChanged > 0) {
            $parts[] = $r->countiesChanged === 1
                ? '1 county changed hands'
                : "{$r->countiesChanged} counties changed hands";
        }
        $line = "📊 Today on the grid — " . implode(' · ', $parts) . '.';
        if ($r->rushCrewName !== null) {
            $line .= " Rush of the day: {$r->rushCrewName}.";
        }
        $line .= " More tomorrow. ⚡ {$url}";
        return $this->clamp($line);
    }

    private function kmEn(float $km): string
    {
        return number_format($km, ($km >= 100 ? 0 : 1), '.', ',') . ' km';
    }

    private function kmDe(float $km): string
    {
        return number_format($km, ($km >= 100 ? 0 : 1), ',', '.') . ' km';
    }

    /** Hält den Text unter dem Zeichenlimit; kürzt notfalls am Wortende. */
    private function clamp(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LEN) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::MAX_LEN - 1);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > self::MAX_LEN - 40) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut) . '…';
    }
}
