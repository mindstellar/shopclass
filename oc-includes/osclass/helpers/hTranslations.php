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
 * Helper Translation
 *
 * @package    Osclass
 * @subpackage Helpers
 * @author     Osclass
 */

/**
 * Translate strings
 *
 * @param string $key
 * @param string $domain
 *
 * @return string
 * @since unknown
 *
 */
function __($key, $domain = 'core')
{
    $gt     = Translation::newInstance()->_get();
    $string = $gt->dgettext($domain, $key);

    return osc_apply_filter('gettext', $string);
}


/**
 * Translate strings and echo them
 *
 * @param string $key
 * @param string $domain
 *
 * @since unknown
 *
 */
function _e($key, $domain = 'core')
{
    echo __($key, $domain);
}


/**
 * Translate string (flash messages)
 *
 * @param string $key
 *
 * @return string
 * @since unknown
 *
 */
function _m($key)
{
    return __($key, 'messages');
}


/**
 * Retrieve the singular or plural translation of the string.
 *
 * @param string $single_key
 * @param string $plural_key
 * @param int    $count
 * @param string $domain
 *
 * @return string
 * @since 2.2
 *
 */
function _n($single_key, $plural_key, $count, $domain = 'core')
{
    $gt     = Translation::newInstance()->_get();
    $string = $gt->dngettext($domain, $single_key, $plural_key, $count);

    return osc_apply_filter('ngettext', $string);
}


/**
 * Retrieve the singular or plural translation of the string.
 *
 * @param string $single_key
 * @param string $plural_key
 * @param int    $count
 *
 * @return string
 * @since 2.2
 *
 */
function _mn($single_key, $plural_key, $count)
{
    return _n($single_key, $plural_key, $count, 'messages');
}

/* file end: ./oc-includes/osclass/helpers/hTranslations.php */
