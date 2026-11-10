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

use mindstellar\admin\AdminNotices;
use mindstellar\utility\AjaxResponse;
use Params;

/**
 * Hide and Show on a notice, for the signed-in admin only. The panel comes back rendered by the server.
 */
final class NoticeAjax extends AjaxHandler
{
    public function hide(): void
    {
        $this->change('hide');
    }

    public function show(): void
    {
        $this->change('show');
    }

    private function change(string $how): void
    {
        if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
            AjaxResponse::json(array('error' => _m('Probable invalid request.')), 405);

            return;
        }
        $id      = Params::getParamString('id');
        [$admin, $moderator] = osc_admin_notice_viewer();
        $service = AdminNotices::getInstance();
        $ok      = $how === 'hide'
            ? $service->hide($admin, $moderator, $id)
            : $service->show($admin, $id);

        ob_start();
        $this->controller->doView('parts/notices-panel.php');
        $view  = osc_admin_notices();
        $marks = array();
        foreach ($view->marks() as $key => $mark) {
            $marks[$key] = array('group' => $mark['group'], 'name' => osc_admin_notice_name($mark['count'], $mark['action']));
        }

        AjaxResponse::json(array(
            'ok'     => $ok ? 1 : 0,
            'count'  => $view->count(),
            'action' => $view->actionCount(),
            'hidden' => count($view->hidden),
            'name'   => osc_admin_notice_name($view->count(), $view->actionCount(), true),
            'panel'  => (string) ob_get_clean(),
            'marks'  => $marks,
        ));
    }
}
