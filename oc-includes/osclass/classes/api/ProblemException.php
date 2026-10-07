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
     * @param string              $code   a Problem::CATALOGUE code
     * @param string              $detail
     * @param array<string,mixed> $extra
     */
    public static function of(string $code, string $detail = '', array $extra = []): self
    {
        return new self(Problem::make($code, $detail, $extra));
    }

    /**
     * 404 not_found with $detail, such as "No such user."
     */
    public static function notFound(string $detail): self
    {
        return self::of('not_found', $detail);
    }

    /**
     * Wrap a problem answer that already has its headers, e.g. Problem::unauthorized().
     */
    public static function from(Response $response): self
    {
        return new self($response);
    }

    /**
     * 422 for one member of the body.
     *
     * @param string $pointer a JSON pointer, e.g. `/photo_tokens/0`
     * @param string $code    a short machine name, e.g. `invalid`
     */
    public static function field(string $pointer, string $code, string $message): self
    {
        return new self(Problem::validation([['pointer' => $pointer, 'code' => $code, 'message' => $message]]));
    }

    /**
     * 429 with the seconds to wait.
     *
     * @param string $code `rate_limited`, or another 429 code of the catalogue
     */
    public static function tooMany(string $message, int $retryAfter, string $code = 'rate_limited'): self
    {
        return new self(Problem::make($code, $message)->withHeader('Retry-After', (string) max(1, $retryAfter)));
    }

    public function response(): Response
    {
        return $this->response;
    }
}
