<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The category mode picker draws a subcategory with no saved mode as "same as parent",
 * without a warning.
 *
 * DB-free. Usage: php tests/picker-category-modes.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

if (!function_exists('__')) {
    function __($text, $domain = 'core')
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($one, $many, $n, $domain = 'core')
    {
        return $n === 1 ? $one : $many;
    }
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

$warnings = array();
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$tree = array(
    array('pk_i_id' => 1, 's_name' => 'Shop', 'categories' => array(
        array('pk_i_id' => 2, 's_name' => 'Shoes', 'categories' => array()),
        array('pk_i_id' => 3, 's_name' => 'Hats', 'categories' => array()),
    )),
);

ob_start();
\mindstellar\admin\ui\Picker::categoryModes(array(
    'id'         => 'cm',
    'name'       => 'modes',
    'modes'      => array('shop' => 'Shop', 'classifieds' => 'Classifieds'),
    'value'      => array(1 => 'shop', 3 => 'classifieds'),
    'categories' => $tree,
));
$html = (string)ob_get_clean();
restore_error_handler();

pin('no warning for a subcategory with no saved mode', array(), $warnings);
check('the unsaved subcategory follows its parent', (bool)preg_match('/name="modes\[2\]" value="" checked/', $html));
check('the saved subcategory keeps its mode', (bool)preg_match('/name="modes\[3\]" value="classifieds" checked/', $html));

exit(harness_result());
