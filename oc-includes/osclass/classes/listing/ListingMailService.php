<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\listing;

use mindstellar\security\ActionThrottle;
use mindstellar\security\MessageGuard;
use mindstellar\security\MessageHold;
use mindstellar\validation\BlockedException;
use mindstellar\validation\ConflictException;
use mindstellar\validation\InvalidException;

/**
 * Mail a visitor sends about a listing: an enquiry to its seller, or the listing shared with
 * a friend. Callers check the site allows the visitor to send it and the captcha first.
 */
final class ListingMailService
{
    /**
     * Send an enquiry to the seller, or hold it until the sender confirms their address.
     * Fires `pre_item_contact_post` and `post_item_contact_post`.
     *
     * @param array<string,mixed>  $item       the listing row
     * @param array<string,string> $form       yourName, yourEmail, phoneNumber, message
     * @param callable|null        $attachment stores the uploaded file and returns it; called
     *                                         only once the checks before it pass
     *
     * @return bool true when sent, false when held
     * @throws InvalidException|ConflictException|BlockedException
     */
    public function contactSeller(array $item, array $form, ?callable $attachment = null): bool
    {
        $form = self::fields($form, array('yourName', 'yourEmail', 'phoneNumber', 'message'));
        $this->guard(MessageGuard::refusal($form['yourEmail'], $form['message'], array($form['yourName']), $form['phoneNumber']));
        if (osc_isExpired($item['dt_expiration'] ?? '')) {
            throw new ConflictException(_m("We're sorry, but the listing has expired. You can't contact the seller"));
        }
        // Contact only reaches the listing's own seller, so its default limit is looser.
        $this->throttle('item_contact', _m("You've sent too many messages recently. Please try again later."));
        $this->guard(MessageHold::attachmentError($form['yourEmail'], $attachment === null ? null : $attachment()));

        osc_run_hook('pre_item_contact_post', $item);
        try {
            $errors = array();
            if (!osc_validate_text($form['yourName'])) {
                $errors[] = self::error('/yourName', _m('Your name: this field is required'));
            }
            if (!osc_validate_email($form['yourEmail'])) {
                $errors[] = self::error('/yourEmail', _m('Invalid email address'));
            }
            if (!osc_validate_text($form['message'])) {
                $errors[] = self::error('/message', _m('Message: this field is required'));
            }
            if ($errors !== array()) {
                throw InvalidException::all($errors);
            }

            $sent = MessageHold::deliver('item_contact', $form['yourEmail'], array(
                'id'          => (int) $item['pk_i_id'],
                'yourEmail'   => $form['yourEmail'],
                'yourName'    => $form['yourName'],
                'phoneNumber' => $form['phoneNumber'],
                'message'     => $form['message'],
            ));
        } finally {
            osc_run_hook('post_item_contact_post', $item);
        }
        ActionThrottle::record('item_contact');

        return $sent;
    }

    /**
     * Send the listing to a friend, or hold it until the sender confirms their address.
     * Fires `pre_item_send_friend_post` and `post_item_send_friend_post`.
     *
     * @param array<string,mixed>  $item the listing row
     * @param array<string,string> $form yourName, yourEmail, friendName, friendEmail, message
     *
     * @return bool true when sent, false when held
     * @throws InvalidException|BlockedException
     */
    public function shareWithFriend(array $item, array $form): bool
    {
        $form = self::fields($form, array('yourName', 'yourEmail', 'friendName', 'friendEmail', 'message'));
        $this->guard(MessageGuard::refusal($form['yourEmail'], $form['message'], array($form['yourName'], $form['friendName'])));
        // The form relays site-branded mail to any address, so it needs a limit for everyone.
        $this->throttle('send_friend', _m("You've shared too many listings recently. Please try again later."));

        osc_run_hook('pre_item_send_friend_post', $item);
        try {
            // Checked before sending: a bad address makes PHPMailer throw.
            $errors = array();
            if (!osc_validate_text($form['yourName'])) {
                $errors[] = self::error('/yourName', _m('Your name: this field is required'));
            }
            if (!osc_validate_email($form['yourEmail'])) {
                $errors[] = self::error('/yourEmail', _m('Your email: invalid email address'));
            }
            if (!osc_validate_text($form['friendName'])) {
                $errors[] = self::error('/friendName', _m("Your friend's name: this field is required"));
            }
            if (!osc_validate_email($form['friendEmail'])) {
                $errors[] = self::error('/friendEmail', _m("Your friend's email: invalid email address"));
            }
            if ($errors !== array()) {
                throw InvalidException::all($errors);
            }

            $sent = MessageHold::deliver('send_friend', $form['yourEmail'], array(
                'id'          => (int) $item['pk_i_id'],
                'yourName'    => $form['yourName'],
                'yourEmail'   => $form['yourEmail'],
                'friendName'  => $form['friendName'],
                'friendEmail' => $form['friendEmail'],
                'message'     => $form['message'],
            ));
        } finally {
            osc_run_hook('post_item_send_friend_post', $item);
        }
        ActionThrottle::record('send_friend');

        return $sent;
    }

    /**
     * All the validation messages of a refusal, one per line, as the old form methods returned them.
     */
    public static function messages(InvalidException $e): string
    {
        return implode(PHP_EOL, array_column($e->errors(), 'message')) . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $form
     * @param string[]            $names
     *
     * @return array<string,string>
     */
    private static function fields(array $form, array $names): array
    {
        $out = array();
        foreach ($names as $name) {
            $out[$name] = is_scalar($form[$name] ?? null) ? (string) $form[$name] : '';
        }

        return $out;
    }

    /**
     * @return array{pointer:string,code:string,message:string}
     */
    private static function error(string $pointer, string $message): array
    {
        return array('pointer' => $pointer, 'code' => 'invalid', 'message' => $message);
    }

    private function guard(?string $refusal): void
    {
        if ($refusal !== null) {
            throw new InvalidException('', 'refused', $refusal);
        }
    }

    private function throttle(string $context, string $message): void
    {
        if (ActionThrottle::exceededFor($context)) {
            throw BlockedException::rateLimit($message, 3600);
        }
    }
}
