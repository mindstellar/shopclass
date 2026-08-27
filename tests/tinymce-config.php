<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * osc_tinymce_config() and the rule that every editor goes through it.
 *
 * The base config was copied into five call sites, so when TinyMCE 7 made license_key
 * mandatory four were updated and one -- the front-end listing editor -- was not. The
 * result was an editor that refused to load on the public post page, and nothing in the
 * suite could have noticed. Two things are pinned here: the helper's own contract, and
 * that no init() anywhere hand-rolls its config again.
 *
 * No database and no bootstrap: the helper is pure, and the call-site rule is a scan.
 * Usage:  php tests/tinymce-config.php
 */

// Defined before hForms.php loads, so there is no redeclaration. The filter hook is the
// only thing the helper reaches outside itself. hUtils.php is a plain function library
// with no load-time registration, which is why the helper lives there and not beside the
// form contexts in hForms.php -- those register widgets as they load.
function osc_apply_filter($hook, $content, ...$args)
{
    return isset($GLOBALS['__filter']) ? ($GLOBALS['__filter'])($content, ...$args) : $content;
}

require_once __DIR__ . '/../oc-includes/osclass/helpers/hUtils.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** Decoded config for a preset + overrides. */
function cfg($preset = 'basic', array $overrides = array())
{
    return json_decode(osc_tinymce_config($preset, $overrides), true);
}

/* ----------------------------------------------------------------------------
 * The base every editor shares. license_key leads the list because its absence is
 * not a degraded editor -- it is no editor at all.
 * ------------------------------------------------------------------------- */
harness_section('osc_tinymce_config — the shared base');

foreach (array('basic', 'full') as $preset) {
    $c = cfg($preset);
    pin("[$preset] declares the bundled GPL licence", 'gpl', $c['license_key'] ?? null);
    pin("[$preset] promotion off", false, $c['promotion'] ?? null);
    pin("[$preset] branding off", false, $c['branding'] ?? null);
    pin("[$preset] menubar off", false, $c['menubar'] ?? null);
    pin("[$preset] entities left raw", 'raw', $c['entity_encoding'] ?? null);
    pin("[$preset] urls untouched", false, $c['convert_urls'] ?? null);
    pin("[$preset] relative urls off", false, $c['relative_urls'] ?? null);
    pin("[$preset] script host kept", false, $c['remove_script_host'] ?? null);
}

/* ----------------------------------------------------------------------------
 * The two presets differ only in what the writer can reach for.
 * ------------------------------------------------------------------------- */
harness_section('osc_tinymce_config — presets');

$basic = cfg('basic');
$full  = cfg('full');

pin('basic is the lean plugin set', 'autolink lists link code', $basic['plugins']);
check('basic offers no image button', strpos($basic['toolbar'], 'image') === false);
check('basic has no paste-cleaning block', !isset($basic['smart_paste']));
check('basic sets no content_style', !isset($basic['content_style']));

check('full can embed images and media', strpos($full['plugins'], 'image') !== false
    && strpos($full['plugins'], 'media') !== false);
pin('full cleans Word paste', true, $full['smart_paste'] ?? null);
pin('full never inlines pasted images', false, $full['paste_data_images'] ?? null);
check('full styles the editing surface', isset($full['content_style']));

pin('an unknown preset falls back to basic, never to nothing', $basic, cfg('nonsense'));

/* ----------------------------------------------------------------------------
 * Overrides and the plugin seam.
 * ------------------------------------------------------------------------- */
harness_section('osc_tinymce_config — overrides and the filter');

$over = cfg('basic', array('selector' => 'textarea#x', 'height' => 320));
pin('the caller supplies the selector', 'textarea#x', $over['selector']);
pin('...and any extra it needs', 320, $over['height']);

pin(
    'an override beats the preset',
    'autolink lists',
    cfg('basic', array('plugins' => 'autolink lists'))['plugins']
);
pin(
    'an override beats the base too',
    true,
    cfg('basic', array('menubar' => true))['menubar']
);

$GLOBALS['__filter'] = static function ($config, $preset) {
    $config['height'] = 999;
    $config['seen']   = $preset;

    return $config;
};
$filtered = cfg('full', array('height' => 460));
pin('tinymce_config lets a plugin reach these editors', 999, $filtered['height']);
pin('...and is told which preset it is amending', 'full', $filtered['seen']);
unset($GLOBALS['__filter']);

check('the helper returns embeddable JSON', is_string(osc_tinymce_config('basic')));
check(
    'a selector with quotes survives encoding',
    json_decode(osc_tinymce_config('basic', array('selector' => 'textarea[id^="description"]')), true)['selector']
    === 'textarea[id^="description"]'
);

/* ----------------------------------------------------------------------------
 * The rule that keeps the base in one place: no call site may hand-roll a config.
 * A literal init({...}) is how the five drifted apart in the first place.
 * ------------------------------------------------------------------------- */
harness_section('every editor is configured through the helper');

$roots   = array(__DIR__ . '/../oc-includes', __DIR__ . '/../oc-admin');
$offend  = array();
$editors = 0;
foreach ($roots as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php' || strpos($file->getPathname(), '/assets/') !== false) {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        if (!preg_match('/tiny(?:MCE|mce)\.init\s*\(/', $src)) {
            continue;
        }
        $editors++;
        // Every init must be handed a config the helper built -- directly, or through a
        // variable the helper filled in on the line above.
        if (!preg_match('/osc_tinymce_config\s*\(/', $src)) {
            $offend[] = str_replace(dirname(__DIR__) . '/', '', (string) realpath($file->getPathname()));
        }
    }
}

check('the scan found the editors it is meant to police', $editors >= 5);
pin('no editor hand-rolls its own config', '', implode(', ', $offend));

exit(harness_result());
