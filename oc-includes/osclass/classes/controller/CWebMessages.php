<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\exception\BlockedException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\listing\ListingStore;
use mindstellar\listing\PhotoStore;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\MessagePolicy;
use mindstellar\messaging\MessageStore;
use mindstellar\messaging\MessageTime;
use mindstellar\messaging\MessagingService;
use mindstellar\user\UserStore;

/**
 * The signed-in user's inbox and conversations (`?page=messages`).
 */
class CWebMessages extends WebSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Threads on one inbox page. */
    private const PER_PAGE = 20;

    /** Each action and the method that answers it; any other action shows the inbox or a thread. */
    private const ACTIONS = array(
        ''            => 'index',
        'send_post'   => 'sendPost',
        'block_post'  => 'blockPost',
        'unblock_post' => 'unblockPost',
        'report_post' => 'reportPost',
    );

    /**
     * Boots the secured base controller and sends the visitor to the dashboard while messaging is off.
     */
    public function __construct()
    {
        parent::__construct();
        if (!osc_messaging_enabled()) {
            osc_add_flash_error_message(_m('Messages are switched off on this site'));
            $this->redirectTo(osc_user_dashboard_url());
        }
    }

    /**
     * Sends a signed-out visitor to the login, remembering the inbox or thread they asked for,
     * so a link from a new-message mail lands on the thread after sign-in.
     *
     * @return void
     */
    public function showAuthFailPage()
    {
        if (Params::getServerParam('REQUEST_METHOD') === 'GET') {
            osc_set_login_redirect(osc_base_url() . Params::getRequestURI(false, false, false));
        }
        parent::showAuthFailPage();
    }

    /**
     * Dispatches the page's actions.
     *
     * @return void
     */
    public function doModel()
    {
        $method = $this->actionMethod('index');
        if ($method === null) {
            return;
        }
        $this->redirectShopperHome();

        $this->$method();
    }

    /**
     * A conversation when the request names one, else the inbox.
     */
    private function index(): void
    {
        if (Params::getParamInt('id') > 0) {
            $this->thread();

            return;
        }
        $this->inbox();
    }

    /**
     * The user's conversations, newest first, a page at a time.
     */
    private function inbox(): void
    {
        $before     = Params::getParamString('before');
        $beforeDate = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before) === 1 ? $before : null;
        $beforeId   = $beforeDate !== null ? Params::getParamInt('before_id') : null;

        try {
            [$rows, $older] = $this->listRows(Actor::visitor(), $beforeDate, $beforeId, 0);
        } catch (RefusedException $e) {
            osc_add_flash_error_message($e->getMessage());
            $this->redirectTo(osc_user_dashboard_url());

            return;
        }

        $this->_exportVariableToView('conversations', $rows);
        $this->_exportVariableToView('older', $older);
        $this->_exportVariableToView('current_id', 0);
        $this->doView(osc_locate_template(array('user-messages.php'), 'user-messages'));
    }

    /**
     * One page of inbox rows, ready to print, and the link to the next page (or null). Five
     * reads however many rows: the threads, titles, names, last messages, listing cards and photos.
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     * @throws RefusedException
     */
    private function listRows(Actor $actor, ?string $beforeDate, ?int $beforeId, int $currentId): array
    {
        $userId = (int) $actor->userId();
        $rows   = $this->service()->inbox($actor, self::PER_PAGE + 1, $beforeDate, $beforeId);
        $older  = null;
        if (count($rows) > self::PER_PAGE) {
            $rows  = array_slice($rows, 0, self::PER_PAGE);
            $last  = $rows[self::PER_PAGE - 1];
            $older = osc_messaging_inbox_url() . (str_contains(osc_messaging_inbox_url(), '?') ? '&' : '?')
                . 'before=' . urlencode((string) $last['dt_last_message']) . '&before_id=' . (int) $last['pk_i_id'];
        }

        $facts    = $this->listingFacts(array_map('intval', array_column($rows, 'fk_i_item_id')));
        $names    = UserStore::displayNames(array_map(
            static fn (array $row): int => ConversationStore::otherParty($row, $userId),
            $rows
        ));
        $previews = Container::root()->get(MessageStore::class)
            ->lastVisible(array_map('intval', array_column($rows, 'pk_i_id')), $userId);
        foreach ($rows as &$row) {
            $id       = (int) $row['pk_i_id'];
            $item     = $facts[(int) $row['fk_i_item_id']] ?? null;
            $side     = (string) ConversationStore::sideOf($row, $userId);
            $name     = $names[ConversationStore::otherParty($row, $userId)] ?? _m('Former user');
            $last     = $previews[$id] ?? null;
            $row['title']        = $item['title'] ?? _m('Removed listing');
            $row['other_name']   = $name;
            $row['side']         = $side;
            $row['unread']       = (int) $row[$side === ConversationStore::SIDE_BUYER ? 'i_buyer_unread' : 'i_seller_unread'];
            $row['url']          = osc_messaging_thread_url($id);
            $row['initial']      = mb_strtoupper(mb_substr(trim($name), 0, 1));
            $row['preview']      = $last === null ? '' : trim((string) preg_replace('/\s+/u', ' ', (string) $last['s_preview']));
            $row['preview_mine'] = $last !== null && (int) $last['fk_i_sender_id'] === $userId;
            $row['short_time']   = MessageTime::short((string) $row['dt_last_message']);
            $row['photo_url']    = $item['photo_url'] ?? '';
            $row['price']        = $item['price'] ?? null;
            $row['listing_live'] = $item !== null && $item['live'];
            $row['is_current']   = $id === $currentId;
        }
        unset($row);

        return array($rows, $older);
    }

    /**
     * What a row or the chat header shows of each listing: title, price, whether visitors can see
     * it and the first photo's thumbnail. A listing that is gone is left out. Three reads.
     *
     * @param int[] $itemIds
     *
     * @return array<int,array{title:string,price:?int,currency:?string,live:bool,photo_url:string}>
     */
    private function listingFacts(array $itemIds): array
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

    /**
     * One conversation, oldest message first. Opening it marks it read.
     */
    private function thread(): void
    {
        $id     = Params::getParamInt('id');
        $actor  = Actor::visitor();
        $userId = (int) $actor->userId();

        try {
            $data = $this->service()->thread($id, $actor);
            $this->service()->markRead($id, $actor);
            [$rows, $older] = $this->listRows($actor, null, null, $id);
        } catch (RefusedException $e) {
            osc_add_flash_error_message($e->getMessage());
            $this->redirectTo(osc_messaging_inbox_url());

            return;
        }

        $conversation = $data['conversation'];
        $itemId       = (int) $conversation['fk_i_item_id'];
        $item         = $this->listingFacts(array($itemId))[$itemId] ?? null;
        $side         = (string) ConversationStore::sideOf($conversation, $userId);
        $theirs       = $side === ConversationStore::SIDE_BUYER ? 'b_seller_blocked' : 'b_buyer_blocked';
        $symbol       = '';
        if ($item !== null && $item['currency'] !== null) {
            $currency = Currency::getInstance()->findByPrimaryKey($item['currency']);
            $symbol   = is_array($currency) ? (string) $currency['s_description'] : '';
        }

        $this->_exportVariableToView('conversation', $conversation);
        $this->_exportVariableToView('messages', $data['messages']);
        $this->_exportVariableToView('names', UserStore::displayNames(array(
            (int) $conversation['fk_i_buyer_id'],
            (int) $conversation['fk_i_seller_id'],
        )));
        $this->_exportVariableToView('title', $item['title'] ?? _m('Removed listing'));
        $this->_exportVariableToView('item_url', $item === null ? null : osc_item_url_ns($itemId));
        $this->_exportVariableToView('listing_live', $item !== null && $item['live']);
        $this->_exportVariableToView('listing_photo', $item['photo_url'] ?? '');
        $this->_exportVariableToView('listing_price', $item === null ? '' : osc_format_price($item['price'], $symbol));
        $this->_exportVariableToView('role', $side === ConversationStore::SIDE_SELLER ? _m('Asking about your listing') : _m('Seller'));
        $this->_exportVariableToView('their_unread', (int) $conversation[$side === ConversationStore::SIDE_BUYER ? 'i_seller_unread' : 'i_buyer_unread']);
        $this->_exportVariableToView('server_now', time());
        $this->_exportVariableToView('can_send', MessagePolicy::canSend($conversation, $userId));
        $this->_exportVariableToView('my_block', (int) $conversation['b_' . $side . '_blocked'] === 1);
        $this->_exportVariableToView('their_block', (int) $conversation[$theirs] === 1);
        $this->_exportVariableToView('conversations', $rows);
        $this->_exportVariableToView('older', $older);
        $this->_exportVariableToView('current_id', $id);
        $this->doView(osc_locate_template(array('user-conversation.php'), 'user-conversation'));
    }

    /**
     * Write in a conversation.
     */
    private function sendPost(): void
    {
        osc_csrf_check();
        $id   = Params::getParamInt('id');
        $body = Params::getParamString('body', false, false, false);
        try {
            $sent = $this->service()->reply($id, $body, Actor::visitor());
            osc_add_flash_ok_message($sent->held() ? _m('Your message is waiting for review') : _m('Message sent'));
        } catch (BlockedException $e) {
            osc_add_flash_error_message(osc_message_throttle_message($e->retryAfter()));
        } catch (InvalidException $e) {
            osc_keep_form(array('body' => $body), $e->getMessage());
        } catch (RefusedException $e) {
            osc_add_flash_error_message($e->getMessage());
        }
        $this->redirectTo(osc_messaging_thread_url($id));
    }

    /**
     * Block the conversation for both sides.
     */
    private function blockPost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->change($id, function () use ($id): void {
            $this->service()->block($id, Actor::visitor());
        }, _m('Conversation blocked'));
    }

    /**
     * Lift the user's own block.
     */
    private function unblockPost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->change($id, function () use ($id): void {
            $this->service()->unblock($id, Actor::visitor());
        }, _m('Conversation unblocked'));
    }

    /**
     * Report the conversation to the admins.
     */
    private function reportPost(): void
    {
        osc_csrf_check();
        $id     = Params::getParamInt('id');
        $reason = Params::getParamString('reason');
        $this->change($id, function () use ($id, $reason): void {
            $this->service()->report($id, $reason, Actor::visitor());
        }, _m('Thanks, the conversation was reported to the admins'));
    }

    /**
     * Runs a change on a conversation, flashes the result and goes back to it.
     */
    private function change(int $id, callable $do, string $done): void
    {
        try {
            $do();
            osc_add_flash_ok_message($done);
        } catch (RefusedException $e) {
            osc_add_flash_error_message($e->getMessage());
        }
        $this->redirectTo(osc_messaging_thread_url($id));
    }

    private function service(): MessagingService
    {
        return Container::root()->get(MessagingService::class);
    }

    /**
     * Renders the account template, falling back to core's view when the theme has none.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        if (!osc_gui_account_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebMessages.php */
