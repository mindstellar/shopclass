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

    namespace shopclass\includes\sphinx;

    use Alerts;
    use BaseModel;
    use Category;
    use City;
    use Country;
    use LatestSearches;
    use Params;
    use Region;
    use RSSFeed;
    use Session;
    use shopclass\includes\cacheModal\tfcCache;

    /**
     * Class CWebSphSearch
     * @package includes\sphinx
     */
    class CWebSphSearch extends BaseModel {
        var $mSearch;
        private $uri;

        /**
         * CWebSphSearch constructor.
         */
        function __construct() {
            parent::__construct();

            $this->mSearch = SphSearch::newInstance();
            $this->uri     = preg_replace( '|^' . REL_WEB_URL . '|' , '' , Params::getServerParam( 'REQUEST_URI' , false , false ) );
            if ( preg_match( '/^index\.php/' , $this->uri ) > 0 ) {
                // search url without permalinks params
            } else {
                $this->uri = preg_replace( '|/$|' , '' , $this->uri );
                // redirect if it ends with a slash NOT NEEDED ANYMORE, SINCE WE CHECK WITH osc_search_url
                if ( ( $this->uri != osc_get_preference( 'rewrite_search_url' )
                       && stripos( $this->uri ,
                                   osc_get_preference( 'rewrite_search_url' ) . '/' ) === false )
                     && osc_rewrite_enabled()
                     && ! Params::existParam( 'sFeed' ) ) {
                    // clean GET html params
                    $this->uri  = preg_replace( '/(\/?)\?.*$/' , '' , $this->uri );
                    $search_uri = preg_replace( '|/[0-9]+$|' , '' , $this->uri );
                    $this->_exportVariableToView( 'search_uri' , $search_uri );

                    // get page if it's set in the url
                    $iPage = preg_replace( '|.*/([0-9]+)$|' , '$01' , $this->uri );
                    if ( is_numeric( $iPage ) && $iPage > 0 ) {
                        Params::setParam( 'iPage' , $iPage );
                        // redirect without number of pages
                        if ( $iPage == 1 ) {
                            $this->redirectTo( osc_base_url() . $search_uri );
                        }
                    }
                    if ( Params::getParam( 'iPage' ) > 1 ) {
                        $this->_exportVariableToView( 'canonical' , osc_base_url() . $search_uri );
                    }

                    // get only the last segment
                    $search_uri = preg_replace( '|.*?/|' , '' , $search_uri );
                    if ( preg_match( '|-r([0-9]+)$|' , $search_uri , $r ) ) {
                        $region = Region::newInstance()->findByPrimaryKey( $r[ 1 ] );
                        if ( ! $region ) {
                            $this->do404();
                        }
                        Params::setParam( 'sRegion' , $region[ 'pk_i_id' ] );
                        Params::unsetParam( 'sCategory' );
                        if ( preg_match( '|(.*?)_.*?-r[0-9]+|' , $search_uri , $match ) ) {
                            Params::setParam( 'sCategory' , $match[ 1 ] );
                        }
                    } else {
                        if ( preg_match( '|-c([0-9]+)$|' , $search_uri , $c ) ) {
                            $city = City::newInstance()->findByPrimaryKey( $c[ 1 ] );
                            if ( ! $city ) {
                                $this->do404();
                            }
                            Params::setParam( 'sCity' , $city[ 'pk_i_id' ] );
                            Params::unsetParam( 'sCategory' );
                            if ( preg_match( '|(.*?)_.*?-c[0-9]+|' , $search_uri , $match ) ) {
                                Params::setParam( 'sCategory' , $match[ 1 ] );
                            }
                        } else {
                            if ( ! Params::existParam( 'sCategory' ) ) {
                                $category = Category::newInstance()->findBySlug( $search_uri );
                                if ( count( $category ) === 0 ) {
                                    $this->do404();
                                }
                                Params::setParam( 'sCategory' , $search_uri );
                            } else {
                                if ( stripos( Params::getParam( 'sCategory' ) , '/' ) !== false ) {
                                    $tmp      = explode( "/" , preg_replace( '|/$|' , '' , Params::getParam( 'sCategory' ) ) );
                                    $category = Category::newInstance()->findBySlug( $tmp[ count( $tmp ) - 1 ] );
                                    Params::setParam( 'sCategory' , $tmp[ count( $tmp ) - 1 ] );
                                } else {
                                    $category = Category::newInstance()->findBySlug( Params::getParam( 'sCategory' ) );
                                    Params::setParam( 'sCategory' , Params::getParam( 'sCategory' ) );
                                }
                                if ( count( $category ) === 0 ) {
                                    $this->do404();
                                }
                            }
                        }
                    }
                }
            }
        }

        //Business Layer...
        function doModel() {

            osc_run_hook( 'sph_before_search' );

            if ( osc_rewrite_enabled() ) {
                // IF rewrite is not enabled, skip this part, preg_match is always time&resources consuming task
                $p_sParams = "/" . Params::getParam( 'sParams' , false , false );
                if ( preg_match_all( '|\/([^,]+),([^\/]*)|' , $p_sParams , $m ) ) {
                    $l = count( $m[ 0 ] );
                    for ( $k = 0; $k < $l; $k ++ ) {
                        switch ( $m[ 1 ][ $k ] ) {
                            case osc_get_preference( 'rewrite_search_country' ):
                                $m[ 1 ][ $k ] = 'sCountry';
                                break;
                            case osc_get_preference( 'rewrite_search_region' ):
                                $m[ 1 ][ $k ] = 'sRegion';
                                break;
                            case osc_get_preference( 'rewrite_search_city' ):
                                $m[ 1 ][ $k ] = 'sCity';
                                break;
                            case osc_get_preference( 'rewrite_search_city_area' ):
                                $m[ 1 ][ $k ] = 'sCityArea';
                                break;
                            case osc_get_preference( 'rewrite_search_category' ):
                                $m[ 1 ][ $k ] = 'sCategory';
                                break;
                            case osc_get_preference( 'rewrite_search_user' ):
                                $m[ 1 ][ $k ] = 'sUser';
                                break;
                            case osc_get_preference( 'rewrite_search_pattern' ):
                                $m[ 1 ][ $k ] = 'sPattern';
                                break;
                            default :
                                // custom fields
                                if ( preg_match( "/meta(\d+)-?(.*)?/" , $m[ 1 ][ $k ] , $results ) ) {
                                    $meta_key   = $m[ 1 ][ $k ];
                                    $meta_value = $m[ 2 ][ $k ];
                                    $array_r    = array ();
                                    if ( Params::existParam( 'meta' ) ) {
                                        $array_r = Params::getParam( 'meta' );
                                    }
                                    if ( $results[ 2 ] == '' ) {
                                        // meta[meta_id] = meta_value
                                        $meta_key             = $results[ 1 ];
                                        $array_r[ $meta_key ] = $meta_value;
                                    } else {
                                        // meta[meta_id][meta_key] = meta_value
                                        $meta_key                           = $results[ 1 ];
                                        $meta_key2                          = $results[ 2 ];
                                        $array_r[ $meta_key ][ $meta_key2 ] = $meta_value;
                                    }
                                    $m[ 1 ][ $k ] = 'meta';
                                    $m[ 2 ][ $k ] = $array_r;
                                }
                                break;
                        }

                        Params::setParam( $m[ 1 ][ $k ] , $m[ 2 ][ $k ] );
                    }
                    Params::unsetParam( 'sParams' );
                }
            }


            $uriParams = Params::getParamsAsArray();
            $searchUri = osc_search_url( $uriParams );
            if ( $this->uri != 'feed' ) {
                $_base_url = WEB_PATH;
                if ( str_replace( "%20" , '+' , $searchUri ) != str_replace( "%20" , '+' , ( $_base_url . $this->uri ) ) ) {
                    $this->redirectTo( $searchUri , 301 );
                }
            }

            ////////////////////////////////
            //GETTING AND FIXING SENT DATA//
            ////////////////////////////////
            $p_sCategory = Params::getParam( 'sCategory' );
            if ( ! is_array( $p_sCategory ) ) {
                if ( $p_sCategory == '' ) {
                    $p_sCategory = array ();
                } else {
                    $p_sCategory = explode( "," , $p_sCategory );
                }
            }

            $p_sCity = Params::getParam( 'sCity' );
            if ( ! is_array( $p_sCity ) ) {
                if ( $p_sCity == '' ) {
                    $p_sCity = array ();
                } else {
                    $p_sCity = explode( "," , $p_sCity );
                }
            }

            $p_sRegion = Params::getParam( 'sRegion' );
            if ( ! is_array( $p_sRegion ) ) {
                if ( $p_sRegion == '' ) {
                    $p_sRegion = array ();
                } else {
                    $p_sRegion = explode( "," , $p_sRegion );
                }
            }

            $p_sCountry = Params::getParam( 'sCountry' );
            if ( ! is_array( $p_sCountry ) ) {
                if ( $p_sCountry == '' ) {
                    $p_sCountry = array ();
                } else {
                    $p_sCountry = explode( "," , $p_sCountry );
                }
            }

            $p_sUser = Params::getParam( 'sUser' );
            if ( ! is_array( $p_sUser ) ) {
                if ( $p_sUser == '' ) {
                    $p_sUser = '';
                } else {
                    $p_sUser = explode( "," , $p_sUser );
                }
            }

            $p_sLocale = Params::getParam( 'sLocale' );
            if ( ! is_array( $p_sLocale ) ) {
                if ( $p_sLocale == '' ) {
                    $p_sLocale = '';
                } else {
                    $p_sLocale = explode( "," , $p_sLocale );
                }
            }

            $p_sPattern = trim( strip_tags( Params::getParam( 'sPattern' ) ) );

            // ADD TO THE LIST OF LAST SEARCHES
            if ( osc_save_latest_searches() && ( ! Params::existParam( 'iPage' ) || Params::getParam( 'iPage' ) == 1 ) ) {
                $savePattern = osc_apply_filter( 'save_latest_searches_pattern' , $p_sPattern );
                if ( $savePattern != '' ) {
                    LatestSearches::newInstance()->insert( array (
                                                               's_search' => $savePattern ,
                                                               'd_date'   => date( 'Y-m-d H:i:s' )
                                                           ) );
                }
            }

            $p_bPic = Params::getParam( 'bPic' );
            $p_bPic = ( $p_bPic == 1 ) ? 1 : 0;

            $p_bPremium = Params::getParam( 'bPremium' );
            $p_bPremium = ( $p_bPremium == 1 ) ? 1 : 0;

            $p_sPriceMin = Params::getParam( 'sPriceMin' );
            $p_sPriceMax = Params::getParam( 'sPriceMax' );

            //WE CAN ONLY USE THE FIELDS RETURNED BY Search::getAllowedColumnsForSorting()
            $p_sOrder = Params::getParam( 'sOrder' );
            if ( ! in_array( $p_sOrder , SphSearch::getAllowedColumnsForSorting() ) ) {
                //$p_sOrder = osc_default_order_field_at_search();
            }
            $old_order = $p_sOrder;

            //ONLY 0 ( => 'asc' ), 1 ( => 'desc' ) AS ALLOWED VALUES
            $p_iOrderType           = Params::getParam( 'iOrderType' );
            $allowedTypesForSorting = SphSearch::getAllowedTypesForSorting();
            $orderType              = osc_default_order_type_at_search();
            foreach ( $allowedTypesForSorting as $k => $v ) {
                if ( $p_iOrderType == $v ) {
                    $orderType = $k;
                    break;
                }
            }
            $p_iOrderType = $orderType;

            $p_sFeed = Params::getParam( 'sFeed' );
            $p_iPage = 0;
            if ( is_numeric( Params::getParam( 'iPage' ) ) && Params::getParam( 'iPage' ) > 0 ) {
                $p_iPage = intval( Params::getParam( 'iPage' ) ) - 1;
            }

            if ( $p_sFeed != '' ) {
                $p_sPageSize = 1000;
            }

            $p_sShowAs          = Params::getParam( 'sShowAs' );
            $aValidShowAsValues = array ( 'list' , 'gallery' );
            if ( ! in_array( $p_sShowAs , $aValidShowAsValues ) ) {
                $p_sShowAs = osc_default_show_as_at_search();
            }

            // search results: it's blocked with the maxResultsPerPage@search defined in t_preferences
            $p_iPageSize = intval( Params::getParam( 'iPagesize' ) );
            if ( $p_iPageSize > 0 ) {
                if ( $p_iPageSize > osc_max_results_per_page_at_search() ) {
                    $p_iPageSize = osc_max_results_per_page_at_search();
                }
            } else {
                $p_iPageSize = osc_default_results_per_page_at_search();
            }


            $successCat = false;
            if ( count( $p_sCategory ) > 0 ) {
                foreach ( $p_sCategory as $category ) {
                    $successCat = ( $this->mSearch->addCategory( $category ) || $successCat );
                }
            }
            //FILTERING CITY
            foreach ( $p_sCity as $city ) {
                $this->mSearch->addCity( $city );
            }
            $p_sCity = implode( ", " , $p_sCity );

            //FILTERING REGION
            foreach ( $p_sRegion as $region ) {
                $this->mSearch->addRegion( $region );
            }
            $p_sRegion = implode( ", " , $p_sRegion );

            //FILTERING COUNTRY
            foreach ( $p_sCountry as $country ) {
                $this->mSearch->addCountry( $country );
            }
            $p_sCountry = implode( ", " , $p_sCountry );

            // FILTERING PATTERN
            if ( $p_sPattern != '' ) {
                $this->mSearch->addPattern( $p_sPattern );
                $osc_request[ 'sPattern' ] = $p_sPattern;
            } else {
                // hardcoded - if there isn't a search pattern, order by dt_pub_date desc
                if ( $p_sOrder == 'relevance' ) {
                    $p_sOrder = 'dt_pub_date';
                    foreach ( $allowedTypesForSorting as $k => $v ) {
                        if ( $p_iOrderType == 'desc' ) {
                            $orderType = $k;
                            break;
                        }
                    }
                    $p_iOrderType = $orderType;
                }
            }

            // FILTERING USER
            if ( $p_sUser != '' ) {
                $this->mSearch->fromUser( $p_sUser );
            }

            // FILTERING IF WE ONLY WANT ITEMS WITH PICS
            if ( $p_bPic ) {
                $this->mSearch->withPicture( true );
            }

            // FILTERING IF WE ONLY WANT PREMIUM ITEMS
            if ( $p_bPremium ) {
                $this->mSearch->onlyPremium( true );
            }

            //FILTERING BY RANGE PRICE
            $this->mSearch->priceRange( $p_sPriceMin , $p_sPriceMax );

            //ORDERING THE SEARCH RESULTS
            $this->mSearch->order( $p_sOrder , $allowedTypesForSorting[ $p_iOrderType ] );

            //SET PAGE
            if ( $p_sFeed == 'rss' ) {
                // If param sFeed=rss, just output last 'osc_num_rss_items()'
                $this->mSearch->page( 0 , osc_num_rss_items() );
            } else {
                $this->mSearch->page( $p_iPage , $p_iPageSize );
            }

            osc_run_hook( 'sph_search_conditions' , Params::getParamsAsArray() );

            // RETRIEVE ITEMS AND TOTAL
            $key = md5( osc_base_url() . $this->mSearch->toJson() );
            //$found = null;
            $cache = false;

            $aItems      = null;
            $iTotalItems = null;
            if ( $cache ) {
                $aItems      = $cache[ 'aItems' ];
                $iTotalItems = $cache[ 'iTotalItems' ];
            } else {
                $aItems                  = $this->mSearch->doSearch();
                $iTotalItems             = $this->mSearch->count();
                $_cache[ 'aItems' ]      = $aItems;
                $_cache[ 'iTotalItems' ] = $iTotalItems;
                tfcCache::runCache()->tfcStore( $key , $_cache , 60 );
            }

            $aItems = osc_apply_filter( 'sph_pre_show_items' , $aItems );

            $iStart    = $p_iPage * $p_iPageSize;
            $iEnd      = min( ( $p_iPage + 1 ) * $p_iPageSize , $iTotalItems );
            $iNumPages = ceil( $iTotalItems / $p_iPageSize );

            // works with cache enabled ?
            osc_run_hook( 'sph_search' , $this->mSearch );

            //preparing variables...
            $countryName = $p_sCountry;
            if ( strlen( $p_sCountry ) == 2 ) {
                $c = Country::newInstance()->findByCode( $p_sCountry );
                if ( $c ) {
                    $countryName = $c[ 's_name' ];
                }
            }
            $regionName = $p_sRegion;
            if ( is_numeric( $p_sRegion ) ) {
                $r = Region::newInstance()->findByPrimaryKey( $p_sRegion );
                if ( $r ) {
                    $regionName = $r[ 's_name' ];
                }
            }
            $cityName = $p_sCity;
            if ( is_numeric( $p_sCity ) ) {
                $c = City::newInstance()->findByPrimaryKey( $p_sCity );
                if ( $c ) {
                    $cityName = $c[ 's_name' ];
                }
            }

            $this->_exportVariableToView( 'search_start' , $iStart );
            $this->_exportVariableToView( 'search_end' , $iEnd );
            $this->_exportVariableToView( 'search_category' , $p_sCategory );
            // hardcoded - non pattern and order by relevance
            $p_sOrder = $old_order;
            $this->_exportVariableToView( 'search_order_type' , $p_iOrderType );
            $this->_exportVariableToView( 'search_order' , $p_sOrder );

            $this->_exportVariableToView( 'search_pattern' , $p_sPattern );
            $this->_exportVariableToView( 'search_from_user' , $p_sUser );
            $this->_exportVariableToView( 'search_total_pages' , $iNumPages );
            $this->_exportVariableToView( 'search_page' , $p_iPage );
            $this->_exportVariableToView( 'search_has_pic' , $p_bPic );
            $this->_exportVariableToView( 'search_only_premium' , $p_bPremium );
            $this->_exportVariableToView( 'search_country' , $countryName );
            $this->_exportVariableToView( 'search_region' , $regionName );
            $this->_exportVariableToView( 'search_city' , $cityName );
            $this->_exportVariableToView( 'search_price_min' , $p_sPriceMin );
            $this->_exportVariableToView( 'search_price_max' , $p_sPriceMax );
            $this->_exportVariableToView( 'search_total_items' , $iTotalItems );
            $this->_exportVariableToView( 'items' , $aItems );
            $this->_exportVariableToView( 'search_show_as' , $p_sShowAs );
            $this->_exportVariableToView( 'search' , $this->mSearch );
            $this->_exportVariableToView( 'search_time' , $this->mSearch->getQueryTime() );
            $this->_exportVariableToView( 'search_keywords' , $this->mSearch->getQueryKeyword() );


            // json
            $json          = $this->mSearch->toJson();
            $encoded_alert = base64_encode( osc_encrypt_alert( $json ) );

            // Create the HMAC signature and convert the resulting hex hash into base64
            $stringToSign = osc_get_alert_public_key() . $encoded_alert;
            $signature    = hex2b64( hmacsha1( osc_get_alert_private_key() , $stringToSign ) );
            Session::newInstance()->_set( 'alert_signature' , $signature );

            $this->_exportVariableToView( 'search_alert' , $encoded_alert );
            $alerts_sub = 0;
            if ( osc_is_web_user_logged_in() ) {
                $alerts = Alerts::newInstance()->findBySearchAndUser( $json , osc_logged_user_id() );
                if ( count( $alerts ) > 0 ) {
                    $alerts_sub = 1;
                }
            }
            $this->_exportVariableToView( 'search_alert_subscribed' , $alerts_sub );

            // calling the view...
            if ( count( $aItems ) === 0 ) {
                header( 'HTTP/1.1 404 Not Found' );
            }

            osc_run_hook( "sph_after_search" );

            if ( ! Params::existParam( 'sFeed' ) ) {
                $this->doView( 'search.php' );
            } else {
                if ( $p_sFeed == '' || $p_sFeed == 'rss' ) {
                    // FEED REQUESTED!
                    header( 'Content-type: text/xml; charset=utf-8' );

                    $feed = new RSSFeed;
                    $feed->setTitle( __( 'Latest listings added' ) . ' - ' . osc_page_title() );
                    $feed->setLink( osc_base_url() );
                    $feed->setDescription( __( 'Latest listings added in' ) . ' ' . osc_page_title() );

                    if ( osc_count_items() > 0 ) {
                        while ( osc_has_items() ) {

                            $itemArray = array (
                                'title'       => osc_item_title() ,
                                'link'        => htmlentities( osc_item_url() , ENT_COMPAT , "UTF-8" ) ,
                                'description' => osc_item_description() ,
                                'country'     => osc_item_country() ,
                                'region'      => osc_item_region() ,
                                'city'        => osc_item_city() ,
                                'city_area'   => osc_item_city_area() ,
                                'category'    => osc_item_category() ,
                                'dt_pub_date' => osc_item_pub_date()
                            );

                            if ( osc_count_item_resources() > 0 ) {
                                osc_has_item_resources();
                                $itemArray[ 'image' ] = array (
                                    'url'   => htmlentities( osc_resource_thumbnail_url() , ENT_COMPAT , "UTF-8" ) ,
                                    'title' => osc_item_title() ,
                                    'link'  => htmlentities( osc_item_url() , ENT_COMPAT , "UTF-8" )
                                );
                            }
                            $feed->addItem( $itemArray );
                        }
                    }

                    osc_run_hook( 'feed' , $feed );
                    $feed->dumpXML();
                    exit;
                } else {
                    osc_run_hook( 'feed_' . $p_sFeed , $aItems );
                    exit;
                }
            }

        }

        /**
         * @param $file
         *
         * @return mixed|void
         */
        function doView( $file ) {
            osc_run_hook( "before_html" );
            osc_current_web_theme_path( $file );
            //echo '<! '.$this->mSearch->_toString().' -->';
            Session::newInstance()->_clearVariables();
            osc_run_hook( "after_html" );
            exit;
        }
    }
