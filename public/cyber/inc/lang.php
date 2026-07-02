<?php
/**
 * Sprach-Auflösung für die Landing (self-contained, ohne App-Framework, damit
 * die Seite auch bei Direktaufruf /cyber/ funktioniert).
 *
 * Standard: Englisch. `?lang=de` / `?lang=en` überschreibt und persistiert ein
 * 1-Jahres-Cookie. Fehlende Keys fallen automatisch auf Englisch zurück.
 *
 * Exponiert:
 *   $CR_LANG : 'en' | 'de'
 *   $T       : Übersetzungs-Array der aktuellen Sprache (mit EN-Fallback)
 */
$supported = ['en', 'de'];
$lang = $_GET['lang'] ?? ($_COOKIE['lang'] ?? 'en');
$lang = in_array($lang, $supported, true) ? $lang : 'en';

if (isset($_GET['lang'])) {
    $secure = (($_SERVER['HTTPS'] ?? '') !== '')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('lang', $lang, [
        'expires'  => time() + 31536000, // 1 Jahr
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['lang'] = $lang;
}

$CR_LANG = $lang;

$base = require __DIR__ . '/../lang/en.php';
$T = $base;
if ($lang !== 'en') {
    $over = require __DIR__ . '/../lang/' . $lang . '.php';
    // Rekursiver Merge, damit fehlende Übersetzungen auf EN zurückfallen.
    $merge = static function (array $b, array $o) use (&$merge): array {
        foreach ($o as $k => $v) {
            $b[$k] = (is_array($v) && isset($b[$k]) && is_array($b[$k])) ? $merge($b[$k], $v) : $v;
        }
        return $b;
    };
    $T = $merge($base, $over);
}
