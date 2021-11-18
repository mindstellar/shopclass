<?php if ( osc_count_items() > 0 ) { ?>
    <div class="col-md-12 recently-viewed-ads">
        <div class="panel panel-body">
            <h2 class="text-center title">
                <i class="fa fa-bookmark"></i><?php _e( 'Recently Viewed' , 'shopclass' ); ?>
            </h2>
            <?php
                while ( osc_has_items() ) {
                    ?>
                    <div class="col-md-2 col-xs-6 col-sm-3" data-mh="photo-recent">
                        <div class="thumbnail">
                            <div class="img-container" data-toggle="tooltip" data-animation="true" data-placement="top"
                                 data-original-title="<?php echo osc_highlight( osc_item_title() , 70 ); ?>">
                                <?php if ( osc_images_enabled_at_items() ) { ?>
                                    <?php if ( osc_count_item_resources() ) { ?>
                                        <span class="box_badge"><i
                                                    class="fa fa-camera fa-fw"></i><?php echo osc_count_item_resources(); ?></span>
                                        <a href="<?php echo osc_item_url(); ?>">
                                            <img src="<?php echo osc_resource_thumbnail_url(); ?>"
                                                 alt="<?php echo osc_esc_html( osc_item_title() ); ?>"/></a>
                                    <?php } else { ?>
                                        <a href="<?php echo osc_item_url(); ?>">
                                            <img src="<?php echo tfc_category_image_thumbnail_url( tfc_item_parent_category_id() ); ?>"
                                                 alt="No image available"/>
                                        </a>
                                    <?php }
                                } ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
        </div>
    </div>
<?php } ?>
<script>
    $.fn.matchHeight._update();
    $('[data-toggle="tooltip"]').tooltip();
</script>