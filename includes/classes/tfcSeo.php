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

    /**
     * User: navjottomer
     * Date: 03/04/19
     * Time: 3:52 PM
     */

    namespace shopclass\includes\classes;


    use Rewrite;

    /**
     * Class tfcSeo
     * @package shopclass\includes\classes
     */
    class tfcSeo {
        private static $instance;
        private $location;
        private $section;
        private $brandName;
        private $title;
        private $description;
        private $seo_enabled;

        /**
         * tfcSeo constructor.
         *
         */
        public function __construct() {
            $this->seo_enabled = tfc_getPref( 'enable_seo' );
            $this->location    = Rewrite::newInstance()->get_location();
            $this->section     = Rewrite::newInstance()->get_section();
            $this->brandName   = tfc_getPref( 'add-brand-to-title' );
        }

        /**
         * @return tfcSeo
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        public function __invoke() {
            $this->run_seo();
        }

        private function run_seo() {
            if ( $this->seo_enabled ) {
                $this->generate_title_description();

                if ( ! empty( $this->title ) ) {
                    $title_filter = function () {
                        return $this->title;
                    };
                    osc_add_filter( 'meta_title_filter' , $title_filter );
                }
                if ( ! empty( $this->description ) ) {
                    $description_filter = function () {
                        return $this->description;
                    };
                    osc_add_filter( 'meta_description_filter' , $description_filter );
                }
            }
        }

        /**
         *
         */
        private function generate_title_description() {
            $brand = true;
            switch ( $this->location ) {
                case( '' ):
                    switch ( $this->section ) {
                        case( '' ):
                            //$seodetail        = tfc_seo_get_row( osc_item_id() );
                            $title_text       = tfc_getPref( 'homepage_title' , true );
                            $description_text = tfc_getPref( 'homepage_desc' , true );
                            $brand            = false;
                            break;
                    }
                    break;
                case( 'item' ):
                    switch ( $this->section ) {
                        case '':
                            $title_text       = osc_item_title();
                            $description_text = osc_item_description();

                            if ( tfc_getPref( 'item-title-add-city-cat' ) == 'before-title' ) {
                                $title_text = osc_item_category() . ' ' . osc_item_city() . ' ' . $title_text;
                            } elseif ( tfc_getPref( 'item-title-add-city-cat' ) == 'after-title' ) {
                                $title_text = $title_text . ' ' . osc_item_category() . ' ' . osc_item_city();
                            }

                            break;
                        case 'item_add':
                            $title_text       = tfc_getPref( 'publishpage_title' , true );
                            $description_text = tfc_getPref( 'publishpage_desc' , true );
                            break;
                        case 'item_edit':
                            $title_text       = tfc_getPref( 'editpage_title' , true );
                            $description_text = tfc_getPref( 'editpage_desc' , true );
                            break;
                        case 'send_friend':
                            $title_text       = __( 'Send to a friend' , 'shopclass' ) . ' - ' . osc_item_title();
                            $description_text = osc_item_description();
                            break;
                        case 'contact':
                            $title_text       = __( 'Contact seller' , 'shopclass' ) . ' - ' . osc_item_title();
                            $description_text = osc_item_description();
                            break;
                    }
                    break;
                case( 'page' ):
                    $title_text       = osc_static_page_title();
                    $description_text = $text = osc_highlight( osc_static_page_text() , 140 , '' , '' );
                    break;
                case( 'error' ):
                    $title_text = __( 'Error' , 'shopclass' );
                    break;
                case( 'search' ):
                    $country  = osc_search_country();
                    $region   = osc_search_region();
                    $city     = osc_search_city();
                    $pattern  = osc_search_pattern();
                    $category = osc_search_category_id();
                    $s_page   = '';
                    /** @var array $params */
                    $params = \Params::getParamsAsArray();
                    $i_page = ( isset( $params[ 'iPage' ] ) ? $params[ 'iPage' ] : '' );

                    if ( $i_page != '' && $i_page > 1 ) {
                        $s_page = __( 'Page' , 'shopclass' ) . ' ' . $i_page . ': ';
                    }

                    //$b_show_all = ( $region == '' && $city == '' & $pattern == '' && $category == '' );
                    $b_category = ( $category != '' );
                    $b_pattern  = ( $pattern != '' );
                    $b_city     = ( $city != '' );
                    $b_region   = ( $region != '' );
                    $b_country  = ( $country != '' );

                    $title       = '';
                    $description = '';

                    if ( $b_pattern ) {
                        $title       .= $pattern . ' &raquo; ';
                        $description = __( 'Find latest ' , 'shopclass' ) . ' ' . $pattern . ' ';
                    }

                    if ( $b_category && is_array( $category ) && count( $category ) > 0 ) {
                        $cat = \Category::newInstance()->findByPrimaryKey( array_pop( $category ) , osc_current_user_locale() );
                        if ( $cat ) {
                            $title       .= __( 'Latest classified ads ' , 'shopclass' ) . ' ' . ucwords( strtolower( $cat[ 's_name' ] ) ) . ' ';
                            $description .= ( isset( $cat[ 's_description' ] ) && trim( $cat[ 's_description' ] ) ) ? $cat[ 's_description' ] . ' ' : __( 'Search and Find latest classified listings In' , 'shopclass' ) . ' ' . ucwords( strtolower( $cat[ 's_name' ] ) ) . ' ';
                        }
                        unset ( $cat );
                    }

                    if ( $b_city && $b_region && $b_country ) {
                        $title       .= __( 'In' , 'shopclass' ) . ' ' . $city . ' ' . __( 'In' , 'shopclass' ) . ' ' . $region . ' ' . __( 'In' , 'shopclass' ) . ' ' . $country;
                        $description .= __( 'At' , 'shopclass' ) . ' ' . $city . ',' . $region . ',' . $country . ' ';
                    } else if ( $b_city ) {
                        $title       .= $city . ' ';
                        $description .= __( 'At' , 'shopclass' ) . ' ' . $city . ' ';
                    } else if ( $b_region ) {
                        $title       .= __( 'In' , 'shopclass' ) . ' ' . $region . ' ';
                        $description .= __( 'At' , 'shopclass' ) . ' ' . $region . ' ';
                    } else if ( $b_country ) {
                        $title       .= __( 'In' , 'shopclass' ) . ' ' . $country . ' ';
                        $description .= __( 'At' , 'shopclass' ) . ' ' . $country . ' ';
                    }
                    //???
                    //$title = str_replace('&raquo;',' ',$title);

                    if ( $title == '' ) {
                        $title       = __( 'Latest Classified Ads' , 'shopclass' );
                        $description = __( 'Find and search all latest classified ads.' , 'shopclass' );
                    }


                    if ( osc_get_preference( 'seo_title_keyword' ) != '' ) {
                        $title       .= osc_get_preference( 'seo_title_keyword' ) . ' ';
                        $description .= osc_get_preference( 'seo_title_keyword' ) . ' ';
                    }

                    if ( trim( $title ) ) {
                        $title = $s_page . $title;
                    }
                    if ( trim( $description ) ) {
                        $description = $s_page . $description;
                    }


                    $title_text       = $title;
                    $description_text = $description;

                    break;
                case( 'login' ):
                    switch ( $this->section ) {
                        case( 'recover' ):
                            $title_text = __( 'Recover your password' , 'shopclass' );
                            break;
                        default:
                            $title_text       = tfc_getPref( 'loginpage_title' , true );
                            $description_text = tfc_getPref( 'loginpage_desc' , true );
                            break;
                    }
                    break;
                case( 'register' ):
                    $title_text       = tfc_getPref( 'registerpage_title' , true );
                    $description_text = tfc_getPref( 'registerpage_desc' , true );
                    break;
                case( 'user' ):
                    switch ( $this->section ) {
                        case( 'dashboard' ):
                            $title_text = __( 'User Dashboard' , 'shopclass' );
                            break;
                        case( 'items' ):
                            $title_text = __( 'Manage listings' , 'shopclass' );
                            break;
                        case( 'alerts' ):
                            $title_text = __( 'Manage alerts' , 'shopclass' );
                            break;
                        case( 'profile' ):
                            $title_text = __( 'Update profile' , 'shopclass' );
                            break;
                        case( 'pub_profile' ):
                            $title_text = __( 'Public profile' , 'shopclass' ) . ' - ' . osc_user_name();
                            break;
                        case( 'change_email' ):
                            $title_text = __( 'Change email' , 'shopclass' );
                            break;
                        case( 'change_password' ):
                            $title_text = __( 'Change password' , 'shopclass' );
                            break;
                        case( 'forgot' ):
                            $title_text = __( 'Recover password' , 'shopclass' );
                            break;
                    }
                    break;
                case( 'contact' ):
                    $title_text       = tfc_getPref( 'contactpage_title' , true );
                    $description_text = tfc_getPref( 'contactpage_desc' , true );
                    break;
            }

            if ( isset( $title_text ) ) {
                if ( trim( $title_text ) ) {
                    if ( $brand && tfc_getPref( 'add-brand-to-title' ) ) {
                        $title_text = $title_text . ' ' . tfc_getPref( 'add-brand-to-title' );
                    }
                    $this->title = tfcFormatting::newInstance()->sanatize_text( $title_text );
                }
            }
            if ( isset( $description_text ) && trim( $description_text ) ) {
                $this->description = tfcFormatting::newInstance()->sanatize_text( osc_highlight( $description_text , 140 , '' , '' ) );
            }
        }

        /**
         * @return mixed
         */
        public function getTitle() {
            return $this->title;
        }

        /**
         * @return mixed
         */
        public function getDescription() {
            return $this->description;
        }
    }