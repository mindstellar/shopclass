<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class CWebLogin
 */
class CWebLogin extends BaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to loginForm(). */
    private const ACTIONS = array(
        'login_post'   => 'loginPost',
        'resend'       => 'resendActivation',
        'recover'      => 'recoverForm',
        'recover_post' => 'recoverPost',
        'forgot'       => 'forgotForm',
        'forgot_post'  => 'forgotPost',
    );

    /**
     * Boots the base controller, bounces the visitor home when accounts are disabled,
     * and fires the `init_login` hook.
     */
    public function __construct()
    {
        parent::__construct();
        if (!osc_users_enabled()) {
            osc_add_flash_error_message(_m('Users not enabled'));
            $this->redirectTo(osc_base_url());
        }
        osc_run_hook('init_login');
    }

    //Business Layer...
    /**
     * Handles the login, activation-resend and password recovery/reset actions; with no
     * action it renders the login form.
     *
     * @return void
     */
    public function doModel()
    {
        $method = $this->actionMethod('loginForm');

        $this->$method();
    }

    /**
     * Sign in.
     */
    private function loginPost(): void
    {
        if (!osc_users_enabled()) {
            osc_add_flash_error_message(_m('Users are not enabled'));
            $this->redirectTo(osc_base_url());
        }
        osc_csrf_check();

        // e-mail or/and password is/are empty or incorrect
        $wrongCredentials = false;
        $email            = trim(Params::getParam('email'));
        $password         = Params::getParam('password', false, false);
        if ($email == '') {
            osc_add_flash_error_message(_m('Please provide an email address'));
            $wrongCredentials = true;
        }
        if ($password == '') {
            osc_add_flash_error_message(_m('Empty passwords are not allowed. Please provide a password'));
            $wrongCredentials = true;
        }
        if ($wrongCredentials) {
            $this->redirectTo(osc_user_login_url());
        }

        if (!\mindstellar\security\Captcha::passes()) {
            osc_add_flash_error_message(\mindstellar\security\Captcha::failMessage());
            $this->redirectTo(osc_user_login_url());
        }

        $signIn = \mindstellar\auth\SignIn::attempt($email, $password, osc_captcha_enabled());
        $user   = $signIn->user();
        switch ($signIn->status()) {
            case \mindstellar\auth\SignIn::BLOCKED:
                osc_add_flash_error_message(osc_login_throttle_message($signIn->retryAfter()));
                $this->redirectTo(osc_user_login_url());
                break;
            case \mindstellar\auth\SignIn::WRONG:
                osc_add_flash_error_message(_m('Invalid email/username or password'));
                $this->redirectTo(osc_user_login_url());
                break;
            case \mindstellar\auth\SignIn::BANNED:
                if ($signIn->banned() & 1) {
                    osc_add_flash_error_message(_m('Your current email is not allowed'));
                }
                if ($signIn->banned() & 2) {
                    osc_add_flash_error_message(_m('Your current IP is not allowed'));
                }
                $this->redirectTo(osc_user_login_url());
                break;
            case \mindstellar\auth\SignIn::INACTIVE:
                if ((time() - strtotime($user['dt_access_date'])) > 1200) { // EACH 20 MINUTES
                    osc_add_flash_error_message(sprintf(
                        _m('The user has not been validated yet. Would you like to re-send your <a href="%s">activation?</a>'),
                        osc_user_resend_activation_link($user['pk_i_id'], $user['s_email'])
                    ));
                } else {
                    osc_add_flash_error_message(_m('The user has not been validated yet'));
                }
                $this->redirectTo(osc_user_login_url());
                break;
            case \mindstellar\auth\SignIn::DISABLED:
                osc_add_flash_error_message(_m('The user has been suspended'));
                $this->redirectTo(osc_user_login_url());
                break;
        }

        $url_redirect = osc_pop_login_redirect();
        if (osc_rewrite_enabled() && $url_redirect != '') {
            // if comes from oc-admin/
            if (strpos($url_redirect, 'oc-admin') !== false) {
                $url_redirect = osc_user_dashboard_url();
            } else {
                $request_uri =
                    urldecode(preg_replace('@^' . osc_base_url() . '@', '', $url_redirect));
                $tmp_ar      = explode('?', $request_uri);
                $request_uri = $tmp_ar[0];
                $rules       = Rewrite::getInstance()->listRules();
                foreach ($rules as $match => $uri) {
                    if (preg_match('#' . $match . '#', $request_uri, $m)) {
                        $request_uri = preg_replace('#' . $match . '#', $uri, $request_uri);
                        if (preg_match(
                            '|([&?]{1})page=([^&]*)|',
                            '&' . $request_uri . '&',
                            $match
                        )
                        ) {
                            $page_redirect = $match[2];
                            if ($page_redirect == '' || $page_redirect === 'login') {
                                $url_redirect = osc_user_dashboard_url();
                            }
                        }
                        break;
                    }
                }
            }
        }

        // Read again: a before_login listener may have blocked the account since SignIn read it.
        $fresh = User::getInstance()->findByPrimaryKey((int) $user['pk_i_id']);
        if (!$fresh || !$fresh['b_active'] || !$fresh['b_enabled']) {
            osc_add_flash_error_message(_m("The user doesn't exist"));
            $this->redirectTo(osc_user_login_url());
        }
        osc_web_user_login($fresh, Params::getParam('remember') == 1);

        if ($url_redirect == '') {
            $url_redirect = osc_user_dashboard_url();
        }

        \mindstellar\auth\SignIn::complete($user, (string) $url_redirect);

        $this->redirectTo(osc_apply_filter(
            'correct_login_url_redirect',
            $url_redirect
        ));
    }

    /**
     * Send the account's activation e-mail again.
     */
    private function resendActivation(): void
    {
        $id    = Params::getParam('id');
        $email = Params::getParam('email');
        $user  = User::getInstance()->findByPrimaryKey((int) $id);
        if ($id == '' || $email == '' || !isset($user) || $user['b_active'] == 1
            || $email != $user['s_email']
        ) {
            osc_add_flash_error_message(_m('Incorrect link'));
            $this->redirectTo(osc_user_login_url());
        }
        if ((new \mindstellar\user\AccountService())->resendActivation((int) $user['pk_i_id'], true)) {
            osc_add_flash_ok_message(_m('Validation email re-sent'));
        } elseif (\mindstellar\user\AccountService::resendWait($user) > 0) {
            osc_add_flash_warning_message(_m('We have just sent you an email to validate your account, you will have to wait a few minutes to resend it again'));
        } else {
            osc_add_flash_error_message(_m('Incorrect link'));
        }
        $this->redirectTo(osc_user_login_url());
    }

    /**
     * The form that asks for a password reset link.
     */
    private function recoverForm(): void
    {
        $this->doView(osc_locate_template(array('user-recover.php'), 'user-recover'));
    }

    /**
     * Send a password reset link.
     */
    private function recoverPost(): void
    {
        osc_csrf_check();

        osc_run_hook('before_user_recover');

        // e-mail is incorrect
        if (!osc_validate_email(Params::getParam('s_email'))) {
            osc_add_flash_error_message(_m('Invalid email address'));
            $this->redirectTo(osc_recover_user_password_url());
        }

        // Before the account is looked up, so it cannot only fail for
        // addresses that exist -- that would hand back the answer the
        // shared message below withholds. It also has to precede the
        // throttle, which relaxes its per-account limit on the strength
        // of a solved captcha.
        if (!\mindstellar\security\Captcha::passes()) {
            osc_add_flash_error_message(\mindstellar\security\Captcha::failMessage());
            $this->redirectTo(osc_recover_user_password_url());
        }

        // Counted on its own, so that reset requests cannot lock anyone
        // out of signing in. Every request counts, not only the ones
        // that match an account: sending mail to an address someone else
        // owns is the abuse being bounded here, and that only happens
        // when the address does match.
        $recoverAccount = trim((string)Params::getParam('s_email'));
        $throttle       = \mindstellar\security\LoginThrottle::evaluate('web-recover', $recoverAccount, osc_captcha_enabled());
        if ($throttle['status'] === \mindstellar\security\LoginThrottle::BLOCKED) {
            osc_add_flash_error_message(osc_login_throttle_message($throttle['retry_after']));
            $this->redirectTo(osc_recover_user_password_url());
        }
        \mindstellar\security\LoginThrottle::recordFailure('web-recover', $recoverAccount);

        // Whether or not the address belongs to an account, the answer is the same: telling
        // the visitor it was not recognised would let anyone use this form to test addresses.
        (new \mindstellar\user\AccountService())->requestPasswordReset((string) Params::getParam('s_email'));
        osc_add_flash_ok_message(_m('If that email address belongs to an account, we have sent it instructions to reset the password'));
        $this->redirectTo(osc_base_url());
    }

    /**
     * The form, reached from the reset link, that sets a new password.
     */
    private function forgotForm(): void
    {
        $user = User::getInstance()
            ->findByIdPasswordSecret(Params::getParamInt('userId'), Params::getParam('code'));
        if ($user) {
            $this->doView(osc_locate_template(array('user-forgot_password.php'), 'user-forgot_password'));
        } else {
            osc_add_flash_error_message(_m('Sorry, the link is not valid'));
            $this->redirectTo(osc_base_url());
        }
    }

    /**
     * Set the new password from the reset link.
     */
    private function forgotPost(): void
    {
        osc_csrf_check();
        if ((Params::getParam('new_password', false, false) == '')
            || (Params::getParam('new_password2', false, false) == '')
        ) {
            osc_add_flash_warning_message(_m('Password cannot be blank'));
            $this->redirectTo(osc_forgot_user_password_confirm_url(
                Params::getParamInt('userId'),
                Params::getParam('code')
            ));
        }

        $user = User::getInstance()
            ->findByIdPasswordSecret(Params::getParamInt('userId'), Params::getParam('code'));
        if (!empty($user) && $user['b_enabled'] == 1) {
            if (Params::getParam('new_password', false, false)
                == Params::getParam('new_password2', false, false)
            ) {
                // Matching on the code keeps the link single-use under two posts at once.
                \mindstellar\user\AccountService::setPassword(
                    (int)$user['pk_i_id'],
                    Params::getParamString('new_password', false, false),
                    Params::getParamString('code'),
                    (string) Params::getServerParam('REMOTE_ADDR')
                );
                osc_add_flash_ok_message(_m('The password has been changed'));
                $this->redirectTo(osc_user_login_url());
            } else {
                osc_add_flash_error_message(_m("Error, the password don't match"));
                $this->redirectTo(osc_forgot_user_password_confirm_url(
                    Params::getParamInt('userId'),
                    Params::getParam('code')
                ));
            }
        } else {
            osc_add_flash_error_message(_m('Sorry, the link is not valid'));
        }
        $this->redirectTo(osc_base_url());
    }

    /**
     * The sign-in form.
     */
    private function loginForm(): void
    {
        // Stash where the visitor came from in a short-lived signed cookie rather
        // than the session, so merely opening the login page never starts a session
        // (which would carry an osclass cookie and defeat reverse-proxy caching).
        osc_set_login_redirect(osc_get_http_referer(), true);
        if (osc_logged_user_id()) {
            $this->redirectTo(osc_user_dashboard_url());
        }
        $this->doView(osc_locate_template(array('user-login.php'), 'user-login'));
    }
    //hopefully generic...

    /**
     * Renders the account template, marked noindex.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        // A sign-in form has nothing to rank for, and every account page behind it
        // redirects here — so this one URL stands in for all of them in a crawl.
        $this->_exportVariableToView('meta_noindex', true);
        osc_run_hook('before_html');
        if (!osc_gui_account_view($file)) {
            osc_current_web_theme_path($file);
        }
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebLogin.php */
