<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

/**
 * The ban-rule screen, declared once: its fields, the table row they are, the rule that
 * spans two of them, and the route the form posts back to. Registered on demand rather
 * than at boot, so its labels are translated.
 *
 * @package mindstellar\admin\form
 */
final class BanRuleForm
{
    public const PAGE_ID = 'core.ban_rule';

    /** Unprefixed; the store applies DB_TABLE_PREFIX. */
    public const TABLE = 't_ban_rule';

    public const PK = 'pk_i_id';

    /**
     * Declare the page, once per request.
     *
     * @return string the page id, so a caller can register and use it in one line
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        osc_admin_form(self::PAGE_ID)
            ->title(__('Ban rule'))
            // Reached from the ban-rule list, not from a menu of its own.
            ->menu('')
            ->store(self::TABLE, self::PK)
            ->onValidate(static function (array $values, string $pageId, $id) {
                // A rule matching neither an address nor an IP bans nobody, and the name
                // is only there to say why. Neither field can answer this alone.
                if (($values['s_ip'] ?? '') === '' && ($values['s_email'] ?? '') === '') {
                    // _m, not __: this is the message the screen has always shown, and it
                    // is already translated under that domain.
                    return _m('Both rules can not be empty');
                }

                return null;
            })
            // The lengths are the columns'. Declared, an over-long value comes back to be
            // shortened; undeclared, MySQL keeps as much of it as fits and says nothing.
            // Declared text is purified by default, which is what these three always were.
            ->text('s_name', __('Ban name / Reason'))
                ->set('maxlength', 250)
            ->text('s_ip', __('IP rule'), __('(e.g. 192.168.10-20.*)'))
                ->set('maxlength', 50)
            ->text('s_email', __('E-mail rule'), __('(e.g. *@badsite.com, *@subdomain.badsite.com, *@*badsite.com)'))
                // Addresses are matched lower-cased, so they are stored that way.
                ->sanitize(static fn ($value) => strtolower($value))
                ->set('maxlength', 250)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * Everything the view needs to draw the screen: which page, which route, what the
     * fields say now, and what the button is called.
     *
     * @param int|null            $id     the row being edited, or null when one is being
     *                                    added
     * @param array<string,mixed> $values values to show -- what is stored, or what was just
     *                                    rejected
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars($id, array $values): array
    {
        $edit = $id !== null;

        return array(
            'id'      => self::PAGE_ID,
            'title'   => $edit ? __('Edit rule') : __('Add new ban rule'),
            'name'    => 'register',
            'route'   => array(
                'page'   => 'users',
                'action' => $edit ? 'edit_ban_rule_post' : 'create_ban_rule_post',
                'id'     => $edit ? $id : '',
            ),
            'values'  => $values,
            'actions' => array(
                array(
                    'label'   => $edit ? __('Update rule') : __('Add new ban rule'),
                    'type'    => 'submit',
                    'variant' => 'primary',
                ),
            ),
        );
    }
}
