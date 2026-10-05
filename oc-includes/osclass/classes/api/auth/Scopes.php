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

namespace mindstellar\api\auth;

use mindstellar\admin\ModeratorAccess;

/**
 * API scopes: `resource:verb`, stored space separated. Plugins add `ext:<slug>:<verb>` scopes
 * through the `api_scopes` filter, each with the audience that may hold it.
 *
 * Built once per request (fromHooks()) and passed to whatever needs the full list. A
 * moderator's key holds the moderator scopes only for the admin pages moderators may open.
 */
final class Scopes
{
    /** Only full admins' keys may hold the scope. */
    public const AUDIENCE_ADMIN = 'admin';
    /** Admins' and moderators' keys may hold it. */
    public const AUDIENCE_MODERATOR = 'moderator';
    /** Users' keys and tokens may hold it, as may any admin's key. */
    public const AUDIENCE_USER = 'user';

    public const AUDIENCES = [self::AUDIENCE_ADMIN, self::AUDIENCE_MODERATOR, self::AUDIENCE_USER];

    /** The scope public data is read with; the only one a public key may hold. */
    public const PUBLIC_READ = 'listings:read';

    public const CORE = [
        'listings:read'   => 'Read listings and other public data.',
        'listings:write'  => 'Create and edit own listings and their photos.',
        'listings:delete' => 'Delete own listings.',
        'comments:write'  => 'Post and delete own comments.',
        'alerts:write'    => 'Create and delete own saved searches.',
        'account:read'    => 'Read own profile and sessions.',
        'account:write'   => 'Edit own profile, password and e-mail.',
        'admin:listings'  => 'Moderate and edit any listing.',
        'admin:comments'  => 'Moderate comments.',
        'admin:users'     => 'List, edit, enable and disable users.',
        'admin:taxonomy'  => 'Edit categories, locations, currencies and custom fields.',
        'admin:settings'  => 'Read and change the settings the API exposes.',
        'admin:keys'      => 'Manage API keys.',
        'admin:webhooks'  => 'Manage webhook endpoints.',
    ];

    public const PUBLIC = [self::PUBLIC_READ];

    public const USER = [
        'listings:read', 'listings:write', 'listings:delete', 'comments:write', 'alerts:write',
        'account:read', 'account:write',
    ];

    public const ADMIN = [
        'admin:listings', 'admin:comments', 'admin:users', 'admin:taxonomy', 'admin:settings',
        'admin:keys', 'admin:webhooks',
    ];

    public const MODERATOR = ['admin:listings', 'admin:comments'];

    /** The admin page behind each moderator scope: a moderator holds the scope while moderators may open the page. */
    public const MODERATOR_PAGES = ['admin:listings' => 'items', 'admin:comments' => 'comments'];

    /** Scopes only a signed-in user's access token may hold, never a key or a session. */
    public const TOKEN_ONLY = ['account:write'];

    private const PLUGIN_SCOPE = '/^ext:[a-z0-9-]+:[a-z0-9:_-]+$/D';

    /** held => what it also grants */
    private const IMPLIES = [
        'listings:write' => ['listings:read'],
        'account:write'  => ['account:read'],
    ];

    /** @var array<string,array{description:string,audience:string}> */
    private array $plugin = [];

    /** @var string[] the moderator scopes moderators may hold on this site */
    private array $moderator;

    /**
     * @param array<string,string|array{description?:string,audience?:string}> $declared plugin
     *        scopes: scope => description, or scope => [description, audience]. Anything not
     *        named `ext:<slug>:...` or with an unknown audience is dropped; the audience
     *        defaults to admin.
     * @param string[]|null $moderatorPages the admin pages moderators may open; the default list when null
     */
    public function __construct(array $declared = [], ?array $moderatorPages = null)
    {
        $pages           = $moderatorPages ?? ModeratorAccess::DEFAULT_PAGES;
        $this->moderator = array_keys(array_filter(self::MODERATOR_PAGES, static fn (string $page): bool => in_array($page, $pages, true)));
        foreach ($declared as $scope => $entry) {
            $entry    = is_array($entry) ? $entry : ['description' => (string) $entry];
            $audience = (string) ($entry['audience'] ?? self::AUDIENCE_ADMIN);
            if (is_string($scope) && preg_match(self::PLUGIN_SCOPE, $scope) === 1 && in_array($audience, self::AUDIENCES, true)) {
                $this->plugin[$scope] = ['description' => (string) ($entry['description'] ?? ''), 'audience' => $audience];
            }
        }
    }

    /**
     * Core scopes plus those plugins declare on `api_scopes`.
     */
    public static function fromHooks(): self
    {
        $scopes = [];
        $scopes = osc_apply_filter('api_scopes', $scopes);

        return new self(is_array($scopes) ? $scopes : [], ModeratorAccess::pages());
    }

    /**
     * Every scope with its description.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        return self::CORE + array_map(static fn (array $s): string => $s['description'], $this->plugin);
    }

    /**
     * The scopes a credential of this kind and owner may hold.
     *
     * @return string[]
     */
    public function allowedFor(string $kind, KeyOwner $owner): array
    {
        if ($kind === CredentialKind::PUBLIC) {
            return self::PUBLIC;
        }
        if ($owner->isModerator()) {
            return array_merge(self::PUBLIC, $this->moderator, $this->pluginFor([self::AUDIENCE_MODERATOR, self::AUDIENCE_USER]));
        }
        if ($owner->isAdmin()) {
            return array_merge(self::PUBLIC, self::ADMIN, $this->pluginFor(self::AUDIENCES));
        }
        // A key or a same-site session never holds account:write: no password, e-mail or key changes.
        $user = $kind === CredentialKind::USER ? self::USER : array_values(array_diff(self::USER, self::TOKEN_ONLY));

        return array_merge($user, $this->pluginFor([self::AUDIENCE_USER]));
    }

    /**
     * Whether holding $held grants $needed. Any admin scope also reads public data.
     *
     * @param string[] $held
     */
    public static function implies(array $held, string $needed): bool
    {
        if (in_array($needed, $held, true)) {
            return true;
        }
        foreach ($held as $scope) {
            if (in_array($needed, self::IMPLIES[$scope] ?? [], true)
                || ($needed === self::PUBLIC_READ && str_starts_with($scope, 'admin:'))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A scope list cut down to what is allowed, unique, in the allowed list's order.
     *
     * @param string[]|string $scopes  a list or a space separated string
     * @param string[]        $allowed
     *
     * @return string[]
     */
    public static function normalize(array|string $scopes, array $allowed): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
        }

        return array_values(array_unique(array_intersect($allowed, array_map('strval', $scopes))));
    }

    /**
     * @param string[] $audiences
     *
     * @return string[]
     */
    private function pluginFor(array $audiences): array
    {
        return array_keys(array_filter($this->plugin, static fn (array $s): bool => in_array($s['audience'], $audiences, true)));
    }
}
