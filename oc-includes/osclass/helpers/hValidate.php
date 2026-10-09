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
    return (new \mindstellar\utility\Validate())->text($value, $count, $required);
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
    return (new \mindstellar\utility\Validate())->int($value);
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
    return (new \mindstellar\utility\Validate())->nozero($value);
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
    return (new \mindstellar\utility\Validate())->number($value, $required);
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
    return (new \mindstellar\utility\Validate())->phone($value, $count, $required);
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
    return (new \mindstellar\utility\Validate())->min($value, $min);
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
    return (new \mindstellar\utility\Validate())->max($value, $max);
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
    return (new \mindstellar\utility\Validate())->range($value, $min, $max);
}

/**
 * Validate if exist $city, $region, $country in db
 *
 * @param int|string $city     City id
 * @param string     $sCity    Free-text city name
 * @param int|string $region   Region id
 * @param string     $sRegion  Free-text region name
 * @param string     $country  Country code
 * @param string     $sCountry Free-text country name
 *
 * @return bool
 */
function osc_validate_location($city, $sCity, $region, $sRegion, $country, $sCountry)
{
    return (new \mindstellar\utility\Validate())->location($city, $sCity, $region, $sRegion, $country, $sCountry);
}

/**
 * Validate if exist category $value and is enabled in db
 *
 * @param int|string $value Category id
 *
 * @return bool
 */
function osc_validate_category($value)
{
    return (new \mindstellar\utility\Validate())->category($value);
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
    return (new \mindstellar\utility\Validate())->url($value, $required, $get_headers);
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
    return (new \mindstellar\utility\Validate())->delay($type);
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
    return (new \mindstellar\utility\Validate())->email($email, $required);
}

/**
 * validate username, accept letters plus underline, without separators
 *
 * @param string $value
 * @param int    $min
 *
 * @return bool
 */
function osc_validate_username($value, $min = 1)
{
    return (new \mindstellar\utility\Validate())->username($value, $min);
}

/**
 * Validate locale  string. Check against available locale list
 *
 * @param string $locale
 * @param bool   $admin  Check the admin locale list instead of the public one
 *
 * @return bool
 * @since 4.0
 * @author maddrid <https://github.com/maddrid>
 */
function osc_validate_locale($locale, $admin = false)
{
    return (new \mindstellar\utility\Validate())->localeCode($locale, $admin);
}
