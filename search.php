<?php

    use shopclass\includes\classes\tfcAdsLoop;
    use shopclass\includes\sphinx\SphSearch;

    osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="content list">
        <section class="col-lg-9 col-md-8 col-sm-12 pull-right">
            <div class="visible-sm visible-xs refine-btn">
                <a class="btn btn-block btn-danger" href="#searchrefineModal" data-toggle="modal"><i
                            class="fa fa-filter"></i><?php _e( 'Filter Listings' , 'shopclass' ) ?></a>
            </div>
            <div class="text-info category_desc">
                <p><?php echo osc_category_description( osc_current_user_locale() ); ?></p>
            </div>
            <div class="row">
                <div class="col-sm-8">
                    <?php if ( defined( 'SPHINX_SEARCH' ) ) {
                        if ( SPHINX_SEARCH ) { ?>
                            <h4><?php echo SphSearch::sphinx_search_title(); ?></h4>
                        <?php }
                    } else { ?>
                        <?php if ( search_title() ) { ?>
                            <h4><?php echo search_title(); ?></h4>
                        <?php }
                    } ?>
                </div>
                <div class="col-sm-4">
                    <div class="form-inline">
                        <div class="input-group pull-right">
                            <select name="sort-by" onchange="location = this.options[this.selectedIndex].value;"
                                    class="input-sm form-control">
                                <?php $i         = 0;
                                    $searchcatId = '';
                                    if ( osc_search_category_id() ) {
                                        $searchcatId = implode( osc_search_category_id() );
                                    }
                                    if ( Params::getParam( 'sPattern' ) && defined( 'SPHINX_SEARCH' ) ) {
                                        ?>
                                        <option value="<?php echo osc_esc_html( osc_update_search_url( array ( 'sOrder'     => '' ,
                                                                                                               'iOrderType' => ''
                                                                                                       ) ) ); ?>" <?php if ( Params::getParam( 'sOrder' ) == '' ) {
                                            echo 'selected';
                                        } ?> ><?php _e( 'Normal' , 'shopclass' ); ?></option>
                                        <option value="<?php echo osc_esc_html( osc_update_search_url( array ( 'sOrder'     => 'relevance' ,
                                                                                                               'iOrderType' => 'desc'
                                                                                                       ) ) ); ?>" <?php if ( Params::getParam( 'sOrder' ) == 'relevance' ) {
                                            echo 'selected';
                                        } ?> ><?php _e( 'Relevance' , 'shopclass' ); ?></option>
                                        <?php
                                    }
                                    $orders = osc_list_orders();
                                    foreach ( $orders as $label => $params ) {
                                        $params[ 'sCategory' ] = $searchcatId;
                                        $orderType             = ( $params[ 'iOrderType' ] == 'asc' ) ? '0' : '1'; ?>
                                        <?php if ( Params::getParam( 'sOrder' ) == $params[ 'sOrder' ] && osc_search_order_type() == $orderType ) { ?>
                                            <option selected
                                                    value="<?php echo osc_update_search_url( $params ); ?>"><?php echo $label; ?></option>
                                        <?php } else { ?>
                                            <option value="<?php echo osc_update_search_url( $params ); ?>"><?php echo $label; ?></option>
                                        <?php } ?>
                                        <?php if ( $i != count( $orders ) - 1 ) { ?>
                                        <?php } ?>
                                        <?php $i ++; ?>
                                    <?php } ?>
                            </select>
                            <div class="input-group-btn">
                                <a href="<?php echo osc_esc_html( osc_update_search_url( array ( 'sShowAs' => 'list' ) ) ); ?>"
                                   class="btn btn-danger btn-sm <?php if ( tfc_show_as() == 'list' ) {
                                       echo 'active';
                                   } ?>"><span><i class="fa fa-th-list"></i></span></a>
                                <a href="<?php echo osc_esc_html( osc_update_search_url( array ( 'sShowAs' => 'gallery' ) ) ); ?>"
                                   class="btn btn-danger btn-sm <?php if ( tfc_show_as() == 'gallery' ) {
                                       echo 'active';
                                   } ?>"><span><i class="fa fa-th"></i></span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row padding-top">
                <div class="ad_list">
                    <?php if ( tfc_getPref( 'enable_adba' ) && tfc_getPref( 'adsense_banner3' ) ) { ?>
                        <div class="adbox_ads">
                            <div class="adbox_ads">
                                <div class="adbox_box panel">

                                    <h3 class="panel-title"><?php _e( 'Sponsored Links' , 'shopclass' ); ?></h3>

                                    <div class="panel-body">
                                        <?php
                                            echo tfc_getPref( 'adsense_banner3' );

                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="clearfix"></div>
                    <?php } ?>
                    <div class="col-md-12">
                        <?php
                            tfcAdsLoop::newInstance( 'items' , tfc_show_as() , 'col-md-12' )->renderSearchLoop();
                        ?>
                    </div>
                    <div class="col-md-12">
                        <div class="paginate col-md-offset-1">
                            <?php echo tfc_search_pagination(); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <?php osc_current_web_theme_path( 'search_sidebar.php' ); ?>
    </div>
    <?php $footerLinks = osc_search_footer_links(); ?>
    <div class="col-md-12 footer-links">
        <?php foreach ( $footerLinks as $f ) {
            View::newInstance()->_exportVariableToView( 'footer_link' , $f ); ?>
            <?php if ( $f[ 'total' ] < 3 ) {
                continue;
            } ?>
            <a href="<?php echo osc_footer_link_url(); ?>"><?php echo osc_footer_link_title(); ?></a>,
        <?php } ?>
    </div>
    <?php osc_current_web_theme_path( 'footer.php' ); ?>
    <div class="modal fade" id="searchrefineModal" tabindex="-1" role="dialog" aria-labelledby="search-refine-modal"
         aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
                    <h4 class="modal-title"
                        id="search-refine-model"><?php _e( 'Refine Listing' , 'shopclass' ); ?></h4>
                </div>
                <div class="modal-body search-modal tfc-form">

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
    <script>
        $(document).ready(function () {
            moveFilter();
            $(window).resize(function () {
                moveFilter();
            });
        });

        function moveFilter() {
            if ($(".hidden-sm").css('display').toLowerCase() == 'none') {
                appendSearchFormModal(".search-modal", ".refine-cat", ".border-top-2p.tfc-item");
            }
            ;
            if ($(".hidden-sm").css('display').toLowerCase() != 'none') {
                appendSearchFormModal(".sidebar-search .tfc-form", ".refine-cat", ".border-top-2p.tfc-item");
            }
            ;
        }

        function appendSearchFormModal(apndInto, apndFirst, apndSecond) {
            if (!$(apndInto).find(apndFirst).length) {
                $(apndFirst).prependTo(apndInto);
            }
            if (!$(apndInto).find(apndSecond).length) {
                $(apndSecond).prependTo(apndInto);
            }
        }
    </script>
</body>
</html>