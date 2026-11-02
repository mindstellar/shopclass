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

namespace mindstellar\widgets\event;

use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * The customiser published a theme's draft. Old name: customiser_published.
 */
final class CustomiserPublished extends Event implements Bridged
{
    public function __construct(private string $theme)
    {
    }

    public static function legacyNames(): array
    {
        return ['customiser_published'];
    }

    public function theme(): string
    {
        return $this->theme;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->theme);
    }
}
