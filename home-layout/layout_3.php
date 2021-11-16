<?php
Params::setParam('adloop_listclass', 'col-md-6');
Params::setParam('adloop_galleryclass', 'col-md-3 col-sm-6 col-xs-12');
?>
<?php osc_current_web_theme_path('carousel.php'); ?>
<div class="row">
    <section class="col-md-12">
        <?php if (osc_count_categories() > 0) { ?>
            <h2 class="title text-center"><i class="fa fa-certificate"></i><?php _e('Categories', 'shopclass'); ?></h2>
            <div class=" main-layout-3 row">
                <!--category-productsr-->
                <?php while (osc_has_categories()) { ?>
                    <div class="panel col-md-3">
                        <div class="panel-heading cardbox-default">
                            <h4 class="panel-title">
                                <a href="<?php echo osc_search_category_url(); ?>">
                                    <?php echo osc_category_name(); ?>
                                </a>
                                <span data-maincatid="<?php echo osc_category_id(); ?>" class="subcat-modal cursor-pointer badge pull-right"><i class="fa fa-plus"></i></span>
                            </h4>
                        </div>
                    </div>
                <?php } ?>
            </div>
            <!--/category-products-->
        <?php }
        if (tfc_getPref('enable_adba')) {
            echo tfc_getPref('adsense_banner1');
        }
        ?>
    </section>
    <?php //osc_current_web_theme_path('home-sidebar.php') ; 
    ?>
    <section class="col-md-12 ads-section-3">
        <h2 class="title text-center"><i class="fa fa-bullhorn"></i><?php _e(' Latest Items', 'shopclass'); ?>
        </h2>
        <?php osc_current_web_theme_path('mainpage-ad-loop.php'); ?>
    </section>
</div>
<!-- Modal -->
<div id="subCatModal" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <!-- Modal content-->
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><?php _e('Sub Categories', 'shopclass'); ?></h4>
            </div>
            <div id="modalsubcatlist" class="list-group text-center">
                <i class="fa-spin fa fa-spinner" aria-hidden="true"></i> <?php _e('Loading', 'shopclass'); ?> ...
            </div>
        </div>
    </div>
</div>
<?php $subcat_modal_js = function () { ?>
    <script>
        function outputSubCatList(catId) {
            $('#subCatModal').one('shown.bs.modal', function() {
                $('#modalsubcatlist').load('<?php echo osc_base_url(true) . "?page=ajax&action=runhook&hook=subcat_list"; ?>&maincatId=' + catId);
            });
        }

        $('.subcat-modal').click(function() {
            var catId = $(this).data('maincatid');
            $('#subCatModal').modal('show');
            outputSubCatList(catId);
        });
        $('#subCatModal').on('hidden.bs.modal', function() {
            $("#modalsubcatlist").html('<i class="fa-spin fa fa-spinner" aria-hidden="true"></i> <?php _e('Loading ', 'shopclass '); ?> ...');
        });
    </script>
<?php };
osc_add_hook('footer_scripts_loaded', $subcat_modal_js);
Params::unsetParam('adloop_listclass');
Params::unsetParam('adloop_galleryclass');
?>