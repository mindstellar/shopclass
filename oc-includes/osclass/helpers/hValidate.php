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
 * Helper Validation
 *
 * @package    Shopclass
 * @subpackage Helpers
 * @author     Shopclass
 */

/**
 * Validate the text with a minimum of non-punctuation characters (international)
 *
 * @param string  $value
 * @param integer $count
 * @param boolean $required
 *
 * @return boolean
 */
function osc_validate_text($value = '', $count = 1, $required = true)
{
    if ($required || $value) {
        if (!preg_match("/([\p{L}\p{N}]){" . $count . '}/iu', strip_tags($value))) {
            return false;
        }
    }

    return true;
}


/**
 * Validate one or more numbers (no periods)
 *
 * @param string $value
 *
 * @return boolean
 */
function osc_validate_int($value)
{
    if (preg_match('/^[0-9]+$/', $value)) {
        return true;
    }

    return false;
}


/**
 * Validate one or more numbers (no periods), must be more than 0.
 *
 * @param string $value
 *
 * @return boolean
 */
function osc_validate_nozero($value)
{
    return preg_match('/^[0-9]+$/', $value) && $value > 0;
}


/**
 * Validate $value is a number or a numeric string
 *
 * @param string  $value
 * @param boolean $required
 *
 * @return boolean
 */
function osc_validate_number($value = null, $required = false)
{
    if ($required || $value != '') {
        if (!is_numeric($value)) {
            return false;
        }
    }

    return true;
}


/**
 * Validate $value is a number phone,
 * with $count length
 *
 * @param string  $value
 * @param int     $count
 * @param boolean $required
 *
 * @return boolean
 */
function osc_validate_phone($value = null, $count = 10, $required = false)
{
    if ($required || $value != '') {
        if (!preg_match("/([\p{Nd}][^\p{Nd}]*){" . $count . '}/i', strip_tags($value))) {
            return false;
        }
    }

    return true;
}


/**
 * Validate if $value is more than $min
 *
 * @param string $value
 * @param int    $min
 *
 * @return boolean
 */
function osc_validate_min($value = null, $min = 6)
{
    return !(mb_strlen($value, 'UTF-8') < $min);
}


/**
 * Validate if $value is less than $max
 *
 * @param string $value
 * @param int    $max
 *
 * @return boolean
 */
function osc_validate_max($value = null, $max = 255)
{
    return !(mb_strlen($value, 'UTF-8') > $max);
}


/**
 * Validate if $value belongs at range between min to max
 *
 * @param string $value
 * @param int    $min
 * @param int    $max
 *
 * @return boolean
 */
function osc_validate_range($value, $min = 6, $max = 255)
{
    return mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
}


/**
 * Validate if exist $city, $region, $country in db
 *
 * @param string $city
 * @param        $sCity
 * @param string $region
 * @param        $sRegion
 * @param string $country
 * @param        $sCountry
 *
 * @return boolean
 */
function osc_validate_location($city, $sCity, $region, $sRegion, $country, $sCountry)
{
    if (osc_validate_nozero($city) && osc_validate_nozero($region) && osc_validate_text($country, 2)) {
        $data      = Country::newInstance()->findByCode($country);
        $countryId = $data['pk_c_code'];
        if ($countryId) {
            $data     = Region::newInstance()->findByPrimaryKey($region);
            $regionId = $data['pk_i_id'];
            if ($data['b_active'] == 1) {
                $data = City::newInstance()->findByPrimaryKey($city);
                if ($data['b_active'] == 1 && $data['fk_i_region_id'] == $regionId
                    && strtolower($data['fk_c_country_code']) == strtolower($countryId)
                ) {
                    return true;
                }
            }
        }
    } elseif (osc_validate_nozero($region) && osc_validate_text($country, 2) && $sCity != '') {
        return true;
    } elseif ($sRegion != '' && osc_validate_text($country, 2) && $sCity != '') {
        return true;
    } elseif ($sRegion != '' && $sCountry != '' && $sCity != '') {
        return true;
    }

    return false;
}


/**
 * Validate if exist category $value and is enabled in db
 *
 * @param string $value
 *
 * @return boolean
 */
function osc_validate_category($value)
{
    if (osc_validate_nozero($value)) {
        $data = Category::newInstance()->findByPrimaryKey($value);
        if (isset($data['b_enabled']) && $data['b_enabled'] == 1) {
            if (osc_selectable_parent_categories()) {
                return true;
            }

            if ($data['fk_i_parent_id'] != null) {
                return true;
            }
        }
    }

    return false;
}


/**
 * Validate if $value url is a valid url.
 * Check header response to validate.
 *
 * @param string  $value
 * @param boolean $required
 * @param bool    $get_headers
 *
 * @return boolean
 */
function osc_validate_url($value, $required = false, $get_headers = false)
{
    if ($required || $value !== '') {
        $value = osc_sanitize_url($value);
        if (!function_exists('filter_var')) {
            $success =
                preg_match(
                    '|^(http\:\/\/[a-zA-Z0-9_\-]+(?:\.[a-zA-Z0-9_\-]+)*\.[a-zA-Z]{2,4}(?:\/[a-zA-Z0-9_]+)*(?:\/[a-zA-Z0-9_]+\.[a-zA-Z]{2,4}(?:\?[a-zA-Z0-9_]+\=[a-zA-Z0-9_]+)?)?(?:\&[a-zA-Z0-9_]+\=[a-zA-Z0-9_]+)*)$|',
                    $value,
                    $m
                );
        } else {
            $success = filter_var($value, FILTER_VALIDATE_URL);
        }
        if ($success) {
            if ($get_headers) {
                @$headers = get_headers($value);
                if (!preg_match('/^HTTP\/\d\.\d\s+(200|301|302)/', $headers[0])) {
                    return false;
                }
            }
        } else {
            return false;
        }
    }

    return true;
}


/**
 * Validate time between two items added/comments
 *
 * @param string $type
 *
 * @return boolean
 */
function osc_validate_spam_delay($type = 'item')
{
    if ($type === 'item') {
        $delay    = osc_item_spam_delay();
        $saved_as = 'last_submit_item';
    } else {
        $delay    = osc_comment_spam_delay();
        $saved_as = 'last_submit_comment';
    }

    // check $_SESSION
    return !((Session::newInstance()->_get($saved_as) + $delay) > time()
        || (Cookie::newInstance()->get_value($saved_as) + $delay) > time());
}


/**
 * Validate an email address
 * Source: http://www.linuxjournal.com/article/9585?page=0,3
 *
 * @param string  $email
 * @param boolean $required
 *
 * @return boolean
 */
function osc_validate_email($email, $required = true)
{
    if ($required || $email !== '') {
        // Test for the minimum length the email can be
        if (strlen($email) < 3) {
            return false;
        }

        // Test for an @ character after the first position
        if (strpos($email, '@', 1) === false) {
            return false;
        }

        // Split out the local and domain parts
        list($local, $domain) = explode('@', $email, 2);

        // LOCAL PART
        // Test for invalid characters
        if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local)) {
            return false;
        }

        // DOMAIN PART
        // Test for sequences of periods
        if (preg_match('/\.{2,}/', $domain)) {
            return false;
        }
        // Test for leading and trailing periods and whitespace
        if (trim($domain, " \t\n\r\0\x0B.") !== $domain) {
            return false;
        }
        // Split the domain into subs
        $subs = explode('.', $domain);
        // Assume the domain will have at least two subs
        if (2 > count($subs)) {
            return false;
        }
        // Loop through each sub
        foreach ($subs as $sub) {
            // Test for leading and trailing hyphens and whitespace
            if (trim($sub, " \t\n\r\0\x0B-") !== $sub) {
                return false;
            }
            // Test for invalid characters
            if (!preg_match('/^[a-z0-9-]+$/i', $sub)) {
                return false;
            }
        }

        // Congratulations your email made it!
        return true;
    }

    return true;
}


/**
 * validate username, accept letters plus underline, without separators
 *
 * @param $value
 * @param $min
 *
 * @return bool
 */
function osc_validate_username($value, $min = 1)
{
    return mb_strlen($value, 'UTF-8') >= $min && preg_match('/^[A-Za-z0-9_]+$/', $value);
}


/**
 * Validate locale  string. Check against available locale list
 *
 * @param $locale
 * @return bool
 * @since 4.0
 * @author maddrid <https://github.com/maddrid>
 */
function osc_validate_locale($locale, $admin = false)
{
    return (new \mindstellar\utility\Validate())->localeCode($locale, $admin);
}
