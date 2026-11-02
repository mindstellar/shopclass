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

/**
 * Thrown anywhere on the API path (a handler, an `api_request_before` callback, the kernel's
 * own checks) to answer with a problem. The kernel catches it in one place.
 */
final class ProblemException extends \RuntimeException
{
    private function __construct(private Response $response)
    {
        $body = $response->body();
        parent::__construct((string) ($body['detail'] ?? $body['code'] ?? 'API problem'));
    }

    /**
     * @api
     *
     * @param string              $code   a Problem::CATALOGUE code or a plugin's `ext_<slug>_<name>` code
     * @param array<string,mixed> $extra
     */
    public static function of(string $code, string $detail = '', array $extra = []): self
    {
        return new self(Problem::make($code, $detail, $extra));
    }

    /**
     * 404 not_found with $detail, such as "No such user."
     *
     * @api
     */
    public static function notFound(string $detail): self
    {
        return self::of('not_found', $detail);
    }

    /**
     * 403 api_disabled: the site owner has switched the API off.
     */
    public static function apiDisabled(): self
    {
        return self::of('api_disabled', 'Ask the site owner to switch the API on.');
    }

    /**
     * $value when a lookup found something, else a 404 saying `No such <what>.`
     *
     * @template T
     *
     * @param T|null|false $value
     *
     * @return T
     * @throws self 404
     */
    public static function found(mixed $value, string $what): mixed
    {
        return $value !== null && $value !== false ? $value : throw self::notFound('No such ' . $what . '.');
    }

    /**
     * Wrap a problem answer that already has its headers, e.g. Problem::unauthorized().
     *
     * @api
     */
    public static function from(Response $response): self
    {
        return new self($response);
    }

    /**
     * 422 for one field.
     *
     * @api
     *
     * @param string $pointer a JSON pointer, e.g. `/photo_tokens/0`
     * @param string $code    a short machine name, e.g. `invalid`
     * @param string $in      `body` or `query`
     */
    public static function field(string $pointer, string $code, string $message, string $in = 'body'): self
    {
        return new self(Problem::validation([['pointer' => $pointer, 'code' => $code, 'message' => $message, 'in' => $in]]));
    }

    /**
     * 429 with the seconds to wait.
     *
     * @api
     *
     * @param string $code `rate_limited`, or another 429 code of the catalogue
     */
    public static function tooMany(string $message, int $retryAfter, string $code = 'rate_limited'): self
    {
        return new self(Problem::make($code, $message)->withHeader('Retry-After', (string) max(1, $retryAfter)));
    }

    /** @api */
    public function response(): Response
    {
        return $this->response;
    }
}
