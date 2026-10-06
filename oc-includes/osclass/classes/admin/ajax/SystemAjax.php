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

namespace mindstellar\admin\ajax;

use mindstellar\utility\AjaxResponse;
use Page;
use Params;
use User;

/**
 * Small admin ajax actions: test mails, the backup poll, the user lookup, page order.
 */
final class SystemAjax extends AjaxHandler
{
    /** Answers nothing; kept so the action is not reported as unknown. */
    public function bulkActions(): void
    {
    }

    public function errorPermissions(): void
    {
        AjaxResponse::json(array('error' => __("You don't have the necessary permissions")));
    }

    /** The user autocomplete. */
    public function userLookup(): void
    {
        $users = User::getInstance()->ajax(Params::getParam('term'));
        if (count($users) == 0) {
            AjaxResponse::json(array(
                0 => array(
                    'id'    => '',
                    'label' => __('No results'),
                    'value' => __('No results')
                )
            ));
        } else {
            AjaxResponse::json($users);
        }
    }

    public function testMail(): void
    {
        $title = sprintf(__('Test email, %s'), osc_page_title());
        $body  = __('Test email') . '<br><br>' . osc_page_title();

        self::sendTestMail(array(
            'subject'  => $title,
            'to'       => osc_contact_email(),
            'to_name'  => 'admin',
            'body'     => $body,
        ));
    }

    /** Sends mail to any address, so it needs the CSRF token. */
    public function testMailTemplate(): void
    {
        self::sendTestMail(array(
            'subject'  => Params::getParam('title'),
            'to'       => Params::getParam('email'),
            'to_name'  => 'admin',
            'body'     => Params::getParam('body', false, false),
        ));
    }

    public function backupStatus(): void
    {
        // The poll may run a backup step; it must not hold the session meanwhile.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Cache-Control: no-store');
        AjaxResponse::json(\mindstellar\backup\BackupService::poll());
    }

    /** Swaps a page with its neighbour above or below. Answers nothing. */
    public function orderPages(): void
    {
        $order = Params::getParam('order');
        $id    = Params::getParam('id');
        if ($order != '' && $id != '') {
            $mPages       = Page::getInstance();
            $actual_page  = $mPages->findByPrimaryKey((int) $id);
            $actual_order = $actual_page['i_order'];

            if ($order === 'up') {
                $page = $mPages->findPrevPage($actual_order);
            } elseif ($order === 'down') {
                $page = $mPages->findNextPage($actual_order);
            }
            if (isset($page['i_order'])) {
                $mPages->update(array('i_order' => $page['i_order']), array('pk_i_id' => $id));
                $mPages->update(array('i_order' => $actual_order), array('pk_i_id' => $page['pk_i_id']));
            }
        }
    }

    /**
     * @param array<string,mixed> $emailParams
     */
    private static function sendTestMail(array $emailParams): void
    {
        if (osc_sendMail($emailParams)) {
            $array = array('status' => '1', 'html' => __('Email sent successfully'));
        } else {
            $array = array('status' => '0', 'html' => __('An error occurred while sending email'));
        }
        AjaxResponse::json($array);
    }
}
