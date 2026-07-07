<?php
declare(strict_types=1);

namespace App\Support;

/**
 * JSON-LD (schema.org) für die öffentlichen Web-Seiten.
 *
 * `render()` liefert IMMER Organization + WebSite (global) und hängt eine
 * optionale seiten-spezifische Objektliste an (z. B. Person + BreadcrumbList).
 * Ausgabe als ein einzelner <script type="application/ld+json"> mit @graph.
 */
final class StructuredData
{
    private const NAME = 'CYBERRIDE';
    private const LOGO = '/assets/brand/icon-512.png';

    /** @var list<string> Offizielle Marken-/Social-Profile (sameAs). */
    private const SAME_AS = [
        'https://instagram.com/gravaapp',
        'https://twitter.com/gravaapp',
        'https://www.strava.com/clubs/gravaworld',
    ];

    /** @return array<string,mixed> */
    public static function organization(): array
    {
        return [
            '@type'  => 'Organization',
            '@id'    => SiteUrl::base() . '/#organization',
            'name'   => self::NAME,
            'url'    => SiteUrl::base() . '/',
            'logo'   => SiteUrl::absolute(self::LOGO),
            'sameAs' => self::SAME_AS,
        ];
    }

    /** @return array<string,mixed> */
    public static function website(): array
    {
        return [
            '@type'     => 'WebSite',
            '@id'       => SiteUrl::base() . '/#website',
            'name'      => self::NAME,
            'url'       => SiteUrl::base() . '/',
            'publisher' => ['@id' => SiteUrl::base() . '/#organization'],
        ];
    }

    /** @return array<string,mixed> */
    public static function person(string $handle, ?string $displayName, string $profileUrl, ?string $imageUrl = null): array
    {
        $person = [
            '@type' => 'Person',
            'name'  => ($displayName !== null && $displayName !== '') ? $displayName : '@' . $handle,
            'url'   => $profileUrl,
            'alternateName' => '@' . $handle,
        ];
        if ($imageUrl !== null) {
            $person['image'] = $imageUrl;
        }
        return $person;
    }

    /**
     * BreadcrumbList aus [name, url]-Paaren.
     * @param list<array{0:string,1:string}> $items
     * @return array<string,mixed>
     */
    public static function breadcrumb(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $i => $item) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item[0],
                'item'     => $item[1],
            ];
        }
        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * Rendert Organization + WebSite + optionale Zusatz-Objekte als ein
     * <script type="application/ld+json"> (mit @graph). JSON_HEX_TAG schützt
     * gegen </script>-Ausbruch in dynamischen Feldern.
     *
     * @param list<array<string,mixed>> $extra
     */
    public static function render(array $extra = []): string
    {
        $graph = array_merge([self::organization(), self::website()], $extra);
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            return '';
        }
        return '<script type="application/ld+json">' . $json . '</script>';
    }
}
