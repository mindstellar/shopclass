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

/**
 * An admin PATCH body split into its status members, as flags, and the rest.
 */
final class StatusMembers
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed>         $input   the body
     * @param array<string,string> $members API member => flag name
     *
     * @return array{0: array<string,bool>, 1: array<mixed>} the flags and the other members
     */
    public static function split(array $input, array $members): array
    {
        $flags = [];
        foreach (array_intersect_key($input, $members) as $member => $value) {
            $flags[$members[$member]] = (bool) $value;
        }

        return [$flags, array_diff_key($input, $members)];
    }
}
