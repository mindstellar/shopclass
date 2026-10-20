<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\admin;

/**
 * The admin pages a moderator may open. Plugins change the list on `moderator_access`; the
 * admin panel and the API both read it from here.
 */
final class ModeratorAccess
{
    public const DEFAULT_PAGES = ['items', 'comments', 'media', 'login', 'admins', 'ajax', 'stats', ''];

    private function __construct()
    {
    }

    /**
     * @return string[]
     */
    public static function pages(): array
    {
        $pages = osc_apply_filter('moderator_access', self::DEFAULT_PAGES);

        return is_array($pages) ? array_values(array_map('strval', $pages)) : self::DEFAULT_PAGES;
    }
}
