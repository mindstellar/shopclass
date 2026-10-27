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
 * The product form's show/hide script: Sell online, options, the digital marker, an empty
 * Details section and folded sections opening for an error link, the "On this page" list
 * or a hash. variants.js and editor.js are driven in headless Chrome on a fixture.
 *
 * Usage: php tests/item-form-sections.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

function test_browser(): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }
    foreach (array((string) getenv('CHROME'), '/opt/google/chrome/chrome', 'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser') as $c) {
        if ($c === '') {
            continue;
        }
        if (strpos($c, '/') !== false) {
            if (is_executable($c)) {
                return $c;
            }
            continue;
        }
        $found = trim((string) shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }
    }

    return '';
}

/** @return array<int,array{0:string,1:bool}>|null */
function drive_form(string $chrome, string $hash): ?array
{
    $js      = ABS_PATH . 'oc-admin/themes/modern/js/';
    $scripts = '';
    foreach (array('variants.js', 'editor.js') as $file) {
        $scripts .= '<script>' . file_get_contents($js . $file) . '</script>';
    }
    $page = (string) file_get_contents(__DIR__ . '/fixtures/item-form.html');
    $page = (string) preg_replace('#<script src="[^"]+"></script>\s*#', '', $page);
    $page = str_replace('</body>', '<pre id="osc-out"></pre>' . $scripts
        . '<script>' . file_get_contents(__DIR__ . '/lib/item-form-driver.js') . '</script></body>', $page);

    $dir = sys_get_temp_dir() . '/osc-item-form-' . getmypid();
    @mkdir($dir, 0700, true);
    file_put_contents($dir . '/page.html', $page);
    $out = (string) shell_exec(escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=5000 --dump-dom ' . escapeshellarg('file://' . $dir . '/page.html' . $hash) . ' 2>/dev/null');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    if (preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
        $r = json_decode((string) base64_decode($m[1], true), true);

        return is_array($r) ? $r : null;
    }

    return null;
}

$chrome = test_browser();
if ($chrome === '' && (string) getenv('CI') !== '') {
    check('a browser is available to drive the form', false, 'no Chrome or Chromium, so the browser checks did not run');
}
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
    exit(harness_result());
}

foreach (array('' => 'the form', '#sec-seller' => 'the form opened at #sec-seller') as $hash => $label) {
    harness_section($label);
    $results = drive_form($chrome, $hash);
    check('the browser run produced a reading', is_array($results));
    foreach ((array) $results as [$name, $ok]) {
        check((string) $name, (bool) $ok);
    }
}

exit(harness_result());
