<?php

/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Helper Pages
 *
 * @package    Osclass
 * @subpackage Helpers
 * @author     Osclass
 */

/**
 * Gets current page object
 *
 * @return array
 */
function osc_static_page()
{
    if (View::newInstance()->_exists('pages')) {
        $page = View::newInstance()->_current('pages');
    } else {
        $page = null;
    }
    if (View::newInstance()->_exists('page')) {
        $page = View::newInstance()->_get('page');
    }

    if (!View::newInstance()->_exists('page_meta')) {
        View::newInstance()->_exportVariableToView('page_meta', json_decode(@$page['s_meta'], true));
    }

    return $page;
}


/**
 * Gets current page field
 *
 * @param string $field
 * @param string $locale
 *
 * @return string
 */
function osc_static_page_field($field, $locale = '')
{
    return osc_field(osc_static_page(), $field, $locale);
}


/**
 * Gets current page title
 *
 * @param string $locale
 *
 * @return string
 */
function osc_static_page_title($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_static_page_field('s_title', $locale);
}


/**
 * Gets current page text
 *
 * @param string $locale
 *
 * @return string
 */
function osc_static_page_text($locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }

    return osc_static_page_field('s_text', $locale);
}


/**
 * Gets current page ID
 *
 * @return string
 */
function osc_static_page_id()
{
    return osc_static_page_field('pk_i_id');
}


/**
 * Get page order
 *
 * @return int
 */
function osc_static_page_order()
{
    return (int)osc_static_page_field('i_order');
}


/**
 * Gets current page modification date
 *
 * @return string
 */
function osc_static_page_mod_date()
{
    return osc_static_page_field('dt_mod_date');
}


/**
 * Gets current page publish date
 *
 * @return string
 */
function osc_static_page_pub_date()
{
    return osc_static_page_field('dt_pub_date');
}


/**
 * Gets current page slug or internal name
 *
 * @return string
 */
function osc_static_page_slug()
{
    return osc_static_page_field('s_internal_name');
}


/**
 * Gets current page meta information
 *
 * @param null $field
 *
 * @return string
 */
function osc_static_page_meta($field = null)
{
    if (!View::newInstance()->_exists('page_meta')) {
        $meta = json_decode(osc_static_page_field('s_meta'), true);
    } else {
        $meta = View::newInstance()->_get('page_meta');
    }
    if ($field == null) {
        $meta = (isset($meta[$field]) && !empty($meta[$field])) ? $meta[$field] : '';
    }

    return $meta;
}


/**
 * Gets current page url
 *
 * @param string $locale
 *
 * @return string
 */
function osc_static_page_url($locale = '')
{
    if (osc_rewrite_enabled()) {
        $sanitized_categories = array();
        $cat                  = Category::newInstance()->hierarchy(osc_item_category_id());
        for ($i = count($cat); $i > 0; $i--) {
            $sanitized_categories[] = $cat[$i - 1]['s_slug'];
        }
        $url = str_replace(array('{PAGE_ID}', '{PAGE_TITLE}'), array(
            osc_static_page_id(),
            osc_static_page_title()
        ), str_replace('{PAGE_SLUG}', urlencode(osc_static_page_slug()), osc_get_preference('rewrite_page_url')));
        if ($locale != '') {
            $path = osc_base_url() . $locale . '/' . $url;
        } else {
            $path = osc_base_url() . $url;
        }
    } else {
        if ($locale != '') {
            $path = osc_base_url(true) . '?page=page&id=' . osc_static_page_id() . '&lang=' . $locale;
        } else {
            $path = osc_base_url(true) . '?page=page&id=' . osc_static_page_id();
        }
    }

    return $path;
}


/**
 * Gets the specified static page by internal name.
 *
 * @param string $internal_name
 * @param string $locale
 *
 * @return void
 */
function osc_get_static_page($internal_name, $locale = '')
{
    if ($locale == '') {
        $locale = osc_current_user_locale();
    }
    $page = Page::newInstance()->findByInternalName($internal_name, $locale);
    View::newInstance()->_exportVariableToView('page_meta', json_decode(@$page['s_meta'], true));

    View::newInstance()->_exportVariableToView('page', $page);
}


/**
 * Gets the total of static pages. If static pages are not loaded, this function will load them.
 *
 * @return int
 */
function osc_count_static_pages()
{
    if (!View::newInstance()->_exists('pages')) {
        View::newInstance()->_exportVariableToView('pages', Page::newInstance()->listAll(false));
    }

    return View::newInstance()->_count('pages');
}


/**
 * Let you know if there are more static pages in the list. If static pages are not loaded,
 * this function will load them.
 *
 * @return boolean
 */
function osc_has_static_pages()
{
    if (!View::newInstance()->_exists('pages')) {
        View::newInstance()->_exportVariableToView('pages', Page::newInstance()->listAll(false, 1));
    }
    if (View::newInstance()->_get('pageLoop') !== 'pages') {
        View::newInstance()->_exportVariableToView('oldPage', View::newInstance()->_get('page'));
        View::newInstance()->_exportVariableToView('pageLoop', 'pages');
    }
    $page = View::newInstance()->_next('pages');
    if (!$page) {
        View::newInstance()->_exportVariableToView('page', View::newInstance()->_get('oldPage'));
        View::newInstance()->_exportVariableToView('pageLoop', '');
    } else {
        View::newInstance()->_exportVariableToView('page', View::newInstance()->_current('pages'));
    }
    if (isset($page['s_meta'])) {
        View::newInstance()->_exportVariableToView('page_meta', json_decode($page['s_meta'], true));
    }

    return $page;
}


/**
 * Move the iterator to the first position of the pages array
 * It reset the osc_has_page function so you could have several loops
 * on the same page
 *
 * @return void
 */
function osc_reset_static_pages()
{
    if (View::newInstance()->_exists('oldPage')) {
        View::newInstance()->_exportVariableToView('page', View::newInstance()->_get('oldPage'));
    }
    if (View::newInstance()->_exists('pageLoop')) {
        View::newInstance()->_exportVariableToView('pageLoop', '');
    }
    return View::newInstance()->_reset('pages');
}
