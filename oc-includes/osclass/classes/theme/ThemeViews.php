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

namespace mindstellar\theme;

/**
 * The view-name vocabulary: names core asks a theme for, plus whatever the theme
 * declares on top through osc_add_theme_support('views', …).
 *
 * A static page's internal name becomes a URL segment, so a page slugged
 * "contact" would shadow the contact route. The reserved set is what the admin
 * page editor refuses.
 *
 * CORE is the list core itself requests and is deliberately frozen: adding a
 * name silently forbids a slug that sites are already using, removing one lets a
 * page shadow a route.
 *
 * @package mindstellar\theme
 */
final class ThemeViews
{
    /**
     * View names core asks any theme for, whatever that theme actually ships.
     *
     * @var string[]
     */
    public const CORE = [
        '404',
        'contact',
        'alert-form',
        'custom',
        'footer',
        'functions',
        'head',
        'header',
        'inc.search',
        'index',
        'item-contact',
        'item-edit',
        'item-post',
        'item-send-friend',
        'item',
        'main',
        'page',
        'search',
        'search_gallery',
        'search_list',
        'user-alerts',
        'user-change_email',
        'user-change_password',
        'user-dashboard',
        'user-delete_account',
        'user-forgot_password',
        'user-items',
        'user-login',
        'user-profile',
        'user-recover',
        'user-register',
    ];

    /**
     * A view name as the vocabulary holds it: no directory, no .php.
     * Comparison stays case-sensitive, as it has always been.
     */
    public static function normalize(string $name): string
    {
        $name = trim($name);
        $name = str_replace('\\', '/', $name);
        $at   = strrpos($name, '/');
        if ($at !== false) {
            $name = substr($name, $at + 1);
        }
        if (substr($name, -4) === '.php') {
            $name = substr($name, 0, -4);
        }

        return $name;
    }

    /**
     * Normalized names out of whatever a theme passed to
     * osc_add_theme_support('views', …). A bare true, a string, or a list of
     * strings; anything else declares nothing.
     *
     * @param mixed $declared
     *
     * @return string[]
     */
    public static function declared($declared): array
    {
        if (is_string($declared)) {
            $declared = [$declared];
        }
        if (!is_array($declared)) {
            return [];
        }

        $names = [];
        foreach ($declared as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = self::normalize($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * CORE plus the theme's declarations, in that order, deduplicated.
     *
     * @param mixed $declared raw osc_theme_supports('views') value
     *
     * @return string[]
     */
    public static function reserved($declared): array
    {
        return array_values(array_unique(array_merge(self::CORE, self::declared($declared))));
    }
}
