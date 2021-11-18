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

    use ItemActions;
    use Params;
    use Rewrite;

    /**
     * User: navjottomer
     * Date: 04/11/17
     * Time: 6:05 PM
     */
    class tfcSpamSecurity extends ItemActions {
        public static $instance;
        public $recaptchaEnabled = false;
        public $honeypotEnabled = false;

        /**
         * tfcSpamSecurity constructor.
         *
         * @param bool $is_admin
         */
        public function __construct( $is_admin = false ) {
            parent::__construct( $is_admin );
            if ( tfc_getPref( 'enable_recaptcha' ) ) {
                $this->recaptchaEnabled = true;
            }
            if ( tfc_getPref( 'enable_honeypot' ) ) {
                $this->honeypotEnabled = true;
            }
        }

        /**
         * @return tfcSpamSecurity
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * @return bool
         */
        public function isRequestValid() {
            $honeypot  = $this->isHoneypotValid();
            $recaptcha = $this->isRecaptchaValid();
            if ( ! $honeypot || ! $recaptcha ) {
                return false;
            } else {
                return true;
            }
        }

        /**
         * @return bool
         */
        public function isHoneypotValid() {
            $honeypot = Params::getParam( 'donotdisturb' );
            if ( $honeypot ) {
                return false;
            } else {
                return true;
            }
        }

        /**
         * @return bool
         */
        public function isRecaptchaValid() {
            if ( $this->recaptchaEnabled ) {
                $secret_key = tfc_getPref( 'tfc_recaptcha_secret_key' );
                $response   = Params::getParam( 'g-recaptcha-response' );
                $url        = "https://www.google.com/recaptcha/api/siteverify?secret=" . $secret_key . "&response=" . $response;

                if ( ! $secret_key ) { //if $secret_key is not set
                    return true;
                }

                if ( ! $response ) {
                    return false;
                }
                if ( function_exists( 'curl_version' ) ) {
                    $curl = curl_init( $url );
                    curl_setopt( $curl , CURLOPT_HEADER , false );
                    curl_setopt( $curl , CURLOPT_RETURNTRANSFER , true );
                    curl_setopt( $curl , CURLOPT_TIMEOUT , 1 );
                    curl_setopt( $curl , CURLOPT_SSL_VERIFYPEER , false );
                    $request = curl_exec( $curl );
                } else {
                    $request = file_get_contents( $url );
                }

                $result = json_decode( $request , true );
                if ( true == $result[ 'success' ] ) {
                    return true;
                }

            }

            return true;
        }

        /**
         *
         */
        public function printHoneypotForm() {
            if ( $this->honeypotEnabled ) {

                echo '<div class="donotdisturb"><input type="text" name="donotdisturb" size="10" maxlength="6" value=""/></div>';
            }

        }

        /**
         * @param string $recaptchaId
         */
        public function printRecaptchaForm( $recaptchaId = "recaptcha1" ) {

            if ( $this->recaptchaEnabled ) {
                echo '<div id="' . $recaptchaId . '" data-element="' . $recaptchaId . '" data-sitekey="' . tfc_getPref( 'tfc_recaptcha_site_key' ) . '" data-theme="light" data-callback="tfcCallBack" class="g-recaptcha"></div>';

            }

        }

        /**
         * @return \Closure
         */
        public function printRecaptchaJs() {
            if ( $this->recaptchaEnabled ) {
                return $loadJs = function () { ?>
                    <script>
                        var ids = $('.g-recaptcha').map(function () {
                            return this.id;
                        }).get();

                        var tfcCallBack = function () {
                            var flen = ids.length;
                            for (i = 0; i < flen; i++) {
                                var recaptchaid = ids[i];
                                ids[i] = grecaptcha.render(ids[i]);
                            }
                        };
                    </script>
                    <script src="https://www.google.com/recaptcha/api.js?onload=tfcCallBack&render=explicit" async
                            defer></script>
                    <?php
                };
            }
        }

        /**
         * @return bool
         */
        public function processRequest() {
            if ( Params::getParam( 'authorEmail' ) ) {
                if ( tfcStopForumSpam::newInstance()->isUserIsSpammer( Params::getParam( 'authorEmail' ) ) ) {
                    tfcStopForumSpam::newInstance()->insertUserBanRule( Params::getParam( 'authorEmail' ) );
                    $spamUser = true;
                } else {
                    $spamUser = false;
                }
            } else {
                $spamUser = false;
            }
            if ( ! $this->isRecaptchaValid() || ! $this->isHoneypotValid() || $spamUser ) {

                $location = Rewrite::newInstance()->get_location();
                $section  = Rewrite::newInstance()->get_section();
                if ( $location !== 'ajax' ) {
                    osc_add_flash_error_message( __( 'That was an Invalid request' , 'shopclass' ) );

                    switch ( $location ) {
                        case ( 'item' ):
                            switch ( $section ) {
                                case 'item_add_post':
                                    osc_redirect_to( osc_item_post_url() );
                                    break;
                                case 'item_edit_post':
                                    $secret = Params::getParam( 'secret' );
                                    $id     = Params::getParam( 'id' );
                                    osc_redirect_to( osc_item_edit_url( $secret , $id ) );
                                    break;
                                case 'contact_post':
                                    osc_redirect_to( osc_item_url() );
                                    break;
                                case 'send_friend':
                                    osc_redirect_to( osc_item_send_friend_url() );
                                    break;
                                case 'add_comment':
                                    osc_redirect_to( osc_item_url() );
                                    break;
                                default:
                                    osc_redirect_to( osc_item_url() );
                                    break;
                            }
                            break;
                        case ( 'register' ):
                            osc_redirect_to( osc_register_account_url() );
                            break;
                        case ( 'contact' ):
                            osc_redirect_to( osc_contact_url() );
                            break;
                        case ( 'user' ):
                            switch ( $section ) {
                                case ( 'profile' ):
                                    osc_redirect_to( osc_user_public_profile_url() );
                            }
                            break;
                        default:
                            osc_redirect_to( osc_base_url() );
                            break;
                    }
                } else {
                    echo '<div class="alert alert-danger alert-dismissible" role="alert">';
                    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>';
                    echo __( 'That was an Invalid request' , 'shopclass' ) . '</div>';
                    exit;
                }
            }

            return true;
        }


    }