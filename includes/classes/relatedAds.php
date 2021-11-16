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
	 * Date: 31/03/19
	 * Time: 11:25 AM
	 */

	namespace shopclass\includes\classes;
	use shopclass\includes\sphinx\SphSearch;

	/**
	 * Class relatedAds
	 * @package shopclass\includes\classes
	 */
	class relatedAds {
		private static $instance;
		private $sphinxEnabled;
		private $relatedSearch;
		private $numberOfAds;
		private $filterCategory;
		private $filterCountry;
		private $filterRegion;
		private $filterPictureOnly;
		private $filterPremiumOnly;
		/**
		 * relatedAds constructor.
		 */
		public function __construct() {
			$this->numberOfAds       = tfc_getPref( 'related_ra_numads' );
			$this->filterCategory    = tfc_getPref( 'related_ra_category' );
			$this->filterCountry     = tfc_getPref( 'related_ra_country' );
			$this->filterRegion      = tfc_getPref( 'related_ra_region' );
			$this->filterPictureOnly = tfc_getPref( 'related_picOnly' );
			$this->filterPremiumOnly = tfc_getPref( 'related_premiumonly' );
			$this->sphinxEnabled     = defined( 'SPHINX_SEARCH' );
			if ( $this->sphinxEnabled ) {
				$this->relatedSearch = SphSearch::newInstance();
				$this->relatedSearch->addCustomPattern( '"' . osc_item_title() . '"/1' );
				$this->relatedSearch->query->addWhere( 'id' , $this->numberOfAds , '<>' );
			} else {
				$this->relatedSearch = \Search::newInstance();
				$this->relatedSearch->dao->where( sprintf( '%st_item.pk_i_id <> ' . $this->numberOfAds , DB_TABLE_PREFIX ) );
			}
		}

		public static function relatedAds() {
			self::newInstance()->renderRelatedItems();
		}

		public function renderRelatedItems() {
			$this->addFitlers();
			$items = $this->searchRelated();
			if ( ! empty( $items ) ) {
				echo '<div class="col-md-12 related-ads card-box cardbox-default">';
				echo '<h4>'. __( 'Related Ads', 'shopclass') . '</h4>';
				echo '<div class="related-ads border-top-2p padding-top">';


				\View::newInstance()->_exportVariableToView('items',$items);
				tfcAdsLoop::newInstance('items','list','col-md-12')->renderLoop();
					//while(osc_has_items()) {
						//tfcAdsLoop::newInstance( 'items' , 'list' , 'col-md-12' )->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' );
					//}
				\View::newInstance()->_erase('items');
				unset( $items );
				echo '</div></div>';
			}
		}

		public function addFitlers() {
			if ( $this->filterCountry ) {
				$this->relatedSearch->addCountry( osc_item_country() );
			}
			if ( $this->filterRegion ) {
				$this->relatedSearch->addRegion( osc_item_region() );
			}
			if ( $this->filterCategory ) {
				$this->relatedSearch->addCategory( osc_item_category_id() );
			}
			if ( $this->filterPictureOnly ) {
				$this->relatedSearch->withPicture( true );
			}
			if ( $this->filterPremiumOnly ) {
				$this->relatedSearch->onlyPremium( true );
			}

		}

		/**
		 * @return array
		 */
		public function searchRelated() {
			$this->relatedSearch->limit( 0 , $this->numberOfAds );

			$aItems = $this->relatedSearch->doSearch();

			return $aItems;
		}

		/**
		 * @return relatedAds
		 */
		public static function newInstance() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}