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

namespace mindstellar\api\serializer;

use mindstellar\search\AlertEnvelope;

/**
 * A saved search, with its filters in the names `GET /listings` takes.
 */
final class AlertSerializer
{
    /** Stored search key => API filter. */
    private const FILTERS = [
        'sPattern'  => 'q',
        'sCategory' => 'category',
        'sCountry'  => 'country',
        'sRegion'   => 'region',
        'sCity'     => 'city',
        'sCityArea' => 'city_area',
        'sUser'     => 'user',
        'sLocale'   => 'locale',
        'sPriceMin' => 'price_min',
        'sPriceMax' => 'price_max',
        'bPic'      => 'with_photos',
        'bPremium'  => 'premium',
        'meta'      => 'field',
    ];

    /**
     * @param array<string,mixed> $row a t_alerts row
     *
     * @return array<string,mixed>
     */
    public function one(array $row): array
    {
        $params  = AlertEnvelope::validate((string) ($row['s_search'] ?? '')) ?? [];
        $filters = [];
        foreach (self::FILTERS as $key => $name) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value          = $params[$key];
            $filters[$name] = match ($key) {
                'bPic', 'bPremium'       => (bool) $value,
                'sPattern'               => (string) $value,
                'sPriceMin', 'sPriceMax' => (int) $value,
                'meta'                   => (array) $value,
                default                  => array_values((array) $value),
            };
        }

        return [
            'id'         => Format::int($row['pk_i_id'] ?? 0),
            'filters'    => $filters,
            'type'       => strtolower((string) ($row['e_type'] ?? '')),
            'active'     => Format::bool($row['b_active'] ?? 0),
            'created_at' => Format::time($row['dt_date'] ?? null),
        ];
    }
}
