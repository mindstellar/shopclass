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
 * Pins that ItemForm::category_multiple_selects() keeps the request's catId inside its JS string.
 *
 * The value was printed raw between single quotes in a <script>, and HTMLPurifier keeps quotes,
 * so `?catId=1';alert(1);//` ran script on the post page. It is now a JSON string literal, and
 * the category branch ids passed to draw_select() are integers.
 *
 * DB-free.  Usage: php tests/item-form-category-id-xss.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

class Session
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function _getForm($key = '')
    {
        return '';
    }
}

/** A branch Root(3) > Child(7) > Leaf(9), whatever id is asked for. */
class Category
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function toRootTree($cat = null)
    {
        if ($cat == null) {
            return array();
        }

        return array(
            array('pk_i_id' => '3', 'fk_i_parent_id' => null),
            array('pk_i_id' => '7', 'fk_i_parent_id' => '3'),
            array('pk_i_id' => '9', 'fk_i_parent_id' => '7'),
        );
    }
}

function osc_item_category_id()
{
    return null;
}

function osc_item()
{
    return array();
}

$categories = array(
    array('pk_i_id' => 3, 'fk_i_parent_id' => null, 's_name' => 'Root'),
    array('pk_i_id' => 7, 'fk_i_parent_id' => 3, 's_name' => 'Child'),
    array('pk_i_id' => 9, 'fk_i_parent_id' => 7, 's_name' => 'Leaf'),
);

$render = static function ($catId) use ($categories): string {
    $_GET     = array('catId' => $catId);
    $_POST    = array();
    $_REQUEST = $_GET;
    Params::init();
    ob_start();
    ItemForm::category_multiple_selects($categories, array());

    return (string) ob_get_clean();
};

/** The JS string the page assigns to category_id, decoded, or null when it is not one literal. */
$categoryId = static function (string $html) {
    if (!preg_match('/osc\.item_post\.category_id = ("(?:[^"\\\\]|\\\\.)*");\s*$/m', $html, $m)) {
        return null;
    }

    return json_decode($m[1]);
};

harness_section('a crafted catId stays inside the JS string');
$payload = "1';alert(document.domain);//";
$html    = $render($payload);
pin('category_id is one string literal holding the request value', $payload, $categoryId($html));
check('no single quote is printed in the script', strpos($html, "alert(document.domain);//'") === false
    && strpos($html, "= '1'") === false, $html);

$payload = '1"+alert(1)+"';
pin('a double quote cannot close it either', $payload, $categoryId($render($payload)));

harness_section('a valid id behaves as before');
$html = $render('9');
pin('category_id is the id as a string', '9', $categoryId($html));
check('the hidden catId input keeps the id', (bool) preg_match('/<input[^>]*name="catId"[^>]*value="9"/', $html), $html);
check(
    'the branch above the category is drawn with integer ids',
    strpos($html, 'draw_select(2, 3);') !== false && strpos($html, 'draw_select(3, 7);') !== false,
    $html
);
check('the category itself gets no extra select', strpos($html, 'draw_select(4,') === false, $html);

harness_section('an array catId');
$error = '';
try {
    $html = $render(array('1'));
} catch (Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
pin('catId[]=1 is no error', '', $error);
pin('and counts as no category', '', $categoryId($html));

exit(harness_result());
