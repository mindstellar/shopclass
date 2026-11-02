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

/**
 * Checkout is about to write the order. A listener may refuse it. Old action: `cart_checkout_before`,
 * where a callback refuses by throwing an InvalidException.
 */
final class CheckoutStarting extends CartEvent implements Bridged
{
    /** @var list<array{pointer:string,code:string,message:string}> */
    private array $refusals = [];

    public static function legacyNames(): array
    {
        return ['cart_checkout_before'];
    }

    /**
     * Refuse the order and stop the listeners that follow.
     */
    public function refuse(string $pointer, string $code, string $message): void
    {
        $this->refusals[] = ['pointer' => $pointer, 'code' => $code, 'message' => $message];
        $this->stopPropagation();
    }

    /**
     * @return list<array{pointer:string,code:string,message:string}>
     */
    public function refusals(): array
    {
        return $this->refusals;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $fn($this->cart);
    }
}
