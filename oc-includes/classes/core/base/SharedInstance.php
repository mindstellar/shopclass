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

namespace mindstellar\base;

/**
 * One shared object per class, made on first use. Each using class gets its own private static
 * $instance, and a subclass shares its parent's object. getInstance() has no return type, so a
 * plugin subclass that overrides it keeps working.
 */
trait SharedInstance
{
    /** @var self|null */
    private static $instance;

    /**
     * The shared object, made on first call.
     *
     * @return self
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
