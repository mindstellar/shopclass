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
 * The web push switch (push.js) and its service worker (push-worker.js), driven in headless
 * Chrome over the real opt-in partial with a fake service worker container, push manager,
 * Notification and fetch.
 *
 * Usage: php tests/push-browser.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/push-optin-render.php';

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
    check('a browser is available to drive the scripts', false, 'no Chrome or Chromium, so the browser assertions did not run');
}
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
    exit(harness_result());
}

$stage  = push_optin_render(true);
$pushVariant = 'bell';
$bellStage = (static function () use ($pushVariant): string {
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/account/parts/push-optin.php';

    return (string) ob_get_clean();
})();
$assets = ABS_PATH . 'oc-includes/assets/osclass/';
$dir    = sys_get_temp_dir() . '/osc-push-browser-' . getmypid();
@mkdir($dir, 0700, true);
$fixture = $dir . '/page.html';
file_put_contents($fixture, '<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>'
    . '<div id="stage"></div><pre id="osc-out"></pre>'
    . '<script>window.__stage = ' . json_encode($stage) . ';'
    . 'window.__bellStage = ' . json_encode($bellStage) . ';'
    . 'window.__page = ' . json_encode((string) file_get_contents($assets . 'push.js')) . ';'
    . 'window.__worker = ' . json_encode((string) file_get_contents($assets . 'push-worker.js')) . ';</script>'
    . '<script>' . file_get_contents(__DIR__ . '/lib/push-driver.js') . '</script>'
    . '</body></html>');

$out = (string) shell_exec(escapeshellarg($chrome)
    . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
    . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
    . ' --virtual-time-budget=10000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null');
shell_exec('rm -rf ' . escapeshellarg($dir));

$r = null;
if (preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
    $r = json_decode((string) base64_decode($m[1], true), true);
}
check('the browser run produced a reading', is_array($r) && !isset($r['error']), is_array($r) ? (string) ($r['error'] ?? '') : substr($out, 0, 400));
$r = is_array($r) ? $r : array();

preg_match('/data-worker-url="([^"]*)"/', $stage, $wm);
$workerUrl = html_entity_decode($wm[1] ?? '', ENT_QUOTES);
$scope     = 'https://example.test/sub/';
$inbox     = 'https://example.test/sub/user/messages';
$on        = 'Stop notifying this device';
$off       = 'Notify me on this device';
$csrf      = array('CSRFName' => 'test-name', 'CSRFToken' => 'test-token');

harness_section('the page script');
pin('an unsupported browser keeps the form hidden and asks nothing', array('hidden' => true, 'fetches' => 0),
    array('hidden' => $r['unsupported']['state']['hidden'] ?? null, 'fetches' => $r['unsupported']['fetches'] ?? null));
pin('the form is hidden until support is known', true, $r['fresh']['hiddenAtStart'] ?? null);
pin('no subscription: shown, off, no request', array(false, $off, 0),
    array($r['fresh']['state']['hidden'] ?? null, $r['fresh']['state']['label'] ?? null, $r['fresh']['fetches'] ?? null));
pin('a click asks for permission first, inside the click itself', array('in-click'), $r['freshClick']['asks'] ?? null);
pin('before registering the worker', array('permission', 'register'), array_slice($r['freshClick']['log'] ?? array(), 0, 2));
pin('a click registers the worker by its URL, with no scope', array(array('url' => $workerUrl, 'args' => 1)), $r['freshClick']['registers'] ?? null);
pin('then subscribes, user-visible, with the public key bytes', array(array('userVisibleOnly' => true, 'key' => push_test_key_bytes())),
    $r['freshClick']['subscribes'] ?? null);
pin('then posts the endpoint with the CSRF fields, once', array(array(
    'url'         => 'https://example.test/sub/index.php?page=ajax&action=push_subscribe',
    'method'      => 'POST',
    'credentials' => 'same-origin',
    'fields'      => $csrf + array('endpoint' => 'https://push.example/send/new'),
)), $r['freshClick']['fetches'] ?? null);
pin('and shows it as on', $on, $r['freshClick']['state']['label'] ?? null);

pin('a dismissed prompt registers, subscribes and posts nothing', array(0, 0, 0),
    array($r['dismissed']['registers'] ?? null, $r['dismissed']['subscribes'] ?? null, $r['dismissed']['fetches'] ?? null));
pin('and stays off with the button usable', array($off, false), array($r['dismissed']['state']['label'] ?? null, $r['dismissed']['state']['disabled'] ?? null));
pin('a refused prompt registers nothing and shows blocked', array(0, 0, true, 'Notifications are blocked in this browser. Allow them in its site settings first.'), array(
    $r['refusedPermission']['registers'] ?? null,
    $r['refusedPermission']['fetches'] ?? null,
    $r['refusedPermission']['state']['disabled'] ?? null,
    $r['refusedPermission']['state']['note'] ?? null,
));
pin('an existing subscription asks push_status once, for its endpoint', array(array(
    'url'         => 'https://example.test/sub/index.php?page=ajax&action=push_status&endpoint=' . rawurlencode('https://push.example/send/old'),
    'method'      => 'GET',
    'credentials' => 'same-origin',
    'fields'      => null,
)), $r['existing']['fetches'] ?? null);
pin('the same worker URL is not registered again', 0, $r['existing']['registers'] ?? null);
pin('active: shown as on', array(false, $on), array($r['existing']['state']['hidden'] ?? null, $r['existing']['state']['label'] ?? null));
pin('a click posts unsubscribe, then drops the browser subscription', array(
    'POST https://example.test/sub/index.php?page=ajax&action=push_unsubscribe',
    'unsubscribe',
), $r['existingClick']['log'] ?? null);
pin('and shows it as off', array($off, true), array($r['existingClick']['state']['label'] ?? null, $r['existingClick']['dropped'] ?? null));
pin('turning off asks no permission', 0, $r['existingClick']['asks'] ?? null);
pin('a subscription the server does not have shows as off', $off, $r['inactive']['label'] ?? null);
pin('and the browser drops it, so another user\'s browser stops being woken', true, $r['inactiveDropped'] ?? null);
pin('a refused subscribe drops the browser subscription', 'unsubscribe', end($r['refused']['log']) ?: null);
pin('and says so in the note, still off', array($off, 'Notifications could not be changed. Try again.', false),
    array($r['refused']['state']['label'] ?? null, $r['refused']['state']['note'] ?? null, $r['refused']['state']['disabled'] ?? null));
pin('blocked: the note says so and the button is disabled', array(
    'hidden'   => false,
    'disabled' => true,
    'note'     => 'Notifications are blocked in this browser. Allow them in its site settings first.',
    'fetches'  => 0,
), array(
    'hidden'   => $r['denied']['state']['hidden'] ?? null,
    'disabled' => $r['denied']['state']['disabled'] ?? null,
    'note'     => $r['denied']['state']['note'] ?? null,
    'fetches'  => $r['denied']['fetches'] ?? null,
));

harness_section('the bell variant');
pin('the default variant has a text button and no pressed state', array(null, null), array($r['fresh']['state']['pressed'] ?? null, $r['fresh']['state']['aria'] ?? null));
pin('the bell is an icon button with no text', array('', true), array(
    trim($r['bellFresh']['label'] ?? 'x'),
    strpos($bellStage, '<svg') !== false && strpos($bellStage, 'oe-icon-btn') !== false,
));
pin('the bell starts off: not pressed, labelled and titled', array('false', $off, $off), array(
    $r['bellFresh']['pressed'] ?? null, $r['bellFresh']['aria'] ?? null, $r['bellFresh']['title'] ?? null,
));
pin('subscribing presses it and relabels it, still no text', array('true', $on, $on, ''), array(
    $r['bellOn']['pressed'] ?? null, $r['bellOn']['aria'] ?? null, $r['bellOn']['title'] ?? null, trim($r['bellOn']['label'] ?? 'x'),
));
pin('unsubscribing flips it back', array('false', $off, $off), array(
    $r['bellOff']['pressed'] ?? null, $r['bellOff']['aria'] ?? null, $r['bellOff']['title'] ?? null,
));
pin('the note stays in the page for screen readers', true,
    strpos($bellStage, 'class="oe-sr-only" data-osc-push-note aria-live="polite"') !== false);
pin('a [data-osc-push-toggle] click subscribes from inside the click', array(1, 1, array('in-click'), 'true'), array(
    $r['toggled']['subscribes'] ?? null, $r['toggled']['fetches'] ?? null, $r['toggled']['asks'] ?? null, $r['toggled']['state']['pressed'] ?? null,
));

harness_section('the worker');
pin('a push fetches messages_check under the scope, with the cookies', array(array(
    'url'         => $scope . 'index.php?page=ajax&action=messages_check&for=7',
    'method'      => 'GET',
    'credentials' => 'include',
    'fields'      => null,
)), $r['push3']['fetches'] ?? null);
$note = static fn (string $body): array => array('title' => 'Shop & Co', 'body' => $body, 'tag' => 'messages', 'url' => $inbox);
pin('3 unread: one notification with the count and the inbox link', array($note('3 new messages')), $r['push3']['notes'] ?? null);
pin('1 unread uses the singular', array($note('1 new message')), $r['push1'] ?? null);
pin('401: the sign-in text', array($note('Sign in to read your messages')), $r['push401'] ?? null);
pin('0 unread still shows one notification', array($note('You have new messages')), $r['push0'] ?? null);
pin('a failed fetch still shows one notification', array($note('You have new messages')), $r['pushOffline'] ?? null);
pin('a click with no tab open opens the inbox', array(
    'opened'    => array($inbox),
    'focused'   => array(),
    'matchOpts' => array('type' => 'window', 'includeUncontrolled' => true),
    'closed'    => 1,
), $r['clickNone'] ?? null);
pin('a click with the inbox open focuses that tab', array('opened' => array(), 'focused' => array($inbox)), $r['clickOpen'] ?? null);
pin('a changed subscription re-subscribes with the old key', array(array('userVisibleOnly' => true, 'key' => array(4, 9, 8, 7))),
    $r['change']['subs'] ?? null);
pin('and posts the new endpoint with CSRF fields read from the inbox', array(
    array('url' => $inbox, 'method' => 'GET', 'credentials' => 'include', 'fields' => null),
    array(
        'url'         => $scope . 'index.php?page=ajax&action=push_subscribe',
        'method'      => 'POST',
        'credentials' => 'include',
        'fields'      => array('CSRFName' => 'nm', 'CSRFToken' => 'tk', 'endpoint' => 'https://push.example/send/renewed'),
    ),
), $r['change']['fetches'] ?? null);

exit(harness_result());
