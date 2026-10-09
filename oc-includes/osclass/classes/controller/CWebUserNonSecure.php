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

use mindstellar\admin\ListPaging;
use mindstellar\search\UserAlerts;

/**
 * Class CWebUserNonSecure
 */
class CWebUserNonSecure extends BaseModel
{
    use \mindstellar\base\ActionMap;

    /** Each action and the method that answers it; any other action goes to toSignIn(). */
    private const ACTIONS = array(
        'change_email_confirm' => 'confirmEmailChange',
        'activate_alert'       => 'activateAlert',
        'unsub_alert'          => 'unsubscribeAlert',
        'pub_profile'          => 'publicProfile',
        'contact_post'         => 'contactPost',
    );

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
     * @return null
     */
    public function doModel()
    {
        $method = $this->actionMethod('toSignIn');

        $this->$method();

        return null;
    }

    /**
     * Confirm a new e-mail from the link sent to it.
     */
    private function confirmEmailChange(): void
    {
        $change = (new \mindstellar\user\AccountService())->confirmEmailChange(
            Params::getParamInt('userId'),
            Params::getParamString('code')
        );
        if ($change['status'] === 'ok') {
            // Request-scoped refresh only — the next request re-resolves the
            // email from the database via the signed identity cookie, so no
            // physical session is started for this logged-in user.
            Session::getInstance()->_setEphemeral('userEmail', $change['new']);

            osc_run_hook(
                'change_email_confirm',
                Params::getParam('userId'),
                $change['old'],
                $change['new']
            );

            osc_add_flash_ok_message(_m('Your email has been changed successfully'));
            $this->redirectTo(osc_user_profile_url());
        } elseif ($change['status'] === 'taken') {
            osc_add_flash_error_message(_m('The specified e-mail is already in use'));
            $this->redirectTo(osc_base_url());
        } elseif ($change['status'] === 'failed') {
            osc_add_flash_error_message(_m('Your email could not be changed. Please try again.'));
            $this->redirectTo(osc_base_url());
        } else {
            osc_add_flash_error_message(_m('Sorry, the link is not valid'));
            $this->redirectTo(osc_base_url());
        }
    }

    /**
     * Turn on a saved search from the link in its e-mail.
     */
    private function activateAlert(): void
    {
        $result = (new UserAlerts())->activateByLink(
            Params::getParamInt('id'),
            Params::getParamString('email'),
            Params::getParamString('secret')
        );
        if ($result === UserAlerts::HELD) {
            osc_add_flash_error_message(_m('Sorry, the link is not valid'));
        } elseif ($result === UserAlerts::ACTIVATED) {
            osc_add_flash_ok_message(_m('Alert activated'));
        } else {
            osc_add_flash_error_message(_m('Oops! There was a problem trying to activate your alert. Please contact an administrator'));
        }

        $this->redirectTo(osc_base_url());
    }

    /**
     * Stop a saved search's e-mails from the link in them.
     */
    private function unsubscribeAlert(): void
    {
        $unsubscribed = (new UserAlerts())->unsubscribeByLink(
            Params::getParamInt('id'),
            Params::getParamString('email'),
            Params::getParamString('secret')
        );
        if ($unsubscribed) {
            osc_add_flash_ok_message(_m('Unsubscribed correctly'));
        } else {
            osc_add_flash_error_message(_m('Oops! There was a problem trying to unsubscribe you. Please contact an administrator'));
        }

        $this->redirectTo(osc_base_url());
    }

    /**
     * A seller's public profile.
     */
    private function publicProfile(): void
    {
        if (Params::getParam('username') != '') {
            $user = User::getInstance()->findByUsername(Params::getParam('username'));
        } else {
            $user = User::getInstance()->findByPrimaryKey(Params::getParamInt('id'));
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

        $itemsPerPage = ListPaging::length(10, 'itemsPerPage', 100);

        $page = ListPaging::page() - 1;

        $total_items =
            Item::getInstance()->countItemTypesByUserID($user['pk_i_id'], 'active');

        $total_pages = ceil($total_items / $itemsPerPage);
        $items       = Item::getInstance()
            ->findItemTypesByUserID(
                $user['pk_i_id'],
                $page * $itemsPerPage,
                $itemsPerPage,
                'active'
            );

        View::getInstance()->_exportVariableToView('user', $user);
        osc_prime_item_upgrades($items);
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
    }

    /**
     * Send a message to a seller from their profile.
     */
    private function contactPost(): void
    {
        osc_csrf_check();
        $user = User::getInstance()->findByPrimaryKey(Params::getParamInt('id'));
        if (!$user || !\mindstellar\user\UserStore::isLive($user)) {
            $this->do404();

            return;
        }
        View::getInstance()->_exportVariableToView('user', $user);
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
            osc_keep_form(array(
                'yourEmail'   => $yourEmail, 'yourName' => $yourName,
                'phoneNumber' => $phone, 'message_body' => $message,
            ), $error);
            $this->redirectTo($back);
        };

        if (!\mindstellar\security\Captcha::passes()) {
            $fail(\mindstellar\security\Captcha::failMessage());

            return;
        }
        if ($yourName === '' || trim($message) === '' || !osc_validate_email($yourEmail)) {
            $fail(_m('Please enter your name, a valid email address and a message.'));

            return;
        }

        $refused = \mindstellar\security\MessageGuard::refusal($yourEmail, $message, array($yourName), $phone);
        if ($refused !== null) {
            $fail($refused);

            return;
        }

        if (\mindstellar\security\ActionThrottle::exceededFor('user_contact')) {
            $fail(_m("You've sent too many messages recently. Please try again later."));

            return;
        }

        $sent = \mindstellar\security\MessageHold::deliver('user_contact', $yourEmail, array(
            'id'          => (int) $user['pk_i_id'],
            'yourEmail'   => $yourEmail,
            'yourName'    => $yourName,
            'phoneNumber' => $phone,
            'message'     => $message,
        ));
        \mindstellar\security\ActionThrottle::record('user_contact');
        if ($sent) {
            osc_add_flash_ok_message(_m('Your email has been sent properly.'));
        }
        $this->redirectTo($back);
    }

    /**
     * Any other action goes to the sign-in page.
     */
    private function toSignIn(): void
    {
        $this->redirectTo(osc_user_login_url());
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
        Session::getInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebUserNonSecure.php */
