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
 * The markup helpers in gui/page-fn.php: the classes each prints, and that the text it is
 * given comes out escaped.
 *
 * Usage: php tests/gui-markup-helpers.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

require ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';

$render = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

harness_section('osc_gui_field()');
$row = $render(static fn () => osc_gui_field('x-mail', 'Mail <b>', '<input id="x-mail">', 'Hint & more', 'Bad "mail"'));
check('the row is an .oe-field', str_starts_with($row, '<div class="oe-field">'));
check('the label points at the input, escaped', str_contains($row, '<label class="oe-label" for="x-mail">Mail &lt;b&gt;</label>'));
check('the input is printed as given', str_contains($row, "\n<input id=\"x-mail\">\n"));
check('the hint has the -hint id, escaped', str_contains($row, '<span class="oe-hint" id="x-mail-hint">Hint &amp; more</span>'));
check('the error has the -err id, escaped', str_contains($row, '<span class="oe-hint oe-field-error" id="x-mail-err">Bad &quot;mail&quot;</span>'));
$bare = $render(static fn () => osc_gui_field('y', 'Y', static function (): void {
    echo '<select id="y"></select>';
}, '', '', 'oe-wide'));
check('a closure prints the input', str_contains($bare, '<select id="y"></select>'));
check('extra classes go on the row', str_starts_with($bare, '<div class="oe-field oe-wide">'));
check('no hint or error when none is given', !str_contains($bare, 'oe-hint'));

harness_section('osc_gui_panel_open() and osc_gui_panel_close()');
$panel = $render(static function (): void {
    osc_gui_panel_open('Order <1>', 'oe-bill-card', 'p1', 'div');
    osc_gui_panel_open();
    osc_gui_panel_close();
    osc_gui_panel_close('div');
});
check('the panel carries the id and its classes', str_starts_with($panel, '<div id="p1" class="oe-panel oe-bill-card">'));
check('the title is an escaped .oe-panel__title', str_contains($panel, '<h2 class="oe-panel__title">Order &lt;1&gt;</h2>'));
check('an untitled panel is a section', str_contains($panel, '<section class="oe-panel">'));
check('each close prints the tag it is given', str_ends_with($panel, '</section></div>'));
pin('a close with no tag is a section', '</section>', $render(static fn () => osc_gui_panel_close()));
pin('a close with a tag off the list is a section', '</section>', $render(static fn () => osc_gui_panel_close('script')));
check('no global keeps the tags', !array_key_exists('osc_gui_panel_tags', $GLOBALS));

harness_section('osc_gui_empty()');
pin('the empty line', '<p class="oe-empty oe-bill-empty">None &amp; nothing</p>', $render(static fn () => osc_gui_empty('None & nothing', 'oe-bill-empty')));

harness_section('osc_gui_actions()');
$row = $render(static fn () => osc_gui_actions(array(
    array('label' => 'Save'),
    array('label' => 'Back', 'href' => '/a?b=1&c=2', 'style' => 'link'),
    array('label' => 'Drop', 'style' => 'danger', 'attrs' => array('name' => 'discard', 'value' => '1')),
    array('label' => 'More', 'href' => '#m', 'style' => 'secondary', 'class' => 'oe-more'),
), 'oe-extra'));
check('the row is an .oe-actions', str_starts_with($row, '<div class="oe-actions oe-extra">'));
check('a primary submit button', str_contains($row, '<button class="oe-btn oe-btn--primary" type="submit">Save</button>'));
check('a plain link, href escaped', str_contains($row, '<a href="/a?b=1&amp;c=2">Back</a>'));
check('a danger button with its attributes', str_contains($row, '<button class="oe-btn oe-btn-danger" type="submit" name="discard" value="1">Drop</button>'));
check('a secondary link with an extra class', str_contains($row, '<a class="oe-btn oe-secondary oe-more" href="#m">More</a>'));

exit(harness_result());
