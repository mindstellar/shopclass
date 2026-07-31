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

/**
 * Class Session
 */
class Session
{
    //attributes
    private static $instance;
    private $session = array();
    private $ephemeral = array();
    private $messages = array();
    private $form = array();
    private $keepForm = array();
    private $started = false;

    /**
     * Seed the in-memory default containers so reads are safe before (or without) a
     * physical session. This touches only the in-memory copy — it never starts a session
     * or writes $_SESSION, so anonymous read-only requests stay cookieless and cacheable.
     */
    public function __construct()
    {
        $this->seedDefaults();
    }

    /**
     * @return \Session
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Physically start (or resume) a session immediately. This is the explicit, eager
     * entry point: any caller is guaranteed a live $_SESSION afterwards. Kept eager so the
     * historical contract holds for third-party themes/plugins (and core logout/install)
     * that call it and then touch $_SESSION directly.
     */
    public function session_start()
    {
        $this->ensureStarted();
    }

    /**
     * Lazily attach to a session: resume it only if the visitor already carries the cookie,
     * otherwise stay deferred so anonymous read-only requests send no Set-Cookie and no
     * no-cache headers and stay cacheable. The first write (see _set) starts one on demand.
     * The bootstrap uses this so merely loading a page never forces a session.
     */
    public function session_resume()
    {
        $this->maybeResume();
        $this->seedDefaults();
    }

    /**
     * Resume the session only when a session cookie is already present.
     */
    private function maybeResume()
    {
        if (!$this->started && isset($_COOKIE['osclass'])) {
            $this->ensureStarted();
        }
    }

    /**
     * Physically start (or resume) the PHP session and mark it active. Idempotent.
     */
    private function ensureStarted()
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        // Values written in-memory before the physical start (e.g. default containers).
        $pending = $this->session;
        $this->configureCookieParams();

        if (!isset($_SESSION)) {
            session_name('osclass');
            if (!$this->_session_start()) {
                session_id(uniqid('', true));
                session_start();
                session_regenerate_id();
            }
        }

        // Persisted state wins; re-apply anything written before the session started.
        foreach ($pending as $key => $value) {
            if (!array_key_exists($key, $_SESSION)) {
                $_SESSION[$key] = $value;
            }
        }
        $this->session = $_SESSION;
        $this->seedDefaults();
    }

    /**
     * Apply Shopclass cookie params (domain, secure under HTTPS) plus SameSite=Lax hardening.
     */
    private function configureCookieParams()
    {
        $params = session_get_cookie_params();
        if (defined('COOKIE_DOMAIN')) {
            $params['domain'] = COOKIE_DOMAIN;
        }
        if (isset($_SERVER['HTTPS'])) {
            $params['secure'] = true;
        }
        session_set_cookie_params(array(
            'lifetime' => $params['lifetime'],
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'] ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /**
     * Seed the in-memory default containers (messages/keepForm/form) without starting a
     * session or writing $_SESSION.
     */
    private function seedDefaults()
    {
        foreach (array('keepForm', 'form') as $key) {
            if (!isset($this->session[$key])) {
                $this->session[$key] = array();
            }
        }
    }

    /**
     * @return bool
     */
    public function _session_start()
    {
        $sn = session_name();
        if (isset($_COOKIE[$sn])) {
            $sessid = $_COOKIE[$sn];
        } elseif (isset($_GET[$sn])) {
            $sessid = $_GET[$sn];
        } else {
            return session_start();
        }

        if (!preg_match('/^[a-zA-Z0-9,\-]{22,40}$/', $sessid)) {
            return false;
        }

        return session_start();
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    public function _get($key)
    {
        $this->maybeResume();

        // A physical session value wins; otherwise fall back to a request-scoped
        // ephemeral value (see _setEphemeral) so cookie-authenticated identity is
        // readable through the same API without a session having been started.
        return $this->session[$key] ?? $this->ephemeral[$key] ?? '';
    }

    /**
     * @param $key
     *
     * @return bool
     * @since 4.0.0
     */
    public function _has($key)
    {
        $this->maybeResume();

        return isset($this->session[$key]) || isset($this->ephemeral[$key]);
    }
    /**
     * @param $key
     * @param $value
     */
    public function _set($key, $value)
    {
        $this->ensureStarted();
        $_SESSION[$key]      = $value;
        $this->session[$key] = $value;
    }

    /**
     * Set a request-scoped value that is readable via _get()/_has() but never persisted.
     *
     * Unlike _set(), this touches neither $_SESSION nor starts a physical session, and it
     * is deliberately excluded from the pending-write merge in ensureStarted(). It exists so
     * identity resolved from a signed cookie can be exposed through the historical
     * Session::_get('userId') API while the visitor stays session-free and cacheable.
     *
     * @param $key
     * @param $value
     */
    public function _setEphemeral($key, $value)
    {
        $this->ephemeral[$key] = $value;
    }

    /**
     * Drop a request-scoped ephemeral value (e.g. on logout).
     *
     * @param $key
     */
    public function _dropEphemeral($key)
    {
        unset($this->ephemeral[$key]);
    }

    public function session_destroy()
    {
        // Sessions are lazy now, so this can be reached with none started — e.g. the secure
        // base controllers call logout() (which lands here) for any not-logged-in visitor.
        // Destroying an uninitialised session raises a PHP warning, so guard on the status.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }

    /**
     * @param $key
     */
    public function _drop($key)
    {
        unset($_SESSION[$key], $this->session[$key]);
    }

    /**
     * @param $value
     */
    public function _setReferer($value)
    {
        $this->ensureStarted();
        $_SESSION['osc_http_referer']            = $value;
        $this->session['osc_http_referer']       = $value;
        $_SESSION['osc_http_referer_state']      = 0;
        $this->session['osc_http_referer_state'] = 0;
    }

    /**
     * @return string
     */
    public function _getReferer()
    {
        return $this->session['osc_http_referer'] ?? '';
    }

    public function _view()
    {
        print_r($this->session);
    }

    /**
     * @param $key
     * @param $value
     * @param $type
     */
    public function _setMessage($key, $value, $type)
    {
        // Flash messages live in a request-scoped store (see below), never $_SESSION, so
        // adding one does not start a session. They are carried across the following
        // redirect in a short-lived, HMAC-signed cookie by _flushFlashMessages().
        $this->messages[$key][] = array('msg' => str_replace(PHP_EOL, '<br />', $value), 'type' => $type);
    }

    /**
     * @param $key
     *
     * @return string|array
     */
    public function _getMessage($key)
    {
        return $this->messages[$key] ?? '';
    }

    /**
     * @param $key
     */
    public function _dropMessage($key)
    {
        unset($this->messages[$key]);
    }

    /**
     * Load flash messages left by the previous request from the signed cookie into the
     * request-scoped store, then clear the cookie (single-use). Call once, early in the
     * bootstrap — before any output — so clearing the cookie can still emit a header and a
     * mere GET that only *reads* flash messages never starts a session.
     *
     * @return void
     */
    public function _loadFlashMessages()
    {
        $value = $_COOKIE['oc_flash'] ?? '';
        if ($value === '') {
            return;
        }
        $this->writeSignedStore('oc_flash', '', time() - 3600);
        unset($_COOKIE['oc_flash']);

        $messages = $this->decodeSignedStore($value);
        if (!empty($messages)) {
            $this->messages = $messages;
        }
    }

    /**
     * Persist any pending flash messages into the signed cookie so the next request can show
     * them. Called right before a redirect (the moment a flash needs to survive), while
     * headers can still be sent. Messages already rendered — and dropped — this request are
     * simply not present, so they are not carried over.
     *
     * @return void
     */
    public function _flushFlashMessages()
    {
        if (empty($this->messages)) {
            return;
        }
        $this->writeSignedStore('oc_flash', $this->encodeSignedStore($this->messages), time() + 300);
    }

    /**
     * Serialise + HMAC-sign a value for a standalone cookie store (flash, form repop). The
     * signature is what lets these be client-side cookies safely: a tampered value verifies
     * to nothing, so a visitor cannot forge flash HTML or form input.
     *
     * @param mixed $data
     *
     * @return string
     */
    private function encodeSignedStore($data)
    {
        $payload = base64_encode(json_encode($data));

        return $payload . '.' . hash_hmac('sha256', $payload, \mindstellar\security\SigningKey::get());
    }

    /**
     * Verify and decode a signed cookie-store value. Returns an empty array unless the
     * signature is valid.
     *
     * @param string $value
     *
     * @return array
     */
    private function decodeSignedStore($value)
    {
        if (!is_string($value) || strpos($value, '.') === false) {
            return array();
        }
        $dot     = strrpos($value, '.');
        $payload = substr($value, 0, $dot);
        $sig     = substr($value, $dot + 1);
        if (!hash_equals(hash_hmac('sha256', $payload, \mindstellar\security\SigningKey::get()), $sig)) {
            return array();
        }
        $json = base64_decode($payload, true);
        if ($json === false) {
            return array();
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Write (or, with a past expiry, delete) a standalone signed-store cookie. Standalone —
     * not the session container — so it never starts a session.
     *
     * @param string $name
     * @param string $value
     * @param int    $expiry
     *
     * @return void
     */
    private function writeSignedStore($name, $value, $expiry)
    {
        if (headers_sent()) {
            return;
        }
        $options = array(
            'expires'  => $expiry,
            'path'     => defined('REL_WEB_URL') ? REL_WEB_URL : '/',
            'httponly' => true,
            'samesite' => 'Lax',
        );
        if (function_exists('osc_is_ssl') && osc_is_ssl()) {
            $options['secure'] = true;
        }
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
            $options['domain'] = COOKIE_DOMAIN;
        }
        setcookie($name, $value, $options);
    }

    /**
     * @param $key
     */
    public function _keepForm($key)
    {
        $this->keepForm[$key] = 1;
    }

    /**
     * @param string $key
     */
    public function _dropKeepForm($key = '')
    {
        if ($key) {
            unset($this->keepForm[$key]);
        } else {
            $this->keepForm = array();
        }
    }

    /**
     * Stash a submitted form value so a form can be refilled after a validation error. Held in
     * a request-scoped store (never $_SESSION, so it starts no session) and carried across the
     * redirect back to the form in a signed cookie by _flushFormData().
     *
     * @param $key
     * @param $value
     */
    public function _setForm($key, $value)
    {
        $this->form[$key] = $value;
    }

    /**
     * @param string $key
     *
     * @return string|array
     */
    public function _getForm($key = '')
    {
        if ($key) {
            return $this->form[$key] ?? '';
        }

        return $this->form;
    }

    /**
     * @return string|array
     */
    public function _getKeepForm()
    {
        return $this->keepForm;
    }

    public function _viewMessage()
    {
        print_r($this->messages);
    }

    public function _viewForm()
    {
        print_r($this->form);
    }

    public function _viewKeep()
    {
        print_r($this->keepForm);
    }

    public function _clearVariables()
    {
        foreach ($this->form as $key => $value) {
            if (!isset($this->keepForm[$key])) {
                unset($this->form[$key]);
            }
        }
    }

    /**
     * Load form-repopulation data left by the previous request from its signed cookie into the
     * request-scoped store. Called once, early in the bootstrap. When nothing is marked to
     * keep the data is single-use (consume and clear the cookie); when some keys are kept
     * (a multi-step post that detours through, e.g., the login page) the cookie is re-written
     * so it survives an intermediate render-only request that never redirects.
     *
     * @return void
     */
    public function _loadFormData()
    {
        $value = $_COOKIE['oc_form'] ?? '';
        if ($value === '') {
            return;
        }
        $data           = $this->decodeSignedStore($value);
        $this->form     = (isset($data['f']) && is_array($data['f'])) ? $data['f'] : array();
        $this->keepForm = (isset($data['k']) && is_array($data['k'])) ? $data['k'] : array();

        if (!empty($this->keepForm)) {
            $this->writeSignedStore('oc_form', $value, time() + 1800);
        } else {
            $this->writeSignedStore('oc_form', '', time() - 3600);
            unset($_COOKIE['oc_form']);
        }
    }

    /**
     * Persist pending form-repopulation data into the signed cookie so the form can be refilled
     * after the redirect back to it. Called right before a redirect (in Utils::redirectTo). A
     * value too large for a cookie is skipped rather than silently truncated — the form simply
     * will not pre-fill that one time.
     *
     * @return void
     */
    public function _flushFormData()
    {
        if (empty($this->form) && empty($this->keepForm)) {
            if (isset($_COOKIE['oc_form'])) {
                $this->writeSignedStore('oc_form', '', time() - 3600);
            }

            return;
        }
        $value = $this->encodeSignedStore(array('f' => $this->form, 'k' => $this->keepForm));
        if (strlen($value) > 3800) {
            return;
        }
        $this->writeSignedStore('oc_form', $value, time() + 1800);
    }

    public function _dropReferer()
    {
        unset(
            $_SESSION['osc_http_referer'],
            $this->session['osc_http_referer'],
            $_SESSION['osc_http_referer_state'],
            $this->session['osc_http_referer_state']
        );
    }
}
