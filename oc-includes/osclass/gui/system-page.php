<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Shared system page — the single calm, on-brand screen behind every full-page
 * system message: fatal errors, "database unavailable", "not installed",
 * "not configured", and maintenance mode. Deliberately self-contained (inline
 * CSS, no external assets, no database, no helper functions) so it renders even
 * when the app cannot boot.
 *
 * The caller provides $oscSys:
 *   [
 *     'title'    => string,   // browser tab title
 *     'heading'  => string,   // short, plain-language headline
 *     'body'     => string,   // what happened / what to do
 *     'bodyHtml' => bool,     // echo body as trusted HTML instead of escaping
 *     'tone'     => string,   // 'info' | 'warning' | 'danger' | 'success'
 *     'ref'      => ?string,  // reference id the admin can grep for in logs
 *     'actions'  => ?array,   // [ ['label'=>, 'href'=>, 'primary'=>bool], ... ]
 *     'detail'   => ?array,   // ['type','message','file','line','trace'] (debug)
 *   ]
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$oscSys   = isset($oscSys) && is_array($oscSys) ? $oscSys : array();
$title    = isset($oscSys['title']) ? $oscSys['title'] : 'Shopclass';
$heading  = isset($oscSys['heading']) ? $oscSys['heading'] : 'Something went wrong';
$body     = isset($oscSys['body']) ? $oscSys['body'] : '';
$bodyHtml = !empty($oscSys['bodyHtml']);
$tone     = isset($oscSys['tone']) ? $oscSys['tone'] : 'danger';
$ref      = isset($oscSys['ref']) ? $oscSys['ref'] : null;
$actions  = (isset($oscSys['actions']) && is_array($oscSys['actions'])) ? $oscSys['actions'] : array();
$detail   = !empty($oscSys['detail']) ? $oscSys['detail'] : null;

// Site branding, when the caller can supply it (e.g. maintenance mode, where
// the database is reachable). Boot-failure pages leave these empty and fall
// back to the Shopclass wordmark, since the site's name/logo can't be read when
// the app can't boot.
$brandName = isset($oscSys['brandName']) && $oscSys['brandName'] !== '' ? $oscSys['brandName'] : null;
$brandLogo = isset($oscSys['brandLogo']) && $oscSys['brandLogo'] !== '' ? $oscSys['brandLogo'] : null;

if (!in_array($tone, array('info', 'warning', 'danger', 'success'), true)) {
    $tone = 'danger';
}

$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

// Tone → accent colour + icon path.
$toneColor = array(
    'info'    => '#0b7269',
    'warning' => '#7a6716',
    'danger'  => '#c22826',
    'success' => '#1d7d3e',
);
$accent = $toneColor[$tone];

$icons = array(
    // wrench — used for info/maintenance
    'info'    => '<path d="M14.7 6.3a4 4 0 0 1-5.4 5.2l-4.6 4.6a1.5 1.5 0 0 1-2.1-2.1l4.6-4.6a4 4 0 0 1 5.2-5.4l-2.3 2.3 1.4 1.4 2.3-2.3q.5 .4 .9 1Z" stroke="%C" stroke-width="1.6" fill="none" stroke-linejoin="round"/>',
    // triangle warning
    'warning' => '<path d="M12 8v5" stroke="%C" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1.25" fill="%C"/><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z" stroke="%C" stroke-width="1.6"/>',
    'danger'  => '<path d="M12 8v5" stroke="%C" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16.5" r="1.25" fill="%C"/><path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z" stroke="%C" stroke-width="1.6"/>',
    // check circle
    'success' => '<circle cx="12" cy="12" r="9" stroke="%C" stroke-width="1.6"/><path d="m8 12 2.5 2.5L16 9" stroke="%C" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
);
$iconSvg = str_replace('%C', $accent, $icons[$tone]);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($title); ?></title>
<style>
  :root {
    --oe-bench-warm: #f7f9fb; --oe-bench: #ffffff; --oe-bench-sunk: #eef1f5;
    --oe-rule: #dde3ea; --oe-ink: #14181f; --oe-ink-muted: #5f6b7a;
    --oe-bronze: #0b7269; --oe-bronze-deep: #09625c;
    --oe-accent: <?php echo $accent; ?>;
    --oe-band: <?php echo array('info' => '#e6f6f4', 'warning' => '#fdf4d2', 'danger' => '#ffe9e5', 'success' => '#e4f8e7')[$tone]; ?>;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--oe-bench-warm); color: var(--oe-ink);
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    line-height: 1.55; min-height: 100vh;
    display: flex; align-items: flex-start; justify-content: center; padding: 48px 16px;
  }
  .oe-card {
    background: var(--oe-bench); border: 1px solid var(--oe-rule); border-radius: 6px;
    box-shadow: 0 1px 3px rgba(20,24,31,.06), 0 8px 24px rgba(20,24,31,.05);
    max-width: 640px; width: 100%; overflow: hidden;
  }
  .oe-band {
    background: var(--oe-band); border-bottom: 1px solid var(--oe-rule);
    padding: 16px 32px; display: flex; align-items: center; gap: 12px;
  }
  .oe-band svg { flex: none; }
  .oe-brand { font-weight: 600; color: var(--oe-ink); letter-spacing: -0.01em; }
  .oe-brand span { color: var(--oe-bronze); }
  .oe-logo { max-height: 28px; width: auto; display: block; }
  .oe-body { padding: 32px; }
  .oe-title { font-size: 1.375rem; font-weight: 600; letter-spacing: -0.005em; margin: 0 0 12px; }
  .oe-lead { font-size: 1rem; color: var(--oe-ink); margin: 0 0 20px; max-width: 60ch; }
  .oe-lead p { margin: 0 0 12px; }
  .oe-lead a { color: var(--oe-bronze); text-decoration: underline; }
  .oe-lead code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--oe-bench-sunk); border-radius: 4px; padding: 2px 6px; font-size: .9em;
  }
  /* Buttons embedded in a message (legacy .button / .btn) render on-brand. */
  .oe-lead a.button, .oe-lead a.btn, .oe-actions a {
    display: inline-block; text-decoration: none; border-radius: 4px;
    padding: 9px 18px; font-size: .9375rem; font-weight: 500; margin-top: 8px;
    background: var(--oe-bronze); color: #fff; border: 1px solid var(--oe-bronze);
  }
  .oe-lead a.button:hover, .oe-lead a.btn:hover, .oe-actions a.oe-primary:hover { background: var(--oe-bronze-deep); }
  .oe-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px; }
  .oe-actions a.oe-secondary {
    background: var(--oe-bench); color: var(--oe-ink); border: 1px solid var(--oe-rule);
  }
  .oe-actions a.oe-secondary:hover { border-color: var(--oe-ink-muted); }
  .oe-ref { font-size: .8125rem; color: var(--oe-ink-muted); margin: 16px 0 0; }
  .oe-ref code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--oe-bench-sunk); border-radius: 4px; padding: 2px 6px; color: var(--oe-ink);
  }
  .oe-detail { margin-top: 24px; border-top: 1px solid var(--oe-rule); padding-top: 20px; }
  .oe-detail-head { font-size: .8125rem; font-weight: 500; color: var(--oe-accent); margin-bottom: 10px; }
  .oe-detail-msg {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .875rem; color: var(--oe-ink); margin: 0 0 6px; word-break: break-word;
  }
  .oe-detail-loc { font-size: .8125rem; color: var(--oe-ink-muted); margin: 0 0 12px; word-break: break-all; }
  .oe-trace {
    background: var(--oe-bench-sunk); border: 1px solid var(--oe-rule); border-radius: 4px;
    padding: 12px 14px; margin: 0; overflow-x: auto;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .8125rem; line-height: 1.5; color: var(--oe-ink-muted);
  }
</style>
</head>
<body>
  <main class="oe-card" role="alert">
    <div class="oe-band">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><?php echo $iconSvg; ?></svg>
      <?php if ($brandLogo) { ?>
        <img class="oe-logo" src="<?php echo $esc($brandLogo); ?>" alt="<?php echo $esc($brandName !== null ? $brandName : 'Shopclass'); ?>">
      <?php } elseif ($brandName !== null) { ?>
        <span class="oe-brand"><?php echo $esc($brandName); ?></span>
      <?php } else { ?>
        <span class="oe-brand">Shop<span>class</span></span>
      <?php } ?>
    </div>
    <div class="oe-body">
      <h1 class="oe-title"><?php echo $esc($heading); ?></h1>
      <div class="oe-lead"><?php echo $bodyHtml ? $body : $esc($body); ?></div>
      <?php if ($actions) { ?>
        <div class="oe-actions">
          <?php foreach ($actions as $a) {
              if (empty($a['label']) || empty($a['href'])) {
                  continue;
              }
              $cls = !empty($a['primary']) ? 'oe-primary' : 'oe-secondary';
              ?>
            <a class="<?php echo $cls; ?>" href="<?php echo $esc($a['href']); ?>"><?php echo $esc($a['label']); ?></a>
          <?php } ?>
        </div>
      <?php } ?>
      <?php if ($ref) { ?>
        <p class="oe-ref">Reference: <code><?php echo $esc($ref); ?></code></p>
      <?php } ?>
      <?php if ($detail) { ?>
        <div class="oe-detail">
          <div class="oe-detail-head"><?php echo $esc('Debug detail — shown because debug mode is on; hidden in production.'); ?></div>
          <p class="oe-detail-msg"><?php echo $esc((isset($detail['type']) ? $detail['type'] : 'Error') . ': ' . (isset($detail['message']) ? $detail['message'] : '')); ?></p>
          <?php if (!empty($detail['file'])) { ?>
            <p class="oe-detail-loc"><?php echo $esc($detail['file']); ?><?php echo isset($detail['line']) ? ' : ' . $esc($detail['line']) : ''; ?></p>
          <?php } ?>
          <?php if (!empty($detail['trace'])) { ?>
            <pre class="oe-trace"><?php echo $esc($detail['trace']); ?></pre>
          <?php } ?>
        </div>
      <?php } ?>
    </div>
  </main>
</body>
</html>
