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
 * Category::hierarchy() builds each branch once per request, since every listing link on a page
 * asks for its category path. Each Category object builds its own, in its language.
 *
 * Usage:  php tests/models/category-path.php
 *         php tests/run-models.php category-path
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\cache\CacheGroup;
use mindstellar\routing\CoreRoutes;

$admin = scratchdb_session('osc_models_category_path');
foreach (array('OSC_CACHE_TTL' => 60, 'WEB_PATH' => 'http://localhost/', 'OC_ADMIN' => false) as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
$parent = seed_category($admin, 'Vehicles', null, $locale);
$child  = seed_category($admin, 'Cars', $parent, $locale);
$spanish = seed_locale($admin, 'es_ES', 'Spanish');
foreach (array($parent => 'vehiculos', $child => 'coches') as $id => $slug) {
    seed_exec($admin, "INSERT INTO {$p}t_category_description (fk_i_category_id, fk_c_locale_code, s_name, s_description, s_slug) VALUES (?, ?, ?, '', ?)", 'isss', array($id, $spanish, ucfirst($slug), $slug));
}

\mindstellar\cache\CacheManager::getInstance()->flush();
CacheGroup::forgetGenerations();
$reset = new ReflectionProperty('Category', 'instance');
if (PHP_VERSION_ID < 80100) {
    $reset->setAccessible(true);
}
$reset->setValue(null, null);

pin('a child category\'s path names its parent first', 'vehicles/cars', CoreRoutes::categoryPath($child));
pin('the second link gets the same path', 'vehicles/cars', CoreRoutes::categoryPath($child));

$admin->query("UPDATE {$p}t_category_description SET s_slug = 'motors' WHERE fk_i_category_id = $parent AND fk_c_locale_code = '$locale'");
CacheGroup::invalidate('category');
$reset->setValue(null, null);
pin('after a category edit the next link has the new slug', 'motors/cars', CoreRoutes::categoryPath($child));

$reset->setValue(null, null);
Category::getInstance('es_ES');
pin('a Category in another language builds its own path', 'vehiculos/coches', CoreRoutes::categoryPath($child));
$reset->setValue(null, null);
Category::getInstance('en_US');
pin('and switching back needs no cache drop', 'motors/cars', CoreRoutes::categoryPath($child));

harness_section('CategoryModes::modeFor(), behind osc_category_mode()');
$grandchild = seed_category($admin, 'Electric', $child, $locale);
pin('a category takes its parent\'s mode', 'nights', \mindstellar\category\CategoryModes::modeFor('{"' . $parent . '":"nights"}', $grandchild));
pin('the nearest mode decides', 'slots', \mindstellar\category\CategoryModes::modeFor(array($parent => 'nights', $child => 'slots'), $grandchild));
pin('a subcategory switched off is off', '', \mindstellar\category\CategoryModes::modeFor(array($parent => 'nights', $child => 'off'), $grandchild));
pin('nothing set is off', '', \mindstellar\category\CategoryModes::modeFor('{}', $child));
pin('a category with its own mode uses it', 'slots', \mindstellar\category\CategoryModes::modeFor(array($parent => 'nights', $grandchild => 'slots'), $grandchild));

harness_section('category card pickers');
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Picker.php';
$admin->query("UPDATE {$p}t_category_description SET s_name = 'Cars <b>' WHERE fk_i_category_id = $child");
$tree = array(array('pk_i_id' => $parent, 's_name' => 'Vehicles', 'categories' => array(
    array('pk_i_id' => $child, 's_name' => 'Cars <b>', 'categories' => array(
        array('pk_i_id' => $grandchild, 's_name' => 'Electric', 'categories' => array()),
    )),
)));
ob_start();
\mindstellar\admin\ui\Picker::categoryChecks(array('id' => 'cat_tree', 'categories' => $tree, 'selected' => array($child)));
$html = (string)ob_get_clean();
check('the tree keeps its root id', strpos($html, '<ul id="cat_tree"') !== false);
check('each box posts categories[]', substr_count($html, 'name="categories[]"') === 3);
check('a branch keeps its ul#cat<id> for checkCat()', strpos($html, '<ul id="cat' . $parent . '">') !== false && strpos($html, 'checkCat(\'' . $parent . '\'') !== false);
check('a selected id is checked', (bool)preg_match('/value="' . $child . '"[^>]*checked/', $html));
check('Check all and Uncheck all target the root', substr_count($html, 'data-tree-toggle="cat_tree"') === 2);
check('a category name is escaped', strpos($html, 'Cars &lt;b&gt;') !== false && strpos($html, 'Cars <b>') === false);
ob_start();
\mindstellar\admin\ui\Picker::categoryModes(array('id' => 'cats', 'name' => 'cats', 'modes' => array('nights' => 'Nights'), 'categories' => $tree, 'value' => '{"' . $child . '":"off"}'));
$html = (string)ob_get_clean();
preg_match_all('/name="cats\[(\d+)\]"/', $html, $posted);
pin('every category posts under the field name', array($parent, $child, $grandchild), array_map('intval', array_values(array_unique($posted[1]))));
check('a main category off checks the empty value', (bool)preg_match('/name="cats\[' . $parent . '\]" value="" checked/', $html));
check('a saved subcategory mode is checked', (bool)preg_match('/name="cats\[' . $child . '\]" value="off" checked/', $html));
check('a subcategory row is a labelled radio group', strpos($html, 'role="radiogroup" data-depth="1" aria-labelledby="cats-cat-' . $grandchild . '"') !== false);
check('a card with a subcategory set apart starts open', strpos($html, '<li class="osc-catcard" data-osc-tree-open>') !== false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
