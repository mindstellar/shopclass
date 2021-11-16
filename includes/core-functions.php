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

	use shopclass\includes\cacheModal\tfcCache;
	use shopclass\includes\classes\tfcBreadcrumb;
	use shopclass\includes\classes\tfcFilesClass;
	use shopclass\includes\classes\tfcPagination;

	/**
	 * @param $pref
	 *
	 * @param bool $translate
	 *
	 * @return mixed|string|array|int
	 */
	function tfc_getPref( $pref , $translate = false ) {
		//get preference of theme

		if ( $translate == true && osc_get_preference( $pref . osc_current_user_locale() , 'shopclass_theme' ) ) {
			return osc_get_preference( $pref . osc_current_user_locale() , 'shopclass_theme' );
		} elseif ( $translate == true && osc_get_preference( $pref . 'en_US' , 'shopclass_theme' ) ) {
			return osc_get_preference( $pref . 'en_US' , 'shopclass_theme' );
		}
		/** @var mixed|array|string|int $data */
		$data = osc_get_preference( $pref , 'shopclass_theme' );

		return $data;
	}


	/**
	 * @param $pref
	 * @param $param
	 *
	 * @return bool
	 */
	function tfc_setPref( $pref , $param ) {
		//set preference of theme
		return osc_set_preference( $pref , $param , 'shopclass_theme' );
	}


// --Commented out by Inspection START (2019-05-02 14:02):
//	/**
//	 * @param $string
//	 *
//	 * @return mixed
//	 */
//	function tfc_clean_str( $string ) {
//		$string = preg_replace( "/\s+/" , " " , $string );
//		$string = html_entity_decode( $string );
//		$string = strip_tags( $string );
//
//		return preg_replace( '/[^a-zA-Z,0-9-\/ ]/' , '' , $string );
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


	/**
	 * @param array $params
	 *
	 * @return mixed
	 * @internal param bool $forced
	 */
	function tfc_update_url( $params = array () ) {
		/** @var array $request */
		$request = Params::getParamsAsArray();
		$merged  = array_merge( $request , $params );

		return tfc_base_url( $merged );
	}


	/**
	 * @param null $params
	 *
	 * @return mixed|string
	 */
	function tfc_base_url( $params = null ) {
		if ( is_array( $params ) ) {
			osc_prune_array( $params );
		}
		$countP = count( $params );
		if ( ! empty( $countP ) ) {
			$params[ 'page' ] = 'search';
		};
		$base_url = osc_base_url();
		//$http_url = osc_is_ssl () ? "https://" : "http://";
		$countP = count( $params );
		if ( $countP == 0 ) {
			return $base_url;
		};
		unset( $params[ 'page' ] );
		//$countP = count ( $params );
		$url = $base_url . 'index.php?';
		if ( $params != null && is_array( $params ) ) {
			foreach ( $params as $k => $v ) {
				if ( $k == 'meta' ) {
					if ( is_array( $v ) ) {
						foreach ( $v as $_k => $aux ) {
							if ( is_array( $aux ) ) {
								foreach ( array_keys( $aux ) as $aux_k ) {
									$url .= "&" . $k . "[$_k][$aux_k]=" . urlencode( $aux[ $aux_k ] );
								}
							} else {
								$url .= "&" . $_k . "[]=" . urlencode( $aux );
							}
						}
					}
				} else {
					if ( is_array( $v ) ) {
						$v = implode( "," , $v );
					}
					$url .= "&" . $k . "=" . urlencode( $v );
				}
			}
		}

		return str_replace( '%2C' , ',' , $url );
	}

	/**
	 * Gets current searched Country
	 *
	 * @return string
	 */
	function get_search_country() {
		return Params::getParam( 'sCountry' );
	}


// --Commented out by Inspection START (2019-05-02 14:02):
//	/**
//	 * @return string
//	 */
//	function tfc_item_pub_title() {
//		$title = osc_item_title();
//		foreach ( osc_get_locales() as $locale ) {
//			if ( Session::newInstance()->_getForm( 'title' ) != "" ) {
//				$title_ = Session::newInstance()->_getForm( 'title' );
//				if ( $title_[ $locale[ 'pk_c_code' ] ] != "" ) {
//					$title = $title_[ $locale[ 'pk_c_code' ] ];
//				}
//			}
//		}
//
//		return $title;
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


	/**
	 * Gets parent category id of current item
	 *
	 * @param null $catId
	 *
	 * @return int
	 */
	function tfc_item_parent_category_id( $catId = null ) {
		if ( $catId === null ) {
			if ( osc_premium_category_id() > 0 ) {

				$Category = osc_premium_category_id();

			} else {
				$Category = osc_item_category_id();
			}
		} else {
			$Category = $catId;
		}
		$rootCategory = Category::newInstance()->findRootCategory( $Category );
		if ( isset($rootCategory[ 'pk_i_parent_id' ]) ) {
			$categoryId = tfc_item_parent_category_id( $rootCategory[ 'pk_i_parent_id' ] );
		} else {
			$categoryId = $rootCategory[ 'pk_i_id' ];
		}

		return $categoryId;

	}

//Funtion to get country name with given ID

// --Commented out by Inspection START (2019-05-02 14:02):
//	/**
//	 * @param $countryid
//	 *
//	 * @return mixed
//	 * @throws Exception
//	 */
//	function get_country_name( $countryid ) {
//		$tfccache = new tfcCache();
//		$key      = 'country_name' . $countryid;
//		if ( ! $data = $tfccache->tfcFetch( $key ) ) {
//			$conn = DBConnectionClass::newInstance();
//			$c_db = $conn->getOsclassDb();
//			$comm = new DBCommandClass( $c_db );
//			$comm->select( 's_name' );
//			$comm->from( DB_TABLE_PREFIX . 't_country' );
//			$comm->where( 'pk_c_code' , $countryid );
//			$result = $comm->get();
//			$data   = $result->resultArray();
//			$tfccache->tfcStore( $key , $data , 900 );
//		}
//
//		return $data[ 0 ][ 's_name' ];
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


//Function to create country selection box

	/**
	 * @param $country_select_txt
	 * @param null $countryId
	 */
	function item_country_box( $country_select_txt , $countryId = null ) {
		/** @var array $aCountries */
		$aCountries = osc_get_countries();
		if ( osc_is_ad_page() ) {
			$countryId = osc_item_field( 'fk_c_country_code' );
		} else {
			if ( osc_is_search_page() ) {
				$countryId = Params::getParam( 'sCountry' );
			}
		}
		if ( ! $countryId ) {
			$countryId = tfc_getPref( 'default_country' );
		}
		switch ( count( $aCountries ) ) {
			case 1: // one country
				?>
                <select class="form-control" id="sCountry" name="sCountry">
					<?php foreach ( $aCountries as $country ) {
						?>
                        <option value="<?php echo $country[ 'pk_c_code' ]; ?>"><?php echo $country[ 's_name' ]; ?></option>
						<?php
					}
					?>
                </select>
				<?php
				break;
			default: // more than one country
				?>
                <select class="form-control" id="sCountry" name="sCountry">
                    <option value=""><?php echo $country_select_txt; ?></option>
					<?php
						foreach ( $aCountries as $country ) {
							if ( $country[ 'pk_c_code' ] == $countryId ) {
								?>
                                <option value="<?php echo $country[ 'pk_c_code' ]; ?>"
                                        selected><?php echo $country[ 's_name' ]; ?></option>
							<?php } else { ?>
                                <option value="<?php echo $country[ 'pk_c_code' ]; ?>"><?php echo $country[ 's_name' ]; ?></option>
							<?php }
						} ?>
                </select>
				<?php
				break;
		}
	}

	/**
	 *Give Url for category by given Id
	 *
	 * @param $category_id
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tf_osc_item_category_url( $category_id ) {
		$key = 'item_cat_url_' . $category_id;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$data = tfcCache::runCache()->tfcFetch( $key );
		} else {
			View::newInstance()->_erase( 'subcategories' );
			View::newInstance()->_erase( 'categories' );
			View::newInstance()->_exportVariableToView( 'category' , Category::newInstance()->findByPrimaryKey( $category_id ) );
			$data = osc_search_category_url();
			View::newInstance()->_erase( 'category' );
			tfcCache::runCache()->tfcStore( $key , $data , 600 );
		}

		return $data;
	}

	/**
	 *
	 * functions to get category name and url with give id
	 *
	 * @param $catId
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tfc_category_name_url( $catId ) {

		$key = 'tfc_category_name_url' . $catId;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$data = tfcCache::runCache()->tfcFetch( $key );
		} else {

			Category::newInstance()->toTree();
			View::newInstance()->_erase( 'subcategories' );
			View::newInstance()->_erase( 'categories' );
			View::newInstance()->_exportVariableToView( 'category' , Category::newInstance()->findByPrimaryKey( $catId ) );
			$name = osc_category_name();
			$url  = osc_search_category_url();
			View::newInstance()->_erase( 'category' );
			//Return Url and category name in associative array
			$data = array ( 'url' => $url , 'name' => $name );
			tfcCache::runCache()->tfcStore( $key , $data , 600 );
		}

		return $data;
	}

//Functions to create popular category links
	/************************************************************\
	 *
	 * \************************************************************/
	function tfc_popular_category_links() {
		$categoryIds = tfc_getPref( 'category_id' );
		// split ids by comma, returns array
		$categoryIdsArray = explode( ',' , $categoryIds );
		$maxi             = count( $categoryIdsArray );

		$key = 'tfc_popular_category_array';
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$aCategories = tfcCache::runCache()->tfcFetch( $key );
		} else {
			$aCategories = Category::newInstance()->toTree( true );
			tfcCache::runCache()->tfcStore( $key , $aCategories , 600 );
		}

		View::newInstance()->_exportVariableToView( 'categories' , $aCategories );
		View::newInstance()->_get( 'categories' );
		osc_goto_first_category();
		if ( osc_count_categories() > 0 ) {
			$i = 1;
			while ( osc_has_categories() ) {

				if ( in_array( osc_category_id() , $categoryIdsArray ) ) {
					?>

                    <div class="row small-box <?php if ( $maxi == $i ++ ) {
						echo "pad-botom";
					} ?>">
                        <strong><?php echo osc_category_name(); ?></strong>
						<?php if ( osc_count_subcategories() > 0 ) {
							while ( osc_has_subcategories() ) { ?>
                                <a href="<?php echo osc_search_category_url(); ?>"><?php echo osc_category_name(); ?></a> |
							<?php }
						} ?>
                        <strong><a href="<?php echo osc_search_category_url(); ?>"><?php _e( 'view all items' , 'shopclass' ) ?></a></strong>

                    </div>
				<?php }
			}
		}
	}

	/**
	 * Function to render subcategories of given category id
	 * or all categories
	 *
	 * @param string $parentId
	 *
	 * @throws Exception
	 */
	function tfc_get_subcat( $parentId = '' ) {

		$key = 'category_list' . $parentId;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$categories = tfcCache::runCache()->tfcFetch( $key );
		} else {
			if ( ! $parentId ) {
				$categories = Category::newInstance()->listWhere( 'fk_i_parent_id IS NULL AND b_enabled=1' );
				//$sql = "SELECT pk_i_id FROM `" . DB_TABLE_PREFIX . "t_category` where fk_i_parent_id IS NULL AND b_enabled=1 Order by i_position";
			} else {
				$categories = Category::newInstance()->listWhere( 'fk_i_parent_id = ' . $parentId . ' AND b_enabled=1' );
			}

			tfcCache::runCache()->tfcStore( $key , $categories , 680 );
		}


		if ( $parentId != "" ) {
			?>
            <li>
                <a href="<?php echo osc_esc_html( osc_update_search_url( array (
					                                                         'sCategory' => null ,
					                                                         'iPage'     => null
				                                                         ) ) ) ?>"><?php _e( 'Reset Categories:' , 'shopclass' ); ?></a>
            </li>
			<?php
		}
		if ( ! empty( $categories ) ) {
			foreach ( $categories as $category ) {
				$nameurl = tfc_category_name_url( $category[ 'pk_i_id' ] );
				?>
                <li><i class="text-muted fa fa-chevron-right"></i><a
                            href="<?php echo osc_esc_html( osc_update_search_url( array (
								                                                      'sCategory' => $category[ 'pk_i_id' ] ,
								                                                      'iPage'     => null
							                                                      ) ) ) ?>"><?php echo $nameurl[ 'name' ]; ?></a>
                </li>
				<?php
			}
		}
	}

	/************************************************************\
	 *
	 * \************************************************************/
	function tfc_show_as() {
		$p_sShowAs          = Params::getParam( 'sShowAs' );
		$aValidShowAsValues = array ( 'list' , 'gallery' );
		if ( ! in_array( $p_sShowAs , $aValidShowAsValues ) ) {
			$p_sShowAs = tfc_getPref( 'defaultShowAs@all' );
		}
        if(!$p_sShowAs){
            return 'list';
        }
		return $p_sShowAs;
	}

//New Ad loop to get number of ads with different category
	/************************************************************\
	 *
	 * \************************************************************/
	function osc_distinct_ad_loop() {
		$key = 'tfc_distinct_ad_loop';

		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$aItems = tfcCache::runCache()->tfcFetch( $key );
		} else {
			// Sql query to select distinct items with category group order by publish date
			//.....................
			// Creat index for category,items and publish date to improve query performance
			//.....................
			$sql       = "select f.pk_i_id "
			             . "from ("
			             . " select fk_i_category_id, max(dt_pub_date) as maxdt_pub_date"
			             . " from " . DB_TABLE_PREFIX . "t_item group by fk_i_category_id"
			             . ") as x inner join " . DB_TABLE_PREFIX . "t_item as f on f.fk_i_category_id = x.fk_i_category_id and f.dt_pub_date = x.maxdt_pub_date"
			             . " Where b_spam = 0 AND b_active = 1 ORDER BY pk_i_id DESC LIMIT 0," . osc_max_latest_items();
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			foreach ( $results as $k => $v ) {
				$distinctIds[] = $v[ 'pk_i_id' ];
			}
			//initalizing Search call
			$mSearch = new Search();
			//adding condition to search call
			$mSearch->dao->whereIn( sprintf( "%st_item.pk_i_id" , DB_TABLE_PREFIX ) , $distinctIds );
			$mSearch->dao->limit( osc_max_latest_items() );
			$aItems = $mSearch->doSearch( true , false );
			tfcCache::runCache()->tfcStore( $key , $aItems , 300 );
		}
		//$global_items = View::newInstance ()->_get ( 'items' ); //save existing item array
		//exporting variable to view
		View::newInstance()->_exportVariableToView( 'items' , $aItems );
	}

//functions to get category id with passed item_id
	/************************************************************\
	 *
	 * \***********************************************************
	 * @param $itemId
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tfc_item_cat_id( $itemId ) {
		$key      = 'tfc_item_cat_id' . $itemId;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$data = tfcCache::runCache()->tfcFetch( $key );
		} else {
			$sql       = "SELECT fk_i_category_id FROM `" . DB_TABLE_PREFIX . "t_item` WHERE pk_i_id=" . $itemId . " LIMIT 0, 1 ";
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			//creating comma seprated index of results
			$data = $results[ 0 ][ 'fk_i_category_id' ];
			tfcCache::runCache()->tfcStore( $key , $data , 480 );
		}

		return $data;
	}

	/**
	 *
	 * functions to get city of item with passed item_id
	 *
	 * @param $itemId
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tfc_item_city( $itemId ) {
		$key      = 'tfc_item_city' . $itemId;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$data = tfcCache::runCache()->tfcFetch( $key );
		} else {
			$sql       = "SELECT s_city FROM `" . DB_TABLE_PREFIX . "t_item_location` WHERE fk_i_item_id=" . $itemId . " LIMIT 0, 1 ";
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			//creating comma seprated index of results
			$data = $results[ 0 ][ 's_city' ];
			tfcCache::runCache()->tfcStore( $key , $data , 480 );
		}

		return $data;
	}

	/**
	 *
	 * functions to get title of item with passed item_id
	 *
	 * @param $itemId
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tfc_item_title( $itemId ) {
		$key      = 'tfc_item_title' . $itemId;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$data = tfcCache::runCache()->tfcFetch( $key );
		} else {
			$sql       = "SELECT s_title FROM `" . DB_TABLE_PREFIX . "t_item_description` WHERE fk_i_item_id=" . $itemId . " LIMIT 0, 1 ";
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			$data      = $results[ 0 ][ 's_title' ];
			tfcCache::runCache()->tfcStore( $key , $data , 480 );
		}

		return $data;
	}

	/**
	 *
	 * functions to create item url with passed item_id
	 *
	 * @param $itemId
	 * @param string $locale
	 *
	 * @return array|bool|mixed|string
	 * @throws Exception
	 */
	function tfc_item_url( $itemId , $locale = '' ) {
		$key      = 'tfc_item_url' . $itemId . $locale;
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$path = tfcCache::runCache()->tfcFetch( $key );
		} else {
			if ( osc_rewrite_enabled() ) {
				$url = osc_get_preference( 'rewrite_item_url' );
				if ( preg_match( '|{CATEGORIES}|' , $url ) ) {
					$sanitized_categories = array ();
					$cat                  = Category::newInstance()->hierarchy( tfc_item_cat_id( $itemId ) );
					for ( $i = ( count( $cat ) ); $i > 0; $i -- ) {
						$sanitized_categories[] = $cat[ $i - 1 ][ 's_slug' ];
					}
					$url = str_replace( '{CATEGORIES}' , implode( "/" , $sanitized_categories ) , $url );
				}
				$url = str_replace( '{ITEM_ID}' , osc_sanitizeString( $itemId ) , $url );
				$url = str_replace( '{ITEM_CITY}' , osc_sanitizeString( tfc_item_city( $itemId ) ) , $url );
				$url = str_replace( '{ITEM_TITLE}' , osc_sanitizeString( tfc_item_title( $itemId ) ) , $url );
				$url = str_replace( '?' , '' , $url );
				if ( $locale != '' ) {
					$path = osc_base_url() . $locale . "/" . $url;
				} else {
					$path = osc_base_url() . $url;
				}
			} else {
				$path = osc_item_url_ns( $itemId , $locale );
			}
			tfcCache::runCache()->tfcStore( $key , $path , 550 );
		}

		return $path;
	}

	/**
	 *show next item of current item
	 *
	 */
	function osc_next_item_url() {
		$key      = md5( 'osc_next_item_url' . osc_item_id() );
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$id = tfcCache::runCache()->tfcFetch( $key );
		} else {
			$sql       = "SELECT pk_i_id FROM " . DB_TABLE_PREFIX . "t_item WHERE pk_i_id > " . osc_item_id() . " AND b_spam = 0 AND b_enabled = 1 AND b_active = 1 LIMIT 1";
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			$id        = $results[ 0 ][ 'pk_i_id' ];

			tfcCache::runCache()->tfcStore( $key , $id , 180 );
		}

		if ( ! empty( $id ) ) {
			echo tfc_item_url( $id );
		}
	}


	/**
	 *show prev item of current item
	 */
	function osc_prev_item_url() {
		$key      = md5( 'osc_prev_item_url' . osc_item_id() );
		if ( tfcCache::runCache()->tfcExists( $key ) ) {
			$id = tfcCache::runCache()->tfcFetch( $key );
		} else {

			$sql       = "SELECT pk_i_id FROM " . DB_TABLE_PREFIX . "t_item WHERE pk_i_id < " . osc_item_id() . " AND b_spam = 0 AND b_enabled = 1 AND b_active = 1 ORDER BY pk_i_id DESC LIMIT 1";
			$dao       = New DAO;
			$daoresult = $dao->dao->query( $sql );
			$results   = $daoresult->result();
			$id        = $results[ 0 ][ 'pk_i_id' ];

			tfcCache::runCache()->tfcStore( $key , $id , 180 );
		}

		if ( ! empty( $id ) ) {
			echo tfc_item_url( $id );
		}

	}

	/**
	 *function to change image url to cdn
	 *
	 */
	function cdn_resource_path() {
		return "//" . tfc_getPref( 'cdn_url' ) . "/" . osc_resource_field( "s_path" );
	}

	if ( tfc_getPref( 'cdn_url' ) ) {
		osc_add_filter( 'resource_path' , 'cdn_resource_path' );
	}


	/**
	 * GeoCoder GoogleMaps API
	 *
	 * @param $item
	 */
	function tfc_insert_geo_location( $item ) {
		$itemId       = $item[ 'pk_i_id' ];
		$aItem        = Item::newInstance()->findByPrimaryKey( $itemId );
		$sAddress     = ( isset( $aItem[ 's_address' ] ) ? $aItem[ 's_address' ] : '' );
		$sCity        = ( isset( $aItem[ 's_city' ] ) ? $aItem[ 's_city' ] : '' );
		$sRegion      = ( isset( $aItem[ 's_region' ] ) ? $aItem[ 's_region' ] : '' );
		$sCountry     = ( isset( $aItem[ 's_country' ] ) ? $aItem[ 's_country' ] : '' );
		$address      = sprintf( '%s, %s, %s, %s' , $sAddress , $sCity , $sRegion , $sCountry );
		$response     = tfc_curl_get_content( sprintf( 'http://maps.googleapis.com/maps/api/geocode/json?address=%s&sensor=false' , urlencode( $address ) ) );
		$jsonResponse = json_decode( $response );
		if ( isset( $jsonResponse->results[ 0 ]->geometry->location ) && count( $jsonResponse->results[ 0 ]->geometry->location ) > 0 ) {
			$location = $jsonResponse->results[ 0 ]->geometry->location;
			$lat      = $location->lat;
			$lng      = $location->lng;
			ItemLocation::newInstance()->update(
				array (
					'd_coord_lat'  => $lat
				,
					'd_coord_long' => $lng
				)
				, array ( 'fk_i_item_id' => $itemId )
			);
		}
	}

	osc_add_hook( 'posted_item' , 'tfc_insert_geo_location' );
	osc_add_hook( 'edited_item' , 'tfc_insert_geo_location' );

	/**
	 *
	 * Get current file modification time in active theme directory
	 *
	 * @param $file
	 *
	 * @return bool|int
	 */
	function tfc_filetime( $file ) {
		$filemtime = filemtime( WebThemes::newInstance()->getCurrentThemePath() . $file );

		return $filemtime;
	}

	/**
	 * Shopclass custom theme url of given file
	 *
	 * @param string $file
	 *
	 * @return string
	 */
	function tfc_theme_url( $file = '' ) {
        if (tfc_getPref('cdn_url')) {
            return "http://" . tfc_getPref('cdn_url') . "/oc-content/themes/shopclass/" . $file;
        } else {
            return WebThemes::newInstance()->getCurrentThemeUrl() . $file;
        }
	}

	/***
	 * push_to_top_button functions
	 *
	 * @param string $itemId
	 * @param string $authorId
	 */
	function tfc_push_to_top_button( $itemId = '' , $authorId = '' ) {
		if ( $itemId == '' ) {
			$itemId = osc_item_id();
		};
		if ( $authorId == '' ) {
			$authorId = osc_item_user_id();
		};
		$pub_time  = strtotime( osc_item_field( "dt_pub_date" ) );
		$cur_time  = time();
		$diff_time = $cur_time - $pub_time;
		$hour_diff = intval( floor( $diff_time / 3600 ) );
		if ( tfc_getPref( 'push_top_limit' ) ) {
			$push_top_limit = tfc_getPref( 'push_top_limit' );
		} else {
			$push_top_limit = 0;
		}
		if ( $hour_diff >= $push_top_limit ) {
			?>
            <a class="btn btn-success btn-xs"
               href="<?php echo osc_base_url( true ) . '?page=ajax&action=runhook&hook=push_listing&itemid=' . $itemId . '&authorId=' . $authorId; ?>">
                <i class="fa fa-level-up"></i><?php _e( ' Push To Top' , 'shopclass' ); ?>
            </a>
			<?php

		}
	}

	/**
	 * Set Current passed item published and modified time to current
	 *
	 * @param $itemId
	 */
	function tfc_push_to_top( $itemId ) {
		Item::newInstance()->update( array ( 'dt_pub_date' => date( 'Y-m-d H:i:s' ) ) , array ( 'pk_i_id' => $itemId ) );
		Item::newInstance()->update( array ( 'dt_mod_date' => date( 'Y-m-d H:i:s' ) ) , array ( 'pk_i_id' => $itemId ) );
		osc_add_flash_info_message( __( 'Your listing has been pushed to top' , 'shopclass' ) );
		osc_redirect_to( osc_user_dashboard_url() );
	}

	function ajax_tfc_push_to_top() {
		$userId   = osc_logged_user_id();
		$itemId   = Params::getParam( 'itemid' );
		$authorId = Params::getParam( 'authorId' );
		if ( osc_is_web_user_logged_in() && $userId == $authorId ) {

			tfc_push_to_top( $itemId );
		} else {
			osc_add_flash_error_message( __( 'We could not find any item with this ID!' , 'shopclass' ) );
			osc_redirect_to( osc_base_url() );
		}

	}

	osc_add_hook( 'ajax_push_listing' , 'ajax_tfc_push_to_top' );
	/**
	 * Helper Pagination
	 *
	 * @param array $extraParams
	 * @param bool $field
	 *
	 * @return string
	 */
	function tfc_pagination_items( $extraParams = array () , $field = false ) {
		$url       = '';
		$first_url = '';
		if ( osc_is_public_profile() ) {
			$url       = osc_user_list_items_pub_profile_url( '{PAGE}' , $field );
			$first_url = osc_user_public_profile_url();;
		} elseif ( osc_is_list_items() ) {
			$url       = osc_user_list_items_url( '{PAGE}' , $field );
			$first_url = osc_user_list_items_url();
		}
		$params = array (
			'total'     => osc_search_total_pages() ,
			'selected'  => osc_search_page() ,
			'url'       => $url ,
			'first_url' => $first_url

		);
		if ( is_array( $extraParams ) && ! empty( $extraParams ) ) {
			foreach ( $extraParams as $key => $value ) {
				$params[ $key ] = $value;
			}
		}
		$pagination = new tfcPagination( $params );

		return $pagination->doPagination();
	}


	/************************************************************\
	 * Gets the pagination links of search pagination
	 *
	 * @return string pagination links
	 * \************************************************************/
	function tfc_search_pagination() {
		$params = array ();
		if ( View::newInstance()->_exists( 'search_uri' ) ) {
			$params[ 'url' ] = osc_base_url() . View::newInstance()->_get( 'search_uri' ) . '/{PAGE}';
		}
		$pagination = new tfcPagination( $params );

		return $pagination->doPagination();
	}


	/************************************************************\
	 * Gets the pagination links of comments pagination
	 *
	 * @return string pagination links
	 * \************************************************************/
	function tfc_comments_pagination() {
		if ( ( osc_comments_per_page() == 0 ) || ( osc_item_comments_page() === 'all' ) ) {
			return '';
		} else {
			$params     = array (
				'total'    => ceil( osc_item_total_comments() / osc_comments_per_page() )
			,
				'selected' => osc_item_comments_page()
			,
				'url'      => osc_item_comments_url( '{PAGE}' )
			);
			$pagination = new tfcPagination( $params );

			return $pagination->doPagination();
		}
	}


	/************************************************************\
	 * Prints the user's account menu
	 *
	 * @param array $options array with options of the form array('name' => 'display name', 'url' => 'url of link')
	 *
	 * @return void
	 * \************************************************************/
	function tfc_private_user_menu( $options = null ) {
		if ( $options == null ) {
			$dashClass      = ( osc_is_user_dashboard() ) ? ' active' : ' ';
			$itemClass      = ( osc_is_list_items() ) ? ' active' : '';
			$alertClass     = ( osc_is_list_alerts() ) ? ' active' : '';
			$profileClass   = ( osc_is_user_profile() ) ? ' active' : '';
			$emailClass     = ( osc_is_change_email_page() ) ? ' active' : '';
			$usernameClass  = ( osc_is_change_username_page() ) ? ' active' : '';
			$passwordClass  = ( osc_is_change_password_page() ) ? ' active' : '';
			$favouriteClass = ( tfc_is_fav_page() ) ? ' active' : '';

			$options   = array ();
			$options[] = array (
				'name'  => __( 'Dashboard' , 'shopclass' ) ,
				'url'   => osc_user_dashboard_url() ,
				'class' => 'opt_dashboard ' . $dashClass ,
				'icon'  => 'fa fa-dashboard'
			);
			$options[] = array (
				'name'  => __( 'Manage your listings' , 'shopclass' ) ,
				'url'   => osc_user_list_items_url() ,
				'class' => 'opt_items' . $itemClass ,
				'icon'  => 'fa fa-list'
			);
			$options[] = array (
				'name'  => __( 'Manage your alerts' , 'shopclass' ) ,
				'url'   => osc_user_alerts_url() ,
				'class' => 'opt_alerts' . $alertClass ,
				'icon'  => 'fa fa-bell'
			);
			$options[] = array (
				'name'  => __( 'Manage your Favourite' , 'shopclass' ) ,
				'url'   => osc_route_url( 'shopclass-favourite' , array ( 'iPage' => '' ) ) ,
				'class' => 'opt_favourite' . $favouriteClass ,
				'icon'  => 'fa fa-heart'
			);
			$options[] = array (
				'name'  => __( 'Manage your profile' , 'shopclass' ) ,
				'url'   => osc_user_profile_url() ,
				'class' => 'opt_account' . $profileClass ,
				'icon'  => 'fa fa-user'
			);
			$options[] = array (
				'name'  => __( 'Change email' , 'shopclass' ) ,
				'url'   => osc_change_user_email_url() ,
				'class' => 'opt_change_email' . $emailClass ,
				'icon'  => 'fa fa-envelope-o'
			);
			$options[] = array (
				'name'  => __( 'Change username' , 'shopclass' ) ,
				'url'   => osc_change_user_username_url() ,
				'class' => 'opt_change_username' . $usernameClass ,
				'icon'  => 'fa fa-user-secret'
			);
			$options[] = array (
				'name'  => __( 'Change password' , 'shopclass' ) ,
				'url'   => osc_change_user_password_url() ,
				'class' => 'opt_change_password' . $passwordClass ,
				'icon'  => 'fa fa-key'
			);
			$options[] = array (
				'name'  => __( 'Public Profile' , 'shopclass' ) ,
				'url'   => osc_user_public_profile_url( osc_logged_user_id() ) ,
				'class' => 'opt_public_profile' ,
				'icon'  => 'fa fa-globe'
			);
		}
		$options = osc_apply_filter( 'user_menu_filter' , $options );

		$user_menu_js = function () {
			echo '
     <script>
	$(".user_menu > :first-child").addClass("first");
	$(".user_menu > :last-child").addClass("last");
	</script>';
		};

		osc_add_hook( 'footer_scripts_loaded' , $user_menu_js );

		$var_l = count( $options );
		for ( $var_o = 0; $var_o < ( $var_l - 1 ); $var_o ++ ) {
			echo '<li class="' . $options[ $var_o ][ 'class' ] . '" ><a href="' . $options[ $var_o ][ 'url' ] . '" ><i class="' . $options[ $var_o ][ 'icon' ] . '"></i>' . $options[ $var_o ][ 'name' ] . '</a></li>';
		}
		osc_run_hook( 'user_menu' );
		echo '<li class="' . $options[ $var_l - 1 ][ 'class' ] . ' " ><a href="' . $options[ $var_l - 1 ][ 'url' ] . '" ><i class="' . $options[ $var_o ][ 'icon' ] . '"></i>' . $options[ $var_l - 1 ][ 'name' ] . '</a></li>';

	}

	/************************************************************\
	 *Function for carousel
	 * \***********************************************************
	 * @internal param null $layout
	 */
	function tfc_carousel_start() {
		$tfccache = new tfcCache();
		$key      = 'tfc_carousel';
		if ( ! $aItems = $tfccache->tfcFetch( $key ) ) {

			$mSearch = new Search();

			//Carousel with premium item
			if ( tfc_getPref( 'caraousel_premium' ) ) {
				$mSearch->onlyPremium( true );
			}

			//Numbers of carousel item
			if ( tfc_getPref( 'caraousel_number' ) ) {
				$numofads = tfc_getPref( 'caraousel_number' );
			} else {
				$numofads = 6;
			}
			//Carousel with Popular ads
			if ( tfc_getPref( 'caraousel_popular' ) ) {

				$dao = New DAO;
				$sql = "";
				$sql .= "SELECT fk_i_item_id, ";
				$sql .= "       Sum(i_num_views) AS t_views ";
				$sql .= "FROM   (SELECT * ";
				$sql .= "        FROM   " . DB_TABLE_PREFIX . "t_item_stats ";
				$sql .= "        WHERE  i_num_views > 1 ";
				$sql .= "        ORDER  BY fk_i_item_id DESC ";
				$sql .= "        LIMIT  250) AS stats_table ";
				$sql .= "GROUP  BY fk_i_item_id ";
				$sql .= "ORDER  BY t_views DESC ";
				$sql .= "LIMIT " . $numofads;
				//$sql       = "SELECT fk_i_item_id FROM " . DB_TABLE_PREFIX . "t_item_stats Where i_num_views > 1 and dt_date > DATE_SUB(NOW(), INTERVAL 24 HOUR ) ORDER BY i_num_views DESC LIMIT " . $numofads;
				$daoresult = $dao->dao->query( $sql );
				$results   = $daoresult->result();

				foreach ( $results as $k => $v ) {
					$newResult[] = $v[ 'fk_i_item_id' ];
				}

				if ( ! empty( $newResult ) ) {
					$mSearch->dao->where( sprintf( "%st_item.pk_i_id IN (" . join( "," , $newResult ) . ")" , DB_TABLE_PREFIX ) );
				}
				unset( $dao );

			} else {
				$mSearch->limit( 0 , tfc_getPref( 'caraousel_number' ) );
			}
			//Carousel with pics
			if ( tfc_getPref( 'carousel_withpic' ) ) {
				$mSearch->withPicture( true );
			}

			//Searching with all enabled conditions
			$aItems = $mSearch->doSearch( true , false );

			$tfccache->tfcStore( $key , $aItems , 300 );
		}

		$global_items = View::newInstance()->_get( 'items' ); //save existing item array
		View::newInstance()->_exportVariableToView( 'items' , $aItems ); //exporting our searched item array

		tfc_path( 'carousel-loop.php' );

		//calling stored item array
		View::newInstance()->_exportVariableToView( 'items' , $global_items ); //restore original item array
	}

	/**
	 * Hack to enable route support through theme
	 */
	function tfc_custom_controller() {
		osc_run_hook( 'tfc_action_before_init' );

		Rewrite::newInstance()->init();
		osc_run_hook( 'tfc_action_after_init' );

	}

	osc_add_hook( 'init' , 'tfc_custom_controller' );

	/**
	 * Get admin base url with given options
	 *
	 * @param null $params
	 *
	 * @return mixed
	 */
	function tfc_admin_base_url( $params = null ) {
		if ( is_array( $params ) ) {
			osc_prune_array( $params );
		}
		$countP = count( $params );
		if ( $countP == 0 ) {
			$params[ 'page' ] = 'search';
		};
		$base_url = osc_admin_base_url( true ) . '?page=appearance';
		//$http_url = osc_is_ssl () ? "https://" : "http://";
		$countP = count( $params );
		if ( $countP == 0 ) {
			return $base_url;
		};
		unset( $params[ 'page' ] );
		//$countP = count ( $params );
		$url = $base_url;
		if ( $params != null && is_array( $params ) ) {
			foreach ( $params as $k => $v ) {
				if ( $k == 'meta' ) {
					if ( is_array( $v ) ) {
						foreach ( $v as $_k => $aux ) {
							if ( is_array( $aux ) ) {
								foreach ( array_keys( $aux ) as $aux_k ) {
									$url .= "&" . $k . "[$_k][$aux_k]=" . $aux[ $aux_k ];
								}
							} else {
								$url .= "&" . $_k . "[]=" . $aux;
							}
						}
					}
				} else {
					if ( is_array( $v ) ) {
						$v = implode( "," , $v );
					}
					$url .= "&" . $k . "=" . $v;
				}
			}
		}

		return str_replace( '%2C' , ',' , $url );
	}

// --Commented out by Inspection START (2019-05-02 14:02):
//	/************************************************************\
//	 *Get listing country ID
//	 * \************************************************************/
//	function tfc_item_country_id() {
//		return (string) osc_item_field( "fk_c_country_code" );
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


	/**
	 * Get listing region ID
     */
	function tfc_item_region_id() {
		return (string) osc_item_field( "fk_i_region_id" );
	}

	/************************************************************\
	 *Get listing City ID
	 * \************************************************************/
	function tfc_item_city_id() {
		return (string) osc_item_field( "fk_i_city_id" );
	}

	/************************************************************\
	 ** Get if user is on item edit page
	 *
	 * @return boolean
	 * \************************************************************/
	function tfc_is_item_edit_page() {
		$location = Rewrite::newInstance()->get_location();
		$section  = Rewrite::newInstance()->get_section();
		if ( $location == 'item' && $section == 'item_edit' ) {
			return true;
		}

		return false;
	}

	/**
	 * Print copyright year
	 *
	 * @param string $year
	 */
	function auto_copyright( $year = 'auto' ) {
		if ( intval( $year ) == 'auto' ) {
			$year = date( 'Y' );
		}
		if ( intval( $year ) == date( 'Y' ) ) {
			echo intval( $year );
		}
		if ( intval( $year ) < date( 'Y' ) ) {
			echo intval( $year ) . ' - ' . date( 'Y' );
		}
		if ( intval( $year ) > date( 'Y' ) ) {
			echo date( 'Y' );
		}
	}


	/**
	 *
     * TinyMce Editor Addition to Item-post and Item-edit page.
	 *
     */
	function tfc_tinymce_edit() {
		$location = Rewrite::newInstance()->get_location();
		$section  = Rewrite::newInstance()->get_section();
		if ( isset( $location ) ) {
			$location = Params::getParam( 'page' , false , true );
			$section  = Params::getParam( 'action' , false , true );
		}
		if ( ( $location == 'item' && ( $section == 'item_add' || $section == 'item_edit' ) )
		     || ( $location == 'items' && ( $section == 'post' || $section == 'item_edit' ) ) ) {
			?>
            <script src="//cdn.tinymce.com/4/tinymce.min.js"></script>
            <script type="text/javascript">
                tinyMCE.init({
                        mode: "none",
                        theme: "modern",
                        height: "400px",
                        toolbar1: "fullscreen undo redo | bold italic | alignleft aligncenter alignright alignjustify | bullist numlist | preview | forecolor backcolor",
                        plugins: [
                            "code fullscreen",
                            "paste textcolor"
                        ],
                        selector: "textarea[id^=description]"
                    }
                );
            </script>
			<?php
		}
	}

	/**
	 * Function to prevent html formatting removel
	 *
	 * @param $item
	 */
	function tfc_not_clean_items( $item ) {
		//$catID = $item['fk_i_category_id'];
		$itemID = $item[ 'pk_i_id' ];

		$title       = Params::getParam( 'title' );
		$description = Params::getParam( 'description' , false , false );
		$description = preg_replace( '/<a[^>]*>/i' , ' ' , $description );
		$description = preg_replace( '/<\/a>/i' , ' ' , $description );
		$description = str_replace( '<p>&nbsp;</p>' , ' ' , $description );
		$description = preg_replace( "{(<br[\\s]*(>|\/>)\s*){1,}}i" , " " , $description );

		$locale = osc_current_user_locale();

		$mItems = Item::newInstance();
		$mItems->updateLocaleForce( $itemID , $locale , $title[ $locale ] , $description[ $locale ] );
	}

	if ( tfc_getPref( 'enable_tinyMce' ) ) {
		osc_add_hook( 'posted_item' , 'tfc_not_clean_items' );
		osc_add_hook( 'edited_item' , 'tfc_not_clean_items' );
		osc_add_hook( 'footer_scripts_loaded' , 'tfc_tinymce_edit' );
		osc_add_hook( 'admin_header' , 'tfc_tinymce_edit' );
	}


	/**
	 *
	 * create list of sub-categories with all it's child categories
	 *
	 * @param $categories
	 * @param string $class
	 *
	 * @throws Exception
	 */
	function tfc_subcategory_list( $categories , $class = '' ) {
		echo '<ul class="' . $class . '">';
		foreach ( $categories as $c ) {
			$name_url = tfc_category_name_url( $c[ 'pk_i_id' ] );
			if ( isset( $c[ 'categories' ] ) && is_array( $c[ 'categories' ] ) ) {
				echo '<li><a href="' . $name_url[ 'url' ] . '">' . $name_url[ 'name' ] . '</a>';
				tfc_subcategory_list( $c[ 'categories' ] );
				echo '</li>';
			} else {
				echo '<li><a href="' . $name_url[ 'url' ] . '">' . $name_url[ 'name' ] . '</a></li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * Get Subcategories list with given main category
	 *
     */
	function tfc_get_subcatlist() {
		$categoryId = Params::getParam( 'maincatId' );
		osc_get_categories();
		while ( osc_has_categories() ) {
			if ( osc_category_id() == $categoryId ) {
				if ( osc_count_subcategories() > 0 ) {
					while ( osc_has_subcategories() ) { ?>
                        <li class="list-group-item"><a
                                    href="<?php echo osc_search_category_url(); ?>"><?php echo osc_category_name(); ?> </a>
                        </li>
					<?php }

				}
			}
		}
	}

	osc_add_hook( 'ajax_subcat_list' , 'tfc_get_subcatlist' );
	/************************************************************\
	 *Get elapsed time from Date-Hours-Seconds
	 * \***********************************************************
	 *
	 * @param $ptime
	 *
	 * @return null|string
	 */
	function tfc_time_ago( $ptime ) {
		$etime = time() - strtotime( $ptime );

		if ( $etime < 1 ) {
			return '0 seconds';
		}

		$a        = array (
			365 * 24 * 60 * 60 => 'year' ,
			30 * 24 * 60 * 60  => 'month' ,
			24 * 60 * 60       => 'day' ,
			60 * 60            => 'hour' ,
			60                 => 'minute' ,
			1                  => 'second'
		);
		$a_plural = array (
			'year'   => 'years' ,
			'month'  => 'months' ,
			'day'    => 'days' ,
			'hour'   => 'hours' ,
			'minute' => 'minutes' ,
			'second' => 'seconds'
		);

		foreach ( $a as $secs => $str ) {
			$d = $etime / $secs;
			if ( $d >= 1 ) {
				$r = round( $d );

				return $r . ' ' . ( $r > 1 ? $a_plural[ $str ] : $str ) . ' ago';
			}
		}

		return null;
	}

	/************************************************************\
	 *Ajax Load more url
	 * \************************************************************/
	function tfc_ajax_listing() {
		osc_current_web_theme_path( 'includes/tfc_ajax_listing.php' );
	}

	osc_add_hook( 'ajax_load_listing' , 'tfc_ajax_listing' );
	/**
	 *Ajax Add Comment
	 *
	 */
	function tfc_ajax_add_comment() {
		osc_csrf_check();

		$mItem  = new ItemActions( false );
		$status = $mItem->add_comment();
		switch ( $status ) {
			case - 1:
				$msg = __( 'Sorry, we could not save your comment. Try again later' , 'shopclass' );

				break;
			case 1:
				$msg = __( 'Your comment is awaiting moderation' , 'shopclass' );

				break;
			case 2:
				$msg = __( 'Your comment has been approved' , 'shopclass' );

				break;
			case 3:
				$msg = __( 'Please fill the required field (email)' , 'shopclass' );

				break;
			case 4:
				$msg = __( 'Please type a comment' , 'shopclass' );

				break;
			case 5:
				$msg = __( 'Your comment has been marked as spam' , 'shopclass' );

				break;
			case 6:
				$msg = __( 'You need to be logged to comment' , 'shopclass' );

				break;
			case 7:
				$msg = __( 'Sorry, comments are disabled' , 'shopclass' );

				break;
			case 10:
				$msg = __( 'Sorry, Invalid Request' , 'shopclass' );

				break;
		}

		if ( $status == 2 ) {
			$class = "alert-success";
		} else {
			$class = "alert-warning";
		}

		if ( isset( $msg ) ) {
			echo '<div class="alert ' . $class . ' alert-dismissible" role="alert">
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                          ' . $msg . '
                    </div>';
		}


	}

	osc_add_hook( 'ajax_tfc-comment' , 'tfc_ajax_add_comment' );


	/************************************************************\
	 *Function For Recently viewed ads
	 *It will trackpage
	 * \***********************************************************
	 *
	 * @param $itemId
	 */
	function tfc_trackPage( $itemId ) {
		if ( in_array( $itemId , Session::newInstance()->_get( 'pageurls' ) ) !== true ) {
			$array_pageurls = Session::newInstance()->_get( 'pageurls' );
			array_unshift( $array_pageurls , $itemId );
			array_pop( $array_pageurls );
			Session::newInstance()->_set( 'pageurls' , $array_pageurls );
		}
	}

	/************************************************************\
	 *Function For Recently viewed ads
	 *It will set array of listing ids for session
	 * \************************************************************/
	function tfc_set_pageurl() {
		if ( ! Session::newInstance()->_get( 'pageurls' ) ) {
			Session::newInstance()->_set( 'pageurls' , array_fill( 0 , 6 , '' ) );
		}
		tfc_trackPage( osc_item_id() );
	}

	if ( osc_is_ad_page() ) {
		osc_add_hook( 'before_html' , 'tfc_set_pageurl' );
	}

	/************************************************************\
	 *Function For Recently viewed listings
	 * \************************************************************/
	function tfc_recent_viewed_ads() {
		osc_add_hook( 'footer_scripts_loaded' , 'ajax_recent_ads_js' );
		echo '<div class="ajax-recent"></div>';
	}

	/**
	 *
	 */
	function tfc_ajax_recent_viewed_ads() {
		if ( Session::newInstance()->_get( 'pageurls' ) ) {
			$ItemIds = Session::newInstance()->_get( 'pageurls' );
			$ids     = implode( ',' , array_values( array_filter( $ItemIds ) ) );
			$mSearch = new Search();
			$mSearch->dao->where( sprintf( "%st_item.pk_i_id IN (" . $ids . ")" , DB_TABLE_PREFIX ) );
			//Searching with all enabled conditions
			$aItems = $mSearch->doSearch( true , false );

			$global_items = View::newInstance()->_get( 'items' ); //save existing item array
			View::newInstance()->_exportVariableToView( 'items' , $aItems ); //exporting our searched item array


			tfc_path( 'recent_ads.php' );

			//calling stored item array
			View::newInstance()->_exportVariableToView( 'items' , $global_items ); //restore original item array

		}

	}

	osc_add_hook( 'ajax_recent_ads' , 'tfc_ajax_recent_viewed_ads' );

	/************************************************************
	 *Function to create sphinx table in Database
	 ************************************************************/
	function tfc_sphinx_create_table() {

		$path     = WebThemes::newInstance()->getCurrentThemePath() . 'assets/sql/sphinx-struct.sql';
		$sql      = file_get_contents( $path );
		$conn     = DBConnectionClass::newInstance();
		$c_db     = $conn->getOsclassDb();
		$comm     = new DBCommandClass( $c_db );
		$imported = $comm->importSQL( $sql );

		return $imported;
	}


	/************************************************************\
	 * Function for demo purpose
	 * \************************************************************/
	function tfc_demo_layout() {
		tfc_enqueue_script( 'bootsidemenu' );
		?>
        <link rel="stylesheet" type="text/css" href="<?php echo tfc_theme_url( 'assets/css/BootSideMenu.css' ); ?>"/>
        <div id="demo_layout" class="bg-primary">
            <div class="col-md-12">
                <h3><i class="fa fa-gears"></i>Theme Options</h3>
				<?php osc_add_hook( 'footer_scripts_loaded' , 'tfc_demo_css_js' ); ?>
                <ul id="nav-css" class="list-unstyled">
                    <h4>Color</h4>
					<?php
						$dir      = WebThemes::newInstance()->getCurrentThemePath() . '/assets/css/theme/';
						$filelist = tfcFilesClass::newInstance()
						                         ->setEditDirectory( $dir )
						                         ->setValidFiles( array ( 'css' ) )
						                         ->scanFilenames();

						foreach ( $filelist as $file ) {
							if ( strpos( $file , '.min.css' ) !== false ) {
								$filebasename = str_replace( '.min.css' , '' , $file );
								echo '<li><a href="#" rel="' . tfc_theme_url( 'assets/css/theme/' . $file ) . '">' . ucfirst( $filebasename ) . '</a></li>';
							}
						}
					?>
                </ul>
				<?php if ( osc_is_home_page() ) { ?>
                    <div class="list-unstyled">
                        <h4>HomePage Layout</h4>
                        <li><a href="<?php echo tfc_update_url( array ( 'layout' => 1 ) ); ?>">Layout 1</a></li>
                        <li><a href="<?php echo tfc_update_url( array ( 'layout' => 2 ) ); ?>">Layout 2</a></li>
                        <li><a href="<?php echo tfc_update_url( array ( 'layout' => 3 ) ); ?>">Layout 3</a></li>
                    </div>
				<?php } ?>
            </div>
        </div>
		<?php
	}

// --Commented out by Inspection START (2019-05-02 14:02):
//	/************************************************************\
//	 *Strip Single tag with given string
//	 * \***********************************************************
//	 *
//	 * @param $tag
//	 * @param $string
//	 *
//	 * @return mixed
//	 */
//	function strip_single( $tag , $string ) {
//		$string = preg_replace( '/<' . $tag . '[^>]*>/i' , '' , $string );
//		$string = preg_replace( '/<\/' . $tag . '>/i' , '' , $string );
//
//		return $string;
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


// --Commented out by Inspection START (2019-05-02 14:02):
//	/************************************************************\
//	 *Clean Description or given String
//	 * \***********************************************************
//	 *
//	 * @param $string
//	 *
//	 * @return mixed
//	 */
//	function tfc_clean_description( $string ) {
//		$string = preg_replace( '/<a[^>]*>/i' , ' ' , $string );
//		$string = preg_replace( '/<\/a>/i' , ' ' , $string );
//		$string = str_replace( '<p>&nbsp;</p>' , ' ' , $string );
//		$string = preg_replace( "{(<br[\\s]*(>|\/>)\s*){1,}}i" , " " , $string );
//
//		//$string=preg_replace("{(<br[\\s]*(>|\/>)\s*)}i", "<br />", $string);
//		return $string;
//
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


	/************************************************************\
	 * Echo array for background Images
	 * \************************************************************/
	function tfc_array_bk_image() {

		$dir      = osc_uploads_path() . '/shopclass_slider/';
		$filelist = tfcFilesClass::newInstance()
		                         ->setEditDirectory( $dir )
		                         ->setValidFiles( array ( 'jpeg' , 'jpg' ) )
		                         ->scanFilenames();
		echo 'var array = [';
		//echo '"' . osc_current_web_theme_url() . 'assets/images/home-try.jpg",';
		if ( ! empty( $filelist ) ) {
			foreach ( $filelist as $image ) {
				echo '"' . REL_WEB_URL . str_replace( ABS_PATH , '' , osc_uploads_path() . 'shopclass_slider/' . $image ) . '",';
			}
		}
		echo '];';
	}

	/**
	 *Give User profile pic url by given userid and email
	 *
	 * @param $userId
	 *
	 * @return string
	 * @internal param null $useremail
	 */
	function tfc_user_profile_pic_url( $userId ) {

		return tfc_user_avatar_url( $userId );
	}

	/**
	 *Custom Breadcrumb
	 *
	 * @param string $separator
	 * @param bool $echo
	 * @param array $lang
	 *
	 * @return string
	 */
	function tfc_breadcrumb( $separator = '' , $echo = true , $lang = array () ) {
		$br = new tfcBreadcrumb( $lang );
		$br->init();
		if ( $echo ) {
			echo $br->render( $separator );
		}

		return $br->render( $separator );
	}

	/**
	 * @param $URL
	 *
	 * @return array|bool|mixed|string
	 */
	function tfc_curl_get_content( $URL ) {
		$ch = curl_init();
		curl_setopt( $ch , CURLOPT_SSL_VERIFYPEER , false );
		curl_setopt( $ch , CURLOPT_FOLLOWLOCATION , true );
		curl_setopt( $ch , CURLOPT_RETURNTRANSFER , 1 );
		curl_setopt( $ch , CURLOPT_URL , $URL );
		$data = curl_exec( $ch );
		curl_close( $ch );

		return $data;
	}

	/**
	 * Return Active Items of given UserId
	 *
	 * @param $userId
	 *
	 * @return int
	 */
	function tfc_total_user_items( $userId ) {
		$items = new Item();
		$items->dao->select( 'COUNT(pk_i_id) AS total' );
		$items->dao->from( $items->getTableName() );
		$items->dao->where( 'fk_i_user_id' , (int) $userId );
		$items->dao->where( 'b_enabled' , true );
		$items->dao->where( 'b_active' , true );

		$items->dao->where( '( b_premium = 1 || dt_expiration >= \'' . date( 'Y-m-d H:i:s' ) . '\' )' );
		//echo $items->dao->;
		$result = $items->dao->get();

		if ( $result == false ) {
			return 0;
		}

		if ( $result->numRows() == 0 ) {
			return 0;
		}

		$row = $result->row();

		return $row[ 'total' ];
	}

	/**
	 * Return Total Views of User Items
	 *
	 * @param $userId
	 *
	 * @return int
	 */
	function tfc_total_user_item_views( $userId ) {
		$mSearch = new Search();
		$mSearch->fromUser( $userId );
		$mSearch->limit( 0 , tfc_total_user_items( $userId ) );
		$result = $mSearch->doSearch();

		View::newInstance()->_exportVariableToView( 'items' , $result );
		$totalViews = 0;

		if ( osc_count_items() == 0 ) {
			$totalViews = 0;
		} else {
			while ( osc_has_items() ) {
				$totalViews += osc_item_views();
			}
		}
		View::newInstance()->_reset( 'items' );

		return $totalViews;
	}

// --Commented out by Inspection START (2019-05-02 14:02):
//	function tfc_ajax_load_location_map() {
//		osc_run_hook( 'location' );
//
//	}
// --Commented out by Inspection STOP (2019-05-02 14:02)


	function tfc_report_item() {
		$itemId = Params::getParam( 'itemId' );
		?>
        <div class="list-group text-center">
            <a rel="nofollow" class="list-group-item reportedItem"
               href="<?php echo osc_base_url(); ?>item/mark/spam/<?php echo $itemId; ?>"><?php _e( 'Report As Spam' , 'shopclass' ); ?></a>
            <a rel="nofollow" class="list-group-item reportedItem"
               href="<?php echo osc_base_url(); ?>item/mark/badcat/<?php echo $itemId; ?>"><?php _e( 'Report As Misclassified' , 'shopclass' ); ?></a>
            <a rel="nofollow" class="list-group-item reportedItem"
               href="<?php echo osc_base_url(); ?>item/mark/repeated/<?php echo $itemId; ?>"><?php _e( 'Report As Duplicated' , 'shopclass' ); ?></a>
            <a rel="nofollow" class="list-group-item reportedItem"
               href="<?php echo osc_base_url(); ?>item/mark/expired/<?php echo $itemId; ?>"><?php _e( 'Report As Expired' , 'shopclass' ); ?></a>
            <a rel="nofollow" class="list-group-item reportedItem"
               href="<?php echo osc_base_url(); ?>item/mark/offensive/<?php echo $itemId; ?>"><?php _e( 'Report As Offensive' , 'shopclass' ); ?></a>
        </div>
		<?php
	}

	osc_add_hook( 'ajax_load_itemreport' , 'tfc_report_item' );
	/**
	 * @param $categoryId
	 *
	 * @return string
	 */
	function tfc_category_image_url( $categoryId ) {
		if ( ( file_exists( osc_uploads_path() . "categorypics/" . $categoryId . ".jpg" ) ) ) {
			return osc_base_url() . 'oc-content/uploads/categorypics/' . $categoryId . '.jpg';
		} else {
			return osc_current_web_theme_url() . "assets/images/no_photo.gif";
		}
	}

	/**
	 * @param $categoryId
	 *
	 * @return string
	 */
	function tfc_category_image_thumbnail_url( $categoryId ) {
		if ( ( file_exists( osc_uploads_path() . "categorypics/" . $categoryId . "_thumbnail.jpg" ) ) ) {
			return osc_base_url() . 'oc-content/uploads/categorypics/' . $categoryId . '_thumbnail.jpg';
		} else {
			return osc_current_web_theme_url() . "assets/images/no_photo.gif";
		}
	}