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

namespace mindstellar\api\read;

use mindstellar\security\SignedPayload;

/**
 * Opaque paging cursors: a SignedPayload, versioned, tied to the filters they were made for
 * and good for a week. Clients only follow `links.next`, so the paging kind
 * behind a cursor can change later without a client change.
 */
final class Cursor
{
    public const VERSION = 1;

    /** Sorts that page by keyset; every other sort pages by offset. */
    private const KEYSET_SORTS = ['created', 'id', 'price'];

    /** Seconds a cursor stays good. */
    public const TTL = 604800;

    /** The expiry is rounded up to this many seconds, so a page links to the same cursor for an hour. */
    private const ROUND = 3600;

    private const PURPOSE = 'api-cursor';

    public static function modeFor(string $sort): string
    {
        return in_array($sort, self::KEYSET_SORTS, true) ? CursorState::KEYSET : CursorState::OFFSET;
    }

    /**
     * A stable hash of the filters a list was asked with. Paging values are left out.
     *
     * @param array<string,mixed> $filters
     */
    public static function filterHash(array $filters): string
    {
        unset($filters['cursor'], $filters['limit'], $filters['count'], $filters['fields'], $filters['include'], $filters['api_key']);

        return substr(hash('sha256', (string) json_encode(self::sortDeep($filters))), 0, 16);
    }

    public function encode(CursorState $state): string
    {
        $data = ['v' => self::VERSION, 'k' => $state->kind(), 's' => $state->sort(), 'd' => $state->direction(), 'h' => $state->filterHash()];
        if ($state->kind() === CursorState::KEYSET) {
            $data['a'] = $state->after();
        } else {
            $data['o'] = $state->offsetValue();
        }
        return SignedPayload::pack(self::PURPOSE, $data, self::TTL, self::ROUND);
    }

    /**
     * The state inside a cursor, or null when this request cannot use it: forged, malformed,
     * expired, another version, made for other filters, a sort not allowed here, or an offset past
     * the cap.
     *
     * @param string   $hash      filterHash() of the request
     * @param string[] $sorts     the sorts this list allows
     * @param int      $maxOffset the deepest offset this list pages to
     */
    public function decode(string $cursor, string $hash, array $sorts, int $maxOffset): ?CursorState
    {
        $state = strlen($cursor) > 1024 ? null : SignedPayload::unpack(self::PURPOSE, $cursor);
        if (!is_array($state)
            || ($state['v'] ?? null) !== self::VERSION
            || !in_array($state['s'] ?? null, $sorts, true)
            || !in_array($state['d'] ?? null, ['asc', 'desc'], true)
            || !is_string($state['h'] ?? null) || !hash_equals($hash, $state['h'])
            || ($state['k'] ?? null) !== self::modeFor($state['s'])
        ) {
            return null;
        }

        if ($state['k'] === CursorState::OFFSET) {
            $offset = $state['o'] ?? null;

            return is_int($offset) && $offset >= 0 && $offset <= $maxOffset
                ? CursorState::offset($state['s'], $state['d'], $hash, $offset)
                : null;
        }

        $after = $state['a'] ?? null;
        if (!is_array($after) || $after === []) {
            return null;
        }
        foreach ($after as $value) {
            if (!is_int($value) && !is_string($value) && $value !== null) {
                return null;
            }
        }

        return CursorState::keyset($state['s'], $state['d'], $hash, $after);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sortDeep(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortDeep($v);
            }
        }

        return $value;
    }
}
