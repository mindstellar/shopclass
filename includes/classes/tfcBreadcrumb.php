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

    use Category;
    use City;
    use Params;
    use Region;
    use Rewrite;
    use View;

    /**
     * Class tfcBreadcrumb
     */
    class tfcBreadcrumb {
        protected $aLevel;
        private $location;
        private $section;
        private $title;

        /**
         * tfcBreadcrumb constructor.
         *
         * @param array $lang
         */
        public function __construct( $lang = array () ) {
            $this->location = Rewrite::newInstance()->get_location();
            $this->section  = Rewrite::newInstance()->get_section();
            $this->aLevel   = array ();
            $this->setTitles( $lang );
        }

        /**
         * Set the texts for the breadcrumb
         *
         * @param $lang
         *
         * @since 3.1
         */
        public function setTitles( $lang ) {
            // default titles
            $this->title[ 'item_add' ]               = __( 'Publish a listing' , 'shopclass' );
            $this->title[ 'item_edit' ]              = __( 'Edit your listing' , 'shopclass' );
            $this->title[ 'item_send_friend' ]       = __( 'Send to a friend' , 'shopclass' );
            $this->title[ 'item_contact' ]           = __( 'Contact publisher' , 'shopclass' );
            $this->title[ 'search' ]                 = __( 'Search results' , 'shopclass' );
            $this->title[ 'search_pattern' ]         = __( 'Search results: %s' , 'shopclass' );
            $this->title[ 'user_dashboard' ]         = __( 'Dashboard' , 'shopclass' );
            $this->title[ 'user_dashboard_profile' ] = __( "%s's profile" , 'shopclass' );
            $this->title[ 'user_account' ]           = __( 'Account' , 'shopclass' );
            $this->title[ 'user_items' ]             = __( 'My listings' , 'shopclass' );
            $this->title[ 'user_alerts' ]            = __( 'My alerts' , 'shopclass' );
            $this->title[ 'user_profile' ]           = __( 'Update my profile' , 'shopclass' );
            $this->title[ 'user_change_email' ]      = __( 'Change my email' , 'shopclass' );
            $this->title[ 'user_change_username' ]   = __( 'Change my username' , 'shopclass' );
            $this->title[ 'user_change_password' ]   = __( 'Change my password' , 'shopclass' );
            $this->title[ 'login' ]                  = __( 'Login' , 'shopclass' );
            $this->title[ 'login_recover' ]          = __( 'Recover your password' , 'shopclass' );
            $this->title[ 'login_forgot' ]           = __( 'Change your password' , 'shopclass' );
            $this->title[ 'register' ]               = __( 'Create a new account' , 'shopclass' );
            $this->title[ 'contact' ]                = __( 'Contact' , 'shopclass' );


            if ( ! is_array( $lang ) ) {
                return;
            }

            foreach ( $lang as $k => $v ) {
                if ( array_key_exists( $k , $this->title ) ) {
                    $this->title[ $k ] = $v;
                }
            }
        }

        /**
         *
         */
        public function init() {
            $l = array (
                'url'   => osc_base_url() ,
                'title' => __( 'Home' , 'shopclass' )
            );
            $this->addLevel( $l );
            switch ( $this->getLocation() ) {
                case( 'item' ):
                    if ( $this->getSection() == 'item_add' ) {
                        $l = array ( 'title' => $this->title[ 'item_add' ] );
                        $this->addLevel( $l );
                        break;
                    }

                    $aCategory = osc_get_category( 'id' , osc_item_category_id() );
                    // remove
                    View::newInstance()->_erase( 'categories' );
                    View::newInstance()->_erase( 'subcategories' );
                    View::newInstance()->_exportVariableToView( 'category' , $aCategory );

                    $parentCat = osc_field( $aCategory , "fk_i_parent_id" , "" );
                    if ( $parentCat ) {
                        $parentCatName = Category::newInstance()->findByPrimaryKey( $parentCat );

                        $l = array (
                            'url'   => osc_search_url( array ( 'sCategory' => $parentCat ) ) ,
                            'title' => ucwords( strtolower( osc_field( $parentCatName , 's_name' , "" ) ) )
                        );
                        $this->addLevel( $l );
                    }
                    $l = array (
                        'url'   => osc_search_category_url() ,
                        'title' => osc_category_name()
                    );
                    $this->addLevel( $l );

                    switch ( $this->getSection() ) {
                        case( 'item_edit' ):
                            $l = array ( 'url' => osc_item_url() , 'title' => osc_item_title() );
                            $this->addLevel( $l );
                            $l = array ( 'title' => $this->title[ 'item_edit' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'send_friend' ):
                            $l = array ( 'url' => osc_item_url() , 'title' => osc_item_title() );
                            $this->addLevel( $l );
                            $l = array ( 'title' => $this->title[ 'item_send_friend' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'contact' ):
                            $l = array ( 'url' => osc_item_url() , 'title' => osc_item_title() );
                            $this->addLevel( $l );
                            $l = array ( 'title' => $this->title[ 'item_contact' ] );
                            $this->addLevel( $l );
                            break;
                        case( '' ):
                            $l = array ( 'title' => osc_item_title() );
                            $this->addLevel( $l );
                            break;
                        default:
                            $l = array ( 'title' => Rewrite::newInstance()->get_title() );
                            $this->addLevel( $l );
                            break;
                    }
                    break;
                case( 'search' ):
                    $region   = osc_search_region();
                    $city     = osc_search_city();
                    $pattern  = osc_search_pattern();
                    $category = osc_search_category_id();
                    $category = ( ( count( $category ) == 1 ) ? $category[ 0 ] : '' );

                    $b_show_all = ( $pattern == '' && $category == '' && $region == '' && $city == '' );
                    $b_category = ( $category != '' );
                    $b_pattern  = ( $pattern != '' );
                    $b_region   = ( $region != '' );
                    $b_city     = ( $city != '' );
                    $b_location = ( $b_region || $b_city );

                    // show all
                    if ( $b_show_all ) {
                        $l = array ( 'title' => $this->title[ 'search' ] );
                        $this->addLevel( $l );
                        break;
                    }

                    // category
                    if ( $b_category ) {
                        $aCategories = Category::newInstance()->toRootTree( $category );
                        foreach ( $aCategories as $c ) {
                            View::newInstance()->_erase( 'categories' );
                            View::newInstance()->_erase( 'subcategories' );
                            View::newInstance()->_exportVariableToView( 'category' , $c );
                            $l = array (
                                'url'   => osc_search_category_url() ,
                                'title' => ucwords( strtolower( osc_category_name() ) )
                            );
                            $this->addLevel( $l );
                        }
                    }

                    // location
                    if ( $b_location ) {
                        $params = array ();
                        if ( $b_category ) {
                            $params[ 'sCategory' ] = $category;
                        }

                        if ( $b_city ) {
                            $aCity = array ();
                            if ( $b_region ) {
                                $_region = Region::newInstance()->findByName( $region );
                                if ( isset( $_region[ 'pk_i_id' ] ) ) {
                                    $aCity = City::newInstance()->findByName( $city , $_region[ 'pk_i_id' ] );
                                }
                            } else {
                                if ( is_numeric( Params::getParam( 'sCity' ) ) ) {
                                    $aCity = City::newInstance()->findByPrimaryKey( Params::getParam( 'sCity' ) );
                                } else {
                                    $aCity = City::newInstance()->findByName( $city );
                                }
                            }

                            if ( count( $aCity ) == 0 ) {
                                $params[ 'sCity' ] = $city;
                                $l                 = array (
                                    'url'   => osc_search_url( $params ) ,
                                    'title' => $city
                                );
                                $this->addLevel( $l );
                            } else {
                                $aRegion = Region::newInstance()->findByPrimaryKey( $aCity[ 'fk_i_region_id' ] );

                                $params[ 'sRegion' ] = $aRegion[ 's_name' ];
                                $l                   = array (
                                    'url'   => osc_search_url( $params ) ,
                                    'title' => $aRegion[ 's_name' ]
                                );
                                $this->addLevel( $l );

                                $params[ 'sCity' ] = $aCity[ 's_name' ];
                                $l                 = array (
                                    'url'   => osc_search_url( $params ) ,
                                    'title' => $aCity[ 's_name' ]
                                );
                                $this->addLevel( $l );
                            }
                        } else if ( $b_region ) {
                            $params[ 'sRegion' ] = $region;
                            $l                   = array (
                                'url'   => osc_search_url( $params ) ,
                                'title' => $region
                            );
                            $this->addLevel( $l );
                        }
                    }

                    // pattern
                    if ( $b_pattern ) {
                        $l = array ( 'title' => sprintf( $this->title[ 'search_pattern' ] , $pattern ) );
                        $this->addLevel( $l );
                    }

                    // remove url from the last node
                    $nodes = $this->getaLevel();
                    if ( $nodes > 0 ) {
                        if ( array_key_exists( 'url' , $nodes[ count( $nodes ) - 1 ] ) ) {
                            unset( $nodes[ count( $nodes ) - 1 ][ 'url' ] );
                        }
                    }
                    $this->setaLevel( $nodes );
                    break;
                case( 'user' ):
                    // use dashboard without url if you're in the dashboards
                    if ( $this->getSection() == 'dashboard' ) {
                        $l = array ( 'title' => $this->title[ 'user_dashboard' ] );
                        $this->addLevel( $l );
                        break;
                    }

                    // use dashboard without url if you're in the dashboards
                    if ( $this->getSection() == 'pub_profile' ) {
                        $l = array ( 'title' => sprintf( $this->title[ 'user_dashboard_profile' ] , osc_user_name() ) );
                        $this->addLevel( $l );
                        break;
                    }

                    $l = array (
                        'url'   => osc_user_dashboard_url() ,
                        'title' => $this->title[ 'user_account' ]
                    );
                    $this->addLevel( $l );

                    switch ( $this->getSection() ) {
                        case( 'items' ):
                            $l = array ( 'title' => $this->title[ 'user_items' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'alerts' ):
                            $l = array ( 'title' => $this->title[ 'user_alerts' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'profile' ):
                            $l = array ( 'title' => $this->title[ 'user_profile' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'change_email' ):
                            $l = array ( 'title' => $this->title[ 'user_change_email' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'change_password' ):
                            $l = array ( 'title' => $this->title[ 'user_change_password' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'change_username' ):
                            $l = array ( 'title' => $this->title[ 'user_change_username' ] );
                            $this->addLevel( $l );
                            break;
                        default:
                            $l = array ( 'title' => Rewrite::newInstance()->get_title() );
                            $this->addLevel( $l );
                            break;
                    }
                    break;
                case( 'login' ):
                    switch ( $this->getSection() ) {
                        case( 'recover' ):
                            $l = array ( 'title' => $this->title[ 'login_recover' ] );
                            $this->addLevel( $l );
                            break;
                        case( 'forgot' ):
                            $l = array ( 'title' => $this->title[ 'login_forgot' ] );
                            $this->addLevel( $l );
                            break;
                        case( '' ):
                            $l = array ( 'title' => $this->title[ 'login' ] );
                            $this->addLevel( $l );
                            break;
                    }
                    break;
                case( 'register' ):
                    $l = array ( 'title' => $this->title[ 'register' ] );
                    $this->addLevel( $l );
                    break;
                case( 'page' ):
                    $l = array ( 'title' => osc_static_page_title() );
                    $this->addLevel( $l );
                    break;
                case( 'contact' ):
                    $l = array ( 'title' => $this->title[ 'contact' ] );
                    $this->addLevel( $l );
                    break;
                case( 'error' ):
                    $l = array ( 'title' => __( 'Error' , 'shopclass' ) . ' 404' );
                    $this->addLevel( $l );
                    break;
                case( 'custom' ):
                    $l = array ( 'title' => Rewrite::newInstance()->get_title() );
                    $this->addLevel( $l );
                    break;
            }
            $this->setaLevel( osc_apply_filter( 'tfc-breadcrumb-custom-level' , $this->aLevel ) );
        }

        /**
         * @param $level
         */
        public function addLevel( $level ) {
            if ( ! is_array( $level ) ) {
                return;
            }
            $this->aLevel[] = $level;
        }

        /**
         * @return string
         */
        public function getLocation() {
            return $this->location;
        }

        /**
         * @param $location
         */
        public function setLocation( $location ) {
            $this->location = $location;
        }

        /**
         * @return string
         */
        public function getSection() {
            return $this->section;
        }

        /**
         * @param $section
         */
        public function setSection( $section ) {
            $this->section = $section;
        }

        /**
         * @return array
         */
        public function getaLevel() {
            return $this->aLevel;
        }

        /**
         * @param $aLevel
         */
        public function setaLevel( $aLevel ) {
            $this->aLevel = $aLevel;
        }

        /**
         * @param string $separator
         *
         * @return string
         */
        public function render( $separator = '&raquo;' ) {
            if ( count( $this->aLevel ) == 0 ) {
                return '';
            }

            $node = array ();
            for ( $i = 0 , $iMax = count( $this->aLevel ); $i < $iMax; $i ++ ) {
                $text = '<li ';
                // set a class style for first and last <li>
                if ( $i == 0 ) {
                    $text .= 'class="first-child" ';
                }
                if ( ( $i == ( count( $this->aLevel ) - 1 ) ) && ( $i != 0 ) ) {
                    $text .= 'class="last-child" ';
                }
                $text .= 'itemscope itemtype="http://data-vocabulary.org/Breadcrumb" >';
                // set separator
                if ( $i > 0 ) {
                    $text .= '' . $separator . '';
                }
                // create span tag
                $title = '<span itemprop="title">' . $this->aLevel[ $i ][ 'title' ] . '</span>';
                if ( array_key_exists( 'url' , $this->aLevel[ $i ] ) ) {
                    $title = '<a href="' . osc_esc_html( $this->aLevel[ $i ][ 'url' ] ) . '" itemprop="url">' . $title . '</a>';
                }
                $node[] = $text . $title . '</li>' . PHP_EOL;
            }

            $result = '<ul class="breadcrumb">' . PHP_EOL;
            $result .= implode( PHP_EOL , $node );
            $result .= '</ul>' . PHP_EOL;

            return $result;
        }
    }