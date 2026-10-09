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

namespace mindstellar\apiaccess;

use mindstellar\model\ApiCredential;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;

/**
 * API keys, sign-ins and page tokens as the rest of core uses them: the account page, Settings
 * -> API, the CLI and sign-out. The REST API builds on the same services.
 */
final class ApiAccess
{
    private static ?self $site = null;

    /** @var array<string,\Closure(): void> what the REST API registered with connect() */
    private static array $api = [];

    /** @var array<string,object> service name => instance */
    private array $built = [];

    public function __construct(
        private ApiSettings $settings,
        private Scopes $scopes,
        private SignInStore $store,
        private Clock $clock
    ) {
    }

    /**
     * The services of this site, built once per request.
     */
    public static function site(): self
    {
        return self::$site ??= new self(ApiSettings::fromPreferences(), Scopes::fromHooks(), new ApiCredential(), new SystemClock());
    }

    /**
     * Forget the site's services, so the next site() reads settings and hooks again.
     */
    public static function reset(): void
    {
        self::$site = null;
    }

    /**
     * The REST API plugs into core here, so core never names an API class.
     *
     * @param \Closure(): void $serve       answers this API request and stops
     * @param \Closure(): void $begin       forgets the browser's identity at the start of an API request
     * @param \Closure(): void $maintenance answers 503 while the site is in maintenance, and stops
     */
    public static function connect(\Closure $serve, \Closure $begin, \Closure $maintenance): void
    {
        self::$api = ['serve' => $serve, 'begin' => $begin, 'maintenance' => $maintenance];
    }

    /**
     * Answer this request with the REST API.
     */
    public static function serve(): void
    {
        self::api('serve');
    }

    /**
     * Start an API request: a cookie never authenticates an API call.
     */
    public static function begin(): void
    {
        self::api('begin');
    }

    /**
     * Answer an API request with the maintenance problem.
     */
    public static function maintenance(): void
    {
        self::api('maintenance');
    }

    public function settings(): ApiSettings
    {
        return $this->settings;
    }

    public function scopes(): Scopes
    {
        return $this->scopes;
    }

    public function store(): SignInStore
    {
        return $this->store;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function keys(): ApiKeys
    {
        return $this->once(__FUNCTION__, fn (): ApiKeys => new ApiKeys($this->store, $this->scopes, $this->clock));
    }

    /**
     * The key rules of Settings -> API, for personal keys and `/admin/keys`.
     */
    public function keyService(): ApiKeyService
    {
        return $this->once(__FUNCTION__, fn (): ApiKeyService => new ApiKeyService($this->keys(), $this->store, $this->scopes, $this->clock));
    }

    public function accessEntries(): AccessEntries
    {
        return $this->once(__FUNCTION__, fn (): AccessEntries => new AccessEntries($this->store));
    }

    public function personalKeys(): PersonalKeys
    {
        return $this->once(__FUNCTION__, fn (): PersonalKeys => new PersonalKeys(
            $this->keyService(),
            $this->store,
            $this->settings->userKeys(),
            $this->clock
        ));
    }

    /**
     * The account's "API access" page.
     */
    public function accountAccess(): AccountAccess
    {
        return $this->once(__FUNCTION__, fn (): AccountAccess => new AccountAccess($this->settings, $this->accessEntries(), $this->personalKeys()));
    }

    /**
     * Page tokens for the same-site session mode.
     */
    public function pageTokens(): PageTokens
    {
        return $this->once(__FUNCTION__, fn (): PageTokens => new PageTokens(clock: $this->clock));
    }

    /**
     * @template T of object
     *
     * @param \Closure(): T $make
     *
     * @return T
     */
    private function once(string $name, \Closure $make): object
    {
        return $this->built[$name] ??= $make();
    }

    private static function api(string $step): void
    {
        if (!isset(self::$api[$step])) {
            throw new \LogicException('The REST API is not loaded.');
        }
        (self::$api[$step])();
    }
}
