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
 * Renders the admin Categories screen from a category tree, with no database.
 * Usage: categories_page_html($tree, ['status' => 'off', 'depthLimit' => 3, 'shell' => true]).
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$GLOBALS['categories_page_vars'] = array();
$GLOBALS['categories_page_shell'] = false;

foreach ([
    '__get'                     => 'return $GLOBALS["categories_page_vars"][$k] ?? null;',
    'osc_admin_base_url'        => 'return "https://example.test/oc-admin/index.php";',
    'osc_csrf_token_url'        => 'return "CSRFName=n&CSRFToken=t";',
    'osc_mode_text'             => 'return $a;',
    'osc_admin_page'            => '$GLOBALS["categories_page_head"] = $a; return null;',
    'osc_enqueue_script'        => 'return null;',
    'osc_admin_form_open'       => 'echo "<form method=\"post\">";',
    'osc_admin_form_close'      => 'echo "</form>";',
    'osc_locale_thousands_sep'  => 'return ",";',
] as $fn => $body) {
    if (!function_exists($fn)) {
        $args = $fn === '__get' ? '$k' : '$a = null, ...$rest';
        eval('function ' . $fn . '(' . $args . ') { ' . $body . ' }');
    }
}
if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file)
    {
        if (!empty($GLOBALS['categories_page_shell'])) {
            if ($file !== 'parts/header.php') {
                echo '</main>';

                return;
            }
            // The page head as the real header draws it: title, Add category and the help toggle.
            $page = $GLOBALS['categories_page_head'] ?? array();
            echo '<main class="admin-main" style="padding-inline:.7rem"><div id="content-head" class="row"><div class="col">';
            osc_admin_page_header((string) ($page['section'] ?? ''), array(
                'actions' => $page['actions'] ?? array(),
                'help'    => isset($page['help']),
                'heading' => $page['heading'] ?? null,
            ));
            echo '</div></div>';
        }
    }
}
if (!function_exists('osc_print_bulk_actions')) {
    // Same output as the helper in hTheme.php, which cannot be loaded whole here.
    function osc_print_bulk_actions($id, $name, $options, $class = '', $attributes = '')
    {
        echo '<select id="' . $id . '" name="' . $name . '" ' . $attributes . ' class="form-select ' . $class . '">';
        foreach ($options as $o) {
            $label = $o['label'] ?? '';
            unset($o['label']);
            $attrs = '';
            foreach ($o as $k => $v) {
                $attrs .= $k . '="' . $v . '" ';
            }
            echo '<option ' . $attrs . '>' . $label . '</option>';
        }
        echo '</select>';
    }
}
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_admin_count')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
}
// The expiry control is tested with the drawer; here a stand-in marks where it prints.
if (!class_exists('CategoryForm', false)) {
    class CategoryForm
    {
        public static function expiry_control(int $days, string $prefix, string $name = 'i_expiration_days', bool $posted = true): void
        {
            echo '<div class="cat-exp" data-cat-exp></div>';
        }

        public static function expiry_posted(string $prefix): void
        {
        }
    }
}

/**
 * @param array<int,array<string,mixed>> $tree Category::toTreeAll() shape
 * @param array{status?:string,depthLimit?:?int,shell?:bool} $opts
 */
function categories_page_html(array $tree, array $opts = []): string
{
    $GLOBALS['categories_page_vars'] = [
        'categories'      => $tree,
        'category_table'  => new \mindstellar\admin\CategoryTable($tree, $opts['depthLimit'] ?? null),
        'category_status' => $opts['status'] ?? '',
    ];
    $GLOBALS['categories_page_shell'] = !empty($opts['shell']);
    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/categories/index.php';

    return (string) ob_get_clean();
}
