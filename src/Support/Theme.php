<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Parallelbetrieb Alt-/Cyber-Design.
 *
 * `?theme=cyber` schaltet einen Besucher auf das neue Design und persistiert die
 * Wahl in einem 30-Tage-Cookie; `?theme=classic` schaltet zurück. Ohne Query
 * zählt das zuvor gesetzte Cookie. Default: klassisch.
 *
 * Genutzt vom LandingController (eigenständige Cyber-Landing) und von WebView
 * (pro-View-Migration: rendert eine `views/web/cyber/<view>.php`, falls vorhanden).
 */
final class Theme
{
    public const COOKIE = 'theme';

    /** Löst den Theme-Wunsch auf und persistiert ihn bei ?theme=…. */
    public static function wantsCyber(): bool
    {
        $theme = $_GET[self::COOKIE] ?? ($_COOKIE[self::COOKIE] ?? 'classic');
        $theme = $theme === 'cyber' ? 'cyber' : 'classic';

        if (isset($_GET[self::COOKIE])) {
            $secure = (($_SERVER['HTTPS'] ?? '') !== '')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(self::COOKIE, $theme, [
                'expires'  => time() + 2592000, // 30 Tage
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,            // rein kosmetisch, kein Secret
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $theme;
        }

        return $theme === 'cyber';
    }
}
