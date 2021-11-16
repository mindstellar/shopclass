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
	use Params;
	use shopclass\includes\sphinx\SphSearch;
	use View;

	/**
	 * User: navjottomer
	 * Date: 10/12/17
	 * Time: 3:11 PM
	 * This class generate adloop for Shopclass theme
	 */
	class tfcAdsLoop {
		public static $instance;
		private $looptype;
		private $showAs;
		private $numberOfItems;
		private $listClass;
		private $galleryClass;


		/**
		 * tfcAdsLoop constructor.
		 *
		 * @param string $looptype
		 * @param string $showAs
		 * @param $listClass
		 * @param $galleryClass
		 */
		public function __construct( $looptype = 'items' , $showAs = 'list' , $listClass , $galleryClass ) {
			$this->looptype     = $looptype;
			$this->showAs       = $showAs;
			$this->listClass    = $listClass;
			$this->galleryClass = $galleryClass;

			switch ( $this->looptype ) {
				case 'premium':
					$this->numberOfItems = osc_count_premiums();
					break;
				case 'latest':
					$this->numberOfItems = osc_count_latest_items();
					break;
				default:
					$this->numberOfItems = osc_count_items();
					break;
			}
			if ( $this->showAs === 'gallery' && $this->numberOfItems > 0 ) {
				osc_add_hook( 'footer_scripts_loaded' , 'tfc_gallery_js' );
			}
		}

		/**
		 * @param string $looptype
		 * @param string $showAs
		 *
		 * @param string $listClass
		 * @param string $galleryClass
		 *
		 * @return tfcAdsLoop
		 */
		public static function newInstance( $looptype = 'items' , $showAs = 'list' , $listClass = '' , $galleryClass = 'col-md-4 col-sm-6 col-xs-12' ) {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self( $looptype , $showAs , $listClass , $galleryClass );
			}

			return self::$instance;
		}

		/**
		 * @param bool $withAds
		 *
		 */
		public function renderLoop( $withAds = false ) {
			if ( $this->numberOfItems > 0 ) {
				$counter = 0;
				if ( $this->showAs === 'gallery' ) {
					echo '<div id="show_gallery" class="row">';
				} else {
					echo '<div id="show_list" class="row">';
				}
				switch ( $this->looptype ) {
					case'premium':
						while ( osc_has_premiums() ) {
							$counter ++;
							$this->renderItem( $this->getItemProperty( 'premium' , true ) , $this->showAs , $withAds , $counter );
						}
						View::newInstance()->_erase( 'premiums' );
						break;
					case'latest':
						while ( osc_has_latest_items() ) {
							$counter ++;
							$this->renderItem( $this->getItemProperty( 'item' , osc_item_is_premium() ) , $this->showAs , $withAds , $counter );
						}
						View::newInstance()->_erase( 'items' );
						break;
					default:
						while ( osc_has_items() ) {
							$counter ++;
							$this->renderItem( $this->getItemProperty( 'item' , osc_item_is_premium() ) , $this->showAs , $withAds , $counter );
						}
						View::newInstance()->_erase( 'items' );
						break;
				}

				echo '</div>';

			} else {
				echo '<p class="empty">';
				echo osc_esc_html( __( 'No Item Found' , 'shopclass' ) );
				echo '</p>';
			}
		}

		/**
		 * @param $item
		 * @param string $renderAs Accepted list||gallery
		 *
		 * @param bool $withAds
		 * @param $counter
		 *
		 */
		public function renderItem( $item , $renderAs , $withAds = false , $counter=0 ) {
			if ( $renderAs === 'list' ) {
				$this->renderListItem( $item );
			}
			if ( $renderAs === 'gallery' ) {
				$this->renderGalleryItem( $item );
			}
			if ( $withAds ) {
				$this->renderAdSpace( $counter );
			}

		}

		/**
		 * @param $item
		 *
		 */
		private function renderListItem( $item ) { ?>
            <article class="adbox_ads <?php echo $this->listClass; ?>" data-mh="ads-group">
                <div class="adbox_box panel <?php if ( $item[ 'isPremium' ] ) {
					echo 'premium premium-ads';
				} ?>">
                    <div class="panel-body">
                        <div class="col-md-3 col-sm-4 col-xs-12 thumbnail">
                            <div class="img-container">
						        <span class="box_badge"><?php tfc_favourite_loop( $item[ 'itemId' ] , osc_logged_user_id() ); ?>
							        <?php if ( $item[ 'itemTotalImage' ] > 0 ) { ?>
                                        <span class="pull-right"><i
                                                    class="fa fa-camera fa-fw"></i><?php echo $item[ 'itemTotalImage' ]; ?></span>
							        <?php } ?>
						        </span>
								<?php if ( $item[ 'itemTotalImage' ] > 0 ) { ?>
                                    <a href="<?php echo $item[ 'itemUrl' ]; ?>" class="img-zoom"
                                       data-rel="<?php echo osc_resource_url(); ?>">
                                        <img src="<?php echo osc_resource_thumbnail_url(); ?>"
                                             alt="<?php echo osc_esc_html( $item[ 'itemTitle' ] ); ?>"/>
                                    </a>
								<?php } else { ?>
                                    <a href="<?php echo $item[ 'itemUrl' ]; ?>">
                                        <img src="<?php echo tfc_category_image_thumbnail_url(tfc_item_parent_category_id()); ?>"
                                             alt="<?php osc_esc_html( __( 'No image available' , 'shopclass' ) ); ?>"/>
                                    </a>
								<?php } ?>
                            </div>
                        </div>
                        <div class="adbox_detail col-md-9 col-sm-8 col-xs-12">
                            <h3 class="panel-title">
                                <a href="<?php echo $item[ 'itemUrl' ]; ?>"><?php echo $item[ 'itemTitle' ]; ?>
                                </a>
                            </h3>
                            <hr>
                            <p class="adbox_desc text-justify hidden-xs">
								<?php echo $item[ 'itemDescription' ]; ?>
                            </p>

                        </div>
                    </div>
                    <div class="panel-footer">
                        <span><strong><i class="fa fa-certificate text-info"></i>:</strong> <a
                                    href="<?php echo tf_osc_item_category_url( $item[ 'itemCategoryId' ] ); ?>"><?php echo $item[ 'itemCategory' ]; ?></a></span>
                        <span><strong><i
                                        class="fa fa-clock-o text-danger"></i>:</strong> <?php echo $item[ 'itemPublicationDate' ]; ?></span>
		                <?php if ( $item[ 'isPriceEnabled' ] ) { ?>
                        <span><strong><i class="fa fa-tag text-warning"></i>:</strong> <?php echo $item[ 'itemPrice' ]; ?></span><?php } ?>
		                <?php if ( $item[ 'itemCity' ] || $item[ 'itemRegion' ] ) { ?>
                            <span><strong><i
                                            class="fa fa-map-marker text-success"></i>:</strong> <?php echo trim( $item[ 'itemCity' ] );
					                if ( $item[ 'itemRegion' ] ) {
						                echo ' (' . $item[ 'itemRegion' ] . ') ';
					                } ?></span>
		                <?php } ?>
                    </div>
	                <?php if ( osc_is_user_dashboard()||osc_is_list_items() ) {
		                $this->addAdminDetail($item);
	                } ?>
                </div>
            </article> <?php
		}

		/**
		 * @param $item
		 *
		 */
		private function renderGalleryItem( $item ) { ?>
            <div class="adbox_gallery <?php echo $this->galleryClass; ?>">
                <div class="adbox_gallery_item thumbnail img-container panel <?php if ( $item[ 'isPremium' ] ) {
					echo 'premium';
				} ?>">
                    <div class="img-container">
						<span class="box_badge"><?php tfc_favourite_loop( $item[ 'itemId' ] , osc_logged_user_id() ); ?>
							<?php if ( $item[ 'itemTotalImage' ] > 0 ) { ?>
                                <span class="pull-right"><i
                                            class="fa fa-camera fa-fw"></i><?php echo $item[ 'itemTotalImage' ]; ?></span>
							<?php } ?>
						</span>
						<?php if ( $item[ 'itemTotalImage' ] > 0 ) { ?>
                            <a href="<?php echo $item[ 'itemUrl' ]; ?>">
                                <img src="<?php echo osc_resource_thumbnail_url(); ?>"
                                     alt="<?php echo osc_esc_html( $item[ 'itemTitle' ] ); ?>"/>
                            </a>
						<?php } else { ?>
                            <a href="<?php echo $item[ 'itemUrl' ]; ?>">
                                <img src="<?php echo tfc_category_image_thumbnail_url(tfc_item_parent_category_id()); ?>"
                                     alt="<?php osc_esc_html( __( 'No image available' , 'shopclass' ) ); ?>"/>
                            </a>
						<?php } ?>
                    </div>
                    <div class="adbox-listmenu <?php if ( $item[ 'isPremium' ] ) {
						echo 'premium-ads';
					} ?>">
                        <ul class="nav">
                            <li>
                                <div class="nav-link text-ellipsis">
                                    <a class="nav-title text-capitalize"
                                       href="<?php echo $item[ 'itemUrl' ]; ?>"><?php echo $item[ 'itemTitle' ]; ?></a>
                                </div>
                            </li>
                            <li><a href="<?php echo tf_osc_item_category_url( $item[ 'itemCategoryId' ] ); ?>"><i
                                            class="fa fa-certificate text-info"></i><?php echo $item[ 'itemCategory' ]; ?>
                                </a>
                            </li>
                            <li>
                                <div class="nav-link">
                                    <i class="fa fa-clock-o text-danger"></i><?php echo $item[ 'itemPublicationDate' ]; ?>
                            </li>
							<?php if ( $item[ 'itemCity' ] || $item[ 'itemRegion' ] ) { ?>
                                <li>
                                    <div class="nav-link">
                                        <i class="fa fa-map-marker text-success">&nbsp;</i><?php echo $item[ 'itemCity' ];
											echo '(' . $item[ 'itemRegion' ] . ')'; ?>
                                    </div>
                                </li>
							<?php } ?>

							<?php if ( $item[ 'isPriceEnabled' ] ) { ?>
                                <li>
                                    <div class="nav-link">
                                        <i class="fa fa-tag text-warning"></i><?php _e( "Price" , 'shopclass' ); ?>
                                        : <?php echo $item[ 'itemPrice' ]; ?>
                                    </div>
                                </li>
							<?php } ?>
                        </ul>
                    </div>
                </div>
            </div>
			<?php
		}

		/**
		 * @param $counter
		 */
		private function renderAdSpace( $counter ) {
			if ( $counter === 1 ) {
				$adId = 'adsense_banner6';
			}
			if ( $counter === 5 ) {
				$adId = 'adsense_banner7';
			}
			if ( isset( $adId ) ) {
				if ( tfc_getPref( $adId ) && tfc_getPref( 'enable_adba' ) ) {
					if ( $this->showAs === 'gallery' ) { ?>
                        <div class="adbox_gallery <?php echo $this->galleryClass; ?>">
                            <div class="adbox_gallery_item thumbnail img-container panel ">
                                <h3 class="panel-title"><?php _e( 'Sponsored Links' , 'shopclass' ); ?></h3>
								<?php
									echo tfc_getPref( $adId );
								?>
                            </div>
                        </div>
					<?php }
					if ( $this->showAs === 'list' ) {
						?>
                        <div class="adbox_ads <?php echo $this->listClass; ?>">
                            <div class="adbox_ads">
                                <div class="adbox_box panel">
                                    <h3 class="panel-title"><?php _e( 'Sponsored Links' , 'shopclass' ); ?></h3>
                                    <div class="panel-body">
										<?php
											echo tfc_getPref( $adId );
										?>
                                    </div>
                                </div>
                            </div>
                        </div>
						<?php
					}
				}
			}
		}

		/**
		 * @param string $callType Accepted item||premium
		 * @param bool $premium Accepted true||false
		 *
		 * @return array
		 */
		public function getItemProperty( $callType = 'item' , $premium = false ) {

			/**
			 * Item Properties
			 */
			$itemProperties                      = array ();
			$itemProperties[ 'isPremium' ]       = $premium;
			$itemProperties[ 'itemId' ]          = call_user_func( 'osc_' . $callType . '_id' );
			$itemProperties[ 'itemUrl' ]         = call_user_func( 'osc_' . $callType . '_url' );
			$itemProperties[ 'itemTitle' ]       = call_user_func( 'osc_' . $callType . '_title' );
			$itemProperties[ 'itemTitle' ]       = osc_highlight( $itemProperties[ 'itemTitle' ] , 60 , '<mark>' , '</mark>' );
			$itemProperties[ 'itemDescription' ] = call_user_func( 'osc_' . $callType . '_description' );
			if ( Params::getParam( 'sPattern' ) && defined( 'SPHINX_SEARCH' ) ) {
				$itemProperties[ 'itemDescription' ] = SphSearch::tfc_build_excerpt( $itemProperties[ 'itemDescription' ] );
			} else {
				$itemProperties[ 'itemDescription' ] = osc_highlight( strip_tags( $itemProperties[ 'itemDescription' ] ) , 150 , '<mark>' , '</mark>' );
			}
			$itemProperties[ 'itemCategoryId' ]      = call_user_func( 'osc_' . $callType . '_category_id' );
			$itemProperties[ 'itemCategory' ]        = call_user_func( 'osc_' . $callType . '_category' );
			$itemProperties[ 'itemPublicationDate' ] = osc_format_date( call_user_func( 'osc_' . $callType . '_pub_date' ) );
			$itemProperties[ 'itemCity' ]            = call_user_func( 'osc_' . $callType . '_city' );
			$itemProperties[ 'itemRegion' ]          = call_user_func( 'osc_' . $callType . '_region' );
			$itemProperties[ 'isImageEnabled' ]      = osc_images_enabled_at_items();
			if ( $itemProperties[ 'isImageEnabled' ] ) {
				$itemProperties[ 'itemTotalImage' ] = call_user_func( 'osc_count_' . $callType . '_resources' );
			} else {
				$itemProperties[ 'itemTotalImage' ] = 0;
			}
			$itemProperties[ 'isPriceEnabled' ] = osc_price_enabled_at_items();
			if ( $itemProperties[ 'isPriceEnabled' ] ) {
				$itemProperties[ 'itemPrice' ] = call_user_func( 'osc_item_formatted_price' );
			}
			return $itemProperties;

		}

		public function renderSearchLoop() {
			if ( $this->numberOfItems > 0 ) {
				$counter = 0;
				if ( $this->showAs === 'gallery' ) {
					echo '<div id="show_gallery" class="row">';
				} else {
					echo '<div id="show_list" class="row">';
				}
				if ( tfc_getPref( 'max-premium-items' ) ) {
					$max = tfc_getPref( 'max-premium-items' );
				} else {
					$max = 2;
				}
				osc_get_premiums( $max );
				while ( osc_has_premiums() ) {
					$counter ++;
					$this->renderItem( $this->getItemProperty( 'premium' , true ) , $this->showAs , false , $counter );
				}
				View::newInstance()->_erase( 'premiums' );
				while ( osc_has_items() ) {
					$counter ++;
					$this->renderItem( $this->getItemProperty( 'item' , osc_item_is_premium() ) , $this->showAs , true , $counter );
				}
				View::newInstance()->_erase( 'items' );
				echo '</div>';
			} else {
				echo '<p class="empty">';
				echo osc_esc_html( __( 'No result found matching your query' , 'shopclass' ) );
				echo ( osc_search_pattern() ) ? ' "'. osc_search_pattern() .'"': '';
				echo  '</p>';
			}
		}

		/**
		 * @param $item
		 */
		private function addAdminDetail( $item ) {
			if ( ! osc_item_is_expired() ) { ?>
				<?php if ( ( osc_logged_user_id() == osc_item_user_id() ) && osc_logged_user_id() != 0 ) { ?>
                    <div class="panel-footer">
                        <div class="list-inline">
                            <li><a class="btn btn-info btn-xs" href="<?php echo osc_item_url(); ?>"><i
                                            class="fa fa-eye"></i><?php _e( 'View item' , 'shopclass' ); ?></a>
                            </li>
                            <li><a class="btn btn-warning btn-xs" href="<?php echo osc_item_edit_url(); ?>"><i
                                            class="fa fa-pencil"></i><?php _e( 'Edit' , 'shopclass' ); ?></a>
                            </li>
                            <li><a class="btn btn-danger btn-xs"
                                   onclick="javascript:return confirm('<?php echo osc_esc_js( __( 'This action can not be undone. Are you sure you want to continue?' , 'shopclass' ) ); ?>')"
                                   href="<?php echo osc_item_delete_url(); ?>"><i
                                            class="fa fa-trash-o"></i><?php _e( 'Delete' , 'shopclass' ); ?></a>
                            </li>
							<?php if ( osc_item_is_inactive() ) { ?>
                                <li><a class="btn btn-success btn-xs"
                                       href="<?php echo osc_item_activate_url(); ?>"><i
                                            class="fa fa-check-circle"></i><?php _e( 'Activate' , 'shopclass' ); ?>
                                </a>
                                </li><?php } ?>
                            <li><?php tfc_push_to_top_button( $item[ 'itemId' ] ); ?></li>
                        </div>
                    </div>
				<?php }
			}
		}

		/**
		 * @param mixed $listClass
		 *
		 * @return tfcAdsLoop
		 */
		public function setListClass( $listClass ) {
			$this->listClass = $listClass;
			return $this;
		}

		/**
		 * @param mixed $galleryClass
		 *
		 * @return tfcAdsLoop
		 */
		public function setGalleryClass( $galleryClass ) {
			$this->galleryClass = $galleryClass;
			return $this;
		}

	}