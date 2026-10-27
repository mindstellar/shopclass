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
 * Public forms keep what was typed and always answer: the contact errors, the login e-mail
 * kept after a wrong password, the register return path and the change-e-mail flash.
 *
 * Usage:  php tests/models/public-form-flows.php          (standalone, own scratch database)
 *         php tests/run-models.php public-form-flows      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingMailService;

$admin = scratchdb_session('osc_models_public_form_flows');
require_once __DIR__ . '/../lib/action-standins.php';

harness_section('contact form errors');
try {
    (new ListingMailService())->contactSeller(
        array('pk_i_id' => 1, 'dt_expiration' => '9999-12-31 00:00:00'),
        array('yourName' => '', 'yourEmail' => '', 'phoneNumber' => '', 'message' => '')
    );
    $errors = array();
} catch (InvalidException $e) {
    $errors = $e->errors();
}
pin('three errors, one per field, each with a pointer', array('/yourName', '/yourEmail', '/message'), array_column($errors, 'pointer'));
pin('plain wording', array('Enter your name', 'Enter a valid e-mail address', 'Write a message'), array_column($errors, 'message'));

harness_section('contact form fields');
ob_start();
ContactForm::your_email();
$email = (string) ob_get_clean();
check('the e-mail input is type=email and required', str_contains($email, 'type="email"') && str_contains($email, 'required'));
ob_start();
ContactForm::your_name();
check('the name input is required', str_contains((string) ob_get_clean(), 'required'));

harness_section('login keeps the typed e-mail');
Session::getInstance()->_setForm('email', 'kept@example.test');
ob_start();
UserForm::email_login_text();
check('the login field shows the kept e-mail', str_contains((string) ob_get_clean(), 'value="kept@example.test"'));
Session::getInstance()->_setForm('email', '');
ob_start();
UserForm::email_login_text();
check('and is empty with nothing kept', !str_contains((string) ob_get_clean(), 'kept@example.test'));

$dir   = dirname(__DIR__, 2) . '/oc-includes/osclass/classes/controller/';
$login = file_get_contents($dir . 'CWebLogin.php');
check('every login bounce goes through the keeping redirect', substr_count(harness_method_source($dir . 'CWebLogin.php', 'loginPost'), '$this->redirectTo($back)') === 0
    && str_contains(harness_method_source($dir . 'CWebLogin.php', 'bounce'), "_setForm('email'"));

harness_section('register keeps the return path');
$register = file_get_contents($dir . 'CWebRegister.php');
check('a register that needs validation keeps the return for the validate link', str_contains($register, 'osc_set_login_redirect(') && str_contains($register, 'osc_login_return_path()'));
check('the validate link goes to the kept return', str_contains($register, 'osc_pop_login_redirect()'));

harness_section('change e-mail always answers');
$change = harness_method_source($dir . 'CWebUser.php', 'changeEmailPost');
check('a taken address gets the same flash as a free one', str_contains($change, "osc_add_flash_ok_message(_m('If that address is free, we sent a link to it.'))"));

exit(harness_result());
