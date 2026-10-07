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

namespace mindstellar\api\controller\admin;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\Response;
use mindstellar\api\serializer\LocationSerializer;
use mindstellar\currency\CurrencyService;
use mindstellar\validation\NotFoundException;

/**
 * `/admin/currencies`: the currency screen's writes, through CurrencyService.
 */
final class AdminCurrenciesController
{
    private CurrencyService $currencies;

    public function __construct(private ApiServices $api)
    {
        $this->currencies = $api->currencyService();
    }

    /**
     * POST /admin/currencies
     */
    public function create(ApiCall $call): Response
    {
        $input = $call->input();
        $code  = $this->currencies->create((string) $input['code'], (string) $input['name'], (string) ($input['symbol'] ?? ''));

        return Response::created($this->currency($code), $this->api->links()->api('admin/currencies/' . $code));
    }

    /**
     * GET /admin/currencies/{code}
     */
    public function show(ApiCall $call): Response
    {
        $code = self::code($call->args());
        if ($this->currencies->find($code) === null) {
            throw new NotFoundException(_m('No such currency.'));
        }

        return Response::ok($this->currency($code));
    }

    /**
     * PATCH /admin/currencies/{code}
     */
    public function update(ApiCall $call): Response
    {
        $code  = self::code($call->args());
        $input = $call->input();
        $this->currencies->update(
            $code,
            array_key_exists('name', $input) ? (string) $input['name'] : null,
            array_key_exists('symbol', $input) ? (string) $input['symbol'] : null
        );

        return Response::ok($this->currency($code));
    }

    /**
     * DELETE /admin/currencies/{code}: not the site's default, nor one a listing is priced in.
     */
    public function delete(ApiCall $call): Response
    {
        $this->currencies->delete(self::code($call->args()));

        return Response::noContent();
    }

    /**
     * @return array<string,mixed>
     */
    private function currency(string $code): array
    {
        return (new LocationSerializer())->currency((array) $this->currencies->find($code));
    }

    /**
     * @param array<string,string> $args
     *
     * @throws NotFoundException for anything but a three-letter code
     */
    private static function code(array $args): string
    {
        $code = strtoupper((string) ($args['code'] ?? ''));
        if (preg_match('/^[A-Z]{3}$/D', $code) !== 1) {
            throw new NotFoundException(_m('No such currency.'));
        }

        return $code;
    }
}
