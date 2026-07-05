<?php
/** Gemeinsamer CYBERRIDE-E-Mail-Footer — schließt Inhalt + Karte. */
$e = $e ?? (static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
?>
      </td></tr>
      <tr><td style="padding:20px 28px 26px;border-top:1px solid rgba(150,180,210,0.14);">
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#7C8CA3;">
          &copy; <?= date('Y') ?> CYBERRIDE ·
          <a href="https://cyberride.world" style="color:#00E5FF;text-decoration:none;">cyberride.world</a>
        </div>
      </td></tr>
    </table>
    <div style="font-family:'SFMono-Regular',Consolas,Menlo,monospace;font-size:11px;letter-spacing:1px;color:#4a5568;margin-top:14px;">RIDE REAL ROADS. CLAIM THE GRID.</div>
  </td></tr>
</table>
</body>
</html>
