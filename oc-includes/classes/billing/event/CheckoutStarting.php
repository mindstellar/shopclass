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

    /** @var array<string,int> the longest value of each asked field */
    private array $limits = [];

    /**
     * @param array<string,mixed>                      $cart   its `fields` holds the buyer's value of each asked field
     * @param list<array{id:string,max:int}>           $fields the fields the checkout asked for
     */
    public function __construct(array $cart, array $fields = [])
    {
        parent::__construct($cart);
        foreach ($fields as $field) {
            $this->limits[$field['id']] = $field['max'];
        }
    }

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
     * The buyer's value of an asked checkout field; empty when none was given or the field is not asked for.
     */
    public function field(string $id): string
    {
        $value = $this->cart['fields'][$id] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Replace the value of an asked field, for example to upper-case a tax number. A value longer than
     * the field allows is cut.
     *
     * @throws \InvalidArgumentException when the checkout does not ask for that field
     */
    public function setField(string $id, string $value): void
    {
        if (!isset($this->cart['fields']) || !is_array($this->cart['fields']) || !array_key_exists($id, $this->cart['fields'])) {
            throw new \InvalidArgumentException(sprintf('The checkout asks for no field "%s".', $id));
        }
        $this->cart['fields'][$id] = mb_substr(CheckoutFields::value($value), 0, $this->limits[$id] ?? CheckoutFields::MAX_VALUE, 'UTF-8');
    }

    /**
     * The values of the asked fields, by id.
     *
     * @return array<string,string>
     */
    public function fields(): array
    {
        return array_map('strval', array_filter(is_array($this->cart['fields'] ?? null) ? $this->cart['fields'] : [], 'is_string'));
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
