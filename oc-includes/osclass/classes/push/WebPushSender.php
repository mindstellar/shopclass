<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\push;

use mindstellar\http\HttpClient;
use mindstellar\live\LiveStatus;
use mindstellar\security\AddressGuard;

/**
 * Sends one wake-up to one browser: an empty POST to its push service. No text, name or id
 * goes with it; the browser asks the site what is new.
 */
final class WebPushSender
{
    /** The container id of the push status row. */
    public const STATUS     = 'push.status';
    public const STATUS_KEY = 'push';

    public const OK     = 'ok';
    public const GONE   = 'gone';
    public const RETRY  = 'retry';
    public const FAILED = 'failed';

    public const TOPIC = 'shopclass-messages';

    public function __construct(private HttpClient $http, private AddressGuard $guard, private Vapid $vapid, private LiveStatus $status)
    {
    }

    /**
     * @return string one of ok, gone (forget the browser), retry (later) and failed; never throws
     */
    public function send(string $endpoint): string
    {
        try {
            if (strlen($endpoint) > PushSubscriptions::MAX_ENDPOINT || strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
                return $this->failed('Address refused: not an https:// push address');
            }
            $check = $this->guard->check($endpoint);
            if (!$check['ok']) {
                return $this->failed('Address refused: ' . rtrim((string) ($check['error'] ?? 'not allowed'), '.'));
            }
            $options = ['connect_timeout' => 2, 'timeout' => 5, 'max_bytes' => 4096];
            $resolve = AddressGuard::curlResolve($endpoint, (string) ($check['ip'] ?? ''));
            if ($resolve !== []) {
                $options['resolve'] = $resolve;
            }
            $headers = [
                'Content-Length' => '0',
                'TTL'            => '86400',
                'Urgency'        => 'normal',
                'Topic'          => self::TOPIC,
                'Authorization'  => $this->vapid->authorization($endpoint),
            ];
            $status = $this->http->send('POST', $endpoint, $headers, null, $options)['status'];
        } catch (\Throwable $e) {
            return $this->failed($e->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            $this->status->ok();

            return self::OK;
        }
        if ($status === 404 || $status === 410) {
            return self::GONE;
        }
        if ($status === 429 || $status >= 500) {
            return self::RETRY;
        }

        return $this->failed('HTTP ' . $status);
    }

    private function failed(string $error): string
    {
        $this->status->failed($error);

        return self::FAILED;
    }
}
