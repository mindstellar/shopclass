<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class CAdminLogin
 */
class CAdminLogin extends AdminBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_login');
    }

    //Business Layer...

    /**
     * @return bool|false
     * @throws \Exception
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('login_post'):     //post execution for the login
                osc_csrf_check();
                osc_run_hook('before_login_admin');
                $url_redirect  = osc_pop_admin_login_redirect();
                $page_redirect = '';
                $password      = Params::getParam('password', false, false);
                if (preg_match('|[?&]page=([^&]+)|', $url_redirect . '&', $match)) {
                    $page_redirect = $match[1];
                }
                if ($page_redirect == '' || $page_redirect === 'login' || $url_redirect == '') {
                    $url_redirect = osc_admin_base_url();
                }

                if (Params::getParam('user') == '') {
                    osc_add_flash_error_message(_m('The username field is empty'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                }

                if (Params::getParam('password', false, false) == '') {
                    osc_add_flash_error_message(_m('The password field is empty'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                }

                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    osc_add_flash_error_message(_m('Please complete the security check.'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                }

                // fields are not empty
                // Before the account is looked up and before any password is
                // hashed, so that a refused attempt costs neither.
                $throttle = \mindstellar\security\LoginThrottle::evaluate('admin', Params::getParam('user'), osc_captcha_enabled());
                if ($throttle['status'] === \mindstellar\security\LoginThrottle::BLOCKED) {
                    osc_add_flash_error_message(osc_login_throttle_message($throttle['retry_after']), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                }

                $admin = Admin::newInstance()->findByUsername(Params::getParam('user'));

                // An unknown account and a wrong password must answer the same way,
                // and take about as long, or the form tells anyone who asks which
                // administrator names exist.
                $authenticated = !$admin
                    ? osc_dummy_password_verify($password)
                    : osc_verify_password($password, $admin['s_password']);

                if (!$authenticated) {
                    // Counted against the name as submitted, so one nobody holds
                    // accumulates exactly like a real one.
                    \mindstellar\security\LoginThrottle::recordFailure('admin', Params::getParam('user'));
                    osc_add_flash_error_message(sprintf(
                        _m('Sorry, incorrect username or password. <a href="%s">Have you lost your password?</a>'),
                        osc_admin_base_url(true) . '?page=login&amp;action=recover'
                    ), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                } elseif (@$admin['s_password'] != '') {
                    $needs_rehash = true;
                    if (preg_match('|\$2y\$([0-9]{2})\$|', $admin['s_password'], $cost)) {
                        $needs_rehash = ((int)$cost[1] !== BCRYPT_COST);
                    }
                    if ($needs_rehash) {
                        // Mirror the rehash into the in-memory row so the remember-me token below
                        // binds to the hash actually persisted, not the stale one.
                        $admin['s_password'] = osc_hash_password($password);
                        Admin::newInstance()->update(
                            array('s_password' => $admin['s_password']),
                            array('pk_i_id' => $admin['pk_i_id'])
                        );
                    }
                }

                \mindstellar\security\LoginThrottle::clear('admin', Params::getParam('user'));

                $locale          = Params::getParam('locale');
                $is_valid_locale = osc_validate_locale($locale, true);
                if (Params::getParam('remember')) {
                    Cookie::newInstance()->set_expires(osc_time_cookie());
                    Cookie::newInstance()->push('oc_adminId', $admin['pk_i_id']);
                    Cookie::newInstance()->push(
                        'oc_adminSecret',
                        \mindstellar\security\RememberMe::issue(
                            'admin',
                            $admin['pk_i_id'],
                            $admin['s_password'],
                            osc_time_cookie()
                        )
                    );
                    if ($is_valid_locale === true) {
                        Cookie::newInstance()->push('oc_adminLocale', Params::getParam('locale'));
                    } else {
                        Cookie::newInstance()->push('oc_adminLocale', osc_admin_language());
                    }
                    Cookie::newInstance()->set();
                }

                // we are logged in... let's go!
                Session::newInstance()->_set('adminId', $admin['pk_i_id']);
                Session::newInstance()->_set('adminUserName', $admin['s_username']);
                Session::newInstance()->_set('adminName', $admin['s_name']);
                Session::newInstance()->_set('adminEmail', $admin['s_email']);
                if ($is_valid_locale === true) {
                    Session::newInstance()->_set('adminLocale', $locale);
                } else {
                    Session::newInstance()->_set('adminLocale', osc_admin_language());
                }
                osc_run_hook('login_admin', $admin);

                $this->redirectTo($url_redirect);
                break;
            case ('recover'):        // form to recover the password (in this case we have the form in /gui/)
                View::newInstance()->_exportVariableToView('login_admin_page_title', osc_page_title().' &raquo;'. __('Lost your password'));
                View::newInstance()->_exportVariableToView('login_admin_form', 'gui/recover.php');
                $this->doView();
                break;
            case ('recover_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url());
                }
                osc_csrf_check();

                // post execution to recover the password

                // The security check runs before the account is looked up. Inside the
                // branch below it would only ever fail for names that exist, which
                // would hand back the answer the shared message is meant to withhold.
                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    osc_add_flash_error_message(_m('Please complete the security check.'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login&action=recover');

                    return false; // BREAK THE PROCESS, THE CAPTCHA IS WRONG
                }

                // Counted on its own, so that reset requests cannot lock anyone
                // out of signing in. Every request counts, not only the ones
                // that match an account: sending mail to an address someone else
                // owns is the abuse being bounded here, and that only happens
                // when the address does match.
                $recoverAccount = trim((string)Params::getParam('email'));
                $throttle       = \mindstellar\security\LoginThrottle::evaluate('admin-recover', $recoverAccount, osc_captcha_enabled());
                if ($throttle['status'] === \mindstellar\security\LoginThrottle::BLOCKED) {
                    osc_add_flash_error_message(osc_login_throttle_message($throttle['retry_after']), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login&action=recover');

                    return false;
                }
                \mindstellar\security\LoginThrottle::recordFailure('admin-recover', $recoverAccount);

                $admin = Admin::newInstance()->findByEmail(Params::getParam('email'));
                if (!isset($admin['pk_i_id'])) {
                    $admin = Admin::newInstance()->findByUsername(Params::getParam('email'));
                }
                if (isset($admin['pk_i_id'])) {
                    require_once osc_lib_path() . 'osclass/helpers/hSecurity.php';
                    $newPassword = osc_genRandomPassword(40);

                    // Persist only a fingerprint; the plaintext code lives solely in the emailed link.
                    Admin::newInstance()->update(
                        array('s_secret' => \mindstellar\security\ActionToken::hash($newPassword)),
                        array('pk_i_id' => $admin['pk_i_id'])
                    );
                    $password_url = osc_forgot_admin_password_confirm_url($admin['pk_i_id'], $newPassword);

                    osc_run_hook('hook_email_user_forgot_password', $admin, $password_url);
                }

                osc_add_flash_ok_message(_m('A new password has been sent to your e-mail'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                break;
            case ('forgot'):         // form to recover the password (in this case we have the form in /gui/)
                $admin = Admin::newInstance()->findByIdSecret(
                    Params::getParam('adminId'),
                    \mindstellar\security\ActionToken::hash(Params::getParam('code'))
                );
                if (!$admin) {
                    osc_add_flash_error_message(_m('Sorry, the link is not valid'), 'admin');
                    $this->redirectTo(osc_admin_base_url());
                }
                View::newInstance()->_exportVariableToView('login_admin_page_title', osc_page_title().' &raquo;'. __('Change your password'));
                View::newInstance()->_exportVariableToView('login_admin_form', 'gui/forgot_password.php');
                $this->doView();
                break;
            case ('forgot_post'):
                osc_csrf_check();
                $admin = Admin::newInstance()->findByIdSecret(
                    Params::getParam('adminId'),
                    \mindstellar\security\ActionToken::hash(Params::getParam('code'))
                );
                if (!$admin) {
                    osc_add_flash_error_message(_m('Sorry, the link is not valid'), 'admin');
                    $this->redirectTo(osc_admin_base_url());
                }

                if (Params::getParam('new_password', false, false) === Params::getParam(
                    'new_password2',
                    false,
                    false
                )) {
                    // Consume the reset code (single-use) by replacing its fingerprint with a
                    // fresh dead one, alongside the new password.
                    Admin::newInstance()->update(
                        array(
                            's_secret'   => \mindstellar\security\ActionToken::hash(osc_genRandomPassword(40)),
                            's_password' => osc_hash_password(Params::getParam('new_password', false, false))
                        ),
                        array('pk_i_id' => $admin['pk_i_id'])
                    );
                    osc_add_flash_ok_message(_m('The password has been changed'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=login');
                } else {
                    osc_add_flash_error_message(_m("Error, the passwords don't match"), 'admin');
                    $this->redirectTo(osc_forgot_admin_password_confirm_url(
                        Params::getParam('adminId'),
                        Params::getParam('code')
                    ));
                }
                break;
            default:
                //osc_run_hook( 'init_admin' );
                View::newInstance()->_exportVariableToView('login_admin_page_title', osc_page_title().' &raquo;'. __('Log in'));
                View::newInstance()->_exportVariableToView('login_admin_form', 'gui/login.php');
                // Signed cookie instead of the session, so opening the admin login page does
                // not start a session; keep a destination the auth gate already recorded.
                osc_set_admin_login_redirect(osc_get_http_referer(), true);
                $this->doView();
                break;
        }
    }

    //in this case, this function is prepared for the "recover your password" form

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file = 'gui/main.php')
    {
        $login_admin_title = osc_apply_filter('login_admin_title', 'Shopclass');
        $login_admin_url   = osc_apply_filter('login_admin_url', 'https://github.com/mindstellar/shopclass/');
        $login_admin_image = osc_apply_filter('login_admin_image', osc_admin_base_url() . 'images/shopclass-logo.svg');

        View::newInstance()->_exportVariableToView('login_admin_title', $login_admin_title);
        View::newInstance()->_exportVariableToView('login_admin_url', $login_admin_url);
        View::newInstance()->_exportVariableToView('login_admin_image', $login_admin_image);

        osc_run_hook('before_admin_html');
        require osc_admin_base_path() . $file;
        osc_run_hook('after_admin_html');
    }

}
/* file end: ./oc-admin/CAdminLogin.php */
