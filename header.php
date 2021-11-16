<div class="navbar navbar-default bottom-shadow" role="navigation">
    <div class="container">
        <div class="navbar-header">
			<?php use shopclass\includes\classes\tfcCookieConsent;
				use shopclass\includes\frm\tfcForm;

				if ( file_exists( WebThemes::newInstance()->getCurrentThemePath() . "assets/images/logo.png" ) ) { ?>
                <strong>
                    <a href="<?php echo osc_base_url(); ?>"><img
                                src="<?php echo osc_current_web_theme_url() . "assets/images/logo.png"; ?>"
                                alt="<?php echo osc_esc_html( tfc_getPref( 'header_logo_text' ) ); ?>"
                                style="margin-top:4px;width:173px;height:53px;"></a>
                </strong>
			<?php } else { ?>
                <strong>
                    <a class="navbar-brand" href="<?php echo osc_base_url(); ?>"><i
                                class="fa <?php echo tfc_getPref( 'header_logo_icon' ); ?>"></i><?php echo tfc_getPref( 'header_logo_text' ); ?>
                    </a>
                </strong>
			<?php } ?>

            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
                <span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
            </button>

        </div>
        <div class="navbar-collapse collapse" id="navbar-main">
            <ul class="nav navbar-nav navbar-left">

				<?php if ( ! osc_is_home_page() && ! osc_is_search_page() ) { ?>
                    <li>
                        <!-- Button trigger modal -->
                        <a data-toggle="modal" href="#categoryModal"><?php _e( 'Categories ' , 'shopclass' ); ?><i
                                    class="caret"></i></a>
                    </li>
				<?php } ?>
            </ul>
            <ul class="nav navbar-nav navbar-right">
				<?php //demo_css_chooser() ?>
				<?php if ( osc_users_enabled() ) { ?>
					<?php if ( osc_is_web_user_logged_in() ) { ?>
                        <li class="dropdown">
							<?php $username = explode( ' ' , osc_logged_user_name() ); ?>
                            <a href="#" class="dropdown-toggle profile-pic" data-toggle="dropdown">
                                <img class="img-circle" width="35"
                                     src="<?php echo tfc_user_profile_pic_url( osc_logged_user_id() ); ?>">
								<?php echo __( 'Hi !' , 'shopclass' ) . ' ' . $username[ 0 ]; ?><i
                                        class="caret"></i>
                            </a>
                            <ul class="dropdown-menu" role="menu">
                                <li>
                                    <a href="<?php echo osc_user_dashboard_url(); ?>"><i
                                                class="fa fa-dashboard"></i><?php _e( 'Dashboard' , 'shopclass' ); ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo osc_user_logout_url(); ?>"><i
                                                class="fa fa-sign-out"></i><?php _e( 'Logout' , 'shopclass' ); ?></a>
                                </li>
                            </ul>
                        </li>
					<?php } else { ?>
                        <li class="dropdown">
                        <a href="#" class="dropdown-toggle"
                           data-toggle="dropdown"><?php echo __( 'Hi Guest' , 'shopclass' ); ?> !<i
                                    class="caret"></i></a>
                        <ul class="dropdown-menu" role="menu">
                        <li><a href="<?php echo osc_user_login_url(); ?>"><i
                                        class="fa fa-sign-in"></i><?php _e( 'Login' , 'shopclass' ); ?></a></li>
						<?php if ( osc_user_registration_enabled() ) { ?>
                            <li><a href="<?php echo osc_register_account_url(); ?>"><i
                                            class="fa fa-lock"></i><?php _e( 'Register' , 'shopclass' ); ?></a></li>
                            </ul>
                            </li>
						<?php } ?>
					<?php } ?>
				<?php } ?>
				<?php if ( ! osc_is_home_page() ) { ?>
                    <li>
                        <a href="<?php echo osc_item_post_url(); ?>"><i
                                    class="fa fa-plus-circle"></i><?php _e( 'Post Free Ad' , 'shopclass' ) ?></a>
                    </li>
				<?php } ?>
                <li class="dropdown">
                    <a href="#" class="dropdown-toggle" data-toggle="dropdown"><?php _e( 'Pages' , 'shopclass' ); ?><i
                                class="caret"></i></a>
                    <ul class="dropdown-menu" role="menu">
                        <li><a href="<?php echo osc_contact_url(); ?>"><?php _e( 'Contact Us' , 'shopclass' ); ?></a>
                        </li>
						<?php osc_reset_static_pages(); ?>
						<?php while ( osc_has_static_pages() ) { ?>
                            <li>
                                <a href="<?php echo osc_static_page_url(); ?>"><?php echo osc_static_page_title(); ?></a>
                            </li>
						<?php } ?>
						<?php osc_reset_static_pages(); ?>
                        <li>
                            <a href="<?php echo osc_search_url( array () ); ?>"><?php _e( 'All Ads' , 'shopclass' ); ?></a>
                        </li>
                    </ul>
                </li>
				<?php if ( ! osc_is_home_page() && ! osc_is_search_page() ) { ?>
                    <li><a data-toggle="modal" href="#searchbarModal" class><i class="fa fa-search"></i></a></li>
				<?php } ?>
            </ul>
        </div>
        <!--/.navbar-collapse -->
    </div>
</div>
<?php echo tfcCookieConsent::cookieConsent(); ?>
<?php tfc_show_flash_message(); ?>
<?php if ( osc_is_home_page() ) { ?>
	<?php
	$sQuery = tfc_getPref( 'keyword_placeholder' , true );
	?>
    <div class="header-box">
        <div class="jumbotron">
            <div class="container">
                <div class="text-center row">
                    <h1><?php echo tfc_getPref( 'header_title_h1' , true ) ?></h1>
                    <h3 class="hidden-xs"><?php echo tfc_getPref( 'header_title_h3' , true ) ?></h3>
                    <div class="col-md-4 col-md-offset-4">
                        <a href="<?php echo osc_item_post_url_in_category(); ?>" class="btn btn-default btn-block"><i
                                    class="fa fa-plus-circle"></i><?php _e( 'Post your Free Ad!' , 'shopclass' ); ?>
                        </a>
                    </div>
                    <div class="col-md-12">
                        <h3 class="hidden-xs"><?php echo tfc_getPref( 'header_search_title_h3' , true ); ?></h3>
                    </div>
                </div>
                <form action="<?php echo osc_base_url( true ); ?>" method="get" class="row nocsrf">
                    <input type="hidden" name="page" value="search"/>

                    <div class="col-lg-8 col-lg-offset-2 header-search">
                        <div class="header-searcbar row">
                            <div class="searchinput col-md-9" id="querypattern">
                                <div class="input-group">
                                    <input autocomplete="off" data-provide="typeahead" name="sPattern" type="text"
                                           id="query" class="form-control input-lg"
                                           placeholder="<?php echo ( osc_search_pattern() != '' ) ? osc_esc_html( osc_search_pattern() ) : osc_esc_html( $sQuery ); ?>"
                                           value="">
                                    <div class="input-group-btn">
                                        <button class="btn btn-danger btn-lg" type="submit"><i class="fa fa-search"></i><span
                                                    class="hidden-xs"><?php _e( "Search" , 'shopclass' ); ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 header-refine">
                                <button class="btn btn-default btn-lg btn-block" type="button" data-toggle="modal"
                                        data-target="#searchModal"><i
                                            class="fa fa-sliders"></i><?php _e( "Refine" , 'shopclass' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- /input-group -->
                    <!--Search Filter Modal-->
                    <div class="modal fade" id="searchModal" tabindex="-1" role="dialog"
                         aria-labelledby="searchModalLabel">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                                                aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title"
                                        id="searchModalLabel"><?php echo __( 'Refine your search' , 'shopclass' ); ?>
                                        ...</h4>
                                </div>
                                <div class="modal-body">
									<?php $layout = tfc_getPref( 'searchbar_layout' );
										if ( $layout == '1' ) {
											$colclass = 'col-md-6 col-sm-6';
										} else {
											$colclass = 'col-md-4 col-sm-4';
										} ?>
                                    <div class="row">
                                        <div class="col-md-12">
											<?php if ( osc_count_categories() ) { ?>
												<?php if ( tfc_getPref( 'search_multistep_cat' ) ) { ?>
                                                    <div class="form-group tfc-form">
                                                        <label><?php _e( 'Category' , 'shopclass' ); ?></label>
														<?php tfcForm::tfc_category_multiple_selects(); ?>
                                                    </div>
												<?php } else { ?>
                                                    <div class="form-group">
                                                        <label><?php _e( 'Select Category' , 'shopclass' ); ?>:</label>
														<?php tfcForm::category_select( Category::newInstance()->toTree( false ) , null , __( "Select a category" , 'shopclass' ) , 'sCategory' ); ?>
                                                    </div>
												<?php }
											} ?>
											<?php if ( $layout == 0 || $layout == 2 ) { ?>
                                                <div class="form-group">
                                                    <label for="sCountry"><?php _e( 'Select Country' , 'shopclass' ); ?>
                                                        :</label>
													<?php
														item_country_box( __( "Select a country..." , 'shopclass' ) );
													?>
                                                </div>
											<?php }
												if ( $layout == 2 || $layout == 1 ) { ?>
													<?php
													$countryId = ( Params::getParam( 'sCountry' ) ) ? Params::getParam( 'sCountry' ) : tfc_getPref( 'default_country' );
													$aRegions  = Region::newInstance()->findByCountry( $countryId );
													if ( count( $aRegions ) >= 0 ) { ?>
                                                        <div class="form-group">
                                                            <label for="sRegion"><?php _e( 'Select Region' , 'shopclass' ); ?>
                                                                :</label>
                                                            <select id="sRegion" name="sRegion"
                                                                    class="selects form-control">
                                                                <option value=""><?php _e( "Select State/Region" , 'shopclass' ); ?></option>
																<?php foreach ( $aRegions as $region ) { ?>
                                                                    <option value="<?php echo $region[ 'pk_i_id' ]; ?>"<?php if ( Params::getParam( 'sRegion' ) || tfc_item_region_id() == $region[ 'pk_i_id' ] ) { ?> selected<?php } ?>><?php echo $region[ 's_name' ]; ?></option>
																<?php } ?>
                                                            </select>
                                                        </div>
													<?php } ?>
													<?php if ( osc_is_ad_page() ) {
														$regionId = tfc_item_region_id();
													} else {
														if ( osc_is_search_page() ) {
															$regionId = Params::getParam( 'sRegion' );
														}
													}
													if ( ! isset( $regionId ) ) {
														$regionId = '';
													}

													$aCities = City::newInstance()->findByRegion( $regionId );
												;
													if ( count( $aCities ) >= 0 ) {
														?>
                                                        <div class="form-group">
                                                            <label for="sCity"><?php _e( 'Select City' , 'shopclass' ); ?>
                                                                :</label>
                                                            <select name="sCity" id="sCity" class="form-control">
                                                                <option value=""><?php _e( "Select State/Region First" , 'shopclass' ); ?></option>
																<?php foreach ( $aCities as $city ) { ?>
                                                                    <option value="<?php echo $city[ 'pk_i_id' ]; ?>"<?php if ( Params::getParam( 'sCity' ) || tfc_item_city_id() == $city[ 'pk_i_id' ] ) { ?> selected<?php } ?>><?php echo $city[ 's_name' ]; ?></option>
																<?php } ?>
                                                            </select>
                                                        </div>
													<?php } ?>
												<?php } else { ?>
                                                    <div class="form-group">
                                                        <label for="sRegion"><?php _e( 'Select Region' , 'shopclass' ); ?>
                                                            :</label>
                                                        <input class="form-control" id="sRegion" type="text"
                                                               name="sRegion" value=""
                                                               placeholder="<?php echo osc_esc_html( __( "Enter State/Region" , 'shopclass' ) ); ?>"/>
                                                    </div>
                                                    <input id="regionId" type="hidden" name="regionId" value=""/>
                                                    <div class="form-group">
                                                        <label for="sCity"><?php echo osc_esc_html( __( 'Select City' , 'shopclass' ) ); ?>
                                                            :</label>
                                                        <input class="form-control" id="sCity" type="text" name="sCity"
                                                               placeholder="<?php echo osc_esc_html( __( "Enter City/Area" , 'shopclass' ) ); ?>"
                                                               value=""/>
                                                    </div>
                                                    <input id="cityId" type="hidden" name="cityId" value=""/>
												<?php } ?>
											<?php if ( osc_images_enabled_at_items() ) { ?>
                                                <div class="control-group">
                                                    <label class="control-label" for="withPicture">
														<?php _e( 'Pictures Only' , 'shopclass' ); ?>
                                                        <span class="controls"><input class="checkbox inline"
                                                                                      type="checkbox" name="bPic"
                                                                                      id="withPicture"
                                                                                      value="1" <?php echo( osc_search_has_pic() ? 'checked="checked"' : '' ); ?> ></span>
                                                    </label>
                                                </div>
											<?php } ?>
											<?php if ( osc_price_enabled_at_items() ) { ?>
                                                <div class="form-group">
                                                    <label><?php _e( 'Price' , 'shopclass' ); ?></label>
                                                    <div class="input-group">
                                                        <span class="input-group-addon"><?php _e( 'Min ' , 'shopclass' ); ?></span>
                                                        <input class="form-control" type="text" id="priceMin"
                                                               name="sPriceMin"
                                                               value="<?php echo osc_search_price_min(); ?>"/>
                                                    </div>
                                                    <br>
                                                    <div class="input-group">
                                                        <span class="input-group-addon"><?php _e( 'Max' , 'shopclass' ); ?></span>
                                                        <input class="form-control" type="text" id="priceMax"
                                                               name="sPriceMax"
                                                               value="<?php echo osc_search_price_max(); ?>"/>
                                                    </div>
                                                </div>
											<?php } ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-success"
                                            data-dismiss="modal"><?php echo __( 'Done' , 'shopclass' ); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
	<?php
	switch ( $layout ) {
		case 1:
		    $search_drop_js = function (){tfcForm::search_drop_js();};
			osc_add_hook( 'footer_scripts_loaded' , $search_drop_js );
			unset($search_drop_js);
			break;
		case 2:
			$search_drop_all_js = function (){tfcForm::search_drop_all_js();};
			osc_add_hook( 'footer_scripts_loaded' , $search_drop_all_js );
			unset($search_drop_all_js);
			break;
		default:
			$search_default_js = function (){tfcForm::search_default_js();};
			osc_add_hook( 'footer_scripts_loaded' , $search_default_js );
			unset($search_default_js);
			break;
	}
	if ( defined( 'SPHINX_SEARCH' ) ) {
		osc_add_hook( 'footer_scripts_loaded' , 'ajax_suggestion_sphinx_js' );
	}
	?>
<?php } else { ?>
    <div class="content-title">
        <div class="container">
			<?php
				$breadcrumb = tfc_breadcrumb( '' , false );
				if ( $breadcrumb != '' ) { ?>
                    <div class="breadcrumb-row text-ellipsis">
						<?php echo $breadcrumb; ?>
                    </div>
				<?php } ?>
        </div>
    </div>
<?php } ?>
<div class="container content_start">
	<?php osc_show_widgets( 'header' ); ?>

    <div class="clearfix"></div>