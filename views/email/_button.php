<?php
/**
 * Bulletproof CTA-Button im CYBERRIDE-Look (Neon-Cyan auf dunklem Grund).
 * Erwartet im Scope: string $button_url, string $button_label.
 */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
?>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:22px auto;">
  <tr><td align="center" style="border-radius:6px;background:#00E5FF;">
    <a href="<?= $e((string)$button_url) ?>" style="display:inline-block;padding:13px 26px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#04060B;text-decoration:none;border-radius:6px;letter-spacing:.3px;"><?= $e((string)$button_label) ?></a>
  </td></tr>
</table>
