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

namespace mindstellar\event;

/**
 * An event that owns old hook names. A callback added with osc_add_hook() or osc_add_filter()
 * on one of them runs as a listener of this event, through applyLegacy().
 */
interface Bridged
{
    /**
     * The hook names this event owns.
     *
     * @return list<string>
     */
    public static function legacyNames(): array;

    /**
     * Call an old-style callback added on $name with that hook's arguments, and fold what it returns into the event.
     */
    public function applyLegacy(string $name, callable $fn): void;
}
