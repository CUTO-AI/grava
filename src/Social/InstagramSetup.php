<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Einrichtungs-Helfer für Instagram (CLI `social:ig-setup`): nimmt einen
 * kurzlebigen Graph-Token, tauscht ihn (optional) in einen long-lived Token
 * und ermittelt die IG-Business-Account-ID über die verknüpfte Facebook-Seite.
 * Spart die manuellen Graph-Explorer-Aufrufe aus dem Runbook (§4–5).
 *
 * Wirft nie — Fehler stecken im Ergebnis-Array.
 */
final class InstagramSetup
{
    private const BASE = 'https://graph.facebook.com';

    public function __construct(private readonly string $graphVersion = 'v21.0') {}

    /**
     * Tauscht einen kurzlebigen Token in einen long-lived (~60 Tage).
     * @return array{ok:bool, token:?string, expires_in:?int, error:?string}
     */
    public function exchangeLongLived(string $appId, string $appSecret, string $shortToken): array
    {
        if ($appId === '' || $appSecret === '' || $shortToken === '') {
            return ['ok' => false, 'token' => null, 'expires_in' => null, 'error' => 'app_id/app_secret/token fehlen'];
        }
        $url = self::BASE . '/' . $this->graphVersion . '/oauth/access_token'
            . '?grant_type=fb_exchange_token'
            . '&client_id=' . rawurlencode($appId)
            . '&client_secret=' . rawurlencode($appSecret)
            . '&fb_exchange_token=' . rawurlencode($shortToken);
        [$status, $body, $err] = $this->get($url);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'token' => null, 'expires_in' => null, 'error' => "HTTP {$status} {$err} " . substr($body, 0, 200)];
        }
        $d = json_decode($body, true);
        $token = is_array($d) ? (string)($d['access_token'] ?? '') : '';
        return [
            'ok'         => $token !== '',
            'token'      => $token !== '' ? $token : null,
            'expires_in' => is_array($d) && isset($d['expires_in']) ? (int)$d['expires_in'] : null,
            'error'      => $token !== '' ? null : 'kein access_token in Antwort',
        ];
    }

    /**
     * Findet die IG-Business-Account-ID über die verknüpften Facebook-Seiten.
     * @return array{ok:bool, ig_user_id:?string, username:?string, page:?string, error:?string}
     */
    public function discoverBusinessAccount(string $token): array
    {
        if ($token === '') {
            return ['ok' => false, 'ig_user_id' => null, 'username' => null, 'page' => null, 'error' => 'token fehlt'];
        }
        $url = self::BASE . '/' . $this->graphVersion . '/me/accounts'
            . '?fields=' . rawurlencode('name,instagram_business_account{id,username}')
            . '&access_token=' . rawurlencode($token);
        [$status, $body, $err] = $this->get($url);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'ig_user_id' => null, 'username' => null, 'page' => null, 'error' => "HTTP {$status} {$err} " . substr($body, 0, 200)];
        }
        $d = json_decode($body, true);
        $pages = is_array($d) && isset($d['data']) && is_array($d['data']) ? $d['data'] : [];
        foreach ($pages as $page) {
            $iba = $page['instagram_business_account'] ?? null;
            if (is_array($iba) && ($iba['id'] ?? '') !== '') {
                return [
                    'ok'         => true,
                    'ig_user_id' => (string)$iba['id'],
                    'username'   => isset($iba['username']) ? (string)$iba['username'] : null,
                    'page'       => isset($page['name']) ? (string)$page['name'] : null,
                    'error'      => null,
                ];
            }
        }
        return [
            'ok'         => false,
            'ig_user_id' => null,
            'username'   => null,
            'page'       => null,
            'error'      => $pages === []
                ? 'keine Facebook-Seiten am Token — Berechtigung pages_show_list fehlt oder keine Seite verknüpft'
                : 'keine der Seiten hat ein verknüpftes instagram_business_account (IG-Konto als Business + mit Seite verbinden)',
        ];
    }

    /** @return array{0:int,1:string,2:string} [status, body, error] */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $body, $err];
    }
}
