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
 * Class CWebContact
 */
class CWebContact extends BaseModel
{
    /**
     * Boots the base controller and fires the `init_contact` hook.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_contact');
    }

    //Business Layer...

    /**
     * Sends the contact message on `contact_post`, otherwise renders the contact form.
     *
     * @return false|null false only when the captcha check failed and the request was
     *                    redirected back to the form
     */
    public function doModel()
    {
        switch ($this->action) {
            case ('contact_post'):   //contact_post
                osc_csrf_check();
                $yourName  = Params::getParamString('yourName');
                $yourEmail = Params::getParamString('yourEmail');
                $subject   = Params::getParamString('subject');
                $message   = Params::getParamString('message');
                // A failed send keeps what was typed and the reason, so the form can show both.
                $fail = function (string $error) use ($yourName, $yourEmail, $subject, $message) {
                    osc_keep_form(array(
                        'yourName' => $yourName, 'yourEmail' => $yourEmail,
                        'subject'  => $subject, 'message_body' => $message,
                    ), $error);
                    $this->redirectTo(osc_contact_url());
                };

                if (osc_captcha_enabled() && !osc_check_captcha()) {
                    $fail(_m('Please complete the security check.'));

                    return false;
                }
                if (trim($yourName) === '' || trim($subject) === '' || trim($message) === '') {
                    $fail(_m('Please enter your name, a subject and a message.'));

                    return false;
                }
                if (!osc_validate_email($yourEmail)) {
                    $fail(_m('Please enter a correct email'));

                    return false;
                }

                $banned = osc_is_banned($yourEmail);
                if ($banned == 1) {
                    $fail(_m('Your current email is not allowed'));

                    return false;
                } elseif ($banned == 2) {
                    $fail(_m('Your current IP is not allowed'));

                    return false;
                }

                $user = User::newInstance()->findByEmail($yourEmail);
                if (isset($user['b_active'])
                    && ($user['b_active'] == 0
                        || $user['b_enabled'] == 0)
                ) {
                    $fail(_m('Your current email is not allowed'));

                    return false;
                }

                $message_name    = sprintf(__('Name: %s'), $yourName);
                $message_email   = sprintf(__('Email: %s'), $yourEmail);
                $message_subject = sprintf(__('Subject: %s'), $subject);
                $message_body    = sprintf(__('Message: %s'), $message);
                $message_date    = sprintf(__('Date: %s at %s'), date('l F d, Y'), date('g:i a'));
                $message_IP      = sprintf(__('IP Address: %s'), get_ip());
                $message         = <<<MESSAGE
{$message_name}
{$message_email}
{$message_subject}
{$message_body}

{$message_date}
{$message_IP}
MESSAGE;

                $params = array(
                    'from'     => _osc_from_email_aux(),
                    'to'       => osc_contact_email(),
                    'to_name'  => osc_page_title(),
                    'reply_to' => $yourEmail,
                    'subject'  => '[' . osc_page_title() . '] ' . __('Contact') . ' - ' . $subject,
                    'body'     => nl2br($message)
                );

                $error = false;
                if (osc_contact_attachment()) {
                    $emailAttachment = osc_mail_upload_attachment('attachment');
                    $error           = $emailAttachment === false;
                }
                if ($error) {
                    osc_add_flash_error_message(_m('That type of file cannot be attached.'));
                } else {
                    if (!empty($emailAttachment)) {
                        $params['attachment'] = $emailAttachment;
                    }

                    osc_run_hook('pre_contact_post', $params);

                    osc_sendMail(osc_apply_filter('contact_params', $params));

                    osc_add_flash_ok_message(_m('Your email has been sent properly. Thank you for contacting us!'));
                }

                $this->redirectTo(osc_contact_url());
                break;
            default:                //contact
                $this->doView(osc_locate_template(array('contact.php'), 'contact'));
        }
    }

    //hopefully generic...

    /**
     * Renders the contact template with its canonical URL exported to the view.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        // Indexable on purpose — a contact page is somewhere people search for. It
        // only lacked a canonical, which it is reachable without.
        $this->_exportVariableToView('canonical', osc_contact_url());
        osc_run_hook('before_html');
        if (!osc_gui_page_view($file)) {
            osc_current_web_theme_path($file);
        }
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebContact.php */
