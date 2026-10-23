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

namespace mindstellar\messaging;

use mindstellar\auth\Actor;
use mindstellar\security\ActionThrottle;

/**
 * Who may see and write in a conversation, and the names of the hourly message limits.
 */
final class MessagePolicy
{
    /** Messages one user may send in an hour. */
    public const SEND_CONTEXT  = 'message_send';
    public const PER_HOUR_SEND = ActionThrottle::DEFAULT_LIMITS[self::SEND_CONTEXT];

    /** New conversations one user may start in an hour. */
    public const START_CONTEXT  = 'message_start';
    public const PER_HOUR_START = ActionThrottle::DEFAULT_LIMITS[self::START_CONTEXT];

    /** canSend(): allowed. */
    public const ALLOWED = '';

    /** canSend(): either side blocked the conversation. */
    public const BLOCKED = 'blocked';

    /** canSend(): an admin closed the conversation. */
    public const CLOSED = 'closed';

    /** canSend(): the user is not the buyer or the seller. */
    public const NOT_PARTY = 'not_party';

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $conversation a t_conversation row
     */
    public static function isParty(array $conversation, Actor $actor): bool
    {
        return self::sideOf($conversation, $actor) !== null;
    }

    /**
     * 'buyer' or 'seller' for the actor, null for anyone else.
     *
     * @param array<string,mixed> $conversation
     */
    public static function sideOf(array $conversation, Actor $actor): ?string
    {
        return ConversationStore::sideOf($conversation, (int) $actor->userId());
    }

    /**
     * A party or an admin. A thread held for review is hidden from its seller.
     *
     * @param array<string,mixed> $conversation
     */
    public static function canView(array $conversation, Actor $actor): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        $side = self::sideOf($conversation, $actor);

        return $side === ConversationStore::SIDE_BUYER
            || ($side === ConversationStore::SIDE_SELLER && (string) $conversation['s_state'] !== 'held');
    }

    /**
     * Whether the user may write in the conversation now.
     *
     * @param array<string,mixed> $conversation
     *
     * @return string ALLOWED, BLOCKED, CLOSED or NOT_PARTY
     */
    public static function canSend(array $conversation, int $userId): string
    {
        if (ConversationStore::sideOf($conversation, $userId) === null) {
            return self::NOT_PARTY;
        }
        if ((int) $conversation['b_buyer_blocked'] === 1 || (int) $conversation['b_seller_blocked'] === 1) {
            return self::BLOCKED;
        }

        return (string) $conversation['s_state'] === 'closed' ? self::CLOSED : self::ALLOWED;
    }
}
