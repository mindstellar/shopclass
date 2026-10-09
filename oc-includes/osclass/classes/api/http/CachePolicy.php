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

namespace mindstellar\api\http;

use mindstellar\api\Request;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\PageTokens;

/**
 * Caching headers for a successful answer. Public answers may sit in a shared cache, key answers
 * and the OpenAPI document are private and revalidated, and writes and session answers are never stored.
 */
final class CachePolicy
{
    /** What a same-site session answer varies on. */
    public const SESSION_VARY = ['Authorization', PageTokens::HEADER, 'Cookie', 'Origin'];

    /** Paths whose answer changes with admin switches, so a shared cache must not keep them. */
    private const PRIVATE_PATHS = ['openapi.json'];

    public function __construct(private int $maxAge)
    {
    }

    public function isPublic(Request $request, Credential $credential): bool
    {
        return $request->isRead()
            && !in_array($request->routePath(), self::PRIVATE_PATHS, true)
            && ($credential->isAnonymous() || $credential->kind() === CredentialKind::PUBLIC);
    }

    /**
     * The Cache-Control value.
     */
    public function header(Request $request, Credential $credential): string
    {
        if (!$request->isRead() || $credential->isSession() || self::isPersonal($request->routePath())) {
            return 'private, no-store';
        }
        if (!$this->isPublic($request, $credential)) {
            return 'private, no-cache';
        }

        return $this->maxAge > 0
            ? 'public, max-age=' . $this->maxAge . ', stale-while-revalidate=' . $this->maxAge
            : 'public, no-cache';
    }

    /**
     * Whether a path below the version reads an account, admin data or sign-ins.
     */
    private static function isPersonal(string $path): bool
    {
        return preg_match('#^(?:account(?:/|$)|admin/)|(?:^|/)sessions(?:/|$)#', $path) === 1;
    }

    /**
     * The request headers a cached read varies on.
     *
     * @return string[]
     */
    public function vary(Request $request, bool $originMatters): array
    {
        if (!$request->isRead()) {
            return [];
        }

        $vary = ['Authorization', PageTokens::HEADER];

        return $originMatters ? [...$vary, 'Origin'] : $vary;
    }
}
