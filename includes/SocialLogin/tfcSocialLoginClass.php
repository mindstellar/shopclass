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

	namespace shopclass\includes\SocialLogin;
	use Exception;
	use Hybridauth;
	use Hybridauth\HttpClient\Curl;
	use Hybridauth\Storage\Session;
	use Params;
	use User;
	use UserActions;

	/**
	 * Class tfcSocialLoginClass
	 */
	class tfcSocialLoginClass {

		private static $instance;
		private $hybridauth = null;
		private $adapter = null;
		private $provider = null;
		private $storage = null;
		private $userActions = null;
		private $config = null;

		/**
		 * @param null $config
		 *
		 * @throws \Hybridauth\Exception\InvalidArgumentException
		 * @throws \Hybridauth\Exception\RuntimeException
		 */
		private function __construct( $config = null ) {
			if ( $config == null ) {
				/** @var array $config */
				$config = array (
					"callback"   => osc_base_url( true ) . '?endpoint=true' ,
					"providers"  => array (
						"Google"   => array (
							"enabled"    => osc_get_preference( 'tfcGoogleEnabled' , 'shopclass_theme' ) ,
							"photo_size" => 250 ,
							"keys"       => array (
								"id"     => osc_get_preference( 'tfcGoogleId' , 'shopclass_theme' ) ,
								"secret" => osc_get_preference( 'tfcGoogleSecret' , 'shopclass_theme' )

							)
						) ,
						'Facebook' => array (
							"enabled"    => osc_get_preference( 'tfcFacebookEnabled' , 'shopclass_theme' ) ,
							"scope"      => 'email,public_profile' ,
							"photo_size" => 250 ,
							"keys"       => array (
								"id"     => osc_get_preference( 'tfcFacebookId' , 'shopclass_theme' ) ,
								"secret" => osc_get_preference( 'tfcFacebookSecret' , 'shopclass_theme' )
							)
						) ,
						'Twitter'  => array (
							"enabled" => osc_get_preference( 'tfcTwitterEnabled' , 'shopclass_theme' ) ,
							"keys"    => array (
								"key"    => osc_get_preference( 'tfcTwitterId' , 'shopclass_theme' ) ,
								"secret" => osc_get_preference( 'tfcTwitterSecret' , 'shopclass_theme' )
							)
						)
					) ,
					"debug_mode" => false ,
					"debug_file" => dirname( __FILE__ ) . '/hauth.log'
				);
			}
			$this->config = $config;
			if ( ! $this->hybridauth ) {
				$this->hybridauth = new Hybridauth\Hybridauth( $config );
			}
			if ( ! $this->storage ) {
				$this->storage = new Session();
			}
			if ( ! $this->userActions ) {

				$this->userActions = new UserActions( true );
			}

		}

		/**
		 * Process endpoint
		 */
		public function endpoint() {
			if ( $provider = $this->storage->get( 'provider' ) ) {
				tfcSocialLoginClass::newInstance()->authenticate( $provider );
			}
		}

		/**
		 * @param $provider
		 *
		 * @throws \Hybridauth\Exception\InvalidArgumentException
		 * @throws \Hybridauth\Exception\UnexpectedValueException
		 */
		public function authenticate( $provider ) {
			$this->provider = $provider;

			$this->storage->set( 'provider' , $this->provider );
			$this->adapter = $this->hybridauth->getAdapter( $this->provider );
			$this->adapter->setHttpClient( new Curl() );
			try {

				//$this->adapter->setHttpClient(new \Hybridauth\HttpClient\Curl());
				$this->adapter->authenticate();

				$user_profile = (array) $this->adapter->getUserProfile();

				if ( $this->adapter->isConnected() ) {
					$this->process_authentication( $user_profile , $provider );
				}
			} /**
			 * Catch httpClient Error
			 */
			catch ( Hybridauth\Exception\HttpClientFailureException $e ) {

				error_log( 'Curl text error message : ' . $e->getMessage() , 0 );
				osc_add_flash_error_message( __( 'HTTP error, Unabled to send request' , 'shopclass' ) );
			} /**
			 * Catch API Requests Errors
			 */
			catch ( Hybridauth\Exception\HttpRequestFailedException $e ) {

				error_log( 'Raw API Response: ' . $e->getMessage() , 0 );
				osc_add_flash_error_message( __( 'API error, Unable to authenticate' , 'shopclass' ) );
			} /**
			 * This fellow will catch everything else
			 */
			catch ( Exception $e ) {
				osc_add_flash_error_message( 'Oops! We ran into an unknown issue: ' . $e->getMessage() );
			}
			$this->storage->set( 'provider' , null );
			osc_redirect_to( osc_base_url() );
		}

		/**
		 * @param $user_profile
		 * @param $provider
		 *
		 */
		private function process_authentication( $user_profile , $provider ) {


			$manager = User::newInstance();

			$email_taken = $user_profile[ 'email' ] == "" ? false : $manager->findByEmail( $user_profile[ 'email' ] );

			$isUserConnected    = tfc_socialData::newInstance()->is_user_connected( osc_logged_user_id() , $provider );
			$isAnyUserConnected = tfc_socialData::newInstance()->is_other_user_connected( $user_profile[ 'identifier' ] , $provider );

			// Check if this is a connect request
			if ( osc_is_web_user_logged_in() ) { //User LoggedIn now process connect request
				// check user is already connected
				if ( $isUserConnected ) {
					//user connected
				} else {
					// not connected, now check if other user is connected
					if ( $isAnyUserConnected ) {
						// connected to another user  add flash message
						osc_add_flash_error_message( __( 'This Social account is already linked to another user' , 'shopclass' ) );
						$msg         = __( 'This Social account is already linked to another user' , 'shopclass' );
						$redirectUri = osc_base_url();

						osc_add_flash_ok_message( $msg );
						osc_redirect_to( $redirectUri );


					} else { //Not connected to anyone
						//Insert New Data to social profile
						tfc_socialData::newInstance()->insert(
							array (
								'fk_i_user_id'         => osc_logged_user_id() ,
								'fk_i_social_id'       => $user_profile[ 'identifier' ] ,
								'fk_s_authorizer_name' => $provider ,
								'fk_s_social_url'      => $user_profile[ 'profileURL' ] ,
								'fk_s_social_pic'      => $user_profile[ 'photoURL' ]
							)
						);
						$msg         = __( 'You account is now connected with your social account' , 'shopclass' );
						$redirectUri = osc_user_dashboard_url();

						osc_add_flash_ok_message( $msg );
						osc_redirect_to( $redirectUri );

					}
				}
			} else { //User not loggedIn process login/register request
				//checkif someone connected with this social account
				if ( $isAnyUserConnected ) { //connected do login
					$this->userActions->bootstrap_login( $isAnyUserConnected );
					$msg         = __( "You are Successfully logged in" , 'shopclass' );
					$redirectUri = osc_user_dashboard_url();

					osc_add_flash_ok_message( $msg );
					osc_redirect_to( $redirectUri );

				} elseif ( $email_taken ) { //email is available in UserDatabase connect user
					//Insert New Data to social profile
					tfc_socialData::newInstance()->insert(
						array (
							'fk_i_user_id'         => $email_taken[ 'pk_i_id' ] ,
							'fk_i_social_id'       => $user_profile[ 'identifier' ] ,
							'fk_s_authorizer_name' => $provider ,
							'fk_s_social_url'      => $user_profile[ 'profileURL' ] ,
							'fk_s_social_pic'      => $user_profile[ 'photoURL' ]
						)
					);

					$this->userActions->bootstrap_login( $email_taken[ 'pk_i_id' ] );
					$msg         = __( 'You account is now connected with your social account' , 'shopclass' );
					$redirectUri = osc_user_dashboard_url();

					osc_add_flash_ok_message( $msg );
					osc_redirect_to( $redirectUri );

				} else {
					// No one exists with this social account register new User
					$response = $this->register_user( $user_profile , $provider );
					if ( $response[ 'status' ] ) {
						$msg = $response[ 'msg' ];

						$redirectUri = osc_user_dashboard_url();
					} else {
						$msg = $response[ 'msg' ];

						$redirectUri = osc_base_url();
					}


					if ( $response[ 'status' ] == 'success' ) {
						osc_add_flash_ok_message( $msg );
					} else {
						osc_add_flash_error_message( $msg );
					}
					osc_redirect_to( $redirectUri );

				}

			}
		}

		/**
		 * @param $user_profile
		 * @param $provider
		 *
		 * @return array
		 */
		private function register_user( $user_profile , $provider ) {
			$status = false;
			//check if user have any email in his social account
			if ( $user_profile[ 'email' ] == "" ) { //does not contain email
				$msg = __( 'Your social account did not given any email address.' , 'shopclass' );
			} else {    //contain email, move forward

				$isBanned = osc_is_banned( $user_profile[ 'email' ] );
				if ( $isBanned == 1 ) { //email is banned
					$msg = __( 'This email is not allowed' , 'shopclass' );


				} else if ( $isBanned == 2 ) { //IP is banned
					$msg = __( 'This IP address is not allowed' , 'shopclass' );
				} else {

					$manager = User::newInstance();
					//$new_password = osc_genRandomPassword ( 10 ); //create random password for user

					//$emailparts = explode("@", $user_profile['email']); // Needed a better approach
					Params::setParam( 's_name' , $user_profile[ 'displayName' ] );
					Params::setParam( 's_email' , $user_profile[ 'email' ] );

					$email_taken = $manager->findByEmail( $user_profile[ 'email' ] );
					if ( $email_taken ) {
						$msg = __( 'This email is already been used .' , 'shopclass' );
					} else {
						$input = $this->userActions->prepareData( true );
						$manager->insert( $input );
						$userID = $manager->dao->insertedId();
						if ( $userID > 0 ) {

							tfc_socialData::newInstance()->insert(
								array (
									'fk_i_user_id'         => $userID ,
									'fk_i_social_id'       => $user_profile[ 'identifier' ] ,
									'fk_s_authorizer_name' => $provider ,
									'fk_s_social_url'      => $user_profile[ 'profileURL' ] ,
									'fk_s_social_pic'      => $user_profile[ 'photoURL' ]
								)
							);

						}

						osc_run_hook( 'user_register_completed' , $userID );
						$userDB = $manager->findByPrimaryKey( $userID );
						if ( osc_notify_new_user() ) {
							osc_run_hook( 'hook_email_admin_new_user' , $userDB );
						}
						$manager->update( array ( 'b_active' => '1' ) , array ( 'pk_i_id' => $userID ) );
						osc_run_hook( 'hook_email_user_registration' , $userDB );
						osc_run_hook( 'validate_user' , $userDB );
						$msg    = __( 'Your account has been created successfully' , 'shopclass' );
						$status = true;
						$this->userActions->bootstrap_login( $userID );

						return array ( 'status' => $status , 'msg' => $msg );
					}
				}
			}

			return array ( 'status' => $status , 'msg' => $msg );
		}

		/**
		 * @return tfcSocialLoginClass
		 * @throws \Hybridauth\Exception\InvalidArgumentException
		 * @throws \Hybridauth\Exception\RuntimeException
		 */
		public static function newInstance() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Process Logout for all providers
		 */
		public function logout() {

			$this->hybridauth->disconnectAllAdapters();
		}

		/**
		 * @return array
		 */
		public function getProviders() {
			return $this->hybridauth->getProviders();
		}

		/**
		 * @return \Hybridauth\Adapter\AdapterInterface[]
		 * @throws \Hybridauth\Exception\InvalidArgumentException
		 * @throws \Hybridauth\Exception\UnexpectedValueException
		 */
		public function getConnectedAdapters() {
			return $this->hybridauth->getConnectedAdapters();
		}

		/**
		 * @return mixed
		 */
		public function getCallbackUrl() {
			return $this->config[ 'callback' ];
		}

	}