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
 * An e-mail template with no text is built from the bundled English text, and a template
 * that does not exist at all skips that one mail without throwing.
 *
 * Usage:  php tests/models/mail-template-fallback.php      (standalone, own scratch database)
 *         php tests/run-models.php mail-template-fallback  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\mail\CoreEmails;

$GLOBALS['sent_mail'] = array();

$admin = api_admin_boot('osc_models_mail_template_fallback');
$p     = DB_TABLE_PREFIX;
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
if (!function_exists('osc_themes_path')) {
    function osc_themes_path()
    {
        return ABS_PATH . 'oc-content/themes/';
    }
}
if (!function_exists('osc_get_domain')) {
    function osc_get_domain()
    {
        return 'example.test';
    }
}

final class TemplateRecordingMailer extends \PHPMailer\PHPMailer\PHPMailer
{
    public function send()
    {
        $GLOBALS['sent_mail'][] = array('subject' => $this->Subject, 'body' => $this->Body);

        return true;
    }
}
osc_add_filter('init_send_mail', static fn () => new TemplateRecordingMailer(true));

$user = array('s_name' => 'Zed Newcomer', 's_email' => 'zed@example.test');

api_admin_seed_admin($admin, 'boss');
seed_page($admin, 'email_admin_new_user', 'DB-TITLE');

harness_section('page text in the database wins');
$GLOBALS['sent_mail'] = array();
CoreEmails::adminNewUser($user);
pin('the stored title is used', true, str_contains((string) ($GLOBALS['sent_mail'][0]['subject'] ?? ''), 'DB-TITLE'));

harness_section('no text in any locale');
$admin->query("DELETE FROM {$p}t_pages_description WHERE fk_i_pages_id = (SELECT pk_i_id FROM {$p}t_pages WHERE s_internal_name = 'email_admin_new_user')");
$GLOBALS['sent_mail'] = array();
$thrown = null;
try {
    CoreEmails::adminNewUser($user);
} catch (\Throwable $e) {
    $thrown = $e->getMessage();
}
pin('nothing is thrown', null, $thrown);
pin('one mail is sent', 1, count($GLOBALS['sent_mail']));
$bundled = json_decode((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/mail.json'), true)['template'];
$english = array_values(array_filter($bundled, static fn ($t) => $t['s_internal_name'] === 'email_admin_new_user'))[0];
pin('the body carries the new user name from the bundled text', true, str_contains((string) ($GLOBALS['sent_mail'][0]['body'] ?? ''), 'Zed Newcomer'));
pin('the bundled text is not left unfilled', false, str_contains((string) ($GLOBALS['sent_mail'][0]['body'] ?? ''), '{USER_NAME}'));
pin('the template helper returns the bundled title', $english['s_title'], CoreEmails::template('email_admin_new_user')['s_title'] ?? null);

harness_section('no template at all');
$admin->query("DELETE FROM {$p}t_pages WHERE s_internal_name = 'email_admin_new_user'");
$GLOBALS['sent_mail'] = array();
$thrown = null;
try {
    CoreEmails::adminNewUser($user);
} catch (\Throwable $e) {
    $thrown = $e->getMessage();
}
pin('the page row is gone and nothing is thrown', null, $thrown);
pin('the bundled text still sends the mail', 1, count($GLOBALS['sent_mail']));
pin('an unknown template gives null', null, CoreEmails::template('email_does_not_exist'));
$GLOBALS['sent_mail'] = array();
$thrown = null;
try {
    $got = CoreEmails::alertTemplate('alert_nothing_here');
} catch (\Throwable $e) {
    $thrown = $e->getMessage();
}
pin('an unknown digest template is skipped without throwing', array(null, null), array($thrown, $got ?? null));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
