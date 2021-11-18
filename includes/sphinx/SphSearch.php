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

    use Category;
    use City;
    use Item;
    use Params;
    use Region;
    use Search;

    /**
     * Created by Navjot Tomer.
     * User: navjottomer
     * Date: 17/10/17
     * Time: 11:33 AM
     * @property SphinxQL sphinx Not all method tested fully, but pretty usable for current
     */
    class SphSearch extends SphinxQL {

        private static $instance;
        public $query;
        private $conditions;
        private $itemConditions;
        private $sql;
        private $order_column;
        private $order_direction;
        private $limit_init;
        private $results_per_page;
        private $cities;
        private $regions;
        private $countries;
        private $categories;
        private $sPattern;
        private $sEmail;
        private $withPattern;
        private $withPicture;
        private $withLocations;
        private $withCategoryId;
        private $withUserId;
        //private $withItemId;
        private $withNoUserEmail;
        private $onlyPremium;
        private $price_min;
        private $price_max;
        private $user_ids;
        private $itemId;
        private $expired;
        private $dao;
        private $oSearch;
        private $total_results;
        private $query_time;
        private $query_keyword;
        private $premiumSql;
        /**
         * @var int
         */
        private $total_results_table;
        //private $newdao;
        //private $makeSql;
        //private $regionsId;
        //private $citiesId;

        /**
         * SphSearch constructor.
         *
         * @param string $server
         * @param string $port
         * @param bool $expired
         */
        public function __construct( $server = '' , $port = '' , $expired = false ) {
            if ( empty( $server ) ) {
                $server = SPHINX_HOST;
            }
            if ( empty( $port ) ) {
                $port = SPHINX_MYSQL_PORT;
            }
            parent::__construct( $server , $port );


            $this->withPattern     = false;
            $this->withLocations   = false;
            $this->withCategoryId  = false;
            $this->withUserId      = false;
            $this->withPicture     = false;
            $this->withNoUserEmail = false;
            $this->onlyPremium     = false;
            $this->total_results   = null;
            $this->query_keyword   = null;
            $this->query_time      = null;

            $this->price_min = null;
            $this->price_max = null;

            $this->user_ids = null;
            $this->itemId   = null;


            $this->cities     = array ();
            $this->regions    = array ();
            $this->countries  = array ();
            $this->categories = array ();
            $this->conditions = array ();

            $this->itemConditions = array ();
            $this->query          = $this->getQuery();
            $indexes              = explode( " " , SPHINX_ALL_SEARCH_INDEX );
            $index1               = $indexes[ 0 ];
            $index2               = $indexes[ 1 ];
            $this->query->addIndex( $index1 );
            $this->query->addIndex( $index2 );

            $this->order( $this->order_direction , $this->order_column );
            $this->limit();
            $this->results_per_page = 10;

            $this->expired = $expired;
            $this->oSearch = Search::newInstance();

        }

        /**
         * @param string $o_c
         * @param string $o_d
         *
         * @return $this
         */
        public function order( $o_c = 'dt_pub_date' , $o_d = 'DESC' ) {
            $this->order_column    = $o_c;
            $this->order_direction = $o_d;

            return $this;
        }

        /**
         * @param int $l_i
         * @param null $r_p_p
         *
         * @return $this
         */
        public function limit( $l_i = 0 , $r_p_p = null ) {
            $this->limit_init = $l_i;
            if ( $r_p_p != null ) {
                $this->results_per_page = $r_p_p;
            };

            return $this;
        }

        /**
         * Return an array with columns allowed for sorting
         *
         * @return array
         */
        public static function getAllowedColumnsForSorting() {
            return ( array ( 'i_price' , 'dt_pub_date' , 'dt_expiration' , 'relavance' , 'rank' ) );
        }

        /**
         * Return an array with type of sorting
         *
         * @return array
         */
        public static function getAllowedTypesForSorting() {
            return ( array ( 0 => 'asc' , 1 => 'desc' ) );
        }

        /**
         * @param $description
         *
         * @return mixed
         */
        static function tfc_build_excerpt( $description ) {
            return self::newInstance()->getExcerpt( $description );
        }

        /**
         * @param $description
         *
         * @return mixed
         *
         */
        public function getExcerpt( $description ) {

            $description = preg_replace( '/[^a-zA-Z0-9.,]/' , ' ' , strip_tags( html_entity_decode( $description ) ) );
            $description = $this->escapeString( $description );

            $sPattern = $this->escapeString( $this->sPattern );
            $sPattern = html_entity_decode( $sPattern , ENT_QUOTES );

            $indexes = explode( " " , SPHINX_ALL_SEARCH_INDEX );
            $index1  = $indexes[ 0 ];
            $query   = $this->fromString( $this->query->_buildCallSnippets( $sPattern , $description , $index1 ) );
            //echo $this->query->_buildCallSnippets($sPattern,$description,$index1);

            $this->query( $query );
            $resultdata = $this->fetchAll();
            $val        = $resultdata[ '0' ];
            $val        = $val[ 'snippet' ];

            return $val;
        }

        /**
         * @return SphSearch
         */
        public static function newInstance() {
            if ( ! self::$instance instanceof self ) {
                self::$instance = new self;
            }

            return self::$instance;
        }

        /**
         * Create title for search pages
         * @return string
         */
        static function sphinx_search_title() {
            $i_page = Params::getParam( 'iPage' );

            $sphinx_search_title = "";

            if ( $i_page != '' && $i_page > 1 ) {
                $s_page              = __( 'Page' , 'shopclass' ) . ' ' . $i_page . ': ';
                $sphinx_search_title .= $s_page;
            }
            if ( Params::getParam( 'sPattern' ) ) {
                $sphinx_search_title .= osc_search_total_items() . ' ' . __( 'Ads Found' , 'shopclass' );
                $sphinx_search_title .= ' ' . __( 'for' , 'shopclass' ) . ' "' . Params::getParam( 'sPattern' ) . '"';

            } else {
                if ( ! Params::getParam( 'sCategory' ) ) {
                    $sphinx_search_title .= ' ' . __( 'Latest Classified Ads' , 'shopclass' ) . ' ';
                }
            }
            if ( Params::getParam( 'sCategory' ) ) {
                $sphinx_search_title .= ' ' . ucwords( strtolower( osc_category_name() ) );
            }
            if ( Params::getParam( 'sCountry' ) || Params::getParam( 'sRegion' ) || Params::getParam( 'sCity' ) ) {
                $sphinx_search_title .= ' ' . __( 'In' , 'shopclass' ) . ' ' . osc_search_city() . ' ' . osc_search_region() . ' ' . osc_search_country();

            }

            return $sphinx_search_title;

        }

        /**
         * @param null $category
         *
         * @return bool
         */
        public function addCategory( $category = null ) {
            if ( $category == null ) {
                return false;
            }

            if ( ! is_numeric( $category ) ) {
                $category  = preg_replace( '|/$|' , '' , $category );
                $aCategory = explode( '/' , $category );
                $category  = Category::newInstance()->findBySlug( $aCategory[ count( $aCategory ) - 1 ] );

                if ( count( $category ) == 0 ) {
                    return false;
                }

                $category = $category[ 'pk_i_id' ];
            }
            $tree = Category::newInstance()->toSubTree( $category );
            if ( ! in_array( $category , $this->categories ) ) {
                $this->categories[] = $category;
            }
            $this->pruneBranches( $tree );

            return true;
        }

        /**
         * @param null $branches
         *
         * @return $this
         */
        private function pruneBranches( $branches = null ) {
            if ( $branches != null ) {
                foreach ( $branches as $branch ) {
                    if ( ! in_array( $branch[ 'pk_i_id' ] , $this->categories ) ) {
                        $this->categories[] = $branch[ 'pk_i_id' ];
                        if ( isset( $branch[ 'categories' ] ) ) {
                            $this->pruneBranches( $branch[ 'categories' ] );
                        }
                    }
                }
            }

            return $this;
        }

        /**
         * @param null $id
         *
         * @return $this
         */
        public function fromUser( $id = null ) {
            $this->user_ids[] = $id;

            return $this;
        }

        /**
         * @param $city
         *
         * @return $this
         */
        public function addCity( $city ) {
            if ( ! is_numeric( $city ) ) {
                $city = City::newInstance()->findByName( $city );


                $this->cities[] = $city[ 'pk_i_id' ];
            } else {
                $this->cities[] = $city;
            }
            $this->withLocations = true;

            return $this;
        }

        /**
         * @param $region
         *
         * @return $this
         */
        public function addRegion( $region ) {
            if ( ! is_numeric( $region ) ) {
                $region = Region::newInstance()->findByName( $region );

                $this->regions[] = $region[ 'pk_i_id' ];
            } else {
                $this->regions[] = $region;
            }
            $this->withLocations = true;

            return $this;
        }

        /**
         * @param $country
         *
         * @return $this
         */
        public function addCountry( $country ) {
            $this->countries[]   = crc32( strtolower( $country ) );
            $this->withLocations = true;

            return $this;
        }

        /**
         * @param $Pattern
         *
         * @return $this
         */
        public function addPattern( $Pattern ) {
            $this->withPattern = true;
            $this->sPattern    = $this->escapeString( $Pattern );

            return $this;
        }

        /**
         * @param $string
         *
         * @return mixed
         */
        public function escapeString( $string ) {
            $qoute_count = substr_count( $string , '"' );
            /** @noinspection SpellCheckingInspection */
            if ( $qoute_count % 2 ) {
                $string = str_replace( '"' , ' ' , $string );
            }
            $from   = array (
                '\\' ,
                '(' ,
                ')' ,
                '!' ,
                '~' ,
                '&' ,
                '/' ,
                '^' ,
                '$' ,
                '=' ,
                "'" ,
                "\x00" ,
                "\n" ,
                "\r" ,
                "\x1a"
            );
            $to     = array (
                '\\\\' ,
                '\\\(' ,
                '\\\)' ,
                '\\\!' ,
                '\\\@' ,
                '\\\~' ,
                '\\\&' ,
                '\\\/' ,
                '\\\^' ,
                '\\\$' ,
                '\\\=' ,
                "\\'" ,
                "\\x00" ,
                "\\n" ,
                "\\r" ,
                "\\x1a"
            );
            $string = str_replace( $from , $to , $string );

            return str_replace( "'" , "\'" , $string );
        }

        /**
         * @param $Pattern
         *
         * @return $this
         */
        public function addCustomPattern( $Pattern ) {
            $this->withPattern = true;
            $this->sPattern    = $Pattern;

            return $this;
        }

        /**
         * @param $true
         *
         * @return $this
         */
        public function withPicture( $true ) {
            $this->withPicture = $true;

            return $this;
        }

        /**
         * @param $true
         *
         * @return $this
         */
        public function onlyPremium( $true ) {
            $this->onlyPremium = $true;

            return $this;
        }

        /**
         * @param $p_sPriceMin
         * @param $p_sPriceMax
         *
         * @return $this
         */
        public function priceRange( $p_sPriceMin , $p_sPriceMax ) {

            if ( is_numeric( $p_sPriceMax ) ) {
                $this->price_max = 1000000 * $p_sPriceMax;
            }
            if ( is_numeric( $p_sPriceMin ) ) {
                $this->price_min = 1000000 * $p_sPriceMin;
            }

            return $this;
        }

        /**
         * @param $r_p_p
         *
         * @return $this
         */
        public function set_rpp( $r_p_p ) {
            $this->results_per_page = $r_p_p;

            return $this;
        }

        /**
         * @param int $p
         * @param null $r_p_p
         *
         * @return $this
         */
        public function page( $p = 0 , $r_p_p = null ) {
            if ( $r_p_p != null ) {
                $this->results_per_page = $r_p_p;
            };
            $this->limit_init = $this->results_per_page * $p;

            return $this;
        }

        /**
         * @return null|string
         */
        public function _toString() {

            $query = $this->sql;

            return $query;
        }

        /**
         * @param bool $convert
         *
         * @return mixed|string
         */
        public function toJson( $convert = false ) {
            return $this->oSearch->toJson( $convert );
        }

        /**
         * @param $aData
         */
        public function setJsonAlert( $aData ) {
            return $this->oSearch->setJsonAlert( $aData );
        }

        /**
         * @param int $max
         *
         * @param bool $extended
         *
         * @param bool $count
         *
         * @return array
         */
        public function getPremiums( $max = 2 , $extended = true , $count = true ) {
            //return $results;
            $sql = $this->_makePremiumSQL( $max );
            //$this->sql = $sql;
            //echo $sql;
            $this->query( $sql );
            $resultdata  = $this->fetchAll();
            $meta        = $this->getMeta();
            $total_found = $meta[ 'total_found' ];
            //$this->query_time = $meta[ 'time' ];
            //$this->query_keyword = $meta[ 'keyword' ];
            $resultIds = $this->fetchField( 'id' , $resultdata );

            if ( $count ) {
                $this->total_results = $total_found;

            } else {
                $this->total_results = 0;
            }

            if ( $resultIds == false ) {
                return array ();
            }

            if ( $resultIds ) {
                $oSearch = $this->oSearch;

                $oSearch->dao->where( sprintf( "%st_item.pk_i_id IN (" . $resultIds . ")" , DB_TABLE_PREFIX ) );

                $oSearch->dao->orderBy( sprintf( "FIND_IN_SET(%st_item.pk_i_id, '" . $resultIds . "')" , DB_TABLE_PREFIX ) );

                $aItems = $oSearch->doSearch( true , false );
                //print_r($resultIds);
            } else {
                $aItems = array ();
            }

            if ( $extended ) {
                return Item::newInstance()->extendData( $aItems );
            } else {
                return $aItems;
            }
        }

        /**
         * @param int $max
         *
         * @return SphinxQLQuery
         */
        private function _makePremiumSQL( $max = 2 ) {
            if ( count( $this->cities ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->regions ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->countries ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->categories ) > 0 ) {
                $this->withCategoryId = true;
            }

            if ( $this->withPattern ) {
                $this->query->setSearch( $this->sPattern );
            }
            $this->query->removeOrderBy();
            $this->query->addOrderBy( 'RAND()' , 'none' , false );
            if ( $this->withNoUserEmail ) {
                $this->query->addWhere( 's_contact_email' , $this->sEmail );
            }

            if ( ! $this->expired ) {
                // ItemConditions
                $this->query->addWhere( 'b_enabled' , 1 );
                $this->query->addWhere( 'b_active' , 1 );
                $this->query->addWhere( 'b_spam' , 0 );
                //$current_date = date( 'Y-m-d H:i:s' );
                //$current_date = str_replace( array ( ':' , '-' , ' ' ) , '' , $current_date );
                //$this->query->addWhere( 'dt_expiration' , $current_date , '>=' );
            }
            if ( $this->withCategoryId && ( count( $this->categories ) > 0 ) ) {
                $this->query->addWhere( 'category' , $this->categories , 'IN' );
            }

            if ( $this->withUserId ) {
                $this->_fromUser();
            }
            if ( $this->withLocations ) {
                $this->_addLocations();
            }
            if ( $this->withPicture ) {
                $this->query->addWhere( 'hasPic' , 1 );
            }

            $this->query->addWhere( 'b_premium' , 1 );

            $this->_priceRange();

            $this->query->setOffset( 0 );
            $this->query->setLimit( $max );

            $this->premiumSql = $this->query->addOption( 'max_matches' , max( 1000 , ceil( ( $this->limit_init + $this->results_per_page ) / 250 ) * 250 ) );

            return $this->premiumSql;


        }

        private function _fromUser() {
            $this->query->addWhere( 'fk_i_user_id' , $this->user_ids );
        }

        private function _addLocations() {

            if ( count( $this->cities ) > 0 ) {
                $this->query->addWhere( 'city_id' , $this->cities , 'IN' );
            }
            if ( count( $this->regions ) > 0 ) {
                $this->query->addWhere( 'region_id' , $this->regions , 'IN' );
            }
            if ( count( $this->countries ) > 0 ) {
                $this->query->addWhere( 'country_id' , $this->countries , 'IN' );
            }
        }

        private function _priceRange() {
            if ( is_numeric( $this->price_min ) && $this->price_min != 0 ) {
                $this->query->addWhere( 'price' , intval( $this->price_min ) . '.0' , '>=' );
            }
            if ( is_numeric( $this->price_max ) && $this->price_max > 0 ) {
                $this->query->addWhere( 'price' , intval( $this->price_max ) . '.0' , '<=' );
            }

        }

        /**
         * @param $field
         * @param $Results
         *
         * @return array|bool|string
         * @internal param bool $withMeta
         */
        public function fetchField( $field , $Results ) {

            if ( ! empty( $Results ) ) {
                if ( count( $Results ) > 0 ) {
                    foreach ( $Results as $result ) {
                        $fields[] = $result[ $field ];

                    }
                }
            }

            if ( isset( $fields ) ) {
                $fields = implode( ',' , $fields );

                return $fields;
            }

            return false;
        }

        public function reconnect() {
            //   $this->conn = getConnection();
        }

        /**
         * @return mixed
         */
        public function count() {
            if ( is_null( $this->total_results ) ) {
                $this->doSearch();
            }

            return $this->total_results;
        }

        /**
         * @param bool $extended
         * @param bool $count
         *
         * @return array
         *
         */
        public function doSearch( $extended = true , $count = true ) {

            //return $results;
            $sql = $this->_makeSQL();
            //$this->sql = $sql;
            //echo $sql;
            $this->query( $sql );
            $resultdata       = $this->fetchAll();
            $meta             = $this->getMeta();
            $total_found      = $meta[ 'total_found' ];
            $this->query_time = $meta[ 'time' ];
            //$this->query_keyword = $meta[ 'keyword' ];
            $resultIds = $this->fetchField( 'id' , $resultdata );

            if ( $count ) {
                $this->total_results = $total_found;

            } else {
                $this->total_results = 0;
            }

            if ( $resultIds == false ) {
                return array ();
            }

            if ( $resultIds ) {
                $oSearch = $this->oSearch;

                $oSearch->dao->where( sprintf( "%st_item.pk_i_id IN (" . $resultIds . ")" , DB_TABLE_PREFIX ) );

                $oSearch->dao->orderBy( sprintf( "FIND_IN_SET(%st_item.pk_i_id, '" . $resultIds . "')" , DB_TABLE_PREFIX ) );

                $aItems = $oSearch->doSearch( true , false );
                //print_r($resultIds);
            } else {
                $aItems = array ();
            }

            if ( $extended ) {
                return Item::newInstance()->extendData( $aItems );
            } else {
                return $aItems;
            }

        }

        /**
         * @return SphinxQLQuery
         */
        public function _makeSQL() {

            if ( count( $this->cities ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->regions ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->countries ) > 0 ) {
                $this->withLocations = true;
            }

            if ( count( $this->categories ) > 0 ) {
                $this->withCategoryId = true;
            }

            if ( $this->withPattern ) {
                $this->query->setSearch( $this->sPattern );

                switch ( $this->order_column ) {
                    case "i_price":
                        $this->query->addOrderBy( 'price' , $this->order_direction );
                        break;
                    case "dt_pub_date":

                        $this->query->addOrderBy( 'date_pub' , $this->order_direction );
                        break;
                    case "relevance":
                        $this->query->addField( '*,weight()' , 'relevance' );
                        $this->query->addOrderBy( 'relevance' , $this->order_direction );
                        $this->query->addOption( 'ranker' , 'matchany' );
                        break;
                    default:
                        //$this->query->addField('*,INTERVAL(date_pub, NOW()-90*86400, NOW()-30*86400, NOW()-7*86400, NOW()-86400, NOW()-3600)','time_seg');
                        $this->query->addField( '*,(weight()*5000 + (date_pub/100000)) + (b_premium*30000)' , 'rank' );
                        $this->query->addOrderBy( 'rank' , $this->order_direction );
                        $this->query->addOption( 'ranker' , 'matchany' );
                        break;
                }
            } else {

                switch ( $this->order_column ) {
                    case "i_price":
                        $this->query->addOrderBy( 'price' , $this->order_direction );
                        break;
                    case "dt_pub_date":
                        $this->query->addOrderBy( 'date_pub' , $this->order_direction );
                        break;
                    default:
                        $this->query->addOrderBy( 'date_pub' , $this->order_direction );
                        break;
                }
            }
            if ( $this->withNoUserEmail ) {
                $this->query->addWhere( 's_contact_email' , $this->sEmail );
            }

            if ( ! $this->expired ) {
                // ItemConditions
                $this->query->addWhere( 'b_enabled' , 1 );
                $this->query->addWhere( 'b_active' , 1 );
                $this->query->addWhere( 'b_spam' , 0 );
                $current_date = date( 'Y-m-d H:i:s' );
                $current_date = str_replace( array ( ':' , '-' , ' ' ) , '' , $current_date );
                $this->query->addWhere( 'dt_expiration' , $current_date , '>=' );
            }
            if ( $this->withCategoryId && ( count( $this->categories ) > 0 ) ) {
                $this->query->addWhere( 'category' , $this->categories , 'IN' );
            }

            if ( $this->withUserId ) {
                $this->_fromUser();
            }
            if ( $this->withLocations ) {
                $this->_addLocations();
            }
            if ( $this->withPicture ) {
                $this->query->addWhere( 'hasPic' , 1 );
            }
            if ( $this->onlyPremium ) {
                $this->query->addWhere( 'b_premium' , 1 );
            }
            $this->_priceRange();

            $this->query->setOffset( $this->limit_init );
            $this->query->setLimit( $this->results_per_page );

            $this->sql = $this->query->addOption( 'max_matches' , max( 1000 , ceil( ( $this->limit_init + $this->results_per_page ) / 250 ) * 250 ) );

            return $this->sql;

        }

        /**
         * Return total items on t_item without any filter
         * We are using a predefined number,
         * @return int
         */
        public function countAll() {
            //TODO//
            if ( is_null( $this->total_results_table ) ) {
                $this->total_results_table = 1000000;
            }

            return $this->total_results_table;
        }

        /**
         * @return mixed
         */
        public function getQueryTime() {

            return $this->query_time;
        }

        /**
         * @return mixed
         */
        public function getQueryKeyword() {

            return $this->query_keyword;
        }

    }