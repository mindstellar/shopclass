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

namespace mindstellar\comment;

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\security\ActionThrottle;
use mindstellar\security\AddressBucket;
use mindstellar\security\RateLimit;

/**
 * Who may post, see and delete comments, for the comment form and the API alike.
 */
final class CommentPolicy
{
    /** Comments one user, or one address for a guest, may post in an hour; see limit(). */
    public const LIMIT_CONTEXT = 'comment_post';
    public const PER_HOUR      = ActionThrottle::DEFAULT_LIMITS[self::LIMIT_CONTEXT];

    /** mayPost(): allowed. */
    public const ALLOWED = '';

    /** mayPost(): comments are switched off. */
    public const DISABLED = 'disabled';

    /** mayPost(): the site takes comments from signed-in users only. */
    public const REGISTERED_ONLY = 'registered_only';

    /** mayPost(): a ban rule matches the e-mail or the address. */
    public const BANNED = 'banned';

    private function __construct()
    {
    }

    /**
     * Whether this actor may comment with this e-mail.
     *
     * @return string ALLOWED, DISABLED, REGISTERED_ONLY or BANNED
     */
    public static function mayPost(Actor $actor, string $email): string
    {
        if (!osc_comments_enabled()) {
            return self::DISABLED;
        }
        if (in_array((int) osc_is_banned($email, $actor->ip()), [1, 2], true)) {
            return self::BANNED;
        }
        if (osc_reg_user_post_comments() && $actor->userId() === null) {
            return self::REGISTERED_ONLY;
        }

        return self::ALLOWED;
    }

    /**
     * Count one comment and say whether it is over the hourly limit, per user or, for a guest,
     * per address (an IPv6 /64 counts as one). Fails open, as RateLimit does.
     */
    public static function tooMany(Actor $actor): bool
    {
        $key = $actor->userId() !== null ? 'user:' . $actor->userId() : 'ip:' . AddressBucket::of($actor->ip());
        [$max, $window] = self::limit();
        if ($max <= 0) {
            return false;
        }

        return !RateLimit::hit(self::LIMIT_CONTEXT, $key, $max, $window);
    }

    /**
     * Seconds until the comment window starts again.
     */
    public static function retryAfter(): int
    {
        $window = self::limit()[1];

        return $window - (time() % $window);
    }

    /**
     * PER_HOUR in an hour, unless the action_throttle_limit filter changes it for 'comment_post'.
     *
     * @return array{0:int,1:int} max and window in seconds
     */
    private static function limit(): array
    {
        return ActionThrottle::limitFor(self::LIMIT_CONTEXT, self::PER_HOUR, 3600);
    }

    /**
     * @param array<string,mixed> $comment a t_item_comment row
     */
    public static function isAuthor(array $comment, Actor $actor): bool
    {
        return $actor->userId() !== null && (int) ($comment['fk_i_user_id'] ?? 0) === $actor->userId();
    }

    /**
     * Approved, not blocked and not spam.
     *
     * @param array<string,mixed> $comment
     */
    public static function isLive(array $comment): bool
    {
        return (int) ($comment['b_active'] ?? 0) === 1 && (int) ($comment['b_enabled'] ?? 0) === 1 && (int) ($comment['b_spam'] ?? 0) === 0;
    }

    /**
     * One comment: its author always sees it; anyone else a live one on a listing they can
     * see, while comments are on.
     *
     * @param array<string,mixed>       $comment
     * @param array<string,mixed>|false $item    the comment's listing
     */
    public static function canView(array $comment, array|false $item, Actor $actor): bool
    {
        if (self::isAuthor($comment, $actor)) {
            return true;
        }

        return self::isLive($comment) && osc_comments_enabled() && is_array($item) && $item !== [] && ListingPolicy::canView($item, $actor);
    }
}
