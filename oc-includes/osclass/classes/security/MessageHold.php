<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use mindstellar\job\JobQueue;

/**
 * Mail to a member leaves only from a confirmed sender address. A signed-in member's own
 * address is confirmed; anyone else's message waits in the job queue until they click a link
 * sent to the address they typed, and that browser is then trusted for 30 days.
 */
final class MessageHold
{
    /** A message waiting for its sender to confirm. The job itself does nothing; it expires. */
    public const JOB = 'message.held';

    private const HOLD_TTL  = 86400;
    private const TRUST_TTL = 30 * 86400;
    private const COOKIE    = 'osc_msg_trust';

    /** @var bool whether the last deliver() held the message */
    private static $held = false;

    /**
     * Whether $email is confirmed for this visitor.
     *
     * @param string $email
     *
     * @return bool
     */
    public static function verified(string $email): bool
    {
        if (trim($email) === '') {
            return false;
        }
        $key = self::key($email);
        if (osc_is_web_user_logged_in() && hash_equals(self::key((string) osc_logged_user_email()), $key)) {
            return true;
        }
        $trust = SignedPayload::unpack('message-trust', (string) ($_COOKIE[self::COOKIE] ?? ''));

        return $trust !== null && hash_equals((string) ($trust['h'] ?? ''), $key);
    }

    /**
     * Send the message now when the sender is confirmed, or hold it and mail them a link.
     * A held message sets its own flash message.
     *
     * @param string              $kind  'item_contact', 'user_contact' or 'send_friend'
     * @param string              $email the sender's address
     * @param array<string,mixed> $args  what send() needs, as plain data
     *
     * @return bool true when the message was sent now
     */
    public static function deliver(string $kind, string $email, array $args): bool
    {
        self::$held = false;
        if (self::verified($email)) {
            return self::send($kind, $args);
        }

        self::$held = true;
        $queue      = JobQueue::instance();
        $key        = self::key($email);
        if ($queue->hasKey(self::JOB, $key)) {
            osc_add_flash_info_message(sprintf(
                _m('A message from %s is already waiting. Click the link we e-mailed to that address to send it.'),
                $email
            ));

            return false;
        }
        try {
            $id = $queue->enqueue(
                self::JOB,
                array('kind' => $kind, 'email' => $email, 'args' => $args),
                array('delay' => self::HOLD_TTL, 'unique_key' => $key)
            );
        } catch (\InvalidArgumentException $e) {
            $id = 0;
        }
        if ($id <= 0) {
            osc_add_flash_error_message(_m('Your message could not be sent. Please try again later.'));

            return false;
        }

        self::mailConfirmLink($email, $id);
        osc_add_flash_info_message(sprintf(
            _m('Check your inbox. We sent a link to %s, and your message is sent when you click it.'),
            $email
        ));

        return false;
    }

    /**
     * Whether the last deliver() held the message rather than sending it.
     *
     * @return bool
     */
    public static function held(): bool
    {
        return self::$held;
    }

    /**
     * Send a held message whose link was clicked, and trust this browser for its address.
     *
     * @param string $token
     *
     * @return string 'sent', 'gone' (already sent, or expired), 'invalid' or 'failed'
     */
    public static function confirm(string $token): string
    {
        $data = SignedPayload::unpack('message-confirm', $token);
        if ($data === null || !isset($data['i'], $data['h'])) {
            return 'invalid';
        }
        // The job's key is the sender's address hash, so a link only takes its own message.
        $held = JobQueue::instance()->take((int) $data['i'], self::JOB, (string) $data['h']);
        if ($held === null) {
            return 'gone';
        }
        $email = (string) ($held['email'] ?? '');

        self::trust($email);

        return self::send((string) ($held['kind'] ?? ''), (array) ($held['args'] ?? array())) ? 'sent' : 'failed';
    }

    /**
     * Send a message through its mail hook. Listings and users are read again, so a message
     * held for a listing that has since gone is dropped.
     *
     * @param string              $kind
     * @param array<string,mixed> $args
     *
     * @return bool
     */
    public static function send(string $kind, array $args): bool
    {
        switch ($kind) {
            case 'item_contact':
            case 'send_friend':
                $item = \Item::newInstance()->findByPrimaryKey((int) ($args['id'] ?? 0));
                if (!$item) {
                    return false;
                }
                \View::newInstance()->_exportVariableToView('item', $item);
                $args['item'] = $item;
                if ($kind === 'send_friend') {
                    $args['s_title'] = $item['s_title'];
                    osc_run_hook('hook_email_send_friend', $args);
                } else {
                    osc_run_hook('hook_email_item_inquiry', $args);
                }

                return true;
            case 'user_contact':
                $user = \User::newInstance()->findByPrimaryKey((int) ($args['id'] ?? 0));
                if (!$user || !$user['b_active'] || !$user['b_enabled']) {
                    return false;
                }
                \View::newInstance()->_exportVariableToView('user', $user);
                osc_run_hook(
                    'hook_email_contact_user',
                    (int) $user['pk_i_id'],
                    (string) $args['yourEmail'],
                    (string) $args['yourName'],
                    (string) $args['phoneNumber'],
                    (string) $args['message']
                );

                return true;
        }

        return false;
    }

    /**
     * The confirm mail. It carries no text the visitor typed, so it cannot carry spam.
     *
     * @param string $email
     * @param int    $id
     *
     * @return void
     */
    private static function mailConfirmLink(string $email, int $id): void
    {
        $url = self::confirmUrl($id, $email);

        $body = '<p>' . osc_esc_html(sprintf(
            _m('Someone, hopefully you, wrote a message on %s and gave this e-mail address.'),
            osc_page_title()
        )) . '</p>'
            . '<p><a href="' . osc_esc_html($url) . '">' . osc_esc_html(_m('Send my message')) . '</a></p>'
            . '<p>' . osc_esc_html(_m('If it was not you, ignore this e-mail. The message is deleted in 24 hours.'))
            . '</p>';

        osc_sendMail(array(
            'from'      => _osc_from_email_aux(),
            'from_name' => osc_page_title(),
            'to'        => $email,
            'subject'   => sprintf(__('Confirm your message on %s'), osc_page_title()),
            'body'      => $body,
        ));
    }

    /**
     * The link that sends held message $id from $email.
     *
     * @param int    $id
     * @param string $email
     *
     * @return string
     */
    public static function confirmUrl(int $id, string $email): string
    {
        $token = SignedPayload::pack('message-confirm', array('i' => $id, 'h' => self::key($email)), self::HOLD_TTL);

        return osc_base_url(true) . '?page=contact&action=confirm&t=' . rawurlencode($token);
    }

    /**
     * Remember in this browser that $email is confirmed.
     *
     * @param string $email
     *
     * @return void
     */
    private static function trust(string $email): void
    {
        $value = SignedPayload::pack('message-trust', array('h' => self::key($email)), self::TRUST_TTL);
        osc_write_signed_redirect_cookie(self::COOKIE, $value, time() + self::TRUST_TTL);
        $_COOKIE[self::COOKIE] = $value;
    }

    /**
     * @param string $email
     *
     * @return string
     */
    private static function key(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * The two queue types this feature uses. Neither does work when it runs: each only
     * waits out its time and is then dropped.
     *
     * @return void
     */
    public static function registerJobs(): void
    {
        $expire = static function () {
        };
        osc_job_register_handler(self::JOB, $expire);
        osc_job_register_handler(MessageGuard::USED_JOB, $expire);
        osc_job_describe(self::JOB, __('Message waiting for its sender to confirm'));
        osc_job_describe(MessageGuard::USED_JOB, __('Used report link'));
    }
}
