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

namespace mindstellar\security;

/**
 * Asks Akismet, when the site has a key, whether a text is spam. An Akismet outage counts as
 * not spam, so it never blocks a post.
 */
final class SpamCheck
{
    private function __construct()
    {
    }

    public static function akismet(string $name, string $email, string $text, ?string $ip = null, ?string $permalink = null, ?string $type = null): bool
    {
        $key = (string) osc_akismet_key();
        if ($key === '') {
            return false;
        }
        $akismet = new \Akismet(osc_base_url(), $key);
        $akismet->setCommentAuthor($name);
        $akismet->setCommentAuthorEmail($email);
        $akismet->setCommentContent($text);
        if ($ip !== null && $ip !== '') {
            $akismet->setUserIP($ip);
        }
        if ($permalink !== null) {
            $akismet->setPermalink($permalink);
        }
        if ($type !== null) {
            $akismet->setCommentType($type);
        }
        try {
            return (bool) $akismet->isCommentSpam();
        } catch (\Throwable $e) {
            trigger_error($e->getMessage(), E_USER_NOTICE);

            return false;
        }
    }
}
