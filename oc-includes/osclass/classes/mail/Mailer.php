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

declare(strict_types=1);

namespace mindstellar\mail;

use EmailVariables;
use Params;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\POP3;
use WebThemes;

/**
 * Sends one e-mail through PHPMailer and builds its layout and plain-text copy. utils.php keeps
 * the old osc_sendMail() and osc_mail_* names.
 */
final class Mailer
{
    /**
     * Seconds PHPMailer may wait on one SMTP connect or command.
     *
     * PHPMailer's default is 300. A dead or firewalled host then holds a php-fpm
     * worker for five minutes per send (or per "Send a test email" click). Fifteen
     * seconds is long enough for a slow provider and short enough that a bad host
     * fails the page instead of pinning the pool. Plugins may change it with the
     * `phpmailer_smtp_timeout` filter; the value is clamped to 1–60.
     *
     * Pure aside from that filter. Safe to call from characterization tests.
     *
     * @return int
     */
    public static function smtpTimeoutSeconds()
    {
        $seconds = (int)osc_apply_filter('phpmailer_smtp_timeout', 15);
        if ($seconds < 1) {
            return 1;
        }
        if ($seconds > 60) {
            return 60;
        }

        return $seconds;
    }

    /**
     * Apply {@see self::smtpTimeoutSeconds()} to a PHPMailer instance
     * and to the SMTP object it will use on send().
     *
     * @param PHPMailer $mail
     *
     * @return void
     */
    public static function limitSmtpWait($mail)
    {
        if (!is_object($mail)) {
            return;
        }
        $seconds        = self::smtpTimeoutSeconds();
        $mail->Timeout  = $seconds;
        if (method_exists($mail, 'getSMTPInstance')) {
            $smtpInst = $mail->getSMTPInstance();
            if (is_object($smtpInst)) {
                $smtpInst->Timeout = $seconds;
                if (property_exists($smtpInst, 'Timelimit')) {
                    $smtpInst->Timelimit = $seconds;
                }
            }
        }
    }

    /**
     * A visitor's uploaded file, ready to attach to a mail straight from PHP's temporary upload.
     *
     * The file is never copied under the web root. Only pictures, PDF, text and office documents
     * up to the size set in Settings → General are taken.
     *
     * @param string $field upload field name
     *
     * @return array{path:string,name:string}|false|null null when nothing was uploaded, false when refused
     */
    public static function uploadAttachment($field)
    {
        $file = Params::getFiles($field);
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])) {
            return false;
        }
        // Direction marks are dropped too: they can make a name show a different extension.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/', '', basename((string) ($file['name'] ?? '')));
        $name = trim((string) preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name));
        if (!\mindstellar\storage\UploadMimes::isAllowedAttachment($file['tmp_name'], $name)) {
            return false;
        }

        return array('path' => $file['tmp_name'], 'name' => $name !== '' ? $name : 'attachment');
    }

    /**
     * Send one email through PHPMailer, using the site's configured mail transport.
     *
     * @param array<string,mixed> $params from, to, to_name, subject, body, alt_body and optional attachment/reply-to keys;
     *                                    secret_link => true marks a mail whose link carries a secret
     *
     * @return bool False on a demo install or when the send fails
     */
    public static function send($params)
    {
        // DO NOT send mail if it's a demo
        if (\mindstellar\security\Demo::active()) {
            return false;
        }
        // Held while a database write is open; sent once it commits.
        if (\mindstellar\utility\DeferredMail::hold($params)) {
            return true;
        }

        $mail = new PHPMailer(true);
        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();
        $mail->clearCustomHeaders();
        $mail->clearReplyTos();

        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = osc_apply_filter('init_send_mail', $mail, $params);

        // A key in $params overrides the stored mail server setting.
        $server = array();
        foreach (array(
            'host'     => 'osc_mailserver_host',
            'port'     => 'osc_mailserver_port',
            'username' => 'osc_mailserver_username',
            'password' => 'osc_mailserver_password',
            'ssl'      => 'osc_mailserver_ssl',
        ) as $key => $getter) {
            $server[$key] = array_key_exists($key, $params) ? $params[$key] : $getter();
        }

        if (osc_mailserver_pop()) {
            $pop = new POP3();
            $pop->authorise($server['host'], $server['port'], self::smtpTimeoutSeconds(), $server['username'], $server['password']);
        }

        if (osc_mailserver_auth()) {
            $mail->isSMTP();
            $mail->SMTPAuth = true;
        } elseif (osc_mailserver_pop()) {
            $mail->isSMTP();
        }

        foreach (array('ssl' => 'SMTPSecure', 'username' => 'Username', 'password' => 'Password', 'host' => 'Host', 'port' => 'Port') as $key => $property) {
            if ($server[$key] != '') {
                $mail->$property = $server[$key];
            }
        }

        $from = osc_mailserver_mail_from();
        if (empty($from)) {
            $from = 'osclass@' . osc_get_domain();
            if (array_key_exists('from', $params)) {
                $from = $params['from'];
            }
        }

        $from_name = osc_mailserver_name_from();
        if (empty($from_name)) {
            $from_name = osc_page_title();
            if (array_key_exists('from_name', $params)) {
                $from_name = $params['from_name'];
            }
        }

        $mail->From     = osc_apply_filter('mail_from', $from, $params);
        $mail->FromName = osc_apply_filter('mail_from_name', $from_name, $params);

        // With exceptions enabled (new PHPMailer(true)) a malformed recipient throws from
        // addAddress()/addBCC()/addReplyTo() before send() is reached, and send() itself
        // throws on a transport failure. Guard the whole dispatch so a bad address or a
        // dead mailserver degrades to a logged warning and a false return, never a 500.
        try {
            $to      = $params['to'];
            $to_name = '';
            if (array_key_exists('to_name', $params)) {
                $to_name = $params['to_name'];
            }

            if (!is_array($to)) {
                $to = array($to => $to_name);
            }

            foreach ($to as $to_email => $to_name) {
                $mail->addAddress($to_email, $to_name);
            }

            if (array_key_exists('add_bcc', $params)) {
                if (!is_array($params['add_bcc']) && $params['add_bcc'] != '') {
                    $params['add_bcc'] = array($params['add_bcc']);
                }

                foreach ($params['add_bcc'] as $bcc) {
                    $mail->addBCC($bcc);
                }
            }

            if (array_key_exists('reply_to', $params)) {
                $mail->addReplyTo($params['reply_to']);
            }

            $mail->Subject = $params['subject'];
            $mail->Body    = self::layout((string) $params['body'], $params);

            if (array_key_exists('attachment', $params)) {
                if (!is_array($params['attachment']) || isset($params['attachment']['path'])) {
                    $params['attachment'] = array($params['attachment']);
                }

                foreach ($params['attachment'] as $attachment) {
                    if (is_array($attachment)) {
                        if (isset($attachment['path']) && isset($attachment['name'])) {
                            try {
                                $mail->addAttachment($attachment['path'], $attachment['name']);
                            } catch (\PHPMailer\PHPMailer\Exception $e) {
                                continue;
                            }
                        }
                    } else {
                        try {
                            $mail->addAttachment($attachment);
                        } catch (\PHPMailer\PHPMailer\Exception $e) {
                            continue;
                        }
                    }
                }
            }

            $mail->CharSet = 'utf-8';
            $mail->isHTML();
            // Set ahead of pre_send_mail, so a plugin can still replace it. One a plugin
            // already set in init_send_mail is kept, as it always was.
            if ($mail->AltBody === '') {
                $mail->AltBody = self::altBody($params);
            }

            $mail = osc_apply_filter('pre_send_mail', $mail, $params);
            self::limitSmtpWait($mail);

            if (!self::linksTrusted($mail, (array) $params)) {
                return false;
            }

            // send email!
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            trigger_error($e->errorMessage(), E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Send a short notice to the site's contact address, one escaped paragraph per line.
     * Never throws once called: a failure is logged and returns false.
     *
     * @param array<int,mixed> $lines plain text; anything that is not a string or number is left out
     */
    public static function admin(string $subject, array $lines): bool
    {
        try {
            $to = (string) osc_contact_email();
            if ($to === '') {
                return false;
            }
            $body = '';
            foreach ($lines as $line) {
                if (is_scalar($line) || $line instanceof \Stringable) {
                    $body .= '<p>' . osc_esc_html((string) $line) . '</p>';
                }
            }

            return (bool) self::send([
                'from'    => CoreEmails::fromAddress(),
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
            ]);
        } catch (\Throwable $e) {
            error_log('Mailer::admin: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Keep mail links off an address taken from the request Host header. With OSC_CLI_URL set,
     * links to the request address point at it instead; without it, a mail that carries a secret
     * link (params 'secret_link') is refused.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $mail
     * @param array<string,mixed>            $params osc_sendMail() parameters
     *
     * @return bool False when the mail must not be sent
     */
    public static function linksTrusted($mail, array $params = array()): bool
    {
        if (!defined('OSC_WEB_PATH_FROM_REQUEST') || !OSC_WEB_PATH_FROM_REQUEST || !defined('WEB_PATH')) {
            return true;
        }
        if (defined('OSC_TRUSTED_WEB_PATH')) {
            $mail->Body    = str_replace((string) WEB_PATH, OSC_TRUSTED_WEB_PATH, (string) $mail->Body);
            $mail->AltBody = str_replace((string) WEB_PATH, OSC_TRUSTED_WEB_PATH, (string) $mail->AltBody);

            return true;
        }
        if (empty($params['secret_link'])) {
            return true;
        }
        if (!self::secretLinksBlocked()) {
            return true;
        }
        trigger_error(
            'E-mail not sent: it carries a secret link, and the site address comes from the request Host header. Set WEB_PATH or OSC_CLI_URL to the site address.',
            E_USER_WARNING
        );

        return false;
    }

    /**
     * Whether mails with a secret link are refused: the site address comes from the request Host
     * header and OSC_CLI_URL gives no trusted one.
     *
     * @return bool
     */
    public static function secretLinksBlocked(): bool
    {
        return defined('OSC_WEB_PATH_FROM_REQUEST') && OSC_WEB_PATH_FROM_REQUEST && defined('WEB_PATH')
            && !defined('OSC_TRUSTED_WEB_PATH');
    }

    /**
     * Expand the {PLACEHOLDER} tokens in an email body: the caller's pairs, then the
     * site-wide ones. Case does not matter, and a caller key wins over a site token of
     * the same name.
     *
     * One pass: a replacement is never searched again, so a token typed into a value
     * (a contact message, a name) stays as typed.
     *
     * @param string                             $text
     * @param array{0:array<int,string>,1:array<int,string>} $params Search list and its replacement list
     *
     * @return string
     */
    public static function beauty($text, $params)
    {
        // In the order of EmailVariables::COMMON, which the template editor lists.
        $kwords = EmailVariables::COMMON;
        $rwords = array(
            osc_base_url(),
            osc_page_title(),
            '<a href="' . osc_base_url() . '">' . osc_page_title() . '</a>',
            date(osc_date_format() ?: 'Y-m-d') . ' ' . date(osc_time_format() ?: 'H:i:s'),
            date(osc_time_format() ?: 'H:i'),
            Params::getServerParam('REMOTE_ADDR')
        );

        // A key without a value is removed, as str_ireplace() did.
        $keys    = array_values((array) $params[0]);
        $values  = array_slice(array_pad(array_values((array) $params[1]), count($keys), ''), 0, count($keys));
        $search  = array_merge($keys, $kwords);
        $replace = array_merge($values, $rwords);
        $map     = array();
        $pattern = array();
        foreach ($search as $i => $key) {
            $key = (string) $key;
            $low = strtolower($key);
            if ($key === '' || isset($map[$low])) {
                continue;
            }
            $map[$low] = (string) $replace[$i];
            $pattern[] = preg_quote($key, '/');
        }

        return (string) preg_replace_callback(
            '/' . implode('|', $pattern) . '/i',
            static function (array $m) use ($map): string {
                return $map[strtolower($m[0])] ?? $m[0];
            },
            (string) $text
        );
    }

    /**
     * A plain-text copy of an HTML mail body.
     *
     * Link addresses are kept -- "unsubscribe (https://...)" -- because a plain-text
     * reader has no other way to follow them. Paragraphs, line breaks and list items keep
     * their shape; everything else a browser would not show is dropped. Tolerant of the
     * loose markup stored mail templates carry.
     *
     * @param string $html
     *
     * @return string '' when the body has no text in it
     */
    public static function plainText($html)
    {
        $html = (string)$html;
        if ($html === '') {
            return '';
        }

        // ASCII whitespace only, and no /u: an ASCII byte never occurs inside a UTF-8
        // sequence, so this is safe on any text, including text that is not valid UTF-8.
        $text = preg_replace(
            array('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '/[ \t\r\n\f\v]+/'),
            array('', ' '),
            $html
        );

        if ($text !== null) {
            // Quoted attribute values are skipped whole, so an href spelled inside another
            // attribute is not taken for the real one. The address cannot run past a quote
            // or ">", and a label cannot swallow the next link. A browser ends a link at
            // "</a" whatever follows it up to ">", so this does too.
            $text = preg_replace_callback(
                '#<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\shref\s*=\s*(["\'])([^"\'>]*)\1[^>]*>'
                . '((?:(?!<a\b).)*?)</a\b[^>]*>#is',
                static function ($m) {
                    $url   = trim($m[2]);
                    $label = trim(strip_tags($m[3]));
                    if ($url === '' || $url[0] === '#' || stripos($url, 'javascript:') === 0) {
                        return $label;
                    }
                    if ($label === '' || $label === $url) {
                        return $url;
                    }

                    return $label . ' (' . $url . ')';
                },
                $text
            );
        }

        if ($text !== null) {
            $text = preg_replace(
                array(
                    '#<br\s*/?>#i',
                    '#<li\b[^>]*>#i',
                    '#</\s*li\s*>#i',
                    '#</\s*(p|div|h[1-6]|tr|table|blockquote|ul|ol)\s*>#i',
                ),
                array("\n", "\n- ", '', "\n\n"),
                $text
            );
        }

        // A pattern gave up -- PCRE's backtracking limit on pathological markup. A plainer
        // copy without link addresses still beats none.
        if ($text === null) {
            $text = $html;
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        $lines = array();
        foreach (explode("\n", $text) as $line) {
            $lines[] = trim((string) preg_replace('/[ \t]+/', ' ', $line));
        }

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * The plain copy a mail is sent with.
     *
     * With no alt_body, or HTML in it, the copy is made from the HTML. A plain copy
     * written by hand is sent as written.
     *
     * @param array<string,mixed> $params osc_sendMail() parameters
     *
     * @return string
     */
    public static function altBody(array $params)
    {
        $body = (string)($params['body'] ?? '');
        $alt  = (string)($params['alt_body'] ?? '');
        if ($alt !== '' && $alt !== $body && strip_tags($alt) === $alt) {
            return $alt;
        }

        return self::plainText($alt !== '' && $alt !== $body ? $alt : $body);
    }

    /**
     * Wrap an e-mail body in the site's e-mail layout: a theme's templates/email-layout.php,
     * else core's. A body that is already a whole HTML document, or a send with
     * 'layout' => false, goes out as it is.
     *
     * @param string              $body
     * @param array<string,mixed> $params the osc_sendMail() parameters
     *
     * @return string
     */
    public static function layout(string $body, array $params = array()): string
    {
        if (trim($body) === '' || ($params['layout'] ?? true) === false || stripos($body, '<html') !== false) {
            return $body;
        }

        $mail = (array) osc_apply_filter('mail_layout_vars', array(
            'body'      => $body,
            'subject'   => (string) ($params['subject'] ?? ''),
            'preheader' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', self::plainText($body))), 0, 120),
            'site_name' => osc_page_title(),
            'site_url'  => osc_base_url(),
            'logo_url'  => '',
            'accent'    => '#0b7269',
            'footer'    => sprintf(__('You received this e-mail from %s.'), osc_page_title()),
        ), $params);

        $file = self::layoutFile();
        ob_start();
        try {
            (static function (string $file, array $mail): void {
                include $file;
            })($file, $mail);
        } catch (\Throwable $e) {
            // A broken layout must not stop the mail: it goes out bare.
            trigger_error('E-mail layout ' . $file . ': ' . $e->getMessage(), E_USER_WARNING);
            ob_end_clean();

            return $body;
        }
        $html = (string) ob_get_clean();

        return (string) osc_apply_filter('mail_layout', $html !== '' ? $html : $body, $mail, $params);
    }

    /**
     * The e-mail layout file: templates/email-layout.php in the active theme or its parent,
     * else core's own.
     *
     * @param string[]|null $bases theme folders to look in, active first; null for the site's
     *
     * @return string
     */
    public static function layoutFile(?array $bases = null): string
    {
        if ($bases === null) {
            $bases  = array();
            $themes = WebThemes::getInstance();
            // Cron, the admin and the CLI never load the public theme; set it from the site
            // setting, so the layout and the theme URL helpers it calls both find it.
            if ((string) $themes->getCurrentTheme() === '' && \mindstellar\utility\Validate::packageName((string) osc_theme())) {
                $themes->setCurrentTheme((string) osc_theme());
            }
            $theme  = (string) $themes->getCurrentTheme();
            $active = (string) $themes->getCurrentThemePath();
            if ($active !== '') {
                $bases[] = $active;
            }
            $info = $theme === '' ? false : $themes->loadThemeInfo($theme);
            if (is_array($info) && !empty($info['template'])
                && \mindstellar\utility\Validate::packageName((string) $info['template'])
            ) {
                $bases[] = osc_themes_path() . $info['template'] . '/';
            }
        }
        foreach ($bases as $base) {
            if (is_file(rtrim($base, '/') . '/templates/email-layout.php')) {
                return rtrim($base, '/') . '/templates/email-layout.php';
            }
        }

        return LIB_PATH . 'osclass/gui/templates/email-layout.php';
    }
}
