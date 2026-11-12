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
 * The place field as PlaceField::render() prints it, with ui-common.js/.css and place-field.js,
 * in headless Chrome: the list is as wide as the input and inside the viewport at desktop and
 * phone width, long rows wrap, a keyboard-only pick fills the ids, and an edited listing's
 * place keeps its note.
 *
 * Usage: php tests/place-field-browser.php
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

$tmp = sys_get_temp_dir() . '/osc-place-browser-' . getmypid();
mkdir($tmp, 0700, true);
$kept    = array_fill_keys(PlaceField::KEYS, '');
$listing = array('fk_i_city_id' => '7', 's_city' => 'Pune', 'fk_i_region_id' => '10', 's_region' => 'Maharashtra', 'fk_c_country_code' => 'IN');
$assets  = 'file://' . ABS_PATH . 'oc-includes/assets/osclass/';
file_put_contents($tmp . '/page.html', '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="shopclass-api" content=\'{"url":"https://x.test/api/v1","header":"X-Shopclass-Token","token":"tok"}\'>'
    . '<link rel="stylesheet" href="' . $assets . 'ui-common.css">'
    . '<style>body{margin:0;padding:16px;font:15px/1.5 system-ui,sans-serif}.field{max-width:680px;margin-inline:auto}'
    . '.oe-place__input{min-height:40px;font:inherit;border:1px solid #888;border-radius:6px}</style></head><body>'
    . '<form class="field">' . PlaceField::render(array('id' => 'pl'), PlaceField::value($kept, null, null), true, true) . '</form>'
    . '<form class="field">' . PlaceField::render(array('id' => 'ed'), PlaceField::value($kept, $listing, null), true, true) . '</form>'
    . <<<'HTML'
<script>
window.fetch = function () {
  var rows = [
    {id: 1, name: 'Mumbai', region_id: 10, region: 'Maharashtra', country_code: 'IN', country: 'India', label: 'Mumbai, Maharashtra'},
    {id: 2, name: 'Munnar', region_id: 11, region: 'Kerala', country_code: 'IN', country: 'India', label: 'Munnar, Kerala'},
    {id: 3, name: 'Muhlheim an der Ruhr', region_id: 12, region: 'A very long region name that has to wrap inside the list and never push it wider than the input', country_code: 'DE', country: 'Germany', label: 'x'}
  ];
  return Promise.resolve({ok: true, status: 200, json: function () { return Promise.resolve({data: rows, meta: {more: 3}}); }});
};
</script>
HTML
    . '<script src="' . $assets . 'ui-common.js"></script><script src="' . $assets . 'place-field.js"></script></body></html>');
file_put_contents($tmp . '/run.mjs', <<<'MJS'
const [pc, chrome, page] = process.argv.slice(2);
const {default: puppeteer} = await import(pc + '/lib/puppeteer/puppeteer-core.js');
const browser = await puppeteer.launch({executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-gpu']});
const out = {};
try {
    for (const width of [1440, 390]) {
        const tab = await browser.newPage();
        await tab.setViewport({width, height: 900});
        const errors = [];
        tab.on('pageerror', (e) => errors.push(String(e.message)));
        await tab.goto('file://' + page, {waitUntil: 'load'});
        const edit = await tab.evaluate(() => ({
            note: document.getElementById('ed-note').textContent.trim(),
            tick: !!document.querySelector('#ed-note svg'),
            picked: document.getElementById('ed').closest('.oe-place').classList.contains('is-picked'),
        }));
        await tab.focus('#pl');
        await tab.keyboard.type('mu', {delay: 10});
        await tab.waitForSelector('.osc-ac-option', {timeout: 3000});
        const m = await tab.evaluate(() => {
            const i = document.getElementById('pl').getBoundingClientRect();
            const l = document.getElementById('pl-list');
            const r = l.getBoundingClientRect();
            return {input: i.width, list: r.width, left: r.left, right: r.right, inputLeft: i.left,
                view: document.documentElement.clientWidth, overflowX: l.scrollWidth > l.clientWidth,
                pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                rows: l.querySelectorAll('.osc-ac-option').length};
        });
        await tab.keyboard.press('ArrowDown');
        await tab.keyboard.press('ArrowDown');
        await tab.keyboard.press('Enter');
        const after = await tab.evaluate(() => ({
            value: document.getElementById('pl').value,
            ids: ['cityId', 'regionId', 'countryId'].map((n) => document.getElementById('pl-' + n).value),
            open: !document.getElementById('pl-list').hidden,
            picked: document.getElementById('pl').closest('.oe-place').classList.contains('is-picked'),
            note: document.getElementById('pl-note').textContent.trim(),
            focused: document.activeElement.id,
        }));
        out[width] = {m, after, edit, errors};
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
    $m = $r[$w]['m'] ?? array();
    $a = $r[$w]['after'] ?? array();
    pin('the list shows the three rows', 3, $m['rows'] ?? null);
    check('the list is exactly as wide as the input', abs(($m['list'] ?? 0) - ($m['input'] ?? -1)) < 1, json_encode($m));
    check('the list starts at the input edge', abs(($m['left'] ?? 0) - ($m['inputLeft'] ?? -1)) < 1, json_encode($m));
    check('the right edge is inside the viewport', ($m['right'] ?? 9999) <= ($m['view'] ?? 0), json_encode($m));
    pin('long rows wrap instead of scrolling sideways', false, $m['overflowX'] ?? null);
    pin('the page does not scroll sideways', false, $m['pageOverflow'] ?? null);
    pin('keyboard only: the second row is picked', array('Munnar, Kerala', array('2', '11', 'IN'), false, true, 'pl', 'Picked from the list'),
        array($a['value'] ?? null, $a['ids'] ?? null, $a['open'] ?? null, $a['picked'] ?? null, $a['focused'] ?? null, $a['note'] ?? null));
    pin('an edited listing\'s place keeps its own note and tick', array('This listing’s place', true, true), array_values($r[$w]['edit'] ?? array()));
    pin('nothing threw', array(), $r[$w]['errors'] ?? null);
}

exit(harness_result());
