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

use mindstellar\currency\CurrencyService;

/**
 * A line's tax as the live receipt and admin screens print it: the same shape a frozen document
 * line carries under `tax`, with the amounts and the rate written out.
 */
final class LineTaxView
{
    /**
     * @return array{included:bool,base:int,amount:int,code:?string,parts:list<array<string,mixed>>,base_text:string,amount_text:string,rate_text:string}
     */
    public static function describe(LineTax $tax, string $currency): array
    {
        $parts = array();
        foreach ($tax->parts as $part) {
            $parts[] = array('label' => $part['label'], 'bp' => $part['bp'], 'amount' => $part['amount']);
        }

        return self::shape($tax->included, $tax->base, $tax->code, $parts, $currency);
    }

    /**
     * The described shape from its pieces; each part keeps its own keys and gains `amount_text`.
     * The amount is the sum of the parts.
     *
     * @param list<array<string,mixed>&array{bp:int,amount:int}> $parts
     *
     * @return array{included:bool,base:int,amount:int,code:?string,parts:list<array<string,mixed>>,base_text:string,amount_text:string,rate_text:string}
     */
    public static function shape(bool $included, int $base, ?string $code, array $parts, string $currency): array
    {
        $money = CurrencyService::make();
        $sum   = 0;
        foreach ($parts as $i => $part) {
            $sum += $part['amount'];
            $parts[$i]['amount_text'] = $money->money($part['amount'], $currency, false, true);
        }

        return array(
            'included'    => $included,
            'base'        => $base,
            'amount'      => $sum,
            'code'        => $code,
            'parts'       => $parts,
            'base_text'   => $money->money($base, $currency, false, true),
            'amount_text' => $money->money($sum, $currency, false, true),
            'rate_text'   => self::rateText($parts),
        );
    }

    /**
     * The rates of a line's parts: "18%" or "9% + 9%".
     *
     * @param array<int,array<string,mixed>> $parts each with a `bp` key
     */
    public static function rateText(array $parts): string
    {
        $rates = array();
        foreach ($parts as $part) {
            $rates[] = rtrim(rtrim(number_format(((int) ($part['bp'] ?? 0)) / 100, 2, '.', ''), '0'), '.') . '%';
        }

        return implode(' + ', $rates);
    }
}
