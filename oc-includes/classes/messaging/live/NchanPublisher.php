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

namespace mindstellar\live;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Publishes to nchan's loopback publisher with one short POST. A slow or dead server costs the
 * caller at most a second and is recorded in LiveStatus.
 */
final class NchanPublisher implements LivePublisher
{
    private string $publishUrl;
    private string $subscribeUrl;

    public function __construct(string $publishUrl, string $subscribeUrl, private LiveStatus $status, private ?HttpClientInterface $http = null)
    {
        $this->publishUrl   = rtrim($publishUrl, '/') . '/';
        $this->subscribeUrl = rtrim($subscribeUrl, '/') . '/';
    }

    public function publish(string $channel, array $note): bool
    {
        if (LiveChannel::userId($channel) === null) {
            return false;
        }
        try {
            $response = ($this->http ??= HttpClient::create())->request('POST', $this->publishUrl . $channel, [
                'headers'       => ['Content-Type' => 'application/json'],
                'body'          => json_encode($note, JSON_THROW_ON_ERROR),
                'timeout'       => 1,
                'max_duration'  => 1,
                'max_redirects' => 0,
                'no_proxy'      => '*',
            ]);
            $code = $response->getStatusCode();
            unset($response);
        } catch (\Throwable $e) {
            $this->status->failed($e->getMessage());

            return false;
        }
        if ($code < 200 || $code > 299) {
            $this->status->failed('HTTP ' . $code);

            return false;
        }
        $this->status->ok();

        return true;
    }

    public function subscribeUrl(string $channel): ?string
    {
        return LiveChannel::userId($channel) !== null ? $this->subscribeUrl . $channel : null;
    }

    public function mode(): string
    {
        return 'nchan';
    }
}
