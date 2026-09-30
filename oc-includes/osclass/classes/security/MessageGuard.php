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
 * Guards for the public forms that send mail: the contact form, contact the seller,
 * contact a user and share a listing.
 *
 * It caps links, checks the ban list, and signs the "Report the sender" link in mail to a
 * member; only confirmed senders reach a member ({@see MessageHold}), so a report bans a real address.
 */
final class MessageGuard
{
    /** Scope of a ban rule that blocks the message forms only. */
    public const SCOPE = 'messages';

    /** A job that remembers a used report link until the link could no longer work. */
    public const USED_JOB = 'message.report_used';

    private const REPORT_TTL = 30 * 86400;

    private const DEFAULT_MAX_LINKS = 1;
    private const DEFAULT_BAN_DAYS  = 30;

    /** Common top-level domains: a bare name ending in one reads as a link in most mail apps. */
    private const TLDS = 'com|net|org|info|biz|io|co|me|xyz|top|site|online|shop|store|app|dev|live|link|click|'
        . 'pro|club|vip|win|icu|buzz|ru|cn|in|uk|de|fr|it|es|nl|pl|br|au|ca|us|eu|tv|cc|ly|tk|ml|ga|cf|gq|ws|su|to|gg';

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
    private static function reportEnabled(): bool
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
     * Links in a text: anything with a scheme or starting www., a bare domain followed by a
     * path or query, and a bare domain on a common top-level domain. E-mail addresses are
     * not links, and look-alike dots are read as dots.
     *
     * @param string $text
     *
     * @return int
     */
    public static function countLinks(string $text): int
    {
        $text = str_replace(array('．', '。', '｡', '[.]', '(.)', '[dot]', '(dot)'), '.', $text);
        $text = (string) preg_replace('/[^\s@<>"\']+@[^\s@<>"\']+/u', ' ', $text);
        $label = '[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?';
        $n     = preg_match_all(
            '~(?:\b[a-z][a-z0-9+.-]*://|\bwww\.)[^\s<>"\']+'
            . '|' . $label . '(?:\.' . $label . ')*\.\p{L}{2,24}[/?#][^\s<>"\']*'
            . '|' . $label . '(?:\.' . $label . ')*\.(?:' . self::TLDS . ')(?![\p{L}\p{N}-])~iu',
            $text
        );

        return $n === false ? 0 : $n;
    }

    /**
     * The error to show when the message carries more links than allowed, or null.
     *
     * @param string $message
     *
     * @return string|null
     */
    public static function linkError(string $message): ?string
    {
        $max   = self::maxLinks();
        $count = self::countLinks($message);
        if ($count <= $max) {
            return null;
        }
        if ($max === 0) {
            return _m('Links are not allowed in messages.');
        }

        return sprintf(_mn('A message can contain %d link.', 'A message can contain up to %d links.', $max), $max);
    }

    /**
     * Why a message form must refuse this sender, or null: the ban list, then the name and
     * phone fields, then the links in the message.
     *
     * @param string   $email
     * @param string   $message
     * @param string[] $names
     * @param string   $phone
     *
     * @return string|null
     */
    public static function refusal(string $email, string $message, array $names = array(), string $phone = ''): ?string
    {
        return self::banError($email) ?? self::fieldError($names, $phone) ?? self::linkError($message);
    }

    /**
     * The error to show for a name or phone number that is not one, or null. A name has no
     * link and no markup; a phone number is digits, spaces, + ( ) - . and an extension.
     *
     * @param string[] $names every name field on the form
     * @param string   $phone
     *
     * @return string|null
     */
    public static function fieldError(array $names, string $phone = ''): ?string
    {
        foreach ($names as $name) {
            if (mb_strlen($name) > 100 || strpbrk($name, '<>') !== false || self::countLinks($name) > 0) {
                return _m('Please enter a real name, without links.');
            }
        }
        $phone = trim($phone);
        if ($phone !== '' && (mb_strlen($phone) > 30
            || !preg_match('/^\+?[\p{Nd}\s().\-]+(?:\s*(?:ext\.?|x)\s*\p{Nd}+)?$/iu', $phone))
        ) {
            return _m('Please enter a valid phone number.');
        }

        return null;
    }

    /**
     * Whether the sender may use the message forms: the ban list, including the
     * message-only rules, for their address, their account's address and their IP.
     *
     * @param string $email the address typed into the form
     *
     * @return int 0 allowed, 1 address banned, 2 IP banned
     */
    private static function banned(string $email): int
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
     * The report link for mail from $sender to $recipient. Each link carries its own id, so
     * it can be used once. A permanent link, for mail to the site owner, bans for good.
     *
     * @param string $sender
     * @param string $recipient
     * @param bool   $permanent
     *
     * @return string
     */
    public static function reportUrl(string $sender, string $recipient, bool $permanent = false): string
    {
        $data = array('s' => $sender, 'r' => $recipient, 'n' => bin2hex(random_bytes(16)));
        if ($permanent) {
            $data['p'] = 1;
        }

        return osc_base_url(true) . '?page=contact&action=report&t='
            . rawurlencode(SignedPayload::pack('report-sender', $data, self::REPORT_TTL));
    }

    /**
     * The "Report the sender" footer for a mail. Mail to a member carries it when the setting
     * is on; mail to the site owner always does, and bans permanently.
     *
     * @param string $sender
     * @param string $recipient
     * @param bool   $permanent
     *
     * @return string HTML, or ''
     */
    public static function reportFooter(string $sender, string $recipient, bool $permanent = false): string
    {
        if ((!$permanent && !self::reportEnabled()) || $sender === '' || $recipient === '') {
            return '';
        }
        $text = $permanent
            ? _m('Spam? Report the sender to ban this address from the site for good.')
            : sprintf(
                _m('Unwanted message? Report the sender and they cannot send messages on %s for %d days.'),
                osc_page_title(),
                self::banDays()
            );

        return '<p style="margin-top:24px;font-size:12px;color:#666">' . osc_esc_html($text)
            . ' <a href="' . osc_esc_html(self::reportUrl($sender, $recipient, $permanent)) . '">'
            . osc_esc_html(_m('Report the sender')) . '</a></p>';
    }

    /**
     * Read a report token.
     *
     * @param string $token
     *
     * @return array{sender:string,recipient:string,nonce:string,permanent:bool}|null null when forged, damaged or too old
     */
    public static function readReport(string $token): ?array
    {
        $data = SignedPayload::unpack('report-sender', $token);
        if ($data === null || !isset($data['s'], $data['r'], $data['n'])
            || !is_string($data['s']) || !is_string($data['r']) || !is_string($data['n'])
        ) {
            return null;
        }

        return array(
            'sender'    => $data['s'],
            'recipient' => $data['r'],
            'nonce'     => $data['n'],
            'permanent' => !empty($data['p']),
        );
    }

    /**
     * File a report: ban the sender, once per link.
     *
     * @param string $token
     *
     * @return string 'done', 'used', 'invalid', 'admin' (a permanent ban needs a signed-in admin) or 'failed'
     */
    public static function report(string $token): string
    {
        $report = self::readReport($token);
        if ($report === null) {
            return 'invalid';
        }
        if ($report['permanent'] && !osc_is_admin_user_logged_in()) {
            return 'admin';
        }
        $queue = JobQueue::instance();
        if ($queue->hasKey(self::USED_JOB, $report['nonce'])) {
            return 'used';
        }
        if (!self::banSender($report['sender'], $report['recipient'], $report['permanent'])) {
            return 'failed';
        }
        try {
            // Kept until the link has expired, when the queue drops it.
            $queue->enqueue(self::USED_JOB, array(), array('delay' => self::REPORT_TTL, 'unique_key' => $report['nonce']));
        } catch (\InvalidArgumentException $e) {
            // The nonce is always a hex string; nothing to recover here.
        }

        return 'done';
    }

    /**
     * Ban $sender: from messages for banDays(), or from the whole site for good. An active
     * ban of the same kind is extended rather than repeated.
     *
     * @param string $sender
     * @param string $recipient who reported it, kept in the rule's name for the admin
     * @param bool   $permanent
     *
     * @return bool
     */
    public static function banSender(string $sender, string $recipient, bool $permanent = false): bool
    {
        $table   = DB_TABLE_PREFIX . 't_ban_rule';
        $scope   = $permanent ? 'all' : self::SCOPE;
        $expires = $permanent ? null : date('Y-m-d H:i:s', strtotime('+' . self::banDays() . ' days'));
        $pattern = self::literalPattern($sender);

        try {
            $existing = osc_db_select_one(
                'SELECT pk_i_id FROM ' . $table . ' WHERE s_email = ? AND s_scope = ?'
                . ' AND (dt_expires IS NULL OR dt_expires > ?)',
                array($pattern, $scope, date('Y-m-d H:i:s'))
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
                    $scope,
                    $expires,
                )
            );
        } catch (\mindstellar\database\DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * An address as a ban-list pattern that matches only itself: *, | and a leading ! are
     * dropped, and regex characters are escaped with the ban list's own escape, |.
     *
     * @param string $email
     *
     * @return string
     */
    public static function literalPattern(string $email): string
    {
        $email = ltrim(str_replace(array('*', '|'), '', strtolower(trim($email))), '!');

        return (string) preg_replace('/([+?^$(){}\[\]\\\\])/', '|$1', $email);
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
}
