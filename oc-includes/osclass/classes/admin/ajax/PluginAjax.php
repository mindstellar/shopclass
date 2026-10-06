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

use mindstellar\security\PluginAjaxFile;
use mindstellar\utility\AjaxResponse;
use Params;
use Rewrite;

/**
 * The plugin entry points: runhook fires an ajax_admin_* hook, custom runs a plugin's ajax file.
 */
final class PluginAjax extends AjaxHandler
{
    public function runHook(): void
    {
        $hook = Params::getParam('hook');

        if ($hook == '') {
            AjaxResponse::json(array('error' => 'hook parameter not defined'));

            return;
        }

        switch ($hook) {
            case 'item_form':
                osc_run_hook('item_form', Params::getParam('catId'));
                break;
            case 'item_edit':
                $catId  = Params::getParam('catId');
                $itemId = Params::getParam('itemId');
                osc_run_hook('item_edit', $catId, $itemId);
                break;
            default:
                osc_run_hook('ajax_admin_' . $hook);
                break;
        }
    }

    public function custom(): void
    {
        if (Params::existParam('route')) {
            $routes = Rewrite::getInstance()->getRoutes();
            $rid    = Params::getParam('route');
            $file   = $routes[$rid]['file'] ?? '../';
        } else {
            $file = Params::getParam('ajaxfile');
        }

        if ($file == '') {
            AjaxResponse::json(array('error' => 'no action defined'));

            return;
        }

        if (strpos($file, '../') !== false || strpos($file, '..\\') !== false) {
            AjaxResponse::json(array('error' => 'no valid file'));

            return;
        }

        if (!file_exists(osc_plugins_path() . $file)) {
            AjaxResponse::json(array('error' => "file doesn't exist"));

            return;
        }

        // The file is run, so resolve it first: .php only, and inside the plugins
        // directory once symlinks are followed.
        $resolved = PluginAjaxFile::resolve($file, osc_plugins_path());
        if ($resolved === null) {
            AjaxResponse::json(array('error' => 'no valid file'));

            return;
        }

        $this->controller->runPluginFile($file, $resolved);
    }
}
