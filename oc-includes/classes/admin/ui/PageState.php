<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\ui;

/**
 * What one admin request has drawn of its page title: the title the header drew in its row,
 * and whether any page title is drawn yet. Heading levels below the title are picked from it.
 */
final class PageState
{
    private static ?string $headerTitle = null;
    private static bool $titled = false;

    /** Records the title the header drew as the page's h1. */
    public static function setHeaderTitle(string $title): void
    {
        self::$headerTitle = $title;
        self::$titled      = true;
    }

    /** The title the header drew, or null when it drew none. */
    public static function headerTitle(): ?string
    {
        return self::$headerTitle;
    }

    /** Records that the page title is drawn. */
    public static function markTitled(): void
    {
        self::$titled = true;
    }

    /** Whether the page title is drawn yet. */
    public static function titled(): bool
    {
        return self::$titled;
    }
}
