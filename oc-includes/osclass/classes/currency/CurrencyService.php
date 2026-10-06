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

namespace mindstellar\currency;

use Currency;
use mindstellar\admin\AdminText;
use mindstellar\cache\CacheGroup;
use mindstellar\validation\ConflictException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;

/**
 * Currency reads and writes for the currencies screen and the API: the enabled list, add one,
 * rename it or change its symbol, and delete one that neither the site nor a listing uses.
 * Each change purges the page cache.
 */
final class CurrencyService
{
    /** MySQL's ER_DUP_ENTRY. */
    private const DUPLICATE_KEY = 1062;

    public function __construct(private Currency $currencies)
    {
    }

    public static function make(): self
    {
        return new self(Currency::getInstance());
    }

    /**
     * The currency's row, or null.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $code): ?array
    {
        $row = osc_db_table(DB_TABLE_PREFIX . 't_currency')->where('pk_c_code', $code)->first();

        return $row === null ? null : osc_db_stringify_row($row);
    }

    /**
     * The enabled currencies by code, cached in the `currency` group.
     *
     * @return array<int,array<string,mixed>>
     */
    public function enabled(): array
    {
        return CacheGroup::remember('currency', 'enabled', static function (): ?array {
            return osc_db_stringify_rows(osc_db_table(DB_TABLE_PREFIX . 't_currency')->where('b_enabled', 1)->orderBy('pk_c_code')->get());
        }) ?? [];
    }

    /**
     * @param string $code three letters, stored in capitals
     *
     * @return string the code as stored
     * @throws InvalidException for a code that is not three letters
     * @throws ConflictException when a currency with this code exists
     * @throws \RuntimeException when the row is not written
     */
    public function create(string $code, string $name, string $symbol): string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw new InvalidException('/code', 'pattern', _m('The currency code is not in the correct format'));
        }
        if ($this->find($code) !== null) {
            throw new ConflictException(_m('A currency with this code already exists.'));
        }
        $saved = $this->currencies->insert([
            'pk_c_code'     => $code,
            's_name'        => AdminText::clean($name),
            's_description' => AdminText::clean($symbol),
        ]);
        if (!$saved) {
            // Another request added the same code between the check and this insert.
            throw (int) $this->currencies->getErrorLevel() === self::DUPLICATE_KEY
                ? new ConflictException(_m('A currency with this code already exists.'))
                : new \RuntimeException('The currency could not be saved.');
        }
        osc_purge_page_cache('currency');

        return $code;
    }

    /**
     * Rename a currency or change its symbol; null keeps the stored value.
     *
     * @return bool whether anything changed
     * @throws NotFoundException
     */
    public function update(string $code, ?string $name, ?string $symbol): bool
    {
        $row     = $this->find($code) ?? throw new NotFoundException(_m('No such currency.'));
        $changed = (int) $this->currencies->update([
            's_name'        => AdminText::clean($name ?? $row['s_name']),
            's_description' => AdminText::clean($symbol ?? $row['s_description']),
        ], ['pk_c_code' => $code]) > 0;
        if ($changed) {
            osc_purge_page_cache('currency');
        }

        return $changed;
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException for the site's default currency, or one a listing is priced in
     */
    public function delete(string $code): void
    {
        if ($this->find($code) === null) {
            throw new NotFoundException(_m('No such currency.'));
        }
        if (strcasecmp($code, (string) osc_currency()) === 0) {
            throw new ConflictException(_m('This is the site\'s default currency.'));
        }
        if (osc_db_table(DB_TABLE_PREFIX . 't_item')->where('fk_c_currency_code', $code)->count() > 0) {
            throw new ConflictException(_m('Listings are priced in this currency.'));
        }
        if ((int) $this->currencies->delete(['pk_c_code' => $code]) <= 0) {
            throw new \RuntimeException('The currency could not be deleted.');
        }
        osc_purge_page_cache('currency');
    }
}
