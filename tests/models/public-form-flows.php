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
foreach (array('hMessages', 'hUtils') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
// hDefines.php cannot be required here, so the real return-path reader is lifted out of it.
if (!function_exists('osc_login_return_path')) {
    eval(harness_method_source(ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php', 'osc_login_return_path'));
}
if (!function_exists('osc_user_login_url')) {
    function osc_user_login_url($return = '')
    {
        return \mindstellar\auth\SignIn::linkWithReturn(osc_base_url() . 'index.php?page=login', (string) $return);
    }
}
if (!function_exists('osc_register_account_url')) {
    function osc_register_account_url($return = '')
    {
        return \mindstellar\auth\SignIn::linkWithReturn(osc_base_url() . 'index.php?page=register', (string) $return);
    }
}
if (!function_exists('osc_user_profile_url')) {
    function osc_user_profile_url()
    {
        return osc_base_url() . 'index.php?page=user&action=profile';
    }
}
if (!function_exists('osc_change_user_email_confirm_url')) {
    function osc_change_user_email_confirm_url($userId, $code)
    {
        return osc_base_url() . 'index.php?page=user&action=change_email_confirm&userId=' . (int) $userId . '&code=' . $code;
    }
}
if (!function_exists('osc_change_user_email_url')) {
    function osc_change_user_email_url()
    {
        return osc_base_url() . 'index.php?page=user&action=change_email';
    }
}

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

$dir = dirname(__DIR__, 2) . '/oc-includes/osclass/classes/controller/';
defined('OSC_DEBUG') || define('OSC_DEBUG', false);
$login = new class () extends CWebLogin {
    public array $sent = array();

    public function __construct()
    {
        $this->ajax = true;
    }

    public function redirectTo($url, $code = null)
    {
        $this->sent[] = $url;
    }
};
$bounce = new ReflectionMethod('CWebLogin', 'bounce');
$bounce->setAccessible(true);
$bounce->invoke($login, 'https://example.test/login', 'typed@example.test');
pin('a bounce keeps the typed e-mail and goes back', array('typed@example.test', array('https://example.test/login')), array(Session::getInstance()->_getForm('email'), $login->sent));
Session::getInstance()->_setForm('email', '');
check('every login bounce goes through the keeping redirect', substr_count(harness_method_source($dir . 'CWebLogin.php', 'loginPost'), '$this->redirectTo($back)') === 0);

harness_section('a refused add keeps what was typed');
// The admin Add user form has no user, so it hands the fields an empty string.
Session::getInstance()->_setForm('user_s_name', 'Typed Name');
Session::getInstance()->_setForm('user_s_phone_land', '0161 22');
ob_start();
UserForm::name_text('');
UserForm::phone_land_text(null);
$typed = (string) ob_get_clean();
Session::getInstance()->_setForm('user_s_name', '');
Session::getInstance()->_setForm('user_s_phone_land', '');
check('the fields draw with the typed values', str_contains($typed, 'Typed Name') && str_contains($typed, '0161 22'));

harness_section('register keeps the return path');
final class FlowRedirect extends RuntimeException
{
}

/** CWebUser with the redirect caught, so a test can read where it was sent. */
final class ChangeEmailProbe extends CWebUser
{
    public string $sent = '';

    public function __construct()
    {
        $this->ajax   = true;
        $this->action = Params::getParamString('action');
    }

    public function redirectTo($url, $code = null)
    {
        $this->sent = (string) $url;
        throw new FlowRedirect($url);
    }
}
$asVisitor = static function (array $params): void {
    $csrf = new \mindstellar\security\Csrf();
    foreach (array('return', 'new_email', 's_email', 's_name', 's_username', 's_password', 's_password2', 'action') as $key) {
        Params::unsetParam($key);
    }
    foreach ($params + array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()) as $key => $value) {
        Params::setParam($key, $value);
    }
};
foreach (array('enabled_users' => '1', 'enabled_user_registration' => '1', 'enabled_user_validation' => '1') as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_reset_preferences();
$asVisitor(array(
    'action' => 'register_post', 'return' => '/index.php?page=cart', 's_email' => 'flow-new@example.test',
    's_name' => 'Flow New', 's_username' => 'flownew', 's_password' => 'Fl0w-pass-9', 's_password2' => 'Fl0w-pass-9',
));
$register = new class () extends CWebRegister {
    public string $sent = '';

    public function __construct()
    {
        $this->ajax   = true;
        $this->action = Params::getParamString('action');
    }

    public function redirectTo($url, $code = null)
    {
        $this->sent = (string) $url;
        throw new FlowRedirect($url);
    }
};
try {
    $register->doModel();
} catch (FlowRedirect $e) {
}
pin('a register that needs validation sends them to log in with the return kept', osc_base_url() . 'index.php?page=login&return=%2Findex.php%3Fpage%3Dcart', $register->sent);
pin('and the validate link goes back to the return', osc_base_url() . 'index.php?page=cart', osc_pop_login_redirect());

harness_section('change e-mail always answers');
$changer = seed_user($admin, 'changer', 'changer@example.test');
seed_user($admin, 'holder', 'holder@example.test');
Session::getInstance()->_setEphemeral('userId', (string) $changer);
$pending = static fn (): string => (string) (UserEmailTmp::getInstance()->findByPrimaryKey($changer)['s_new_email'] ?? '');
$changeTo = static function (string $email) use ($asVisitor): array {
    $asVisitor(array('action' => 'change_email_post', 'new_email' => $email));
    $web = new ChangeEmailProbe();
    Session::getInstance()->_dropMessage('pubMessages');
    try {
        (new ReflectionMethod('CWebUser', 'changeEmailPost'))->invoke($web);
    } catch (FlowRedirect $e) {
    }

    return array($web->sent, Session::getInstance()->_getMessage('pubMessages'));
};
[$freeSent, $freeFlash] = $changeTo('brand-new@example.test');
pin('a free address is queued for confirmation', 'brand-new@example.test', $pending());
UserEmailTmp::getInstance()->deleteByUser($changer);
[$takenSent, $takenFlash] = $changeTo('holder@example.test');
pin('a taken address is not queued', '', $pending());
pin('both go to the profile', array(osc_user_profile_url(), osc_user_profile_url()), array($freeSent, $takenSent));
pin('both get the same flash', $freeFlash, $takenFlash);
check('and it is the "if that address is free" one', str_contains((string) json_encode($takenFlash), 'If that address is free'));
Session::getInstance()->_setEphemeral('userId', '');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
