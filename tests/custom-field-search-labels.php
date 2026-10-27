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
 * On the search form a custom field's heading names its control: the control (or the radio
 * group) points at the heading with aria-labelledby.
 *
 * Usage: php tests/custom-field-search-labels.php
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

$field = static function (int $id, string $slug, string $type): array {
    return array(
        'pk_i_id'      => $id,
        's_slug'       => $slug,
        's_name'       => ucfirst($slug),
        'e_type'       => $type,
        's_value'      => '',
        's_options'    => 'red,green',
        'fk_i_item_id' => 0,
        'b_required'   => 0,
    );
};

$render = static function (array $f): string {
    ob_start();
    FieldForm::meta($f, true);

    return (string) ob_get_clean();
};

foreach (array('TEXT', 'DROPDOWN', 'TEXTAREA', 'RADIO') as $i => $type) {
    $html = $render($field(70 + $i, strtolower($type), $type));
    $ok   = preg_match('/aria-labelledby="([^"]+)"/', $html, $m) === 1
        && preg_match('/<h6[^>]*\bid="' . preg_quote($m[1], '/') . '"/', $html) === 1;
    check($type . ' search control is labelled by its heading', $ok, $html);
}

exit(harness_result());
