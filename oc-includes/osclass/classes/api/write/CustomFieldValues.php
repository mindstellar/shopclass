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

namespace mindstellar\api\write;

use mindstellar\api\ProblemException;
use mindstellar\fields\FieldQuery;
use mindstellar\utility\DateInput;
use Params;

/**
 * Custom field values as the listing form would post them: only the target category's
 * fields, each in the shape its type takes, purified as the form's request values are.
 */
final class CustomFieldValues
{
    /** @var \Closure(int): array<int,array<string,mixed>> */
    private \Closure $definitions;

    /**
     * @param \Closure|null $definitions fn(category id) => its field rows; FieldQuery::forCategory() by default
     */
    public function __construct(?\Closure $definitions = null)
    {
        $this->definitions = $definitions ?? static fn (int $categoryId): array => FieldQuery::forCategory($categoryId);
    }

    /**
     * @param array<int|string,mixed> $meta field id => value
     *
     * @return array<int,mixed> the values the category's fields take; a category with no
     *                          fields takes none
     */
    public function clean(int $categoryId, array $meta): array
    {
        if ($meta === []) {
            return [];
        }
        $types = [];
        foreach (($this->definitions)($categoryId) as $field) {
            $types[(int) $field['pk_i_id']] = (string) ($field['e_type'] ?? 'TEXT');
        }
        $out = [];
        foreach ($meta as $id => $value) {
            $type = $types[(int) $id] ?? null;
            if ($type === null || !ctype_digit((string) $id)) {
                continue;
            }
            $value = self::shaped($type, $value, '/custom_fields/' . $id);
            if ($value !== null) {
                $out[(int) $id] = Params::purifyText($value);
            }
        }

        return $out;
    }

    /**
     * A date range is {from, to}; every other type one value. Dates arrive as `2026-01-31` or an
     * RFC 3339 date-time and are kept as the Unix time the field stores.
     *
     * @return string|array{from:string,to:string}|null null for a value of the wrong shape
     * @throws ProblemException 422 for a date that cannot be read
     */
    private static function shaped(string $type, mixed $value, string $pointer): string|array|null
    {
        if ($type === 'DATEINTERVAL') {
            if (!is_array($value)) {
                return null;
            }
            $range = [];
            foreach (['from', 'to'] as $end) {
                $range[$end] = self::date(is_scalar($value[$end] ?? null) ? (string) $value[$end] : '', $pointer . '/' . $end);
            }

            return $range;
        }
        if (!is_scalar($value)) {
            return null;
        }

        return $type === 'DATE' ? self::date((string) $value, $pointer) : (string) $value;
    }

    /**
     * @throws ProblemException 422 for a date that cannot be read
     */
    private static function date(string $value, string $pointer): string
    {
        $value = trim($value);
        if ($value === '' || ctype_digit($value)) {
            // Empty clears it; digits are the Unix time a stored value already holds.
            return $value;
        }
        $date = DateInput::parse($value);
        if ($date === null) {
            throw ProblemException::field($pointer, 'format', 'must be a date, as 2026-01-31, or an RFC 3339 date-time');
        }

        return (string) $date->getTimestamp();
    }
}
