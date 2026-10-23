<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\NotFoundException;
use mindstellar\exception\RefusedException;
use mindstellar\listing\ListingStore;
use mindstellar\moderation\MessageModeration;
use mindstellar\security\Demo;

/**
 * Admin review of held messages and reported conversations (`?page=messages`).
 */
class CAdminMessages extends AdminSecBaseModel
{
    use \mindstellar\base\ActionMap;

    /** Rows on one page. */
    private const PER_PAGE = 20;

    /** Each action and the method that answers it; any other action shows the held tab. */
    private const ACTIONS = array(
        ''             => 'held',
        'reported'     => 'reported',
        'thread'       => 'thread',
        'release_post' => 'releasePost',
        'remove_post'  => 'removePost',
        'close_post'   => 'closePost',
        'clear_post'   => 'clearPost',
    );

    /**
     * Dispatches the screen's actions.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        $method = $this->actionMethod('held');
        if ($method !== null) {
            $this->$method();
        }
    }

    /**
     * Held messages, newest first, a page at a time.
     */
    private function held(): void
    {
        $before = Params::getParamInt('before');
        $rows   = $this->moderation()->held(self::PER_PAGE + 1, $before > 0 ? $before : null);
        $older  = $this->trim($rows, 'held');

        $titles = ListingStore::titles(array_map(
            static fn (array $row): int => (int) ($row['conversation']['fk_i_item_id'] ?? 0),
            $rows
        ));
        foreach ($rows as &$row) {
            $itemId            = (int) ($row['conversation']['fk_i_item_id'] ?? 0);
            $row['item_id']    = $itemId;
            $row['item_title'] = $titles[$itemId] ?? _m('Removed listing');
        }
        unset($row);

        $this->show('messages/index.php', 'held', $rows, $older);
    }

    /**
     * Reported conversations, newest first, a page at a time.
     */
    private function reported(): void
    {
        $before = Params::getParamInt('before');
        $rows   = $this->moderation()->reported(self::PER_PAGE + 1, $before > 0 ? $before : null);
        $older  = $this->trim($rows, 'reported');

        $titles = ListingStore::titles(array_map('intval', array_column($rows, 'fk_i_item_id')));
        foreach ($rows as &$row) {
            $row['item_id']    = (int) $row['fk_i_item_id'];
            $row['item_title'] = $titles[(int) $row['fk_i_item_id']] ?? _m('Removed listing');
        }
        unset($row);

        $this->show('messages/index.php', 'reported', $rows, $older);
    }

    /**
     * One conversation, every message oldest first; read only.
     */
    private function thread(): void
    {
        $id = Params::getParamInt('id');
        try {
            $thread = $this->moderation()->thread($id);
        } catch (NotFoundException $e) {
            osc_add_flash_error_message($e->getMessage(), 'admin');
            $this->redirectTo($this->url());

            return;
        }
        $itemId = (int) $thread['conversation']['fk_i_item_id'];
        $titles = ListingStore::titles(array($itemId));

        $this->_exportVariableToView('thread', $thread);
        $this->_exportVariableToView('item_id', $itemId);
        $this->_exportVariableToView('item_title', $titles[$itemId] ?? _m('Removed listing'));
        $this->_exportVariableToView('counts', $this->moderation()->counts());
        $this->doView('messages/thread.php');
    }

    /**
     * Deliver a held message.
     */
    private function releasePost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->act(function (MessageModeration $m, Actor $admin) use ($id): void {
            $m->release($id, $admin);
        }, _m('The message has been released'), '');
    }

    /**
     * Drop a held message.
     */
    private function removePost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->act(function (MessageModeration $m, Actor $admin) use ($id): void {
            $m->remove($id, $admin);
        }, _m('The message has been removed'), '');
    }

    /**
     * Close a reported conversation.
     */
    private function closePost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->act(function (MessageModeration $m, Actor $admin) use ($id): void {
            $m->close($id, $admin);
        }, _m('The conversation has been closed'), 'reported');
    }

    /**
     * Clear the report on a conversation.
     */
    private function clearPost(): void
    {
        osc_csrf_check();
        $id = Params::getParamInt('id');
        $this->act(function (MessageModeration $m, Actor $admin) use ($id): void {
            $m->clearReport($id, $admin);
        }, _m('The report has been cleared'), 'reported');
    }

    /**
     * Run one moderation call, flash the outcome and go back to the tab.
     *
     * @param callable $call called with the moderation class and the signed-in admin
     */
    private function act(callable $call, string $done, string $tab): void
    {
        try {
            $call($this->moderation(), Actor::fromSession(true));
            osc_add_flash_ok_message($done, 'admin');
        } catch (ForbiddenException $e) {
            osc_add_flash_error_message($e->reason() === ForbiddenException::DEMO ? Demo::message() : $e->getMessage(), 'admin');
        } catch (RefusedException $e) {
            osc_add_flash_error_message($e->getMessage(), 'admin');
        }
        $this->redirectTo($this->url($tab));
    }

    /**
     * Cut the extra row that tells there is an older page; returns the link to it.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function trim(array &$rows, string $tab): ?string
    {
        if (count($rows) <= self::PER_PAGE) {
            return null;
        }
        $rows = array_slice($rows, 0, self::PER_PAGE);

        return $this->url($tab === 'held' ? '' : $tab) . '&before=' . (int) $rows[self::PER_PAGE - 1]['pk_i_id'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function show(string $view, string $tab, array $rows, ?string $older): void
    {
        $this->_exportVariableToView('tab', $tab);
        $this->_exportVariableToView('rows', $rows);
        $this->_exportVariableToView('older', $older);
        $this->_exportVariableToView('counts', $this->moderation()->counts());
        $this->doView($view);
    }

    private function url(string $tab = ''): string
    {
        return osc_admin_base_url(true) . '?page=messages' . ($tab !== '' ? '&action=' . $tab : '');
    }

    private function moderation(): MessageModeration
    {
        return Container::root()->get(MessageModeration::class);
    }
}
