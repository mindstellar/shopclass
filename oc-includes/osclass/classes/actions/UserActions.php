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

use mindstellar\auth\Actor;
use mindstellar\user\AccountInput;
use mindstellar\user\AccountService;
use mindstellar\user\Usernames;
use mindstellar\utility\Sanitize;
use mindstellar\validation\InvalidException;

/**
 * Class UserActions
 */
class UserActions
{
    /** Widths of the t_user columns an account form fills. Compatibility: use AccountService::COLUMN_WIDTHS. */
    public const COLUMN_WIDTHS = AccountService::COLUMN_WIDTHS;

    public $is_admin;
    public $manager;
    /**
     * @var \mindstellar\utility\Sanitize
     */
    private $Sanitize;

    /**
     * UserActions constructor.
     *
     * @param bool $is_admin Run as an admin edit rather than a front-end one
     */
    public function __construct($is_admin)
    {
        $this->is_admin = $is_admin;
        $this->manager  = User::getInstance();
        $this->Sanitize = new Sanitize();
    }

    /**
     * Make an account from the sign-up form a request carries, or from the users screen in
     * admin mode. Compatibility: use \mindstellar\user\AccountService::register().
     *
     * @return int|string 1 on success, 2 when activation is pending, else an error message
     */
    public function add()
    {
        $captcha = $this->is_admin || !osc_captcha_enabled() || osc_check_captcha();
        $form    = AccountInput::signUp();
        try {
            $account = (new AccountService())->register($form, $this->actor(), $captcha);
        } catch (InvalidException $e) {
            $session = Session::getInstance();
            $session->_setForm('user_s_name', $this->Sanitize->string((string) $form['s_name']));
            $session->_setForm('user_s_username', $this->Sanitize->username((string) $form['s_username']));
            $session->_setForm('user_s_email', $this->Sanitize->email((string) $form['s_email']));
            $session->_setForm('user_s_phone_land', $this->Sanitize->phone((string) $form['s_phone_land']));
            $session->_setForm('user_s_phone_mobile', $this->Sanitize->phone((string) $form['s_phone_mobile']));

            return implode(PHP_EOL, array_column($e->errors(), 'message')) . PHP_EOL;
        }

        return $account['active'] ? 2 : 1;
    }

    /**
     * Prepare and sanitize user input data.
     * Compatibility: use \mindstellar\user\AccountService::input().
     *
     * @param bool $is_add Build a row for an insert rather than an update
     *
     * @return array<string,mixed>
     */
    public function prepareData($is_add)
    {
        $admin = (bool) $this->is_admin || (bool) $is_add;
        $row   = (new AccountService())->input(AccountInput::read($admin), $admin);
        if ($is_add) {
            $date = date('Y-m-d H:i:s');
            $row  = ['dt_reg_date' => $date, 'dt_access_date' => $date, 's_secret' => osc_genRandomPassword(), 's_access_ip' => Params::getServerParam('REMOTE_ADDR')] + $row;
        }

        return $row;
    }

    /**
     * After a user edits their own profile, the name and phone this request shows. No session
     * write, so a signed-in user stays session-free.
     */
    public static function refreshIdentity(int $userId): void
    {
        $user = User::getInstance()->findByPrimaryKey($userId);
        if (is_array($user) && $user !== []) {
            Session::getInstance()->_setEphemeral('userName', $user['s_name']);
            Session::getInstance()->_setEphemeral('userPhone', $user['s_phone_mobile'] ?: $user['s_phone_land']);
        }
    }

    /**
     * Save the profile form a request carries: the user's own, or any user's in admin mode.
     * Compatibility: use \mindstellar\user\AccountService::update().
     *
     * @param int $userId
     *
     * @return int|string 1 on success, 2 when an admin edit changed the account's state, else an error message
     */
    public function edit($userId)
    {
        try {
            $result = (new AccountService())->update((int) $userId, AccountInput::read((bool) $this->is_admin), $this->actor());
        } catch (InvalidException $e) {
            return implode(PHP_EOL, array_column($e->errors(), 'message')) . PHP_EOL;
        }
        if (!$this->is_admin) {
            self::refreshIdentity((int) $userId);
        }

        return $result;
    }

    /**
     * The acting user as this class has always taken it: the signed-in admin in admin mode,
     * otherwise the signed-in user.
     */
    private function actor(): Actor
    {
        return Actor::fromSession((bool) $this->is_admin);
    }

    /**
     * Recover user password
     *
     * The caller owns the captcha check, which has to happen before the throttle is
     * consulted — and a captcha token verifies exactly once, so there is only ever one
     * place to do it. CWebLogin's 'recover_post' does it, mirroring CAdminLogin.
     *
     * @return int 0 when the email was sent, 1 when the address matched no enabled account
     */
    public function recover_password()
    {
        $user = User::getInstance()->findByEmail(Params::getParam('s_email'));

        if (!$user || ($user['b_enabled'] == 0)) {
            return 1;
        }

        $code = User::getInstance()->issuePassCode((int)$user['pk_i_id'], User::PASS_CODE_RESET);

        $password_url = osc_forgot_user_password_confirm_url($user['pk_i_id'], $code);
        osc_run_hook('hook_email_user_forgot_password', $user, $password_url);

        return 0;
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::activate().
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function activate($user_id)
    {
        return (new AccountService())->activate((int) $user_id, $this->actor());
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::deactivate().
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function deactivate($user_id)
    {
        return (new AccountService())->deactivate((int) $user_id, $this->actor());
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::enable().
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function enable($user_id)
    {
        return (new AccountService())->enable((int) $user_id, $this->actor());
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::disable().
     *
     * @param int $user_id
     *
     * @return bool
     */
    public function disable($user_id)
    {
        return (new AccountService())->disable((int) $user_id, $this->actor());
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::resendActivation().
     *
     * @param int $user_id
     *
     * @return int 1 when a link went out
     */
    public function resend_activation($user_id)
    {
        return (new AccountService())->resendActivation((int) $user_id) ? 1 : 0;
    }

    /**
     * Bootstrap user login
     *
     * @param int $user_id
     *
     * @return int
     */
    public function bootstrap_login($user_id)
    {
        $user = User::getInstance()->findByPrimaryKey($user_id);

        if (!$user) {
            return 0;
        }

        if (!$user['b_active']) {
            return 1;
        }

        if (!$user['b_enabled']) {
            return 2;
        }

        //we are logged in... let's go!
        // Identity now lives in a signed, session-free cookie. Default to a browser-session
        // lifetime here; the login controller upgrades it to persistent when "remember me"
        // is ticked.
        osc_web_user_login($user);

        return 3;
    }

    /**
     * Move the guest listings and alerts posted with this account's e-mail to the account.
     * Compatibility: use \mindstellar\user\AccountService::claimGuestListings().
     *
     * @param int $userId
     *
     * @return void
     */
    public static function claimGuestListings(int $userId): void
    {
        (new AccountService())->claimGuestListings($userId);
    }

    /**
     * Compatibility: use \mindstellar\user\AccountService::confirmEmailChange().
     *
     * @return array{status:string,old:string,new:string}
     */
    public static function confirmEmailChange(int $userId, string $code): array
    {
        return (new AccountService())->confirmEmailChange($userId, $code);
    }

    /**
     * Compatibility: use \mindstellar\user\Usernames::numericError().
     */
    public static function numericUsernameError(string $username): string
    {
        return Usernames::numericError($username);
    }

    /**
     * Compatibility: use \mindstellar\user\Usernames::claim().
     *
     * @return string 'ok', 'taken' or 'failed'
     */
    public static function claimUsername(int $userId, string $username): string
    {
        return Usernames::claim($userId, $username);
    }
}
