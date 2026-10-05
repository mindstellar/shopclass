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
 * Category and custom field names are escaped where the listing and search forms print
 * them, and a name stored HTML-encoded (as Params stores "&") prints as it did, not
 * encoded twice.
 *
 * DB-free.  Usage:  php tests/form-name-escape.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return '';
    }
}

class Session
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function _getForm($key)
    {
        return '';
    }
}

function osc_get_admin_locales()
{
    return [];
}

function osc_current_admin_locale()
{
    return 'en_US';
}

function osc_get_locales()
{
    return [];
}

function osc_current_user_locale()
{
    return 'en_US';
}

$GLOBALS['selectableParents'] = false;
function osc_selectable_parent_categories()
{
    return $GLOBALS['selectableParents'];
}

$evil = '<img src=x onerror=alert(1)>';
$tree = [[
    'pk_i_id' => 1, 'fk_i_parent_id' => null, 's_name' => 'Cars &amp; Vans ' . $evil,
    'categories' => [['pk_i_id' => 2, 'fk_i_parent_id' => 1, 's_name' => 'Small ' . $evil, 'categories' => []]],
]];
$capture = static function (callable $fn): string {
    ob_start();
    $fn();

    return (string) ob_get_clean();
};

harness_section('the listing form\'s category select');
// One category on its own is always selectable, so the optgroup needs a second.
$two = array_merge($tree, [['pk_i_id' => 4, 'fk_i_parent_id' => null, 's_name' => 'Boats', 'categories' => []]]);
$out = $capture(static fn () => ItemForm::category_select($two, ['fk_i_category_id' => 2], 'Pick'));
check('no tag gets through', !str_contains($out, '<img'));
check('the optgroup label is escaped, and a stored & is not encoded twice', str_contains($out, '<optgroup label="Cars &amp; Vans &lt;img src=x onerror=alert(1)&gt;">'));
check('a subcategory option is escaped', str_contains($out, '>&nbsp;&nbsp;Small &lt;img src=x onerror=alert(1)&gt;</option>'));
$GLOBALS['selectableParents'] = true;
$out = $capture(static fn () => ItemForm::category_select($tree, ['fk_i_category_id' => 0], 'Pick'));
check('a selectable parent\'s option is escaped', str_contains($out, '>Cars &amp; Vans &lt;img src=x onerror=alert(1)&gt;</option>'));
$plain = $capture(static fn () => ItemForm::category_select([['pk_i_id' => 3, 's_name' => 'Boats', 'categories' => []]], ['fk_i_category_id' => 0], 'Pick'));
check('a plain name prints as before', str_contains($plain, '<option value="3">Boats</option>'));

harness_section('the category form');
$out = $capture(static fn () => CategoryForm::subcategory_select($tree[0]['categories'], ['pk_i_id' => 2], null, 1));
check('a selected subcategory option is escaped', !str_contains($out, '<img') && str_contains($out, 'Small &lt;img'));
$out = $capture(static fn () => CategoryForm::subcategory_select($tree[0]['categories'], null, null, 1));
check('an unselected one too', !str_contains($out, '<img') && str_contains($out, 'Small &lt;img'));
$out = $capture(static fn () => CategoryForm::categories_tree($tree, [2]));
check('the category tree\'s labels are escaped, & kept as it was', !str_contains($out, '<img') && str_contains($out, '<span>Cars &amp; Vans &lt;img'));

harness_section('a custom field on the search form');
$field = ['pk_i_id' => 7, 's_slug' => 'colour', 's_name' => 'Colour &amp; trim ' . $evil, 'e_type' => 'TEXT', 's_value' => 'red'];
$out   = $capture(static fn () => FieldForm::meta($field, true));
check('the heading is escaped, & kept as it was', !str_contains($out, '<img') && str_contains($out, '<h6>Colour &amp; trim &lt;img src=x onerror=alert(1)&gt;</h6>'));
$out = $capture(static fn () => FieldForm::meta(['s_name' => 'Size'] + $field, true));
check('a plain name prints as before', str_contains($out, '<h6>Size</h6>'));

exit(harness_result());
