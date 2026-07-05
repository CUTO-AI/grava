<?php
/**
 * Gemeinsamer CYBERRIDE-E-Mail-Header — dunkles Cyber-Layout, inline CSS,
 * table-based für maximale Client-Kompatibilität (Gmail, Apple Mail, Outlook).
 *
 * VERWENDUNG für neue E-Mails (HTML):
 *   1) Oben Variablen setzen: $email_kicker, $email_heading, $email_preheader
 *   2) include __DIR__ . '/_head.php';
 *   3) Inhalts-<p>… mit den Inline-Stilen unten (Farben s. Palette)
 *   4) für CTAs: $button_url + $button_label setzen, include '/_button.php'
 *   5) include __DIR__ . '/_foot.php';
 *
 * PALETTE (Cyber-Tokens):
 *   Hintergrund   #04060B   Karte        #0D111B
 *   Akzent-Cyan   #00E5FF   Sekundär     #FF1E6F   Erfolg/Lime #B6FF2E
 *   Text hell     #EAF6FF   Text         #B6C6DA   Muted       #7C8CA3
 *   Linien        rgba(150,180,210,0.14)  / Cyan-Linie rgba(0,229,255,0.22)
 *
 * Erwartet im Scope (alle optional):
 *   string $email_kicker    Mono-Kicker über dem Wortmark (z. B. "// KONTO")
 *   string $email_heading   Zeile unter dem Wortmark (Betreff der Mail)
 *   string $email_preheader versteckter Inbox-Vorschautext
 *   string $app_name        Markenname (Default CYBERRIDE)
 */
$app_name  = (isset($app_name) && $app_name !== '') ? (string)$app_name : 'CYBERRIDE';
$kicker    = isset($email_kicker) ? (string)$email_kicker : '';
$heading   = isset($email_heading) ? (string)$email_heading : '';
$preheader = isset($email_preheader) ? (string)$email_preheader : '';
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title><?= $e($heading !== '' ? $heading : $app_name) ?></title>
</head>
<body style="margin:0;padding:0;background:#04060B;">
<?php if ($preheader !== ''): ?>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#04060B;font-size:1px;line-height:1px;mso-hide:all;"><?= $e($preheader) ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#04060B;padding:28px 12px;">
  <tr><td align="center">
    <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="width:560px;max-width:100%;background:#0D111B;border:1px solid rgba(0,229,255,0.22);border-radius:10px;overflow:hidden;">
      <tr><td style="height:4px;line-height:4px;font-size:0;background:#00E5FF;background-image:linear-gradient(90deg,#00E5FF,#FF1E6F);">&nbsp;</td></tr>
      <tr><td style="padding:26px 28px 4px;">
        <div style="font-family:'SFMono-Regular',Consolas,Menlo,monospace;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#00E5FF;"><?= $kicker !== '' ? $e($kicker) : '// ' . $e($app_name) ?></div>
        <div style="font-family:Arial,Helvetica,sans-serif;font-weight:800;letter-spacing:1px;font-size:26px;color:#EAF6FF;margin-top:6px;"><?= $e($app_name) ?></div>
        <?php if ($heading !== ''): ?><div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#7C8CA3;margin-top:4px;"><?= $e($heading) ?></div><?php endif; ?>
      </td></tr>
      <tr><td style="padding:16px 28px 4px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#B6C6DA;font-size:15px;line-height:1.6;">
