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

use mindstellar\billing\CartPricer;
use mindstellar\event\Bridged;

/**
 * Reasons the cart cannot be ordered yet. Old filter: `cart_problems`.
 */
final class CartProblems extends CartEvent implements Bridged
{
    /** @var mixed list of {code, pointer, message} */
    private $problems = [];

    public static function legacyNames(): array
    {
        return ['cart_problems'];
    }

    /**
     * @throws \InvalidArgumentException when the code, pointer or message breaks CartPricer's rules
     */
    public function add(string $code, string $pointer, string $message): void
    {
        $entry = ['code' => $code, 'pointer' => $pointer, 'message' => $message];
        if (CartPricer::problems([$entry]) === []) {
            throw new \InvalidArgumentException(sprintf('The cart problem "%s" is not well formed.', $code));
        }
        $this->problems   = is_array($this->problems) ? $this->problems : [];
        $this->problems[] = $entry;
    }

    /**
     * The problems as the old filter sees and returns them.
     *
     * @return mixed
     */
    public function rawProblems()
    {
        return $this->problems;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->problems = $fn($this->problems, $this->cart);
    }
}
