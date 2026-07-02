<?php
/**
 * CyberRide — plain-PHP component helpers.
 * Server-rendered equivalents of the design-system React primitives.
 * No framework, no Composer packages. Include once: require 'inc/components.php';
 */

/** Lucide icon (hydrated client-side by site.js). */
function cr_icon(string $name, int $size = 20, string $color = 'currentColor'): string {
  $s = (int)$size;
  return "<i data-lucide=\"" . htmlspecialchars($name) . "\" style=\"width:{$s}px;height:{$s}px;color:{$color};display:inline-flex\"></i>";
}

/** HUD chip. tone: cyan|magenta|lime|amber|neutral. */
function cr_badge(string $label, string $tone = 'cyan', bool $dot = false, bool $outline = false): string {
  $cls = 'cr-badge is-' . $tone . ($outline ? ' is-outline' : '');
  $d   = $dot ? '<span class="dot"></span>' : '';
  return "<span class=\"{$cls}\">{$d}" . htmlspecialchars($label) . "</span>";
}

/**
 * Button / link. $opts: variant(primary|secondary|territory|ghost), size(sm|md|lg),
 * href, icon (lucide name, leading), iconRight (lucide name), attrs (raw string).
 */
function cr_button(string $label, array $opts = []): string {
  $variant = $opts['variant'] ?? 'primary';
  $size    = $opts['size'] ?? 'md';
  $cls = 'cr-btn cr-btn--' . $variant;
  if ($size === 'sm') $cls .= ' cr-btn--sm';
  if ($size === 'lg') $cls .= ' cr-btn--lg';
  $icoL = !empty($opts['icon'])      ? cr_icon($opts['icon'], $size === 'lg' ? 18 : 16) : '';
  $icoR = !empty($opts['iconRight']) ? cr_icon($opts['iconRight'], $size === 'lg' ? 18 : 16) : '';
  $inner = $icoL . '<span>' . htmlspecialchars($label) . '</span>' . $icoR;
  $attrs = $opts['attrs'] ?? '';
  if (!empty($opts['href'])) {
    return "<a class=\"{$cls}\" href=\"" . htmlspecialchars($opts['href']) . "\" {$attrs}>{$inner}</a>";
  }
  return "<button type=\"button\" class=\"{$cls}\" {$attrs}>{$inner}</button>";
}

/** Card open tag with corner brackets. accent: cyan|magenta|lime. Close with cr_card_close(). */
function cr_card_open(string $accent = 'cyan', bool $interactive = false, string $extraClass = '', string $style = ''): string {
  $cls = 'cr-card is-' . $accent . ($interactive ? ' is-interactive' : '') . ($extraClass ? ' ' . $extraClass : '');
  $st  = $style ? " style=\"{$style}\"" : '';
  return "<div class=\"{$cls}\"{$st}><span class=\"brk tl\"></span><span class=\"brk bl\"></span><span class=\"brk br\"></span>";
}
function cr_card_close(): string { return '</div>'; }

/** Stat readout. */
function cr_stat(string $value, string $label, string $accent = 'cyan', ?string $delta = null): string {
  $d = $delta ? '<span class="delta">' . htmlspecialchars($delta) . '</span>' : '';
  return '<div class="stat"><span class="lab">' . htmlspecialchars($label) . '</span>'
       . '<span class="val is-' . $accent . '">' . htmlspecialchars($value) . '</span>' . $d . '</div>';
}
