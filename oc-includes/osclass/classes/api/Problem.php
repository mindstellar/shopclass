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

use mindstellar\validation\BlockedException;
use mindstellar\validation\ConflictException;
use mindstellar\validation\ForbiddenException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;

/**
 * RFC 9457 problem answers and the error catalogue.
 *
 * `code` is the stable machine name a client branches on; `title` is the same for every
 * occurrence of a code and `detail` says what went wrong this time.
 */
final class Problem
{
    /** Where the `type` URLs point. */
    public const TYPE_BASE = 'https://mindstellar.com/docs/developers/api/errors/#';

    /** code => [status, title] */
    public const CATALOGUE = [
        'invalid_json'           => [400, 'The request body is not valid JSON.'],
        'invalid_cursor'         => [400, 'The cursor is not valid for this request.'],
        'invalid_header'         => [400, 'A request header is not valid.'],
        'unsupported_grant_type' => [400, 'The grant type is not supported.'],
        'invalid_request'        => [400, 'The token request is not valid.'],
        'invalid_grant'          => [400, 'The credentials or refresh token are not valid.'],
        'invalid_scope'          => [400, 'A requested scope is not valid.'],
        'unauthorized'           => [401, 'A valid credential is required.'],
        'token_expired'          => [401, 'The access token has expired.'],
        'session_required'       => [401, 'A signed-in session and a valid page token are required.'],
        'forbidden'              => [403, 'This credential may not use this endpoint.'],
        'wrong_credential'       => [403, 'This endpoint needs another kind of credential.'],
        'banned'                 => [403, 'This account or address is banned.'],
        'feature_disabled'       => [403, 'The site has this feature switched off.'],
        'insufficient_scope'     => [403, 'This credential lacks the scope this endpoint needs.'],
        'not_owner'              => [403, 'Only the owner may change this resource.'],
        'api_disabled'           => [403, 'The API is switched off on this site.'],
        'cross_origin'           => [403, 'Session calls must come from the site\'s own pages.'],
        'not_found'              => [404, 'Not found.'],
        'method_not_allowed'     => [405, 'This method is not allowed here.'],
        'conflict'               => [409, 'The request conflicts with the current state.'],
        'idempotency_in_flight'  => [409, 'A request with this Idempotency-Key is still running.'],
        'precondition_failed'    => [412, 'The resource has changed.'],
        'too_large'              => [413, 'The request body is too large.'],
        'unsupported_media_type' => [415, 'The request body has an unsupported content type.'],
        'validation_failed'      => [422, 'The request is not valid.'],
        'idempotency_key_reused' => [422, 'This Idempotency-Key was used for a different request.'],
        'listing_limit'          => [422, 'The listing limit is reached.'],
        'rate_limited'           => [429, 'Too many requests.'],
        'login_blocked'          => [429, 'Too many failed sign-ins.'],
        'too_many_failures'      => [429, 'Too many failed attempts from this address.'],
        'server_error'           => [500, 'The request could not be handled.'],
        'maintenance'            => [503, 'The site is down for maintenance.'],
    ];

    private function __construct()
    {
    }

    /**
     * A problem answer for a catalogued code. An unknown code is a 500, so a typo cannot
     * invent a status.
     *
     * @param array<string,mixed> $extra more members, e.g. `errors`
     */
    public static function make(string $code, string $detail = '', array $extra = []): Response
    {
        if (!isset(self::CATALOGUE[$code])) {
            $code = 'server_error';
        }
        [$status, $title] = self::CATALOGUE[$code];
        $body = ['type' => self::TYPE_BASE . $code, 'title' => $title, 'status' => $status];
        if ($detail !== '') {
            $body['detail'] = $detail;
        }
        $body['code'] = $code;

        return new Response($status, $body + $extra);
    }

    /**
     * 422 with one entry per failed field.
     *
     * @param array<int,array{pointer:string,code:string,message:string}> $errors
     */
    public static function validation(array $errors): Response
    {
        $detail = '';
        if ($errors !== []) {
            $field  = ltrim(str_replace('/', '.', $errors[0]['pointer']), '.');
            $detail = ($field === '' ? '' : $field . ' ') . rtrim($errors[0]['message'], '.') . '.';
        }

        return self::make('validation_failed', $detail, ['errors' => array_values($errors)]);
    }

    /**
     * Shown text as plain text: tags out, entities decoded.
     */
    public static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * 422 from the errors a core service refused with. Their messages are shown text, so tags
     * come out and entities are decoded.
     *
     * @param array<int,array{pointer:string,code:string,message:string}> $errors
     */
    public static function refused(array $errors): Response
    {
        $clean = [];
        foreach ($errors as $error) {
            $message = self::text($error['message']);
            if ($message !== '') {
                $clean[] = ['message' => $message] + $error;
            }
        }

        return $clean === [] ? self::rejected('') : self::validation($clean);
    }

    /**
     * A core service's refusal as a problem: 403, 404, 409, 429, or 422 with its reason.
     */
    public static function fromRefusal(RefusedException $e): Response
    {
        return match (true) {
            $e instanceof NotFoundException  => self::make('not_found', $e->getMessage()),
            $e instanceof ConflictException  => self::make('conflict', $e->getMessage()),
            $e instanceof ForbiddenException => self::make($e->reason() !== '' ? $e->reason() : 'forbidden', $e->getMessage()),
            $e instanceof BlockedException   => self::make($e->isRateLimit() ? 'rate_limited' : 'login_blocked', $e->getMessage())->withHeader('Retry-After', (string) $e->retryAfter()),
            $e instanceof InvalidException   => self::validation($e->errors()),
            default                          => self::rejected($e->getMessage()),
        };
    }

    /**
     * 422 from the messages a core action answers with when it refuses, one per line, as
     * UserActions and ItemActions return them.
     *
     * @param string $pointer the member they are about, '' for the request as a whole
     */
    public static function rejected(string $messages, string $pointer = ''): Response
    {
        $errors = [];
        foreach (preg_split('/\R/', $messages) ?: [] as $line) {
            $line = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($line !== '') {
                $errors[] = ['pointer' => $pointer, 'code' => 'rejected', 'message' => $line];
            }
        }
        if ($errors === []) {
            $errors[] = ['pointer' => $pointer, 'code' => 'rejected', 'message' => 'The request was refused.'];
        }

        return self::make('validation_failed', $errors[0]['message'], ['errors' => $errors]);
    }

    /**
     * 401. Every refusal of a presented token answers the same, so a caller cannot tell a
     * missing key from a wrong secret or a revoked key.
     */
    public static function unauthorized(bool $tokenPresented = true): Response
    {
        return self::make('unauthorized', 'A valid API key or access token is required.')
            ->withHeader('WWW-Authenticate', $tokenPresented ? 'Bearer error="invalid_token"' : 'Bearer');
    }

    public static function insufficientScope(string $scope): Response
    {
        return self::make('insufficient_scope', 'This endpoint needs the ' . $scope . ' scope.')
            ->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="' . $scope . '"');
    }

    /**
     * @param string[] $allowed
     */
    public static function methodNotAllowed(array $allowed): Response
    {
        $list = implode(', ', $allowed);

        return self::make('method_not_allowed', 'Use ' . $list . ' for this endpoint.')->withHeader('Allow', $list);
    }

    public static function maintenance(): Response
    {
        return self::make('maintenance', 'Try again later.')->withHeader('Retry-After', '900');
    }
}
