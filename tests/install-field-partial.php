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
 * Pins the installer field helper: described-by wiring, error visibility, reveal button,
 * escaping and select state.
 *
 * DB-free.  Usage: php tests/install-field-partial.php
 */

function __($text)
{
    return $text;
}

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/lib/harness.php';

$helper = __DIR__ . '/../oc-includes/osclass/installer/gui/fields.php';
check('fields.php exists', is_file($helper));
if (!is_file($helper)) {
    exit(harness_result());
}
require_once $helper;

$out = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

harness_section('Invalid field');
$html = $out(static fn () => install_field(array('id' => 'dbhost', 'label' => 'Host', 'help' => 'Usually localhost.', 'error' => 'Check the host.', 'invalid' => true)));
check('aria-invalid is true', str_contains($html, 'aria-invalid="true"'));
check('describedby lists help then error', str_contains($html, 'aria-describedby="dbhost-help dbhost-error"'));
check('error text is shown and not hidden', str_contains($html, 'Check the host.') && !preg_match('/id="dbhost-error"[^>]*hidden/', $html));

harness_section('Valid field');
$html = $out(static fn () => install_field(array('id' => 'dbhost', 'label' => 'Host', 'help' => 'Usually localhost.', 'error' => 'Check the host.')));
check('no aria-invalid', !str_contains($html, 'aria-invalid'));
check('error is hidden', (bool) preg_match('/id="dbhost-error"[^>]*hidden/', $html));
check('error is not in describedby', str_contains($html, 'aria-describedby="dbhost-help"') && !str_contains($html, 'dbhost-help dbhost-error'));

harness_section('Password');
$html = $out(static fn () => install_field(array('id' => 's_passwd', 'name' => 's_passwd', 'type' => 'password', 'label' => 'Password', 'reveal' => true, 'generate' => true)));
check('reveal button targets the input', str_contains($html, 'data-reveal-target="s_passwd"'));
check('reveal button starts unpressed', str_contains($html, 'aria-pressed="false"'));
check('reveal button is labelled Show password', str_contains($html, 'aria-label="Show password"'));
check('generate button has its id', str_contains($html, 'id="ins-gen-s_passwd"') && str_contains($html, 'data-generate-target="s_passwd"'));
check('generate is a text button under the field, not a second icon in it', (bool) preg_match('/<\/div><button type="button" class="ins-text-btn" id="ins-gen-s_passwd"[^>]*\shidden>.*?<span>Make a strong password<\/span><\/button>/', $html) && substr_count($html, 'ins-input-btn') === 1);
check('no help line without help text', !str_contains($html, 'ins-help'));

harness_section('Escaping and select');
$html = $out(static fn () => install_field(array('id' => 'webtitle', 'label' => 'Name', 'value' => '"><script>')));
check('value is escaped', !str_contains($html, '<script>') && str_contains($html, '&quot;&gt;&lt;script&gt;'));
$html = $out(static fn () => install_field(array('id' => 'country', 'type' => 'select', 'label' => 'Country', 'value' => 'b', 'opt' => '(for locations)', 'options' => array('a' => 'A', 'b' => 'B'))));
check('chosen option is selected', str_contains($html, '<option value="b" selected>') && !str_contains($html, '<option value="a" selected>'));
check('opt suffix is shown', str_contains($html, '<span class="ins-opt">(for locations)</span>'));

harness_section('Check row');
$html = $out(static fn () => install_check(array('id' => 'createdb', 'title' => 'A <script>x</script>', 'help' => 'Help <b>', 'checked' => true, 'invalid' => true)));
check('check row is a label wrapping the box', str_starts_with($html, '<label class="ins-check" for="createdb"><input type="checkbox" id="createdb" name="createdb" value="1"'));
check('check row carries checked and aria-invalid', str_contains($html, 'checked="checked"') && str_contains($html, 'aria-invalid="true"'));
check('check row escapes title and help', !str_contains($html, '<script>') && str_contains($html, 'A &lt;script&gt;x&lt;/script&gt;') && str_contains($html, 'Help &lt;b&gt;'));
$html = $out(static fn () => install_check(array('id' => 'p', 'name' => 'shop_plugins[]', 'value' => 'tax', 'title' => 'Tax')));
check('check row without help, state or flags', !str_contains($html, 'ins-help') && !str_contains($html, 'checked') && !str_contains($html, 'aria-invalid') && str_contains($html, 'name="shop_plugins[]" value="tax"'));

harness_section('Panel');
$html = $out(static fn () => install_panel('danger', 'T <script>', 'Text <i>', array('id' => 'box', 'hidden' => true)));
check('panel has id, tone, alert role, hidden', str_contains($html, 'id="box"') && str_contains($html, 'ins-panel-danger') && str_contains($html, 'role="alert"') && str_contains($html, ' hidden>'));
check('panel has an icon, title and text', str_contains($html, 'class="ins-icon ins-panel-icon"') && str_contains($html, '<p class="ins-panel-title">') && str_contains($html, '<p class="ins-panel-text">'));
check('panel escapes title and text', !str_contains($html, '<script>') && str_contains($html, 'T &lt;script&gt;') && str_contains($html, 'Text &lt;i&gt;'));
$html = $out(static fn () => install_panel('warning', '', 'Safe <b>x</b>', array('html_text' => true)));
check('warning tone, no title when empty', str_contains($html, 'ins-panel-warning') && !str_contains($html, 'ins-panel-title'));
check('html_text prints the text as given', str_contains($html, 'Safe <b>x</b>'));
check('a visible panel has no hidden attribute', !str_contains($html, ' hidden'));

exit(harness_result());
