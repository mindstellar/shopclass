<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\admin\ui\FormSpec;

/**
 * What every core settings screen declared here does the same way.
 *
 * The settings section is a dozen small forms over eight screens, and the parts that
 * genuinely repeat are few: they all store preferences in the same section, none of them
 * wants a menu entry (the Settings menu already lists them), and they all post back to the
 * settings controller. Those three things live here, with the whole-number sanitiser two of
 * them share. Everything else -- the fields, the labels, the rules, the effects -- belongs
 * to the screen that owns it and is written out there, because two screens that merely look
 * alike are not one screen.
 *
 * @package mindstellar\admin\form
 */
final class CoreSettings
{
    /** The preference section core's own settings have always been stored in. */
    public const SECTION = 'osclass';

    /**
     * Start a declaration for one of core's settings forms.
     *
     * No menu entry: these screens are already on the Settings menu, built by the admin
     * theme, and a second entry would list each of them twice.
     *
     * @param string $id      the page id to register under
     * @param string $title
     * @param string $section the preference section the values are stored in
     *
     * @return FormSpec
     */
    public static function page(string $id, string $title, string $section = self::SECTION): FormSpec
    {
        return osc_admin_form($id)
            ->title($title)
            ->menu('')
            ->section($section);
    }

    /**
     * Whatever was typed, as a whole number. A blank box stays blank rather than becoming a
     * zero, so a required field still refuses one.
     *
     * @return callable(mixed):(int|string)
     */
    public static function wholeNumber(): callable
    {
        return static function ($value) {
            return $value === '' ? '' : (int)$value;
        };
    }

    /**
     * Save one declared form and report whatever it refused.
     *
     * Every error is flashed, not just the first: that is the whole point of the declared
     * path, and it is the one presentation decision these screens share. The caller decides
     * what a success says and where it goes, because no two of them say the same thing.
     *
     * @param string $pageId
     *
     * @return array{errors:string[],updated:int,values:array<string,mixed>,id:mixed} the
     *         osc_settings_save() result; 'errors' empty means it was written
     */
    public static function attempt(string $pageId): array
    {
        $result = osc_settings_save($pageId);
        foreach ($result['errors'] as $error) {
            osc_add_flash_warning_message($error, 'admin');
        }

        return $result;
    }

    /**
     * Everything the view needs to draw one declared form: which page, where it posts, and
     * what the fields say now -- the stored values, or the ones a rejected save is handing
     * back to be corrected.
     *
     * @param string                   $pageId
     * @param string                   $action the settings-controller action this form posts to
     * @param array<string,mixed>|null $values values to show, when a rejected save is being
     *                                         redrawn
     * @param array<string,mixed>      $opts   'name' => the form's name attribute,
     *                                         'actions' => the submit row,
     *                                         'upload' => true for a form carrying a file
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function vars(string $pageId, string $action, ?array $values = null, array $opts = array()): array
    {
        $vars = array(
            'id'      => $pageId,
            'values'  => $values ?? osc_settings_values($pageId),
            'route'   => array('page' => 'settings', 'action' => $action),
            'name'    => $opts['name'] ?? null,
            'actions' => $opts['actions'] ?? array(),
        );
        if (!empty($opts['upload'])) {
            $vars['upload'] = true;
        }

        return $vars;
    }
}
