<?php use shopclass\includes\frm\tfcContactForm;

	osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' );
	if ( tfc_getPref( 'enable_adba' ) && tfc_getPref( 'adsense_banner5' ) ) {

		$detail_class  = "col-md-6";
		$detail_class2 = "col-md-4 col-sm-6 col-xs-4";
	} else {

		$detail_class  = "col-md-12";
		$detail_class2 = "col-md-3 col-sm-4 col-xs-4";
	} ?>
<div class="row">
    <div class="col-md-12 cardbox-default item-header cardbox-primary">
        <div class="row">
            <div class="col-md-8">
                <h1 itemprop="name"><a class="text-ellipsis" href="<?php echo osc_item_url(); ?>">
						<?php echo osc_item_title(); ?></a>
                </h1>
                <div class="row">
                    <p class="col-md-12 text-ellipsis"><i
                                class="fa-clock-o fa"></i><?php echo osc_format_date( osc_item_pub_date() ); ?> <i
                                class="fa-certificate fa"></i><?php echo osc_item_category(); ?></p>
                </div>
            </div>
            <div class="col-md-4">
				<?php if ( osc_price_enabled_at_items() ) { ?>
                    <div class="text-center">
                        <h4 class="cardbox-block card-box cardbox-info"><i
                                    class="fa fa-tag fa-fw"></i><strong><?php _e( "Price:" , 'shopclass' ); ?></strong>
                            <span class="price"> <?php echo osc_item_formated_price(); ?></span></h4>
                    </div>
				<?php } ?>
            </div>
        </div>
    </div>
    <div class="col-md-8 ">
        <div class="col-md-12 card-box cardbox-default item-content" data-listingid="<?php echo osc_item_id(); ?>">
            <div class="row">
                <div class="<?php echo $detail_class; ?>">
					<?php if ( osc_images_enabled_at_items() ) { ?>
						<?php if ( osc_count_item_resources() > 0 ) { ?>
                            <div class="photos img-thumbnail">
								<?php for ( $i = 0; osc_has_item_resources(); $i ++ ) {
									if ( $i == 0 ) { ?>
                                        <div class="item-photo-main" data-toggle="tooltip" data-placement="top"
                                             data-original-title="<?php _e( "Click Image to Zoom" , 'shopclass' ); ?>">
                                            <a href="<?php echo osc_resource_url(); ?>" class="thumbnail image-group"
                                               title="<?php _e( 'Image' , 'shopclass' ); ?> <?php echo $i + 1; ?> / <?php echo osc_count_item_resources(); ?>">
                                                <img class="img-responsive" itemprop="image"
                                                     src="<?php echo osc_resource_preview_url(); ?>"
                                                     alt="<?php echo osc_esc_html( osc_item_title() ); ?>"
                                                     title="<?php echo osc_item_title(); ?>"/>
                                            </a>
                                        </div>
										<?php
										if ( osc_count_item_resources() > 1 ) {
											echo '<div class="row item-photos-group">';
										}
									} else { ?>
                                        <div class="<?php echo $detail_class2; ?>" data-mh="photo-group"
                                             data-toggle="tooltip" data-placement="top"
                                             data-original-title="<?php _e( "Click Image to Zoom" , 'shopclass' ); ?>">
                                            <a href="<?php echo osc_resource_url(); ?>" class="thumbnail image-group"
                                               title="<?php _e( 'Image' , 'shopclass' ); ?> <?php echo $i + 1; ?> / <?php echo osc_count_item_resources(); ?>">
                                                <img class="img-responsive"
                                                     src="<?php echo osc_resource_thumbnail_url(); ?>" alt=""
                                                     title=""/></a>
                                        </div>
									<?php } ?>
								<?php } ?>
								<?php if ( osc_count_item_resources() > 1 ) {
									echo '</div>';
								} ?>
                            </div>
						<?php } else { ?>
                            <div class="thumbnail" data-toggle="tooltip"
                                 data-placement="top"
                                 data-original-title="<?php _e( "No Image Available" , 'shopclass' ); ?>">
                                <img class="img-responsive"
                                     src="<?php echo tfc_category_image_url( tfc_item_parent_category_id() ) ?>"
                                     alt="No image available"/>
                            </div>
						<?php }
					} ?>
                </div>
				<?php if ( tfc_getPref( 'enable_adba' ) && tfc_getPref( 'adsense_banner5' ) ) { ?>
                    <div class="col-md-6" style="margin-top:8px">
						<?php echo tfc_getPref( 'adsense_banner5' ); ?>
                    </div>
				<?php } ?>

            </div>
            <h4><?php _e( 'Description' , 'shopclass' ); ?></h4>
            <div class="item_des_trunk border-top-2p padding-top">
                <p><?php echo osc_item_description(); ?></p>
            </div>
            <div class="social-details clearfix">
                <a class="social-icon social fb" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Share on Facebook" , 'shopclass' ); ?>" target="_blank"
                   href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( osc_item_url() ); ?>"><i
                            class="fa fa-facebook"></i></a>
                <a class="social-icon social tw" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Share on Twitter" , 'shopclass' ); ?>" target="_blank"
                   href="https://twitter.com/intent/tweet?text=<?php echo urlencode( osc_highlight( osc_item_title() , 60 ) ); ?>&amp;url=<?php echo urlencode( osc_item_url() ); ?>"><i
                            class="fa fa-twitter"></i></a>
                <a class="social-icon social in" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Share on Linkedin" , 'shopclass' ); ?>" target="_blank"
                   href="http://www.linkedin.com/shareArticle?mini=true&amp;url=<?php echo urlencode( osc_item_url() ); ?>&amp;title=<?php echo urlencode( osc_highlight( osc_item_title() , 120 ) ); ?>"><i
                            class="fa fa-linkedin"></i></a>
                <a class="social-icon social waap" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Share on WhatsApp" , 'shopclass' ); ?>"
                   href="whatsapp://send?text=<?php echo osc_item_title() . ' ' . osc_item_url(); ?>">
                    <i class="fa fa-whatsapp"></i>
                </a>
                <a class="social-icon social printAd" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Print this page" , 'shopclass' ); ?>"
                   href="<?php echo osc_current_web_theme_url() . "print.php?printItemId=" . osc_item_id() . "&amp;printItemUserId=" . osc_item_user_id(); ?>"
                   rel="nofollow"><i class="fa fa-print"></i></a>
                <a class="social-icon social sendToFriend" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "Send to Friend" , 'shopclass' ); ?>"
                   href="<?php echo osc_item_send_friend_url(); ?>" rel="nofollow"><i class="fa fa-group"></i></a>
                <a class="social-icon social qrcode" data-toggle="tooltip" data-placement="top"
                   data-original-title="<?php _e( "View QR Code" , 'shopclass' ); ?>"
                   href="https://chart.googleapis.com/chart?<?php echo htmlentities( "chs=250x250&cht=qr&chld=M%7CPT+6&chl=" . urlencode( osc_item_url() ) . "&chld=1&=UTF-8" ) ?>"
                   rel="nofollow"><i class="fa fa-qrcode"></i></a>
            </div>
			<?php if ( ! osc_item_is_expired() ) { ?>
				<?php if ( ( osc_logged_user_id() == osc_item_user_id() ) && osc_logged_user_id() != 0 ) { ?>
                    <ul class="list-inline">
                        <li><a class="btn btn-warning btn-xs" href="<?php echo osc_item_edit_url(); ?>">
                                <i class="fa fa-pencil"></i><?php _e( 'Edit' , 'shopclass' ); ?></a>
                        </li>
                        <li><a class="btn btn-danger btn-xs"
                               onclick="javascript:return confirm('<?php echo osc_esc_js( __( 'This action can not be undone. Are you sure you want to continue?' , 'shopclass' ) ); ?>')"
                               href="<?php echo osc_item_delete_url(); ?>"><i
                                        class="fa fa-trash-o"></i><?php _e( 'Delete' , 'shopclass' ); ?></a></li>
						<?php if ( osc_item_is_inactive() ) { ?>
                            <li><a class="btn btn-success btn-xs" href="<?php echo osc_item_activate_url(); ?>"><i
                                            class="fa fa-check-circle"><?php _e( 'Activate' , 'shopclass' ); ?></a>
                            </li>
						<?php } ?>
                        <li><?php tfc_push_to_top_button(); ?> </li>
                    </ul>
				<?php }
			} ?>
        </div>
        <div class="col-md-12 item-comments card-box cardbox-default">
            <h4><?php _e( 'More Details' , 'shopclass' ); ?></h4>
            <div class="item_des_trunk border-top-2p"></div>

            <div id="custom_fields">
                <div class="meta_list row">
                    <div class="meta meta-detail col-md-6">
                        <div class="border-bottom-1p clearfix">
                            <span class="meta-title"><i
                                        class="fa fa-eye"></i><?php _e( "Total Views:" , 'shopclass' ); ?></span><span
                                    class="meta-value"><?php echo osc_item_views(); ?></span>
                        </div>
                    </div>
                    <div class="meta meta-detail col-md-6">
                        <div class="border-bottom-1p clearfix">
                            <span class="meta-title"><i
                                        class="fa fa-info-circle"></i><?php _e( "Reference Id:" , 'shopclass' ); ?></span><span
                                    class="meta-value">#<?php echo osc_item_id(); ?></span>
                        </div>
                    </div>
					<?php if ( osc_count_item_meta() >= 1 ) { ?>
						<?php while ( osc_has_item_meta() ) { ?>
							<?php if ( osc_item_meta_value() != '' ) { ?>
                                <div class="metaId-<?php echo osc_item_meta_id(); ?> meta-detail col-md-6">
                                    <div class="border-bottom-1p clearfix">
                                        <span class="meta-title "><?php echo osc_item_meta_name(); ?>:</span><span
                                                class=" meta-value"><?php echo osc_item_meta_value(); ?></span>
                                    </div>
                                </div>
							<?php } ?>
						<?php }
					} ?>
                </div>
            </div>

			<?php if ( function_exists( 'tfc_voting_item_detail' ) ) {
				tfc_voting_item_detail();
			} ?>
            <div class="clearfix"></div>
			<?php osc_run_hook( 'item_detail' , osc_item() ); ?>
        </div>
        <div class="col-md-12 item-comments card-box cardbox-default">
			<?php include 'comments.php'; ?>
        </div>
		<?php \shopclass\includes\classes\relatedAds::relatedAds(); ?>
    </div>
    <div class="left-sidebar col-md-4 ">
        <div class="row">
            <div class="col-md-12">
                <div class="card-box cardbox-default item-sidebar">
                    <div class="row">
                        <div class="col-md-12 col-sm-6">
                            <div class="profile-userpic">
                                <img src="<?php echo tfc_user_profile_pic_url( osc_item_user_id() ); ?>"
                                     class="img-responsive" alt="">
                            </div>

                            <div class="profile-usertitle">
                                <div class="profile-usertitle-name">
									<?php echo osc_item_contact_name(); ?>
                                </div>
                                <div class="profile-usertitle-job text-primary">
									<?php if ( osc_user_is_company() ) {
										echo( __( 'Company' , 'shopclass' ) );
									} else {
										echo( __( 'Individual' , 'shopclass' ) );
									} ?>
                                </div>
								<?php if ( function_exists( 'tfc_voting_item_detail_user' ) ) {
									echo '<div class="tex-center">';
									tfc_voting_item_detail_user();
									echo '</div>';
								} ?>
                            </div>
                        </div>
                        <div class="col-md-12 col-sm-6">
							<?php
								if ( osc_user_public_profile_url( osc_item_user_id() ) ) {
									$profileurl     = osc_user_public_profile_url( osc_item_user_id() );
									$followDisabled = '';
								} else {
									$followDisabled = 'disabled';
									$profileurl     = '#';
								}
							?>
                            <div class="profile-userbuttons">
                                <a href="<?php echo $profileurl; ?>"
                                   class="btn btn-success btn-sm" <?php echo $followDisabled; ?>><i
                                            class="fa fa-binoculars"
                                            aria-hidden="true"></i><?php _e( 'Follow' , 'shopclass' ); ?>
                                </a>
                                <!-- Button trigger modal -->

                                <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#contactseller">
                                    <i class="fa fa-envelope-o"></i><?php _e( "Message" , 'shopclass' ); ?>
                                </button>
                            </div>
                            <div class="content-listmenu" itemscope itemtype="http://schema.org/Organization">
                                <ul class="nav" itemprop="address" itemscope itemtype="http://schema.org/PostalAddress">


									<?php if ( osc_user_phone() != '' ) { ?>
                                        <li>
                                            <div class="nav-link"><i
                                                        class="fa fa-phone"></i><?php _e( "Tel" , 'shopclass' ); ?>
                                                .: <?php echo osc_user_phone(); ?></div>
                                        </li>
									<?php } ?>
                                    <li>
                                        <div class="nav-link"><i
                                                    class="fa fa-map-signs"></i><?php _e( "Location :" , 'shopclass' ); ?>
											<?php if ( osc_item_address() != "" ) { ?>
                                                <span itemprop="streetAddress"><?php echo osc_item_address(); ?>
                                                    ,</span>
											<?php } ?>
											<?php if ( osc_item_city_area() != "" ) { ?>
                                                <span itemprop="streetAddress"><?php echo osc_item_city_area(); ?>
                                                    ,</span>
											<?php } ?>
											<?php if ( osc_item_city() != "" ) { ?>
                                                <span itemprop="addressLocality"><?php echo osc_item_city(); ?>
                                                    ,</span>
											<?php } ?>
											<?php if ( osc_item_region() != "" ) { ?>
                                                <span itemprop="addressRegion"><?php echo osc_item_region(); ?>
                                                    ,</span>
											<?php } ?>
											<?php if ( osc_item_country() != "" ) { ?>
                                                <span itemprop="addressCountry"><?php echo osc_item_country(); ?></span>
											<?php } ?>
                                        </div>
                                    </li>

                                </ul>
                            </div>
                            <div class="col-md-12">

								<?php tfc_favourite(); ?>
								<?php
									$item = osc_item();
								?>
                                <button class="btn btn-success btn-block btn-sm map-button" data-toggle="modal"
                                        data-target="#itemLocation">
                                    <i class="fa fa-crosshairs"></i> <?php _e( 'Location Map' , 'shopclass' ) ?>
                                </button>
                                <button class="btn btn-danger btn-block btn-sm" data-toggle="modal"
                                        data-target="#myMarkAd">
                                    <i class="fa fa-flag"></i> <?php _e( 'Report this Ad' , 'shopclass' ) ?>
                                </button>
                            </div>
                        </div>
                    </div>
					<?php
						if ( tfc_getPref( 'enable_adba' ) ) { ?>
                            <div class="text-center" style="padding-left:10px">
								<?php echo tfc_getPref( 'adsense_banner4' ); ?>
                            </div>
							<?php
						} ?>
                </div>
            </div>
            <div class="col-md-12">
                <div class="profile-usermenu card-box cardbox-default">
                    <div class="item_page_sidebar"><?php osc_show_widgets( 'item_page_sidebar' ); ?></div>
                    <h4 class="col-md-12"><?php _e( "Useful Info" , 'shopclass' ); ?></h4>
                    <div class="clearfix"></div>
                    <ul class="nav border-top-2p padding-top">
                        <li>
                            <div class="nav-link"><?php _e( 'Avoid scams by acting locally or paying with PayPal.' , 'shopclass' ); ?></div>
                        </li>
                        <li>
                            <div class="nav-link"><?php _e( 'Never pay with Western Union, Moneygram or other anonymous payment services.' , 'shopclass' ); ?></div>
                        </li>
                        <li>
                            <div class="nav-link"><?php _e( 'Don\'t buy or sell outside of your country. Don\'t accept cashier cheques from outside your country.' , 'shopclass' ); ?></div>
                        </li>
                        <li>
                            <div class="nav-link"><?php _e( 'This site is never involved in any transaction, and does not handle payments, shipping, guarantee transactions, provide escrow services, or offer "buyer protection" or "seller certification".' , 'shopclass' ); ?></div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>
<div class="row">
    <div class="col-md-12">
        <ul class="pager">
            <li class="previous">
                <a class="btn" href="<?php osc_prev_item_url(); ?>" rel="prev"><span
                            class="meta-nav fa fa-chevron-left"></span><?php _e( ' Prev Ad' , 'shopclass' ); ?>
                </a></li>
            <li class="next">
                <a class="btn" href="<?php osc_next_item_url(); ?>"
                   rel="next"><?php _e( 'Next Ad' , 'shopclass' ); ?> <span
                            class="meta-nav fa fa-chevron-right"></span></a>
            </li>
        </ul>
    </div>
</div>
<!-- Modal -->
<div class="modal fade" id="myMarkAd" tabindex="-1" role="dialog" aria-labelledby="myModalMarkAd" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title" id="myModalMarkAd"><i
                            class="fa fa-flag"></i> <?php _e( 'Report this ad' , 'shopclass' ) ?></h4>
            </div>
            <div id="reportmyItem" class="modal-body">

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="itemLocation" tabindex="-1" role="dialog" aria-labelledby="myModalLocation"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <h4 class="modal-title"><i
                            class="fa fa-map-signs"></i> <?php _e( 'Ad Location Map' , 'shopclass' ) ?></h4>
            </div>
            <div class="modal-body">
                <div id="itemMap" style="height:340px;"></div>
            </div>
        </div>
    </div>
</div>
<!-- Modal Contact Seller -->
<div class="modal fade" id="contactseller" tabindex="-1" role="dialog" aria-labelledby="modalContactSeller"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <i class="fa fa-envelope-o fa-fw"></i><?php _e( "Contact Seller" , 'shopclass' ); ?>
            </div>
            <form action="<?php echo osc_base_url( true ); ?>" method="post" name="contact_form" class="form-group"
                  id="contact_form">
                <div class="modal-body">
                    <div id="contact" class="tfc-form">
						<?php if ( osc_item_is_expired() ) { ?>
                            <p>
								<?php _e( 'The item is expired. You cannot contact the publisher.' , 'shopclass' ); ?>
                            </p>
						<?php } else if ( ( osc_logged_user_id() == osc_item_user_id() ) && osc_logged_user_id() != 0 ) { ?>
                            <p>
								<?php _e( "It's your own item, you cannot contact the publisher." , 'shopclass' ); ?>
                            </p>
						<?php } else if ( osc_reg_user_can_contact() && ! osc_is_web_user_logged_in() ) { ?>
                            <p>
								<?php _e( "You must login or register a new free account in order to contact the advertiser" , 'shopclass' ); ?>
                            </p>
                            <p class="contact_button">
                                <strong><a class="btn btn-default"
                                           href="<?php echo osc_user_login_url(); ?>"><?php _e( 'Login' , 'shopclass' ); ?></a></strong>
                                <strong><a class="btn btn-default"
                                           href="<?php echo osc_register_account_url(); ?>"><?php _e( 'Register for a free account' , 'shopclass' ); ?></a></strong>
                            </p>
						<?php } else { ?>
							<?php if ( osc_item_user_id() != null ) { ?>
                                <p class="name"><?php _e( 'Name' , 'shopclass' ) ?>: <a
                                            href="<?php echo osc_user_public_profile_url( osc_item_user_id() ); ?>"><?php echo osc_item_contact_name(); ?></a>
                                </p>
							<?php } else { ?>
                                <p class="name"><?php _e( 'Name' , 'shopclass' ) ?>
                                    : <?php echo osc_item_contact_name(); ?></p>
							<?php } ?>
							<?php if ( osc_item_show_email() ) { ?>
                                <p class="email"><?php _e( 'E-mail' , 'shopclass' ); ?>
                                    : <?php echo osc_item_contact_email(); ?></p>
							<?php } ?>
							<?php if ( osc_user_phone() != '' ) { ?>
                                <p class="phone"><?php _e( "Tel" , 'shopclass' ); ?>
                                    .: <?php echo osc_user_phone(); ?></p>
							<?php } ?>
                            <ul id="error_list"></ul>
							<?php osc_prepare_user_info(); ?>
                            <fieldset class="tfc-form">
                                <div class="form-group">
                                    <label for="yourName"><?php _e( 'Your name' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-user"></span>
                                        </span>
										<?php tfcContactForm::your_name(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="yourEmail"><?php _e( 'Your e-mail address' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-envelope"></span>
                                        </span>
										<?php tfcContactForm::your_email(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="phoneNumber"><?php _e( 'Phone number' , 'shopclass' ); ?>
                                        (<?php _e( 'optional' , 'shopclass' ); ?>):</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-phone"></span>
                                        </span>
										<?php tfcContactForm::your_phone_number(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="message"><?php _e( 'Message' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-comment"></span>
                                        </span>
										<?php tfcContactForm::your_message(); ?>
                                    </div>
                                </div>
                                <input type="hidden" name="action" value="contact_post"/>
                                <input type="hidden" name="page" value="item"/>
                                <input type="hidden" name="id" value="<?php echo osc_item_id(); ?>"/>
								<?php osc_run_hook( 'tf_after_form' ); ?>
								<?php osc_show_recaptcha(); ?>
                            </fieldset>
						<?php } ?>
                    </div>
                </div>
                <div class="modal-footer">
					<?php $contactdisabled = 'disabled'; ?>
					<?php if ( ! osc_item_is_expired() ) { ?>
						<?php if ( ! ( ( osc_logged_user_id() == osc_item_user_id() ) && osc_logged_user_id() != 0 ) ) { ?>
							<?php if ( osc_reg_user_can_contact() && osc_is_web_user_logged_in() || ! osc_reg_user_can_contact() ) { ?>
								<?php $contactdisabled = ''; ?>
							<?php }
						}
					} ?>
                    <button class="btn btn-success"
                            type="submit" <?php echo $contactdisabled; ?>><?php _e( 'Send' , 'shopclass' ); ?></button>

                    <button type="button" class="btn btn-warning"
                            data-dismiss="modal"><?php _e( 'Close' , 'shopclass' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $(document).ready(function () {
        var itemId = $('.item-content').data('listingid');
        $("#reportmyItem").load("<?php echo osc_base_url( true ) . '?page=ajax&action=runhook&hook=load_itemreport&itemId='; ?>" + itemId);
        $('.printAd').magnificPopup({
            disableOn: 700,
            type: 'iframe',
            mainClass: 'mfp-fade',
            removalDelay: 160,
            preloader: false,

            fixedContentPos: false
        });

        $('.qrcode').magnificPopup({
            disableOn: 700,
            type: 'image',
            mainClass: 'mfp-fade',
            removalDelay: 160,
            preloader: false,

            fixedContentPos: false
        });


        $("form[name=contact_form]").validate({
            rules: {
                message: {
                    required: true,
                    minlength: 20
                },
                yourEmail: {
                    required: true,
                    email: true
                },
                yourName: {
                    required: true,
                    minlength: 5
                }

            },
            messages: {
                yourEmail: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    email: "<?php _e( "Invalid email address" ); ?>."
                },
                message: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    minlength: "<?php _e( "Your Message is too short" ); ?>."
                },
                yourName: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    minlength: "<?php _e( "Your Name is too short" ); ?>."
                }
            },
            highlight: function (element) {
                $(element).closest('.form-group').addClass('has-error');
            },
            unhighlight: function (element) {
                $(element).closest('.form-group').removeClass('has-error');
            },
            errorElement: 'span',
            errorClass: 'help-block',
            errorPlacement: function (error, element) {
                if (element.parent('.input-group').length) {
                    error.insertAfter(element.parent());
                } else {
                    error.insertAfter(element);
                }
            }
        });
        $('.image-group').magnificPopup({
            type: 'image',
            closeOnContentClick: true,
            closeBtnInside: false,
            mainClass: 'mfp-no-margins mfp-with-zoom', // class to remove default margin from left and right side
            gallery: {
                enabled: true
            },
            image: {
                verticalFit: true
            },
            zoom: {
                enabled: true,
                duration: 300 // don't forget to change the duration also in CSS
            }
        });


    });
</script>
<script>
    var mapScriptLoaded = false;

    function outputlocation() {

        $('#itemLocation').one('shown.bs.modal', function () {

            var script = document.createElement('script');
            script.setAttribute('type', 'text/javascript');
            script.setAttribute('src', 'https://maps.googleapis.com/maps/api/js?key=<?php echo trim( tfc_getPref( 'googlemap_key' ) );?>&callback=initializeMap');
            document.head.appendChild(script);
            var mapScriptLoaded = true;

        });
    }

    $('.map-button').click(function () {
        if (typeof google === 'object' && typeof google.maps === 'object') {

        }
        else {
            outputlocation();
        }
    });
</script>

<?php if ( $item[ 'd_coord_lat' ] != '' && $item[ 'd_coord_long' ] != '' ) { ?>
    <script type="text/javascript">
        //var center = new google.maps.LatLng(<?php echo $item[ 'd_coord_lat' ]; ?>, <?php echo $item[ 'd_coord_long' ]; ?>);

        function initializeMap() {
            var center = new google.maps.LatLng(<?php echo $item[ 'd_coord_lat' ]; ?>, <?php echo $item[ 'd_coord_long' ]; ?>);

            var mapOptions = {
                zoom: 15,
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                center: center,
                size: new google.maps.Size(480, 340),
                animation: google.maps.Animation.DROP
            };

            map = new google.maps.Map(document.getElementById('itemMap'), mapOptions);

            var marker = new google.maps.Marker({
                map: map,
                position: center
            });
            google.maps.event.trigger(map, 'resize');
            map.setCenter(center);
        }
    </script>
<?php } else { ?>
    <script type="text/javascript">
        function initializeMap() {
            var map = null;
            var geocoder = null;

            var myOptions = {
                zoom: 15,
                center: new google.maps.LatLng(37.4419, -122.1419),
                mapTypeId: google.maps.MapTypeId.ROADMAP,
                size: new google.maps.Size(480, 340)
            }

            map = new google.maps.Map(document.getElementById("itemMap"), myOptions);
            geocoder = new google.maps.Geocoder();


            function showAddress(address) {
                if (geocoder) {
                    geocoder.geocode({'address': address}, function (results, status) {
                        if (status == google.maps.GeocoderStatus.OK) {
                            map.setCenter(results[0].geometry.location);
                            var marker = new google.maps.Marker({
                                map: map,
                                position: results[0].geometry.location
                            });
                            marker.setMap(map);
                        } else {
                            $("#itemMap").remove();
                        }
                    });
                }
            }

			<?php
			$addr = array ();
			if ( ( $item[ 's_address' ] != '' ) && ( $item[ 's_address' ] != null ) ) {
				$addr[] = $item[ 's_address' ];
			}
			if ( ( $item[ 's_city' ] != '' ) && ( $item[ 's_city' ] != null ) ) {
				$addr[] = $item[ 's_city' ];
			}
			if ( ( $item[ 's_zip' ] != '' ) && ( $item[ 's_zip' ] != null ) ) {
				$addr[] = $item[ 's_zip' ];
			}
			if ( ( $item[ 's_region' ] != '' ) && ( $item[ 's_region' ] != null ) ) {
				$addr[] = $item[ 's_region' ];
			}
			if ( ( $item[ 's_country' ] != '' ) && ( $item[ 's_country' ] != null ) ) {
				$addr[] = $item[ 's_country' ];
			}
			$address = implode( ", " , $addr );
			?>

            $(document).ready(function () {
                showAddress('<?php echo osc_esc_js( $address ); ?>');
            });
        }

    </script>
<?php } ?>
</body>
</html>