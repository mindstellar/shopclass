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
function alerts_run(string $body, string $driver, int $width = 1440, array $scripts = array('oc-admin/themes/modern/js/osc.js', 'oc-includes/assets/osclass/flash-dismiss.js', 'oc-admin/themes/modern/js/ui-osc.js'), string $dir = 'ltr', bool $reduce = false): ?array
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
const [pc, chrome, dir, width, reduce] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true,
    args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files', '--window-size=' + width + ',900']});
try {
    const tab = await browser.newPage();
    await tab.setViewport({width: +width, height: 900});
    if (reduce === '1') {
        await tab.emulateMediaFeatures([{name: 'prefers-reduced-motion', value: 'reduce'}]);
    }
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
        . ' ' . escapeshellarg($tmp) . ' ' . $width . ' ' . ($reduce ? '1' : '0') . ' 2>&1');
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
const before = window.scrollY;
setJsMessage('ok', 'Saved');
await t.wait(300);
r.okTop = box.getBoundingClientRect().top;
r.okMoved = window.scrollY < before - 200;
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
    pin('a success message does not move the page', false, $r['okMoved'] ?? null);
    check('and it is in view, under the top bar', ($r['okTop'] ?? -1) >= 0, json_encode($r));
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

// flash-dismiss.js closes `.flashmessage`; the login page's own script must leave those alone.
preg_match('~<script type="text/javascript">(.*?)</script>~s', $login, $loginScript);
$loginDriver = <<<'JS'
const box = document.getElementById('f');
let removed = 0;
new MutationObserver(records => records.forEach(m => m.removedNodes.forEach(n => { if (n === box) { removed++; } }))).observe(box.parentNode, {childList: true});
box.querySelector('.ico-close').click();
await t.wait(50);
const plugin = document.getElementById('plugin-box');
plugin.querySelector('.ico-close').click();
await t.wait(50);
r.closed = [box.isConnected, removed, box.style.display];
r.plugin = plugin.style.display;
JS;
$r = alerts_run('<div class="flashmessage flashmessage-ok" id="f" role="status">Saved<a class="btn ico btn-mini ico-close" href="#">x</a></div>'
    . '<div class="alert" id="plugin-box">Plugin<a class="ico-close" href="#">x</a></div><script>' . ($loginScript[1] ?? '') . '</script>',
    $loginDriver, 1440, array('oc-includes/assets/osclass/flash-dismiss.js'));
if ($r !== null) {
    pin('a click closes the login flash once, and only flash-dismiss.js touches it', array(false, 1, ''), $r['closed'] ?? null);
    pin('a plugin box on the login page still closes', 'none', $r['plugin'] ?? null);
}

harness_section('flashes sent over AJAX');

foreach (glob(ABS_PATH . 'oc-includes/classes/admin/ajax/*.php') ?: array() as $file) {
    $source = (string) file_get_contents($file);
    check(basename($file) . ': a flash is sent in its cookie or not added', !str_contains($source, 'osc_add_flash_') || str_contains($source, '_flushFlashMessages()'));
}

harness_section('small leftovers');

check('Settings passes text to the message, not an Error', !str_contains($alertsSource('oc-admin/themes/modern/settings/index.php'), "setJsMessage('error', error)"));
check('the JS message close label is translated', str_contains($alertsSource('oc-admin/themes/modern/parts/header.php'), "data-oc-close-label=\"<?php echo osc_esc_html(__('Dismiss')); ?>\""));
check('the docblock no longer says there is no dismiss control', !str_contains($alertsSource('oc-includes/osclass/helpers/hMessages.php'), 'No dismiss control is rendered'));

harness_section('osc.notify: setJsMessage and osc.toast go through it');

$clock  = array('tests/lib/admin-alerts-clock.js', 'oc-admin/themes/modern/js/osc.js', 'oc-admin/themes/modern/js/ui-osc.js');
$real   = array('oc-admin/themes/modern/js/osc.js', 'oc-admin/themes/modern/js/ui-osc.js');
$frames = 'await new Promise(requestAnimationFrame); await new Promise(requestAnimationFrame);';

$r = alerts_run($shell(), <<<'JS'
let seen = [];
const realNotify = osc.notify;
osc.notify = function (type, text, opts) { seen.push(type + ':' + text); return realNotify(type, text, opts); };
setJsMessage('ok', 'First');
const box = document.getElementById('jsMessage');
r.slot = [box.querySelector('p').textContent, box.classList.contains('flashmessage-ok'), box.classList.contains('hide')];
setJsMessage('error', 'Second');
r.copies = [...document.querySelectorAll('#message-wrapper .flashmessage')].map((el) => [el.id, el.textContent.trim().slice(-6)]);
const handle = osc.toast('Undoable', {undo: function () { r.undone = true; }});
const toast = document.querySelector('.osc-toast');
r.toast = [toast.querySelector('.osc-toast-text').textContent, toast.classList.contains('osc-toast-ok'), toast.querySelector('.osc-toast-undo').hidden, typeof handle.close];
osc.notify('ok', 'Quick');
osc.notify('error', 'Undo this', {undo: function () {}});
r.undoTone = toast.classList.contains('osc-toast-error');
r.seen = seen;
r.labels = typeof osc.toast.labels;
JS, 1440, $real);
if ($r !== null) {
    pin('setJsMessage fills #jsMessage', array('First', true, false), $r['slot'] ?? null);
    pin('a new message moves the last one up', array(array('', 'First'), array('jsMessage', 'Second')), array_map(static fn ($c) => array($c[0], trim(ltrim(str_replace('×', '', $c[1]))) ), $r['copies'] ?? array()));
    pin('osc.toast still shows the note with Undo', array('Undoable', true, false, 'function'), $r['toast'] ?? null);
    pin('both went through osc.notify', array('ok:First', 'error:Second', 'ok:Undoable', 'ok:Quick', 'error:Undo this'), $r['seen'] ?? null);
    pin('notify with undo uses the toast and its colour', true, $r['undoTone'] ?? null);
    pin('osc.toast.labels is still there', 'function', $r['labels'] ?? null);
    pin('nothing threw', array(), $r['errors'] ?? null);
}

harness_section('the stack: three in view, then "and N more"');

$r = alerts_run($shell(), <<<'JS'
const visible = () => [...document.querySelectorAll('#message-wrapper .flashmessage:not(.hide):not(.is-stacked)')].map((el) => el.querySelector('p').textContent);
osc.notify('error', 'Broken');
['A', 'B', 'C', 'D', 'E'].forEach((x) => osc.notify('ok', x));
r.visible = visible();
const more = document.querySelector('.osc-flash-more');
r.more = [more.textContent, more.hidden];
r.total = document.querySelectorAll('#message-wrapper .flashmessage:not(.hide)').length;
more.click();
r.expanded = visible().length;
r.moreHidden = more.hidden;
r.focus = document.activeElement && document.activeElement.classList.contains('ico-close');
JS, 1440, $real);
if ($r !== null) {
    pin('the error and the two newest stay in view', array('Broken', 'D', 'E'), $r['visible'] ?? null);
    pin('the rest are counted', array('and 3 more', false), $r['more'] ?? null);
    pin('none were lost', 6, $r['total'] ?? null);
    pin('the button shows them all', 6, $r['expanded'] ?? null);
    pin('and goes away', true, $r['moreHidden'] ?? null);
    pin('keyboard focus moves to what opened', true, $r['focus'] ?? null);
}

harness_section('auto-hide: ok and info after 7 s, errors and warnings stay');

$timing = <<<'JS'
const visible = () => [...document.querySelectorAll('#message-wrapper .flashmessage:not(.hide):not(.is-stacked)')].map((el) => el.textContent.replace(/[×x]/g, '').trim());
const c = window.alertsClock;
c.tick(100);
// A flash from the server.
r.pageShown = visible();
c.tick(6890);
r.pageBefore = visible();
c.tick(20);
c.tick(500);
r.pageAfter = visible();
r.outside = !!document.getElementById('plugin-flash');

osc.notify('ok', 'Saved');
c.tick(6990);
r.jsBefore = visible();
c.tick(20); c.tick(500);
r.jsAfter = visible();

osc.notify('error', 'Bad');
osc.notify('warning', 'Careful');
c.tick(30000);
r.errorsStay = visible();

r.dismissNone = null;
JS;
$flashes = '<div class="flashmessage flashmessage-ok" role="status">Server<a class="btn ico btn-mini ico-close" data-oc-close-label="Dismiss">x</a></div>'
    . '<div class="flashmessage flashmessage-info" role="status">State note</div>';
$below   = '<div id="plugin-flash" class="flashmessage flashmessage-ok" role="status">Plugin box</div>';
$r = alerts_run($shell($flashes, $below), $timing, 1440, $clock);
if ($r !== null) {
    pin('a server ok flash shows first', array('Server', 'State note'), $r['pageShown'] ?? null);
    pin('still there just before 7 s', array('Server', 'State note'), $r['pageBefore'] ?? null);
    pin('gone after 7 s, the one with no close button stays', array('State note'), $r['pageAfter'] ?? null);
    pin('a plugin flash outside the wrapper is not touched', true, $r['outside'] ?? null);
    pin('a JS ok message is there just before 7 s', array('State note', 'Saved'), $r['jsBefore'] ?? null);
    pin('and hides at 7 s', array('State note'), $r['jsAfter'] ?? null);
    pin('error and warning stay', array('State note', 'Bad', 'Careful'), $r['errorsStay'] ?? null);
    pin('nothing threw', array(), $r['errors'] ?? null);
}

$pause = <<<'JS'
const visible = () => [...document.querySelectorAll('#message-wrapper .flashmessage:not(.hide):not(.is-stacked)')].map((el) => el.textContent.replace(/[×x]/g, '').trim());
const c = window.alertsClock;
const wrap = document.getElementById('message-wrapper');
osc.notify('ok', 'Hovered');
c.tick(1000);
wrap.dispatchEvent(new MouseEvent('mouseenter'));
c.tick(20000);
r.hovered = visible();
wrap.dispatchEvent(new MouseEvent('mouseleave'));
c.tick(6000);
r.afterLeave = visible();
c.tick(1200);
c.tick(500);
r.afterHover = visible();

osc.notify('ok', 'Focused');
const close = document.querySelector('#jsMessage .ico-close');
close.setAttribute('tabindex', '0');
close.focus();
r.focused = document.activeElement === close;
c.tick(20000);
r.whileFocused = visible();
close.blur();
c.tick(0);
c.tick(7200);
c.tick(500);
r.afterBlur = visible();
JS;
$r = alerts_run($shell(), $pause, 1440, $clock);
if ($r !== null) {
    pin('hover stops the clock', array('Hovered'), $r['hovered'] ?? null);
    pin('leaving starts it again', array('Hovered'), $r['afterLeave'] ?? null);
    pin('then it hides', array(), $r['afterHover'] ?? null);
    pin('focus is on the close button', true, $r['focused'] ?? null);
    pin('focus stops the clock', array('Focused'), $r['whileFocused'] ?? null);
    pin('blur starts it again and it hides', array(), $r['afterBlur'] ?? null);
}

harness_section('sticking under the top bar when scrolled');

foreach (array(1440, 390) as $width) {
    $r = alerts_run($shell(), <<<'JS'
const wrap = document.getElementById('message-wrapper');
window.scrollTo({top: 2000, behavior: 'instant'});
await t.wait(200);
const before = window.scrollY;
osc.notify('ok', 'Saved on a long page');
await t.wait(200);
const box = document.getElementById('jsMessage').getBoundingClientRect();
const bar = document.getElementById('header').getBoundingClientRect();
r.pos = getComputedStyle(wrap).position;
r.top = box.top; r.bottom = box.bottom; r.barBottom = bar.bottom; r.height = window.innerHeight; r.moved = window.scrollY < before - 200;
JS, $width);
    if ($r !== null) {
        pin("at $width the wrapper is sticky", 'sticky', $r['pos'] ?? null);
        check("at $width the message clears the top bar", ($r['top'] ?? -1) >= ($r['barBottom'] ?? 0) - 1 && ($r['top'] ?? -1) >= 0, json_encode($r));
        check("at $width it is inside the window", ($r['bottom'] ?? 9999) <= ($r['height'] ?? 0), json_encode($r));
        pin("at $width the page did not move", false, $r['moved'] ?? null);
    }
}

harness_section('one live region, polite or assertive, said once');

$speech = <<<'JS'
const c = window.alertsClock;
const live = (m) => document.querySelector('#osc-live [data-osc-live="' + m + '"]');
c.tick(200);
await new Promise(requestAnimationFrame);
r.region = [live('polite').getAttribute('aria-live'), live('assertive').getAttribute('aria-live')];
r.pagePolite = live('polite').textContent;
r.pageAssertive = live('assertive').textContent;
r.flashRole = [document.querySelector('#message-wrapper .flashmessage').getAttribute('role')];
r.outsideRole = document.getElementById('plugin-flash').getAttribute('role');
const jsm = document.getElementById('jsMessage');
r.jsRole = [jsm.getAttribute('role'), jsm.getAttribute('aria-live')];
osc.notify('error', 'Boom');
c.tick(10);
r.afterError = [live('polite').textContent, live('assertive').textContent];
osc.notify('warning', 'Careful');
c.tick(1200);
r.afterWarning = live('polite').textContent;
let writes = 0;
const watch = new MutationObserver((m) => { writes += m.length; });
watch.observe(live('polite'), {childList: true, characterData: true, subtree: true});
const own = document.createElement('div');
document.body.appendChild(own);
osc.announce(own, 'Moved');
osc.toast('Moved');
await new Promise(requestAnimationFrame);
await new Promise(requestAnimationFrame);
r.own = own.textContent;
r.toastDouble = writes;
watch.disconnect();
r.polite = live('polite').textContent;
c.tick(2000);
await new Promise(requestAnimationFrame);
osc.toast('Solo');
await new Promise(requestAnimationFrame);
r.solo = live('polite').textContent;
JS;
$r = alerts_run($shell(
    '<div id="f1" class="flashmessage flashmessage-ok" role="status">All saved<a class="btn ico btn-mini ico-close">x</a></div>'
    . '<div id="f2" class="flashmessage flashmessage-error" role="alert">It broke<a class="btn ico btn-mini ico-close">x</a></div>',
    '<div id="plugin-flash" class="flashmessage flashmessage-ok" role="status">Plugin box</div>'
), $speech, 1440, $clock);
if ($r !== null) {
    pin('the region is polite and assertive', array('polite', 'assertive'), $r['region'] ?? null);
    pin('server messages are copied in, ok politely', 'All saved', $r['pagePolite'] ?? null);
    pin('and errors assertively', 'It broke', $r['pageAssertive'] ?? null);
    pin('the box in the stack is not a region of its own', array(null), $r['flashRole'] ?? null);
    pin('#jsMessage is not a region of its own', array(null, null), $r['jsRole'] ?? null);
    pin('a plugin box outside the stack keeps its role', 'status', $r['outsideRole'] ?? null);
    pin('an error is assertive', array('All saved', 'Boom'), $r['afterError'] ?? null);
    pin('a warning is polite', 'Careful', $r['afterWarning'] ?? null);
    pin('a caller announcing then toasting is said once', 'Moved', $r['own'] ?? null);
    pin('the shared region stays quiet for it', array(0, 'Careful'), array($r['toastDouble'] ?? -1, $r['polite'] ?? null));
    pin('a toast alone is spoken', 'Solo', $r['solo'] ?? null);
}
check('the header prints the region before the first message', str_contains($alertsSource('oc-admin/themes/modern/parts/header.php'), 'id="osc-live"'));

harness_section('reduced motion');

$r = alerts_run($shell(), <<<'JS'
osc.notify('ok', 'Quiet');
const box = document.getElementById('jsMessage');
r.anim = getComputedStyle(box).animationName;
window.alertsClock.tick(7100);
r.hiddenAtOnce = [box.classList.contains('hide'), box.classList.contains('is-dismissing')];
JS, 1440, $clock, 'ltr', true);
if ($r !== null) {
    pin('no entrance animation', 'none', $r['anim'] ?? null);
    pin('it hides at once with no exit animation', array(true, false), $r['hiddenAtOnce'] ?? null);
}

harness_section('one look');

$css = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/css/main.css');
check('the toast has no shadow', preg_match('~\.osc-toast\{[^}]*box-shadow~', str_replace(array("\n", ' '), '', $css)) === 0);
check('#jsMessage has no shadow utility', !str_contains($alertsSource('oc-admin/themes/modern/parts/header.php'), 'shadow-sm'));

harness_section('hand-built banners do not come back');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-admin/themes/modern')) as $file) {
    if ($file->getExtension() !== 'php' || basename($file->getPathname()) === 'header.php') {
        continue;
    }
    $src = (string) file_get_contents($file->getPathname());
    check(str_replace(ABS_PATH, '', $file->getPathname()) . ' has no hand-built flash banner', preg_match('~class="[^"]*flashmessage-(?:ok|error|warning|info)~', $src) === 0);
}
check('a standing plugin problem is not a flash', !str_contains($alertsSource('oc-admin/themes/modern/functions.php'), 'osc_add_flash_warning_message('));
check('and a refused settings page is not either', !preg_match('~osc_settings_refused_notices\(\)\s*\{.*?osc_add_flash_warning_message~s', $alertsSource('oc-includes/osclass/helpers/hSettings.php')));
check('the title rule keeps a banner in the page head visible', str_contains($alertsSource('oc-admin/themes/modern/scss/render-page.scss'), '#help-box.show, .flashmessage, '));

exit(harness_result());

/* file end: ./tests/admin-alerts-ui.php */
