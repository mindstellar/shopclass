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
 * Maintenance-mode helpers.
 *
 * The `.maintenance` file is still the on/off switch. Whether that switch
 * 503s the public site is a separate preference, defaulting on so existing
 * installs and the upgrade path that touches the file keep locking visitors
 * out. The banner (and the 503 page) can show a short admin-written message.
 *
 * @package    Shopclass
 * @subpackage Helpers
 */

/** Preference section (core `t_preference.s_section`). */
const OSC_MAINTENANCE_PREF_SECTION = 'osclass';

/** BOOLEAN preference: public HTTP 503 while `.maintenance` exists. */
const OSC_MAINTENANCE_PREF_LOCKOUT = 'maintenance_lockout';

/** STRING preference: plain-text banner / 503 copy. */
const OSC_MAINTENANCE_PREF_MESSAGE = 'maintenance_message';

/** Stored message is trimmed, tags stripped, then cut to this length. */
const OSC_MAINTENANCE_MESSAGE_MAX = 500;

/**
 * Whether a stored lockout preference means "503 the public site".
 *
 * Missing, null, false, or '' is on. Only an explicit `'0'` (or integer 0)
 * turns lockout off. That way a site that has never saved the new checkbox
 * keeps today's behaviour.
 *
 * Pure: no database. Safe to call from characterization tests.
 *
 * @param mixed $value Preference value, or null if unset.
 *
 * @return bool
 */
function osc_maintenance_lockout_from_pref($value)
{
    if ($value === null || $value === false) {
        return true;
    }
    if (is_array($value) || is_object($value)) {
        return true;
    }

    return trim((string)$value) !== '0';
}

/**
 * Strip tags, trim, and cap the admin-written maintenance message.
 *
 * Pure: no database. HTML is not stored; callers escape on output.
 *
 * @param mixed $raw Posted or stored value.
 *
 * @return string
 */
function osc_sanitize_maintenance_message($raw)
{
    if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
        return '';
    }
    $s = trim(strip_tags((string)$raw));
    if ($s === '') {
        return '';
    }
    $max = OSC_MAINTENANCE_MESSAGE_MAX;
    if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    if (strlen($s) > $max) {
        return substr($s, 0, $max);
    }

    return $s;
}

/**
 * Should this request be answered with HTTP 503?
 *
 * False when there is no `.maintenance` file, the visitor is a signed-in
 * admin, the SAPI is CLI (so `php index.php -p cron` still runs), or lockout
 * has been turned off. Pure: no database.
 *
 * @param bool $fileExists      `.maintenance` is present at the install root.
 * @param bool $lockoutEnabled  osc_maintenance_lockout_from_pref() answer.
 * @param bool $isAdmin         Signed-in oc-admin user.
 * @param bool $isCli           PHP_SAPI === 'cli' / the CLI constant.
 *
 * @return bool
 */
function osc_maintenance_should_lockout_request($fileExists, $lockoutEnabled, $isAdmin, $isCli)
{
    if (!$fileExists || $isCli || $isAdmin) {
        return false;
    }

    return (bool)$lockoutEnabled;
}

/**
 * Whether public visitors should get HTTP 503 while `.maintenance` exists.
 *
 * @return bool
 */
function osc_maintenance_lockout_enabled()
{
    if (!function_exists('osc_get_preference')) {
        return true;
    }

    return osc_maintenance_lockout_from_pref(
        osc_get_preference(OSC_MAINTENANCE_PREF_LOCKOUT, OSC_MAINTENANCE_PREF_SECTION)
    );
}

/**
 * Default visitor copy when the admin has not saved a message.
 *
 * @return string
 */
function osc_maintenance_default_message()
{
    $title = function_exists('osc_page_title') ? (string)osc_page_title() : '';
    if ($title !== '' && function_exists('__')) {
        return sprintf(
            __('%s is undergoing maintenance right now. We\'re making some improvements and will be back shortly — thanks for your patience.'),
            $title
        );
    }

    return "We're making some improvements and will be back shortly — thanks for your patience.";
}

/**
 * Plain-text message shown on the public banner and the 503 page.
 *
 * Empty stored preference falls back to osc_maintenance_default_message().
 *
 * @return string
 */
function osc_maintenance_visitor_message()
{
    $saved = '';
    if (function_exists('osc_get_preference')) {
        $saved = osc_sanitize_maintenance_message(
            osc_get_preference(OSC_MAINTENANCE_PREF_MESSAGE, OSC_MAINTENANCE_PREF_SECTION)
        );
    }
    if ($saved !== '') {
        return $saved;
    }

    return osc_maintenance_default_message();
}
