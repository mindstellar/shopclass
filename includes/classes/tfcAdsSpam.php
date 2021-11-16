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
	use ItemActions;

	/**
	 * User: navjottomer
	 * Date: 08/10/18
	 * Time: 1:34 AM
	 */
	class tfcAdsSpam {
		public static $instance;
		public $tfcAdsSpamEnabled;
		public $tfcSpamKeywordHeading;
		public $tfcSpamKeywordDescription;
		public $tfcSpamKeywordAll;
		private $item;
		private $itemTitle;
		private $itemDescription;
		private $itemActions;
		private $itemId;
		private $itemIsSpam = false;

		/**
		 * tfcAdsSpam constructor.
		 *
		 * @param $item
		 */
		public function __construct( $item ) {

			if ( tfc_getPref( 'keyword_spam_enabled' ) ) {
				$this->tfcAdsSpamEnabled = true;
			}
			if ( tfc_getPref( 'keyword_spam_heading' ) ) {
				$this->tfcSpamKeywordHeading = explode( ',' , strtolower( tfc_getPref( 'keyword_spam_heading' ) ) );
			}
			if ( tfc_getPref( 'keyword_spam_description' ) ) {
				$this->tfcSpamKeywordDescription = explode( ',' , strtolower( tfc_getPref( 'keyword_spam_description' ) ) );
			}
			if ( tfc_getPref( 'keyword_spam_all' ) ) {
				$this->tfcSpamKeywordAll = explode( ',' , strtolower( tfc_getPref( 'keyword_spam_all' ) ) );
			}
			$this->itemActions     = new ItemActions();
			$this->item            = $item;
			$this->itemId          = $item[ 'pk_i_id' ];
			$this->itemTitle       = strtolower( strip_tags( $item[ 's_title' ] ) );
			$this->itemDescription = strtolower( strip_tags( $item[ 's_description' ] ) );
		}

		/**
		 * @param $item
		 *
		 * @return tfcAdsSpam
		 */
		public static function newInstance( $item ) {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self( $item );
			}

			return self::$instance;
		}

		/**
		 * @param mixed $item
		 *
		 * @return tfcAdsSpam
		 */
		public function setItem( $item ) {
			$this->item = $item;

			return $this;
		}

		/**
		 * ProcessRequest function
		 * mark ad as spam if keyword found in item Title or Description
		 */
		public function processRequest() {
			if ( $this->tfcAdsSpamEnabled ) {
				if ( $this->tfcSpamKeywordHeading ) {
					$this->processKeywordCheck( $this->tfcSpamKeywordHeading , $this->itemTitle );
				}
				if ( $this->tfcSpamKeywordDescription ) {
					$this->processKeywordCheck( $this->tfcSpamKeywordDescription , $this->itemDescription );
				}
				if ( $this->tfcSpamKeywordAll ) {
					$this->processKeywordCheck( $this->tfcSpamKeywordAll , $this->itemTitle );
					$this->processKeywordCheck( $this->tfcSpamKeywordAll , $this->itemDescription );
				}

				if ( $this->itemIsSpam ) {
					$this->itemActions->spam( $this->itemId );
					osc_add_flash_error_message( __( 'Your ad is submitted for a review.' , 'shopclass' ) );
				}
			}
		}

		/**
		 * @param $keywordArray
		 * @param $text
		 */
		public function processKeywordCheck( $keywordArray , $text ) {
			if ( ! $this->itemIsSpam ) {
				foreach ( $keywordArray as $keyword ) {
					$keywordExists = $this->keywordExists( $keyword , $text );
					if ( $keywordExists == true ) {
						$this->itemIsSpam = true;
						break;
					}
				}
			}
		}

		/**
		 * @param $keyword
		 * @param $string
		 *
		 * @return bool
		 */
		public function keywordExists( $keyword , $string ) {

			if ( preg_match( "/" . $keyword . "/i" , $string ) ) {
				return true;
			} else {
				return false;
			}
		}
	}