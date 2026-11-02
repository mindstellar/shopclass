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

namespace mindstellar\billing;

use InvalidArgumentException;

/**
 * The tax on one item or shipping line: its taxable base, the parts it pays to each order tax line
 * and an optional tax code (HSN). A breakdown of the order's tax lines, never a source of totals.
 */
final readonly class LineTax
{
    public const MAX_PARTS = 4;

    public const MAX_LABEL = 60;

    public const MAX_CODE = 40;

    /** The sum of the parts' amounts, in micros. */
    public int $amount;

    /** @var list<array{tax:string,label:string,bp:int,amount:int}> */
    public array $parts;

    public ?string $code;

    /**
     * @param array<int,mixed> $parts {tax (the order tax line id), label, bp 0..10000, amount >= 0}, 1 to MAX_PARTS
     *
     * @throws InvalidArgumentException when a part or the code breaks these rules
     */
    public function __construct(public bool $included, public int $base, array $parts, ?string $code = null)
    {
        if ($base < 0) {
            throw new InvalidArgumentException('A line tax base is below zero.');
        }
        if ($parts === array() || count($parts) > self::MAX_PARTS) {
            throw new InvalidArgumentException(sprintf('A line tax has 1 to %d parts.', self::MAX_PARTS));
        }
        $clean = array();
        $sum   = 0;
        foreach (array_values($parts) as $part) {
            $tax    = is_array($part) ? ($part['tax'] ?? null) : null;
            $label  = is_array($part) && is_string($part['label'] ?? null) ? trim($part['label']) : '';
            $bp     = is_array($part) ? ($part['bp'] ?? null) : null;
            $amount = is_array($part) ? ($part['amount'] ?? null) : null;
            if (!is_string($tax) || preg_match('/^[a-z0-9_.-]{1,64}$/', $tax) !== 1 || isset($clean[$tax])) {
                throw new InvalidArgumentException('A line tax part needs its own tax line id.');
            }
            if ($label === '' || !is_int($bp) || $bp < 0 || $bp > 10000 || !is_int($amount) || $amount < 0) {
                throw new InvalidArgumentException(sprintf('The line tax part "%s" needs a label, a rate of 0 to 10000 bp and an amount of zero or more.', $tax));
            }
            $clean[$tax] = array('tax' => $tax, 'label' => mb_substr($label, 0, self::MAX_LABEL, 'UTF-8'), 'bp' => $bp, 'amount' => $amount);
            $sum += $amount;
        }
        $code = $code === null ? null : trim($code);
        if ($code !== null && (mb_strlen($code, 'UTF-8') > self::MAX_CODE || preg_match('/[\x00-\x1F\x7F]/', $code) === 1)) {
            throw new InvalidArgumentException(sprintf('A tax code is at most %d characters of plain text.', self::MAX_CODE));
        }
        $this->parts  = array_values($clean);
        $this->amount = $sum;
        $this->code   = $code === '' ? null : $code;
    }

    /**
     * The line tax an array (as toArray() wrote it) describes, or null when it is not one. A stored
     * amount that is not the sum of its parts is not one.
     *
     * @param mixed $value
     */
    public static function fromArray($value): ?self
    {
        if (!is_array($value) || !is_bool($value['included'] ?? null) || !is_int($value['base'] ?? null) || !is_array($value['parts'] ?? null)) {
            return null;
        }
        $code = $value['code'] ?? null;
        try {
            $tax = new self($value['included'], $value['base'], $value['parts'], is_string($code) ? $code : null);
        } catch (InvalidArgumentException $e) {
            return null;
        }

        return isset($value['amount']) && $value['amount'] !== $tax->amount ? null : $tax;
    }

    /**
     * @return array{included:bool,base:int,amount:int,code:?string,parts:list<array{tax:string,label:string,bp:int,amount:int}>}
     */
    public function toArray(): array
    {
        return array('included' => $this->included, 'base' => $this->base, 'amount' => $this->amount, 'code' => $this->code, 'parts' => $this->parts);
    }
}
