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
 * osc_show_flash_message() enqueues core's dismiss script when it prints a dismiss button,
 * and only then. The markup of the button is unchanged.
 *
 * DB-free. Usage:  php tests/flash-dismiss.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

class Preference
{
    public static function getInstance(): self
    {
        return new self();
    }
    public function get($key)
    {
        return false;
    }
}
function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}
function osc_assets_url($file)
{
    return 'https://example.test/oc-includes/assets/' . $file;
}
function osc_asset_url_versioned($url)
{
    return $url;
}
function osc_register_script($id, $url, $dependencies = null)
{
    Scripts::getInstance()->registerScript($id, $url, $dependencies);
}

class Session
{
    public static array $messages = array();
    public static function getInstance(): self
    {
        return new self();
    }
    public function _getMessage($section)
    {
        return self::$messages;
    }
    public function _dropMessage($section): void
    {
    }
}

$GLOBALS['enqueued'] = array();
function osc_enqueue_script($id)
{
    $GLOBALS['enqueued'][] = $id;
}
function osc_apply_filter($name, $value)
{
    return $value;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function __($s)
{
    return $s;
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hMessages.php';

$show = static function (array $messages): string {
    $GLOBALS['enqueued'] = array();
    Session::$messages   = $messages;
    ob_start();
    osc_show_flash_message();

    return (string) ob_get_clean();
};

harness_section('a message with a dismiss button');

$html = $show(array(array('msg' => 'Saved', 'type' => 'ok')));
pin('enqueues the dismiss script', array('osc-flash-dismiss'), array_values(array_unique($GLOBALS['enqueued'])));
check('the button markup is unchanged', str_contains($html, '<a class="btn ico btn-mini ico-close" data-oc-close-label="Dismiss">x</a>'));

harness_section('core registers the script');

Scripts::init();
pin('osc-flash-dismiss is registered after Scripts::init()', 'https://example.test/oc-includes/assets/osclass/flash-dismiss.js', Scripts::getInstance()->registered['osc-flash-dismiss']['url'] ?? null);
check('the script file ships with core', is_file(ABS_PATH . 'oc-includes/assets/osclass/flash-dismiss.js'));

harness_section('no dismiss button');

$show(array('Plain string message'));
pin('a plain string message enqueues nothing', array(), $GLOBALS['enqueued']);
$show(array());
pin('no messages enqueue nothing', array(), $GLOBALS['enqueued']);

exit(harness_result());

/* file end: ./tests/flash-dismiss.php */
