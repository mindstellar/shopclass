<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The one shell behind every page core renders itself, used when the active
 * theme supplies neither the view nor any chrome to wrap it in.
 *
 * Self-contained on purpose -- inline CSS, no external assets, no database, no
 * helper calls on the card path -- because it also renders the fatal-error and
 * "database unavailable" screens, which happen when the app cannot boot.
 *
 * The caller provides $oscPage:
 *   [
 *     'layout'    => 'card' | 'document',  // a system message, or a real page
 *     'title'     => string,   // browser tab title
 *     'heading'   => string,   // short, plain-language headline
 *     'body'      => string,   // trusted HTML unless bodyHtml is false
 *     'bodyHtml'  => bool,     // default true; false escapes the body
 *     'tone'      => string,   // 'info' | 'warning' | 'danger' | 'success'
 *     'role'      => string,   // landmark role: 'alert' for a message, 'main' for a page
 *     'lang'      => string,   // html lang attribute
 *     'brandName' => ?string,  // site name, when the caller can read it
 *     'brandLogo' => ?string,
 *     'homeUrl'   => ?string,  // makes the brand a link (document layout)
 *     'actions'   => ?array,   // [ ['label'=>, 'href'=>, 'primary'=>bool], ... ]
 *     'ref'       => ?string,  // reference id the admin can grep for in logs
 *     'detail'    => ?array,   // ['type','message','file','line','trace'] (debug)
 *   ]
 *
 * Note osc_die() cannot render a page with a form in it: it clears every output
 * buffer, including the one the CSRF filter writes into, so the form would ship
 * with no token. Form pages require this file directly.
 */

require_once __DIR__ . '/page-fn.php';

$oscPage  = isset($oscPage) && is_array($oscPage) ? $oscPage : array();
$layout   = isset($oscPage['layout']) && $oscPage['layout'] === 'document' ? 'document' : 'card';
$title    = isset($oscPage['title']) ? $oscPage['title'] : 'Shopclass';
$heading  = isset($oscPage['heading']) ? $oscPage['heading'] : '';
$body     = isset($oscPage['body']) ? $oscPage['body'] : '';
$bodyHtml = !isset($oscPage['bodyHtml']) || !empty($oscPage['bodyHtml']);
$tone     = isset($oscPage['tone']) ? $oscPage['tone'] : ($layout === 'card' ? 'danger' : 'info');
$lang     = isset($oscPage['lang']) && $oscPage['lang'] !== '' ? $oscPage['lang'] : 'en';
$ref      = isset($oscPage['ref']) ? $oscPage['ref'] : null;
$actions  = (isset($oscPage['actions']) && is_array($oscPage['actions'])) ? $oscPage['actions'] : array();
$detail   = !empty($oscPage['detail']) ? $oscPage['detail'] : null;
$homeUrl  = isset($oscPage['homeUrl']) && $oscPage['homeUrl'] !== '' ? $oscPage['homeUrl'] : null;

// 'alert' is right for a message the visitor did not ask for. A page that asks
// for something back -- a confirm form, a wallet -- passes 'main' instead, so a
// screen reader does not read the whole card assertively before the fields.
$role = isset($oscPage['role']) && $oscPage['role'] !== ''
    ? $oscPage['role']
    : ($layout === 'card' ? 'alert' : 'main');

// Site branding, when the caller can supply it (e.g. maintenance mode, where the
// database is reachable). Boot-failure pages leave these empty and fall back to
// the Shopclass wordmark, since the site's name and logo cannot be read when the
// app cannot boot.
$brandName = isset($oscPage['brandName']) && $oscPage['brandName'] !== '' ? $oscPage['brandName'] : null;
$brandLogo = isset($oscPage['brandLogo']) && $oscPage['brandLogo'] !== '' ? $oscPage['brandLogo'] : null;

if (!in_array($tone, array('info', 'warning', 'danger', 'success'), true)) {
    $tone = 'danger';
}

$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$accent  = osc_gui_tone_accent($tone);
$band    = osc_gui_tone_band($tone);
$iconSvg = osc_gui_tone_icon($tone);
?><!doctype html>
<html lang="<?php echo $esc($lang); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($title); ?></title>
<style>
  html,body{margin:0;padding:0;}
  body{background:#f7f9fb;min-height:100vh;}
</style>
<?php osc_gui_print_style($tone); ?>
</head>
<body class="oe-page">
<?php if ($layout === 'document') { ?>
  <header class="oe-band oe-band-plain">
    <?php if ($brandLogo) { ?>
      <img class="oe-logo" src="<?php echo $esc($brandLogo); ?>" alt="<?php echo $esc($brandName !== null ? $brandName : 'Shopclass'); ?>">
    <?php } elseif ($homeUrl !== null) { ?>
      <a class="oe-brand" href="<?php echo $esc($homeUrl); ?>"><?php echo $esc($brandName !== null ? $brandName : 'Shopclass'); ?></a>
    <?php } elseif ($brandName !== null) { ?>
      <span class="oe-brand"><?php echo $esc($brandName); ?></span>
    <?php } else { ?>
      <span class="oe-brand">Shop<span>class</span></span>
    <?php } ?>
  </header>
  <main class="oe-doc" role="<?php echo $esc($role); ?>">
    <?php if ($heading !== '') { ?><h1 class="oe-h1"><?php echo $esc($heading); ?></h1><?php } ?>
    <?php echo $bodyHtml ? $body : $esc($body); ?>
  </main>
<?php } else { ?>
  <main class="oe-card" role="<?php echo $esc($role); ?>">
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
<?php } ?>
</body>
</html>
