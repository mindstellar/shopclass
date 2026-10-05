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

use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\PageTokens;
use mindstellar\api\Request;

/**
 * Caching headers for a successful answer.
 *
 * What anyone may see (no credential, or a public key, which only reads the public view)
 * may sit in a shared cache. An answer for a particular key is private and revalidated with
 * its ETag (no-cache, not no-store). A write, and any answer to a same-site session call,
 * is never stored. Every read varies on Authorization and the page token header, and a session
 * answer on the cookie as well, so a shared cache never hands one caller's answer to another.
 */
final class CachePolicy
{
    /** What a same-site session answer varies on. */
    public const SESSION_VARY = ['Authorization', PageTokens::HEADER, 'Cookie', 'Origin'];

    public function __construct(private int $maxAge)
    {
    }

    public function isPublic(Request $request, Credential $credential): bool
    {
        return $request->isRead()
            && ($credential->isAnonymous() || $credential->kind() === CredentialKind::PUBLIC);
    }

    /**
     * The Cache-Control value.
     */
    public function header(Request $request, Credential $credential): string
    {
        if (!$request->isRead() || $credential->isSession()) {
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
