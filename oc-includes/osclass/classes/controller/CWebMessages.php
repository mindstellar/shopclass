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
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\InboxQuery;
use mindstellar\messaging\MessagePolicy;
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
     * One page of inbox rows, ready to print, and the link to the next page (or null).
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     * @throws RefusedException
     */
    private function listRows(Actor $actor, ?string $beforeDate, ?int $beforeId, int $currentId): array
    {
        $rows  = $this->service()->inbox($actor, self::PER_PAGE + 1, $beforeDate, $beforeId);
        $older = null;
        if (count($rows) > self::PER_PAGE) {
            $rows  = array_slice($rows, 0, self::PER_PAGE);
            $last  = $rows[self::PER_PAGE - 1];
            $older = osc_messaging_inbox_url() . (str_contains(osc_messaging_inbox_url(), '?') ? '&' : '?')
                . 'before=' . urlencode((string) $last['dt_last_message']) . '&before_id=' . (int) $last['pk_i_id'];
        }

        return array($this->query()->rows($rows, (int) $actor->userId(), $currentId), $older);
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
        $item         = $this->query()->listing((int) $conversation['fk_i_item_id']);
        $side         = (string) ConversationStore::sideOf($conversation, $userId);
        $theirs       = $side === ConversationStore::SIDE_BUYER ? 'b_seller_blocked' : 'b_buyer_blocked';
        $theirUnread  = (int) $conversation[$side === ConversationStore::SIDE_BUYER ? 'i_seller_unread' : 'i_buyer_unread'];
        $names        = UserStore::displayNames(array(
            (int) $conversation['fk_i_buyer_id'],
            (int) $conversation['fk_i_seller_id'],
        ));
        $otherName    = (string) ($names[ConversationStore::otherParty($conversation, $userId)] ?? _m('Former user'));

        $this->_exportVariableToView('conversation', $conversation);
        $this->_exportVariableToView('messages', $data['messages']);
        $this->_exportVariableToView('names', $names);
        $this->_exportVariableToView('other_name', $otherName);
        $this->_exportVariableToView('other_initial', InboxQuery::initial($otherName));
        $this->_exportVariableToView('other_hue', InboxQuery::hue(ConversationStore::otherParty($conversation, $userId)));
        $this->_exportVariableToView('seen_at', InboxQuery::seenAt($data['messages'], $userId, $theirUnread));
        $this->_exportVariableToView('title', $item['title'] ?? _m('Removed listing'));
        $this->_exportVariableToView('item_url', $item === null ? null : $item['url']);
        $this->_exportVariableToView('listing_live', $item !== null && $item['live']);
        $this->_exportVariableToView('listing_photo', $item['photo_url'] ?? '');
        $this->_exportVariableToView('listing_price', $item['price'] ?? '');
        $this->_exportVariableToView('role', $side === ConversationStore::SIDE_SELLER ? _m('Asking about your listing') : _m('Seller'));
        $this->_exportVariableToView('their_unread', $theirUnread);
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

    private function query(): InboxQuery
    {
        return Container::root()->get(InboxQuery::class);
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
