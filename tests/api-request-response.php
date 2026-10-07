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
 * The API's Request, Response and Problem values: the path read once from the raw URI and
 * refused when it hides a slash, a dot segment or NUL; body cap, content type and JSON errors
 * as ProblemException; the Apache Authorization fallback; the query read as sent; problem+json;
 * core's ETag and 304; HTML-safe JSON; immutability; and no cookie ever sent.
 *
 * DB-free.  Usage: php tests/api-request-response.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\ratelimit\RateBucket;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\Request;
use mindstellar\api\Response;

/** "code status" of the problem a call throws, or 'no problem'. */
$thrown = static function (callable $fn): string {
    try {
        $fn();
    } catch (ProblemException $e) {
        return $e->response()->body()['code'] . ' ' . $e->response()->status();
    }

    return 'no problem';
};

harness_section('Request');

$r = new Request('post', '/v1/listings/', ['limit' => '5'], ['Content-Type' => 'Application/JSON; charset=utf-8', 'AUTHORIZATION' => ' Bearer x '], '10.0.0.1', '{"a":1}');
pin('method is upper case', 'POST', $r->method());
pin('path loses its slashes', 'v1/listings', $r->path());
pin('content type is the bare media type, lower case', 'application/json', $r->contentType());
pin('headers are read in any case', 'Bearer x', $r->authorization());
pin('a JSON object body decodes', ['a' => 1], $r->json());
check('a POST is a write, not a read', $r->isWrite() && !$r->isRead());
pin('withQuery returns a changed copy', [['limit' => '5'], ['x' => 1]], [$r->query(), $r->withQuery(['x' => 1])->query()]);

pin('a body over the cap is 413', 'too_large 413', $thrown(static fn () => (new Request('POST', 'v1', [], ['Content-Type' => 'application/json'], '', null))->json()));
pin('a non-JSON content type is 415', 'unsupported_media_type 415', $thrown(static fn () => (new Request('POST', 'v1', [], ['Content-Type' => 'text/plain'], '', '{}'))->json()));
pin('broken JSON is 400', 'invalid_json 400', $thrown(static fn () => (new Request('POST', 'v1', [], ['Content-Type' => 'application/json'], '', '{"a":'))->json()));
pin('a JSON scalar is 400', 'invalid_json 400', $thrown(static fn () => (new Request('POST', 'v1', [], ['Content-Type' => 'application/json'], '', '"x"'))->json()));
$deep = str_repeat('[', 40) . str_repeat(']', 40);
pin('JSON nested past the limit is 400', 'invalid_json 400', $thrown(static fn () => (new Request('POST', 'v1', [], ['Content-Type' => 'application/json'], '', $deep))->json()));

$q = new Request('GET', 'v1', ['limit' => '20', 'arr' => ['1', '2'], 'cat' => '3, 4,,5', 'yes' => 'true']);
pin('queryInt reads a number', 20, $q->queryInt('limit'));
pin('queryInt of an array is the default', 7, $q->queryInt('arr', 7));
pin('queryString of an array is the default', 'd', $q->queryString('arr', 'd'));
pin('queryBool reads true', true, $q->queryBool('yes'));
pin('queryList splits a comma list', ['3', '4', '5'], $q->queryList('cat'));
pin('queryList reads a repeated param', ['1', '2'], $q->queryList('arr'));
pin('queryList of a missing param is empty', [], $q->queryList('none'));

harness_section('the API path');
pin('rewritten: read from the raw URI below the base', 'v1/listings/12', Request::pathFromUri('/shop/api/v1/listings/12?x=1', '/shop/', ''));
pin('each segment is decoded once', 'v1/c/a b%2541', Request::pathFromUri('/api/v1/c/a%20b%252541', '/', ''));
pin('an encoded slash is refused', null, Request::pathFromUri('/api/v1/c/a%2Fb', '/', ''));
pin('a dot-dot segment is refused', null, Request::pathFromUri('/api/v1/../admin', '/', ''));
pin('an encoded dot-dot is refused', null, Request::pathFromUri('/api/v1/%2e%2e/x', '/', ''));
pin('an encoded NUL is refused', null, Request::pathFromUri('/api/v1/a%00b', '/', ''));
pin('an empty segment is refused', null, Request::pathFromUri('/api/v1//x', '/', ''));
pin('an & in the path stays in the path', 'v1/x&page=user', Request::pathFromUri('/api/v1/x&page=user', '/', ''));
pin('bare /api is the API root', '', Request::pathFromUri('/api', '/', ''));
pin('not rewritten: the path param, already decoded, is not decoded again', 'v1/c/a%20b', Request::pathFromUri('/index.php?page=api&path=v1/c/a%2520b', '/', 'v1/c/a%20b'));
pin('the path param is held to the same rules', null, Request::pathFromUri('/index.php', '/', 'v1/../x'));
pin('a hand-built request with a dot segment has no path', null, (new Request('GET', 'v1/./x'))->path());

harness_section('fromGlobals');
$saved   = $_SERVER;
$_GET    = ['page' => 'api', 'path' => 'v1/x', 'q' => 'AT&T a<b', 'n' => '5'];
$_POST   = [];
$_SERVER = [
    'REQUEST_METHOD'              => 'GET',
    'REQUEST_URI'                 => '/index.php?page=api&path=v1/x',
    'REMOTE_ADDR'                 => '192.0.2.7',
    'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer from-apache',
    'HTTP_IF_NONE_MATCH'          => '"abc"',
    'HTTP_COOKIE'                 => 'osclass=sess',
    'CONTENT_TYPE'                => 'application/json',
];
Params::init();
$g = Request::fromGlobals();
pin('fromGlobals reads the method', 'GET', $g->method());
pin('fromGlobals falls back to REDIRECT_HTTP_AUTHORIZATION', 'Bearer from-apache', $g->authorization());
pin('fromGlobals reads the path param', 'v1/x', $g->path());
pin('page and path are not part of the query, which is read as sent', ['q' => 'AT&T a<b', 'n' => '5'], $g->query());
pin('the address is REMOTE_ADDR', '192.0.2.7', $g->ip());
pin('the cookie header is never kept', '', $g->header('Cookie'));
pin('If-None-Match is read', '"abc"', $g->ifNoneMatch());
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer direct';
$_SERVER['REQUEST_URI']        = '/api/v1/y%20z';
Params::init();
$g = Request::fromGlobals();
pin('a direct Authorization header wins', 'Bearer direct', $g->authorization());
pin('a rewritten request takes its path from the URI', 'v1/y z', $g->path());
$_GET                    = ['city' => '2', 'q' => 'x'];
$_SERVER['QUERY_STRING'] = 'city=1&city=2&q=x&tag%5B%5D=a&tag%5B%5D=b';
Params::init();
$g = Request::fromGlobals();
pin('a key sent twice is read as a list, as OpenAPI clients send one', [['1', '2'], ['1', '2'], 'x'], [$g->query()['city'] ?? null, $g->queryList('city'), $g->query()['q'] ?? null]);
pin('bracketed and single keys are left to PHP', ['city' => ['1', '2']], Request::repeatedKeys('city=1&city=2&q=x&tag%5B%5D=a&tag%5B%5D=b'));
$_SERVER = $saved;
Params::init();

harness_section('Response');

$ok  = Response::ok(['id' => 1, 'html' => '<script>&']);
$out = $ok->prepare('GET');
pin('ok wraps data, with <, > and & escaped', '{"data":{"id":1,"html":"\u003Cscript\u003E\u0026"}}', $out['body']);
pin('JSON content type', Response::JSON_TYPE, $out['headers']['Content-Type']);
pin('nosniff', 'nosniff', $out['headers']['X-Content-Type-Options']);
pin('private, no-store by default', 'private, no-store', $out['headers']['Cache-Control']);
pin('a GET 200 gets core\'s ETag of the body', osc_response_etag_value($out['body']), $out['headers']['ETag']);

$again = $ok->prepare('GET', $out['headers']['ETag']);
pin('a matching If-None-Match is a 304 with no body', [304, ''], [$again['status'], $again['body']]);
pin('a weak tag in a list also matches', 304, $ok->prepare('GET', 'W/"zz", W/' . $out['headers']['ETag'])['status']);
pin('another tag does not', 200, $ok->prepare('GET', '"zz"')['status']);
pin('a POST gets no ETag', false, isset($ok->prepare('POST')['headers']['ETag']));
pin('HEAD keeps headers and drops the body', [200, ''], [$ok->prepare('HEAD')['status'], $ok->prepare('HEAD')['body']]);
pin('204 has no body', '', Response::noContent()->prepare('DELETE')['body']);

$list = Response::collection([5 => 'a', 9 => 'b'], ['total' => 2], ['next' => null]);
pin('a collection is a list with meta and links', '{"data":["a","b"],"meta":{"total":2},"links":{"next":null}}', $list->prepare('GET')['body']);

$cookie = (new Response(200, ['data' => []], ['Set-Cookie' => 'a=b', 'X-Test' => "1\r\nSet-Cookie: c=d"]))->withHeader('set-cookie', 'e=f');
$names  = array_map('strtolower', array_keys($cookie->prepare('GET')['headers']));
pin('a Response never carries Set-Cookie', false, in_array('set-cookie', $names, true));
pin('a header value cannot smuggle a line break', '1Set-Cookie: c=d', $cookie->header('X-Test'));
$src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/Response.php');
check('send() removes cookies queued elsewhere, ends every buffer and flushes before exit', str_contains($src, "header_remove('Set-Cookie')")
    && str_contains($src, 'ob_end_clean()') && str_contains($src, 'flush();'));

$copy = $ok->withHeader('X-A', '1');
pin('withHeader returns a copy', [null, '1'], [$ok->header('X-A'), $copy->header('x-a')]);
pin('withDefaultHeaders keeps a header already set', ['1', '2'], [
    $copy->withDefaultHeaders(['x-a' => 'no', 'X-B' => '2'])->header('X-A'),
    $copy->withDefaultHeaders(['x-a' => 'no', 'X-B' => '2'])->header('X-B'),
]);
pin('withBodyMember returns a copy', [false, 'y'], [isset($ok->body()['x']), $ok->withBodyMember('x', 'y')->body()['x']]);
pin('header names keep their case on the wire', ['X-A'], array_values(array_filter(array_keys($copy->headers()), static fn ($n) => $n === 'X-A')));
check('Response, Request and RouteSpec have no public properties', array_reduce(
    [Response::class, Request::class, \mindstellar\api\RouteSpec::class],
    static fn (bool $ok, string $c): bool => $ok && (new ReflectionClass($c))->getProperties(ReflectionProperty::IS_PUBLIC) === [],
    true
));

harness_section('Problem');

$p    = Problem::make('not_found', 'No such endpoint.');
$wire = $p->prepare('GET');
pin('problem+json content type', Response::PROBLEM_TYPE, $wire['headers']['Content-Type']);
pin('RFC 9457 members plus code', [
    'type'   => 'https://mindstellar.com/docs/developers/api/errors/#not_found',
    'title'  => 'Not found.',
    'status' => 404,
    'detail' => 'No such endpoint.',
    'code'   => 'not_found',
], json_decode($wire['body'], true));
pin('a problem gets no ETag', false, isset($wire['headers']['ETag']));
pin('the problem does not set Content-Type itself', null, $p->header('Content-Type'));
pin('an unknown code is a 500', [500, 'server_error'], [Problem::make('nope')->status(), Problem::make('nope')->body()['code']]);

$v = Problem::validation([['pointer' => '/price/amount', 'code' => 'type', 'message' => 'must be a number']]);
pin('validation is 422 with errors', [422, 'price.amount must be a number.', 1], [$v->status(), $v->body()['detail'], count($v->body()['errors'])]);
pin('a presented bad token says invalid_token', 'Bearer error="invalid_token"', Problem::unauthorized()->header('WWW-Authenticate'));
pin('no token says Bearer only', 'Bearer', Problem::unauthorized(false)->header('WWW-Authenticate'));
pin('insufficient scope names the scope', 'Bearer error="insufficient_scope", scope="admin:users"', Problem::insufficientScope('admin:users')->header('WWW-Authenticate'));
pin('405 carries Allow', 'GET, HEAD', Problem::methodNotAllowed(['GET', 'HEAD'])->header('Allow'));
pin('maintenance is 503 with Retry-After', [503, '900'], [Problem::maintenance()->status(), Problem::maintenance()->header('Retry-After')]);
pin('ProblemException::of carries the problem', [409, 'Busy.'], [ProblemException::of('conflict', 'Busy.')->response()->status(), ProblemException::of('conflict', 'Busy.')->getMessage()]);

$field = ProblemException::field('/title', 'minLength', 'is empty')->response();
pin('ProblemException::field is a 422 on one member', [422, '/title', 'title is empty.'], [$field->status(), $field->body()['errors'][0]['pointer'], $field->body()['detail']]);
$many = ProblemException::tooMany('Slow down.', 0)->response();
pin('ProblemException::tooMany is a 429 with at least a second to wait', [429, 'rate_limited', '1'], [$many->status(), $many->body()['code'], $many->header('Retry-After')]);
$limiter = new RateLimiter(static fn () => 3, new TestClock(static fn (): int => 1_800_000_030));
$refused = null;
try {
    $limiter->enforce(new RateBucket('test', 'k', 2, 60), 'Too many tests.');
} catch (ProblemException $e) {
    $refused = $e->response();
}
pin('RateLimiter::enforce refuses past the limit, until the window ends', [429, 'Too many tests.', '30'], [$refused?->status(), $refused?->body()['detail'] ?? null, $refused?->header('Retry-After')]);
$created = Response::created(['id' => 7], 'http://localhost/api/v1/things/7');
pin('Response::created is a 201 naming the new resource', [201, 'http://localhost/api/v1/things/7', ['id' => 7]], [$created->status(), $created->header('Location'), $created->body()['data']]);

harness_section('bodies');
$caps   = [];
$reader = static function (int $cap) use (&$caps): ?string {
    $caps[] = $cap;

    return '{"a":1}';
};
$lazy = new Request('POST', 'v1/x', [], ['Content-Type' => 'application/json'], '', null, [], $reader);
pin('a body is read on first use, once, up to 1 MB', [[], ['a' => 1], ['a' => 1], [Request::MAX_BODY]], [[], $lazy->input(), $lazy->input(), $caps]);
$caps = [];
(new Request('POST', 'v1/x', [], [], '', null, [], $reader))->forUpload()->body();
pin('an upload reads up to 16 MB', [Request::MAX_UPLOAD], $caps);
$patch = new Request('PATCH', 'v1/x', [], ['Content-Type' => 'application/merge-patch+json'], '', '{"title":null}');
pin('PATCH takes application/merge-patch+json', ['title' => null], $patch->input());
$refused = null;
try {
    (new Request('PATCH', 'v1/x', [], ['Content-Type' => 'text/plain'], '', 'x'))->input();
} catch (ProblemException $e) {
    $refused = $e->response();
}
pin('and a 415 on PATCH names it in Accept-Patch', [415, Request::MERGE_PATCH], [$refused?->status(), $refused?->header('Accept-Patch')]);

$bad = array_filter(Problem::CATALOGUE, static fn (array $e, string $c): bool => preg_match('/^[a-z_]+$/', $c) !== 1 || $e[0] < 400 || $e[0] > 599 || $e[1] === '', ARRAY_FILTER_USE_BOTH);
pin('every catalogue entry is well formed', [], array_keys($bad));

// PHP makes any answer carrying WWW-Authenticate a 401, so a 403 insufficient_scope needs its status set last.
$send = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/api/Response.php');
check('send() sets the status after the headers', strpos($send, 'http_response_code($out[\'status\'])') > strpos($send, "header(\$name . ': ' . \$value);"));

exit(harness_result());
