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
 * The place field end to end in headless Chrome at desktop and phone width: the markup
 * PlaceField::render() prints, with ui-common.js/.css and place-field.js and a stub for the
 * cities route. A keyboard pick sets the ids, editing clears them, "Only places from the list"
 * stops a submit of typed text and the option off lets it through, and the list is as wide as
 * the input and inside the viewport.
 *
 * Usage: php tests/place-picker-e2e-browser.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

function _m($text)
{
    return $text;
}
function osc_location_name(string $type, $id, string $fallback = ''): string
{
    return $fallback;
}

use mindstellar\location\PlaceField;

$chrome = test_browser();
$pc     = test_puppeteer();
if ($chrome === '' || $pc === '') {
    if ((string) getenv('CI') !== '') {
        check('a browser and puppeteer-core are available', false, 'run npm ci');
    }
    echo "  (skipped: no browser or puppeteer-core)\n";
    exit(harness_result());
}

$tmp = sys_get_temp_dir() . '/osc-place-e2e-' . getmypid();
mkdir($tmp, 0700, true);
$kept   = array_fill_keys(PlaceField::KEYS, '');
$empty  = PlaceField::value($kept, null, null);
$assets = 'file://' . ABS_PATH . 'oc-includes/assets/osclass/';
file_put_contents($tmp . '/page.html', '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="shopclass-api" content=\'{"url":"https://x.test/api/v1","header":"X-Shopclass-Token","token":"tok"}\'>'
    . '<link rel="stylesheet" href="' . $assets . 'ui-common.css">'
    . '<style>body{margin:0;padding:16px;font:15px/1.5 system-ui,sans-serif}form{max-width:680px;margin:0 auto 200px}'
    . '.oe-place__input{min-height:40px;font:inherit;border:1px solid #888;border-radius:6px}</style></head><body>'
    . '<form id="on">' . PlaceField::render(array('id' => 'on-pl'), $empty, true, true) . '<button type="submit">Save</button></form>'
    . '<form id="off">' . PlaceField::render(array('id' => 'off-pl'), $empty, false, true) . '<button type="submit">Save</button></form>'
    . <<<'HTML'
<script>
window.fetch = function () {
  var rows = [
    {id: 1, name: 'Mumbai', region_id: 10, region: 'Maharashtra', country_code: 'IN', country: 'India', label: 'Mumbai, Maharashtra'},
    {id: 2, name: 'Munnar', region_id: 11, region: 'Kerala', country_code: 'IN', country: 'India', label: 'Munnar, Kerala'},
    {id: 3, name: 'Muhlheim an der Ruhr', region_id: 12, region: 'A very long region name that has to wrap inside the list and never push it wider than the input', country_code: 'DE', country: 'Germany', label: 'x'}
  ];
  return Promise.resolve({ok: true, status: 200, json: function () { return Promise.resolve({data: rows, meta: {more: 0}}); }});
};
</script>
HTML
    . '<script src="' . $assets . 'ui-common.js"></script><script src="' . $assets . 'place-field.js"></script>'
    . <<<'HTML'
<script>
window.submitted = {};
window.addEventListener('load', function () {
  ['on', 'off'].forEach(function (id) {
    document.getElementById(id).addEventListener('submit', function (e) {
      window.submitted[id] = !e.defaultPrevented;
      e.preventDefault();
    });
  });
});
</script>
HTML
    . '</body></html>');
file_put_contents($tmp . '/run.mjs', <<<'MJS'
const [pc, chrome, page] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-gpu']});
const out = {};
const state = (tab, id) => tab.evaluate((id) => {
    const root = document.getElementById(id).closest('.oe-place');
    return {
        value: document.getElementById(id).value,
        ids: ['cityId', 'regionId', 'countryId'].map((n) => document.getElementById(id + '-' + n).value),
        picked: root.classList.contains('is-picked'),
        open: !document.getElementById(id + '-list').hidden,
    };
}, id);
const submit = (tab, form) => tab.evaluate((form) => {
    document.getElementById(form).requestSubmit();
    const err = document.querySelector('#' + form + ' .oe-field-error');
    const input = document.querySelector('#' + form + ' .oe-place__input');
    return {
        passed: window.submitted[form],
        error: err.hidden ? '' : err.textContent.trim(),
        invalid: input.getAttribute('aria-invalid'),
        focused: document.activeElement === input,
    };
}, form);
try {
    for (const width of [1440, 390]) {
        const tab = await browser.newPage();
        await tab.setViewport({width, height: 900});
        const errors = [];
        tab.on('pageerror', (e) => errors.push(String(e.message)));
        await tab.goto('file://' + page, {waitUntil: 'load'});
        const r = {};

        await tab.focus('#on-pl');
        await tab.keyboard.type('mu', {delay: 10});
        await tab.waitForSelector('#on-pl-list .osc-ac-option', {timeout: 3000});
        r.list = await tab.evaluate(() => {
            const i = document.getElementById('on-pl').getBoundingClientRect();
            const l = document.getElementById('on-pl-list').getBoundingClientRect();
            return {input: i.width, list: l.width, left: l.left, inputLeft: i.left, right: l.right, view: document.documentElement.clientWidth};
        });
        await tab.keyboard.press('ArrowDown');
        await tab.keyboard.press('Enter');
        r.picked = await state(tab, 'on-pl');

        await tab.keyboard.type('x');
        r.edited = await state(tab, 'on-pl');

        await tab.evaluate(() => { const i = document.getElementById('on-pl'); i.value = ''; });
        await tab.focus('#on-pl');
        await tab.keyboard.type('Bombay (Andheri)');
        await tab.keyboard.press('Escape');
        r.refused = await submit(tab, 'on');

        await tab.focus('#off-pl');
        await tab.keyboard.type('Bombay (Andheri)');
        await tab.keyboard.press('Escape');
        r.allowed = await submit(tab, 'off');
        r.allowedState = await state(tab, 'off-pl');
        r.errors = errors;
        out[width] = r;
        await tab.close();
    }
} finally {
    await browser.close();
}
console.log(JSON.stringify(out));
MJS);
$out = (string) shell_exec('node ' . escapeshellarg($tmp . '/run.mjs') . ' ' . escapeshellarg($pc) . ' ' . escapeshellarg($chrome)
    . ' ' . escapeshellarg($tmp . '/page.html') . ' 2>&1');
shell_exec('rm -rf ' . escapeshellarg($tmp));
$r = json_decode(trim($out), true);
check('the browser run produced a reading', is_array($r), substr($out, 0, 400));
$r = is_array($r) ? $r : array();

foreach (array(1440, 390) as $w) {
    harness_section('at ' . $w . ' px wide');
    $m = $r[$w]['list'] ?? array();
    check('the list is as wide as the input, starts at its edge and ends inside the viewport',
        abs(($m['list'] ?? 0) - ($m['input'] ?? -1)) < 1 && abs(($m['left'] ?? 0) - ($m['inputLeft'] ?? -1)) < 1 && ($m['right'] ?? 9999) <= ($m['view'] ?? 0), json_encode($m));
    pin('type mu, ArrowDown, Enter picks Mumbai and sets the three ids', array('Mumbai, Maharashtra', array('1', '10', 'IN'), true, false), array_values($r[$w]['picked'] ?? array()));
    pin('editing the text clears the ids and the picked state', array(array('', '', ''), false), array($r[$w]['edited']['ids'] ?? null, $r[$w]['edited']['picked'] ?? null));
    pin('with the option on, typed text is stopped with the pick message', array(false, 'Pick your location from the list.', 'true', true),
        array_values($r[$w]['refused'] ?? array()));
    pin('with the option off, typed text submits with no error', array(true, '', null, array('', '', ''), false),
        array($r[$w]['allowed']['passed'] ?? null, $r[$w]['allowed']['error'] ?? null, $r[$w]['allowed']['invalid'] ?? null, $r[$w]['allowedState']['ids'] ?? null, $r[$w]['allowedState']['picked'] ?? null));
    pin('nothing threw', array(), $r[$w]['errors'] ?? null);
}

exit(harness_result());
