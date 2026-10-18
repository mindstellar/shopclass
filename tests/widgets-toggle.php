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
 * The widgets screen's eye: widget_toggle_post flips the reserved _enabled key of a typed row,
 * needs the CSRF check, skips legacy rows, and lets a moderator hide a normal row but not
 * a super_admin one.
 *
 * DB-backed.  Usage:  php tests/widgets-toggle.php
 */

// Buffered from the start, so the JSON answers can still set their status code.
ob_start();

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

const SCRATCH = 'osc_widgets_toggle';

$admin = scratchdb_session(SCRATCH);
define('OC_ADMIN', true);
require_once __DIR__ . '/lib/customiser-kit.php';
customiser_kit_declare();

use mindstellar\widgets\WidgetStore;

/** The base controller, stubbed: redirects are recorded and nothing is drawn. */
class AdminSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
    }

    public function doView($view)
    {
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php';

$table = DB_TABLE_PREFIX . 't_widget';
$adminA = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', 'x', 'a@x.test', 0)", '', array());
$GLOBALS['adminId'] = $adminA;

osc_register_widget('test.secret', array(
    'label'      => 'Secret',
    'capability' => 'super_admin',
    'fields'     => array(array('name' => 'text', 'label' => 'Text', 'type' => 'text')),
    'render'     => static function () {
        echo '<secret>';
    },
));

/** One admin request to the appearance controller; the JSON it answered and its status. */
function drive(string $action, array $post = array()): array
{
    $_GET   = array('page' => 'appearance', 'action' => $action);
    $_POST  = $post;
    $_FILES = array();
    Params::init();
    $GLOBALS['csrfChecks'] = array();
    unset($GLOBALS['osc_page_cache_purge']);
    http_response_code(200);
    ob_start();
    try {
        (new CAdminAppearance())->doModel();
    } catch (Throwable $e) {
        // AjaxResponse exits; the stubbed harness lets the answer through.
    }
    $out = (string) ob_get_clean();

    return array('status' => http_response_code(), 'json' => json_decode($out, true), 'csrf' => $GLOBALS['csrfChecks'], 'purges' => $GLOBALS['osc_page_cache_purge'] ?? array());
}

$add = static fn (string $type, string $config): int => WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'W', 's_content' => '', 's_type' => $type, 's_config' => $config, 'i_order' => 0));
$cfg = static fn (int $id): string => (string) WidgetStore::find($id)['s_config'];
$render = static function (int $id): string {
    ob_start();
    osc_render_widget(WidgetStore::find($id));

    return (string) ob_get_clean();
};

harness_section('widget_toggle_post');

$id = $add('aisle.promise', '{"text":"a"}');
$r = drive('widget_toggle_post', array('id' => (string) $id));
pin('a visible row is hidden', array(200, 0, false), array($r['status'], $r['json']['error'] ?? null, $r['json']['enabled'] ?? null));
pin('...and stored', '{"text":"a","_enabled":false}', $cfg($id));
pin('...and renders nothing', '', $render($id));
pin('...the CSRF check ran', array('widget_toggle_post'), $r['csrf']);
pin('...and the page cache was purged', array('widget'), $r['purges']);
$r = drive('widget_toggle_post', array('id' => (string) $id));
pin('a second toggle shows it again', true, $r['json']['enabled'] ?? null);
pin('...and persists', '{"text":"a","_enabled":true}', $cfg($id));
check('...and renders', $render($id) !== '');

$legacy = WidgetStore::add(array('s_location' => 'home', 'e_kind' => 'html', 's_description' => 'Raw', 's_content' => '<p>x</p>', 'i_order' => 1));
pin('a legacy row is refused', 404, drive('widget_toggle_post', array('id' => (string) $legacy))['status']);
pin('...and left alone', '', $cfg($legacy));
pin('an unknown id is refused', 404, drive('widget_toggle_post', array('id' => '999999'))['status']);

harness_section('moderators');

$secret = $add('test.secret', '{"text":"s"}');
$GLOBALS['moderator'] = true;
pin('a moderator can hide a normal row', 200, drive('widget_toggle_post', array('id' => (string) $id))['status']);
pin('...but not a super_admin row', 403, drive('widget_toggle_post', array('id' => (string) $secret))['status']);
pin('...which stays as it was', '{"text":"s"}', $cfg($secret));
$GLOBALS['moderator'] = false;
pin('an administrator can hide a super_admin row', 200, drive('widget_toggle_post', array('id' => (string) $secret))['status']);

$code = harness_result();
ob_end_flush();
exit($code);
