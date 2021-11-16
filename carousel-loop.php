<div class="row">
    <div class="col-md-12">
        <div id="tfc-caraousel" style="display:none">
            <h2 class="text-center"><?php _e ( 'Featured Listing' , 'shopclass' ); ?></h2>
            <p class="text-center"><?php _e ( 'Carefully handpicked listings for you.' , 'shopclass' ); ?></p>
            <div id="nav-crsl" class="crsl-nav fa-2x">
                <a class="next left" href="#"><i class="fa fa-chevron-circle-left"></i></a>
                <a class="previous right" href="#"><i class="fa fa-chevron-circle-right"></i></a>
            </div>
            <div class="col-md-12">
                <div class="crsl-items" data-navigation="nav-crsl">
                    <div class="crsl-wrap">
                        <?php
                            While (osc_has_items ()) {
                                ?>
                                <figure class="crsl-item">
                                    <div class="flag-topright flag-carousel"><i class="flag-pin fa fa-flash"></i></div>
                                    <?php if ( osc_images_enabled_at_items () ) { ?>
                                        <?php if ( osc_count_item_resources () ) { ?>
                                            <a href="<?php echo osc_item_url (); ?>">
                                                <img class="img-responsive "
                                                     src="<?php echo osc_resource_thumbnail_url (); ?>"
                                                     alt="<?php echo osc_esc_html(osc_item_title ()); ?>"/>
                                            </a>
                                        <?php } else { ?>
                                            <a href="<?php echo osc_item_url (); ?>">
                                                <img class="img-responsive"
                                                     src="<?php echo tfc_category_image_thumbnail_url(tfc_item_parent_category_id()); ?>" alt="No image available"/>
                                            </a>
                                        <?php }
                                    } ?>
                                    <figcaption class="text-center">
                                        <h3 class="panel-title text-ellipsis"><a class="text-capitalize"
                                                                                 href="<?php echo osc_item_url (); ?>"><?php echo osc_highlight ( osc_item_title () , 45 ); ?></a>
                                        </h3>
                                        <ul class="list-group list-unstyled">
                                            <li><?php echo tfc_time_ago ( osc_item_pub_date () ); ?></li>
                                            <li class="h5 text-info"><?php if ( osc_price_enabled_at_items () ) { ?><?php _e ( "Price" , 'shopclass' ); ?>: <?php echo osc_item_formated_price (); ?><?php } ?></li>
                                        </ul>
                                    </figcaption>
                                </figure>
                            <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<hr>