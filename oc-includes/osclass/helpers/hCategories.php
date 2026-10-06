<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Categories
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets current category
 *
 * @return array<string,mixed>|null
 */
function osc_category()
{
    $category = null;
    if (View::getInstance()->_exists('subcategories')) {
        $category = View::getInstance()->_current('subcategories');
    } elseif (View::getInstance()->_exists('categories')) {
        $category = View::getInstance()->_current('categories');
    } elseif (View::getInstance()->_exists('category')) {
        $category = View::getInstance()->_get('category');
    }

    return $category;
}

/**
 * Low level function: Gets the list of categories as a tree
 *
 * <code>
 * <?php
 *  $c = osc_get_categories();
 * ?>
 * </code>
 *
 * @return array<int,array<string,mixed>>
 */
function osc_get_categories()
{
    if (!View::getInstance()->_exists('categories')) {
        osc_export_categories(Category::getInstance()->toTree());
    }

    return View::getInstance()->_get('categories');
}

/**
 * Low level function: Gets the value of the category attribute
 *
 * @param string $field
 * @param string $locale Unused; the current category is read without a locale
 *
 * @return mixed Empty string when the field is not set
 */
function osc_category_field($field, $locale = '')
{
    return osc_field(osc_category(), $field, '');
}

/**
 * Gets the number of categories
 *
 * @return int
 */
function osc_priv_count_categories()
{
    return View::getInstance()->_count('categories');
}

/**
 * Gets the number of subcategories
 *
 * @return int
 */
function osc_priv_count_subcategories()
{
    return View::getInstance()->_count('subcategories');
}

/**
 * Gets the total of categories. If categories are not loaded, this function will load them.
 *
 * @return int
 */
function osc_count_categories()
{
    if (!View::getInstance()->_exists('categories')) {
        View::getInstance()->_exportVariableToView('categories', Category::getInstance()->toTree());
    }

    return osc_priv_count_categories();
}

/**
 * Let you know if there are more categories in the list. If categories are not loaded, this function will load them.
 *
 * @return bool
 */
function osc_has_categories()
{
    if (!View::getInstance()->_exists('categories')) {
        View::getInstance()->_exportVariableToView('categories', Category::getInstance()->toTree());
    }

    return View::getInstance()->_next('categories');
}

/**
 * Gets the total of subcategories for the current category. If subcategories are not loaded, this function will load
 * them and it will prepare the the pointer to the first element
 *
 * @return int -1 when there is no current category
 */
function osc_count_subcategories()
{
    $category = View::getInstance()->_current('categories');
    if ($category == '') {
        return -1;
    }
    if (!isset($category['categories'])) {
        return 0;
    }
    if (!is_array($category['categories'])) {
        return 0;
    }
    if (count($category['categories']) == 0) {
        return 0;
    }
    if (!View::getInstance()->_exists('subcategories')) {
        View::getInstance()->_exportVariableToView('subcategories', $category['categories']);
    }

    return osc_priv_count_subcategories();
}

/**
 * Let you know if there are more subcategories for the current category in the list. If subcategories are not loaded,
 * this function will load them and it will prepare the pointer to the first element
 *
 * @return bool|int -1 when there is no current category
 */
function osc_has_subcategories()
{
    $category = View::getInstance()->_current('categories');
    if ($category == '') {
        return -1;
    }
    if (!isset($category['categories'])) {
        return false;
    }

    if (!View::getInstance()->_exists('subcategories')) {
        View::getInstance()->_exportVariableToView('subcategories', $category['categories']);
    }
    $ret = View::getInstance()->_next('subcategories');
    //we have to delete for next iteration
    if (!$ret) {
        View::getInstance()->_erase('subcategories');
    }

    return $ret;
}

/**
 * Gets the name of the current category
 *
 * @param string $locale
 *
 * @return string
 */
function osc_category_name($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_category_field('s_name', $locale);
}

/**
 * Gets the description of the current category
 *
 * @param string $locale
 *
 * @return string
 */
function osc_category_description($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_category_field('s_description', $locale);
}

/**
 * Gets the id of the current category
 *
 * @param string $locale
 *
 * @return string
 */
function osc_category_id($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_category_field('pk_i_id', $locale);
}

/**
 * Gets the slug of the current category. WARNING: This slug could NOT be used as a valid W3C HTML tag attribute as it
 * could have other characters besides [A-Za-z0-9-_] We only did a urlencode to the variable
 *
 * @param string $locale
 *
 * @return string
 */
function osc_category_slug($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_category_field('s_slug', $locale);
}

/**
 * Returns if the category has the prices enabled or not
 *
 * @return bool
 */
function osc_category_price_enabled()
{
    return (bool)osc_category_field('b_price_enabled');
}

/**
 * Returns category's parent id
 *
 * @return string Empty string for a top-level category
 */
function osc_category_parent_id()
{
    return osc_category_field('fk_i_parent_id');
}

/**
 * Gets the total items related with the current category
 *
 * @return string
 */
function osc_category_total_items()
{
    return osc_category_field('i_num_items');
    //$category = osc_category();
    //return CategoryStats::getInstance()->getNumItems($category);
}

/**
 * Reset the pointer of the array to the first category
 *
 * @return void
 */
function osc_goto_first_category()
{
    View::getInstance()->_reset('categories');
}

/**
 * Gets list of non-empty categories
 *
 * @return array<int,array<string,mixed>>
 */
function osc_get_non_empty_categories()
{
    $aCategories = Category::getInstance()->toTree(false);
    View::getInstance()->_exportVariableToView('categories', $aCategories);

    return View::getInstance()->_get('categories');
}

/**
 * Prints category select
 *
 * @param string          $name
 * @param int|string|null $category    Pre-selected category id
 * @param string|null     $default_str
 *
 * @return void
 */
function osc_categories_select($name = 'sCategory', $category = null, $default_str = null)
{
    if ($default_str == null) {
        $default_str = __('Select a category');
    }
    CategoryForm::category_select(Category::getInstance()->toTree(), $category, $default_str, $name);
}

/**
 * Get th category by id or slug
 *
 * @param string     $by   'slug' or 'id'
 * @param int|string $what
 *
 * @return array<string,mixed>|false
 * @since 3.0
 */
function osc_get_category($by, $what)
{
    if (!in_array($by, array('slug', 'id'))) {
        return false;
    }

    switch ($by) {
        case 'slug':
            return Category::getInstance()->findBySlug($what);
            break;
        case 'id':
            return Category::getInstance()->findByPrimaryKey($what);
            break;
    }
}

/**
 * Descend the category pointer into the current category's children.
 *
 * @return int|false|null -1 with no current category, false when it has no children
 */
function osc_category_move_to_children()
{
    $category = View::getInstance()->_current('categories');
    if ($category == '') {
        return -1;
    }
    if (!isset($category['categories'])) {
        return false;
    }

    if (View::getInstance()->_exists('categoryTrail')) {
        $catTrail = View::getInstance()->_get('categoryTrail');
    } else {
        $catTrail = array();
    }
    $catTrail[] = View::getInstance()->_key('categories');
    View::getInstance()->_exportVariableToView('categoryTrail', $catTrail);
    View::getInstance()->_exportVariableToView('categories', $category['categories']);
    View::getInstance()->_reset('categories');
}

/**
 * Move the category pointer back up to the current category's parent.
 *
 * @return int|false|null -1 with no current category, false when it has no parent
 */
function osc_category_move_to_parent()
{
    $category = View::getInstance()->_get('categories');
    $category = end($category);

    if ($category == '') {
        return -1;
    }
    if (!isset($category['fk_i_parent_id'])) {
        return false;
    }

    $keys     = View::getInstance()->_get('categoryTrail');
    $position = array_pop($keys);
    View::getInstance()->_exportVariableToView('categoryTrail', $keys);
    if (!View::getInstance()->_exists('categories_tree')) {
        View::getInstance()->_exportVariableToView('categories_tree', Category::getInstance()->toTree());
    }
    $scats['categories'] = Category::getInstance()->toTree();
    if (count($keys) > 0) {
        foreach ($keys as $k) {
            $scats = $scats['categories'][$k];
        }
    }

    $scats = $scats['categories'];

    View::getInstance()->_erase('categories');
    View::getInstance()->_erase('subcategories');
    View::getInstance()->_exportVariableToView('categories', $scats);
    View::getInstance()->_seek('categories', $position);
}

/**
 * Gets the total of subcategories for the current category. If subcategories are not loaded, this function will load
 * them and it will prepare the the pointer to the first element
 *
 * @return int -1 when there is no current category
 */
function osc_count_subcategories2()
{
    $category = View::getInstance()->_current('categories');
    if ($category == '') {
        return -1;
    }
    if (!isset($category['categories'])) {
        return 0;
    }
    if (!is_array($category['categories'])) {
        return 0;
    }

    return count($category['categories']);
}

/**
 * Export a category tree to the view, loading the full tree when none is given.
 *
 * @param array<int,array<string,mixed>>|null $categories
 *
 * @return void
 */
function osc_export_categories($categories = null)
{
    if ($categories == null) {
        $categories = Category::getInstance()->toTree();
    }
    View::getInstance()->_exportVariableToView('categories', $categories);
    View::getInstance()->_exportVariableToView('categories_tree', $categories);
}
