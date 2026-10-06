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

namespace mindstellar\api\idempotency;

use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\apiaccess\Credential;
use mindstellar\utility\Clock;
use mindstellar\validation\RefusedException;

/**
 * `Idempotency-Key` on POST, PUT, PATCH and DELETE: a write sent again with the same key gets
 * the first answer back instead of running twice.
 *
 * Keys belong to the credential that sent them (one sign-in, one key, or one user's same-site
 * session), and are kept for a day. The first request locks the key while it runs; the same
 * key meanwhile answers 409. A lock left for LOCK_TTL seconds is taken over only by the same
 * request. The same key with a different method, path, query or body answers 422. A 5xx or 429
 * answer is not kept, so the request can be retried; any other answer is, a core refusal included.
 */
final class Idempotency
{
    /** Seconds a key is kept. */
    public const TTL = 86400;

    /** Seconds after which a lock whose request never finished is taken over. */
    public const LOCK_TTL = 120;

    public const MAX_KEY = 255;

    public function __construct(private IdempotencyStore $store, private Clock $clock)
    {
    }

    /**
     * Run a write once per key.
     *
     * @param callable(): Response $handler
     *
     * @throws ProblemException 400 for a malformed key, 409 while the key's first request runs, 422
     *                    for a key reused on another request
     */
    public function run(Request $request, Credential $credential, callable $handler): Response
    {
        $key = $request->idempotencyKey();
        if ($key === '' || !$request->isWrite() || $credential->isAnonymous()) {
            return $handler();
        }
        if (strlen($key) > self::MAX_KEY || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw ProblemException::of('invalid_header', 'Idempotency-Key must be 1 to ' . self::MAX_KEY . ' visible ASCII characters.');
        }

        $hash        = hash('sha256', self::owner($credential) . "\n" . $key);
        $fingerprint = self::fingerprint($request);
        $now         = $this->clock->now();
        $lock        = bin2hex(random_bytes(16));
        $stored      = $this->store->claim($hash, $fingerprint, $now, $now + self::TTL, self::LOCK_TTL, $lock);
        if ($stored !== null) {
            return self::replay($stored, $fingerprint);
        }

        try {
            $response = $handler();
        } catch (ProblemException $e) {
            $response = $e->response();
        } catch (RefusedException $e) {
            $response = Problem::fromRefusal($e);
        } catch (\Throwable $e) {
            $this->store->release($hash, $lock);

            throw $e;
        }
        if ($response->status() >= 500 || $response->status() === 429) {
            $this->store->release($hash, $lock);
        } else {
            $this->store->complete($hash, $lock, $response->status(), (string) json_encode(
                ['status' => $response->status(), 'headers' => $response->headers(), 'body' => $response->body()],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        }

        return $response;
    }

    /**
     * What makes two requests the same: method, path, query, body and uploaded files.
     */
    public static function fingerprint(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        $body = hash('sha256', $request->body() ?? '');
        foreach ($request->files() as $field => $file) {
            $body .= "\n" . $field . '=' . (string) @hash_file('sha256', $file['tmp_name']);
        }

        return hash('sha256', $request->method() . ' ' . ($request->path() ?? '') . "\n" . json_encode($query) . "\n" . $body);
    }

    /**
     * Whose keys these are: one sign-in's (its refresh family, across its access tokens), one
     * key's, or one user's same-site session.
     */
    private static function owner(Credential $credential): string
    {
        return match (true) {
            $credential->isAccessToken() => 'token:' . $credential->userId() . ':' . (string) $credential->family(),
            $credential->isSession()     => 'session:' . $credential->userId(),
            default                      => 'key:' . (int) $credential->id(),
        };
    }

    /**
     * @throws ProblemException 409 while locked, 422 for another request
     */
    private static function replay(IdempotencyRecord $stored, string $fingerprint): Response
    {
        if (!hash_equals($stored->fingerprint(), $fingerprint)) {
            throw ProblemException::of('idempotency_key_reused', 'Send a new Idempotency-Key for a different request.');
        }
        if ($stored->isLocked()) {
            throw ProblemException::from(
                Problem::make('idempotency_in_flight', 'The first request with this key has not finished. Try again shortly.')
                    ->withHeader('Retry-After', '1')
            );
        }
        $data = json_decode((string) $stored->response(), true);
        if (!is_array($data) || !is_int($data['status'] ?? null)) {
            throw ProblemException::of('conflict', 'The stored answer for this Idempotency-Key cannot be read.');
        }
        $body = is_array($data['body'] ?? null) ? $data['body'] : null;

        return (new Response($data['status'], $body, array_map('strval', (array) ($data['headers'] ?? []))))
            ->withHeader('Idempotency-Replayed', 'true');
    }
}
