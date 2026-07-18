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
 * Helper Utils
 *
 * @package    Osclass
 * @subpackage Helpers
 * @author     Osclass
 */

/**
 * Getting from View the $key index
 *
 * @param string $key
 *
 * @return mixed
 */
function __get($key)
{
    return View::newInstance()->_get($key);
}


/**
 * Get variable from $_GET or $_POST
 *
 * @param string $key
 *
 * @return mixed
 */
function osc_get_param($key)
{
    return Params::getParam($key);
}


/**
 * Generic function for view layer, return the $field of $item
 * with specific $locale
 *
 * @param array  $item
 * @param string $field
 * @param string $locale
 *
 * @return string
 */
function osc_field($item, $field, $locale)
{
    if ($item !== null) {
        if ($locale == '') {
            if (isset($item[$field])) {
                return $item[$field];
            }
        } else {
            if (isset($item['locale']) && !empty($item['locale']) && isset($item['locale'][$locale])
                && isset($item['locale'][$locale][$field])
            ) {
                return $item['locale'][$locale][$field];
            }

            if (isset($item['locale'])) {
                foreach ($item['locale'] as $locale2 => $data) {
                    if (isset($item['locale'][$locale2][$field])) {
                        return $item['locale'][$locale2][$field];
                    }
                }
            }
        }
    }

    return '';
}


/**
 * Print all widgets belonging to $location
 *
 * @param string $location
 *
 * @return void
 */
function osc_show_widgets($location)
{
    $widgets = Widget::newInstance()->findByLocation($location);
    foreach ($widgets as $w) {
        echo $w['s_content'];
    }
}


/**
 * Print all widgets named $description
 *
 * @param string $description
 *
 * @return void
 */
function osc_show_widgets_by_description($description)
{
    $widgets = Widget::newInstance()->findByDescription($description);
    foreach ($widgets as $w) {
        echo $w['s_content'];
    }
}


/**
 * Print recaptcha html, if $section = "recover_password"
 * set 'recover_time' at session.
 *
 * @param string $section
 *
 * @return void
 */
function osc_show_recaptcha($section = '')
{
    if (osc_recaptcha_public_key()) {
        switch ($section) {
            case ('recover_password'):
                Session::newInstance()->_set('recover_captcha_not_set', 0);
                $time = Session::newInstance()->_get('recover_time');
                if ((time() - $time) <= 1200) {
                    echo _osc_recaptcha_get_html(osc_recaptcha_public_key(), substr(osc_language(), 0, 2)) . '<br />';
                } else {
                    Session::newInstance()->_set('recover_captcha_not_set', 1);
                }
                break;

            default:
                echo _osc_recaptcha_get_html(osc_recaptcha_public_key(), substr(osc_language(), 0, 2)) . '<br />';
                break;
        }
    }
}


/**
 * @param $siteKey
 * @param $lang
 */
function _osc_recaptcha_get_html($siteKey, $lang)
{
    echo '<div class="g-recaptcha" data-sitekey="' . $siteKey . '"></div>';
    echo '<script type="text/javascript" src="https://www.google.com/recaptcha/api.js?hl=' . $lang . '"></script>';
}


/**
 * Formats the date using the appropiate format.
 *
 * @param string $date
 * @param null   $dateformat
 *
 * @return string
 */
function osc_format_date($date, $dateformat = null)
{
    if ($dateformat == null) {
        $dateformat = osc_date_format();
    }

    $month       = array(
        '',
        __('January'),
        __('February'),
        __('March'),
        __('April'),
        __('May'),
        __('June'),
        __('July'),
        __('August'),
        __('September'),
        __('October'),
        __('November'),
        __('December')
    );
    $month_short = array(
        '',
        __('Jan'),
        __('Feb'),
        __('Mar'),
        __('Apr'),
        __('May'),
        __('Jun'),
        __('Jul'),
        __('Aug'),
        __('Sep'),
        __('Oct'),
        __('Nov'),
        __('Dec')
    );
    $day         = array(
        '',
        __('Monday'),
        __('Tuesday'),
        __('Wednesday'),
        __('Thursday'),
        __('Friday'),
        __('Saturday'),
        __('Sunday')
    );
    $day_short   = array('', __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun'));
    $ampm        = array('AM' => __('AM'), 'PM' => __('PM'), 'am' => __('am'), 'pm' => __('pm'));


    $time       = strtotime($date);
    $dateformat = preg_replace('|(?<!\\\)F|', osc_escape_string($month[date('n', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)M|', osc_escape_string($month_short[date('n', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)l|', osc_escape_string($day[date('N', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)D|', osc_escape_string($day_short[date('N', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)A|', osc_escape_string($ampm[date('A', $time)]), $dateformat);
    $dateformat = preg_replace('|(?<!\\\)a|', osc_escape_string($ampm[date('a', $time)]), $dateformat);

    return date($dateformat, $time);
}


/**
 * Escapes letters and numbers of a string
 *
 * @param string $string
 *
 * @return string
 * @since 2.4
 */
function osc_escape_string($string)
{
    $string = preg_replace('/^(\d)/', '\\\\\\\\\1', $string);
    $string = preg_replace('/([a-z])/i', '\\\\\1', $string);

    return $string;
}


/**
 * Prints the user's account menu
 *
 * @param array $options array with options of the form array('name' => 'display name', 'url' => 'url of link')
 *
 * @return void
 */
function osc_private_user_menu($options = null)
{
    if ($options == null) {
        $options   = array();
        $options[] = array(
            'name'  => __('Public Profile'),
            'url'   => osc_user_public_profile_url(osc_logged_user_id()),
            'class' => 'opt_publicprofile'
        );
        $options[] = array('name' => __('Dashboard'), 'url' => osc_user_dashboard_url(), 'class' => 'opt_dashboard');
        $options[] =
            array('name' => __('Manage your listings'), 'url' => osc_user_list_items_url(), 'class' => 'opt_items');
        $options[] = array('name' => __('Manage your alerts'), 'url' => osc_user_alerts_url(), 'class' => 'opt_alerts');
        $options[] = array('name' => __('My profile'), 'url' => osc_user_profile_url(), 'class' => 'opt_account');
        $options[] = array('name' => __('Logout'), 'url' => osc_user_logout_url(), 'class' => 'opt_logout');
    }

    $options = osc_apply_filter('user_menu_filter', $options);

    echo '<script type="text/javascript">';
    // Vanilla, and DOMContentLoaded-wrapped so it runs after the list below exists (the
    // old jQuery ran inline before the <ul> and matched nothing). No jQuery dependency.
    echo 'document.addEventListener("DOMContentLoaded",function(){'
         . 'var m=document.querySelector(".user_menu");if(m){'
         . 'if(m.firstElementChild){m.firstElementChild.classList.add("first");}'
         . 'if(m.lastElementChild){m.lastElementChild.classList.add("last");}}});';
    echo '</script>';
    echo '<ul class="user_menu">';

    $var_l = count($options);
    for ($var_o = 0; $var_o < ($var_l - 1); $var_o++) {
        echo '<li class="' . $options[$var_o]['class'] . '" ><a href="' . $options[$var_o]['url'] . '" >'
            . $options[$var_o]['name'] . '</a></li>';
    }

    osc_run_hook('user_menu');

    echo '<li class="' . $options[$var_l - 1]['class'] . '" ><a href="' . $options[$var_l - 1]['url'] . '" >'
        . $options[$var_l - 1]['name'] . '</a></li>';

    echo '</ul>';
}


/**
 * Gets prepared text, with:
 * - higlight search pattern and search city
 * - maxim length of text
 *
 * @param string $txt
 * @param int    $len
 * @param string $start_tag
 * @param string $end_tag
 *
 * @return string
 */
function osc_highlight($txt, $len = 300, $start_tag = '<strong>', $end_tag = '</strong>')
{
    $txt = strip_tags($txt);
    $txt = str_replace(array("\n\r", "\r\n", "\n", "\r", "\t"), ' ', $txt);
    $txt = trim($txt);
    $txt = preg_replace('/\s+/', ' ', $txt);
    if (mb_strlen($txt, 'UTF-8') > $len) {
        $txt = mb_substr($txt, 0, $len, 'UTF-8') . '...';
    }
    $query = osc_search_pattern();
    $query = str_replace(array('(', ')', '+', '-', '~', '>', '<'), array('', '', '', '', '', '', ''), $query);

    $query = str_replace(
        array('\\', '^', '$', '.', '[', '|', '?', '*', '{', '}', '/', ']'),
        array('\\\\', '\\^', '\\$', '\\.', '\\[', '\\|', '\\?', '\\*', '\\{', '\\}', '\\/', '\\]'),
        $query
    );

    $query = preg_replace('/\s+/', ' ', $query);

    $words = array();
    if (preg_match_all('/"([^"]*)"/', $query, $matches)) {
        $l = count($matches[1]);
        for ($k = 0; $k < $l; $k++) {
            $words[] = $matches[1][$k];
        }
    }

    $query = trim(preg_replace('/\s+/', ' ', preg_replace('/"([^"]*)"/', '', $query)));
    $words = array_merge($words, explode(' ', $query));

    foreach ($words as $word) {
        if ($word != '') {
            $txt =
                preg_replace("/(\PL|\s+|^)($word)(\PL|\s+|$)/i", '$01' . $start_tag . '$02' . $end_tag . '$03', $txt);
        }
    }

    return $txt;
}


/**
 *
 */
function osc_get_http_referer()
{
    $ref = Rewrite::newInstance()->get_http_referer();
    if ($ref != '') {
        return $ref;
    }

    if (Session::newInstance()->_getReferer() != '') {
        return Session::newInstance()->_getReferer();
    } elseif (Params::existServerParam('HTTP_REFERER')) {
        if (filter_var(Params::getServerParam('HTTP_REFERER', false, false), FILTER_VALIDATE_URL)) {
            return Params::getServerParam('HTTP_REFERER', false, false);
        }
    }

    return '';
}


/**
 * @param        $id
 * @param        $regexp
 * @param        $url
 * @param        $file
 * @param bool   $user_menu
 * @param string $location
 * @param string $section
 * @param string $title
 */
function osc_add_route(
    $id,
    $regexp,
    $url,
    $file,
    $user_menu = false,
    $location = 'custom',
    $section = 'custom',
    $title = 'Custom'
) {
    Rewrite::newInstance()->addRoute($id, $regexp, $url, $file, $user_menu, $location, $section, $title);
}


/**
 *
 */
function osc_get_subdomain_params()
{
    $options = array();
    if (osc_subdomain_name() != '') {
        if (Params::getParam('sCountry') != '') {
            $options['sCountry'] = Params::getParam('sCountry');
        }
        if (Params::getParam('sRegion') != '') {
            $options['sRegion'] = Params::getParam('sRegion');
        }
        if (Params::getParam('sCity') != '') {
            $options['sCity'] = Params::getParam('sCity');
        }
        if (Params::getParam('sCategory') != '') {
            $options['sCategory'] = Params::getParam('sCategory');
        }
        if (Params::getParam('sUser') != '') {
            $options['sUser'] = Params::getParam('sUser');
        }
    }

    return $options;
}

/**
 * Get Google Analytics tracking ID.
 *
 * @return string
 */
function osc_google_analytics_id()
{
    return osc_get_preference('ga_tracking_id');
}

/**
 * Get Google Maps API key.
 *
 * @return string
 */
function osc_google_maps_api_key()
{
    return osc_get_preference('googlemaps_api_key');
}

/**
 * Get Open Street Maps API key.
 *
 * @return string
 */
function osc_openstreet_api_key()
{
    return osc_get_preference('openstreet_api_key');
}

/**
 * Get Google Maps geocode URL.
 *
 * @return string
 */
function osc_google_maps_geocode_url($address)
{
    return 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . urlencode($address) . '&sensor=false&key='.osc_google_maps_api_key();
}

/**
 * Get OpenStreetMaps geocode URL.
 *
 * @return string
 */
function osc_openstreet_geocode_url($address)
{
    return 'https://www.mapquestapi.com/geocoding/v1/address?location='
        . urlencode($address) . '&key='.osc_openstreet_api_key();
}


/**
 * Get URL of location files JSON.
 *
 * @return string
 */
function osc_get_locations_json_url()
{
    return 'https://raw.githubusercontent.com/mindstellar/geodata/master/src/json-list.json';
}


/**
 * Get URL of location SQL.
 *
 * @param string $location
 *
 * @return string
 */
function osc_get_locations_sql_url($location)
{
    $location = rawurlencode($location);

    return 'https://raw.githubusercontent.com/mindstellar/Osclass-Extras/master/locations/' . $location;
}

/**
 * Get i18n repository URL.
 * @return string
 */
function osc_get_i18n_repository_url($path = '')
{
    // Check if version tring contain dev,alpha,beta,RC set is_dev to 1
    $is_dev = false;
    // try str_replace to remove all version tags from string if string changed than it's dev
    $version = str_replace(array('dev', 'alpha', 'beta', 'rc'), '', strtolower(osc_version()));
    // if version string changed than it's dev
    if ($version !== osc_version()) {
        $is_dev = true;
    }
    if ($is_dev) {
        // get url of local_list.json from github
        $repoUrl = 'https://raw.githubusercontent.com/mindstellar/i10n-osclass/develop/';
    } else {
        $repoUrl = 'https://raw.githubusercontent.com/mindstellar/i10n-osclass/master/';
    }
    if ($path === '') {
        $path = 'locale_list.json';
    }
    ltrim($path, '/');
    $path = rawurlencode($path);

    return $repoUrl . $path;
}
