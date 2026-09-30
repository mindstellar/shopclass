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

/**
 * Guards for the public forms that send mail: the contact form, contact the seller,
 * contact a user and share a listing.
 *
 * It caps the links in a message, and it signs the "Report the sender" link added to mail
 * a member receives. A report bans the sender's address from these forms for a set number
 * of days; the ban rule it writes is scoped to messages, so sign-in and posting still work.
 */
final class MessageGuard
{
    /** Scope of a ban rule that blocks the message forms only. */
    public const SCOPE = 'messages';

    /** How long a report link stays usable. */
    private const LINK_TTL = 30 * 86400;

    private const DEFAULT_MAX_LINKS = 1;
    private const DEFAULT_BAN_DAYS  = 30;

    /**
     * Links allowed in one message. 0 allows none.
     *
     * @return int
     */
    public static function maxLinks(): int
    {
        $v = osc_get_preference('message_max_links');

        return $v === '' || $v === null ? self::DEFAULT_MAX_LINKS : max(0, (int) $v);
    }

    /**
     * Whether mail to a member carries a "Report the sender" link.
     *
     * @return bool
     */
    public static function reportEnabled(): bool
    {
        return osc_get_preference('message_report_link') !== '0';
    }

    /**
     * Days a report blocks the sender's address from the message forms.
     *
     * @return int
     */
    public static function banDays(): int
    {
        $v = (int) osc_get_preference('message_report_days');

        return $v > 0 ? $v : self::DEFAULT_BAN_DAYS;
    }

    /**
     * Links in a text: anything with a scheme, anything starting www., and a bare
     * domain followed by a path. A bare name like example.com on its own is not counted,
     * so ordinary words with a dot in them do not trip the limit.
     *
     * @param string $text
     *
     * @return int
     */
    public static function countLinks(string $text): int
    {
        $n = preg_match_all(
            '~(?:\b[a-z][a-z0-9+.-]*://|\bwww\.)[^\s<>"\']+'
            . '|\b[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)*\.[a-z]{2,24}/[^\s<>"\']*~i',
            $text
        );

        return $n === false ? 0 : $n;
    }

    /**
     * The error to show when the texts together carry more links than allowed, or null.
     *
     * @param string ...$texts
     *
     * @return string|null
     */
    public static function linkError(string ...$texts): ?string
    {
        $max   = self::maxLinks();
        $count = self::countLinks(implode("\n", $texts));
        if ($count <= $max) {
            return null;
        }
        if ($max === 0) {
            return _m('Links are not allowed in messages.');
        }

        return sprintf(_mn('A message can contain %d link.', 'A message can contain up to %d links.', $max), $max);
    }

    /**
     * Whether the sender may use the message forms: the ban list, including the
     * message-only rules, for their address, their account's address and their IP.
     *
     * @param string $email the address typed into the form
     *
     * @return int 0 allowed, 1 address banned, 2 IP banned
     */
    public static function banned(string $email): int
    {
        $banned = osc_is_banned($email, null, self::SCOPE);
        if ($banned === 0 && osc_is_web_user_logged_in()) {
            $own = (string) osc_logged_user_email();
            if ($own !== '' && strcasecmp($own, $email) !== 0) {
                $banned = osc_is_banned($own, null, self::SCOPE);
            }
        }

        return $banned;
    }

    /**
     * The error to show for a banned sender, or null.
     *
     * @param string $email
     *
     * @return string|null
     */
    public static function banError(string $email): ?string
    {
        switch (self::banned($email)) {
            case 1:
                return _m('Your current email is not allowed');
            case 2:
                return _m('Your current IP is not allowed');
        }

        return null;
    }

    /**
     * The report link for mail from $sender to $recipient.
     *
     * @param string $sender
     * @param string $recipient
     *
     * @return string
     */
    public static function reportUrl(string $sender, string $recipient): string
    {
        $payload = self::b64((string) json_encode(array('s' => $sender, 'r' => $recipient, 't' => time())));

        return osc_base_url(true) . '?page=contact&action=report&t=' . rawurlencode($payload . '.' . self::sign($payload));
    }

    /**
     * The footer appended to mail a member receives, or '' when reporting is off.
     *
     * @param string $sender
     * @param string $recipient
     *
     * @return string HTML
     */
    public static function reportFooter(string $sender, string $recipient): string
    {
        if (!self::reportEnabled() || $sender === '' || $recipient === '') {
            return '';
        }

        return '<p style="margin-top:24px;font-size:12px;color:#666">'
            . osc_esc_html(sprintf(
                _m('Unwanted message? Report the sender and they cannot send messages on %s for %d days.'),
                osc_page_title(),
                self::banDays()
            ))
            . ' <a href="' . osc_esc_html(self::reportUrl($sender, $recipient)) . '">'
            . osc_esc_html(_m('Report the sender')) . '</a></p>';
    }

    /**
     * Read a report token.
     *
     * @param string $token
     *
     * @return array{sender:string,recipient:string}|null null when forged, damaged or too old
     */
    public static function readReport(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (!is_array($data) || !isset($data['s'], $data['r'], $data['t'])
            || !is_string($data['s']) || !is_string($data['r'])
            || time() - (int) $data['t'] > self::LINK_TTL
        ) {
            return null;
        }

        return array('sender' => $data['s'], 'recipient' => $data['r']);
    }

    /**
     * Ban $sender from the message forms for banDays(). An active report ban on the same
     * address is extended rather than repeated.
     *
     * @param string $sender
     * @param string $recipient who reported it, kept in the rule's name for the admin
     *
     * @return bool
     */
    public static function banSender(string $sender, string $recipient): bool
    {
        $table   = DB_TABLE_PREFIX . 't_ban_rule';
        $expires = date('Y-m-d H:i:s', strtotime('+' . self::banDays() . ' days'));
        // The ban list reads *, | and a leading ! as patterns, so the address is matched literally.
        $pattern = ltrim(str_replace(array('*', '|'), '', $sender), '!');

        try {
            $existing = osc_db_select_one(
                'SELECT pk_i_id FROM ' . $table . ' WHERE s_email = ? AND s_scope = ?'
                . ' AND dt_expires IS NOT NULL AND dt_expires > ?',
                array($pattern, self::SCOPE, date('Y-m-d H:i:s'))
            );
            if ($existing) {
                osc_db_execute(
                    'UPDATE ' . $table . ' SET dt_expires = ? WHERE pk_i_id = ?',
                    array($expires, (int) $existing['pk_i_id'])
                );

                return true;
            }
            osc_db_execute(
                'INSERT INTO ' . $table . ' (s_name, s_ip, s_email, s_scope, dt_expires) VALUES (?, ?, ?, ?, ?)',
                array(
                    mb_substr(sprintf(__('Reported by %s'), $recipient), 0, 250),
                    '',
                    mb_substr($pattern, 0, 250),
                    self::SCOPE,
                    $expires,
                )
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete report bans that have ended. Run by the daily task.
     *
     * @return void
     */
    public static function purgeExpired(): void
    {
        try {
            osc_db_execute(
                'DELETE FROM ' . DB_TABLE_PREFIX . 't_ban_rule WHERE dt_expires IS NOT NULL AND dt_expires <= ?',
                array(date('Y-m-d H:i:s'))
            );
        } catch (\mindstellar\database\DbException $e) {
            // Before the upgrade adds dt_expires there is nothing to purge.
        }
    }

    private static function sign(string $payload): string
    {
        return self::b64(hash_hmac('sha256', 'report-sender|' . $payload, SigningKey::get(), true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
