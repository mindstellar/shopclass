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
 * The custom-field markup on the publish form and the script that loads it into #plugin-hook.
 *
 * Labels must name the control they sit beside, date fields must work with no jQuery UI, and
 * the loader must not leave a stale or broken set of fields behind when the category changes.
 * The markup is rendered for real; the script is driven in headless Chrome with a stubbed fetch.
 *
 * Usage: php tests/item-form-custom-fields.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/Params.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

/** Stored interval values, as Field::getDateIntervalByPrimaryKey() hands them over. */
class Field
{
    public static function getInstance(): self
    {
        return new self();
    }

    public static function newInstance(): self
    {
        return self::getInstance();
    }

    public function getDateIntervalByPrimaryKey($itemId, $fieldId)
    {
        return $GLOBALS['testInterval'][$fieldId] ?? array();
    }
}

class Session
{
    public static function getInstance(): self
    {
        return new self();
    }

    public static function newInstance(): self
    {
        return self::getInstance();
    }

    public function _getForm($key = '')
    {
        return '';
    }
}

class Category
{
    public static function getInstance(): self
    {
        return new self();
    }

    public static function newInstance(): self
    {
        return self::getInstance();
    }

    public function listAll($order = true)
    {
        return array(
            array('pk_i_id' => 1, 'b_price_enabled' => 1),
            array('pk_i_id' => 2, 'b_price_enabled' => 1),
            array('pk_i_id' => 3, 'b_price_enabled' => 0),
        );
    }
}

function osc_get_admin_locales()
{
    return array();
}
function osc_current_admin_locale()
{
    return 'en_US';
}
function osc_get_locales()
{
    return array();
}
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_base_url($withIndex = false)
{
    return 'http://test.local/' . ($withIndex ? 'index.php' : '');
}
function osc_admin_base_url($withIndex = false)
{
    return 'http://test.local/oc-admin/' . ($withIndex ? 'index.php' : '');
}
function osc_esc_js($s)
{
    return addslashes((string) $s);
}
function osc_enqueue_script($id)
{
}
function osc_enqueue_style($id)
{
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

$field = static function (int $id, string $slug, string $type, $value = '', array $extra = array()): array {
    return array_merge(array(
        'pk_i_id'       => $id,
        's_slug'        => $slug,
        's_name'        => ucfirst($slug),
        'e_type'        => $type,
        's_value'       => $value,
        's_options'     => 'red,green',
        'fk_i_item_id'  => 42,
        'b_required'    => 0,
    ), $extra);
};

$render = static function (array $f, bool $search = false): string {
    ob_start();
    FieldForm::meta($f, $search);

    return (string) ob_get_clean();
};

/** The opening tag of the element carrying this id, or '' when none does. */
$tag = static function (string $html, string $id): string {
    return preg_match('/<[a-z]+[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m) ? $m[0] : '';
};

// 2026-03-14 and 2026-03-20 at 00:00 UTC.
$march14 = 1773446400;
$march20 = 1773964800;
$GLOBALS['testInterval'][12] = array('from' => (string) $march14, 'to' => (string) ($march20 + 86399));

harness_section('a date field');

$date = $render($field(11, 'built', 'DATE', (string) $march14));
check('the hidden input keeps its posted name and id', (bool) preg_match('/<input name="meta\[11\]"[^>]*type="hidden"[^>]*value="' . $march14 . '"/', $date) && str_contains($tag($date, 'meta_built'), 'type="hidden"'), $date);
$visible = $tag($date, 'meta_built_date');
check('the visible control is a native date input', str_contains($visible, 'type="date"'), $visible);
check('it points at the hidden input it writes into', str_contains($visible, 'data-osc-date="meta_built"'), $visible);
check('it shows the stored date on edit', str_contains($visible, 'value="2026-03-14"'), $visible);
check('it keeps the classes themes style it by', str_contains($visible, 'cf_date meta_built'), $visible);
check('the label points at the date input, not at a name', str_contains($date, 'for="meta_built_date"'), $date);
check('no jQuery UI datepicker is called', !preg_match('/\.datepicker\s*\(|jQuery|\$\(/', $date));
check('the shared date script is printed with the first date field', str_contains($date, 'window.oscDateFields'));
$empty = $render($field(13, 'empty', 'DATE', ''));
check('an empty date shows an empty date input', str_contains($tag($empty, 'meta_empty_date'), 'value=""'), $tag($empty, 'meta_empty_date'));
check('the script is printed once per request', !str_contains($empty, 'window.oscDateFields'));

harness_section('a date range field');

$range = $render($field(12, 'stay', 'DATEINTERVAL'));
check('the from end posts as before', (bool) preg_match('/name="meta\[12\]\[from\]"[^>]*type="hidden"[^>]*value="' . $march14 . '"/', $range), $range);
check('the to end posts as before', (bool) preg_match('/name="meta\[12\]\[to\]"[^>]*type="hidden"[^>]*value="' . ($march20 + 86399) . '"/', $range), $range);
$from = $tag($range, 'meta_stay_from_date');
$to   = $tag($range, 'meta_stay_to_date');
check('from shows its stored date', str_contains($from, 'type="date"') && str_contains($from, 'value="2026-03-14"'), $from);
check('to shows its stored date, not the next day', str_contains($to, 'value="2026-03-20"'), $to);
check('only the to end stores the end of its day', str_contains($to, 'data-osc-date-end="day"') && !str_contains($from, 'data-osc-date-end'), $from . $to);
check('the two ends are named From and To', str_contains($from, 'aria-label="From"') && str_contains($to, 'aria-label="To"'), $from . $to);
check('the label names the group, not one control', str_contains($range, '<label id="meta_stay-label">') && !str_contains($range, 'for="meta_stay'), $range);
check('the pair is a group labelled by it', str_contains($range, 'role="group" aria-labelledby="meta_stay-label"'), $range);

harness_section('labels point at real controls');

$text = $render($field(14, 'colour', 'TEXT', 'blue'));
check('a text field label points at the input id', str_contains($text, 'for="meta_colour"') && $tag($text, 'meta_colour') !== '', $text);
$select = $render($field(15, 'size', 'DROPDOWN', 'red'));
check('a dropdown label points at the select id', str_contains($select, 'for="meta_size"') && str_starts_with($tag($select, 'meta_size'), '<select'), $select);
$box = $render($field(16, 'boxed', 'CHECKBOX', '1'));
check('a checkbox label points at the checkbox id', str_contains($box, 'for="meta_boxed"') && str_contains($tag($box, 'meta_boxed'), 'type="checkbox"'), $box);
$radio = $render($field(17, 'tone', 'RADIO', 'green'));
check('a radio list is a group named by its label', str_contains($radio, 'id="meta_tone-label"') && str_contains($radio, 'role="group" aria-labelledby="meta_tone-label"'), $radio);
check('the radio group label has no dead for', !preg_match('/<label[^>]*for="meta\[17\]"/', $radio), $radio);
$num = $render($field(18, 'year', 'NUMBER'), true);
check('a searched number range is a labelled group with From and To', str_contains($num, 'role="group" aria-labelledby="meta_year-label"') && str_contains($num, 'aria-label="From"') && str_contains($num, 'aria-label="To"'), $num);
check('and its two inputs no longer share one id', substr_count($num, 'id="meta_year"') === 0, $num);

harness_section('the #plugin-hook wrapper');

ob_start();
ItemForm::plugin_post_item();
$post = (string) ob_get_clean();
check('the wrapper is printed empty, so :empty matches before the script runs', str_contains($post, '<div id="plugin-hook"></div>'), $post);
check('the loader fires osc:item-fields-loaded', str_contains($post, "'osc:item-fields-loaded'"));
check('the loader needs no jQuery', !preg_match('/jQuery\s*\(|\$\(|\$\./', $post));

/* ----------------------------------------------------------------------------
 * The script, run in a real browser.
 * ------------------------------------------------------------------------- */

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

$chrome = test_browser();
if ($chrome === '' && (string) getenv('CI') !== '') {
    check('a browser is available to drive the loader', false, 'no Chrome or Chromium, so the browser assertions did not run');
}
if ($chrome === '') {
    echo "  (skipped: no Chrome or Chromium on this machine)\n";
} else {
    // What the server answers for category 2: the date fields above, scripts included.
    $replyTwo = '<div class="meta_list"><div class="meta">' . $date . '</div><div class="meta">' . $range . '</div></div>'
        . '<script>window.hookScriptRan = (window.hookScriptRan || 0) + 1;</script>';

    $dir = sys_get_temp_dir() . '/osc-item-fields-' . getmypid();
    @mkdir($dir, 0700, true);
    $fixture = $dir . '/page.html';
    file_put_contents($fixture, '<!doctype html><html><head><meta charset="utf-8"></head><body>'
        . '<script>window.replyTwo = ' . json_encode($replyTwo) . ';</script>'
        . '<script>' . file_get_contents(__DIR__ . '/lib/item-fields-fetch.js') . '</script>'
        . '<form><select id="catId"><option value="">-</option><option value="1">1</option>'
        . '<option value="2">2</option><option value="3">3</option></select>'
        . '<input id="price" name="price">'
        . $post . '</form>'
        . '<pre id="osc-out"></pre>'
        . '<script>' . file_get_contents(__DIR__ . '/lib/item-fields-driver.js') . '</script>'
        . '</body></html>');

    $out = (string) shell_exec('TZ=UTC ' . escapeshellarg($chrome)
        . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
        . ' --user-data-dir=' . escapeshellarg($dir . '/profile')
        . ' --virtual-time-budget=10000 --dump-dom ' . escapeshellarg('file://' . $fixture) . ' 2>/dev/null');
    shell_exec('rm -rf ' . escapeshellarg($dir));

    $r = null;
    if (preg_match('#<pre id="osc-out">([A-Za-z0-9+/=]*)</pre>#', $out, $m) && $m[1] !== '') {
        $r = json_decode((string) base64_decode($m[1], true), true);
    }
    check('the browser run produced a reading', is_array($r), substr($out, 0, 400));
    $r = is_array($r) ? $r : array();

    pin('an empty category at load sends no request', 0, $r['initialRequests'] ?? null);
    pin('the late reply for category 1 is dropped; category 2 is shown', 'two', $r['afterRace']['shown'] ?? null);
    pin('one event fired, for category 2', array('2'), $r['afterRace']['events'] ?? null);
    pin('scripts in the reply ran once', 1, $r['afterRace']['scriptRan'] ?? null);
    pin('a server error keeps the fields on screen', 'two', $r['afterError']['shown'] ?? null);
    pin('and fires no event', array('2'), $r['afterError']['events'] ?? null);
    pin('the stored date shows in the date input', '2026-03-14', $r['dates']['dateShown'] ?? null);
    pin('the stored range end shows its own day', '2026-03-20', $r['dates']['toShown'] ?? null);
    pin('picking a date writes its midnight timestamp', (string) 1777593600, $r['dates']['dateWritten'] ?? null);
    pin('picking a range end writes 23:59:59 of that day', (string) (1777766400 + 86399), $r['dates']['toWritten'] ?? null);
    pin('picking a range start writes its midnight', (string) 1777593600, $r['dates']['fromWritten'] ?? null);
    pin('clearing a date clears the timestamp', '', $r['dates']['cleared'] ?? null);
    pin('clearing the category empties #plugin-hook', true, $r['afterClear']['empty'] ?? null);
    pin('and fires the event with an empty catId', array('2', ''), $r['afterClear']['events'] ?? null);
    pin('the event bubbles from #plugin-hook', 'plugin-hook', $r['afterClear']['target'] ?? null);
}

exit(harness_result());
