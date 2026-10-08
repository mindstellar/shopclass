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
 * ApiServices::created() answers 201 with a Location for the new resource in the call's version.
 * Pager::respond() builds self/next links on the list's own path, and a cursor made for one
 * path is refused on another.
 *
 * DB-free.  Usage: php tests/api-created-pager.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\Cursor;
use mindstellar\api\read\ListingSort;
use mindstellar\api\read\Pager;
use mindstellar\api\Request;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\Scopes;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

define('OSC_CSRF_SECRET', 'api-created-pager-test-secret');

$links = new class () implements Links {
    public function listing(array $item): string
    {
        return '';
    }

    public function photo(array $resource, string $variant): string
    {
        return '';
    }

    public function user(int $id, string $username): string
    {
        return '';
    }

    public function avatar(int $userId): string
    {
        return '';
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'https://site.test/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return '';
    }
};

harness_section('ApiServices::created');
$services = new ApiServices(new ApiSettings(true), new Scopes(), new ApiCredential(), api_test_users(), new SystemClock(), api_test_limiter(), links: $links);
$call     = new ApiCall(new Request('POST', 'v1/listings'), Credential::anonymous());
$created  = $services->created($call, ['id' => 42], 'listings/42', ['meta' => ['state' => 'pending']]);

pin('status is 201', 201, $created->status());
check('Location ends with /api/v1/listings/42', str_ends_with((string) $created->header('Location'), '/api/v1/listings/42'));
pin('body carries the data unchanged', ['id' => 42], $created->body()['data'] ?? null);
pin('extra members are kept', ['state' => 'pending'], $created->body()['meta'] ?? null);

harness_section('Pager::respond');
$cursor = new Cursor();
$pager  = static fn (string $path, array $q): Pager => Pager::fromRequest(new Request('GET', 'v1/' . $path, $q), $cursor, ListingSort::of('created', 'desc')->spec(2, 50), $path);
$rows   = [['pk_i_id' => 9, 'dt_pub_date' => '2026-01-03 00:00:00'], ['pk_i_id' => 8, 'dt_pub_date' => '2026-01-02 00:00:00'], ['pk_i_id' => 7, 'dt_pub_date' => '2026-01-01 00:00:00']];
$first  = $pager('users', ['status' => 'active']);
$body   = $first->respond(static fn (): array => $rows, null, static fn (array $page): array => array_column($page, 'pk_i_id'), $links)->body();

pin('the page holds limit rows', [9, 8], $body['data']);
pin('self is the list path with its query', 'https://site.test/api/v1/users?status=active', $body['links']['self']);
check('next is on the list path with its query and a cursor', str_starts_with((string) $body['links']['next'], 'https://site.test/api/v1/users?status=active&cursor='));

parse_str((string) parse_url((string) $body['links']['next'], PHP_URL_QUERY), $nextQuery);
$refused = null;
try {
    $pager('comments', $nextQuery);
} catch (ProblemException $e) {
    $refused = $e->response()->body()['code'] ?? null;
}
pin('a cursor made for users is refused on comments', 'invalid_cursor', $refused);
check('the same cursor works on its own path', $pager('users', $nextQuery)->after() !== null);

exit(harness_result());
