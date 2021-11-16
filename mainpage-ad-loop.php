<?php

use shopclass\includes\classes\tfcAdsLoop;

$listclass    = Params::getParam('adloop_listclass');
$galleryclass = Params::getParam('adloop_galleryclass');
if (tfc_getPref('distinct_ad')) {
    osc_distinct_ad_loop();
    tfcAdsLoop::newInstance('items', tfc_show_as(), $listclass, $galleryclass)->renderLoop();
} else {
    tfcAdsLoop::newInstance('latest', tfc_show_as(), $listclass, $galleryclass)->renderLoop();
}
Params::unsetParam('adloop_listclass');
Params::unsetParam('adloop_galleryclass');
?>
<div id="load_more_10">
    <span class="col-md-offset-3"><a class="btn btn-primary cardbox-primary"
                                     href="<?php echo osc_search_show_all_url(); ?>"><?php _e("See all offers",
                'shopclass'); ?>
            &raquo;</a> </span>
    <?php if (!tfc_getPref('distinct_ad')) { ?>
        <span><button data-listclass="<?php echo $listclass; ?>" data-galleryclass="<?php echo $galleryclass; ?>"
                      class="btn btn-primary tfc_load_more cardbox-primary"
                      id="10"><?php _e("Load More Item", 'shopclass'); ?> &raquo;</button></span>
        <?php osc_add_hook('footer_scripts_loaded', 'tfc_load_more_js'); ?>
    <?php } ?>
</div>