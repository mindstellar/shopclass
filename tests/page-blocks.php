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
pin('a choice inside its options is kept', 'third', $saved['width']);
pin('an unsafe link is dropped', '', $saved['button_link']);
pin('the column count keeps its value, not its label', '3', WidgetConfig::fromPosted($registry->get('core.columns'), array('count' => '3'), array())['count']);
foreach (array('mailto:hi@shop.test', '/contact', 'https://shop.test/a?b=1') as $ok) {
    pin('a link is kept: ' . $ok, $ok, WidgetConfig::linkValue($ok));
}
foreach (array('javascript:alert(1)', 'mailto:', 'data:text/html,x', '//evil.test') as $bad) {
    pin('a link is refused: ' . $bad, '', WidgetConfig::linkValue($bad));
}

exit(harness_result());
