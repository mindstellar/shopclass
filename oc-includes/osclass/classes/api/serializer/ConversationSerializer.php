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

namespace mindstellar\api\serializer;

use mindstellar\messaging\ConversationStore;

/**
 * A conversation and its messages as one of its two people sees them. A held message shows to
 * its sender only.
 */
final class ConversationSerializer
{
    /**
     * @param array<string,mixed> $row    a t_conversation row the viewer is a party to
     * @param array<int,string>   $names  user id => display name
     * @param array<int,string>   $titles listing id => title
     *
     * @return array<string,mixed>
     */
    public function one(array $row, int $viewerId, array $names, array $titles): array
    {
        $side     = (string) ConversationStore::sideOf($row, $viewerId);
        $other    = $side === ConversationStore::SIDE_BUYER ? ConversationStore::SIDE_SELLER : ConversationStore::SIDE_BUYER;
        $buyerId  = Format::int($row['fk_i_buyer_id'] ?? 0);
        $sellerId = Format::int($row['fk_i_seller_id'] ?? 0);
        $itemId   = Format::int($row['fk_i_item_id'] ?? 0);

        return [
            'id'              => Format::int($row['pk_i_id'] ?? 0),
            'listing_id'      => $itemId,
            'listing_title'   => Format::text($titles[$itemId] ?? null),
            'side'            => $side,
            'buyer'           => ['id' => $buyerId, 'name' => Format::text($names[$buyerId] ?? null)],
            'seller'          => ['id' => $sellerId, 'name' => Format::text($names[$sellerId] ?? null)],
            'state'           => (string) ($row['s_state'] ?? 'open'),
            'unread'          => Format::int($row['i_' . $side . '_unread'] ?? 0),
            'blocked_by_me'   => Format::bool($row['b_' . $side . '_blocked'] ?? 0),
            'blocked_by_them' => Format::bool($row['b_' . $other . '_blocked'] ?? 0),
            'reported_by_me'  => Format::id($row['fk_i_reporter_id'] ?? null) === $viewerId,
            'last_message_at' => Format::time($row['dt_last_message'] ?? null),
            'created_at'      => Format::time($row['dt_created'] ?? null),
        ];
    }

    /**
     * A message, or null when the viewer may not see it: a held one someone else sent, or a
     * removed one.
     *
     * @param array<string,mixed> $row a t_message row
     *
     * @return array<string,mixed>|null
     */
    public function message(array $row, int $viewerId): ?array
    {
        $state  = (string) ($row['s_state'] ?? '');
        $sender = Format::int($row['fk_i_sender_id'] ?? 0);
        if ($state !== 'sent' && !($state === 'held' && $sender === $viewerId)) {
            return null;
        }

        return [
            'id'              => Format::int($row['pk_i_id'] ?? 0),
            'conversation_id' => Format::int($row['fk_i_conversation_id'] ?? 0),
            'sender_id'       => $sender,
            'body'            => (string) ($row['s_body'] ?? ''),
            'held'            => $state === 'held',
            'created_at'      => Format::time($row['dt_created'] ?? null),
        ];
    }

    /**
     * The messages the viewer may see, in the order given.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    public function messages(array $rows, int $viewerId): array
    {
        $out = [];
        foreach ($rows as $row) {
            $message = $this->message($row, $viewerId);
            if ($message !== null) {
                $out[] = $message;
            }
        }

        return $out;
    }

    /**
     * A thread as the admin sees it, both sides alike.
     *
     * @param array<string,mixed> $row    a t_conversation row
     * @param array<int,string>   $names  user id => display name
     * @param array<int,string>   $titles listing id => title
     *
     * @return array<string,mixed>
     */
    public function admin(array $row, array $names, array $titles): array
    {
        $buyerId  = Format::int($row['fk_i_buyer_id'] ?? 0);
        $sellerId = Format::int($row['fk_i_seller_id'] ?? 0);
        $itemId   = Format::int($row['fk_i_item_id'] ?? 0);
        $reporter = Format::id($row['fk_i_reporter_id'] ?? null);

        return [
            'id'              => Format::int($row['pk_i_id'] ?? 0),
            'listing_id'      => $itemId,
            'listing_title'   => Format::text($titles[$itemId] ?? null),
            'buyer'           => ['id' => $buyerId, 'name' => Format::text($names[$buyerId] ?? null)],
            'seller'          => ['id' => $sellerId, 'name' => Format::text($names[$sellerId] ?? null)],
            'state'           => (string) ($row['s_state'] ?? 'open'),
            'buyer_unread'    => Format::int($row['i_buyer_unread'] ?? 0),
            'seller_unread'   => Format::int($row['i_seller_unread'] ?? 0),
            'buyer_blocked'   => Format::bool($row['b_buyer_blocked'] ?? 0),
            'seller_blocked'  => Format::bool($row['b_seller_blocked'] ?? 0),
            'report'          => $reporter === null ? null : ['user_id' => $reporter, 'reason' => (string) ($row['s_report_reason'] ?? '')],
            'last_message_at' => Format::time($row['dt_last_message'] ?? null),
            'created_at'      => Format::time($row['dt_created'] ?? null),
        ];
    }

    /**
     * A message as the admin sees it: held and removed ones too, with the sender's name.
     *
     * @param array<string,mixed> $row        a t_message row
     * @param string|null         $senderName
     *
     * @return array<string,mixed>
     */
    public function adminMessage(array $row, int $listingId, ?string $senderName): array
    {
        $sender = Format::int($row['fk_i_sender_id'] ?? 0);
        $state  = (string) ($row['s_state'] ?? 'sent');

        return [
            'id'              => Format::int($row['pk_i_id'] ?? 0),
            'conversation_id' => Format::int($row['fk_i_conversation_id'] ?? 0),
            'listing_id'      => $listingId,
            'sender_id'       => $sender,
            'sender'          => ['id' => $sender, 'name' => Format::text($senderName)],
            'body'            => (string) ($row['s_body'] ?? ''),
            'held'            => $state === 'held',
            'state'           => $state,
            'created_at'      => Format::time($row['dt_created'] ?? null),
        ];
    }
}
