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
 * Maps each admin ajax action (?page=ajax&action=...) to its handler method, and says
 * which actions need a CSRF token and which a moderator may reach.
 */
final class AjaxRegistry
{
    /** The actions a moderator may reach; any other answers error_permissions. */
    public const MODERATOR_ACTIONS = array(
        'items', 'media', 'comments', 'custom', 'runhook', 'save_admin_theme',
        'save_sidebar_state', 'resource_upload', 'media_list',
    );

    /** action => [handler class, method, CSRF token required] */
    private const ROUTES = array(
        'bulk_actions'           => array(SystemAjax::class, 'bulkActions', false),
        'error_permissions'      => array(SystemAjax::class, 'errorPermissions', false),
        'userajax'               => array(SystemAjax::class, 'userLookup', false),
        'test_mail'              => array(SystemAjax::class, 'testMail', true),
        'test_mail_template'     => array(SystemAjax::class, 'testMailTemplate', true),
        'backup_status'          => array(SystemAjax::class, 'backupStatus', true),
        'order_pages'            => array(SystemAjax::class, 'orderPages', true),

        'date_format'            => array(PreferenceAjax::class, 'dateFormat', false),
        'save_admin_theme'       => array(PreferenceAjax::class, 'saveAdminTheme', true),
        'save_sidebar_state'     => array(PreferenceAjax::class, 'saveSidebarState', true),

        'runhook'                => array(PluginAjax::class, 'runHook', false),
        'custom'                 => array(PluginAjax::class, 'custom', false),

        'media_list'             => array(MediaAjax::class, 'mediaList', false),
        'resource_upload'        => array(MediaAjax::class, 'resourceUpload', true),

        'regions'                => array(LocationAjax::class, 'regions', false),
        'cities'                 => array(LocationAjax::class, 'cities', false),
        'location'               => array(LocationAjax::class, 'cityLookup', false),
        'location_catalog'       => array(LocationAjax::class, 'catalog', false),
        'country_slug'           => array(LocationAjax::class, 'countrySlug', false),
        'region_slug'            => array(LocationAjax::class, 'regionSlug', false),
        'city_slug'              => array(LocationAjax::class, 'citySlug', false),
        'location_search'        => array(LocationAjax::class, 'read', false),
        'location_impact'        => array(LocationAjax::class, 'read', false),
        'location_record'        => array(LocationAjax::class, 'read', false),
        'location_stats'         => array(LocationAjax::class, 'stats', true),

        'categories_order'       => array(CategoryAjax::class, 'order', true),
        'category_edit_iframe'   => array(CategoryAjax::class, 'editIframe', false),
        'enable_category'        => array(CategoryAjax::class, 'enable', true),
        'delete_category'        => array(CategoryAjax::class, 'delete', true),
        'edit_category_post'     => array(CategoryAjax::class, 'editPost', true),

        'field_categories_iframe' => array(FieldAjax::class, 'editIframe', false),
        'field_categories_post'  => array(FieldAjax::class, 'editPost', true),
        'delete_field'           => array(FieldAjax::class, 'delete', true),
        'add_field'              => array(FieldAjax::class, 'add', true),
        'fields_order'           => array(FieldAjax::class, 'order', true),

        'add_group'              => array(FormBuilderAjax::class, 'addGroup', true),
        'group_post'             => array(FormBuilderAjax::class, 'groupPost', true),
        'delete_group'           => array(FormBuilderAjax::class, 'deleteGroup', true),
        'group_categories_iframe' => array(FormBuilderAjax::class, 'groupIframe', false),
        'form_set_fields'        => array(FormBuilderAjax::class, 'setFields', true),
        'migrate_loose_fields'   => array(FormBuilderAjax::class, 'migrateLooseFields', true),
        'form_submission_status' => array(FormBuilderAjax::class, 'submissionStatus', true),
        'form_submission_delete' => array(FormBuilderAjax::class, 'submissionDelete', true),
        'form_submissions_purge' => array(FormBuilderAjax::class, 'submissionsPurge', true),

        'check_version'          => array(UpdateAjax::class, 'checkVersion', false),
        'check_languages'        => array(UpdateAjax::class, 'checkLanguages', false),
        'check_themes'           => array(UpdateAjax::class, 'checkThemes', false),
        'check_plugins'          => array(UpdateAjax::class, 'checkPlugins', false),
        'upgrade'                => array(UpdateAjax::class, 'upgrade', true),
        'reinstall_osclass'      => array(UpdateAjax::class, 'reinstall', true),
        'upgrade_db'             => array(UpdateAjax::class, 'upgradeDb', true),

        'market_refresh'         => array(MarketAjax::class, 'refresh', true),
        'market_install'         => array(MarketAjax::class, 'install', true),
        'market_update'          => array(MarketAjax::class, 'update', true),
        'market_detail'          => array(MarketAjax::class, 'detail', true),
    );

    private function __construct()
    {
    }

    /**
     * The route for an action, or null when no handler answers it.
     *
     * @param mixed $action raw request value; an array is never an action
     *
     * @return array{handler:class-string<AjaxHandler>, method:string, csrf:bool}|null
     */
    public static function route(mixed $action): ?array
    {
        if (!is_string($action) || !isset(self::ROUTES[$action])) {
            return null;
        }
        [$handler, $method, $csrf] = self::ROUTES[$action];

        return array('handler' => $handler, 'method' => $method, 'csrf' => $csrf);
    }

    /**
     * Whether a moderator may run this action. A loose match, as the controller always did.
     */
    public static function moderatorMay(mixed $action): bool
    {
        return in_array($action, self::MODERATOR_ACTIONS);
    }

    /**
     * Every registered action name.
     *
     * @return string[]
     */
    public static function actions(): array
    {
        return array_keys(self::ROUTES);
    }
}
