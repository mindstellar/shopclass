<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
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
     * @return \mindstellar\Csrf
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Initalize csrf guard
     */
    public static function init()
    {
        ob_start();
        $injectCsrf = static function () {
            $data = ob_get_clean();
            $data = self::newInstance()->replaceForms($data);
            echo $data;
        };
        $functions  = Plugins::applyFilter('shutdown_functions', [$injectCsrf]);
        foreach ($functions as $f) {
            register_shutdown_function($f);
        }
    }

    /**
     * Replace form with csrf inputs added
     * @param $form_data_html
     *
     * @return string
     */
    public function replaceForms($form_data_html)
    {
        preg_match_all('/<form(.*?)>/is', $form_data_html, $matches, PREG_SET_ORDER);
        if (is_array($matches)) {
            foreach ($matches as $m) {
                if (strpos($m[1], 'nocsrf') !== false) {
                    continue;
                }
                $form_data_html = str_replace($m[0], "<form{$m[1]}>" . $this->tokenForm(), $form_data_html);
            }
        }

        return $form_data_html;
    }

    /**
     * Resolve the token for this request. Signing touches no session state, so this is called
     * lazily at the point a token is actually emitted (tokenForm/tokenUrl/replaceForms) and never
     * starts a session. All forms on a page share one token — same issue time and binding.
     */
    private function setToken()
    {
        if ($this->tokenName !== null) {
            return;
        }
        $payload          = self::TOKEN_VERSION . '|' . time() . '|' . $this->bind();
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
     * @param $str_error
     */
    private function setMessage($str_error)
    {
        if (defined('OC_ADMIN') && OC_ADMIN) {
            $this->session->_setMessage('admin', $str_error, 'error');
        } else {
            $this->session->_setMessage('pubMessages', $str_error, 'error');
        }
    }

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
     * @return string
     */
    public function getCsrfTokenName()
    {
        $this->setToken();

        return $this->tokenName;
    }

    /**
     * @return string
     */
    public function getCsrfTokenValue()
    {
        $this->setToken();

        return $this->tokenValue;
    }
}
