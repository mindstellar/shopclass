<?php

/*
 * This file is part of Osclass (Mindstellar).
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
     * Apply Osclass cookie params (domain, secure under HTTPS) plus SameSite=Lax hardening.
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
        foreach (array('messages', 'keepForm', 'form') as $key) {
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

        return $this->session[$key] ?? '';
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

        return isset($this->session[$key]);
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
        $messages         = $this->_get('messages');
        $messages[$key][] = array('msg' => str_replace(PHP_EOL, '<br />', $value), 'type' => $type);
        $this->_set('messages', $messages);
    }

    /**
     * @param $key
     *
     * @return string|array
     */
    public function _getMessage($key)
    {
        $messages = $this->_get('messages');

        return $messages[$key] ?? '';
    }

    /**
     * @param $key
     */
    public function _dropMessage($key)
    {
        $messages = $this->_get('messages');
        if (!isset($messages[$key])) {
            // Nothing to drop — avoid a write that would needlessly start a session.
            // osc_show_flash_message() calls this on every page render.
            return;
        }
        unset($messages[$key]);
        $this->_set('messages', $messages);
    }

    /**
     * @param $key
     */
    public function _keepForm($key)
    {
        $aKeep       = $this->_get('keepForm');
        $aKeep[$key] = 1;
        $this->_set('keepForm', $aKeep);
    }

    /**
     * @param string $key
     */
    public function _dropKeepForm($key = '')
    {
        $aKeep = $this->_get('keepForm');
        if ($key) {
            unset($aKeep[$key]);
            $this->_set('keepForm', $aKeep);
        } else {
            $this->_set('keepForm', array());
        }
    }

    /**
     * @param $key
     * @param $value
     */
    public function _setForm($key, $value)
    {
        $form       = $this->_get('form');
        $form[$key] = $value;
        $this->_set('form', $form);
    }

    /**
     * @param string $key
     *
     * @return string|array
     */
    public function _getForm($key = '')
    {
        $form = $this->_get('form');
        if ($key) {
            return $form[$key] ?? '';
        }

        return $form;
    }

    /**
     * @return string|array
     */
    public function _getKeepForm()
    {
        return $this->_get('keepForm');
    }

    public function _viewMessage()
    {
        print_r($this->session['messages']);
    }

    public function _viewForm()
    {
        print_r($_SESSION['form']);
    }

    public function _viewKeep()
    {
        print_r($_SESSION['keepForm']);
    }

    public function _clearVariables()
    {
        $form  = $this->_get('form');
        $aKeep = $this->_get('keepForm');
        if (is_array($form)) {
            foreach ($form as $key => $value) {
                if (!isset($aKeep[$key])) {
                    unset($_SESSION['form'][$key], $this->session['form'][$key]);
                }
            }
        }

        if (isset($this->session['osc_http_referer_state'])) {
            $this->session['osc_http_referer_state']++;
            $_SESSION['osc_http_referer_state']++;
            if ((int)$this->session['osc_http_referer_state'] >= 2) {
                $this->_dropReferer();
            }
        }
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
