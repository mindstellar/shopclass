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
                // Themes label name and subject optional, so only the message is required.
                if (trim($message) === '') {
                    $fail(_m('Please enter a message.'));

                    return false;
                }
                if (!osc_validate_email($yourEmail)) {
                    $fail(_m('Please enter a correct email'));

                    return false;
                }

                $refused = \mindstellar\security\MessageGuard::refusal($yourEmail, $message, array($yourName));
                if ($refused !== null) {
                    $fail($refused);

                    return false;
                }
                if (\mindstellar\security\ActionThrottle::exceeded(
                    'site_contact',
                    (int) osc_apply_filter('site_contact_throttle_max', 5),
                    (int) osc_apply_filter('site_contact_throttle_window', 3600)
                )) {
                    $fail(_m("You've sent too many messages recently. Please try again later."));

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
                    'body'     => nl2br(osc_esc_html($message))
                        . \mindstellar\security\MessageGuard::reportFooter($yourEmail, osc_contact_email(), true),
                );

                $attachment = osc_contact_attachment() ? osc_mail_upload_attachment('attachment') : null;
                $refused    = \mindstellar\security\MessageHold::attachmentError($yourEmail, $attachment);
                if ($refused !== null) {
                    $fail($refused);

                    return false;
                }
                if (is_array($attachment)) {
                    $params['attachment'] = $attachment;
                }

                osc_run_hook('pre_contact_post', $params);
                $sent = \mindstellar\security\MessageHold::deliver(
                    'site_contact',
                    $yourEmail,
                    array('params' => osc_apply_filter('contact_params', $params), 'message' => $message)
                );
                \mindstellar\security\ActionThrottle::record('site_contact');
                if ($sent) {
                    osc_add_flash_ok_message(_m('Your email has been sent properly. Thank you for contacting us!'));
                }

                $this->redirectTo(osc_contact_url());
                break;
            case ('report'):
            case ('confirm'):
                $this->messageView($this->action);
                break;
            case ('report_post'):
                $this->linkPost('report', 10, static function (string $token): string {
                    $status = \mindstellar\security\MessageGuard::report($token);
                    if ($status === 'done') {
                        $sender = (string) (\mindstellar\security\MessageGuard::readReport($token)['sender'] ?? '');
                        Log::newInstance()->insertLog('ban', 'report', 0, $sender, 'user', 0);
                    }

                    return $status;
                }, array(
                    'used'    => _m('This message has already been reported.'),
                    'invalid' => _m('This report link is not valid or has expired.'),
                    'admin'   => _m('Only a site admin can ban a sender for good.'),
                    'failed'  => _m('The report could not be saved. Please try again later.'),
                ));
                break;
            case ('confirm_post'):
                $discard = Params::getParamString('discard') === '1';
                $this->linkPost('confirm', 20, static function (string $token) use ($discard): string {
                    return \mindstellar\security\MessageHold::confirm($token, $discard);
                }, array(
                    'gone'    => _m('This message was already sent, or its link has expired.'),
                    'invalid' => _m('This link is not valid.'),
                    'failed'  => _m('The message could not be sent. The listing or member may no longer be available.'),
                ), $discard ? 'deleted' : '1');
                break;
            default:                //contact
                $this->doView(osc_locate_template(array('contact.php'), 'contact'));
        }
    }

    /**
     * The button on a message-link page: check the form, spend one of this address's tries
     * for the hour, run $run on the link's token and come back with its outcome.
     *
     * @param string               $mode   'report' or 'confirm'
     * @param int                  $max    tries per hour from one address
     * @param callable             $run    fn(string $token): string, 'done' or an $errors key
     * @param array<string,string> $errors message per outcome
     * @param string               $done   the done= value on success
     *
     * @return void
     */
    private function linkPost(string $mode, int $max, callable $run, array $errors, string $done = '1')
    {
        osc_csrf_check();
        $back    = osc_base_url(true) . '?page=contact&action=' . $mode;
        $context = 'message_' . $mode;
        if (\mindstellar\security\ActionThrottle::exceeded($context, $max, 3600)) {
            osc_add_flash_error_message(_m('Too many tries from your connection. Please try again later.'));
            $this->redirectTo($back);
        }
        \mindstellar\security\ActionThrottle::record($context);
        $status = $run(Params::getParamString('t'));
        if ($status === 'done') {
            $this->redirectTo($back . '&done=' . $done);
        }
        osc_add_flash_error_message($errors[$status] ?? $errors['failed']);
        $this->redirectTo($back);
    }

    /**
     * The pages the links in message mail open: send a held message, or report its sender.
     * Nothing happens until the button is pressed, so a mail scanner following a link does nothing.
     *
     * @param string $mode 'confirm' or 'report'
     *
     * @return void
     */
    private function messageView(string $mode)
    {
        $done  = Params::getParamString('done');
        $token = $done !== '' ? '' : Params::getParamString('t');
        $data  = null;
        if ($token !== '') {
            $data = $mode === 'report'
                ? \mindstellar\security\MessageGuard::readReport($token)
                : \mindstellar\security\MessageHold::preview($token);
        }
        // The token is in this page's address; keep it out of Referer headers.
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }
        $heading = $mode === 'report' ? _m('Report the sender') : _m('Send your message');

        $this->_exportVariableToView('meta_noindex', true);
        $this->_exportVariableToView('message_mode', $mode);
        $this->_exportVariableToView('message_done', $done);
        $this->_exportVariableToView('message_token', $data === null ? '' : $token);
        $this->_exportVariableToView('message_to', $mode === 'confirm' ? (string) ($data['to'] ?? '') : '');
        $this->_exportVariableToView('message_text', $mode === 'confirm' ? (string) ($data['message'] ?? '') : '');
        $this->_exportVariableToView('report_sender', $mode === 'report' ? (string) ($data['sender'] ?? '') : '');
        $this->_exportVariableToView('report_permanent', $mode === 'report' && !empty($data['permanent']));
        $this->_exportVariableToView('report_days', \mindstellar\security\MessageGuard::banDays());

        osc_run_hook('before_html');
        osc_gui_view(
            'contact-message.php',
            ABS_PATH . 'oc-includes/osclass/gui/contact-message-content.php',
            array('heading' => $heading, 'title' => $heading . ' — ' . osc_page_title())
        );
        Session::newInstance()->_clearVariables();
        osc_run_hook('after_html');
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
