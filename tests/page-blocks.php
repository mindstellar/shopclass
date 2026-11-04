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
 * The Page builder's layout blocks: where they may go, the markup each prints (themes style these
 * classes), and how their choice and link fields are cleaned on save.
 *
 * DB-free.  Usage:  php tests/page-blocks.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
if (!function_exists('osc_autop')) {
    function osc_autop($text)
    {
        return (string) $text;
    }
}
if (!function_exists('osc_sanitize_html')) {
    function osc_sanitize_html($value)
    {
        return (new \mindstellar\utility\Sanitize())->richHtml($value);
    }
}
$GLOBALS['kv'] = array();
if (!function_exists('osc_kv_get')) {
    function osc_kv_get(string $group, string $key, $default = null)
    {
        return $GLOBALS['kv'][$group . '/' . $key] ?? $default;
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return 4;
    }
}
if (!function_exists('osc_current_web_theme')) {
    function osc_current_web_theme()
    {
        return 'aisle';
    }
}
if (!function_exists('osc_static_page_id')) {
    function osc_static_page_id()
    {
        return $GLOBALS['pageId'] ?? 0;
    }
}
if (!function_exists('osc_static_page_text')) {
    function osc_static_page_text()
    {
        return $GLOBALS['pageText'] ?? '';
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';

use mindstellar\widgets\WidgetConfig;
use mindstellar\widgets\WidgetRegistry;

$registry = WidgetRegistry::getInstance();
$render   = static function (string $type, array $config): string {
    ob_start();
    (WidgetRegistry::getInstance()->get($type)['render'])($config);

    return (string) ob_get_clean();
};

harness_section('layout blocks go on pages only');
foreach (array('core.media_text', 'core.columns', 'core.heading', 'core.button') as $type) {
    check($type . ' fits a page', $registry->fits($type, 'page.7'));
    check($type . ' does not fit a widget area', !$registry->fits($type, 'header'));
    check($type . ' fits a theme section that lists it', $registry->fits($type, 'home', true));
}
check('content blocks still fit a widget area', $registry->fits('core.rich_text', 'header'));
pin('each layout block has an icon', array('postcard', 'layout-three-columns', 'type-h1', 'capsule'), array_map(
    static fn (string $t): string => $registry->get($t)['icon'],
    array('core.media_text', 'core.columns', 'core.heading', 'core.button')
));

harness_section('markup');
pin('a centred heading with its intro, escaped',
    '<div class="osc-heading osc-heading--center"><h2>Buy &amp; sell</h2><p>Near &lt;you&gt;</p></div>',
    $render('core.heading', array('text' => 'Buy & sell', 'level' => 'h2', 'align' => 'center', 'intro' => 'Near <you>')));
pin('a subheading on the left with no intro', '<div class="osc-heading"><h3>Hi</h3></div>',
    $render('core.heading', array('text' => 'Hi', 'level' => 'h3', 'align' => 'left')));
pin('a heading with no text prints nothing', '', $render('core.heading', array('text' => '  ')));

$media = $render('core.media_text', array('image' => 'https://cdn.test/a.jpg', 'alt' => 'A "stall"', 'side' => 'right', 'width' => 'third',
    'text' => '<p>Hi</p>', 'button_label' => 'Read more', 'button_link' => '/rules'));
check('image and text: right and third', str_starts_with($media, '<div class="osc-media-text osc-media-text--right osc-media-text--third"><figure><img src="https://cdn.test/a.jpg" alt="A &quot;stall&quot;"'), $media);
check('its text and secondary button sit in the text column', str_contains($media, '<div class="osc-rich-text"><p>Hi</p><div class="osc-button-row"><a class="osc-button osc-button--secondary" href="'), $media);
pin('no image: just the text', '<div class="osc-rich-text"><p>Hi</p></div>', $render('core.media_text', array('text' => '<p>Hi</p>')));
pin('no image and no text: nothing', '', $render('core.media_text', array('button_label' => 'x')));

pin('three columns', '<div class="osc-columns osc-columns--3"><div class="osc-rich-text"><p>A</p></div><div class="osc-rich-text"></div><div class="osc-rich-text"><p>C</p></div></div>',
    $render('core.columns', array('count' => '3', 'text_1' => '<p>A</p>', 'text_3' => '<p>C</p>')));
check('two columns leave the third out', !str_contains($render('core.columns', array('count' => '2', 'text_1' => '<p>A</p>', 'text_3' => '<p>C</p>')), 'C'));
check('column text is cleaned', !str_contains($render('core.columns', array('count' => '2', 'text_1' => '<p>A</p><script>x</script>')), 'script'));

$buttons = $render('core.button', array('label' => 'Post', 'link' => 'mailto:a@b.test', 'style' => 'primary', 'align' => 'center', 'label_2' => 'Browse', 'link_2' => 'javascript:alert(1)'));
pin('a button row; a second button with an unsafe link is left out',
    '<div class="osc-button-row osc-button-row--center"><a class="osc-button osc-button--primary" href="mailto:a@b.test">Post</a></div>', $buttons);
pin('a button with no label prints nothing', '', $render('core.button', array('link' => '/x')));

harness_section('saving');
$saved = WidgetConfig::fromPosted($registry->get('core.media_text'), array('side' => 'diagonal', 'width' => 'third'), array('button_link' => 'javascript:alert(1)'));
pin('a choice outside its options falls back to the default', 'left', $saved['side']);
check('a note field stores nothing', !array_key_exists('button_note', $saved));
pin('a choice inside its options is kept', 'third', $saved['width']);
pin('an unsafe link is dropped', '', $saved['button_link']);
pin('the column count keeps its value, not its label', '3', WidgetConfig::fromPosted($registry->get('core.columns'), array('count' => '3'), array())['count']);
foreach (array('mailto:hi@shop.test', '/contact', 'https://shop.test/a?b=1') as $ok) {
    pin('a link is kept: ' . $ok, $ok, WidgetConfig::linkValue($ok));
}
foreach (array('javascript:alert(1)', 'mailto:', 'data:text/html,x', '//evil.test') as $bad) {
    pin('a link is refused: ' . $bad, '', WidgetConfig::linkValue($bad));
}

harness_section('one preview path');
check('the old unsaved-block preview helper is a no-op', osc_page_block_preview_rows('page.1', array(array('pk_i_id' => '1'))) === array(array('pk_i_id' => '1')));
ob_start();
osc_render_widget(array('pk_i_id' => '11', 's_type' => 'core.heading', 's_config' => '{"text":"Saved"}', '_preview' => true));
check('a row flagged _preview renders with no attribute', str_starts_with((string) ob_get_clean(), '<div class="osc-heading">'));

harness_section('page draft preview');
$pageDraft = static fn (array $rows, string $key = 'customiser/page.7:4'): array => array($key => array(
    'theme' => 'page.7', 'locations' => array('page.7' => $rows), 'settings' => array(), 'base' => array(), 'updated_at' => 1,
));
$GLOBALS['kv'] = $pageDraft(array(
    array('id' => 11, 'key' => '', 'type' => 'core.heading', 'description' => 'Intro', 'config' => array('text' => 'Draft title')),
    array('id' => 0, 'key' => 'n1', 'type' => 'core.button', 'description' => '', 'config' => array('label' => 'Go', 'link' => '/go')),
    array('id' => 0, 'key' => 'n2', 'type' => 'no.such.type', 'description' => 'Gone', 'config' => array()),
));
$GLOBALS['osc_customiser_preview'] = null;
pin('outside a preview there are no draft rows', null, osc_customiser_preview_rows('page.7'));
$_GET['osc_blocks'] = '1';
Params::init();
$GLOBALS['osc_customiser_preview'] = array('locations' => array());
$draftRows = osc_customiser_preview_rows('page.7');
pin('in a preview the page draft rows come back, an unknown type left out', array('11', '0'), array_map(static fn ($r) => $r['pk_i_id'], $draftRows ?? array()));
pin('they carry the draft config and order', array('{"text":"Draft title"}', 'core.button', '1'), array($draftRows[0]['s_config'], $draftRows[1]['s_type'], $draftRows[1]['i_order']));
check('draft rows come back unmarked; marking is decided in one place', !isset($draftRows[0]['_block_ref']));
$draftRows = _osc_customiser_mark_page_blocks('page.7', $draftRows ?? array());
pin('a row is marked by its id, or its draft key when unsaved', array('11', 'n1'), array($draftRows[0]['_block_ref'], $draftRows[1]['_block_ref']));
check('a theme location is never marked', !isset(_osc_customiser_mark_page_blocks('home', array(array('pk_i_id' => '3')))[0]['_block_ref']));
ob_start();
osc_render_widget($draftRows[0]);
check('a drafted block carries the marker and its name', str_starts_with((string) ob_get_clean(), '<div data-osc-block="11" data-osc-block-name="Intro" class="osc-heading">'));
ob_start();
osc_render_widget($draftRows[1]);
check('an unnamed block is named after its type', str_contains(substr((string) ob_get_clean(), 0, 90), 'data-osc-block-name="Button"'));
ob_start();
osc_render_widget(array('pk_i_id' => '11', 's_type' => 'core.heading', 's_config' => '{"text":"Live"}'));
check('a block rendered outside the editor has no marker', !str_contains((string) ob_get_clean(), 'data-osc-block'));
pin('the draft of another page is ignored', null, osc_customiser_preview_rows('page.8'));
$GLOBALS['kv'] = $pageDraft(array(array('id' => 11, 'key' => '', 'type' => 'core.heading', 'description' => '', 'config' => array())), 'customiser/page.7:5');
pin('another admin\'s draft is ignored', null, osc_customiser_preview_rows('page.7'));
pin('a theme location is not answered from a page draft', null, osc_customiser_preview_rows('home'));
$live = _osc_customiser_mark_blocks(array(array('pk_i_id' => '12', 's_description' => 'About', 's_type' => 'core.heading', 's_config' => '{"text":"Live"}')));
pin('live rows are marked for the picker too', array('12', 'About'), array($live[0]['_block_ref'], $live[0]['_block_name']));
ob_start();
osc_render_widget(array('pk_i_id' => '12', 's_type' => 'core.heading', 's_config' => '{"text":"x"}', '_block_ref' => 'a"b', '_block_name' => '<i>'));
check('the marker is escaped', str_starts_with((string) ob_get_clean(), '<div data-osc-block="a&quot;b" data-osc-block-name="&lt;i&gt;"'));
$GLOBALS['kv'] = array();
$GLOBALS['osc_customiser_preview'] = null;

harness_section('frame script');
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/customiser-preview-js.test.mjs') . ' 2>&1', $nodeOut, $nodeCode);
    pin('customiser-preview.js passes its stub-DOM tests', 0, $nodeCode);
    if ($nodeCode !== 0) {
        fwrite(STDERR, implode("\n", $nodeOut) . "\n");
    }
}

harness_section('page text');

$GLOBALS['pageId'] = 0;
pin('outside a page the Page text block prints nothing', '', $render('core.page_text', array()));
$GLOBALS['pageId']   = 7;
$GLOBALS['pageText'] = '<p>Our story</p>';
pin('on a page it prints the page text', '<div class="osc-rich-text osc-page-text"><p>Our story</p></div>', $render('core.page_text', array()));
$GLOBALS['pageText'] = '<p> </p>';
pin('an empty page text prints nothing', '', $render('core.page_text', array()));
pin('it is a builder-only block', true, !empty($registry->get('core.page_text')['builder']));

harness_section('text block width and position');

pin('a rich text block is reading width by default', '<div class="osc-rich-text"><p>Hi</p></div>', $render('core.rich_text', array('text' => '<p>Hi</p>')));
pin('...centred when asked', '<div class="osc-rich-text osc-rich-text--center"><p>Hi</p></div>', $render('core.rich_text', array('text' => '<p>Hi</p>', 'place' => 'center')));
pin('...and full width ignores the position', '<div class="osc-rich-text osc-rich-text--wide"><p>Hi</p></div>', $render('core.rich_text', array('text' => '<p>Hi</p>', 'width' => 'full', 'place' => 'center')));
$GLOBALS['pageId']   = 7;
$GLOBALS['pageText'] = '<p>Our story</p>';
pin('the Page text block takes the same choices', '<div class="osc-rich-text osc-rich-text--wide osc-page-text"><p>Our story</p></div>', $render('core.page_text', array('width' => 'full')));

exit(harness_result());
