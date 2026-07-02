<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Site-weite Zwei-Sprachigkeit (EN/DE) für die Web-Views.
 *
 * Quellsprache im Markup ist Deutsch: `t('Anmelden')`. Für DE gibt t() den
 * Schlüssel selbst zurück, für EN die Übersetzung aus translations/en.php
 * (fehlt sie, Fallback auf den deutschen Text). Standard-Sprache: Englisch.
 *
 * Sprachwahl via `?lang=en|de` (persistiert 1-Jahres-Cookie `lang`, dasselbe
 * Cookie wie die Landing). Ohne Query zählt das Cookie.
 */
final class I18n
{
    public const COOKIE = 'lang';
    private const SUPPORTED = ['en', 'de'];

    private static string $locale = 'en';
    /** @var array<string,string> de => en */
    private static array $map = [];

    public static function boot(string $basePath): void
    {
        $l = $_GET[self::COOKIE] ?? ($_COOKIE[self::COOKIE] ?? 'en');
        $l = in_array($l, self::SUPPORTED, true) ? $l : 'en';

        if (isset($_GET[self::COOKIE])) {
            $secure = (($_SERVER['HTTPS'] ?? '') !== '')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(self::COOKIE, $l, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $l;
        }

        self::$locale = $l;
        if ($l !== 'de') {
            $f = rtrim($basePath, '/') . '/translations/' . $l . '.php';
            $map = is_file($f) ? require $f : [];
            self::$map = is_array($map) ? $map : [];
        }
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /** Übersetzt den deutschen Quelltext in die aktive Sprache. */
    public static function t(string $de): string
    {
        if (self::$locale === 'de') {
            return $de;
        }
        return self::$map[$de] ?? $de;
    }
}
