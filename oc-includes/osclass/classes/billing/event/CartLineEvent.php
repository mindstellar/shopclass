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
 * Base of the shipping, tax and discount line events. Lines are added in that order, so the tax
 * listeners see the shipping line.
 */
abstract class CartLineEvent extends CartEvent implements Bridged
{
    /** @var mixed the lines as arrays; whatever an old filter returned is kept for core to check */
    private $lines;

    /**
     * @param array<string,mixed>            $cart
     * @param array<int,array<string,mixed>> $lines
     */
    public function __construct(array $cart, array $lines = [])
    {
        parent::__construct($cart);
        $this->lines = $lines;
    }

    /**
     * The line kind: shipping, tax or discount.
     */
    abstract protected function kind(): string;

    /**
     * @throws \InvalidArgumentException when the line breaks the rules of its kind
     */
    public function addLine(Line $line): void
    {
        $this->lines   = $this->arrays();
        $this->lines[] = $this->accept($line, $this->kind());
    }

    public function removeLine(string $id): void
    {
        $this->lines = array_values(array_filter($this->arrays(), static fn ($line): bool => !is_array($line) || ($line['id'] ?? null) !== $id));
    }

    /**
     * @return list<Line>
     */
    public function lines(): array
    {
        $out = [];
        foreach ($this->arrays() as $line) {
            $line = Line::fromArray($line, (string) ($this->cart['currency'] ?? ''));
            if ($line !== null) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The lines as the old filters see and return them.
     *
     * @return mixed
     */
    public function rawLines()
    {
        return $this->lines;
    }

    /**
     * @param mixed $lines
     */
    public function replaceLines($lines): void
    {
        $this->lines = $lines;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->lines = $fn($this->lines, $this->cart);
    }

    /**
     * @return array<int,mixed>
     */
    private function arrays(): array
    {
        return is_array($this->lines) ? $this->lines : [];
    }
}
