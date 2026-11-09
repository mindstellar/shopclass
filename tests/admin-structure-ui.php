<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The built admin CSS in headless Chrome: a form that is not a gap stack keeps its row and
 * heading margins inside a body, and a stack form's rows are spaced by gap alone.
 *
 * Usage: php tests/admin-structure-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

$chrome = test_browser();
$css    = ABS_PATH . 'oc-admin/themes/modern/css/main.css';
if ($chrome === '' || !is_file($css)) {
    if ((string) getenv('CI') !== '') {
        check('Chrome and the built main.css are available', false, 'install Chrome, run npm run css');
    }
    echo "  (skipped: no Chrome or no main.css)\n";
    exit(harness_result());
}

$dir = sys_get_temp_dir() . '/osc-structure-ui-' . getmypid();
mkdir($dir);
$page = $dir . '/page.html';
file_put_contents($page, <<<HTML
<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="file://{$css}">
<body style="width:1200px">
<div class="osc-body theme-files">
  <form><fieldset><div class="form-horizontal">
    <h3 id="h1" class="render-title osc-section-title">One</h3>
    <p id="intro" class="form-intro">Intro</p>
    <div id="r1" class="form-row"><div class="form-label">A</div><div class="form-controls">a</div></div>
    <div id="r2" class="form-row"><div class="form-label">B</div><div class="form-controls">b</div></div>
    <h3 id="h2" class="render-title osc-section-title">Two</h3>
    <div id="r3" class="form-row"><div class="form-label">C</div><div class="form-controls">c</div></div>
  </div></fieldset></form>
</div>
<div class="osc-body">
  <form><fieldset><div class="form-horizontal osc-stack">
    <section class="osc-section"><div class="osc-section-body">
      <div id="s1" class="form-row"><div class="form-label">A</div><div class="form-controls">a</div></div>
      <div id="s2" class="form-row"><div class="form-label">B</div><div class="form-controls">b</div></div>
    </div></section>
  </div></fieldset></form>
</div>
<pre id="out"></pre>
<script>
const box = id => document.getElementById(id).getBoundingClientRect();
const space = (a, b) => Math.round(box(b).top - box(a).bottom);
document.getElementById('out').textContent = JSON.stringify({
  rows: space('r1', 'r2'),
  above: space('r2', 'h2'),
  below: space('h2', 'r3'),
  intro: space('intro', 'r1'),
  stackMargin: parseFloat(getComputedStyle(document.getElementById('s1')).marginBottom),
  stackGap: space('s1', 's2'),
  fieldGap: parseFloat(getComputedStyle(document.getElementById('s1').parentElement).rowGap),
});
</script>
HTML);

$dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --no-sandbox --disable-gpu --allow-file-access-from-files'
    . ' --user-data-dir=' . escapeshellarg($dir . '/profile') . ' --dump-dom ' . escapeshellarg('file://' . $page) . ' 2>/dev/null');
exec('rm -rf ' . escapeshellarg($dir));

$out = preg_match('#<pre id="out">(.*?)</pre>#s', $dom, $m) ? json_decode(html_entity_decode($m[1]), true) : null;
check('the page was measured', is_array($out), substr($dom, 0, 200));
$out = (array) $out;

harness_section('a form that is not a stack, inside a body');
check('rows keep their gap', ($out['rows'] ?? 0) > 0, (string) ($out['rows'] ?? ''));
check('a heading keeps its air above', ($out['above'] ?? 0) > 0, (string) ($out['above'] ?? ''));
check('a heading keeps its air below', ($out['below'] ?? 0) > 0, (string) ($out['below'] ?? ''));
check('an intro keeps its air below', ($out['intro'] ?? 0) > 0, (string) ($out['intro'] ?? ''));

harness_section('a stack form');
pin('rows have no margin', 0.0, (float) ($out['stackMargin'] ?? -1));
pin('the gap alone spaces them', round((float) ($out['fieldGap'] ?? -1)), (float) ($out['stackGap'] ?? -2));

exit(harness_result());
