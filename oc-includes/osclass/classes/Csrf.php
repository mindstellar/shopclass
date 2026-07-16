<?php
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 11/07/20
 * Time: 5:32 PM
 * License is provided in root directory.
 */

namespace mindstellar;

use mindstellar\utility\Utils;
use Params;
use Plugins;
use Preference;
use Session;

/**
 * Class Csrf
 *
 * @package mindstellar\osclass\classes
 */
class Csrf
{
    private static $instance;
    /**
     * @var string
     */
    private $csrfTokenName;
    /**
     * @var string
     */
    private $csrfTokenValue;

    /**
     * @var string
     */
    public $csrfName;
    /**
     * @var \Session
     */
    private $session;

    /**
     * Csrf constructor.
     */
    public function __construct()
    {
        $this->session  = Session::newInstance();
        $this->csrfName = Preference::newInstance()->get('csrf_name');
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
     * Resolve the CSRF token: reuse the one already resolved for this request, then the one
     * in the session, otherwise generate a fresh pair. Called lazily at the point a token is
     * actually emitted (see tokenForm/tokenUrl and replaceForms) so a page with no protected
     * form never generates a token — and therefore never starts a session.
     */
    private function setToken()
    {
        if ($this->csrfTokenName !== null && $this->csrfTokenValue !== null) {
            return;
        }
        $token_name = $this->session->_get('token_name');
        if ($token_name !== '' && $this->session->_get($token_name) !== '') {
            $this->csrfTokenName  = $token_name;
            $this->csrfTokenValue = $this->session->_get($token_name);
        } else {
            $this->csrfTokenName  = $this->csrfName . '_' . bin2hex(random_bytes(8));
            $this->csrfTokenValue = bin2hex(random_bytes(32));
            $this->session->_set('token_name', $this->csrfTokenName);
            $this->session->_set($this->csrfTokenName, $this->csrfTokenValue);
        }
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
     * Create a hidden CSRF token input field to be placed in a form
     *
     * @return string
     *
     */
    public function tokenForm()
    {
        $this->setToken();

        return "<input type='hidden' name='CSRFName' value='" . $this->csrfTokenName . "' />
        <input type='hidden' name='CSRFToken' value='" . $this->csrfTokenValue . "' />";
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

        return 'CSRFName=' . $this->csrfTokenName . '&CSRFToken=' . $this->csrfTokenValue;
    }

    /**
     * Check if CSRF token is valid, die in other case
     */
    public function check()
    {
        $error         = false;
        $str_error     = '';
        $csrfTokenName = Params::getParam('CSRFName');
        $csrfToken     = Params::getParam('CSRFToken');
        if (!$csrfTokenName || !$csrfToken) {
            $str_error = _m('Probable invalid request.');
            $error     = true;
        } elseif (!$this->validateToken($csrfTokenName, $csrfToken)) {
            $str_error = _m('Invalid CSRF token.');
            $error     = true;
        }

        // check ajax request
        if (defined('IS_AJAX') && $error && IS_AJAX === true) {
            echo json_encode(array(
                'error' => 1,
                'msg'   => $str_error
            ));
            exit;
        }

        if ($error === true) {
            $this->setMessage($str_error);

            $this->errorRedirect();
        }
    }

    /**
     * Check if Token is valid
     *
     * @param $csrfTokenName
     * @param $csrfTokenValue
     *
     * @return bool
     */
    private function validateToken($csrfTokenName, $csrfTokenValue)
    {
        $name  = $this->session->_get('token_name');
        $token = $this->session->_get($csrfTokenName);

        return $name === $csrfTokenName && hash_equals((string)$token, (string)$csrfTokenValue);
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

        return $this->csrfTokenName;
    }

    /**
     * @return string
     */
    public function getCsrfTokenValue()
    {
        $this->setToken();

        return $this->csrfTokenValue;
    }
}
