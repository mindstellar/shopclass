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
     * @return array{included:bool,base:int,amount:int,code:?string,parts:list<array{label:string,bp:int,amount:int,amount_text:string}>,base_text:string,amount_text:string,rate_text:string}
     */
    public static function describe(LineTax $tax, string $currency): array
    {
        $money = CurrencyService::make();
        $parts = array();
        foreach ($tax->parts as $part) {
            $parts[] = array(
                'label'       => $part['label'],
                'bp'          => $part['bp'],
                'amount'      => $part['amount'],
                'amount_text' => $money->money($part['amount'], $currency, false, true),
            );
        }

        return array(
            'included'    => $tax->included,
            'base'        => $tax->base,
            'amount'      => $tax->amount,
            'code'        => $tax->code,
            'parts'       => $parts,
            'base_text'   => $money->money($tax->base, $currency, false, true),
            'amount_text' => $money->money($tax->amount, $currency, false, true),
            'rate_text'   => self::rateText($tax->parts),
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
