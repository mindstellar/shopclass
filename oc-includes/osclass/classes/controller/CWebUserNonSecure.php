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
 * Class CWebUserNonSecure
 */
class CWebUserNonSecure extends BaseModel
{
    /**
     * Boots the base controller, bounces the visitor home when accounts are disabled
     * (except for the alert actions), and fires the `init_user_non_secure` hook.
     */
    public function __construct()
    {
        parent::__construct();
        if (!osc_users_enabled()
            && ($this->action !== 'activate_alert'
                && $this->action !== 'unsub_alert')
        ) {
            osc_add_flash_error_message(_m('Users not enabled'));
            $this->redirectTo(osc_base_url());
        }
        osc_run_hook('init_user_non_secure');
    }

    //Business Layer...

    /**
     * Dispatches the account actions that need no session: email-change confirmation,
     * alert activation/unsubscribe, the public profile and its contact form.
     *
     * @return false|null false only when the captcha check failed and the request was
     *                    redirected back to the profile
     */
    public function doModel()
    {
        switch ($this->action) {
            case 'change_email_confirm':    //change email confirm
                if (Params::getParam('userId') && Params::getParam('code')) {
                    $userManager = new User();
                    $user        = $userManager->findByPrimaryKey(Params::getParam('userId'));

                    if ($user['s_pass_code'] == Params::getParam('code')
                        && $user['b_enabled'] == 1
                    ) {
                        $userOldEmail = $user['s_email'];
                        $userEmailTmp = UserEmailTmp::newInstance()
                            ->findByPrimaryKey(Params::getParam('userId'));
                        $code         = osc_genRandomPassword(50);
                        $userManager->update(
                            array('s_email' => $userEmailTmp['s_new_email']),
                            array('pk_i_id' => $userEmailTmp['fk_i_user_id'])
                        );
                        Item::newInstance()
                            ->update(
                                array('s_contact_email' => $userEmailTmp['s_new_email']),
                                array('fk_i_user_id' => $userEmailTmp['fk_i_user_id'])
                            );
                        ItemComment::newInstance()
                            ->update(
                                array('s_author_email' => $userEmailTmp['s_new_email']),
                                array('fk_i_user_id' => $userEmailTmp['fk_i_user_id'])
                            );
                        Alerts::newInstance()
                            ->update(
                                array('s_email' => $userEmailTmp['s_new_email']),
                                array('fk_i_user_id' => $userEmailTmp['fk_i_user_id'])
                            );
                        // Request-scoped refresh only — the next request re-resolves the
                        // email from the database via the signed identity cookie, so no
                        // physical session is started for this logged-in user.
                        Session::newInstance()->_setEphemeral('userEmail', $userEmailTmp['s_new_email']);
                        UserEmailTmp::newInstance()
                            ->delete(array('s_new_email' => $userEmailTmp['s_new_email']));

                        osc_run_hook(
                            'change_email_confirm',
                            Params::getParam('userId'),
                            $userOldEmail,
                            $userEmailTmp['s_new_email']
                        );

                        osc_add_flash_ok_message(_m('Your email has been changed successfully'));
                        $this->redirectTo(osc_user_profile_url());
                    } else {
                        osc_add_flash_error_message(_m('Sorry, the link is not valid'));
                        $this->redirectTo(osc_base_url());
                    }
                } else {
                    osc_add_flash_error_message(_m('Sorry, the link is not valid'));
                    $this->redirectTo(osc_base_url());
                }
                break;
            case 'activate_alert':
                $email  = Params::getParam('email');
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');

                $alert  = Alerts::newInstance()->findByPrimaryKey($id);
                $result = 0;
                // A held alert has no search left to send, so its link no longer works.
                if (!empty($alert)
                    && \mindstellar\search\AlertEnvelope::heldReason((string)$alert['s_search']) !== null
                ) {
                    osc_add_flash_error_message(_m('Sorry, the link is not valid'));
                    $this->redirectTo(osc_base_url());
                }
                if (!empty($alert) && $email == $alert['s_email']
                    && $secret == $alert['s_secret']
                ) {
                    $user = User::newInstance()->findByEmail($alert['s_email']);
                    if (isset($user['pk_i_id'])) {
                        Alerts::newInstance()->update(
                            array('fk_i_user_id' => $user['pk_i_id']),
                            array('pk_i_id' => $id)
                        );
                    }
                    $result = Alerts::newInstance()->activate($id);
                }

                if ($result == 1) {
                    osc_add_flash_ok_message(_m('Alert activated'));
                } else {
                    osc_add_flash_error_message(_m('Oops! There was a problem trying to activate your alert. Please contact an administrator'));
                }

                $this->redirectTo(osc_base_url());
                break;
            case 'unsub_alert':
                $email  = Params::getParam('email');
                $secret = Params::getParam('secret');
                $id     = Params::getParam('id');

                $alert  = Alerts::newInstance()->findByPrimaryKey($id);
                $result = 0;
                if (!empty($alert) && $email == $alert['s_email']
                    && $secret == $alert['s_secret']
                ) {
                    $result = Alerts::newInstance()->unsub($id);
                }

                if ($result == 1) {
                    osc_add_flash_ok_message(_m('Unsubscribed correctly'));
                } else {
                    osc_add_flash_error_message(_m('Oops! There was a problem trying to unsubscribe you. Please contact an administrator'));
                }

                $this->redirectTo(osc_base_url());
                break;
            case 'pub_profile':
                if (Params::getParam('username') != '') {
                    $user = User::newInstance()->findByUsername(Params::getParam('username'));
                } else {
                    $user = User::newInstance()->findByPrimaryKey(Params::getParam('id'));
                }
                // user doesn't exist, show 404 error
                if (!$user) {
                    $this->do404();

                    return;
                }

                if ($user['b_active'] == 0) {
                    // user is not active, redirect to homepage
                    osc_add_flash_warning_message(_m('User not activated'));
                    $this->redirectTo(osc_base_url());
                }
                if ($user['b_enabled'] == 0) {
                    // user is not enabled, redirect to homepage
                    osc_add_flash_warning_message(_m('User not enabled'));
                    $this->redirectTo(osc_base_url());
                }

                $itemsPerPage = Params::getParam('itemsPerPage');
                if (is_numeric($itemsPerPage) && (int)$itemsPerPage > 0) {
                    $itemsPerPage = min((int)$itemsPerPage, 100);
                } else {
                    $itemsPerPage = 10;
                }

                $page = Params::getParam('iPage');
                if (is_numeric($page) && (int)$page > 0) {
                    $page = (int)$page - 1;
                } else {
                    $page = 0;
                }

                $total_items =
                    Item::newInstance()->countItemTypesByUserID($user['pk_i_id'], 'active');

                if ($itemsPerPage === 'all') {
                    $total_pages = 1;
                    $items       = Item::newInstance()
                        ->findItemTypesByUserID($user['pk_i_id'], 0, null, 'active');
                } else {
                    $total_pages = ceil($total_items / $itemsPerPage);
                    $items       = Item::newInstance()
                        ->findItemTypesByUserID(
                            $user['pk_i_id'],
                            $page * $itemsPerPage,
                            $itemsPerPage,
                            'active'
                        );
                }

                View::newInstance()->_exportVariableToView('user', $user);
                $this->_exportVariableToView('items', $items);
                $this->_exportVariableToView('search_total_pages', $total_pages);
                $this->_exportVariableToView('search_total_items', $total_items);
                $this->_exportVariableToView('items_per_page', $itemsPerPage);
                $this->_exportVariableToView('search_page', $page);
                $this->_exportVariableToView('canonical', osc_user_public_profile_url());

                // Public seller profile (a user's public listings): cacheable for anonymous
                // visitors. Sibling `/user` routes (dashboard, account) stay private by default.
                osc_mark_response_cacheable();
                $this->doView(osc_locate_template(array('user-public-profile.php'), 'user-public-profile'));
                break;
            case 'contact_post':
                osc_csrf_check();
                $user = User::newInstance()->findByPrimaryKey(Params::getParamInt('id'));
                if (!$user || !$user['b_active'] || !$user['b_enabled']) {
                    $this->do404();

                    return;
                }
                View::newInstance()->_exportVariableToView('user', $user);
                $back = osc_user_public_profile_url((int) $user['pk_i_id']);

                if (osc_reg_user_can_contact() && !osc_is_web_user_logged_in()) {
                    osc_add_flash_warning_message(_m('Only registered users can send a message.'));
                    $this->redirectTo($back);
                }

                $yourEmail = Params::getParamString('yourEmail');
                $yourName  = Params::getParamString('yourName');
                $phone     = Params::getParamString('phoneNumber');
                $message   = Params::getParamString('message');
                // A failed send keeps what was typed and the reason, so the form can show both.
                $fail = function (string $error) use ($yourEmail, $yourName, $phone, $message, $back) {
                    $session = Session::newInstance();
                    $session->_setForm('yourEmail', $yourEmail);
                    $session->_setForm('yourName', $yourName);
                    $session->_setForm('phoneNumber', $phone);
                    $session->_setForm('message_body', $message);
                    $session->_setForm('contact_error', $error);
                    osc_add_flash_error_message($error);
                    $this->redirectTo($back);
                };

                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    $fail(_m('Please complete the security check.'));

                    return;
                }
                if ($yourName === '' || trim($message) === '' || !osc_validate_email($yourEmail)) {
                    $fail(_m('Please enter your name, a valid email address and a message.'));

                    return;
                }

                $banned = osc_is_banned($yourEmail);
                if ($banned == 1) {
                    $fail(_m('Your current email is not allowed'));

                    return;
                } elseif ($banned == 2) {
                    $fail(_m('Your current IP is not allowed'));

                    return;
                }

                if (\mindstellar\security\ActionThrottle::exceeded(
                    'user_contact',
                    (int) osc_apply_filter('user_contact_throttle_max', 15),
                    (int) osc_apply_filter('user_contact_throttle_window', 3600)
                )) {
                    $fail(_m("You've sent too many messages recently. Please try again later."));

                    return;
                }

                osc_run_hook(
                    'hook_email_contact_user',
                    (int) $user['pk_i_id'],
                    $yourEmail,
                    $yourName,
                    $phone,
                    $message
                );
                \mindstellar\security\ActionThrottle::record('user_contact');
                osc_add_flash_ok_message(_m('Your email has been sent properly.'));
                $this->redirectTo($back);
                break;
            default:
                $this->redirectTo(osc_user_login_url());
                break;
        }
    }

    //hopefully generic...

    /**
     * Renders the account template, falling back to core's view when the theme has none.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        if (!osc_gui_account_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebUserNonSecure.php */
