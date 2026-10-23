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
 * How messages.js learns of new messages: a 15 s check while the tab is visible, or one
 * EventSource when the page has a live URL, with a fall back to polling after three errors.
 * Driven in headless Chrome with a fake clock, fetch and EventSource.
 *
 * Usage: php tests/messages-live.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

function test_browser(): string
{
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

$chrome = test_browser();
if ($chrome === '' && (string) getenv('CI') !== '') {
    check('a browser is available to drive the script', false, 'no Chrome or Chromium, so the browser assertions did not run');
}
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
    exit(harness_result());
}

$dir = sys_get_temp_dir() . '/osc-messages-live-' . getmypid();
@mkdir($dir, 0700, true);
$fixture = $dir . '/page.html';
file_put_contents($fixture, '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>'
    . '<div id="stage"></div><pre id="osc-out"></pre>'
    . '<script>window.__script = ' . json_encode((string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/messages.js')) . ';</script>'
    . '<script>' . file_get_contents(__DIR__ . '/lib/messages-live-driver.js') . '</script>'
    . '</body></html>');

$out = (string) shell_exec('TZ=UTC ' . escapeshellarg($chrome)
    . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
    . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
    . ' --virtual-time-budget=20000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null');
shell_exec('rm -rf ' . escapeshellarg($dir));

$r = null;
if (preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
    $r = json_decode((string) base64_decode($m[1], true), true);
}
check('the browser run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));
$r = is_array($r) ? $r : array();

harness_section('polling');
pin('no check before 15 s', 0, $r['pollEarly'] ?? null);
pin('the first check is at 15 s, without If-None-Match; a 200 then fetches since', array(array('check', null), array('since', null)), $r['pollFirst'] ?? null);
pin('since asks for the thread after the last id', true, str_contains((string) ($r['pollSinceUrl'] ?? ''), 'id=7&after=1'));
pin('the new message appears', array('1', '2'), $r['pollIds'] ?? null);
pin('the next check sends the ETag', '"a"', $r['pollSecond'] ?? null);
pin('a 304 fetches nothing more', 3, $r['pollAfter304'] ?? null);
pin('a changed ETag fetches since again', 2, $r['pollSinceCount'] ?? null);
pin('and the next message appears', array('1', '2', '3'), $r['pollIds2'] ?? null);

harness_section('a hidden tab');
pin('no request in 60 s while hidden', 0, $r['hiddenFetches'] ?? null);
pin('returning to visible checks at once', 1, $r['visibleFetches'] ?? null);

harness_section('the live stream');
pin('EventSource opens with the live URL', array('/live/sub/user-1'), $r['liveSources'] ?? null);
pin('no timed check while the stream is open', 0, $r['liveFetches'] ?? null);
pin('a note fetches since once', 1, $r['liveNoteSince'] ?? null);
pin('two errors keep the stream', false, $r['liveClosedAfter2'] ?? null);
pin('the third error closes it', true, $r['liveClosedAfter3'] ?? null);
pin('and the timer resumes', 1, $r['liveResumed'] ?? null);
pin('a stream closed by an HTTP error resumes polling after one error', 2, $r['liveHttpErrorResumed'] ?? null);
pin('no live URL, no EventSource', 0, $r['noLiveSources'] ?? null);

harness_section('the inbox');
pin('a 200 swaps the inbox contents once', array('fresh' => 1, 'stale' => 0, 'wrappers' => 1), $r['inbox'] ?? null);

exit(harness_result());
