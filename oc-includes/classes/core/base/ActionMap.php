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
 * A controller that answers each action from its own method. The class lists them in a
 * `private const ACTIONS` of action => method name.
 */
trait ActionMap
{
    /**
     * The method for this request's action, or $default for an action the map does not name.
     */
    private function actionMethod(?string $default): ?string
    {
        return is_string($this->action) ? (self::ACTIONS[$this->action] ?? $default) : $default;
    }
}
