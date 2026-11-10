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
 * Admin messages in headless Chrome with the built main.css and the real scripts: closing and
 * reusing the JS message, a JS error on a scrolled page, the toast and the Save bar, text and a
 * link in one message. Then the source pins for the login flash and flashes sent over AJAX.
 *
 * Usage: php tests/admin-alerts-ui.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

$alertsSource = static fn (string $path): string => (string) file_get_contents(ABS_PATH . $path);

/** The real #jsMessage markup from the admin header, its PHP replaced by the English text. */
function alerts_js_message(): string
{
    $header = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/parts/header.php');
    preg_match('~<div id="jsMessage".*?</p>\s*</div>~s', $header, $m);

    return (string) preg_replace('~<\?php.*?\?>~s', 'Dismiss', $m[0] ?? '');
}

/**
 * Runs a driver (the body of an async function taking t, filling r) in a real-time browser.
 *
 * @param array<int,string> $scripts paths of the scripts to load, in order
 *
 * @return array<string,mixed>|null null when node or puppeteer-core is missing
 */
function alerts_run(string $body, string $driver, int $width = 1440, array $scripts = array('oc-admin/themes/modern/js/osc.js', 'oc-includes/assets/osclass/flash-dismiss.js', 'oc-admin/themes/modern/js/ui-osc.js'), string $dir = 'ltr'): ?array
{
    $chrome = test_browser();
    $pc     = test_puppeteer();
    if ($chrome === '' || $pc === '') {
        if ((string) getenv('CI') !== '') {
            check('a browser and puppeteer-core are available', false, 'run npm ci');
        }
        echo "  (skipped: no browser or puppeteer-core)\n";

        return null;
    }
    $tmp = sys_get_temp_dir() . '/osc-alerts-' . getmypid() . '-' . substr(md5($driver . microtime()), 0, 6);
    mkdir($tmp, 0700, true);
    $tags = '';
    foreach ($scripts as $script) {
        $tags .= '<script src="file://' . ABS_PATH . $script . '"></script>';
    }
    $tags = '<script>window.osc = window.osc || {};</script>' . $tags;
    file_put_contents($tmp . '/page.html', '<!doctype html><html lang="en" dir="' . $dir . '" data-bs-theme="light"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="file://' . ABS_PATH . 'oc-admin/themes/modern/css/main.css"></head><body>'
        . $body . $tags . '</body></html>');
    file_put_contents($tmp . '/driver.js', $driver);
    file_put_contents($tmp . '/run.mjs', <<<'MJS'
import {readFileSync} from 'node:fs';
const [pc, chrome, dir, width] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',900']});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 900});
    const errors = [];
    tab.on('pageerror', (e) => errors.push(String(e.message)));
    await tab.goto('file://' + dir + '/page.html', {waitUntil: 'load'});
    const reading = await tab.evaluate(async (src) => {
        const t = {wait: (ms) => new Promise((done) => setTimeout(done, ms))};
        const r = {};
        try {
            await new Function('t', 'r', 'return (async () => {' + src + '})()')(t, r);
        } catch (e) {
            r.thrown = String(e.message);
        }
        return r;
    }, readFileSync(dir + '/driver.js', 'utf8'));
    reading.errors = errors;
    console.log(JSON.stringify(reading));
} finally {
    await browser.close();
}
MJS);
    $out = (string) shell_exec('node ' . escapeshellarg($tmp . '/run.mjs') . ' ' . escapeshellarg($pc) . ' ' . escapeshellarg($chrome)
        . ' ' . escapeshellarg($tmp) . ' ' . $width . ' 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($tmp));
    $r = json_decode(trim($out), true);
    check('the browser run produced a reading', is_array($r), substr($out, 0, 400));

    return is_array($r) ? $r : array();
}

$shell = static fn (string $extra = '', string $below = ''): string => '<main id="content-render" class="col">'
    . '<header id="header" class="admin-topbar navbar">Top bar</header>'
    . '<div id="message-wrapper" class="row"><div class="col">' . $extra . alerts_js_message() . '</div></div>'
    . '<div id="content-page" class="row"><div class="col"><div style="height:3000px">Long page</div>' . $below . '<div style="height:300px"></div></div></div>'
    . '</main>';

harness_section('closing the JS message, then showing another');

$r = alerts_run($shell(), <<<'JS'
const box = document.getElementById('jsMessage');
setJsMessage('ok', 'First');
box.querySelector('.ico-close').click();
await t.wait(500);
const still = document.getElementById('jsMessage');
r.closed = still ? getComputedStyle(still).display : 'removed';
setJsMessage('error', 'Second');
const now = document.getElementById('jsMessage');
r.second = [getComputedStyle(now).display, now.textContent.trim(), now.classList.contains('flashmessage-error')];
now.querySelector('.ico-close').click();
setJsMessage('ok', 'Third');
await t.wait(500);
r.during = [getComputedStyle(now).display, now.querySelector('p').textContent];
JS);
if ($r !== null) {
    pin('closing hides it and keeps the element', 'none', $r['closed'] ?? null);
    pin('the next message shows', array('block', '× Second', true), array($r['second'][0] ?? '', preg_replace('/\s+/', ' ', (string) ($r['second'][1] ?? '')), $r['second'][2] ?? null) ?? null);
    pin('a message arriving during the exit stays', array('block', 'Third'), $r['during'] ?? null);
    pin('and nothing threw', array(), $r['errors'] ?? null);
}

harness_section('a JS error on a scrolled page');

$r = alerts_run($shell(), <<<'JS'
const box = document.getElementById('jsMessage');
window.scrollTo({top: 2000, behavior: 'instant'});
setJsMessage('ok', 'Saved');
await t.wait(300);
r.okTop = box.getBoundingClientRect().top;
box.querySelector('.ico-close').click();
await t.wait(500);
window.scrollTo({top: 2000, behavior: 'instant'});
setJsMessage('error', 'It failed');
// The theme scrolls smoothly, so wait until the page has stopped moving.
for (let was = -1; was !== window.scrollY; await t.wait(150)) { was = window.scrollY; await t.wait(150); }
const m = box.getBoundingClientRect();
const bar = document.getElementById('header').getBoundingClientRect();
r.top = m.top; r.bottom = m.bottom; r.barBottom = bar.bottom; r.height = window.innerHeight;
JS);
if ($r !== null) {
    check('a success message leaves the scroll alone', ($r['okTop'] ?? 0) < 0, json_encode($r));
    check('the error is below the top bar', ($r['top'] ?? -1) >= ($r['barBottom'] ?? 0) - 1, json_encode($r));
    check('and inside the window', ($r['bottom'] ?? 9999) <= ($r['height'] ?? 0), json_encode($r));
}

harness_section('the toast and the Save bar');

$toast = <<<'JS'
const form = document.querySelector('form');
if (form) { form.scrollIntoView({behavior: 'instant'}); await t.wait(50); }
window.osc.toast('Saved', {undo: function () {}});
await t.wait(400);
const toast = document.querySelector('.osc-toast').getBoundingClientRect();
const bar = document.querySelector('.form-actions');
r.toast = [toast.top, toast.bottom];
if (bar) {
    const b = bar.getBoundingClientRect();
    r.bar = [b.top, b.bottom];
    window.dispatchEvent(new Event('resize'));
    const again = document.querySelector('.osc-toast').getBoundingClientRect();
    r.again = again.bottom;
}
r.style = document.querySelector('.osc-toast').style.insetBlockEnd;
JS;
$bar = '<form><div style="height:1500px">Fields</div><div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div></form>';
foreach (array(390, 1440) as $width) {
    $r = alerts_run($shell('', $bar), $toast, $width);
    if ($r !== null) {
        check("at $width the Save bar is on screen, stuck to the bottom", abs(($r['bar'][1] ?? 0) - 900) < 2, json_encode($r));
        check("at $width the toast ends above the bar", ($r['toast'][1] ?? 9999) <= ($r['bar'][0] ?? 0), json_encode($r));
        check("at $width it stays there after a resize", ($r['again'] ?? 9999) <= ($r['bar'][0] ?? 0), json_encode($r));
    }
}
$r = alerts_run($shell(), $toast, 390);
if ($r !== null) {
    pin('with no Save bar it keeps its resting place', '', $r['style'] ?? null);
}

harness_section('text and a link in one message');

$banner = '<div id="b" class="flashmessage flashmessage-warning" role="status">Stripe is in test mode: no real money is taken. '
    . 'Switch to live mode before the site takes payments. <a id="l" href="#x">Stripe settings</a></div>'
    . '<div id="c" class="flashmessage flashmessage-error" role="alert">Your account has not been saved, try again later or ask the site owner for help.'
    . '<a class="btn ico btn-mini ico-close" data-oc-close-label="Dismiss">x</a></div>';
$flow = <<<'JS'
const box = document.getElementById('b');
const range = document.createRange();
range.selectNodeContents(box.firstChild);
const lines = [...range.getClientRects()];
const last = lines[lines.length - 1];
const link = document.getElementById('l').getBoundingClientRect();
r.lastTextLine = [last.top, last.right];
r.link = [link.top, link.left];
r.firstLeft = lines[0].left;
const c = document.getElementById('c');
const textRange = document.createRange();
textRange.selectNodeContents(c.firstChild);
const close = c.querySelector('.ico-close').getBoundingClientRect();
r.overlaps = [...textRange.getClientRects()].some((l) => l.left < close.right && l.right > close.left && l.top < close.bottom && l.bottom > close.top);
const icon = getComputedStyle(c, '::before');
r.iconContent = icon.content !== 'none';
r.closeSize = [close.width >= 24, close.height >= 24];
JS;
foreach (array('ltr', 'rtl') as $direction) {
    $r = alerts_run($shell($banner), $flow, 390, array('oc-admin/themes/modern/js/osc.js', 'oc-admin/themes/modern/js/ui-osc.js'), $direction);
    if ($r !== null) {
        $sameLine = abs(($r['link'][0] ?? 0) - ($r['lastTextLine'][0] ?? -99)) < 2;
        $nextLine = ($r['link'][0] ?? 0) > ($r['lastTextLine'][0] ?? 0);
        check("$direction: the link follows the text in the same flow", $sameLine || $nextLine, json_encode($r));
        pin("$direction: the close button clears the text", false, $r['overlaps'] ?? null);
        pin("$direction: its hit area is 24 px", array(true, true), $r['closeSize'] ?? null);
    }
}

harness_section('closing a message runs once');

$close = <<<'JS'
const box = document.getElementById('f');
box.querySelector('.ico-close').click();
r.afterClick = [box.isConnected, box.classList.contains('is-dismissing')];
await t.wait(500);
r.later = box.isConnected;
JS;
$flash = '<div id="f" class="flashmessage flashmessage-ok" role="status">Saved<a class="btn ico btn-mini ico-close" data-oc-close-label="Dismiss">x</a></div>';
$r = alerts_run($shell($flash), $close);
if ($r !== null) {
    pin('the admin keeps the box for its exit animation', array(true, true), $r['afterClick'] ?? null);
    pin('and removes it after', false, $r['later'] ?? null);
}
$r = alerts_run($flash, $close, 1440, array('oc-includes/assets/osclass/flash-dismiss.js'));
if ($r !== null) {
    pin('on a front-end page the script still removes it', array(false, false), $r['afterClick'] ?? null);
}

harness_section('the login screen');

$login = $alertsSource('oc-admin/gui/main.php');
check('its flash uses the shared look', str_contains($login, "osc_show_flash_message('admin');"));
check('and not the old alert look', !str_contains($login, "osc_show_flash_message('admin', 'alert')"));
check('the close button has its script', str_contains($login, "osclass/flash-dismiss.js"));

harness_section('flashes sent over AJAX');

foreach (glob(ABS_PATH . 'oc-includes/classes/admin/ajax/*.php') ?: array() as $file) {
    $source = (string) file_get_contents($file);
    check(basename($file) . ': a flash is sent in its cookie or not added', !str_contains($source, 'osc_add_flash_') || str_contains($source, '_flushFlashMessages()'));
}

harness_section('small leftovers');

check('Settings passes text to the message, not an Error', !str_contains($alertsSource('oc-admin/themes/modern/settings/index.php'), "setJsMessage('error', error)"));
check('the JS message close label is translated', str_contains($alertsSource('oc-admin/themes/modern/parts/header.php'), "data-oc-close-label=\"<?php echo osc_esc_html(__('Dismiss')); ?>\""));
check('the docblock no longer says there is no dismiss control', !str_contains($alertsSource('oc-includes/osclass/helpers/hMessages.php'), 'No dismiss control is rendered'));

exit(harness_result());

/* file end: ./tests/admin-alerts-ui.php */
