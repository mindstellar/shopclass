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

namespace mindstellar\billing\event;

use mindstellar\event\Bridged;
use mindstellar\event\Event;

/**
 * The admin order screen asks which carriers the shop uses, to suggest them in the Carrier box.
 * A listener adds names. Old callbacks on `admin_billing_carriers` get ($names) and return the list.
 */
final class CarriersListing extends Event implements Bridged
{
    /** @var list<string> */
    private array $names = [];

    /**
     * Add a carrier name. A blank name or one already listed is left out.
     */
    public function add(string $name): void
    {
        $name = mb_substr(trim($name), 0, 100, 'UTF-8');
        if ($name !== '' && !in_array($name, $this->names, true)) {
            $this->names[] = $name;
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    public static function legacyNames(): array
    {
        return ['admin_billing_carriers'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $found = $fn($this->names);
        if (!is_array($found)) {
            return;
        }
        $this->names = [];
        foreach ($found as $one) {
            if (is_string($one)) {
                $this->add($one);
            }
        }
    }
}
