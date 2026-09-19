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

namespace mindstellar;

use mindstellar\utility\Utils;
use Params;
use Plugins;
use Session;

/**
 * Class Csrf
 *
 * Stateless, HMAC-signed CSRF tokens. A token is a base64url payload
 * (version, issue timestamp, identity binding) plus its HMAC-SHA256 signature over that
 * payload. Validation recomputes the signature and checks the timestamp and binding — no
 * per-token state is kept server-side, so issuing a token reads and writes no session and a
 * page carrying one stays cookieless and cacheable.
 *
 * @package mindstellar\osclass\classes
 */
class Csrf
{
    /**
     * How long a token stays valid, in seconds (2 hours). A token older than this is rejected
     * as expired. Kept well above any request-cache TTL so a token embedded in a briefly-cached
     * page is never stale by the time a visitor submits.
     */
    private const TOKEN_LIFETIME = 7200;

    /**
     * Tolerance for a token dated slightly in the future, in seconds, to absorb clock drift
     * between front-ends behind a load balancer.
     */
    private const CLOCK_SKEW = 300;

    /**
     * Payload layout version. Bump if the payload format changes so old tokens fail cleanly
     * instead of being misparsed.
     */
    private const TOKEN_VERSION = '1';

    /**
     * Granularity of the issue time stamped into a token.
     *
     * A token minted per second makes every render of a page different, which is the only
     * thing that does -- pages are otherwise byte-identical -- so it defeats any validator
     * computed from the response body and any cache that wants to compare two renders.
     * Rounding down to a bucket makes a page stable for the length of the bucket while
     * changing nothing about how long a token is accepted: TOKEN_LIFETIME is still measured
     * from the stamped time, so a token issued at the end of a bucket simply has one bucket
     * less of its life left. Issue granularity was never the protection -- these tokens
     * are not one-time, and an anonymous one is already valid for every anonymous visitor.
     */
    private const ISSUE_BUCKET = 1800;

    private static $instance;

    /**
     * Encoded payload for the token issued this request (the CSRFName value).
     * @var string
     */
    private $tokenName;

    /**
     * Signature for the token issued this request (the CSRFToken value).
     * @var string
     */
    private $tokenValue;

    /**
     * @var \Session
     */
    private $session;

    /**
     * Csrf constructor.
     */
    public function __construct()
    {
        $this->session = Session::newInstance();
    }

    /**
     * The shared Csrf instance, created on first call.
     *
     * @return \mindstellar\Csrf
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initalize csrf guard
     *
     * @return void
     */
    public static function init()
    {
        ob_start();
        $injectCsrf = static function () {
            $data = self::newInstance()->injectTokens(ob_get_clean(), headers_list());
            // The one moment the finished page exists as a string: after the tokens are
            // in, before anything reaches the client. Anything that needs the whole body
            // -- a validator to answer conditional requests with, a minifier, a late
            // replacement -- belongs here rather than starting a second output buffer and
            // racing this one for it. A filter that returns '' sends no body, which is
            // what a 304 needs.
            $data = osc_apply_filter('response_body', $data);
            echo $data;
        };
        $functions  = Plugins::applyFilter('shutdown_functions', [$injectCsrf]);
        foreach ($functions as $f) {
            register_shutdown_function($f);
        }
    }

    /**
     * Add tokens to the forms of an HTML response; any other body is returned untouched.
     *
     * @param string|false $body    The buffered response
     * @param string[]     $headers As returned by headers_list()
     *
     * @return string|false
     */
    public function injectTokens($body, array $headers)
    {
        return self::isHtmlResponse($body, $headers) ? $this->replaceForms($body) : $body;
    }

    /**
     * Whether a response with these headers is HTML.
     *
     * No Content-Type means PHP's text/html default, unless the body is valid JSON:
     * many ajax actions echo JSON without declaring it.
     *
     * @param string   $body
     * @param string[] $headers
     *
     * @return bool
     */
    private static function isHtmlResponse($body, array $headers): bool
    {
        $type = '';
        foreach ($headers as $header) {
            if (preg_match('/^content-type\s*:\s*([^;]*)/i', $header, $m)) {
                $type = strtolower(trim($m[1]));
            }
        }

        if ($type === '') {
            $start = ltrim((string) $body)[0] ?? '';
            if (($start === '{' || $start === '[') && json_decode((string) $body) !== null) {
                return false;
            }

            return true;
        }

        return $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /**
     * Replace form with csrf inputs added
     *
     * @param string $form_data_html Rendered page HTML
     *
     * @return string
     */
    public function replaceForms($form_data_html)
    {
        // One pass, so a page carrying two byte-identical opening tags stamps each of them
        // once. A str_replace loop restamped every copy on every iteration.
        $stamped = preg_replace_callback(
            '/<form(.*?)>/is',
            function ($m) {
                if (strpos($m[1], 'nocsrf') !== false) {
                    return $m[0];
                }
                // A GET form cannot need a token: CSRF protects state changes, and a
                // state change is never a GET. Stamping one anyway put the token in the
                // query string -- shared in links, kept in referrers and logs, and
                // unique per visitor, which makes every search URL its own cache entry.
                if (preg_match('/\bmethod\s*=\s*(["\']?)get\1/i', $m[1])) {
                    return $m[0];
                }

                return "<form{$m[1]}>" . $this->tokenForm();
            },
            $form_data_html
        );

        // PCRE gives up on a page that exhausts the backtrack limit. Serve it unstamped
        // rather than blank; the forms on it then fail their own token check.
        return $stamped === null ? $form_data_html : $stamped;
    }

    /**
     * Resolve the token for this request. Signing touches no session state, so this is called
     * lazily at the point a token is actually emitted (tokenForm/tokenUrl/replaceForms) and never
     * starts a session. All forms on a page share one token — same issue time and binding.
     *
     * @return void
     */
    private function setToken()
    {
        if ($this->tokenName !== null) {
            return;
        }
        $payload          = self::TOKEN_VERSION . '|' . self::issuedAt() . '|' . $this->bind();
        $this->tokenName  = self::b64urlEncode($payload);
        $this->tokenValue = self::sign($this->tokenName);
    }

    /**
     * Create hidden CSRF token input fields to be placed in a form
     *
     * @return string
     *
     */
    public function tokenForm()
    {
        $this->setToken();

        return "<input type='hidden' name='CSRFName' value='" . $this->tokenName . "' />
        <input type='hidden' name='CSRFToken' value='" . $this->tokenValue . "' />";
    }

    /**
     * Create a CSRF token to be placed in a url
     *
     * @return string
     *
     */
    public function tokenUrl()
    {
        $this->setToken();

        return 'CSRFName=' . $this->tokenName . '&CSRFToken=' . $this->tokenValue;
    }

    /**
     * Check if CSRF token is valid, die in other case
     *
     * @return void
     */
    public function check()
    {
        $csrfTokenName = Params::getParam('CSRFName');
        $csrfToken     = Params::getParam('CSRFToken');

        if (!$csrfTokenName || !$csrfToken) {
            $status = 'missing';
        } else {
            $status = $this->verify($csrfTokenName, $csrfToken);
        }

        if ($status === 'ok') {
            return;
        }

        $expired = ($status === 'expired');
        if ($status === 'missing') {
            $str_error = _m('Probable invalid request.');
        } elseif ($expired) {
            $str_error = _m('Your session has expired, please reload the page and try again.');
        } else {
            $str_error = _m('Invalid CSRF token.');
        }

        // check ajax request
        if (defined('IS_AJAX') && IS_AJAX === true) {
            echo json_encode(array(
                'error'   => 1,
                'expired' => $expired ? 1 : 0,
                'msg'     => $str_error
            ));
            exit;
        }

        $this->setMessage($str_error);
        $this->errorRedirect();
    }

    /**
     * Verify a submitted token: the signature must match, the payload must be well-formed and
     * unexpired, and its identity binding must match the current visitor.
     *
     * @param string $name  submitted CSRFName (encoded payload)
     * @param string $token submitted CSRFToken (signature)
     *
     * @return string 'ok' | 'invalid' | 'expired'
     */
    private function verify($name, $token)
    {
        if (!hash_equals(self::sign($name), (string)$token)) {
            return 'invalid';
        }

        $payload = self::b64urlDecode($name);
        if ($payload === false) {
            return 'invalid';
        }
        $parts = explode('|', $payload);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_VERSION || !ctype_digit($parts[1])) {
            return 'invalid';
        }

        $issuedAt = (int)$parts[1];
        $now      = time();
        if ($issuedAt > $now + self::CLOCK_SKEW || $now - $issuedAt > self::TOKEN_LIFETIME) {
            return 'expired';
        }

        if (!hash_equals($this->bind(), (string)$parts[2])) {
            return 'invalid';
        }

        return 'ok';
    }

    /**
     * Issue time for a token, rounded down to ISSUE_BUCKET. Always <= now, so it can never
     * trip validate()'s clock-skew guard.
     *
     * @return int
     */
    private static function issuedAt()
    {
        return (int)(floor(time() / self::ISSUE_BUCKET) * self::ISSUE_BUCKET);
    }

    /**
     * Identity the token is bound to: the logged-in admin or web user, else empty for an
     * anonymous visitor. Read-only — Session::_get resumes an existing session but never starts a
     * new one, so anonymous requests stay cacheable. Binding stops a valid token issued to one
     * logged-in account from being replayed against another.
     *
     * @return string
     */
    private function bind()
    {
        $adminId = $this->session->_get('adminId');
        if ($adminId !== '' && $adminId !== null) {
            return 'a' . $adminId;
        }
        $userId = $this->session->_get('userId');
        if ($userId !== '' && $userId !== null) {
            return 'u' . $userId;
        }

        return '';
    }

    /**
     * HMAC-SHA256 of $data under the install signing secret.
     *
     * @param string $data
     *
     * @return string
     */
    private static function sign($data)
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    /**
     * The server-side signing secret, shared with every other stateless signed token.
     *
     * @return string
     */
    private static function secret()
    {
        return \mindstellar\security\SigningKey::get();
    }

    /**
     * URL/attribute-safe base64 (RFC 4648 §5), unpadded.
     *
     * @param string $data
     *
     * @return string
     */
    private static function b64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode URL/attribute-safe base64 back to its raw payload.
     *
     * @param string $data
     *
     * @return string|false decoded payload, or false on malformed input
     */
    private static function b64urlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Flash error message
     *
     * @param string $str_error
     *
     * @return void
     */
    private function setMessage($str_error)
    {
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $this->session->_setMessage('admin', $str_error, 'error');
        } else {
            $this->session->_setMessage('pubMessages', $str_error, 'error');
        }
    }

    /**
     * Send the visitor back where they came from, or to the site/admin home, and stop.
     *
     * @return void
     */
    private function errorRedirect()
    {
        $url = Utils::getHttpReferer();
        // drop session referer
        $this->session->_dropReferer();
        if ($url) {
            Utils::redirectTo($url);
        }

        if (defined('OC_ADMIN') && OC_ADMIN) {
            Utils::redirectTo(osc_admin_base_url(true));
        } else {
            Utils::redirectTo(osc_base_url(true));
        }
    }

    /**
     * The CSRFName value for this request's token (the encoded payload).
     *
     * @return string
     */
    public function getCsrfTokenName()
    {
        $this->setToken();

        return $this->tokenName;
    }

    /**
     * The CSRFToken value for this request's token (the signature).
     *
     * @return string
     */
    public function getCsrfTokenValue()
    {
        $this->setToken();

        return $this->tokenValue;
    }
}
