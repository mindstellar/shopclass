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

namespace mindstellar\api;

use mindstellar\api\auth\Authenticator;
use mindstellar\api\auth\MemoisedRows;
use mindstellar\api\auth\OAuthError;
use mindstellar\api\auth\PageTokenAuth;
use mindstellar\api\auth\UserRows;
use mindstellar\api\http\CachePolicy;
use mindstellar\api\http\Cors;
use mindstellar\api\http\ResourceVersions;
use mindstellar\api\http\RowVersions;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\ratelimit\RateBucket;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\ratelimit\RateLimitResult;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\routing\RouteMatch;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\Scopes;
use mindstellar\validation\RefusedException;

/**
 * Answers one API request as an ordered pipeline from the on/off switch to caching, where any step
 * refuses by throwing ProblemException. handle() builds the Response; serve() is the only place
 * that sends one.
 */
final class Kernel
{
    /** Where deprecations are announced. */
    public const CHANGELOG = 'https://mindstellar.com/docs/developers/api/changelog/';

    /** The writes that honour If-Match. */
    private const CHECKED_WRITES = ['PUT', 'PATCH', 'DELETE'];

    private RatePolicy $ratePolicy;

    private CachePolicy $cachePolicy;

    private ResourceVersions $versions;

    public function __construct(
        private Router $router,
        private Authenticator $authenticator,
        private RateLimiter $limiter,
        private Validator $validator,
        private ApiSettings $settings,
        private UserRows $users,
        private MemoisedRows $admins,
        private Idempotency $idempotency,
        ?ResourceVersions $versions = null,
        ?RatePolicy $ratePolicy = null,
        ?CachePolicy $cachePolicy = null
    ) {
        $this->versions    = $versions ?? new RowVersions();
        $this->ratePolicy  = $ratePolicy ?? new RatePolicy($settings);
        $this->cachePolicy = $cachePolicy ?? new CachePolicy($settings->cacheMaxAge());
    }

    /**
     * Whether a credential may call a route: its auth level, then its scope.
     *
     * @throws ProblemException 403 when it may not
     */
    private static function authorize(RouteSpec $route, Credential $credential): void
    {
        if ($route->auth() === RouteSpec::AUTH_NONE) {
            return;
        }
        if ($route->auth() === RouteSpec::AUTH_USER && !$credential->isUser()) {
            throw ProblemException::of('wrong_credential', 'This endpoint needs a user\'s token or key.');
        }
        $scope = $route->scope();
        if ($route->auth() === RouteSpec::AUTH_ADMIN && !$credential->isAdmin()) {
            throw ProblemException::of('wrong_credential', 'This endpoint needs an admin key.');
        }
        // A moderator holds only the moderator scopes, so an admin route naming no scope is full admins' only.
        if ($route->auth() === RouteSpec::AUTH_ADMIN && $scope === null && $credential->isModerator()) {
            throw ProblemException::of('wrong_credential', 'This endpoint needs a full admin\'s key.');
        }
        if ($scope !== null && !$credential->has($scope)) {
            throw ProblemException::from(Problem::insufficientScope($scope));
        }
    }

    /**
     * The kernel for this site, from its composition root.
     */
    public static function fromSite(): self
    {
        return ApiServices::site()->kernel();
    }

    /**
     * Answer the current request and stop.
     */
    public static function serve(): void
    {
        $request   = Request::fromGlobals();
        $requestId = RequestId::for($request);
        try {
            $settings = ApiServices::site()->settings();
            $response = $settings->enabled()
                ? self::fromSite()->handle($request, $requestId)
                : self::disabled($settings, $request, $requestId);
        } catch (\Throwable $e) {
            self::logFailure($e, $requestId);
            $response = self::withInstance(Problem::make('server_error'), $requestId)->withHeader(RequestId::HEADER, $requestId);
        }
        $response->send($request);
    }

    /**
     * The answer while the API is off, built without the router or any service.
     */
    private static function disabled(ApiSettings $settings, Request $request, string $requestId): Response
    {
        $cors     = Cors::fromSettings($settings, $request);
        $response = ProblemException::apiDisabled()->response();
        $response = $response->withHeader(RequestId::HEADER, $requestId)->withDefaultHeaders($cors->headers($request));

        return self::withInstance($response, $requestId);
    }

    public function handle(Request $request, ?string $requestId = null): Response
    {
        $requestId ??= RequestId::for($request);
        $cors        = Cors::fromSettings($this->settings, $request);
        $rateHeaders = [];
        $route       = null;
        try {
            if ($request->method() === 'OPTIONS') {
                $methods  = $this->methodsForOptions($request);
                $response = $cors->preflight($request, $methods);
                if (in_array('PATCH', $methods, true)) {
                    $response = $response->withDefaultHeaders(['Accept-Patch' => Request::MERGE_PATCH]);
                }

                return $this->finish($response, $request, $cors, $requestId);
            }
            $match = $this->match($request);
            $route = $match->route();
            if ($route->upload()) {
                $request = $request->forUpload();
            }
            if ($route->oauth()) {
                $request = $request->withFormAsJson();
            }
            $credential = $this->credentialFor($request, $route);
            self::authorize($route, $credential);
            $rateHeaders = $this->countRequest($request, $route, $credential);
            $this->assumeIdentity($credential, $route);
            osc_run_hook('api_request_before', $request, $route, $credential);
            $version = null;
            $run     = function () use (&$request, &$version, $route, $credential, $match): Response {
                $request = $this->validate($request, $route);
                if ($request->isRead() && $this->mayWrite($request, $credential)) {
                    // Read first: a write landing meanwhile then fails If-Match instead of slipping past it.
                    $version = $this->storedVersion($route, $credential, $match->args());
                }
                $prepared = $route->prepare($request, $credential, $match->args());

                return $this->callChecked($request, $route, $credential, $match->args(), $prepared);
            };
            $response = $route->replayable() ? $this->idempotency->run($request, $credential, $run) : $run();
            $filtered = osc_apply_filter('api_response', $response, $request, $route);
            $response = $filtered instanceof Response ? $filtered : $response;
            if ($version !== null) {
                $response = $response->withVersion($version);
            }
            if ($credential->isSession()) {
                // Never stored anywhere, whatever a handler or filter asked for.
                $response = $response->withHeader('Cache-Control', $this->cachePolicy->header($request, $credential));
            } elseif (!$response->isProblem()) {
                $response = $response->withDefaultHeaders(['Cache-Control' => $this->cachePolicy->header($request, $credential)]);
                if ($this->cachePolicy->isPublic($request, $credential)) {
                    // A shared cache serves this to everyone, so one caller's counters do not belong in it.
                    $rateHeaders = [];
                }
            }
        } catch (ProblemException $e) {
            $response = $route !== null && $route->oauth() ? OAuthError::from($e->response()) : $e->response();
        } catch (RefusedException $e) {
            $response = Problem::fromRefusal($e);
        } catch (\Throwable $e) {
            // Still finished below, so a 500 carries the CORS, Vary and rate-limit headers.
            self::logFailure($e, $requestId);
            $response = Problem::make('server_error');
        }

        $response = $response->withDefaultHeaders($rateHeaders + self::lifecycleHeaders($route));
        if ($route !== null && $route->method() === 'PATCH') {
            $response = $response->withDefaultHeaders(['Accept-Patch' => Request::MERGE_PATCH]);
        }
        if (PageTokenAuth::applies($request)) {
            $response = $response->withDefaultHeaders(['Vary' => implode(', ', CachePolicy::SESSION_VARY)]);
        } elseif ($response->status() < 300 || $response->status() === 304) {
            $vary = $this->cachePolicy->vary($request, $cors->enabled());
            if ($vary !== []) {
                $response = $response->withDefaultHeaders(['Vary' => implode(', ', $vary)]);
            }
        }

        return $this->finish($response, $request, $cors, $requestId);
    }

    /**
     * Run the handler, honouring If-Match on a PUT, PATCH or DELETE (`*`: any existing resource). A
     * stored GET version is read with its rows locked and the write runs in that transaction;
     * otherwise the GET's ETag is compared.
     *
     * @param array<string,string> $args
     * @param mixed                $prepared what the route's prepare step returned
     * @throws ProblemException 412 when the resource has changed, or cannot be checked
     */
    private function callChecked(Request $request, RouteSpec $route, Credential $credential, array $args, mixed $prepared): Response
    {
        $header = trim($request->ifMatch());
        $read   = $header !== '' && in_array($route->method(), self::CHECKED_WRITES, true)
            ? $this->router->match('GET', $this->routePath($request), $request->version())
            : null;
        if ($read === null) {
            return $route->call($request, $credential, $args, $prepared);
        }
        try {
            self::authorize($read->route(), $credential);
        } catch (ProblemException $e) {
            throw ProblemException::of('precondition_failed', 'This credential cannot read the resource, so If-Match cannot be checked. Send the write without it.');
        }
        $path = $read->route()->path();
        if (!$this->versions->supports($path)) {
            $this->checkRepresentation($header, $request, $read, $credential);

            return $route->call($request, $credential, $args, $prepared);
        }

        return $this->versions->atomically(function () use ($header, $request, $route, $credential, $args, $read, $path, $prepared): Response {
            $version = $this->versions->version($path, $read->args(), $credential, true);
            // No version means no current resource, which If-Match never matches, not even `*`.
            if ($version === null || !Response::versionMatches($header, $version)) {
                $current = $read->route()->call($request->asRead(), $credential, $read->args());
                throw $current->status() < 300 ? self::preconditionFailed() : ProblemException::from($current);
            }
            $response = $route->call($request, $credential, $args, $prepared);
            // Only a PATCH or PUT answer with a body carries the new version; a DELETE leaves none to read.
            if ($route->method() === 'DELETE' || $response->status() !== 200 || $response->body() === null) {
                return $response;
            }
            $version = $this->versions->version($path, $read->args(), $credential);

            return $version === null ? $response : $response->withVersion($version);
        });
    }

    /**
     * If-Match against the ETag of the GET's answer, for a path that keeps no stored version.
     *
     * @throws ProblemException 412 when it differs
     */
    private function checkRepresentation(string $header, Request $request, RouteMatch $read, Credential $credential): void
    {
        $current  = $read->route()->call($request->asRead(), $credential, $read->args());
        $filtered = osc_apply_filter('api_response', $current, $request->asRead(), $read->route());
        $current  = $filtered instanceof Response ? $filtered : $current;
        if ($current->status() >= 300) {
            throw ProblemException::from($current);
        }
        $etag = $current->etag();
        if ($etag !== null && !osc_etag_matches($header, $etag)) {
            throw self::preconditionFailed();
        }
    }

    private static function preconditionFailed(): ProblemException
    {
        return ProblemException::of('precondition_failed', 'Fetch the resource again and retry with its new ETag.');
    }

    /**
     * Whether the credential may PUT, PATCH or DELETE the path it reads. Only such a caller can send
     * If-Match, so only its ETag needs the stored version; others get the plain body hash.
     */
    private function mayWrite(Request $request, Credential $credential): bool
    {
        if ($credential->isAnonymous() || $credential->kind() === CredentialKind::PUBLIC) {
            return false;
        }
        foreach (self::CHECKED_WRITES as $method) {
            $write = $this->router->match($method, $request->routePath(), $request->version());
            if ($write === null) {
                continue;
            }
            try {
                self::authorize($write->route(), $credential);

                return true;
            } catch (ProblemException $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * The stored version for a GET's ETag, where its path keeps one and the caller owns it. One
     * that cannot be read leaves the plain ETag.
     *
     * @param array<string,string> $args
     */
    private function storedVersion(RouteSpec $route, Credential $credential, array $args): ?string
    {
        if (!$this->versions->supports($route->path())) {
            return null;
        }
        try {
            return $this->versions->version($route->path(), $args, $credential, false, true);
        } catch (\Throwable $e) {
            error_log('api: resource version: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Deprecation (RFC 9745), Sunset (RFC 8594) and a link to the API changelog for a route
     * that is on its way out.
     *
     * @return array<string,string>
     */
    private static function lifecycleHeaders(?RouteSpec $route): array
    {
        if ($route === null || ($route->deprecated() === null && $route->sunset() === null)) {
            return [];
        }
        $headers = ['Link' => '<' . self::CHANGELOG . '>; rel="deprecation"'];
        if ($route->deprecated() !== null) {
            $headers['Deprecation'] = '@' . (int) strtotime($route->deprecated() . ' 00:00:00 UTC');
        }
        if ($route->sunset() !== null) {
            $headers['Sunset'] = gmdate('D, d M Y H:i:s', (int) strtotime($route->sunset() . ' 00:00:00 UTC')) . ' GMT';
        }

        return $headers;
    }

    /**
     * The request id, the CORS grant and the problem's `instance`, on every answer. A session
     * call never gets a CORS grant: only the site's own pages may make one.
     */
    private function finish(Response $response, Request $request, Cors $cors, string $requestId): Response
    {
        $response = $response->withHeader(RequestId::HEADER, $requestId);
        if (PageTokenAuth::applies($request)) {
            return self::withInstance($response, $requestId);
        }
        $response = $response->withDefaultHeaders($cors->headers($request));
        if ($cors->enabled() && $response->header('Vary') === null) {
            $response = $response->withHeader('Vary', 'Origin');
        }

        return self::withInstance($response, $requestId);
    }

    /**
     * The methods the requested path answers, for an OPTIONS request; no credential needed.
     *
     * @return string[]
     * @throws ProblemException 403 when the API is off, 404 when the path answers nothing
     */
    private function methodsForOptions(Request $request): array
    {
        $methods = $this->router->methodsFor($this->routePath($request), $request->version());
        if ($methods === []) {
            throw self::noEndpoint();
        }

        return $methods;
    }

    /**
     * @throws ProblemException 403 when the API is off, 404 or 405 when nothing matches
     */
    private function match(Request $request): RouteMatch
    {
        $path  = $this->routePath($request);
        $match = $this->router->match($request->method(), $path, $request->version());
        if ($match !== null) {
            return $match;
        }
        $allowed = $this->router->methodsFor($path, $request->version());

        throw $allowed === []
            ? self::noEndpoint()
            : ProblemException::from(Problem::methodNotAllowed($allowed));
    }

    private static function noEndpoint(): ProblemException
    {
        return ProblemException::notFound('No such endpoint.');
    }

    /**
     * The request's path below the version, e.g. `listings/12`.
     *
     * @throws ProblemException 403 when the API is off, 404 for a refused path or a version the site does not answer
     */
    private function routePath(Request $request): string
    {
        if (!$this->settings->enabled()) {
            throw ProblemException::apiDisabled();
        }
        if ($request->path() === null) {
            throw self::noEndpoint();
        }
        if (!$this->router->serves($request->version())) {
            throw ProblemException::notFound('No such API version.');
        }

        return $request->routePath();
    }

    /**
     * @throws ProblemException 401 when a credential is needed and none (or a bad one) came, 403 for a
     *                    personal key while the site has them switched off
     */
    private function credentialFor(Request $request, RouteSpec $route): Credential
    {
        if ($route->auth() === RouteSpec::AUTH_NONE) {
            return Credential::anonymous();
        }
        $credential = $this->authenticator->authenticate($request);
        if ($credential !== null && $credential->kind() === CredentialKind::KEY && $credential->isUser() && !$this->settings->userKeys()) {
            throw ProblemException::of('feature_disabled', 'Personal keys are switched off on this site.');
        }
        if ($credential !== null) {
            return $credential;
        }
        if ($route->auth() === RouteSpec::AUTH_PUBLIC && $this->settings->publicReads()) {
            return Credential::anonymous(Scopes::PUBLIC);
        }

        throw ProblemException::from(Problem::unauthorized(false));
    }

    /**
     * Let core code act for the user or admin a token or key stands for, for this request only. An
     * admin is taken on for admin routes only, so a public route an admin key calls runs as nobody.
     */
    private function assumeIdentity(Credential $credential, RouteSpec $route): void
    {
        if ($credential->isAdmin()) {
            $admin = $route->auth() === RouteSpec::AUTH_ADMIN ? $this->admins->find((int) $credential->adminId()) : null;
            if ($admin !== null) {
                WebIdentity::assumeAdmin($admin);
            }

            return;
        }
        if (!$credential->isUser()) {
            return;
        }
        $user = $this->users->find((int) $credential->userId());
        if ($user !== null) {
            WebIdentity::assume($user);
        }
    }

    /**
     * @return array<string,string> the rate limit headers
     * @throws ProblemException 429 past a limit
     */
    private function countRequest(Request $request, RouteSpec $route, Credential $credential): array
    {
        $results = array_map(
            fn (RateBucket $bucket): RateLimitResult => $this->limiter->hit($bucket),
            $this->ratePolicy->bucketsFor($request, $route, $credential)
        );
        $headers = $this->limiter->headers($results);
        foreach ($results as $result) {
            if (!$result->allowed()) {
                throw ProblemException::from(ProblemException::tooMany('Too many requests. Try again shortly.', $result->reset())->response()->withDefaultHeaders($headers));
            }
        }

        return $headers;
    }

    /**
     * Check the query and body against the route's schemas.
     *
     * @return Request the request with typed query values
     * @throws ProblemException 422, or the body's own 400/413/415
     */
    private function validate(Request $request, RouteSpec $route): Request
    {
        $query = $request->query();
        unset($query['api_key']);
        $errors = [];

        $schema = $route->query();
        if ($schema !== null) {
            $query = $this->validator->coerceQuery($schema, $query);
            foreach ($this->validator->check($schema, $query) as $error) {
                $errors[] = $error + ['in' => 'query'];
            }
        }
        $schema = $route->body();
        if ($schema !== null) {
            foreach ($this->validator->check($schema, $request->input()) as $error) {
                $errors[] = $error + ['in' => 'body'];
            }
        }
        if ($errors !== []) {
            throw ProblemException::from(Problem::validation($errors));
        }

        return $request->withQuery($query);
    }

    /**
     * Add `instance` (`urn:request:<id>`) to a problem body that has none.
     */
    private static function withInstance(Response $response, string $requestId): Response
    {
        $body = $response->body();
        if (!$response->isProblem() || !isset($body['code']) || isset($body['instance'])) {
            return $response;
        }

        return $response->withBodyMember('instance', 'urn:request:' . $requestId);
    }

    private static function logFailure(\Throwable $e, string $requestId): void
    {
        error_log('api: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . ' (request ' . $requestId . ')');
    }
}
