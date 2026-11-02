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

namespace mindstellar\admin\form;

use mindstellar\base\SettingsScreen;

/**
 * A settings screen under Tools: the same declared form, posted to the tools controller.
 */
abstract class ToolsSettingsScreen extends SettingsScreen
{
    /** Extra query values the tools action expects beside page and action. */
    protected const ROUTE_FIELDS = array();

    /**
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *
     * @return array<string,mixed>
     */
    public static function formVars(?array $values = null): array
    {
        $vars          = parent::formVars($values);
        $vars['route'] = array('page' => 'tools', 'action' => static::ACTION) + static::ROUTE_FIELDS;

        return $vars;
    }
}
