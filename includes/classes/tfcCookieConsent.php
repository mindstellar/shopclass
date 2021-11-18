<?php
    /*
     * Shopclass - Free and open source theme for Osclass with premium features
     * Maintained and supported by Mindstellar Community
     * https://github.com/mindstellar/shopclass
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

    namespace shopclass\includes\classes;
    /**
     * A Simple class for cookie consent.
     * User: navjottomer
     * Date: 13/06/18
     * Time: 2:15 PM
     */
    class tfcCookieConsent {
        private static $instance;
        public $cookieConsentEnabled;
        public $userLoggedIn;
        public $cookieSet;
        public $privacyPage;

        /**
         * tfcCookieConsent constructor.
         */
        public function __construct() {
            $this->userLoggedIn = osc_is_web_user_logged_in();
            $cookieset          = false;
            if ( isset( $_COOKIE[ 'cookies_consent' ] ) ) {
                if ( $_COOKIE[ 'cookies_consent' ] == 1 ) {
                    $cookieset = true;
                }
            }
            $this->cookieSet            = $cookieset;
            $this->privacyPage          = tfc_getPref( 'tfc-privacy-url' );
            $this->cookieConsentEnabled = tfc_getPref( 'enable_cookieconsent' );
            tfc_register_script( 'cookieconsent' , tfc_theme_url( 'assets/js/cookie.js' ) , 'bootstrap' );
        }

        /**
         * @return string
         */
        public static function cookieConsent() {
            return tfcCookieConsent::newInstance()->loadCookieConsent();
        }

        /**
         * @return string
         */
        public function loadCookieConsent() {
            if ( ! OC_ADMIN && $this->cookieConsentEnabled ) {
                if ( $this->isConsentNeeded() ) {
                    tfc_enqueue_script( 'cookieconsent' );

                    $cookieHeader = '<div id="cookie_accept" class="flashmessage-info alert alert-dismissible site-alert fade in">';
                    $cookieHeader .= '<div class="text-center">' . __( 'This website uses cookies to ensure you get the best experience on our website' , 'shopclass' );
                    $cookieHeader .= ' <a class="alert-link" href="' . $this->privacyPage . '" target="_blank">' . __( 'Learn more' , 'shopclass' ) . '</a> <span ';
                    $cookieHeader .= 'class="btn btn-default cookie-consent" data-dismiss="alert">' . __( 'Got It!' , 'shopclass' ) . '</span></div>';
                    $cookieHeader .= $this->cookieSet . '</div>';

                    return $cookieHeader;
                }
            }

            return '';

        }

        /**
         * @return bool
         */
        public function isConsentNeeded() {
            $consentNeeded = true;
            if ( $this->userLoggedIn ) {
                $consentNeeded = false;
            }
            if ( $this->cookieSet ) {
                $consentNeeded = false;
            }

            return $consentNeeded;
        }

        /**
         * @return tfcCookieConsent
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

    }