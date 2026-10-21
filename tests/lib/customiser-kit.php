<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Shared set-up for tests/widgets-customiser.php and its preview child: helper stand-ins,
 * the helpers under test, and a theme that declares a customiser.
 *
 * Define OC_ADMIN before requiring this. Usage:  require_once __DIR__ . '/lib/customiser-kit.php';
 */

namespace mindstellar\settings {

    // CLI has no real uploads; SettingsImage calls this unqualified, so the namespaced stub wins.
    function is_uploaded_file($file): bool
    {
        return is_string($file) && is_file($file);
    }
}

namespace mindstellar\storage {

    /** Stores a settings image as a bare t_resource row, which is all SettingsImage reads. */
    final class ResourceUploader
    {
        public function upload(string $ownerType, int $ownerId, string $tmp, array $options = array()): array|false
        {
            $id = (new \mindstellar\model\Resource())->insertResource($ownerType, $ownerId, array('s_name' => 'img', 's_extension' => 'png'));

            return $id === false ? false : array('pk_i_id' => $id);
        }

        public function delete(array $row): void
        {
            $model = new \mindstellar\model\Resource();
            $model->deleteResourcesIds(array((int) $row['pk_i_id']));
            \Closure::bind(fn () => $this->flushOwnerCache('setting', 0), $model, $model)();
        }
    }
}

namespace {

    if (!defined('OSC_CACHE_TTL')) {
        define('OSC_CACHE_TTL', 60);
    }
    if (!defined('WEB_PATH')) {
        define('WEB_PATH', 'https://shop.test/');
    }
    if (!defined('PLUGINS_PATH')) {
        define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
    }
    foreach (array(
        'osc_base_url'                  => 'return WEB_PATH;',
        'osc_admin_base_url'            => 'return "https://admin.shop.test/oc-admin/" . (func_num_args() && func_get_arg(0) ? "index.php" : "");',
        'osc_plugins_path'              => 'return PLUGINS_PATH;',
        'osc_base_path'                 => 'return ABS_PATH;',
        'osc_current_web_theme'         => 'return (string) ($GLOBALS["webTheme"] ?? "aisle");',
        'osc_logged_admin_id'           => 'return (int) ($GLOBALS["adminId"] ?? 0);',
        'osc_is_admin_user_logged_in'   => 'return (int) ($GLOBALS["adminId"] ?? 0) > 0 && ($GLOBALS["adminLoggedIn"] ?? true);',
        'osc_is_moderator'              => 'return !empty($GLOBALS["moderator"]);',
        'osc_is_api_request'            => 'return !empty($GLOBALS["api"]);',
        'osc_max_size_kb'               => 'return 0;',
        'osc_is_web_user_logged_in'     => 'return false;',
        'osc_csrf_check'                => '$GLOBALS["csrfChecks"][] = Params::getParam("action"); return true;',
        'osc_autop'                     => 'return (string) func_get_arg(0);',
        'osc_validate_text'             => 'return trim((string) func_get_arg(0)) !== "";',
        'meta_title'                    => 'return "";',
    ) as $fn => $body) {
        if (!function_exists($fn)) {
            eval('function ' . $fn . '(...$args) { ' . $body . ' }');
        }
    }
    foreach (array('_m' => 'return $key;', '_e' => 'echo $key;') as $fn => $body) {
        if (!function_exists($fn)) {
            eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
        }
    }
    foreach (array('error', 'ok', 'warning', 'info') as $kind) {
        if (!function_exists('osc_add_flash_' . $kind . '_message')) {
            eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
                 . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
        }
    }

    require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
    require_once dirname(__DIR__) . '/lib/stubs.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hTheme.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hWidgets.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';

    osc_add_filter('page_cache_purge_enabled', static fn () => false);
    osc_add_theme_support('widget_locations', array('home' => 'Home page', 'footer' => 'Footer'));
    osc_register_widget('aisle.hero', array(
        'label'  => 'Hero',
        'fields' => array(
            array('name' => 'title', 'label' => 'Title', 'type' => 'text'),
            array('name' => 'layout', 'label' => 'Layout', 'type' => 'select', 'default' => 'grid', 'options' => array('grid' => 'Grid', 'rail' => 'Rail')),
            array('name' => 'image', 'label' => 'Image', 'type' => 'image'),
        ),
        'render' => static function ($config, $row) {
            echo '<hero ' . ($row['s_key'] ?? $row['pk_i_id']) . '>' . osc_esc_html($config['title'] ?? '') . '</hero>';
        },
    ));
    osc_register_widget('aisle.promise', array(
        'label'  => 'Promise',
        'fields' => array(array('name' => 'text', 'label' => 'Text', 'type' => 'text')),
        'render' => static function ($config) {
            echo '<promise>' . osc_esc_html($config['text'] ?? '') . '</promise>';
        },
    ));
    osc_register_widget('other.thing', array('label' => 'Other', 'render' => static function () {
        echo '<other>';
    }));
    osc_register_settings_page('aisle', array(
        'title'  => 'Aisle',
        'menu'   => '',
        'fields' => array(
            array('name' => 'brand', 'label' => 'Brand colour', 'type' => 'select', 'default' => 'raspberry',
                  'options' => array('raspberry' => 'Raspberry', 'ocean' => 'Ocean')),
            array('name' => 'footer_note', 'label' => 'Footer note', 'type' => 'text', 'maxlength' => 20),
            array('name' => 'logo', 'label' => 'Logo', 'type' => 'image'),
            array('name' => 'api_key', 'label' => 'Key', 'type' => 'secret', 'write_only' => true),
            array('name' => 'hero_title', 'label' => 'Old headline', 'type' => 'text'),
        ),
    ));

    /** The declaration Aisle makes, with a few things the spec must drop. */
    function customiser_kit_declare(array $overrides = array()): void
    {
        osc_add_theme_support('customiser', array_replace(array(
            'locations' => array(
                'home'    => array('label' => 'Home page', 'types' => array('aisle.hero', 'aisle.promise', 'no.such.type'), 'preview' => WEB_PATH),
                'nowhere' => array('label' => 'Not declared', 'types' => array('aisle.hero')),
            ),
            'settings'  => array('page' => 'aisle', 'fields' => array('brand', 'footer_note', 'logo', 'api_key', 'missing')),
            'swatches'  => array('brand' => array('raspberry' => '#C81F5E', 'ocean' => 'red;background:url(x)')),
            'live'      => array('brand' => 'data-brand', 'footer_note' => 'onclick'),
        ), $overrides));
    }
}
