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

namespace mindstellar\utility;

/**
 * Puts a value in the View only while a callback runs, then restores what was there. E-mail
 * hooks read the listing or user through osc_item_*() and osc_user_*(), which read the View;
 * this lets a service fire them without leaving its row behind for the rest of the request.
 */
final class ViewScope
{
    /** Keys the osc_item_*() helpers cache for the current listing. */
    private const ITEM_CACHE = ['resources', 'metafields', 'item_category'];

    private function __construct()
    {
    }

    /**
     * Run $fn with $item as the View's listing.
     *
     * @param array<string,mixed> $item
     */
    public static function withItem(array $item, callable $fn): mixed
    {
        return self::run(['item' => $item], self::ITEM_CACHE, $fn);
    }

    /**
     * Run $fn with $value exported under $key.
     */
    public static function with(string $key, mixed $value, callable $fn): mixed
    {
        return self::run([$key => $value], [], $fn);
    }

    /**
     * @param array<string,mixed> $set   exported for the call
     * @param string[]            $clear erased for the call
     */
    private static function run(array $set, array $clear, callable $fn): mixed
    {
        $view  = \View::getInstance();
        $saved = [];
        foreach (array_merge(array_keys($set), $clear) as $key) {
            $saved[$key] = $view->_exists($key) ? [$view->_get($key)] : null;
        }
        foreach ($clear as $key) {
            $view->_erase($key);
        }
        foreach ($set as $key => $value) {
            $view->_exportVariableToView($key, $value);
        }
        try {
            return $fn();
        } finally {
            foreach ($saved as $key => $previous) {
                if ($previous === null) {
                    $view->_erase($key);
                } else {
                    $view->_exportVariableToView($key, $previous[0]);
                }
            }
        }
    }
}
