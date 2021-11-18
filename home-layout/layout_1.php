<?php osc_current_web_theme_path( 'carousel.php' ); ?>
<div class="row">
    <section class="col-md-3 left-sidebar" style="margin-bottom:15px;">
        <?php if ( osc_count_categories() > 0 ) { ?>
            <h2><i class="fa fa-certificate"></i><?php _e( 'Categories' , 'shopclass' ); ?></h2>
            <div class="panel-group category-products" id="accordian-cat">
                <!--category-productsr-->
                <?php osc_goto_first_category();
                    while ( osc_has_categories() ) { ?>
                        <div class="panel panel-default cardbox-default">
                            <div class="panel-heading">
                                <h4 class="panel-title">
                                    <a href="<?php echo osc_search_category_url(); ?>">
                                        <?php echo osc_category_name(); ?>
                                    </a>
                                    <a data-toggle="collapse" data-parent="#accordian-cat"
                                       href="#cat-<?php echo osc_category_id(); ?>"
                                       class="badge badge-primary pull-right"><i class="fa fa-plus"></i></a>
                                </h4>
                            </div>
                            <div id="cat-<?php echo osc_category_id(); ?>"
                                 class="panel-collapse collapse content-listmenu">
                                <ul class="nav">
                                    <?php if ( osc_count_subcategories() > 0 ) { ?>
                                        <?php while ( osc_has_subcategories() ) { ?>
                                            <li>
                                                <a href="<?php echo osc_search_category_url(); ?>"><?php echo osc_category_name(); ?> </a>
                                            </li>
                                        <?php } ?>
                                    <?php } ?>
                                </ul>
                            </div>
                        </div>
                    <?php } ?>
            </div>
            <!--/category-products-->
        <?php }
            if ( tfc_getPref( 'enable_adba' ) ) {
                echo tfc_getPref( 'adsense_banner1' );
            }
        ?>
    </section>
    <?php //osc_current_web_theme_path('home-sidebar.php') ; ?>
    <section class="col-md-9">
        <h2 class="title text-center"><i class="fa fa-bullhorn"></i><?php _e( ' Latest Items' , 'shopclass' ); ?></h2>
        <?php
            Params::setParam( 'adloop_listclass' , '' );
            Params::setParam( 'adloop_galleryclass' , 'col-md-4 col-sm-6 col-xs-12' );
            osc_current_web_theme_path( 'mainpage-ad-loop.php' ); ?>
    </section>
</div>
<!--/end-row-->