<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin;

use Params;

/**
 * The page and page-size of an admin list screen, read from the request once and safely.
 *
 * Every list screen used to parse `iPage` and `iDisplayLength` itself, and no two did it the
 * same way: some cast, some tested `is_numeric`, some tested neither. What they had in common
 * was trusting the number. `?iDisplayLength=0` divided by it (`hPagination.php:158`) and
 * `?iDisplayLength=abc` multiplied by it, so either one answered HTTP 500 on almost every list
 * screen in the admin; `?iPage=-3` sent two screens into a redirect loop.
 *
 * A page number and a row count are the two values on these screens that come straight from a
 * URL, so they are the two worth reading in one place.
 *
 * @package mindstellar\admin
 */
final class ListPaging
{
    /** Rows per page when the screen names no preference of its own. */
    public const DEFAULT_LENGTH = 10;

    /**
     * The largest page anything may ask for. Comfortably above the 500 the size control
     * offers, so it only ever stops a hand-edited URL asking for a million rows.
     */
    public const MAX_LENGTH = 1000;

    /**
     * The page being asked for, counting from 1.
     *
     * Anything that is not a whole number above zero is page 1 — absent, empty, negative,
     * a word, a float. The value is written back to the request, because a screen builds its
     * own paging links from the parameter afterwards and they have to agree with the rows.
     *
     * @param string $name
     *
     * @return int
     */
    public static function page(string $name = 'iPage'): int
    {
        $raw  = Params::getParam($name);
        $page = (is_numeric($raw) && (int) $raw >= 1) ? (int) $raw : 1;

        Params::setParam($name, $page);

        return $page;
    }

    /**
     * Rows per page: the screen's own default unless the request names a usable number.
     *
     * Capped at MAX_LENGTH, so a hand-edited URL cannot ask for a million rows and take the
     * admin down with it. Every size the per-page control offers is inside the cap.
     *
     * @param int    $default
     * @param string $name
     *
     * @return int
     */
    public static function length(int $default = self::DEFAULT_LENGTH, string $name = 'iDisplayLength'): int
    {
        $default = $default >= 1 ? min($default, self::MAX_LENGTH) : self::DEFAULT_LENGTH;
        $raw     = Params::getParam($name);

        if (!is_numeric($raw) || (int) $raw < 1) {
            return $default;
        }

        return min((int) $raw, self::MAX_LENGTH);
    }

    /**
     * Rows per page, remembered across screens in a cookie.
     *
     * An admin who sets a list to 50 rows expects the next list to be 50 too, so the size the
     * request names is kept and reused when a later request names none. Six screens carried a
     * copy of this; the only thing they disagreed on was what counted as a usable number.
     *
     * The value is written back to the request, because the view and the paging links read the
     * parameter rather than being handed the answer.
     *
     * @param int    $default
     * @param string $cookie
     * @param string $name
     *
     * @return int
     */
    public static function rememberedLength(
        int $default = self::DEFAULT_LENGTH,
        string $cookie = 'listing_iDisplayLength',
        string $name = 'iDisplayLength'
    ): int {
        $raw = Params::getParam($name);

        if (is_numeric($raw) && (int) $raw >= 1) {
            $length = min((int) $raw, self::MAX_LENGTH);
            \Cookie::newInstance()->push($cookie, $length);
            \Cookie::newInstance()->set();
        } else {
            $stored = \Cookie::newInstance()->get_value($cookie);
            $length = (is_numeric($stored) && (int) $stored >= 1)
                ? min((int) $stored, self::MAX_LENGTH)
                : self::length($default, $name);
        }

        Params::setParam($name, $length);

        return $length;
    }

    /**
     * The row offset a page starts at.
     *
     * @param int $page   1-based
     * @param int $length
     *
     * @return int
     */
    public static function start(int $page, int $length): int
    {
        return max(0, ($page - 1) * $length);
    }
}
