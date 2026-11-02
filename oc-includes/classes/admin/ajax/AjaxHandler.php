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

/**
 * Base for the admin ajax handlers. One public method per action, named in AjaxRegistry;
 * the CSRF check and the moderator gate have already run when it is called.
 */
abstract class AjaxHandler
{
    public function __construct(protected \CAdminAjax $controller)
    {
    }

    /**
     * The answer a drag-and-drop reorder sends back.
     *
     * @return array{error:string}|array{ok:string}
     */
    protected static function orderResult(int $error): array
    {
        return $error ? array('error' => __('An error occurred')) : array('ok' => __('Order saved'));
    }
}
