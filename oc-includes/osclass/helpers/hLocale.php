<?php

/*
 * This file is part of Shopclass (Mindstellar).
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
 * Helper Locales
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Gets locale generic field
 *
 * @param $field
 * @param $locale
 *
 * @return string
 */
function osc_locale_field($field, $locale = '')
{
    return osc_field(osc_locale(), $field, $locale);
}

/**
 * Gets locale object
 *
 * @return array
 */
function osc_locale()
{
    $locale = null;
    if (View::newInstance()->_exists('locales')) {
        $locale = View::newInstance()->_current('locales');
    } elseif (View::newInstance()->_exists('locale')) {
        $locale = View::newInstance()->_get('locale');
    }

    return $locale;
}

/**
 * Gets list of locales
 *
 * @return array
 */
function osc_get_locales()
{
    if (!View::newInstance()->_exists('locales')) {
        $locale = OSCLocale::newInstance()->listAllEnabled();
        View::newInstance()->_exportVariableToView('locales', $locale);
    } else {
        $locale = View::newInstance()->_get('locales');
    }

    return $locale;
}

/**
 * Private function to count locales
 *
 * @return boolean
 */
function osc_priv_count_locales()
{
    return View::newInstance()->_count('locales');
}

/**
 * Reset iterator of locales
 *
 * @return void
 */
function osc_goto_first_locale()
{
    View::newInstance()->_reset('locales');
}

/**
 * Gets number of enabled locales for website
 *
 * @return int
 */
function osc_count_web_enabled_locales()
{
    if (!View::newInstance()->_exists('locales')) {
        View::newInstance()->_exportVariableToView('locales', OSCLocale::newInstance()->listAllEnabled());
    }

    return osc_priv_count_locales();
}

/**
 * Iterator for enabled locales for website
 *
 * @return bool
 */
function osc_has_web_enabled_locales()
{
    if (!View::newInstance()->_exists('locales')) {
        View::newInstance()->_exportVariableToView('locales', OSCLocale::newInstance()->listAllEnabled());
    }

    return View::newInstance()->_next('locales');
}

/**
 * Gets current locale's code
 *
 * @return string
 */
function osc_locale_code()
{
    return osc_locale_field('pk_c_code');
}

/**
 * Gets current locale's name
 *
 * @return string
 */
function osc_locale_name()
{
    return osc_locale_field('s_name');
}

/**
 * Gets current locale's currency format
 *
 * @return string
 */
function osc_locale_currency_format()
{
    $aLocales = osc_get_locales();
    $cLocale  = $aLocales[0];

    foreach ($aLocales as $locale) {
        if ($locale['pk_c_code'] == osc_current_user_locale()) {
            $cLocale = $locale;
            break;
        }
    }

    return $cLocale['s_currency_format'];
}

/**
 * Gets current locale's decimal point
 *
 * @return string
 */
function osc_locale_dec_point()
{
    $aLocales = osc_get_locales();
    $cLocale  = $aLocales[0];

    foreach ($aLocales as $locale) {
        if ($locale['pk_c_code'] == osc_current_user_locale()) {
            $cLocale = $locale;
            break;
        }
    }

    return $cLocale['s_dec_point'];
}

/**
 * Gets current locale's thousands separator
 *
 * @return string
 */
function osc_locale_thousands_sep()
{
    $aLocales = osc_get_locales();
    $cLocale  = $aLocales[0];

    foreach ($aLocales as $locale) {
        if ($locale['pk_c_code'] === osc_current_user_locale()) {
            $cLocale = $locale;
            break;
        }
    }

    return $cLocale['s_thousands_sep'];
}

/**
 * Gets current locale's test direction
 *
 * @return string
 */
function osc_locale_text_direction()
{
    $aLocales = osc_get_locales();
    $cLocale  = $aLocales[0];

    foreach ($aLocales as $locale) {
        if ($locale['pk_c_code'] === osc_current_user_locale()) {
            $cLocale = $locale;
            break;
        }
    }

    return $cLocale['s_direction'];
}

/**
 * Gets current locale's number of decimals
 *
 * @return string
 */
function osc_locale_num_dec()
{
    $aLocales = osc_get_locales();
    $cLocale  = $aLocales[0];

    foreach ($aLocales as $locale) {
        if ($locale['pk_c_code'] == osc_current_user_locale()) {
            $cLocale = $locale;
            break;
        }
    }

    return $cLocale['i_num_dec'];
}
/**
 * Gets list of enabled admin locales
 *
 * @return array
 * @since 4.0.0
 */
function osc_get_admin_locales()
{
    if (!View::newInstance()->_exists('adminLocales')) {
        $locale = OSCLocale::newInstance()->listAllEnabled(true);
        View::newInstance()->_exportVariableToView('adminLocales', $locale);
    } else {
        $locale = View::newInstance()->_get('adminLocales');
    }

    return $locale;
}

/**
 * Gets list of enabled locales
 *
 * @param bool $indexed_by_pk
 *
 * @return array
 */
function osc_all_enabled_locales_for_admin($indexed_by_pk = false)
{
    return OSCLocale::newInstance()->listAllEnabled(true, $indexed_by_pk);
}

/**
 * Gets current locale object
 *
 * @return array
 */
function osc_get_current_user_locale()
{
    $locale = OSCLocale::newInstance()->findByPrimaryKey(osc_current_user_locale());
    View::newInstance()->_exportVariableToView('locale', $locale);

    return $locale;
}

/**
 * Get the actual locale of the user.
 *
 * You get the right locale code. If an user is using the website in another language different of the default one, or
 * the user uses the default one, you'll get it.
 *
 * @return string Locale Code
 */
function osc_current_user_locale()
{
    // The chosen front-end locale lives in a standalone cookie (see
    // osc_set_current_user_locale()), not the session, so switching language never
    // starts a session and language-switched anonymous browsing stays cacheable.
    // Validate it: a visitor controls their own cookie and the value is concatenated
    // into a translation .mo file path downstream, so an unchecked value would be a
    // path-traversal vector.
    if (isset($_COOKIE['oc_userLocale'])
        && (new \mindstellar\utility\Validate())->localeCode($_COOKIE['oc_userLocale'])
    ) {
        return $_COOKIE['oc_userLocale'];
    }

    // Backward-compat during upgrade: honour a locale left in an already-active
    // session. Read-only — Session::_get() resumes a session only when its cookie is
    // already present, so this never starts one for an anonymous visitor.
    $sessionLocale = Session::newInstance()->_get('userLocale');
    if ($sessionLocale !== ''
        && (new \mindstellar\utility\Validate())->localeCode($sessionLocale)
    ) {
        return $sessionLocale;
    }

    return osc_language();
}

/**
 * Persist the visitor's chosen front-end locale in a dedicated cookie.
 *
 * The locale used to live in $_SESSION, which started a PHP session — and made the
 * response uncacheable — the moment an anonymous visitor switched language. A
 * standalone cookie keeps the choice without a session. The value is validated
 * against the installed locales both here and on read, because it is later
 * concatenated into a translation .mo file path.
 *
 * It expires after a day: carrying it makes every response personalized (see
 * osc_cache_relevant_cookies()), so a longer life opts a visitor out of the shared
 * cache well past the visit the choice was made in.
 *
 * @param string $locale
 *
 * @return void
 */
function osc_set_current_user_locale($locale)
{
    if (!(new \mindstellar\utility\Validate())->localeCode($locale)) {
        return;
    }

    $options = array(
        'expires'  => time() + 86400,
        'path'     => defined('REL_WEB_URL') ? REL_WEB_URL : '/',
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (osc_is_ssl()) {
        $options['secure'] = true;
    }
    if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
        $options['domain'] = COOKIE_DOMAIN;
    }
    if (!headers_sent()) {
        setcookie('oc_userLocale', $locale, $options);
    }
    // Reflect the choice within the current request too — an item/page rendered right
    // after a ?lang= switch reads it back through osc_current_user_locale().
    $_COOKIE['oc_userLocale'] = $locale;
}

/**
 * Get the actual locale of the admin.
 *
 * You get the right locale code. If an admin is using the website in another language different of the default one, or
 * the admin uses the default one, you'll get it.
 *
 * @return string OSCLocale Code
 */
function osc_current_admin_locale()
{
    if (Session::newInstance()->_get('adminLocale') != '') {
        return Session::newInstance()->_get('adminLocale');
    }

    return osc_admin_language();
}
