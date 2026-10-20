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
 * Escape all the values of an array.
 *
 * @param array $array Array used to apply addslashes.
 * @return array $array after apply addslashes.
 */
function add_slashes_extended($array)
{
    return (new \mindstellar\utility\Formatting())->addSlashesExtended($array);
}

/**
 * Turn a string into a URL-safe slug: tags, accents, entities and punctuation removed,
 * whitespace collapsed to single hyphens.
 *
 * @param string $string
 *
 * @return string
 */
function osc_sanitizeString($string)
{
    return (new \mindstellar\utility\Formatting())->formatSlug($string);
}

/**
 * Replace accented characters with their unaccented ASCII equivalents.
 * Handles both UTF-8 and ISO-8859-1 input; pure ASCII is returned untouched.
 *
 * @param string $string
 *
 * @return string
 */
function remove_accents($string)
{
    return (new \mindstellar\utility\Formatting())->removeAccents($string);
}

/**
 * Tell whether a string is valid UTF-8.
 *
 * @param string $string
 *
 * @return int|false 1 when valid, 0 when not, false if the match itself fails
 */
function is_utf8($string)
{
    return (new \mindstellar\utility\Formatting())->isUtf8($string);
}
