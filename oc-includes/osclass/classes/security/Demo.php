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

namespace mindstellar\security;

/**
 * A demo install (the DEMO constant): admin actions that change the site are refused with one message.
 */
final class Demo
{
    private function __construct()
    {
    }

    public static function active(): bool
    {
        return defined('DEMO');
    }

    /**
     * The refusal an admin sees, as a flash message or a JSON error.
     */
    public static function message(): string
    {
        return _m('This action cannot be done because it is a demo site');
    }
}
