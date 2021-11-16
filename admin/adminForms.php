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

	use shopclass\includes\classes\tfcAdminFormRegister;
	use shopclass\includes\classes\tfcFilesClass;

	/**
	 * Created by TuffIndia.
	 * User: navjottomer
	 * Date: 23/12/17
	 * Time: 3:33 PM
	 */
	//Spam Security Setings Form and Actions
	function tfcRegisterSpamSecurityForm() {
		/**
         * Basic Example Use
         * $newForm      = array (
		 *                          'id'               => 'spam-security-shopclass' , //Id of route
		 *                          'type'             => 'form-action' ,
		 *                          'div-class'        => 'spf-security-shopclass' ,
		 *                          'form-title'       => __( 'Stop Forum Spam Settings' , 'shopclass' ) ,
		 *                          'form-success-msg' => __( 'Settings Saved Successfully' , 'shopclass' ) ,
		 *                          'form-data'        => array (
		 *                                                      array (
		 *                                                              'id'        => 'spfs_enabled' ,
		 *                                                              'type'      => 'checkbox-input' ,
		 *                                                              'div-class' => 'enable_stpfs_shopclass' ,
		 *                                                              'label'     => __( 'Enable StopForumSpam' , 'shopclass' ) ,
		 *                                                              'help-text' => __( 'Check this to enable StopForumSpam check on Item Publish Page and Comment Page' , 'shopclass' )
		 *                                                             ) ,
		 *                                                      array (
		 *                                                              'id'          => 'spf_frequency_threshold' ,
		 *                                                              'type'        => 'text-input' ,
		 *                                                              'div-class'   => 'spf_appearance' ,
		 *                                                              'label'       => __( 'Frequency Threshold' , 'shopclass' ) ,
		 *                                                              'placeholder' => __( 'Enter Frequency Threshold' , 'shopclass' ) ,
		 *                                                              'help-text'   => __( 'Enter Frequency Threshold. Default is 2' , 'shopclass' )
		 *                                                             ) ,
		 *                                                      array (
		 *                                                              'id'          => 'spf_confidence_threshold' ,
		 *                                                              'type'        => 'text-input' ,
		 *                                                              'div-class'   => 'spf_confidence' ,
		 *                                                              'label'       => __( 'Confidence Threshold' , 'shopclass' ) ,
		 *                                                              'placeholder' => __( 'Enter Confidence Threshold' , 'shopclass' ) ,
		 *                                                              'help-text'   => __( 'Enter Confidence Default is 55' , 'shopclass' )
		 *                                                             )
		 *                                                      )
		 *                      );
         */
		$form = new tfcAdminFormRegister();
		$form->addNewSubheading( 'Stop Forum Spam Settings' );
		$form->addCheckboxInput( 'spfs_enabled' , 'spfs_enabled' , 'Enable StopForumSpam Check' , 'Check this to enable StopForumSpam check on Item Publish Page and Comment Page' );
		$form->addTextInput( 'spf_frequency_threshold' , 'spf_frequency_threshold' , 'Frequuecy threshold' , 'Enter Frequency Threshold. Default is 2' , 'i.e. 24' );
		$form->addTextInput( 'spf_confidence_threshold' , 'spf_confidence_threshold' , 'Confidence threshold' , 'Enter Confidence Threshold. Default is 55' , 'i.e. 24' );

		$form->addNewSubheading( 'Recaptcha V2 Settings' );
		$form->addCheckboxInput( 'enable_recaptcha' , 'enable_recaptcha' , 'Enable Inbuilt Recaptcha' ,
		                         array (
			                         'Check to enable Recaptcha' ,
			                         'Please disable Osclass reCaptcha before using inbuilt Captcha' ,
			                         'Please Create reCaptcha API keys From: <a target="_blank" href="https://www.google.com/recaptcha/admin">Here</a>'
		                         )
		);
		$recaptchaContent
			= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>
					<div class="g-recaptcha" data-size="normal" data-theme="light" data-sitekey="' . tfc_getPref( 'tfc_recaptcha_site_key' ) . '"></div>';
		$form->addCustomInput( 'example_recaptcha' , 'example-recaptcha' , 'Example Recaptcha' , 'This is a example recaptcha' , $recaptchaContent );
		$form->addTextInput( 'tfc_recaptcha_site_key' , 'tfc_recaptcha_site_key' , 'Recaptcha Site Key' , 'Enter your reCaptcha site key' , 'Enter your site key' );
		$form->addTextInput( 'tfc_recaptcha_secret_key' , 'recaptcha-secret-key' , 'Recaptcha Secret Key' , 'Enter your reCaptcha secret key' , 'Enter your secret key' );

		$form->addNewSubheading( 'Other Spam Prevention Settings' );
		$form->addCheckboxInput( 'enable_honeypot' , 'enable_honeypot' , 'Enable honeypot check' ,
		                         array (
			                         'Check to enable honeypot check on form' ,
			                         'This will create hidden input trap for bots'
		                         )
		);
		$duplicateMethodOptions = array (
			array ( 'option-name' => 'By Title' , 'option-value' => 1 ) ,
			array ( 'option-name' => 'By Description' , 'option-value' => 2 )
		);
		$form->addCheckboxInput( 'duplicate_helper' , 'duplicate_helper' , 'Enable Duplicate Protector' , array ( 'Enable it to fight with duplicates ads' ) );
		$form->addSelectInput( 'duplicate_method' , 'duplicate_method' , 'Duplicate Stop Method' , 'Check Method for duplicate check. Default is using both title and description' , 'Default' , $duplicateMethodOptions );

		$form->addNewSubheading( 'Keyword Spam Prevention Settings' );
		$form->addCheckboxInput( 'keyword_spam_enabled' , 'keyword_spam_enabled' , 'Enable Keyword Spam Check' , 'Check this to enable Keyword Spam Check, It will mark ads as spam.' );
		$form->addTextAreaInput( 'keyword_spam_heading' , 'keyword_spam_heading' , 'Keyword in Heading' , 'Enter comma seprated keyword to check in Ads Title' );
		$form->addTextAreaInput( 'keyword_spam_description' , 'keyword_spam_description' , 'Keyword in Description' , 'Enter comma seprated keyword to check in Ads Description' );
		$form->addTextAreaInput( 'keyword_spam_all' , 'keyword_spam_all' , 'Keyword in All' , 'Enter comma seprated keyword to check in both title and description' );

		$form->addNewAdminForm( 'spam-security-shopclass' , 'form-action' , 'spam-security-shopclass' , 'Spam/Security Settings' ,  'Settings Saved Successfully' );
		$form->createAdminAllFormActions();
	}

	tfcRegisterSpamSecurityForm();

	//Social Login Setting Form and Actions
	function tfcRegisterSocialLoginForm() {
		$addForm = new tfcAdminFormRegister();
		//Google
		$addForm->addNewSubheading( 'Google App Settings' );
		$addForm->addNewInputToForm(
			'tfcGoogleId' ,
			'text-input' ,
			'google-id' ,
			'Client Id'  ,
			'Enter Google client Id' ,
			'Enter Client Id'
		);
		$addForm->addNewInputToForm(
			'tfcGoogleSecret' ,
			'text-input' ,
			'google-secret' ,
			'Client Secret' ,
			'Enter Google Client Secret' ,
			'Enter Client Id'
		);
		$addForm->addNewInputToForm(
			'tfcGoogleEnabled' ,
			'checkbox-input' ,
			'google-enabled' ,
			'Enable Google' ,
			'Enable Google Authentication'
		);
		//Facebook
		$addForm->addNewSubheading( 'Facebook App Settings' );
		$addForm->addNewInputToForm(
			'tfcFacebookId' ,
			'text-input' ,
			'Facebook-id' ,
			'App Id' ,
			'Enter Facebook App Id' ,
			'Enter App Id'
		);
		$addForm->addNewInputToForm(
			'tfcFacebookSecret' ,
			'text-input' ,
			'Facebook-secret' ,
			'App Secret'  ,
			'Enter Facebook App Secret' ,
			'Enter App Secret'
		);
		$addForm->addCheckboxInput( 'tfcFacebookEnabled' , 'facebook-enabled' , 'Enable Facebook' , 'Enable Facebook Authentication' );

		//Twitter
		$addForm->addNewSubheading( 'Twitter App Settings' );
		$addForm->addNewInputToForm(
			'tfcTwitterId' ,
			'text-input' ,
			'Twitter-id' ,
			'Consumer Key (API Key)' ,
			'Enter your consumer key' ,
			'Enter API Secret'
		);
		$addForm->addNewInputToForm(
			'tfcTwitterSecret' ,
			'text-input' ,
			'Twitter-secret' ,
			'Consumer Secret (API Secret)'  ,
			'Enter your consumer secret' ,
			'Enter API Secret'
		);
		$addForm->addNewInputToForm(
			'tfcTwitterEnabled' ,
			'checkbox-input' ,
			'Twitter-enabled' ,
			'Enable Twitter' ,
			'Enable Twitter Authentication' ,
			''
		);
		$addForm->addNewAdminForm(
			'social-login-shopclass' ,
			'form-action' ,
			'social-login-class' ,
			'Social Login Settings' ,
			'Social Login Settings Saved Successfully'
		);
		$addForm->createAdminAllFormActions();

	}

	tfcRegisterSocialLoginForm();

	//Related Ads Setting Form and Actions
	function tfcRegisterRelatedAdsForm() {
		$addForm = new tfcAdminFormRegister();
		$addForm->addNewInputToForm(
			'related_ra_numads' , //Id
			'text-input' , //Input Type
			'ra-num-ads' , //Div Class Name
			'Number of Related Ads' ,//Input Label
			'Enter number of ads you want to show in Related Ads' , // Help text
			'Enter Number'// Input Place holder
		);
		$addForm->addNewInputToForm(
			'related_premiumonly' ,
			'checkbox-input' ,
			'premium-enabled' ,
			'Show Premium Only' ,
			'Check this box to show only premium ads'
		);
		$addForm->addNewInputToForm(
			'related_picOnly' ,
			'checkbox-input' ,
			'related-piconly' ,
			'With Pictures' ,
			'Check this box to show ads only with pictures.'
		);
		$addForm->addNewInputToForm(
			'related_ra_category' ,
			'checkbox-input' ,
			'ra-category' ,
			'With Category' ,
			'Check this box to show ads with same category.'
		);
		$addForm->addNewInputToForm(
			'related_ra_country' ,
			'checkbox-input' ,
			'related-country' ,
			 'With Country' ,
			'Check this box to show ads with same country.'
		);
		$addForm->addNewInputToForm(
			'related_ra_region' ,
			'checkbox-input' ,
			'related-region' ,
			'With Region' ,
			'Check this box to show ads with same region.'
		);
		$addForm->addNewAdminForm(
			'related-ads-shopclass' ,
			'form-action' ,
			'related-ads-class' ,
			'Related Ads Settings' ,
			'Related Ads Settings Saved Successfully'
		);
		$addForm->createAdminAllFormActions();

	}

	tfcRegisterRelatedAdsForm();

	//Carousel Setting Form and Actions
	function tfcRegisterCarouselForm() {
		$addForm = new tfcAdminFormRegister();
		$addForm->addNewInputToForm(
			'enable_caraousel' ,
			'checkbox-input' ,
			'enable_caraousel' ,
			'Enable Carousel'  ,
			'Enable or Disable Carousel'
		);
		$addForm->addNewInputToForm(
			'caraousel_number' , //Id
			'text-input' , //Input Type
			'caraousel_number' , //Div Class Name
			'Number of Ads' ,//Input Label
			'Enter Number of ads to rotate in carousel.'  , // Help text
			'Enter Number'// Input Place holder
		);
		$addForm->addNewInputToForm(
			'carousel_withpic' ,
			'checkbox-input' ,
			'carousel_withpic' ,
			'Only With Pictures' ,
			'Check this box to show ads only with pictures.'
		);
		$addForm->addNewInputToForm(
			'caraousel_premium' ,
			'checkbox-input' ,
			'caraousel_premium' ,
			'Show Only Premium'  ,
			'Check this box to show only premium ads.'
		);
		$addForm->addNewInputToForm(
			'caraousel_popular' ,
			'checkbox-input' ,
			'caraousel_popular' ,
			'Show Only Popular' ,
			'Check this box to show only popular ads.'
		);
		$addForm->addNewInputToForm(
			'carousel_rotate' , //Id
			'text-input' , //Input Type
			'carousel_rotate' , //Div Class Name
			'Auto Rotate time'  ,//Input Label
			'Enter your timing in miliseconds i.e.4000 to move carousel in specified time.'  , // Help text
			'i.e. 4000' // Input Place holder
		);
		$addForm->addNewAdminForm(
			'carousel-shopclass' ,
			'form-action' ,
			'caraousel-settings-class' ,
			'Carousel Settings'  ,
			'Carousel Settings Saved Successfully'
		);
		$addForm->createAdminAllFormActions();

	}

	tfcRegisterCarouselForm();

	//Theme Setting Form and Actions
	function tfcRegisterThemeSettingsForm() {

		$styleSelectOptions = array ();
		$dir                = WebThemes::newInstance()->getCurrentThemePath() . 'assets/css/theme';
		$filelist           = tfcFilesClass::newInstance()
		                                   ->setEditDirectory( $dir )
		                                   ->setValidFiles( array ( 'css' ) )
		                                   ->scanFilenames();
		foreach ( $filelist as $file ) {
			if ( strpos( $file , '.min.css' ) !== false ) {
				$filebasename         = str_replace( '.min.css' , '' , $file );
				$styleSelectOptions[] = array (
					'option-value' => $filebasename ,
					'option-name'  => ucfirst( $filebasename )
				);
			}
		}

		$addForm = new tfcAdminFormRegister();
		$addForm->addNewSubheading( 'General Settings' );
		$addForm->addCheckboxInput( 'tfc_compatibility_mode' , 'tfc_compatibility_mode' , 'Enable Compatibility Mode' ,
                                    array('Check this to enable old method of script loading','This will fix compatiblity issue with some plugins.')
        );
		$addForm->addSelectInput( 'default_style' , 'default_style' , 'Default Style' , 'Select Default Theme Style' , 'Choose Option' , $styleSelectOptions );
		unset( $filelist , $styleSelectOptions );
		$defaultShowOptions = array (
			array ( 'option-name' => 'List' , 'option-value' => 'list' ) ,
			array ( 'option-name' => 'Gallery' , 'option-value' => 'gallery' )
		);
		$addForm->addSelectInput( 'defaultShowAs@all' , 'default_show' , 'Show Ads As' , 'Choose Ads to show as Gallery Or List' , '' , $defaultShowOptions );
		$addForm->addCheckboxInput( 'cat_selection' , 'cat_selection' , 'Enable MultiStep Category' , 'This will enable multistep category selection at ad publish page' );

		/** @var array $aCountries */
		$aCountries = osc_get_countries();
		if ( count( $aCountries ) > 0 ) {
			$countryOptions = array ();
			foreach ( $aCountries as $country ) {
				$countryOptions[] = array (
					'option-value' => $country[ 'pk_c_code' ] ,
					'option-name'  => ucfirst( $country[ 's_name' ] )
				);
			}
			$addForm->addSelectInput( 'default_country' , 'default-country' , 'Default Country' , 'Choose your default country for Search or publish page.' , 'Choose Country' , $countryOptions );
		}
		/** @var array $aCountries */
		unset( $aCountries , $countryOptions );

		$addForm->addTextInput( 'cdn_url' , 'cdn_url' , 'CDN URL' , 'Enter your CDN URL without "http://" i.e. cdn.example.com you can also use you cname subdomain to fasten your page loading' , 'Enter CDN URL' );
		$addForm->addCheckboxInput( 'enable_disqus' , 'enable_disqus' , 'Enable Disqus' , 'Check to enable Disqus Comments System' );
		$addForm->addTextInput( 'disqus_shortname' , 'disqus_shortname' , 'Disqus Shortname' , 'Signup for your site from here <a href="http://disqus.com/admin/register/" target="_blank">DISQUS</a>' , 'Enter Shortname' );
		$addForm->addTextInput( 'googlemap_key' , 'googlemap_key' , 'Google Map Api Key' , 'Signup for your site from here <a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank">Google API Key</a>' , 'Enter API Key' );
		$addForm->addCheckboxInput( 'enable_tinyMce' , 'enable_tinyMce' , 'Enable TinyMce Editor' , 'Enable Inbuilt Tiny_MCE Editor on listing publish and editing page.' );
		$addForm->addTextInput( 'push_top_limit' , 'push_top_limit' , 'Push To Top Limit' , 'Enter values in hours, It limit users to push their listings for certain time limit' , 'i.e. 1 for one hour' );
		$addForm->addTextAreaInput( 'tfc-address' , 'tfc-address' , 'You Address' , 'This will show on footer section and contact page' , 'Enter your address.' );
		$addForm->addCheckboxInput( 'enable_cookieconsent' , 'enable_cookieconsent' , 'Enable Cookie Consent' , 'This will enable cookie consent alert for first time users' );
		$addForm->addTextInput( 'tfc-privacy-url' , 'tfc-privacy-url' , 'Privacy Page URL' , array (
			'Please Enter your privacy page url, It will be used for Cookie Consent Alert, which is needed according General Data Protection Regulation (GDPR)' ,
			'The General Data Protection Regulation (GDPR) is a EU-wide regulation that controls how companies and other organizations handle personal data. '
		) , 'i.e. https://example.com/privacy-policy' );

		$addForm->addNewSubheading( 'HomePage Settings' );
		$defaultLayoutOptions = array (
			array ( 'option-name' => 'Layout 1' , 'option-value' => '1' ) ,
			array ( 'option-name' => 'Layout 2' , 'option-value' => '2' ) ,
			array ( 'option-name' => 'Layout 3' , 'option-value' => '3' )
		);
		$addForm->addSelectInput( 'page_layout' , 'page_layout' , 'Homepage Layout' , 'Choose Default Homepage Layout' , '' , $defaultLayoutOptions );
		$addForm->addCheckboxInput( 'distinct_ad' , 'distinct_ad' , 'Enable Distinct Ads' , 'It will show unique ads at homepage latest listings' );
		$addForm->addTextInput( 'header_title_h1' , 'header_title_h1' , 'HeaderBox First Title' , 'Add Homepage header first title' , 'Enter Title' , true );
		$addForm->addTextInput( 'header_title_h3' , 'header_title_h3' , 'HeaderBox Second Title' , 'Add Homepage header second title' , 'Enter Title' , true );
		$addForm->addTextInput( 'header_search_title_h3' , 'header_search_title_h3' , 'Headerbox Searchbar Title' , 'Add Searchbar title at homepage header' , 'Enter Title' , true );

		$addForm->addNewSubheading( 'Search Settings' );

		$addForm->addTextInput( 'keyword_placeholder' , 'keyword_placeholder' , 'Search Placeholder' , 'Enter default search placeholder' , 'i.e. Programmer' , true );
		$searchLocationOptions = array (
			array ( 'option-name' => 'Autocomplete' , 'option-value' => '0' ) ,
			array ( 'option-name' => 'City/Region' , 'option-value' => '1' ) ,
			array ( 'option-name' => 'Country/Region/City' , 'option-value' => '2' )
		);
		$addForm->addSelectInput( 'searchbar_layout' , 'searchbar_layout' , 'Search Location Form' , 'Choose Your default option for search form location filter.' , '' , $searchLocationOptions );
		$addForm->addCheckboxInput( 'search_multistep_cat' , 'search_multistep_cat' , 'Enable MultiStep Category' , 'Check to enable MultiStep Category Selection' );

		$addForm->addNewSubheading( 'Footer Settings' );

		$addForm->addTextAreaInput( 'footer_message' , 'footer_message' , 'Footer Message' , 'Enter Text to show in footer section' , 'Enter your message. Can be used for HTML/JS content' , true );
		$addForm->addTextInput( 'footer_link_email' , 'footer_link_email' , 'Footer Info Email' , 'Enter email to show in footer end' , 'info@example.com' );
		$addForm->addTextInput( 'category_id' , 'category_id' , 'Popular Categories Ids' , 'Enter comma seprated categories ids. i.e. 1,2,3,4' , '1,2,3,4' );
		$addForm->addCheckboxInput( 'footer_link' , 'footer_link' , 'Add Osclass Link' , 'Enable if you want to add Osclass.org link in footer.' );
		$addForm->addTextInput( 'facebook_fanpage' , 'facebook_fanpage' , 'Facebook Page Name' , 'Enter Facebook Fanpage Name' , 'osclass' );
		//$addForm->addTextInput( 'googleplus_page' , 'googleplus_page' , 'Google+ Page Id' , 'Enter Google Plus Page Id' , 'osclass' );
		$addForm->addTextInput( 'twitter_username' , 'twitter_username' , 'Twitter Username' , 'Enter Twitter Username' , 'osclass' );


		$addForm->addNewAdminForm( 'settings-shopclass' , 'form-action' , 'settings-shopclass' , 'Shopclass Theme Settings'  , 'Theme Settings Saved Successfully' );
		$addForm->createAdminAllFormActions();

		$afterFormContent = function () {
			echo '<h2 class="render-title ">All Main Categories with their IDs</h2>';

			osc_count_categories();
			osc_goto_first_category();
			while ( osc_has_categories() ) { ?>
                <div class="cat-name-id">
                    <li style="color:#e20300;">
                        <h3><?php echo osc_category_name(); ?> - <?php echo osc_category_id(); ?></h3>
                    </li>
                </div>
			<?php }
		};
		osc_add_hook( 'tfc-settings-shopclass' , $afterFormContent );
	}

	tfcRegisterThemeSettingsForm();

	//Homepage Widget Form and Actions
	function tfcRegisterHomePageWidgetForm() {
		$addForm = new tfcAdminFormRegister();
		$addForm->addNewSubheading( 'Widget Box 1' );
		$addForm->addTextInput( 'icon_widgetbox_1' , 'icon-widgetbox-1' , 'Icon Class' ,
		                        array (
			                        'Enter Icon class i.e. fa-paper-plane' ,
			                        'We use Font Awesome as icon font. Visit: <a href="http://fontawesome.io/icons/" target="_blank"> Font Awesome Icons</a>'
		                        ) ,
		                        'i.e paper-plane' );
		$addForm->addTextInput( 'title_widgetbox_1' , 'title-widgetbox-1' , 'Title' , 'Enter Title' , 'Awesome Title' , true );
		$addForm->addTextAreaInput( 'message_widgetbox_1' , 'message-widgetbox-1' , 'Message' , 'Add your desired message' , 'Awesome Message' , true );

		$addForm->addNewSubheading( 'Widget Box 2' );
		$addForm->addTextInput( 'icon_widgetbox_2' , 'icon-widgetbox-2' , 'Icon Class' ,
		                        array (
			                        'Enter Icon class i.e. fa-paper-plane' ,
			                        'We use Font Awesome as icon font. Visit: <a href="http://fontawesome.io/icons/" target="_blank"> Font Awesome Icons</a>'
		                        ) ,
		                        'i.e paper-plane' );
		$addForm->addTextInput( 'title_widgetbox_2' , 'title-widgetbox-2' , 'Title' , 'Enter Title' , 'Awesome Title' , true );
		$addForm->addTextAreaInput( 'message_widgetbox_2' , 'message-widgetbox-2' , 'Message' , 'Add your desired message' , 'Awesome Message' , true );

		$addForm->addNewSubheading( 'Widget Box 3' );
		$addForm->addTextInput( 'icon_widgetbox_3' , 'icon-widgetbox-3' , 'Icon Class' ,
		                        array (
			                        'Enter Icon class i.e. fa-paper-plane' ,
			                        'We use Font Awesome as icon font. Visit: <a href="http://fontawesome.io/icons/" target="_blank"> Font Awesome Icons</a>'
		                        ) ,
		                        'i.e paper-plane' );
		$addForm->addTextInput( 'title_widgetbox_3' , 'title-widgetbox-3' , 'Title' , 'Enter Title' , 'Awesome Title' , true );
		$addForm->addTextAreaInput( 'message_widgetbox_3' , 'message-widgetbox-3' , 'Message' , 'Add your desired message' , 'Awesome Message' , true );


		$addForm->addNewAdminForm(
			'widgetbox-shopclass' ,
			'form-action' ,
			'homepage-widgetbox-class' ,
			'Homepage Widget Box Settings' ,
			'Homepage Widget Box Settings Saved Successfully'
		);
		$addForm->createAdminAllFormActions();

	}

	tfcRegisterHomePageWidgetForm();

	//Banner Setting Form and Action
	function tfcRegisterAdsenseBannerForm() {
		$addForm = new tfcAdminFormRegister();
		$addForm->addCheckboxInput( 'enable_adba' , 'enable_adba' , 'Enable/Disable Adsense/Banner Ads' , 'Please check or uncheck to disable Adsense/Banner ads.' );
		$addForm->addTextAreaInput( 'adsense_banner1' , 'adsense_banner1' , 'Adsense/Banner Box Home Page layout 1' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner2' , 'adsense_banner2' , 'Search/Category Sidebar Adsense/Banner Box' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner3' , 'adsense_banner3' , 'Search/Category Page Adsense/Banner Box 728x90' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner4' , 'adsense_banner4' , 'Item Page Sidebar Adsense/Bannner Box' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner5' , 'adsense_banner5' , 'Item Page Adsense/Banner Box near Image' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner6' , 'adsense_banner6' , 'Search/Category Between list Adsense/Banner Box 728x90 1st' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addTextAreaInput( 'adsense_banner7' , 'adsense_banner7' , 'Search/Category Between list Adsense/Banner Box 728x90 2nd' , 'This box can also be used with other HTML/Javascript' );
		$addForm->addNewAdminForm(
			'ads-manager-shopclass' ,
			'form-action' ,
			'adsense-banner-class' ,
			'Adsense/Banner Ads Settings' ,
			'Adsense/Banner Ads Settings Saved Successfully'
		);
		$addForm->createAdminAllFormActions();

	}

	tfcRegisterAdsenseBannerForm();

	function tfcRegisterPhpInfoForm() {
		ob_start();
		phpinfo();
		$phpinfo = ob_get_clean();
		$phpinfo = preg_replace( '#^.*<body>(.*)</body>.*$#s' , '$1' , $phpinfo );
		$phpinfo = str_replace( 'module_Zend Optimizer' , 'module_Zend_Optimizer' , $phpinfo );
		$phpinfo = str_replace( '<font' , '<span' , $phpinfo );
		$phpinfo = str_replace( '</font>' , '</span>' , $phpinfo );
		$phpinfo = str_replace( 'border="0" cellpadding="3"' , 'class="table table-bordered table-striped" style="table-layout: fixed;word-wrap: break-word;"' , $phpinfo );
		$phpinfo = str_replace( '<tr class="h"><th>' , '<thead><tr><th>' , $phpinfo );
		$phpinfo = str_replace( '</th></tr>' , '</th></tr></thead><tbody>' , $phpinfo );
		$phpinfo = str_replace( '</table>' , '</tbody></table>' , $phpinfo );
		$phpinfo = preg_replace( '#>(on|enabled|active)#i' , '><span class="text text-info">$1</span>' , $phpinfo );
		$phpinfo = preg_replace( '#>(off|disabled)#i' , '><span class="text-danger">$1</span>' , $phpinfo );
		$phpinfoStyle
			  = '
<style>
#phpinfo th{
background:cornsilk;
}
#phpinfo td.e {
  font-weight: 600;
  font-family: monospace;
  min-width: 300px;
  vertical-align: baseline;
  border: 1px #cd0a0a;
  background:darksalmon;
}
#phpinfo td.v{
  font-size:14px;
  background:wheat;
}
#phpinfo tr.h img{
    display: none;
}
#phpinfo tbody {
    background: beige;
    width: 100%;
}
#phpinfo h2 {
    background: black;
    text-align:center;
}
#phpinfo h2 a{
    color:white;
}
</style>';
		$form = new tfcAdminFormRegister();
		$form->addCustomContent( 'php-info-content' , $phpinfoStyle . '<div id="phpinfo" class="grid-80">' . $phpinfo . '</div>' );
		$form->addNewAdminPage(
			'php-info-shopclass' ,
			'setting-page' ,
			'php-info-dash' ,
			'PHP information'
		);
		$form->createAdminPage();

	}
	tfcRegisterPhpInfoForm();

	function tfcSeoOptionFormRegister(){
        $form = new tfcAdminFormRegister();
        $form->addCheckboxInput('enable_seo','enable-seo','Enable SEO','Check this box to enable SEO Options');
        $form->addNewSubheading('Title and Description');
        $pagesArray = ['home','login','register','publish','edit','contact'];
        foreach($pagesArray as $page) {
	        $form->addTextInput( $page.'page_title' , $page.'-title' , ucfirst($page).' Page Title' , 'Enter your custom title' ,'This is my title', true );
	        $form->addTextAreaInput( $page.'page_desc' , $page.'-desc' , ucfirst($page).' Page Description' , 'Enter your custom description' ,'This is my description', true );
        }
        $form->addNewSubheading('Other Options');
        $itemCityCatOptions = array (
                ['option-name' =>'Before Item Title','option-value' =>'before-title'],
                ['option-name' =>'After Item Title','option-value' =>'after-title'],
        );
        $form->addSelectInput('item-title-add-city-cat','item-add-city-cat','Add City Category to Item Title','Please choose if you want to add City name and category name to add before or after item title','No City Category Name',$itemCityCatOptions);
		$form->addTextInput('add-brand-to-title','add-brand-to-title','Add Brand To All Title','Example title with brand: Your Title your listing - YourBrandName','YourBrandName');
        $form->addTextInput('google_analytic','google_analytic','Enter Google Analytic Id',['Enter your google analytic id if you want to add google analytic code to all pages','For Help Visit: <a href="https://support.google.com/analytics/answer/7476135?hl=en">Here</a>'],'UA-1234568-2');
        $form->addTextInput('google-tag','google-tag','Google Site Verification Meta Code',['Enter your google webmaster verfification code','For help visit : <a href="https://support.google.com/webmasters/answer/9008080?hl=en"> Here</a>'],' Enter your code');
		$form->addNewAdminForm(
			'seo-shopclass' ,
			'form-action' ,
			'seo-option-class' ,
			'Seo Settings' ,
			'Seo settings updated successfully'
		);
		$form->createAdminAllFormActions();
    }
    tfcSeoOptionFormRegister();