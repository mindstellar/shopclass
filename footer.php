<div class="clearfix"></div>
<?php tfc_recent_viewed_ads(); ?>
</div>
<?php if ( osc_is_home_page() ) {
    include 'home-widget-box.php';
} ?>
<?php if ( osc_is_home_page() == false && osc_is_search_page() == false ) { ?>
    <div class="modal fade" id="searchbarModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="container">
            <div class="row" style="margin-top:10%;">
                <div class="searchinput col-md-8 col-md-offset-2" id="querypattern">
                    <form action="<?php echo osc_base_url( true ); ?>" method="get" class="nocsrf">
                        <input type="hidden" name="page" value="search">
                        <div class="input-group">
                            <input autocomplete="off" data-provide="typeahead" name="sPattern" type="text" id="query"
                                   class="form-control input-lg"
                                   placeholder="<?php echo osc_esc_html( tfc_getPref( 'keyword_placeholder' , true ) ); ?>"
                                   value="">
                            <div class="input-group-btn">
                                <button class="btn btn-danger btn-lg" type="submit"><i
                                            class="fa fa-search"></i><?php _e( 'Search' , 'shopclass' ); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
}
    if ( ! ( osc_is_home_page() || osc_is_search_page() ) ) { ?>
        <!-- Category Modal -->
        <div class="modal fade" id="categoryModal" tabindex="-1" role="dialog" aria-labelledby="tfc-cat-model"
             aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                        <h4 class="modal-title"
                            id="tfc-cat-model"><?php _e( 'Choose a category' , 'shopclass' ); ?></h4>
                    </div>
                    <div class="modal-body">
                        <?php
                            osc_get_non_empty_categories();
                            $total_categories = osc_count_categories();
                            $col1_max_cat     = ceil( $total_categories / 2 );
                            $col2_max_cat     = ceil( $total_categories - $col1_max_cat );
                        ?>
                        <div class="categories <?php echo 'c' . $total_categories; ?> clearfix">
                            <?php osc_goto_first_category(); ?>
                            <?php
                                $i   = 1;
                                $x   = 1;
                                $col = 1;
                                if ( osc_count_categories() > 0 ) {
                                    echo '<div class="col c1 col-md-6">';
                                }
                            ?>
                            <?php while ( osc_has_categories() ) { ?>
                                <div class="category">
                                    <ul class="toggle nav nav-pills nav-stacked">
                                        <li class="active">
                                            <a class="category cat_<?php echo osc_category_id(); ?>"
                                               href="<?php echo osc_search_category_url(); ?>">
                                                <?php echo osc_category_name(); ?>
                                                <?php if ( tfc_getPref( 'enable_category_count' ) ) { ?>
                                                    <span class="badge pull-right"> <?php echo osc_category_total_items(); ?></span>
                                                <?php } ?></a>
                                        <li>
                                            <?php if ( osc_count_subcategories() > 0 ) { ?>
                                            <?php while ( osc_has_subcategories() ) { ?>
                                        <li><a class="category cat_<?php echo osc_category_id(); ?>"
                                               href="<?php echo osc_search_category_url(); ?>"><?php echo osc_category_name(); ?>
                                                <?php if ( tfc_getPref( 'enable_category_count' ) ) { ?>
                                                    <span class="badge pull-right"> <?php echo osc_category_total_items(); ?></span>
                                                <?php } ?>
                                            </a>
                                        </li>
                                        <?php } ?>
                                        <?php } ?>
                                    </ul>
                                </div>
                                <?php
                                if ( ( $col == 1 && $i == $col1_max_cat ) || ( $col == 2 && $i == $col2_max_cat ) ) {
                                    $i = 1;
                                    $col ++;
                                    echo '</div>';
                                    if ( $x < $total_categories ) {
                                        echo '<div class="col c' . $col . ' col-md-6" >';
                                    }
                                } else {
                                    $i ++;
                                }
                                $x ++;
                                ?>
                            <?php } ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger btn-xs"
                                data-dismiss="modal"><?php _e( 'Close' , 'shopclass' ); ?></button>
                    </div>
                </div>
                <!-- /.modal-content -->
            </div>
            <!-- /.modal-dialog -->
        </div>
        <!-- /.modal -->
        <?php
    } ?>
<?php osc_show_widgets( 'footer' ); ?>
<div class="full_width_footer hidden-xs"></div>
<?php if ( defined( 'DEMO' ) ) {
    tfc_demo_layout();
}; ?>
<!--Footer -->
<div class="col-md-12 footer-box">
    <?php
        osc_get_categories();
        echo '<div class="hidden-xs ">';
        tfc_popular_category_links();
        echo '</div>';
    ?>
    <div class="row">
        <div class="col-sm-4 text-center">
            <div class="footer1"><?php osc_show_widgets( 'footer1' ); ?></div>
            <h4><?php _e( 'Pages' , 'shopclass' ); ?> </h4>

            <ul class="list-unstyled">
                <li><a href="<?php echo osc_contact_url(); ?>"><?php _e( 'Contact Us' , 'shopclass' ); ?></a></li>
                <?php osc_reset_static_pages(); ?>
                <?php while ( osc_has_static_pages() ) { ?>
                    <li><a href="<?php echo osc_static_page_url(); ?>"><?php echo osc_static_page_title(); ?></a></li>
                <?php } ?>
                <li>
                    <a href="<?php echo osc_item_post_url_in_category(); ?>"><?php _e( "Post Free Ad" , 'shopclass' ); ?></a>
                </li>
                <?php if ( file_exists( ABS_PATH . "sitemapindex.xml" ) ) { ?>
                    <li><a href="<?php echo osc_base_url(); ?>sitemapindex.xml"
                           target="_blank"><?php _e( "Browse Sitemap" , 'shopclass' ); ?></a></li>
                <?php } ?>
                <?php
                    if ( tfc_getPref( 'footer_link' ) ) {
                        echo '<li><a title="' . __( 'OSClass web' , 'shopclass' ) . '" href="http://osclass.org/">' . __( 'Created with OSClass' , 'shopclass' ) . '</a></li>';
                    }
                ?>
            </ul>
        </div>
        <div class="col-sm-4 text-center">
            <div class="footer2"><?php osc_show_widgets( 'footer2' ); ?></div>
            <h4><?php _e( 'Our Location' , 'shopclass' ) ?></h4>
            <?php if ( ! tfc_getPref( 'tfc-address' ) ) {
                ?>
                <address>
                    <strong>ShopClass.com</strong>
                    234- Local Street, Bareilly
                    Just Location, INDIA 243xxx
                    <abbr title="Phone">
                        P:</abbr>
                    (+91 581) 258-xxxx
                </address>
                <div>
                    <strong>Full Name</strong><br>
                    Navjot Tomer
                </div>
            <?php } else {
                echo tfc_getPref( 'tfc-address' );
            }
            ?>
        </div>
        <div class="col-sm-4 social-box text-center">
            <h4><?php _e( "We are Social !!!" , 'shopclass' ); ?></h4>

            <a href="https://www.facebook.com/<?php echo tfc_getPref( 'facebook_fanpage' ); ?>" target="blank"><i
                        data-toggle="tooltip" data-placement="top"
                        data-original-title="<?php _e( "Like Us" , 'shopclass' ); ?>"
                        class="fa fa-facebook-square fa-3x rotate"></i></a>
            <a href="https://twitter.com/<?php echo tfc_getPref( 'twitter_username' ); ?>" target="blank"><i
                        data-toggle="tooltip" data-placement="top"
                        data-original-title="<?php _e( "Follow Us" , 'shopclass' ); ?>"
                        class="rotate fa fa-twitter-square fa-3x"></i></a>
            <a href="<?php echo osc_base_url(); ?>feed" target="blank"><i data-toggle="tooltip" data-placement="top"
                                                                          data-original-title="<?php _e( "Subscribe Us" , 'shopclass' ); ?>"
                                                                          class="rotate fa fa-rss-square fa-3x"></i></a>

            <p>    <?php echo tfc_getPref( 'footer_message' , true ); ?> </p>
        </div>
    </div>
</div>
<!-- /.col -->
<div class="col-md-12 end-box ">
    <?php _e( "Copyright © " , 'shopclass' );
        auto_copyright(); ?>
    | &nbsp; <?php _e( 'All Rights Reserved' , 'shopclass' ); ?>
    | &nbsp; <abbr
            title="<?php _e( 'Free Classified Online' , 'shopclass' ); ?>"><?php echo $_SERVER[ 'HTTP_HOST' ] ?></abbr>
    | &nbsp; 24x7 <?php _e( 'support' , 'shopclass' ); ?>
    | &nbsp; <?php _e( 'Email us' , 'shopclass' ); ?>
    : <?php echo tfc_getPref( 'footer_link_email' ) ? tfc_getPref( 'footer_link_email' ) : 'info[at]yourdomain.com'; ?>
    <?php if ( osc_count_web_enabled_locales() > 1 ) { ?>
        | <span class="dropup">
            <span class="dropdown-toggle btn-sm" type="button" id="dropdownMenu2" data-toggle="dropdown"
                  aria-haspopup="true" aria-expanded="false">
                <span><i class="fa fa-keyboard-o"></i><?php echo osc_current_user_locale() ?><i
                            class="fa fa-caret-up"></i></span>
            </span>
            <ul class="dropdown-menu dropdown-menu-left" aria-labelledby="dropdownMenu2">
                <?php $i = 0; ?>
                <?php osc_goto_first_locale(); ?>
                <?php while ( osc_has_web_enabled_locales() ) { ?>
                    <li class="<?php if ( $i == 0 ) {
                        echo "first";
                    } ?> <?php echo osc_locale_code() == osc_current_user_locale() ? 'disabled' : ''; ?>"><a
                                id="<?php echo osc_locale_code(); ?>"
                                href="<?php echo osc_change_language_url( osc_locale_code() ); ?>"><?php echo osc_locale_field( 's_short_name' ); ?></a></li>
                    <?php $i ++;
                    echo ' ';
                } ?>
            </ul>
        </span>
    <?php } ?>
</div>
<span id="back-top"><a href="#tfc-top"><i data-toggle="tooltip" data-placement="top"
                                          data-original-title="<?php _e( "Scroll To Top" , 'shopclass' ); ?>"
                                          class="hidden-xs text-primary fa fa-chevron-circle-up fa-3x"></i></a></span>
<?php osc_run_hook( 'footer' ); ?>
<?php osc_show_flash_message(); ?>
<script>$(function () {
        $('body').on('close.bs.alert', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(e.target).slideUp();
        });
    });
</script>