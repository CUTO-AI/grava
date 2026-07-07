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

    /** „Landkreis erobert" (A1). $ownerType: 'group'|'rider'. */
    public function regionTaken(string $region, string $owner, string $ownerType, float $fraction, string $lang): string
    {
        $lang = $this->normalizeLang($lang);
        $url  = rtrim($this->publicWebUrl, '/');
        $pct  = (int)round(max(0.0, min(1.0, $fraction)) * 100);

        if ($lang === 'de') {
            $subject = $ownerType === 'group' ? "Crew {$owner}" : $owner;
            return $this->clamp("⚡ Neuer Herrscher im {$region}: {$subject} übernimmt die Kontrolle ({$pct} % gehalten). Wer holt ihn zurück? 🗺️ {$url}");
        }
        $subject = $ownerType === 'group' ? "crew {$owner}" : $owner;
        return $this->clamp("⚡ New ruler of {$region}: {$subject} takes control ({$pct}% held). Who takes it back? 🗺️ {$url} #CYBERRIDE");
    }

    /** „Rush-Ergebnis" (B2). */
    public function rushResult(string $crew, int $edges, int $riders, float $multiplier, string $lang): string
    {
        $lang = $this->normalizeLang($lang);
        $url  = rtrim($this->publicWebUrl, '/');
        $mult = $this->fmtMultiplier($multiplier);

        if ($lang === 'de') {
            $rider = $riders === 1 ? '1 Fahrer' : "{$riders} Fahrer";
            return $this->clamp("🏁 Rush beendet: {$crew} — {$rider}, {$mult}, {$edges} Kanten erobert. 💪 {$url}");
        }
        $rider = $riders === 1 ? '1 rider' : "{$riders} riders";
        return $this->clamp("🏁 Rush done: {$crew} — {$rider}, {$mult}, {$edges} edges taken. 💪 {$url} #CYBERRIDE");
    }

    /**
     * „Fraktions-Wochenstand" (C2).
     * @param list<array{name:string,key:string,share:int,edges:int,len_m:float}> $factions
     */
    public function factionStanding(array $factions, string $lang): string
    {
        $lang = $this->normalizeLang($lang);
        $url  = rtrim($this->publicWebUrl, '/');
        $top  = array_slice($factions, 0, 2);
        $line = implode(' · ', array_map(
            fn($f) => $this->factionEmoji((string)$f['key']) . ' ' . $f['name'] . ' ' . $f['share'] . '%',
            $top,
        ));

        if ($lang === 'de') {
            return $this->clamp("Fraktions-Wochenstand — {$line}. #FactionWar {$url}");
        }
        return $this->clamp("Faction standings — {$line}. #FactionWar {$url}");
    }

    private function factionEmoji(string $key): string
    {
        return match ($key) {
            'green' => '🟩',
            'blue'  => '🟦',
            default => '⬛',
        };
    }

    private function fmtMultiplier(float $m): string
    {
        $s = rtrim(rtrim(number_format($m, 1, '.', ''), '0'), '.');
        return '×' . ($s === '' ? '0' : $s);
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
