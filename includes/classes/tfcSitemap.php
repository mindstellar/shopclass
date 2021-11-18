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
    use DAO;
    use View;

    /**
     * User: navjottomer
     * Date: 23/04/18
     * Time: 3:39 PM
     */
    class tfcSitemap extends DAO {
        public static $instance;
        public $total_results_table;
        private $itemPageId;

        /**
         * tfcSitemap constructor.
         *
         */
        function __construct() {
            parent::__construct();
        }

        /**
         * @return tfcSitemap
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        public function tfc_generate_category_sitemap() {
            $this->tfc_print_sitemap_header();

            $allcategory = new Category();
            $results     = $allcategory->listWhere( 'i_num_items >0' );
            View::newInstance()->_exportVariableToView( 'categories' , $results );

            if ( osc_count_categories() > 0 ) {
                while ( osc_has_categories() ) {
                    $this->tfc_print_url_sitemap( osc_search_category_url() , date( 'Y-m-d' ) , 'hourly' );
                }
            }
            echo '</urlset>';

        }

        public function tfc_print_sitemap_header() {
            header( "Content-type: text/xml" );
            echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<?xml-stylesheet type="text/xsl" href="' . osc_current_web_theme_url( 'includes/xmlsitemap.xsl' ) . '"?>' . PHP_EOL . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        }

        /**
         * Print URL Sitemap
         *
         * @param string $url
         * @param string $date
         * @param string $freq
         */
        private function tfc_print_url_sitemap( $url = '' , $date = '' , $freq = 'daily' ) {
            if ( filter_var( $url , FILTER_VALIDATE_URL ) ) {

                $xml = '    <url>' . PHP_EOL;
                $xml .= '        <loc>' . htmlentities( $url , ENT_QUOTES , "UTF-8" ) . '</loc>' . PHP_EOL;
                $xml .= '        <lastmod>' . $date . '</lastmod>' . PHP_EOL;
                $xml .= '        <changefreq>' . $freq . '</changefreq>' . PHP_EOL;
                $xml .= '    </url>' . PHP_EOL;
                echo $xml;
            }
        }

        public function tfc_generate_cities_sitemap() {
            $this->tfc_print_sitemap_header();
            if ( osc_count_list_cities() > 0 ) {
                while ( osc_has_list_cities() ) {
                    $this->tfc_print_url_sitemap( osc_list_city_url() , date( 'Y-m-d' ) , 'weekly' );
                }
            }
            echo '</urlset>';
        }

        public function tfc_generate_regions_sitemap() {
            $this->tfc_print_sitemap_header();
            if ( osc_count_list_regions() > 0 ) {
                while ( osc_has_list_regions() ) {
                    $this->tfc_print_url_sitemap( osc_list_region_url() , date( 'Y-m-d' ) , 'weekly' );
                }
            }
            echo '</urlset>';
        }

        public function tfc_generate_countries_sitemap() {
            $this->tfc_print_sitemap_header();
            if ( osc_count_list_countries() > 0 ) {
                while ( osc_has_list_countries() ) {
                    $this->tfc_print_url_sitemap( osc_list_country_url() , date( 'Y-m-d' ) , 'weekly' );
                }
            }
            echo '</urlset>';
        }

        public function tfc_generate_cat_region_sitemap() {
            set_time_limit( 160 );
            $sitemap_items = new DAO;
            $sitemap_items->dao->select( 'Distinct a.fk_i_category_id,b.fk_i_region_id' );
            $sitemap_items->dao->from( DB_TABLE_PREFIX . "t_item AS a" );
            $sitemap_items->dao->join( DB_TABLE_PREFIX . "t_item_location AS b" , "a.pk_i_id = b.fk_i_item_id" , "LEFT" );
            $sitemap_items->dao->where( "a.b_enabled=1" );
            $sitemap_items->dao->where( "a.b_active=1" );
            $sitemap_items->dao->where( "a.b_spam= 0" );
            $sitemap_items->dao->where( "a.fk_i_category_id IS NOT NULL" );
            $sitemap_items->dao->where( "b.fk_i_region_id IS NOT NULL" );
            $sitemap_items->dao->orderBy( "a.pk_i_id" , "DESC" );
            $result  = $sitemap_items->dao->get();
            $results = $result->result();

            $this->tfc_print_sitemap_header();
            foreach ( $results as $result ) {

                $this->tfc_print_url_sitemap( osc_esc_html( osc_update_search_url( array (
                                                                                       'action_specific' => '' ,
                                                                                       'CSRFName'        => '' ,
                                                                                       'CSRFToken'       => '' ,
                                                                                       'route'           => '' ,
                                                                                       'sCategory'       => $result[ 'fk_i_category_id' ] ,
                                                                                       'sRegion'         => $result[ 'fk_i_region_id' ]
                                                                                   ) ) ) , date( 'Y-m-d' ) , 'weekly' );
            }

            echo '</urlset>';
        }

        public function tfc_generate_cat_city_sitemap() {
            set_time_limit( 160 );
            $sitemap_items = new DAO;
            $sitemap_items->dao->select( 'Distinct a.fk_i_category_id,b.fk_i_city_id' );
            $sitemap_items->dao->from( DB_TABLE_PREFIX . "t_item AS a" );
            $sitemap_items->dao->join( DB_TABLE_PREFIX . "t_item_location AS b" , "a.pk_i_id = b.fk_i_item_id" , "LEFT" );
            $sitemap_items->dao->where( "a.b_enabled=1" );
            $sitemap_items->dao->where( "a.b_active=1" );
            $sitemap_items->dao->where( "a.b_spam= 0" );
            $sitemap_items->dao->where( "a.fk_i_category_id IS NOT NULL" );
            $sitemap_items->dao->where( "b.fk_i_city_id IS NOT NULL" );
            $sitemap_items->dao->orderBy( "a.pk_i_id" , "DESC" );
            $result  = $sitemap_items->dao->get();
            $results = $result->result();

            $this->tfc_print_sitemap_header();
            foreach ( $results as $result ) {

                $this->tfc_print_url_sitemap( osc_esc_html( osc_update_search_url( array (
                                                                                       'action_specific' => '' ,
                                                                                       'CSRFName'        => '' ,
                                                                                       'CSRFToken'       => '' ,
                                                                                       'route'           => '' ,
                                                                                       'sCategory'       => $result[ 'fk_i_category_id' ] ,
                                                                                       'sCity'           => $result[ 'fk_i_city_id' ]
                                                                                   ) ) ) , date( 'Y-m-d' ) , 'weekly' );
            }

            echo '</urlset>';
        }

        public function tfc_generate_page_sitemap() {
            $this->tfc_print_sitemap_header();
            // INDEX
            $this->tfc_print_url_sitemap( osc_base_url() , date( 'Y-m-d' ) , 'always' );
            $this->tfc_print_url_sitemap( osc_search_url() , date( 'Y-m-d' ) , 'always' );
            if ( osc_count_static_pages() > 0 ) {
                while ( osc_has_static_pages() ) {
                    $lastmod = substr( osc_static_page_pub_date() != '' ? osc_static_page_mod_date() : osc_static_page_pub_date() , 0 , 10 );
                    $this->tfc_print_url_sitemap( osc_static_page_url() , $lastmod , 'monthly' );
                }
            }
            if ( osc_get_preference( 'custom_urls' , 'shopclass_theme' ) ) {
                $array_custom_url = json_decode( osc_get_preference( 'custom_urls' , 'shopclass_theme' ) , true );
                foreach ( $array_custom_url as $custom_pages ) {
                    $this->tfc_print_url_sitemap( $custom_pages[ "url" ] , $custom_pages[ "lastmod" ] , $custom_pages[ "freq" ] );
                }

            }
            echo '</urlset>';

        }

        /**
         * Generate Item Sitemap page
         */
        public function tfc_generate_item_sitemap() {


            $page_number = $this->itemPageId;

            $numurl = osc_get_preference( 'sitemap_number' , 'shopclass_theme' );

            set_time_limit( 180 );
            $this->dao->select( 'pk_i_id, fk_i_category_id,dt_pub_date, dt_mod_date' );
            $this->dao->from( DB_TABLE_PREFIX . 't_item' );
            $this->dao->where( 'b_enabled = 1' );
            $this->dao->where( 'b_active=1' );
            $this->dao->where( 'b_spam=0' );
            $this->dao->where( 'dt_expiration >= \'' . date( 'Y-m-d H:i:s' ) . '\'' );
            $this->dao->orWhere( 'b_premium = 1' );
            $this->dao->orderby( "pk_i_id" , "ASC" );
            $this->dao->limit( $page_number * $numurl , $numurl );

            $filterItemSubQuery = $this->dao->_getSelect();

            $this->dao->_resetSelect();

            $this->dao->select( 't.pk_i_id,t.fk_i_category_id,t.dt_pub_date,t.dt_mod_date,d.s_title,l.s_city' );
            $this->dao->from( '(' . $filterItemSubQuery . ') AS t' );
            $this->dao->join( DB_TABLE_PREFIX . "t_item_description AS d" , "t.pk_i_id = d.fk_i_item_id" , "LEFT" );
            $this->dao->join( DB_TABLE_PREFIX . "t_item_location AS l" , "d.fk_i_item_id = l.fk_i_item_id" , "LEFT" );

            $this->dao->where( "d.s_title is not null " );
            //$this->dao->where("t.pk_i_id >=".$page_number*$numurl);
            $result = $this->dao->get();

            $results = $result->result();

            $freq = 'weekly';
            $this->tfc_print_sitemap_header();

            foreach ( $results as $result ) {

                $sCity = ( isset( $result[ 's_city' ] ) ) ? $result[ 's_city' ] : '';

                $lastmod = $result[ 'dt_mod_date' ] > $result[ 'dt_pub_date' ] ? strtotime( $result[ 'dt_mod_date' ] ) : strtotime( $result[ 'dt_pub_date' ] );

                $creat = " <url>" . PHP_EOL;
                $creat .= "   <loc>" . $this->tfc_sitemap_item_url( $result[ 'pk_i_id' ] , $result[ 's_title' ] , $result[ 'fk_i_category_id' ] , $sCity ) . "</loc>" . PHP_EOL;
                $creat .= "   <changefreq>" . $freq . "</changefreq>" . PHP_EOL;
                $creat .= "   <lastmod>" . date( 'c' , $lastmod ) . "</lastmod>" . PHP_EOL;
                $creat .= " </url>" . PHP_EOL;

                echo $creat;
            }
            echo '</urlset>';

        }

        /**
         * @param $itemId
         * @param $itemTitle
         * @param string $itemCategory
         * @param string $itemCity
         * @param string $locale
         *
         * @return string
         */
        public function tfc_sitemap_item_url( $itemId , $itemTitle , $itemCategory = '' , $itemCity = '' , $locale = '' ) {
            if ( osc_rewrite_enabled() ) {
                $url = osc_get_preference( 'rewrite_item_url' );
                //$url = str_replace('/', '', $url);
                if ( preg_match( '{CATEGORIES}' , $url ) ) {
                    $sanitized_categories = array ();
                    $cat                  = Category::newInstance()->hierarchy( $itemCategory );
                    for ( $i = ( count( $cat ) ); $i > 0; $i -- ) {
                        $sanitized_categories[] = $cat[ $i - 1 ][ 's_slug' ];
                    }
                    $url = str_replace( '{CATEGORIES}' , implode( "/" , $sanitized_categories ) , $url );
                }
                $url = str_replace( '{ITEM_ID}' , osc_sanitizeString( $itemId ) , $url );
                $url = str_replace( '{ITEM_CITY}' , osc_sanitizeString( $itemCity ) , $url );
                $url = str_replace( '{ITEM_TITLE}' , osc_sanitizeString( $itemTitle ) , $url );
                $url = str_replace( '?' , '' , $url );
                if ( $locale != '' ) {
                    $path = osc_base_url() . $locale . "/" . $url;
                } else {
                    $path = osc_base_url() . $url;
                }
            } else {
                $path = osc_item_url_ns( $itemId , $locale );
            }

            return htmlentities( $path , ENT_QUOTES , "UTF-8" );
        }

        /**
         * Generate Sitemap Index for Site
         */
        public function tfc_generate_sitemapindex() {
            header( "Content-type: text/xml" );
            echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . '<?xml-stylesheet type="text/xsl" href="' . osc_current_web_theme_url( 'includes/xmlsitemapindex.xsl' ) . '"?>' . PHP_EOL . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

            $this->countAllActiveItems();
            $numurl            = osc_get_preference( 'sitemap_number' , 'shopclass_theme' );
            $item_pages_number = ceil( $this->total_results_table / $numurl );
            for ( $i = 0; $i < $item_pages_number; $i ++ ) {
                //$moddate = $this->getModificationDateItem( $i);
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-item' , array ( 'itemSitemapID' => $i ) ) , date( 'Y-m-d' ) );
            }
            $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-category' ) , date( 'Y-m-d' ) );
            //Generate Category+Region Sitemap
            if ( osc_get_preference( 'sitemap_cat_regions' , 'shopclass_theme' ) ) {
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-cat-regions' ) , date( 'Y-m-d' ) );
            }
            //Generate Cateogry+City sitemap
            if ( osc_get_preference( 'sitemap_cat_city' , 'shopclass_theme' ) ) {
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-cat-cities' ) , date( 'Y-m-d' ) );
            }
            if ( osc_get_preference( 'sitemap_cities' , 'shopclass_theme' ) ) {
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-cities' ) , date( 'Y-m-d' ) );
            }
            if ( osc_get_preference( 'sitemap_regions' , 'shopclass_theme' ) ) {
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-regions' ) , date( 'Y-m-d' ) );
            }
            if ( osc_get_preference( 'sitemap_countries' , 'shopclass_theme' ) ) {
                $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-countries' ) , date( 'Y-m-d' ) );
            }

            $this->tfc_print_index_url( osc_route_url( 'tfc-render-sitemap-pages' ) , $this->getModificationDatePages() );

            echo '</sitemapindex>';

        }

        /**
         * Return number of active items
         * @return mixed
         */
        public function countAllActiveItems() {
            $countquery = "SELECT count(*) AS total FROM " . DB_TABLE_PREFIX . "t_item ";
            $countquery .= "WHERE b_enabled = 1 ";
            $countquery .= "AND b_active = 1 ";
            $countquery .= "AND b_spam = 0 ";
            $countquery .= "AND  dt_expiration >= '" . date( 'Y-m-d H:i:s' ) . "'";
            $countquery .= " OR b_premium = 1";
            //return $countquery;
            if ( is_null( $this->total_results_table ) ) {
                $result = $this->dao->query( $countquery );

                $row                       = $result->row();
                $this->total_results_table = $row[ 'total' ];
            }

            return $this->total_results_table;
        }

        /**
         * @param string $url
         * @param string $lastmod
         *
         * @return string
         */
        private function tfc_print_index_url( $url = '' , $lastmod = '' ) {
            if ( filter_var( $url , FILTER_VALIDATE_URL ) ) {

                $xml = '    <sitemap>' . PHP_EOL;
                $xml .= '        <loc>' . htmlentities( $url , ENT_QUOTES , "UTF-8" ) . '</loc>' . PHP_EOL;
                $xml .= '        <lastmod>' . $lastmod . '</lastmod>' . PHP_EOL;
                $xml .= '    </sitemap>' . PHP_EOL;

                echo $xml;
            }

            return null;
        }

        /**
         * Get Page Sitemap Modification Date
         * @return mixed
         */
        public function getModificationDatePages() {

            $query  = 'SELECT max(dt_pub_date) as max_pub_date, max(dt_mod_date) as max_mod_date ';
            $query  .= 'FROM ' . DB_TABLE_PREFIX . 't_pages ';
            $query  .= ' WHERE b_indelible < 1 ';
            $result = $this->dao->query( $query );
            $row    = $result->row();
            if ( $row[ 'max_pub_date' ] > $row[ 'max_mod_date' ] ) {
                $newdate = $row[ 'max_pub_date' ];
            } else {
                $newdate = $row[ 'max_mod_date' ];
            }

            if ( osc_get_preference( 'custom_urls' , 'shopclass_theme' ) ) {
                $array_custom_url = json_decode( osc_get_preference( 'custom_urls' , 'shopclass_theme' ) , true );
                foreach ( $array_custom_url as $custom_pages ) {
                    if ( $custom_pages[ "lastmod" ] > $newdate ) {
                        $newdate = $custom_pages[ "lastmod" ];
                    }
                }

            }
            $newdate = date( 'c' , strtotime( $newdate ) );

            return $newdate;

        }

        /**
         * Get Item Sitemap Modification Date
         *
         * @param $page_number
         *
         * @return mixed
         */
        public function getModificationDateItem( $page_number ) {
            $numurl = osc_get_preference( 'sitemap_number' , 'shopclass_theme' );
            $query  = 'SELECT max(dt_pub_date) as max_pub_date, max(dt_mod_date) as max_mod_date ';
            $query  .= 'FROM( Select dt_pub_date, dt_mod_date FROM ' . DB_TABLE_PREFIX . 't_item ';
            $query  .= ' WHERE b_enabled=1 AND b_active =1 AND b_spam = 0 AND dt_expiration >= \'' . date( 'Y-m-d H:i:s' ) . '\' ';
            $query  .= ' OR b_premium = 1 ORDER BY pk_i_id ASC LIMIT ' . $page_number * $numurl . ' ,' . $numurl . ') AS myitems';
            $result = $this->dao->query( $query );
            $row    = $result->row();
            if ( $row[ 'max_pub_date' ] > $row[ 'max_mod_date' ] ) {
                return $row[ 'max_pub_date' ];
            } else {
                return $row[ 'max_mod_date' ];
            }

        }

        /**
         * @param mixed $itemPageId
         */
        public function setItemPageId( $itemPageId ) {
            $this->itemPageId = $itemPageId;
        }

    }