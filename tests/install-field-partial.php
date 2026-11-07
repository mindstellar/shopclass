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
check('no help line without help text', !str_contains($html, 'ins-help'));

harness_section('Escaping and select');
$html = $out(static fn () => install_field(array('id' => 'webtitle', 'label' => 'Name', 'value' => '"><script>')));
check('value is escaped', !str_contains($html, '<script>') && str_contains($html, '&quot;&gt;&lt;script&gt;'));
$html = $out(static fn () => install_field(array('id' => 'country', 'type' => 'select', 'label' => 'Country', 'value' => 'b', 'opt' => '(for locations)', 'options' => array('a' => 'A', 'b' => 'B'))));
check('chosen option is selected', str_contains($html, '<option value="b" selected>') && !str_contains($html, '<option value="a" selected>'));
check('opt suffix is shown', str_contains($html, '<span class="ins-opt">(for locations)</span>'));

exit(harness_result());
