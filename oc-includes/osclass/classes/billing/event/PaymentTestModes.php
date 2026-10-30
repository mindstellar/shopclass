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
 * The admin asks which payment methods take no real money yet, to show one test-mode notice.
 * A listener adds its name and settings link. Old callbacks on `admin_payment_test_modes`
 * get (array name => settings url) and return it.
 */
final class PaymentTestModes extends Event implements Bridged
{
    /** @var array<string,string> */
    private array $modes = [];

    /**
     * Add a payment method in test mode. A blank name is left out.
     */
    public function add(string $name, string $settingsUrl = ''): void
    {
        $name = mb_substr(trim($name), 0, 60, 'UTF-8');
        if ($name !== '') {
            $this->modes[$name] = $settingsUrl;
        }
    }

    /**
     * @return array<string,string> name => settings url
     */
    public function all(): array
    {
        return $this->modes;
    }

    public static function legacyNames(): array
    {
        return ['admin_payment_test_modes'];
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $found = $fn($this->modes);
        if (!is_array($found)) {
            return;
        }
        $this->modes = [];
        foreach ($found as $one => $url) {
            if (is_string($one)) {
                $this->add($one, is_string($url) ? $url : '');
            }
        }
    }
}
