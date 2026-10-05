<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * API paging cursors: signed, round trip, keyset vs offset per sort, refused when forged,
 * made for other filters, for a sort the list does not allow, or past the offset cap.
 *
 * DB-free.  Usage: php tests/api-cursor.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\read\Cursor;
use mindstellar\api\read\CursorState;
use mindstellar\security\SignedPayload;

define('OSC_CSRF_SECRET', 'api-cursor-test-secret');

$cursor = new Cursor();
$sorts  = ['created', 'id', 'price', 'relevance'];

harness_section('kind per sort');
pin('created pages by keyset', CursorState::KEYSET, Cursor::modeFor('created'));
pin('id pages by keyset', CursorState::KEYSET, Cursor::modeFor('id'));
pin('price pages by offset', CursorState::OFFSET, Cursor::modeFor('price'));
pin('relevance pages by offset', CursorState::OFFSET, Cursor::modeFor('relevance'));

harness_section('round trip');
$hash = Cursor::filterHash(['category' => '3', 'q' => 'bike']);
$key  = $cursor->encode(CursorState::keyset('created', 'desc', $hash, ['2026-10-03 12:00:00', 42]));
check('a cursor is base64url with a signature', preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $key) === 1);
$state = $cursor->decode($key, $hash, $sorts, 1000);
pin('a keyset cursor round trips', ['keyset', 'created', 'desc', ['2026-10-03 12:00:00', 42]], [$state->kind(), $state->sort(), $state->direction(), $state->after()]);
$off   = $cursor->encode(CursorState::offset('price', 'asc', $hash, 40));
$state = $cursor->decode($off, $hash, $sorts, 1000);
pin('an offset cursor round trips', ['offset', 'price', 40], [$state->kind(), $state->sort(), $state->offsetValue()]);

harness_section('expiry window');
$expiry = static fn (string $token): int => (int) (json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true)['x'] ?? 0);
$state  = CursorState::keyset('created', 'desc', $hash, ['2026-10-03 12:00:00', 42]);
$x      = $expiry($cursor->encode($state));
pin('the expiry is on an hour boundary', 0, $x % 3600);
pin('and at least a day away', true, $x >= time() + Cursor::TTL);
// Encoded twice inside one hour; a retry covers the rare run that crosses the boundary.
$same = $cursor->encode($state) === $cursor->encode($state) || $cursor->encode($state) === $cursor->encode($state);
pin('so the same page gives the same cursor', true, $same);
pin('a day stays at most a day and an hour', true, $x <= time() + Cursor::TTL + 3600);

harness_section('filter hash');
pin('the hash ignores key order', $hash, Cursor::filterHash(['q' => 'bike', 'category' => '3']));
pin('and paging params', $hash, Cursor::filterHash(['q' => 'bike', 'category' => '3', 'cursor' => 'x', 'limit' => '5', 'fields' => 'id', 'api_key' => 'k']));
check('other filters hash differently', $hash !== Cursor::filterHash(['category' => '4', 'q' => 'bike']));
pin('a cursor made for other filters is refused', null, $cursor->decode($key, Cursor::filterHash(['category' => '4']), $sorts, 1000));

harness_section('refusals');
pin('a token signed for another purpose is refused', null, $cursor->decode(SignedPayload::pack('report-sender', ['v' => 1, 'k' => 'keyset', 's' => 'id', 'd' => 'asc', 'h' => $hash, 'a' => [1]], 60), $hash, $sorts, 1000));
pin('an expired cursor is refused', null, $cursor->decode(SignedPayload::pack('api-cursor', ['v' => 1, 'k' => 'keyset', 's' => 'id', 'd' => 'asc', 'h' => $hash, 'a' => [1]], -1), $hash, $sorts, 1000));
pin('the same, unexpired, is taken', [1], $cursor->decode(SignedPayload::pack('api-cursor', ['v' => 1, 'k' => 'keyset', 's' => 'id', 'd' => 'asc', 'h' => $hash, 'a' => [1]], 60), $hash, $sorts, 1000)?->after());
[$payload, $sig] = explode('.', $off);
$forged = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'k' => 'offset', 's' => 'price', 'd' => 'asc', 'h' => $hash, 'o' => 999999])), '+/', '-_'), '=');
pin('a changed payload with the old signature is refused', null, $cursor->decode($forged . '.' . $sig, $hash, $sorts, PHP_INT_MAX));
pin('a sort this list does not allow is refused', null, $cursor->decode($off, $hash, ['created'], 1000));
pin('an offset past the cap is refused', null, $cursor->decode($off, $hash, $sorts, 39));
pin('garbage is refused', null, $cursor->decode('!!not-a-cursor!!', $hash, $sorts, 1000));
pin('empty is refused', null, $cursor->decode('', $hash, $sorts, 1000));
pin('an unsigned payload is refused', null, $cursor->decode($payload, $hash, $sorts, 1000));

$sign = static fn (array $state): string => SignedPayload::pack('api-cursor', $state, 60);
pin('another version is refused, even signed', null, $cursor->decode($sign(['v' => 2, 'k' => 'offset', 's' => 'price', 'd' => 'asc', 'h' => $hash, 'o' => 1]), $hash, $sorts, 1000));
pin('a kind that does not fit the sort is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'offset', 's' => 'created', 'd' => 'asc', 'h' => $hash, 'o' => 1]), $hash, $sorts, 1000));
pin('a bad direction is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'offset', 's' => 'price', 'd' => 'up', 'h' => $hash, 'o' => 1]), $hash, $sorts, 1000));
pin('a negative offset is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'offset', 's' => 'price', 'd' => 'asc', 'h' => $hash, 'o' => -5]), $hash, $sorts, 1000));
pin('a string offset is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'offset', 's' => 'price', 'd' => 'asc', 'h' => $hash, 'o' => '5 OR 1']), $hash, $sorts, 1000));
pin('a keyset value that is not a scalar is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'keyset', 's' => 'id', 'd' => 'asc', 'h' => $hash, 'a' => [[1]]]), $hash, $sorts, 1000));
pin('an empty keyset is refused', null, $cursor->decode($sign(['v' => 1, 'k' => 'keyset', 's' => 'id', 'd' => 'asc', 'h' => $hash, 'a' => []]), $hash, $sorts, 1000));

exit(harness_result());
