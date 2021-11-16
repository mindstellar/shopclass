<?php
	//error_reporting ( E_ALL );
	//ini_set ( "display_errors" , 1 );
	$info = WebThemes::newInstance()->loadThemeInfo( 'shopclass' );
	define( 'SHOPCLASS_PATH' , __DIR__ );
	define( 'TFC_VER' , $info[ 'version' ] );

	// Composer dependency loader
	require 'includes/vendor/autoload.php';
	// theme classes autoloader
	require 'includes/autoloader.php';

	/**
	 * New flashmessage function derived from official function just changed styling to support our theme
	 *
	 * @param string $section
	 * @param string $class
	 * @param string $id
	 */
	function tfc_show_flash_message( $section = 'pubMessages' , $class = "flashmessage" , $id = "flashmessage" ) {
		$messages = Session::newInstance()->_getMessage( $section );
		if ( is_array( $messages ) ) {

			foreach ( $messages as $message ) {
				if ( isset( $message[ 'msg' ] ) && $message[ 'msg' ] != '' ) {
					echo '<div id="' . $id . '" class="' . strtolower( $class ) . '-' . $message[ 'type' ] . ' alert alert-dismissible site-alert fade in">';
					echo '	<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
					echo '		<span aria-hidden="true">&times;</span>';
					echo '	</button>';
					echo '<div class="text-center">' . osc_apply_filter( 'flash_message_text' , $message[ 'msg' ] ) . '</div>';
					echo '</div>';
				} elseif ( $message != '' ) {
					echo '<div id="' . $id . '" class="site-alert alert-primary alert alert-dismissible fade in" >';
					echo '	<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
					echo '		<span aria-hidden="true">&times;</span>';
					echo '	</button>';
					echo '<div class="text-center">' . osc_apply_filter( 'flash_message_text' , $message ) . '</div>';
					echo '</div>';
				}
			}
		}
		Session::newInstance()->_dropMessage( $section );
	}


	/**
	 * Function to return Shopclass theme path or require given file
	 *
	 * @param string $file
	 *
	 * @return mixed
	 */
	function tfc_path( $file = '' ) {
		if ( ! empty( $file ) ) {
			osc_current_web_theme_path( $file );
		} else {
			return SHOPCLASS_PATH . DIRECTORY_SEPARATOR;
		}
		return true;
	}

	if ( ! function_exists( 'logo_header' ) ) {
		/**
		 * @return string
		 */
		function logo_header() {
			$html = '<img border="0" alt="' . osc_page_title() . '" src="' . osc_current_web_theme_url( 'images/logo.jpg' ) . '" />';
			if ( file_exists( WebThemes::newInstance()->getCurrentThemePath() . "images/logo.jpg" ) ) {
				return $html;
			} else if ( tfc_getPref( 'default_logo' ) && ( file_exists( WebThemes::newInstance()->getCurrentThemePath() . "images/default-logo.jpg" ) ) ) {
				return '<img border="0" alt="' . osc_page_title() . '" src="' . osc_current_web_theme_url( 'images/default-logo.jpg' ) . '" />';
			} else {
				return osc_page_title();
			}
		}
	}

	// functionality loader
	require_once 'includes/core-loader.php';

	// include theme actions processing file
	require_once 'includes/actions.php';

	// Core Functions
	require_once 'includes/core-functions.php';

	// Custom Functions
	tfc_path( 'includes/custom-functions.php' );

	// Script/Style Loading functions
	require_once 'includes/tfcScript.php';

	// Voting plugin helper Functions
	if ( class_exists( 'ModelVoting' ) ) {
		require_once 'includes/voting/tfcvoting.php';
	}

	//Admin Forms
	if (OC_ADMIN) {
		require_once 'admin/adminForms.php';
	}
	osc_run_hook( 'shopclass_admin_settings' );

// install update options
	if ( ! function_exists( 'shopclass_theme_update' ) ) {
		/**
		 * Run function after theme update
		 */
		function shopclass_theme_update() {
			//theme settings

			//tfc_seo_create_table();
			tfc_sphinx_create_table();
			tfc_favourite_create_table();
			tfc_user_avatar_table();
			osc_set_preference( 'version' , TFC_VER , 'shopclass_theme' );
			osc_reset_preferences();

		}
	}


	if ( ! function_exists( 'shopclass_theme_install' ) ) {
		/**
		 * Run function after theme install
		 */
		function shopclass_theme_install() {
			//theme settings
			if ( ! ( osc_get_preference( 'keyword_placeholder' , 'shopclass_theme' ) ) ) {
				osc_set_preference( 'keyword_placeholder' , __( 'ie. PHP Programmer' , 'shopclass' ) , 'shopclass_theme' );
			}
			if ( ! ( osc_get_preference( 'header_logo_text' , 'shopclass_theme' ) ) ) {
				osc_set_preference( 'header_logo_text' , 'ShopClass' , 'shopclass_theme' );
			}
			if ( ! ( osc_get_preference( 'header_logo_icon' , 'shopclass_theme' ) ) ) {
				osc_set_preference( 'header_logo_icon' , 'fa-paper-plane' , 'shopclass_theme' );
			}

			osc_set_preference( 'facebook_fanpage' , 'osclass' , 'shopclass_theme' );
			osc_set_preference( 'twitter_username' , 'osclass' , 'shopclass_theme' );

			osc_set_preference( 'header_title_h1' , 'Welcome to Shopclass' , 'shopclass_theme' );
			osc_set_preference( 'header_title_h3' , 'Post Ads For Mobile, Cars, Jobs, Real Estate, Services....' , 'shopclass_theme' );
			osc_set_preference( 'header_search_title_h3' , 'We got you everything, Find Now ...' , 'shopclass_theme' );

			osc_set_preference( 'footer_message' , 'We are your Free and most popular classified ad listing site. Become a free member and start listing your classified and Yellow pages ads within minutes. You can manage all ads from your personalized Dashboard.' , 'shopclass_theme' );

			osc_set_preference( 'version' , TFC_VER , 'shopclass_theme' );
			osc_set_preference( 'enable_caraousel' , true , 'shopclass_theme' );
			osc_set_preference( 'enable_static_map' , true , 'shopclass_theme' );
			osc_set_preference( 'footer_link' , true , 'shopclass_theme' );

			//Sitemap Settings
			osc_set_preference( 'sitemap_number' , 1000 , 'shopclass_theme' );

			// Seo Options
			osc_set_preference( 'enable_seo' , true , 'shopclass_theme' );

			//createTables

			//tfc_seo_create_table();
			tfc_sphinx_create_table();
			tfc_favourite_create_table();
			tfc_user_avatar_table();

			osc_reset_preferences();

		}
	}
	$versionNew = strtr( $info[ 'version' ] , array ( '.' => '' ) );
	$versionOld = strtr( osc_get_preference( 'version' , 'shopclass_theme' ) , array ( '.' => '' ) );

	if ( ! ( osc_get_preference( 'version' , 'shopclass_theme' ) ) ) {
		shopclass_theme_install();
		osc_run_hook( 'shopclass_install' );
		osc_add_flash_ok_message( __( 'Hey Welcome To Shopclass Theme, Please Checkout Our Dashboard Options' , 'shopclass' ) , 'admin' );
		if (!file_exists(osc_uploads_path().'categorypics')){
			if (is_writable(osc_uploads_path())){
				shopclass\includes\classes\tfcFilesClass::copyFolder(SHOPCLASS_PATH.'/assets/categorypics', osc_uploads_path().'categorypics');
			}
			else{
				osc_add_flash_warning_message( __( 'Osclass upload directory is not writable. Category images function will not work.' , 'shopclass' ) , 'admin' );
			}
		}
		if (!file_exists(osc_uploads_path().'shopclass_slider')){
			if (is_writable(osc_uploads_path())){
				shopclass\includes\classes\tfcFilesClass::copyFolder(SHOPCLASS_PATH.'/assets/images/bk-image', osc_uploads_path().'shopclass_slider');
			}
			else{
				osc_add_flash_warning_message( __( 'Osclass upload directory is not writable. Main Slider will not work properly.' , 'shopclass' ) , 'admin' );
			}
		}
	} elseif ( $versionNew > $versionOld ) {
		shopclass_theme_update();
		osc_run_hook( 'shopclass_update' );
		osc_add_flash_ok_message( __( 'Great You have updated Shopclass to latest version' , 'shopclass' ) , 'admin' );
		if (!file_exists(osc_uploads_path().'categorypics')){
			if (is_writable(osc_uploads_path())){
				shopclass\includes\classes\tfcFilesClass::copyFolder(SHOPCLASS_PATH.'/assets/categorypics', osc_uploads_path().'categorypics');
			}
			else{
				osc_add_flash_warning_message( __( 'Osclass upload directory is not writable. Category images function will not work.' , 'shopclass' ) , 'admin' );
			}
		}
		if (!file_exists(osc_uploads_path().'shopclass_slider')){
			if (is_writable(osc_uploads_path())){
				shopclass\includes\classes\tfcFilesClass::copyFolder(SHOPCLASS_PATH.'/assets/images/bk-image', osc_uploads_path().'shopclass_slider');
			}
			else{
				osc_add_flash_warning_message( __( 'Osclass upload directory is not writable. Main Slider will not work properly.' , 'shopclass' ) , 'admin' );
			}
		}
	}
	osc_run_hook( 'shopclass_loaded' );
	$tfcSeo = new shopclass\includes\classes\tfcSeo();
	osc_add_hook('before_html',$tfcSeo);
	unset($tfcSeo);