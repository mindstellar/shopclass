<div class="sidebar-search left-sidebar col-lg-3 col-md-4 pull-left hidden-sm hidden-xs">
    <div class="tfc-form">
        <div class="panel border-top-2p tfc-item">
            <form class="form-group nocsrf" action="<?php use shopclass\includes\frm\tfcForm;

                echo osc_base_url( true ); ?>" method="get">
                <input type="hidden" name="page" value="search"/>
                <?php $query = Params::getParam( 'sPattern' );
                    if ( ! ( $query ) && ! ( defined( 'SPHINX_SEARCH' ) ) ) { ?>
                        <input type="hidden" name="sOrder" value="<?php echo osc_search_order(); ?>"/>
                        <input type="hidden" name="iOrderType"
                               value="<?php $allowedTypesForSorting = Search::getAllowedTypesForSorting();
                                   echo $allowedTypesForSorting[ osc_search_order_type() ]; ?>"/>
                    <?php } ?>
                <?php /** @noinspection PhpWrongForeachArgumentTypeInspection */
                    foreach ( osc_search_user() as $userId ) { ?>
                        <input type="hidden" name="sUser[]" value="<?php echo osc_search_user(); ?>"/>
                    <?php } ?>
                <div class="panel-heading bg-primary ">
                    <h4 class="panel-title">
                        <i class="fa fa-sliders"></i><?php _e( 'Refine Search' , 'shopclass' ); ?>
                    </h4>
                </div>
                <fieldset class="panel-body">
                    <div class="form-group">
                        <label><?php _e( 'Keyword' , 'shopclass' ); ?></label>
                        <input class="form-control" autocomplete="off"
                               placeholder="<?php echo tfc_getPref( 'keyword_placeholder' ); ?>" type="text"
                               name="sPattern" id="query"
                               value="<?php echo osc_esc_html( osc_search_pattern() ); ?>"/>
                    </div>
                    <?php if ( tfc_getPref( 'search_multistep_cat' ) ) { ?>
                        <div class="form-group">
                            <label><?php _e( 'Category' , 'shopclass' ); ?></label>
                            <?php tfcForm::tfc_category_multiple_selects(); ?>
                        </div>
                    <?php } else {
                        ?>
                        <input type="hidden" name="sCategory" value="<?php echo Params::getParam( 'sCategory' ); ?>"/>
                        <?php
                    } ?>
                    <?php $layout = tfc_getPref( 'searchbar_layout' );
                        if ( $layout == 0 || $layout == 2 ) {
                            ?>
                            <div class="form-group">
                                <label><?php _e( 'Country' , 'shopclass' ); ?></label>
                                <?php item_country_box( __( 'Select a country...' , 'shopclass' ) ); ?>
                            </div>
                        <?php }
                        if ( $layout == 2 || $layout == 1 ) {
                            ?>
                            <div class="form-group">
                                <label><?php _e( 'Region' , 'shopclass' ); ?></label>
                                <?php /** @var array $aRegions */
                                    $countryCode = ( Params::getParam( 'sCountry' ) ) ? Params::getParam( 'sCountry' ) : tfc_getPref( 'default_country' );

                                    $aRegions = Region::newInstance()->findByCountry( $countryCode );
                                    if ( count( $aRegions ) >= 0 ) { ?>
                                        <select id="sRegion" name="sRegion" class="form-control">
                                            <option value=""><?php _e( "Select State/Region" , 'shopclass' ); ?></option>
                                            <?php foreach ( $aRegions as $region ) { ?>
                                                <option value="<?php echo $region[ 'pk_i_id' ]; ?>"
                                                        <?php if ( Params::getParam( 'sRegion' ) == $region[ 'pk_i_id' ] ) { ?>selected<?php } ?> > <?php echo $region[ 's_name' ]; ?></option>
                                            <?php } ?>
                                        </select>
                                    <?php } ?>
                            </div>
                            <div class="form-group">
                                <label><?php _e( 'City' , 'shopclass' ); ?></label>
                                <?php $regionId = Params::getParam( 'sRegion' );
                                    $aCities    = City::newInstance()->findByRegion( $regionId );
                                    if ( count( $aCities ) >= 0 ) {
                                        ?>
                                        <select name="sCity" id="sCity" class="form-control">
                                            <option value=""><?php _e( "Select State/Region First" , 'shopclass' ); ?></option>
                                            <?php foreach ( $aCities as $city ) { ?>
                                                <option value="<?php echo $city[ 'pk_i_id' ]; ?>"
                                                        <?php if ( Params::getParam( 'sCity' ) == $city[ 'pk_i_id' ] ) { ?>selected<?php } ?>><?php echo $city[ 's_name' ]; ?></option>
                                            <?php } ?>
                                        </select>
                                    <?php } ?>
                            </div>
                        <?php } else { ?>
                            <div class="form-group">
                                <label><?php _e( 'Region' , 'shopclass' ); ?></label>
                                <input class="form-control" id="sRegion" type="text" name="sRegion"
                                       placeholder="<?php echo osc_esc_html( __( 'Enter Region' , 'shopclass' ) ); ?>"
                                       value="<?php echo osc_search_region(); ?>" maxlength=""/><input id="regionId"
                                                                                                       type="hidden"
                                                                                                       name="regionId"
                                                                                                       value=""/>
                            </div>
                            <div class="form-group">
                                <label><?php _e( 'City' , 'shopclass' ); ?></label>
                                <input class="form-control" id="sCity" type="text" name="sCity"
                                       placeholder="<?php echo osc_esc_html( __( 'Enter City' , 'shopclass' ) ); ?>"
                                       value="<?php echo osc_search_city(); ?>" maxlength=""/><input id="cityId"
                                                                                                     type="hidden"
                                                                                                     name="cityId"
                                                                                                     value=""/>
                            </div>
                        <?php } ?>
                    <?php if ( osc_images_enabled_at_items() ) { ?>
                        <div class="form-group checkbox">
                            <label for="withPicture">
                                <input class="checkbox inline" type="checkbox" name="bPic" id="withPicture"
                                       value="1" <?php echo( osc_search_has_pic() ? 'checked="checked"' : '' ); ?> >
                                <?php _e( 'Pictures Only' , 'shopclass' ); ?>
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ( osc_price_enabled_at_items() ) { ?>
                        <div class="form-group">
                            <label><?php _e( 'Price' , 'shopclass' ); ?></label>
                            <div class="input-group">
                                <span class="input-group-addon"><?php _e( 'Min' , 'shopclass' ); ?></span>
                                <input class="form-control" type="number" id="priceMin" name="sPriceMin"
                                       value="<?php echo osc_search_price_min(); ?>"
                                       placeholder="<?php echo osc_esc_html( __( 'Min Price' , 'shopclass' ) ); ?>"/>
                            </div>
                            <br>
                            <div class="input-group">
                                <span class="input-group-addon"><?php _e( 'Max' , 'shopclass' ); ?></span>
                                <input class="form-control" type="number" id="priceMax" name="sPriceMax"
                                       value="<?php echo osc_search_price_max(); ?>"
                                       placeholder="<?php echo osc_esc_html( __( 'Max Price' , 'shopclass' ) ); ?>"/>
                            </div>
                        </div>
                    <?php } ?>
                    <div class="form-group col-md-12">
                        <?php
                            if ( osc_search_category_id() ) {
                                osc_run_hook( 'search_form' , osc_search_category_id() );
                            } else {
                                osc_run_hook( 'search_form' );
                            }
                        ?>
                    </div>
                </fieldset>
                <button class="btn btn-danger btn-block" type="submit"><?php _e( 'Apply' , 'shopclass' ); ?></button>
                <a class="btn btn-warning btn-block"
                   href="<?php echo osc_search_url( array () ); ?>"><?php _e( 'Reset' , 'shopclass' ); ?></a>
            </form>
        </div>
        <div class="panel panel-primary tfc-item refine-cat">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <i class="fa fa-list fa-fw"></i><?php _e( 'Refine Categories' , 'shopclass' ); ?>
                </h4>
            </div>
            <div class="panel-body">
                <ul class="list-unstyled">
                    <?php
                        $catId = null;
                        if ( osc_search_category_id() ) {
                            $catId = implode( osc_search_category_id() );
                        }
                        tfc_get_subcat( $catId );
                    ?>
                </ul>
            </div>
        </div>
        <?php osc_alert_form(); ?>
        <?php
            switch ( $layout ) {
                case 1:
                    $search_drop_js = function () {
                        tfcForm::search_drop_js();
                    };
                    osc_add_hook( 'footer_scripts_loaded' , $search_drop_js );
                    unset( $search_drop_js );
                    break;
                case 2:
                    $search_drop_all_js = function () {
                        tfcForm::search_drop_all_js();
                    };
                    osc_add_hook( 'footer_scripts_loaded' , $search_drop_all_js );
                    unset( $search_drop_all_js );
                    break;
                default:
                    $search_default_js = function () {
                        tfcForm::search_default_js();
                    };
                    osc_add_hook( 'footer_scripts_loaded' , $search_default_js );
                    unset( $search_default_js );
                    break;
            }
            if ( defined( 'SPHINX_SEARCH' ) ) {
                osc_add_hook( 'footer_scripts_loaded' , 'ajax_suggestion_sphinx_js' );
            }
            tfc_enqueue_script( 'jquery-ui' );
        ?>
        <div class="text-center">
            <?php if ( tfc_getPref( 'enable_adba' ) ) {
                echo tfc_getPref( 'adsense_banner2' );
            }
            ?>
        </div>
    </div>
</div>
</div>