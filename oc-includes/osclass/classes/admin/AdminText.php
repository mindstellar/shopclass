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

namespace mindstellar\admin;

use mindstellar\validation\InvalidException;
use Params;

/**
 * Names and texts an admin endpoint stores, cleaned as the admin screens clean theirs:
 * every tag out, then trimmed. Text that did not come through Params, such as an API body,
 * gets what reading it from the form would have done.
 */
final class AdminText
{
    private function __construct()
    {
    }

    public static function clean(mixed $value): string
    {
        return trim((string) Params::purifyText((string) $value));
    }

    /**
     * @throws InvalidException when nothing is left once cleaned
     */
    public static function name(mixed $value, string $pointer): string
    {
        $name = self::clean($value);
        if ($name === '') {
            throw new InvalidException($pointer, 'minLength', _m('must not be blank'));
        }

        return $name;
    }

    /**
     * A dropdown or radio field's choices as the field screen stores them: one
     * comma-separated string. A comma would split a choice in two, so it is refused.
     *
     * @param array<int,mixed> $options
     *
     * @throws InvalidException for a blank choice or one with a comma
     */
    public static function options(array $options): string
    {
        $out = [];
        foreach (array_values($options) as $i => $option) {
            $text = self::name($option, '/options/' . $i);
            if (str_contains($text, ',')) {
                throw new InvalidException('/options/' . $i, 'pattern', _m('must not contain a comma'));
            }
            $out[] = $text;
        }

        return implode(',', $out);
    }
}
