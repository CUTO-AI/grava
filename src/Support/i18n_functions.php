<?php
declare(strict_types=1);

use App\Support\I18n;

/**
 * Globale Übersetzungs-Helfer für die Views. Wird einmal im Bootstrap
 * (public/index.php) geladen; in WebView zusätzlich abgesichert.
 */
if (!function_exists('t')) {
    /** Übersetzter Text (roh) — für Textknoten. */
    function t(string $de): string
    {
        return I18n::t($de);
    }
}

if (!function_exists('te')) {
    /** Übersetzt + HTML-escaped — für Attributwerte und sichere Ausgabe. */
    function te(string $de): string
    {
        return htmlspecialchars(I18n::t($de), ENT_QUOTES, 'UTF-8');
    }
}
