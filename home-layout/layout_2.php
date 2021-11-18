<div class="clearfix"></div>
<?php osc_current_web_theme_path( 'carousel.php' ); ?>
<!--/end-row-->
<div class="row category-box">
    <div class="text-center">
        <h2><i class="fa fa-certificate"></i><?php _e( 'All Categories' , 'shopclass' ) ?></h2>
        <p><?php _e( 'Check the list of all categories available for our listings.' , 'shopclass' ) ?></p>
    </div>
    <div class="col-md-12">

        <?php osc_count_categories(); ?>
        <?php while ( osc_has_categories() ) { ?>
            <div class="col-md-3 col-xs-6">
                <div class="catcard">
                    <div class="card-image tfc-item card-box">
                        <a href="<?php echo osc_search_category_url(); ?>">
                            <img class="img-responsive"
                                 src="<?php echo tfc_category_image_url( osc_category_id() ); ?>">
                        </a>
                        <span class="card-title text-center">
                    	<a href="<?php echo osc_search_category_url(); ?>"><?php echo osc_category_name(); ?></a>
							<?php if ( View::newInstance()->_current( 'categories' ) ) { ?><i
                                data-maincatid="<?php echo osc_category_id(); ?>" data-toggle="tooltip"
                                data-placement="top"
                                data-original-title="<?php _e( 'More' , 'shopclass' ) ?>"
                                class="subcat-modal fa fa-plus-circle"></i>
                            <?php } ?>
                    </span>
                    </div>

                </div>
            </div>
        <?php } ?>
        <div class="clearfix"></div>
        <h4 class="col-md-12"><a class="btn btn-primary cardbox-primary"
                                 href="<?php echo osc_search_show_all_url(); ?>"><?php _e( "See all offers" , 'shopclass' ); ?>
                &raquo;</a></h4>
    </div>
</div>
<!-- Modal -->
<div id="subCatModal" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <!-- Modal content-->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><?php _e( 'Sub Categories' , 'shopclass' ); ?></h4>
            </div>
            <div id="modalsubcatlist" class="list-group text-center">
                <i class="fa-spin fa fa-spinner" aria-hidden="true"></i> <?php _e( 'Loading' , 'shopclass' ); ?> ...
            </div>
        </div>
    </div>
</div>
<?php $subcat_modal_js = function () { ?>
    <script>
        function outputSubCatList(catId) {
            $('#subCatModal').one('shown.bs.modal', function () {
                $('#modalsubcatlist').load('<?php echo osc_base_url( true ) . "?page=ajax&action=runhook&hook=subcat_list"; ?>&maincatId=' + catId);
            });
        }

        $('.subcat-modal').click(function () {
            var catId = $(this).data('maincatid');
            $('#subCatModal').modal('show');
            outputSubCatList(catId);
        });
        $('#subCatModal').on('hidden.bs.modal', function () {
            $("#modalsubcatlist").html('<i class="fa-spin fa fa-spinner" aria-hidden="true"></i> <?php _e( 'Loading ' , 'shopclass ' ); ?> ...');
        });
    </script>
<?php };
    osc_add_hook( 'footer_scripts_loaded' , $subcat_modal_js ); ?>