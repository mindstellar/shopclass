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
 * Shared error page. Deliberately self-contained — inline CSS, no external
 * assets, no database, no helper functions — so it renders even when the app
 * cannot boot (a fatal can happen at any point). The caller provides $oscError:
 *
 *   [
 *     'isDebug' => bool,     // show the technical detail block
 *     'heading' => string,   // short, plain-language headline
 *     'body'    => string,   // what happened / what to do
 *     'ref'     => ?string,  // reference id the admin can grep for in logs
 *     'detail'  => ?array,   // ['type','message','file','line','trace'] (debug only)
 *   ]
 */

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

$oscError = isset($oscError) && is_array($oscError) ? $oscError : array();
$isDebug  = !empty($oscError['isDebug']);
$heading  = isset($oscError['heading']) ? $oscError['heading'] : 'Something went wrong';
$body     = isset($oscError['body']) ? $oscError['body'] : "This page ran into an unexpected error and couldn't finish loading.";
$ref      = isset($oscError['ref']) ? $oscError['ref'] : null;
$detail   = ($isDebug && !empty($oscError['detail'])) ? $oscError['detail'] : null;

$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($heading); ?> — Shopclass</title>
<style>
  :root {
    --oe-bench-warm: #f7f9fb; --oe-bench: #ffffff; --oe-bench-sunk: #eef1f5;
    --oe-rule: #dde3ea; --oe-ink: #14181f; --oe-ink-muted: #5f6b7a;
    --oe-bronze: #0b7269; --oe-danger: #c22826; --oe-danger-tint: #ffe9e5;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--oe-bench-warm);
    color: var(--oe-ink);
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    line-height: 1.55;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 48px 16px;
  }
  .oe-card {
    background: var(--oe-bench);
    border: 1px solid var(--oe-rule);
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(20, 24, 31, 0.06), 0 8px 24px rgba(20, 24, 31, 0.05);
    max-width: 640px;
    width: 100%;
    overflow: hidden;
  }
  .oe-band {
    background: var(--oe-danger-tint);
    border-bottom: 1px solid var(--oe-rule);
    padding: 16px 32px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .oe-band svg { flex: none; }
  .oe-brand { font-weight: 600; color: var(--oe-ink); letter-spacing: -0.01em; }
  .oe-brand span { color: var(--oe-bronze); }
  .oe-body { padding: 32px; }
  .oe-title { font-size: 1.375rem; font-weight: 600; letter-spacing: -0.005em; margin: 0 0 12px; }
  .oe-lead { font-size: 1rem; color: var(--oe-ink); margin: 0 0 20px; max-width: 60ch; }
  .oe-ref { font-size: 0.8125rem; color: var(--oe-ink-muted); margin: 0; }
  .oe-ref code {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: var(--oe-bench-sunk); border-radius: 4px; padding: 2px 6px; color: var(--oe-ink);
  }
  .oe-detail { margin-top: 24px; border-top: 1px solid var(--oe-rule); padding-top: 20px; }
  .oe-detail-head {
    font-size: 0.8125rem; font-weight: 500; letter-spacing: 0.01em;
    color: var(--oe-danger); text-transform: none; margin-bottom: 10px;
  }
  .oe-detail-msg {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.875rem; color: var(--oe-ink); margin: 0 0 6px; word-break: break-word;
  }
  .oe-detail-loc { font-size: 0.8125rem; color: var(--oe-ink-muted); margin: 0 0 12px; word-break: break-all; }
  .oe-trace {
    background: var(--oe-bench-sunk); border: 1px solid var(--oe-rule); border-radius: 4px;
    padding: 12px 14px; margin: 0; overflow-x: auto;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.8125rem; line-height: 1.5; color: var(--oe-ink-muted);
  }
</style>
</head>
<body>
  <main class="oe-card" role="alert">
    <div class="oe-band">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 8v5" stroke="#c22826" stroke-width="2" stroke-linecap="round"/>
        <circle cx="12" cy="16.5" r="1.25" fill="#c22826"/>
        <path d="M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.42 0Z" stroke="#c22826" stroke-width="1.6"/>
      </svg>
      <span class="oe-brand">Shop<span>class</span></span>
    </div>
    <div class="oe-body">
      <h1 class="oe-title"><?php echo $esc($heading); ?></h1>
      <p class="oe-lead"><?php echo $esc($body); ?></p>
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
