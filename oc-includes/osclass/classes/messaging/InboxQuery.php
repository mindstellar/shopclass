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

use mindstellar\listing\ListingStore;
use mindstellar\listing\PhotoStore;
use mindstellar\user\UserStore;

/**
 * What the messages screen prints: inbox rows and the open thread's person and listing card.
 * Reads only. A page of rows costs a fixed number of reads however many rows it has.
 */
final class InboxQuery
{
    public function __construct(private MessageStore $messages)
    {
    }

    /**
     * Inbox rows ready to print: titles, names, last messages, listing cards and photos.
     *
     * @param array<int,array<string,mixed>> $threads rows from MessagingService::inbox()
     *
     * @return array<int,array<string,mixed>>
     * @throws \mindstellar\database\DbException
     */
    public function rows(array $threads, int $userId, int $currentId = 0): array
    {
        $facts    = $this->listings(array_map('intval', array_column($threads, 'fk_i_item_id')));
        $names    = UserStore::displayNames(array_map(
            static fn (array $row): int => ConversationStore::otherParty($row, $userId),
            $threads
        ));
        $previews = $this->messages->lastVisible(array_map('intval', array_column($threads, 'pk_i_id')), $userId);
        foreach ($threads as &$row) {
            $id   = (int) $row['pk_i_id'];
            $item = $facts[(int) $row['fk_i_item_id']] ?? null;
            $side = (string) ConversationStore::sideOf($row, $userId);
            $name = $names[ConversationStore::otherParty($row, $userId)] ?? _m('Former user');
            $last = $previews[$id] ?? null;
            $row['title']        = $item['title'] ?? _m('Removed listing');
            $row['other_name']   = $name;
            $row['side']         = $side;
            $row['unread']       = (int) $row[$side === ConversationStore::SIDE_BUYER ? 'i_buyer_unread' : 'i_seller_unread'];
            $row['url']          = osc_messaging_thread_url($id);
            $row['initial']      = self::initial($name);
            $row['preview']      = $last === null ? '' : trim((string) preg_replace('/\s+/u', ' ', (string) $last['s_preview']));
            $row['preview_mine'] = $last !== null && (int) $last['fk_i_sender_id'] === $userId;
            $row['short_time']   = MessageTime::short((string) $row['dt_last_message']);
            $row['photo_url']    = $item['photo_url'] ?? '';
            $row['price']        = $item['price'] ?? null;
            $row['listing_live'] = $item !== null && $item['live'];
            $row['is_current']   = $id === $currentId;
        }
        unset($row);

        return $threads;
    }

    /**
     * The open thread's listing card, or null when the listing is gone. The price is formatted.
     *
     * @return array{title:string,url:string,live:bool,photo_url:string,price:string}|null
     * @throws \mindstellar\database\DbException
     */
    public function listing(int $itemId): ?array
    {
        $item = $this->listings(array($itemId))[$itemId] ?? null;
        if ($item === null) {
            return null;
        }
        $symbol = '';
        if ($item['currency'] !== null) {
            $currency = \Currency::getInstance()->findByPrimaryKey($item['currency']);
            $symbol   = is_array($currency) ? (string) $currency['s_description'] : '';
        }

        return array(
            'title'     => $item['title'],
            'url'       => osc_item_url_ns($itemId),
            'live'      => $item['live'],
            'photo_url' => $item['photo_url'],
            'price'     => (string) osc_format_price($item['price'], $symbol),
        );
    }

    /** The first letter of a name, upper case, for the avatar badge. */
    public static function initial(string $name): string
    {
        return mb_strtoupper(mb_substr(trim($name), 0, 1));
    }

    /**
     * The index of my message that shows "Seen", or -1: my last delivered message, when the other
     * side has read everything and has not written after it.
     *
     * @param array<int,array<string,mixed>> $messages oldest first
     */
    public static function seenAt(array $messages, int $meId, int $theirUnread): int
    {
        if ($theirUnread !== 0) {
            return -1;
        }
        $seenAt = -1;
        foreach (array_values($messages) as $i => $m) {
            if ((int) $m['fk_i_sender_id'] !== $meId) {
                $seenAt = -1;
            } elseif ((string) $m['s_state'] !== 'held') {
                $seenAt = $i;
            }
        }

        return $seenAt;
    }

    /**
     * Title, price, whether visitors can see it and the first photo's thumbnail of each listing.
     * A listing that is gone is left out. Three reads.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array{title:string,price:?int,currency:?string,live:bool,photo_url:string}>
     * @throws \mindstellar\database\DbException
     */
    private function listings(array $itemIds): array
    {
        $titles = ListingStore::titles($itemIds);
        $cards  = ListingStore::cards($itemIds);
        $photos = array();
        foreach (PhotoStore::ofItems(array_keys($cards)) as $photo) {
            $itemId = (int) $photo['fk_i_item_id'];
            if (!isset($photos[$itemId]) && str_starts_with((string) $photo['s_content_type'], 'image/')) {
                $photos[$itemId] = osc_get_resource_url($photo, 'thumbnail');
            }
        }
        $facts = array();
        foreach ($cards as $id => $card) {
            $facts[$id] = array(
                'title'     => $titles[$id] ?? _m('Removed listing'),
                'price'     => $card['price'],
                'currency'  => $card['currency'],
                'live'      => $card['live'],
                'photo_url' => $photos[$id] ?? '',
            );
        }

        return $facts;
    }
}
