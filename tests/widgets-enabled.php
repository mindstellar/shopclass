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
 * The reserved `_enabled` config key hides a typed widget without deleting it, and
 * WidgetConfig::fromPosted() keeps the widgets screen's per-field sanitising: unknown keys
 * dropped, a select outside its options falls to the default, a non-http(s) image is
 * dropped, raw code only on a super_admin type.
 *
 * DB-free.  Usage:  php tests/widgets-enabled.php
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
require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';

use mindstellar\widgets\WidgetConfig;

osc_register_widget('test.echo', array(
    'label'  => 'Echo',
    'fields' => array(array('name' => 'text', 'label' => 'Text', 'type' => 'text')),
    'render' => static function ($config) {
        echo '[' . ($config['text'] ?? '') . ']';
    },
));

$render = static function (string $config): string {
    ob_start();
    osc_render_widget(array('s_type' => 'test.echo', 's_config' => $config, 's_content' => ''));

    return (string) ob_get_clean();
};

harness_section('_enabled');

pin('a row with no _enabled renders', '[hi]', $render('{"text":"hi"}'));
pin('_enabled true renders', '[hi]', $render('{"text":"hi","_enabled":true}'));
pin('_enabled false renders nothing', '', $render('{"text":"hi","_enabled":false}'));
pin('only an explicit false hides: 0 still renders', '[hi]', $render('{"text":"hi","_enabled":0}'));
ob_start();
osc_render_widget(array('s_type' => '', 's_content' => '<p>legacy</p>'));
pin('a legacy row is untouched', '<p>legacy</p>', (string) ob_get_clean());
check('WidgetConfig::enabled agrees', WidgetConfig::enabled(array()) && !WidgetConfig::enabled(array('_enabled' => false)));

harness_section('WidgetConfig::fromPosted');

$type = array(
    'capability' => 'admin',
    'fields'     => array(
        array('name' => 'title', 'type' => 'text', 'default' => 'Hello'),
        array('name' => 'count', 'type' => 'number', 'default' => 7),
        array('name' => 'show', 'type' => 'checkbox'),
        array('name' => 'layout', 'type' => 'select', 'default' => 'grid', 'options' => array('grid' => 'Grid', 'rail' => 'Rail')),
        array('name' => 'source', 'type' => 'select', 'default' => 'a', 'options' => static fn () => array('a', 'b')),
        array('name' => 'image', 'type' => 'image'),
        array('name' => 'code', 'type' => 'code'),
    ),
);
$posted = array(
    'title'  => 'Big sale',
    'count'  => '9',
    'show'   => '1',
    'layout' => 'evil',
    'source' => 'b',
    'image'  => 'ignored, the raw value is read',
    'code'   => 'purified text',
    'extra'  => 'dropped',
);
$raw = array('image' => 'javascript:alert(1)', 'code' => '<script>x</script>');
$config = WidgetConfig::fromPosted($type, $posted, $raw);

pin('declared keys only, in declared order', array('title', 'count', 'show', 'layout', 'source', 'image', 'code'), array_keys($config));
pin('text kept', 'Big sale', $config['title']);
pin('number cast', 9, $config['count']);
pin('checkbox on', 1, $config['show']);
pin('a select outside its options falls to the default', 'grid', $config['layout']);
pin('a callable select resolves its options', 'b', $config['source']);
pin('a javascript: image is dropped', '', $config['image']);
pin('code on an admin type stays purified text', 'purified text', $config['code']);

$config = WidgetConfig::fromPosted(array('capability' => 'super_admin') + $type, $posted, $raw + array('image' => 'https://cdn.test/a.jpg?w=1'));
pin('raw code only on a super_admin type', '<script>x</script>', $config['code']);

$config = WidgetConfig::fromPosted($type, array(), array('image' => 'https://cdn.test/a.jpg?w=1'));
pin('an http(s) image keeps its query string', 'https://cdn.test/a.jpg?w=1', $config['image']);
pin('missing values take their defaults', array('Hello', 7, 0, 'grid'), array($config['title'], $config['count'], $config['show'], $config['layout']));

harness_section('site paths');

if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://shop.test:8090/sub/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH;
    }
}

foreach (array('/oc-content/uploads/a.jpg?x=1', '/') as $path) {
    pin('a site path is kept: ' . $path, $path, WidgetConfig::imageValue($path));
}
foreach (array('//evil.test/a.jpg', '/\\evil.test', '/a\\b', "/a\tb", "/a\x7fb", '/a b', 'a.jpg', 'javascript:alert(1)') as $bad) {
    pin('refused: ' . json_encode($bad), '', WidgetConfig::imageValue($bad));
}
pin('an old absolute address on another host is kept', 'https://cdn.test/a.jpg', WidgetConfig::imageValue('https://cdn.test/a.jpg'));
{
    $base = (string) osc_base_url();
    pin('an own-site address becomes a path', parse_url($base, PHP_URL_PATH) . 'oc-content/a.jpg', WidgetConfig::imageValue($base . 'oc-content/a.jpg'));
    pin('a path resolves back', $base . 'oc-content/a.jpg', WidgetConfig::toAbsolute(parse_url($base, PHP_URL_PATH) . 'oc-content/a.jpg'));
}
pin('toAbsolute leaves a full URL alone', 'https://cdn.test/a.jpg', WidgetConfig::toAbsolute('https://cdn.test/a.jpg'));

exit(harness_result());
