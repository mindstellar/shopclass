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

    use shopclass\includes\classes\tfcAdminDashboard;
    use shopclass\includes\classes\tfcAdsSpam;
    use shopclass\includes\classes\tfcDuplicateItem;
    use shopclass\includes\classes\tfcEnqueueStyleScript;
    use shopclass\includes\classes\tfcFavouriteItem;
    use shopclass\includes\classes\tfcImageManager;
    use shopclass\includes\classes\tfcSitemap;
    use shopclass\includes\classes\tfcSpamSecurity;
    use shopclass\includes\classes\tfcStopForumSpam;
    use shopclass\includes\classes\tfcUserAvatar;
    use shopclass\includes\SocialLogin\tfc_socialData;
    use shopclass\includes\SocialLogin\tfcSocialLoginClass;
    use shopclass\includes\sphinx\CWebSphSearch;
    use shopclass\includes\sphinx\SphinxQL;

    require_once 'core-functions.php';
    /**
     * User: navjottomer
     * Date: 23/03/19
     * Time: 10:51 AM
     */

    if ( OC_ADMIN ) {
        /** @var callable $runThis */
        tfcAdminDashboard::newInstance()->createThemeDashboard();
    }
    /**
     * @param $item
     */
    function tfcSpamKeywordCheck( $item ) {
        tfcAdsSpam::newInstance( $item )->processRequest();
    }

    osc_add_hook( 'posted_item' , 'tfcSpamKeywordCheck' );
    osc_add_hook( 'edited_item' , 'tfcSpamKeywordCheck' );

    function tfcCreateDuplicateTable() {
        tfcDuplicateItem::newInstance()->importSql();
    }

    /**
     * @param $aItemId
     *
     */
    function tfcDeleteItemFromDuplicate( $aItemId ) {
        tfcDuplicateItem::newInstance()->deleteDuplicateItem( $aItemId );
    }

    /**
     * @param $aItem
     */
    function tfcInsertDuplicateItem( $aItem ) {
        tfcDuplicateItem::newInstance()->insertDuplicateItem( $aItem[ 'pk_i_id' ] , $aItem[ 's_title' ] , $aItem[ 's_description' ] );
    }

    /**
     * @param $aItem
     */
    function tfcUpdateDuplicateItem( $aItem ) {
        tfcDuplicateItem::newInstance()->updateDuplicateItem( $aItem[ 'pk_i_id' ] , $aItem[ 's_title' ] , $aItem[ 's_description' ] );
    }

    /**
     * @param $aItem
     */
    function tfcProcessDuplicateItem( $aItem ) {
        tfcDuplicateItem::newInstance()->processDuplicateItem( $aItem );
    }

    osc_add_hook( 'shopclass_update' , 'tfcCreateDuplicateTable' );
    osc_add_hook( 'shopclass_install' , 'tfcCreateDuplicateTable' );
    //Delete User
    osc_add_hook( 'before_delete_item' , 'tfcDeleteItemFromDuplicate' );
    //Insert Data in Duplicate Table
    osc_add_hook( 'posted_item' , 'tfcInsertDuplicateItem' );
    //Update Data in Duplicate Table
    osc_add_hook( 'edited_item' , 'tfcUpdateDuplicateItem' );

    // Process Duplicate Item after Item Posted
    osc_add_hook( 'posted_item' , 'tfcProcessDuplicateItem' );
    // Process Duplicate Item after Item Edited
    osc_add_hook( 'edited_item' , 'tfcProcessDuplicateItem' );


    /**
     * @param $id
     */
    function tfc_enqueue_script( $id ) {
        tfcEnqueueStyleScript::newInstance()->enqueuScript( $id );
    }

    /**
     * Add script to be loaded
     *
     * @param $id
     * @param $url
     * @param $dependencies mixed, could be an array or a string
     */
    function tfc_register_script( $id , $url , $dependencies = null ) {
        tfcEnqueueStyleScript::newInstance()->registerScript( $id , $url , $dependencies );
    }

    function tfc_load_scripts_header() {
        tfcEnqueueStyleScript::newInstance()->printScripts();
    }

    function tfc_load_scripts_footer() {
        tfcEnqueueStyleScript::newInstance()->printScripts();
        osc_run_hook( 'scripts_loaded' );
        osc_run_hook( 'footer_scripts_loaded' );
    }

    function tfc_load_styles() {
        tfcEnqueueStyleScript::newInstance()->printStyles();
    }

    osc_add_hook( 'header' , 'tfc_load_styles' );

    if ( tfc_getPref( 'tfc_compatibility_mode' ) ) {
        //osc_add_hook( 'header' , 'tfc_load_scripts_header' );
    }

    //osc_add_hook( 'footer' , 'tfc_load_scripts_footer' );

    //osc_remove_hook( 'header' , 'osc_load_scripts' );


    /************************************************************\
     *Delete Item from user favourite list
     * \***********************************************************
     *
     * @param $item
     * @param $uid
     */
    function tfc_fav_delete_item( $item , $uid ) {
        tfcFavouriteItem::newInstance()->delete_favourite_from_user( $item , $uid );
    }

    /************************************************************\
     *Check Item is already in favourite List
     * \***********************************************************
     *
     * @param $item
     * @param $uid
     *
     * @return bool
     */
    function tfc_item_is_favourite( $item , $uid ) {
        return tfcFavouriteItem::newInstance()->is_favourite_item( $item , $uid );
    }

    /************************************************************\
     *Count how many user added item to their favourite list
     * \***********************************************************
     *
     * @param $item
     *
     * @return int
     */
    function tfc_item_total_favourite( $item ) {
        return tfcFavouriteItem::newInstance()->count_total_fav_by_itemId( $item );
    }

    /**
     * Return favourite Items of given UserId
     *
     * @param $userId
     *
     * @return int
     */
    function tfc_favourite_user_items( $userId ) {
        return tfcFavouriteItem::newInstance()->count_total_fav_by_userId( $userId );
    }

    /************************************************************\
     *Delete Item from all users
     * \***********************************************************
     *
     * @param $item
     */
    function tfc_fav_delete_item_from_all( $item ) {
        tfcFavouriteItem::newInstance()->delete_favourite_item_from_all( $item );
    }

    /************************************************************\
     *Delete User and his favourite
     * \***********************************************************
     *
     * @param $user
     */
    function tfc_fav_delete_user( $user ) {
        tfcFavouriteItem::newInstance()->delete_favourite_user( $user );
    }

    /************************************************************\
     *Add Item to favourite list
     * \***********************************************************
     *
     * @param $item
     */
    function tfc_favourite_add_item( $item ) {
        tfcFavouriteItem::newInstance()->add_favourite_item( $item , osc_logged_user_id() );
    }

    /************************************************************\
     *
     * \************************************************************/
    function tfc_is_fav_page() {
        if ( Params::getParam( 'route' ) == 'shopclass-favourite' ) {
            return true;
        }

        return false;
    }

    /************************************************************\
     *
     * \************************************************************/
    function tfc_favourite_action() {
        if ( tfc_is_fav_page() ) {

            if ( osc_is_web_user_logged_in() ) {
                $i_userId = osc_logged_user_id();
                if ( Params::getParam( 'delete' ) != '' && osc_is_web_user_logged_in() ) {
                    tfc_fav_delete_item( Params::getParam( 'delete' ) , $i_userId );
                    tfcFavouriteItem::newInstance()->delete_favourite_from_user( Params::getParam( 'delete' ) , osc_logged_user_id() );
                    osc_add_flash_error_message( __( "Successfully removed your favourite item." , 'shopclass' ) );
                    osc_redirect_to( tfc_favourite_url() );
                }
                $itemsPerPage = ( Params::getParam( 'itemsPerPage' ) != '' ) ? Params::getParam( 'itemsPerPage' ) : 5;
                $iPage        = ( Params::getParam( 'iPage' ) != '' ) ? Params::getParam( 'iPage' ) : 1;

                Search::newInstance()->addConditions( sprintf( "%st_item_favourite.fk_i_user_id = %d" , DB_TABLE_PREFIX , $i_userId ) );
                Search::newInstance()->addConditions( sprintf( "%st_item_favourite.fk_i_item_id = %st_item.pk_i_id" , DB_TABLE_PREFIX , DB_TABLE_PREFIX ) );
                Search::newInstance()->addTable( sprintf( "%st_item_favourite" , DB_TABLE_PREFIX ) );
                Search::newInstance()->dao->orderBy( sprintf( "%st_item_favourite.id" , DB_TABLE_PREFIX ) , 'DESC' );
                Search::newInstance()->page( $iPage - 1 , $itemsPerPage );

                $aItems      = Search::newInstance()->doSearch();
                $iTotalItems = Search::newInstance()->count();
                $iNumPages   = ceil( $iTotalItems / $itemsPerPage );

                View::newInstance()->_exportVariableToView( 'items' , $aItems );
                View::newInstance()->_exportVariableToView( 'search_total_pages' , $iNumPages );
                View::newInstance()->_exportVariableToView( 'search_page' , $iPage - 1 );
                osc_run_hook( "before_html" );
                osc_current_web_theme_path( 'user-favourite.php' );
                Session::newInstance()->_clearVariables();
                osc_run_hook( "after_html" );
                exit;
            } else {
                osc_add_flash_error_message( __( "Please login before using this page." , 'shopclass' ) );
                osc_redirect_to( osc_user_login_url() );
            }

        }
    }

    osc_add_hook( 'tfc_action_after_init' , 'tfc_favourite_action' );
    osc_add_route( 'shopclass-favourite' , 'user/favourite.*' , 'user/favourite/{iPage}' , 'user-favourite.php' , true , 'user' , 'favourite' , __( 'Your Favourite' , 'shopclass' ) );


    /**
     * @return string
     */
    function tfc_favourite_url() {
        if ( osc_route_url( 'shopclass-favourite' ) ) {
            return osc_route_url( 'shopclass-favourite' , array ( 'iPage' => '' ) );
        }

        return false;
    }

    /************************************************************\
     *
     * \************************************************************/
    function tfc_favourite() {
        echo '<span class="favourite btn-block" data-id="' . osc_item_id() . '">';
        echo '<button class="btn btn-default btn-block btn-sm"><i class="fa fa-heart fa-fw"></i>' . __( 'Add To Favourite' , 'shopclass' );
        echo '</button></span>';
        osc_add_hook( 'footer_scripts_loaded' , 'favourite_js' );
    }

    /**
     * @param $ItemId
     * @param $uid
     */
    function tfc_favourite_loop( $ItemId , $uid ) {
        if ( osc_is_web_user_logged_in() ) {
            if ( tfc_item_is_favourite( $ItemId , $uid ) ) {
                echo '<span class="favouriteloop" data-placement="right"  data-toggle="tooltip" data-animation="true" data-original-title="' . __( 'View Favourite List' , 'shopclass' ) . '">';
                echo '<a href="' . tfc_favourite_url() . '"><i class="fa fa-heart fa-fw"></i>' . tfc_item_total_favourite( $ItemId ) . '</a>';
                echo '</span>';

            } else {

                echo '<span class="favouriteloop" data-placement="right" data-id="' . $ItemId . '" data-toggle="tooltip" data-animation="true" data-original-title="' . __( 'Add To Favourite' , 'shopclass' ) . '">';
                echo '<i class="fa fa-heart fa-fw"></i>' . tfc_item_total_favourite( $ItemId );
                echo '</span>';

            }
        } else {

            echo '<span class="favouriteloop" data-placement="right" data-toggle="tooltip" data-animation="true" data-original-title="' . __( 'Please Login' , 'shopclass' ) . '">';
            echo '<a href="' . osc_user_login_url() . '"><i class="fa fa-heart fa-fw"></i>' . tfc_item_total_favourite( $ItemId ) . '</a>';
            echo '</span>';

        }
    }

    /************************************************************\
     *
     * \************************************************************/
    function favourite_js() {
        ?>
        <script>
            $(document).ready(function ($) {
                    $(".favourite").click(function () {
                            var id = $(this).attr("data-id");
                            var dataString = {
                                    dataid: +id
                                }
                            ;
                            var parent = $(this);
                            $(this).fadeOut(300);
                            $.ajax({
                                    type: "POST",
                                    url: "<?php echo osc_esc_js( osc_base_url( true ) . '?page=ajax&action=runhook&hook=favourite' ); ?>",
                                    data: dataString,
                                    cache: false,
                                    success: function (html) {
                                        parent.html(html);
                                        parent.fadeIn(300);
                                    }
                                }
                            );
                        }
                    );
                }
            );
        </script>
        <?php
    }

    /************************************************************\
     *
     * \************************************************************/
    function tfc_ajax_favourite() {
        if ( Params::getParam( 'dataid' ) != '' ) {
            $id     = Params::getParam( 'dataid' );
            $userId = osc_logged_user_id();

            if ( osc_is_web_user_logged_in() ) {

                //If nothing returned then we can process
                if ( tfc_item_is_favourite( $id , $userId ) ) {

                    echo '<a class="btn btn-block btn-success btn-sm" href="' . tfc_favourite_url() . '"><i class="fa fa-heart fa-fw"></i>' . __( 'Already Added!' , 'shopclass' ) . '</a>';

                } else {
                    //Already in favourite !
                    tfc_favourite_add_item( $id );
                    echo '<a class="btn btn-block btn-success btn-sm" href="' . tfc_favourite_url() . '">';
                    echo '<i class="fa fa-heart fa-fw"></i>' . __( 'View your favourite' , 'shopclass' ) . '</a>';
                }
            } else {
                //error user is not login in
                echo '<a class="btn btn-block btn-warning btn-sm" href="' . osc_user_login_url() . '"><i class="fa fa-heart fa-fw"></i>' . __( 'Please login' , 'shopclass' ) . '</a>';
            }
        }
    }

    osc_add_hook( 'ajax_favourite' , 'tfc_ajax_favourite' );

    /************************************************************\
     *
     * \************************************************************/
    function tfc_ajax_favourite_loop() {
        if ( Params::getParam( 'dataid' ) != '' ) {
            $id     = Params::getParam( 'dataid' );
            $userId = osc_logged_user_id();

            if ( osc_is_web_user_logged_in() ) {

                if ( tfc_item_is_favourite( $id , $userId ) ) {
                    tfc_favourite_loop( $id , $userId );
                    echo "yes-fav" . $id . $userId;
                } else {

                    tfc_favourite_add_item( $id );
                    tfc_favourite_loop( $id , $userId );
                    echo "not-fav" . $id . $userId;

                }
            } else {
                tfc_favourite_loop( $id , $userId );
            }

        }
    }

    osc_add_hook( 'ajax_favourite_loop' , 'tfc_ajax_favourite_loop' );
    /************************************************************\
     *
     * \************************************************************/
    function favourite_loop_js() {
        ?>
        <script>
            $(document).ready(function ($) {
                    $(".favouriteloop").click(function () {
                            var id = $(this).attr("data-id");
                            var dataString = {
                                    dataid: +id
                                }
                            ;
                            var parent = $(this);
                            $('.tooltip').remove();
                            $.ajax({
                                    type: "POST",
                                    url: "<?php echo osc_esc_js( osc_base_url( true ) . '?page=ajax&action=runhook&hook=favourite_loop' ); ?>",
                                    data: dataString,
                                    cache: false,
                                    success: function (html) {
                                        parent.replaceWith($.parseHTML(html));
                                        $('[data-toggle="tooltip"]').tooltip();

                                    }
                                }
                            );
                        }
                    );
                }
            );
        </script>
        <?php
    }

    osc_add_hook( 'footer_scripts_loaded' , 'favourite_loop_js' );
    /************************************************************\
     *
     * \************************************************************/
    function tfc_favourite_user_menu() {
        echo '<li class="favourite" ><a href="' . tfc_favourite_url() . '" ><i class="fa fa-heart"></i>' . __( 'Your Favourite' , 'shopclass' ) . '</a></li>';
    }

    /************************************************************\
     *
     * \************************************************************/
    function tfc_favourite_create_table() {
        $path     = WebThemes::newInstance()->getCurrentThemePath() . 'assets/sql/favourite-struct.sql';
        $sql      = file_get_contents( $path );
        $conn     = DBConnectionClass::newInstance();
        $c_db     = $conn->getOsclassDb();
        $comm     = new DBCommandClass( $c_db );
        $imported = $comm->importSQL( $sql );

        return $imported;
    }

// Add link in user menu page
    //osc_add_hook ( 'user_menu' , 'tfc_favourite_user_menu' );// Not needed already included in core functions.
//Delete item
    osc_add_hook( 'before_delete_item' , 'tfc_fav_delete_item_from_all' );
//Delete User
    osc_add_hook( 'before_user_delete' , 'tfc_fav_delete_user' );


    /**
     * Get Item Sitemap Page ID from URL
     */
    function tfc_sitemap_page_id() {
        $requestUri = Params::getServerParam( 'REQUEST_URI' );

        if ( $c = preg_match_all( "/sitemap\/item\-sitemap\_s([0-9]+)\.(?i)xml/is" , $requestUri , $matches ) ) {
            $int1 = $matches[ 1 ][ 0 ];

            return $int1;
        }

        return null;
    }

    /**
     *
     */
    function tfc_render_sitemap_index() {
        $route       = Params::getParam( 'route' );
        $indexroutes = array (
            'tfc-render-sitemap-index1' ,
            'tfc-render-sitemap-index2' ,
            'tfc-render-sitemap-index3'
        );
        if ( in_array( $route , $indexroutes ) ) {

            tfcSitemap::newInstance()->tfc_generate_sitemapindex();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_item() {
        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-item' ) {
            $tfcSitemap = new tfcSitemap();
            $tfcSitemap->setItemPageId( tfc_sitemap_page_id() );
            $tfcSitemap->countAllActiveItems();
            $numurl            = osc_get_preference( 'sitemap_number' , 'shopclass_theme' );
            $item_pages_number = ceil( $tfcSitemap->total_results_table / $numurl ) - 1;
            if ( tfc_sitemap_page_id() <= $item_pages_number ) {
                $tfcSitemap->tfc_generate_item_sitemap();
                Session::newInstance()->_clearVariables();
                exit;
            }
        }
    }

    function tfc_render_sitemap_pages() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-pages' ) {

            tfcSitemap::newInstance()->tfc_generate_page_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_category() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-category' ) {

            tfcSitemap::newInstance()->tfc_generate_category_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_cat_regions() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-cat-regions' ) {

            tfcSitemap::newInstance()->tfc_generate_cat_region_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_cat_cities() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-cat-cities' ) {

            tfcSitemap::newInstance()->tfc_generate_cat_city_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_cities() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-cities' ) {

            tfcSitemap::newInstance()->tfc_generate_cities_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_regions() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-regions' ) {

            tfcSitemap::newInstance()->tfc_generate_regions_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_render_sitemap_countries() {

        if ( Params::getParam( 'route' ) == 'tfc-render-sitemap-countries' ) {

            tfcSitemap::newInstance()->tfc_generate_countries_sitemap();

            Session::newInstance()->_clearVariables();
            exit;
        }
    }

    function tfc_sitemap_remove_url() {
        if ( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ] == 'XMLHttpRequest' ) {
            $key = Params::getParam( 'remove_key' );

            $custom_url = json_decode( osc_get_preference( 'custom_urls' , 'shopclass_theme' ) , true );
            unset( $custom_url[ $key ] );
            osc_set_preference( 'custom_urls' , json_encode( $custom_url ) , 'shopclass_theme' , 'STRING' );

            if ( is_array( $custom_url ) ) {

                echo '<div id="sitemap_custom_url">';
                echo '<table class="table"><tr><th>URL</th><th>Frequency</th><th>Last Modified</th><th>Remove</th></tr>';
                foreach ( $custom_url as $key => $custom_pages ) {
                    echo "<tr>";
                    foreach ( $custom_pages as $pages ) {
                        echo "<td>{$pages}</td>";
                    }
                    echo '<td><button data-id="' . $key . '"class="remove_url btn btn-mini">Remove</button></td>';
                    echo "<tr>";
                }
                echo "</table>";
                echo "</div>";
            }
        }
    }

    function tfc_ping_search_engines() {

        // GOOGLE
        osc_doRequest( 'http://www.google.com/webmasters/sitemaps/ping?sitemap=' . urlencode( osc_base_url() . 'sitemap.xml' ) , array () );
        // BING
        osc_doRequest( 'http://www.bing.com/webmaster/ping.aspx?siteMap=' . urlencode( osc_base_url() . 'sitemap.xml' ) , array () );
        // YAHOO!
        //osc_doRequest( 'http://search.yahooapis.com/SiteExplorerService/V1/updateNotification?appid=' . osc_page_title() . '&url=' . urlencode( osc_base_url() . 'sitemap.xml' ) , array () );
    }

    osc_add_hook( 'ajax_tfc_sitemap' , 'tfc_sitemap_remove_url' ); //Used in Admin
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_index' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_item' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_pages' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_category' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_cat_regions' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_cat_cities' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_cities' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_regions' );
    osc_add_hook( 'tfc_action_after_init' , 'tfc_render_sitemap_countries' );

    osc_add_route( 'tfc-render-sitemap-index1' , 'sitemap.xml' , 'sitemap.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-index2' , 'sitemapindex.xml' , 'sitemap.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-index3' , 'sitemap-index.xml' , 'sitemap.xml' , 'index.php' , false , 'sitemap' );

    osc_add_route( 'tfc-render-sitemap-item' , 'sitemap\/item\-sitemap\_s[0-9]+\.(?i)xml' , 'sitemap/item-sitemap_s{itemSitemapID}.xml' , 'index.php' , false , 'sitemapindex' );
    osc_add_route( 'tfc-render-sitemap-pages' , 'sitemap/pages.xml' , 'sitemap/pages.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-category' , 'sitemap/category.xml' , 'sitemap/category.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-cat-regions' , 'sitemap/categories-regions.xml' , 'sitemap/categories-regions.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-cat-cities' , 'sitemap/categories-cities.xml' , 'sitemap/categories-cities.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-cities' , 'sitemap/cities.xml' , 'sitemap/cities.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-regions' , 'sitemap/regions.xml' , 'sitemap/regions.xml' , 'index.php' , false , 'sitemap' );
    osc_add_route( 'tfc-render-sitemap-countries' , 'sitemap/countries.xml' , 'sitemap/countries.xml' , 'index.php' , false , 'sitemap' );

    $cronjob = osc_get_preference( 'cron_frequency' , 'shopclass_theme' );
    if ( $cronjob == 'monthly' ) {
        osc_add_hook( 'cron_monthly' , 'tfc_ping_search_engines' );
    } else if ( $cronjob == 'weekly' ) {
        osc_add_hook( 'cron_weekly' , 'tfc_ping_search_engines' );
    } else if ( $cronjob == 'daily' ) {
        osc_add_hook( 'cron_daily' , 'tfc_ping_search_engines' );
    }


    /**
     * @param string $recaptchaId
     */
    function tfcPrintSpamSecurityForm( $recaptchaId = 'recaptcha1' ) {
        tfcSpamSecurity::newInstance()->printHoneypotForm();
        tfcSpamSecurity::newInstance()->printRecaptchaForm( $recaptchaId );
    }

    function tfcloadRecaptchaJs() {
        $loadJs = tfcSpamSecurity::newInstance()->printRecaptchaJs();
        osc_add_hook( 'footer_scripts_loaded' , $loadJs );
    }


    /**
     * @return bool
     */
    function tfcCheckSecurity() {
        return tfcSpamSecurity::newInstance()->processRequest();
    }

    if ( ! OC_ADMIN ) {
        osc_add_hook( 'hook_email_contact_user' , 'tfcCheckSecurity' , 1 );//checked
        osc_add_hook( 'pre_item_add' , 'tfcCheckSecurity' );//checked
        osc_add_hook( 'pre_item_edit' , 'tfcCheckSecurity' );//checked
        osc_add_hook( 'before_user_register' , 'tfcCheckSecurity' ); //ToDO//
        osc_add_hook( 'before_add_comment' , 'tfcCheckSecurity' );//checked
        //osc_add_hook( 'pre_user_post' , 'tfcCheckSecurity' );//checked
        osc_add_hook( 'pre_contact_post' , 'tfcCheckSecurity' );//checked
        osc_add_hook( 'pre_item_send_friend_post' , 'tfcCheckSecurity' );//checked
        osc_add_hook( 'pre_item_contact_post' , 'tfcCheckSecurity' );//checked
        if ( tfc_getPref( 'enable_recaptcha' ) ) {
            osc_add_hook( 'tf_after_form' , 'tfcloadRecaptchajs' );//checked
        }

        osc_add_hook( 'tf_after_form' , 'tfcPrintSpamSecurityForm' );
        osc_add_hook( 'tf_after_comment_form' , 'tfcPrintSpamSecurityForm' );
    }

    /**
     * @param $aItem
     */
    function tfcStopForumSpamHook( $aItem ) {
        tfcStopForumSpam::newInstance()->processSPFRequest( $aItem );
    }

    osc_add_hook( 'posted_item' , 'tfcStopForumSpamHook' );
    osc_add_hook( 'edited_item' , 'tfcStopForumSpamHook' );


    //tfcUserAvatar functions
    /**
     * @return string
     */
    function tfc_avatar_upload_path() {
        return osc_uploads_path() . 'user_avatar/';
    }

    /**
     * @param $userId
     *
     * @return string
     */
    function tfc_user_avatar_url( $userId ) {
        $avatar = tfcUserAvatar::newInstance()->is_user_has_avatar( $userId );
        if ( $userId > 0 ) {
            if ( $avatar ) {
                return osc_base_url() . 'oc-content/uploads/user_avatar/' . $avatar[ 'avatar_ext' ];
            } else {
                return osc_current_web_theme_url( 'assets/images/no_avatar.gif' );
            }
        } else {
            return osc_current_web_theme_url( 'assets/images/no_avatar.gif' );
        }

    }

    /**
     *
     */
    function tfc_user_avatar_table() {
        $path = tfc_path() . 'assets/sql/avatar-struct.sql';
        $sql  = file_get_contents( $path );
        $dao  = new DAO;
        $dao->dao->importSQL( $sql );
    }

    /**
     * @param $imgData
     * @param $userId
     *
     * @return bool
     */
    function tfc_user_avatar_process( $imgData , $userId ) {
        if ( ! ( is_dir( osc_uploads_path() . 'user_avatar' ) ) ) {
            mkdir( osc_uploads_path() . 'user_avatar' );
        }
        $upload_path = tfc_avatar_upload_path();
        $avatar      = tfcUserAvatar::newInstance()->is_user_has_avatar( $userId );
        if ( $avatar ) {
            if ( file_exists( $upload_path . $avatar[ 'avatar_ext' ] ) ) {
                @unlink( file_exists( $upload_path . $avatar[ 'avatar_ext' ] ) );
            }
        }

        $desired_name = 'avatar_' . $userId . uniqid( 'pic' ) . '.jpg';
        $tfcImg       = new tfcImageManager();
        $tfcImg->setImageData( $imgData );
        $tfcImg->setMaxSize( '2m' );
        $tfcImg->setImageHeight( '250' );
        $tfcImg->setImageWidth( '250' );
        $tfcImg->setUploadName( $desired_name );
        $tfcImg->setUploadPath( $upload_path );
        $upload = $tfcImg->processUpload();

        if ( $upload ) {
            //Upload Successful now updating database.
            if ( tfcUserAvatar::newInstance()->is_user_has_avatar( $userId ) ) {
                tfcUserAvatar::newInstance()->update_user_avatar( $userId , $desired_name );
                osc_add_flash_ok_message( __( 'Avatar Updated Successfully.' , 'shopclass' ) );

                return true;

            } else {
                tfcUserAvatar::newInstance()->insert_user_avatar( $userId , $desired_name );
                osc_add_flash_ok_message( __( 'Avatar Uploaded Successfully.' , 'shopclass' ) );

                return true;

            }
        } else {
            return false;

        }

    }

    /**
     * tfc avatar upload form
     */
    function tfc_avatar_upload() {

        $user_id = osc_logged_user_id(); // the user id of the user profile we're at
        $result  = tfcUserAvatar::newInstance()->is_user_has_avatar( $user_id );
        echo '<div class="thumbnail"><img src="' . tfc_user_avatar_url( $user_id ) . '" width="250" height="250"></div>';

        if ( $result ) {
            echo '
		    <form class="form-group" method="post" enctype="multipart/form-data"  action="' . osc_base_url( true ) . '">
		    <input type="hidden" name="shopclass_specific" value="upload_avatar" />
		    <input type="file" name="useravatar">
		    <p class="help-block">' . __( 'Upload your profile picture. Only .jpg,.png,.gif are supported. Max Size: 2MB' , 'shopclass' ) . '</p>
		    <button class="btn btn-sm btn-success" type="submit"><i class="fa fa-upload"></i>' . __( 'Upload New Avatar' , 'shopclass' ) . '</button>
		    </form>';
            echo '
			<form class="form-group" method="post" enctype="multipart/form-data"  action="' . osc_base_url( true ) . '">
		    <form method="POST" action="' . osc_base_url( true ) . '">
		    <input type="hidden" name="shopclass_specific" value="delete_avatar" />
		    <button class="btn btn-sm btn-danger" type="submit"><i class="fa fa-trash"></i>' . __( 'Delete Avatar' , 'shopclass' ) . '</button>
		    </form>';

        } else {
            echo '
		    <form class="form-group" method="post" enctype="multipart/form-data"  action="' . osc_base_url( true ) . '">
		    <input type="hidden" name="shopclass_specific" value="upload_avatar" />
		    <input type="file" name="useravatar">
		    <p class="help-block">' . __( 'Upload your profile picture. Only .jpg,.png,.gif are supported. Max Size: 2MB' , 'shopclass' ) . '</p>
		    <button class="btn btn-sm btn-success" type="submit"><i class="fa fa-upload"></i>' . __( 'Upload Avatar' , 'shopclass' ) . '</button>
		    </form>';
        }
        if ( function_exists( 'tfc_get_social_pic_btn' ) ) {
            tfc_get_social_pic_btn();
        }
        echo '<hr>';


    }

    //Sphinx Search Functionality
    if ( defined( 'SPHINX_SEARCH' ) ) {

        function init_CWebSphSearch() {
            $do = new CWebSphSearch();
            $do->doModel();

        }

        osc_add_hook( 'before_search' , 'init_CWebSphSearch' );

        /************************************************************
         *Auto Suggest Query based on Sphinx
         ************************************************************/
        function tfcAjaxSuggest() {
            if ( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ] == 'XMLHttpRequest' ) {

                $q = trim( $_POST[ 'term' ] );
                //$q = explode(' ',$q);

                $sPattern = $q . '*';

                $indexes = explode( " " , SPHINX_ALL_SEARCH_INDEX );
                $index1  = $indexes[ 0 ];
                $index2  = $indexes[ 1 ];
                $sphinx  = new SphinxQL( SPHINX_HOST , SPHINX_MYSQL_PORT );
                $query   = $sphinx->getQuery();
                $query->addIndex( $index1 )->addIndex( $index2 )->setSearch( '"' . $sPattern . '"/1' )->addGroupBy( 's_title' )->addOption( 'field_weights' , '(s_description=0)' )->setOffset( 0 )->setLimit( 10 )->addOption( 'ranker' , 'sph04' );
                $sphinx->query( $query );
                $results = $sphinx->fetchAll();

                $arr = array ();

                /** @var integer $i */
                $i = 1;
                foreach ( $results as $r ) {
                    $arr[] = array ( 'id' => $i ++ , 'name' => utf8_encode( $r[ 's_title' ] ) );
                }

                echo json_encode( $arr );
                exit();
            }
        }

        osc_add_hook( 'ajax_tfc-suggest' , 'tfcAjaxSuggest' );

    }

    //Social Login

    /**
     * @param $user
     */
    function tfc_delete_social_user( $user ) {
        $userId = $user[ 'pk_i_id' ];
        tfc_socialData::newInstance()->delete_social_user( $userId );
    }


    /**
     * always return true
     */
    function tfc_create_social_table() {
        tfc_socialData::newInstance()->importSQL();

        return true;
    }

    osc_add_hook( 'shopclass_update' , 'tfc_create_social_table' );
    osc_add_hook( 'shopclass_install' , 'tfc_create_social_table' );
    //Delete User
    osc_add_hook( 'before_user_delete' , 'tfc_delete_social_user' );

    /**
     * Add Social login init to osclass init hook
     */
    function SocialLogin_init() {
        $providers = array ( 'Google' , 'Facebook' , 'Twitter' );
        $provider  = Params::getParam( 'tfclogin' );
        if ( in_array( $provider , $providers ) ) {
            tfcSocialLoginClass::newInstance()->authenticate( $provider );
        }
        $provider = Params::getParam( 'tfcdisconnect' );
        if ( in_array( $provider , $providers ) ) {
            tfc_socialData::newInstance()->delete_authorizer_from_user( osc_logged_user_id() , $provider );
            osc_add_flash_info_message( __( 'Your account has been disconnected successfully' , 'shopclass' ) );
            osc_redirect_to( osc_user() );
        }
        if ( Params::getParam( 'endpoint' ) == 'true' ) {
            tfcSocialLoginClass::newInstance()->endpoint();
        }
    }

    osc_add_hook( 'init' , 'SocialLogin_init' );

    /**
     * Add Social disconnect init to osclass init hook
     */
    function SocialDisconnect_init() {
        $providers = array ( 'Google' , 'Facebook' , 'Twitter' );
        $provider  = Params::getParam( 'tfcdisconnect' );
        if ( in_array( $provider , $providers ) ) {
            tfc_socialData::newInstance()->delete_authorizer_from_user( osc_logged_user_id() , $provider );
            osc_add_flash_info_message( __( 'Your account has been disconnected successfully' , 'shopclass' ) );
            osc_redirect_to( osc_base_url( false ) );
        }

    }

    osc_add_hook( 'init' , 'SocialDisconnect_init' );

    /**
     * Initiate logout for all providers
     */
    function tfc_SocialLogin_logout() {
        tfcSocialLoginClass::newInstance()->logout();
    }

    osc_add_hook( 'logout' , 'tfc_SocialLogin_logout' );

    /**
     * @param $provider
     *
     * @return string
     */
    function tfc_sociallogin_url( $provider ) {
        return osc_base_url( true ) . '?tfclogin=' . ucfirst( $provider );
    }

    /**
     * @param $provider
     *
     * @return string
     */
    function tfc_socialdisconnect_url( $provider ) {
        return osc_base_url( true ) . '?tfcdisconnect=' . ucfirst( $provider );
    }

    /**
     * Check if user is connected with provider
     *
     * @param $provider
     * @param int|string $userId
     *
     * @return bool
     */
    function tfc_is_user_connected( $provider , $userId ) {
        return tfc_socialData::newInstance()->is_user_connected( $userId , $provider );
    }

    /**
     * Generate Social Login button
     *
     * @param $provider
     * @param string $userId
     *
     * @return bool|string
     */
    function tfc_login_button( $provider , $userId = '' ) {
        $provider = trim( $provider );
        if ( empty( $userId ) ) {
            $userId = osc_logged_user_id();
        }
        if ( tfc_getPref( 'tfc' . $provider . 'Enabled' ) ) {
            if ( osc_is_web_user_logged_in() ) {
                if ( ! empty( $userId ) ) {
                    if ( tfc_is_user_connected( $provider , $userId ) ) {
                        echo '<a class="btn btn-' . lcfirst( $provider ) . ' social-btn-' . lcfirst( $provider ) . '" href="' . tfc_socialdisconnect_url( $provider ) . '"><i class="fa fa-' . lcfirst( $provider ) . '"></i>' . __( 'Disconnect' , 'shopclass' ) . ' ' . $provider . '</a>';
                    } else {
                        echo '<a class="btn btn-' . lcfirst( $provider ) . ' social-btn-' . lcfirst( $provider ) . '" href="' . tfc_sociallogin_url( $provider ) . '"><i class="fa fa-' . lcfirst( $provider ) . '"></i>' . __( 'Connect' , 'shopclass' ) . ' ' . $provider . '</a>';
                    }
                }
            } else {
                if ( osc_is_register_page() ) {
                    echo '<a class="btn btn-' . lcfirst( $provider ) . ' social-btn-' . lcfirst( $provider ) . '" href="' . tfc_sociallogin_url( $provider ) . '"><i class="fa fa-' . lcfirst( $provider ) . '"></i>' . __( 'Register With' , 'shopclass' ) . ' ' . $provider . '</a>';
                } else {
                    echo '<a class="btn btn-' . lcfirst( $provider ) . ' social-btn-' . lcfirst( $provider ) . '" href="' . tfc_sociallogin_url( $provider ) . '"><i class="fa fa-' . lcfirst( $provider ) . '"></i>' . __( 'Login With ' , 'shopclass' ) . ' ' . $provider . '</a>';
                }
            }
        }

        return false;
    }

    function tfc_get_social_pic_btn() {
        $providers = array ( 'Google' , 'Facebook' , 'Twitter' );
        $userId    = osc_logged_user_id();

        foreach ( $providers as $provider ) {
            $socialId = tfc_is_user_connected( $provider , $userId );
            if ( $socialId ) {
                echo '<a class="btn btn-' . lcfirst( $provider ) . ' social-btn-' . lcfirst( $provider ) . '" href="' . osc_base_url( false ) . '&shopclass_specific=upload_avatar&social_upload=' . $provider . '">
                <i class="fa fa-' . lcfirst( $provider ) . '"></i>' . __( 'Avatar From' , 'shopclass' ) . ' ' . $provider . '
                </a>';
            }
        }

    }