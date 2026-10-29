<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Runs messages.js under a driver in headless Chrome and returns the driver's reading. The
 * driver writes base64 JSON into pre#osc-out; tests/lib/messages-fake-env.js loads first.
 */

/**
 * Stops the test (skip, or fail on CI) when no browser is found; else returns the reading.
 *
 * @return array<string,mixed>
 */
function messages_browser_run(string $driver): array
{
    $chrome = test_browser();
    if ($chrome === '' && (string) getenv('CI') !== '') {
        check('a browser is available to drive the script', false, 'no Chrome or Chromium, so the browser assertions did not run');
    }
    if ($chrome === '') {
        echo "  (skipped: no Chrome or Chromium on this machine)\n";
        exit(harness_result());
    }

    $dir = sys_get_temp_dir() . '/osc-messages-' . getmypid() . '-' . basename($driver, '.js');
    @mkdir($dir, 0700, true);
    $fixture = $dir . '/page.html';
    file_put_contents($fixture, '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>'
        . '<div id="stage"></div><pre id="osc-out"></pre>'
        . '<script>window.__script = ' . json_encode((string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/messages.js')) . ';</script>'
        . '<script>' . file_get_contents(__DIR__ . '/messages-fake-env.js') . '</script>'
        . '<script>' . file_get_contents($driver) . '</script>'
        . '</body></html>');

    $out = (string) shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --window-size=1280,900'
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=20000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    $r = null;
    if (preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
        $r = json_decode((string) base64_decode($m[1], true), true);
    }
    check('the browser run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));

    return is_array($r) ? $r : array();
}
