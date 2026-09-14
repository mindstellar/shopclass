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
 * The administrator account screen, declared once: its fields, the row of t_admin they
 * are, the rules that span more than one of them, and the route the form posts back to.
 *
 * The screen is three forms rather than one, and which one it is cannot be read off a
 * submitted value: adding has no confirmation box and demands a password, editing has a
 * confirmation box and takes a blank one as "leave it alone", and editing your own
 * account offers no account type, because nobody may promote themselves. Each is
 * therefore its own declaration under its own id, built from one method so the three
 * cannot drift.
 *
 * Three of the controls are not columns. The confirmation box exists to be compared with
 * the new password, and the current-password box is the re-authentication this screen has
 * always required before an administrator is created or changed; both are declared with
 * 'persist' => false so core collects and validates them and writes neither. The new
 * password itself is declared with a persist callable, so the column takes the hash and a
 * blank box writes nothing at all. All three are write-only, so nothing about a stored
 * password is ever drawn into the page.
 *
 * @package mindstellar\admin\form
 */
final class AdminAccountForm
{
    public const PAGE_ID = 'core.admin_account';

    /** Unprefixed; the store applies DB_TABLE_PREFIX. */
    public const TABLE = 't_admin';

    public const PK = 'pk_i_id';

    /**
     * Widths of t_admin, from installer/struct.sql. Declared, an over-long value comes
     * back to be shortened; undeclared, a relaxed connection keeps as much of it as fits
     * and says nothing.
     */
    public const WIDTHS = array(
        's_name'     => 100,
        's_username' => 40,
        's_email'    => 100,
    );

    /**
     * Declare the form this request needs, once per request.
     *
     * @param bool $edit       an existing account rather than a new one
     * @param bool $canSetType whether the account type is this admin's to change, which
     *                         it is for every account but their own
     *
     * @return string the page id, so a caller can register and use it in one line
     */
    public static function register(bool $edit, bool $canSetType): string
    {
        $id = self::PAGE_ID . ($edit ? ($canSetType ? '.edit' : '.self') : '.add');
        if (osc_settings_page($id) !== null) {
            return $id;
        }

        $form = osc_admin_form($id)
            ->title($edit ? __('Edit admin') : __('Add admin'))
            // Reached from the admin list and from the topbar, not from a menu of its own.
            ->menu('')
            ->store(self::TABLE, self::PK)
            ->onValidate(static function (array $values, string $pageId, $rowId) use ($edit) {
                return self::rules($values, $rowId, $edit);
            })
            // Client-side validation goes first inside the form, so the browser is handed
            // the element stream the screen ships.
            ->custom('form_js', static function () {
                \AdminForm::js_validation();
            })
                ->set('row', false)
            ->text('s_name', __('Name'))
                ->set('label_html', __('Name <em>(required)</em>'))
                ->required()
                ->set('maxlength', self::WIDTHS['s_name'])
            ->text('s_username', __('Username'))
                ->set('label_html', __('Username <em>(required)</em>'))
                ->required()
                ->set('maxlength', self::WIDTHS['s_username'])
                // _m, not __: the message the screen has always shown for this, already
                // translated under that domain.
                ->validate(static fn ($value) => osc_validate_username($value) ? null : _m('Username invalid'))
            ->email('s_email', __('E-mail'))
                ->set('label_html', __('E-mail <em>(required)</em>'))
                ->required()
                ->set('maxlength', self::WIDTHS['s_email']);

        if ($canSetType) {
            $form
                ->select('b_moderator', __('Admin type'), array(
                    '0' => __('Administrator'),
                    '1' => __('Moderator'),
                ), __('Administrators have total control over all aspects of your installation, '
                      . 'while moderators are only allowed to moderate listings, comments and media files'))
                ->set('label_html', __('Admin type <em>(required)</em>'))
                ->required()
                ->default('0');
        }

        $form
            ->secret('s_password', __('New password'))
                ->width('text')
                // The column takes the hash, and a blank box takes nothing: on an edit that
                // leaves the password exactly as it was, which is what the empty box has
                // always meant here.
                ->persist(static fn ($value) => $value === '' ? null : osc_hash_password($value))
                // The stored form is a hash, which is not the thing this control shows.
                ->writeOnly();

        if (!$edit) {
            // A new account with no password is one nobody can log into, so the box is
            // required here and only here.
            $form->required();
        }

        if ($edit) {
            $form
                ->secret('s_password2', __('Confirm new password'), __('Type your new password again'))
                    ->width('text')
                    ->persist(false)
                    ->writeOnly();
        }

        $form
            ->custom('password_separator', static function () {
                echo '<hr/>';
            })
                ->set('row', false)
            ->secret('old_password', __('Your current password'))
                ->width('text')
                ->set('help_html', __('For security, type <b>your current password</b>'))
                // Never a column: this is the acting administrator proving who they are,
                // not a value belonging to the account being written.
                ->persist(false)
                ->writeOnly()
            // The one place a plugin has ever been able to add to this form. It is given
            // the row being edited, and null while one is being added, exactly as before.
            ->custom('profile_hook', static function () {
                osc_run_hook('admin_profile_form', __get('admin'));
            })
                ->set('row', false)
            ->register();

        return $id;
    }

    /**
     * Everything the view needs to draw the screen: which page, which route, what the
     * fields say now, and what the buttons are called.
     *
     * @param int|null            $id         the account being edited, or null when one is
     *                                        being added
     * @param array<string,mixed> $values     values to show -- what is stored, or what was
     *                                        just rejected
     * @param bool                $canSetType whether the account type is this admin's to change
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars($id, array $values, bool $canSetType): array
    {
        $edit = $id !== null;

        // A password is never drawn back into the page, not even the one that was just
        // typed: a redrawn form would put the acting administrator's own password in the
        // markup, where the browser caches it and anything reading the page can see it.
        foreach (array('s_password', 's_password2', 'old_password') as $secret) {
            if (array_key_exists($secret, $values)) {
                $values[$secret] = '';
            }
        }

        $actions = array();
        if ($edit) {
            $actions[] = array(
                'label'   => __('Cancel'),
                'url'     => 'javascript:history.go(-1)',
                'variant' => 'dim',
            );
        }
        $actions[] = array(
            'label'   => $edit ? __('Save') : __('Add'),
            'type'    => 'submit',
            'variant' => 'primary',
        );

        return array(
            'id'      => self::register($edit, $canSetType),
            'title'   => $edit ? __('Edit admin') : __('Add admin'),
            'name'    => 'admin_form',
            'route'   => array(
                'page'   => 'admins',
                'action' => $edit ? 'edit_post' : 'add_post',
                'id'     => $edit ? $id : '',
            ),
            'values'  => $values,
            'actions' => $actions,
        );
    }

    /**
     * One row of t_admin by primary key, or an empty array when there is none.
     *
     * Read through the query builder rather than the model, whose findByPrimaryKey()
     * memoises per instance -- an existence check answering from a cache can answer for a
     * row somebody has since deleted. Columns stay strings: this row reaches the
     * admin_profile_form hook, and no plugin has ever been handed an int here.
     *
     * @param int $id
     *
     * @return array<string,string|null> the row as strings, or empty when there is none
     * @throws \mindstellar\database\DbException on a failed read
     */
    public static function row(int $id): array
    {
        if ($id <= 0) {
            return array();
        }

        // The builder is immutable: a clause that is not reassigned is dropped, and a
        // dropped WHERE here would hand back the first administrator in the table.
        $query = osc_db_table(DB_TABLE_PREFIX . self::TABLE);
        $query = $query->where(self::PK, $id);
        $row   = $query->first();

        return $row === null ? array() : osc_db_stringify_row($row);
    }

    /**
     * The rules that no single field can answer: the re-authentication, the confirmation,
     * and the two columns that are unique.
     *
     * @param array<string,mixed> $values validated values, keyed by field name
     * @param int|null            $rowId  the account being saved, null while one is being added
     * @param bool                $edit
     *
     * @return array<int,string> one message per rule that failed
     * @throws \mindstellar\database\DbException on a failed read
     */
    private static function rules(array $values, $rowId, bool $edit): array
    {
        $errors = array();

        // The acting administrator's own password, not the edited account's: this is what
        // stops a walked-away-from session being used to mint another administrator. Blank
        // and wrong are answered identically, so the reply says nothing about which it was.
        $typed   = (string)($values['old_password'] ?? '');
        $current = self::row(osc_logged_admin_id());
        $stored  = (string)($current['s_password'] ?? '');
        if ($typed === '' || $stored === '' || !osc_verify_password($typed, $stored)) {
            $errors[] = _m('Incorrent current password');
        }

        $password = (string)($values['s_password'] ?? '');
        if ($edit && $password !== '' && $password !== (string)($values['s_password2'] ?? '')) {
            $errors[] = _m("The password couldn't be updated. Passwords don't match");
        }

        // Both columns are UNIQUE, so a duplicate is refused by the database either way --
        // as an exception the admin cannot read, after the form has been thrown away.
        $taken = array(
            's_email'    => $rowId === null ? _m('Email already in use') : _m('Existing email'),
            's_username' => $rowId === null ? _m('Username already in use') : _m('Existing username'),
        );
        foreach ($taken as $column => $message) {
            $value = (string)($values[$column] ?? '');
            if ($value === '') {
                continue;
            }
            $query = osc_db_table(DB_TABLE_PREFIX . self::TABLE);
            $query = $query->where($column, $value);
            $row   = $query->first();
            if ($row !== null && (int)$row[self::PK] !== (int)$rowId) {
                $errors[] = $message;
            }
        }

        return $errors;
    }
}
