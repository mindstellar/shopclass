<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$aCountries = __get('aCountries');

osc_admin_page(array(
    'section' => __('Listings'),
    'title'   => __('Locations'),
    'help'    => __("Add, edit or delete the countries, regions and cities installed on your Shopclass. "
                    . '<strong>Be careful</strong>: modifying locations can cause your statistics to be incorrect '
                    . "until they're recalculated. Modify only if you're sure what you're doing!"),
    'actions' => array(
        array(
            'icon'  => 'bi-plus-circle-fill',
            'url'   => '#',
            'title' => __('Import new'),
            'attrs' => array('id' => 'b_import'),
        ),
    ),
));
osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Locations')); ?>
    <!-- settings form -->
    <div id="settings_form" class="locations">
        <div class="row g-1">
            <div class="col-md-4">
                <div class="row-wrapper">
                    <div class="widget-box">
                        <div class="widget-box-title">
                            <span><?php _e('Countries'); ?></span>
                            <a id="b_new_country" class="mx-2 btn btn-sm btn-outline-primary float-right" href="#" title="<?php _e('Add new'); ?>">
                                <i class="bi bi-plus-circle"></i></a>
                            <a id="b_remove_country" class="btn btn-sm btn-outline-danger float-right hide" href="#"
                               title="<?php _e('Remove selected'); ?>">
                                <i class="bi bi-trash"></i></a>
                        </div>
                        <div class="widget-box-content p-0">
                            <div id="l_countries" class="list-group list-group-flush">
                                <?php if (empty($aCountries)) { ?>
                                    <div class="list-group-item text-muted">
                                        <?php _e('No countries installed yet. Use "Add new" above to add one.'); ?>
                                    </div>
                                <?php } ?>
                                <?php foreach ($aCountries as $country) { ?>
                                    <div class="list-group-item" id="country-<?php echo osc_esc_html($country['pk_c_code']); ?>"
                                         data-id="<?php echo osc_esc_html($country['pk_c_code']); ?>" data-s-name="<?php echo osc_esc_html($country['s_name']); ?>"
                                         data-s-slug="<?php echo osc_esc_html($country['s_slug']); ?>">
                                        <input class="form-check-input me-1" name="country[]" type="checkbox"
                                               onclick="checkLocations('l_countries');"
                                               value="<?php echo $country['pk_c_code']; ?>">
                                        <a class="close" data-id="<?php echo $country['pk_c_code']; ?>"
                                           title="<?php echo osc_esc_html(__('Delete')); ?>" href="#"
                                           onclick="deleteLocations(this,'country');"
                                        ><i class="bi bi-x-circle-fill"
                                            title="<?php echo osc_esc_html(__('Delete')); ?>"></i></a>
                                        <a class="edit mx-1" href="#" data-id="<?php echo $country['pk_c_code']; ?>"
                                           onclick="editLocations(this,'country');"
                                           title="<?php echo osc_esc_html(__('Edit')); ?>"><?php echo $country['s_name']; ?></a>
                                        <a class="view-more float-end" href="#" data-id="<?php echo $country['pk_c_code']; ?>"
                                           onclick="showLocations('region',this)">
                                            <?php _e('View more'); ?>&raquo;
                                        </a>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row-wrapper">
                    <div class="widget-box">
                        <div class="widget-box-title">
                            <span><?php _e('Regions'); ?></span>
                            <a class="ms-2 btn btn-sm btn-outline-primary float-right hide" id="b_new_region" href="#" title="<?php _e('Add new');
?>">
                                <i class="bi bi-plus-circle"></i></a>
                            <a id="b_remove_region" class="btn btn-sm btn-outline-danger float-right hide" href="#"
                               title="<?php _e('Remove selected'); ?>">
                                <i class="bi bi-trash"></i></a>
                        </div>
                        <div class="widget-box-content p-0">
                            <div id="i_regions" class="list-group list-group-flush"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="row-wrapper">
                    <div class="widget-box">
                        <div class="widget-box-title">
                            <span><?php _e('Cities'); ?></span>
                            <a id="b_new_city" class="mx-2 btn btn-sm btn-outline-primary float-end hide" href="#" title="<?php _e('Add new'); ?>">
                                <i class="bi bi-plus-circle"></i></a>
                            <a id="b_remove_city" class="btn btn-sm btn-outline-danger hide float-end"
                               href="#" title="<?php _e('Remove selected'); ?>">
                                <i class="bi bi-trash"></i></a>
                        </div>
                        <div class="widget-box-content p-0">
                            <div id="i_cities" class="list-group list-group-flush"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <dialog id="locationModal" class="osc-dialog">
        <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <div class="osc-dialog-body">
                <p class="osc-dialog-title"></p>
                <div class="osc-dialog-content"></div>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button class="btn btn-submit btn-sm" type="submit"></button>
            </div>
        </form>
    </dialog>
    <!-- End form add country -->
    <script>
        // Location constant
        var baseUrl = "<?php echo osc_admin_base_url(); ?>";
        var jsonExistingCountries = <?php echo json_encode(Country::newInstance()->listNames()) ?>;
        var locationJsonUrl = "<?php echo osc_get_locations_json_url() ?>";
        var sCountry = "<?php echo Params::getParam('country')?>";
        var sCountryCode = "<?php echo Params::getParam('country_code')?>";
        var sRegionId = "<?php echo Params::getParam('region')?>";
        //common text vars
        var stringAddCity = '<?php echo osc_esc_js(__('Add city')); ?>';
        var stringAddCountry = '<?php echo osc_esc_js(__('Add country')); ?>';
        var stringAddRegion = '<?php echo osc_esc_js(__('Add region')); ?>';
        var stringCity = '<?php echo osc_esc_js(__('City')); ?>';
        var stringCityName = "<?php echo osc_esc_js(__('City Name')); ?>";
        var stringCountry = '<?php echo osc_esc_js(__('Country')); ?>';
        var stringCountryCode = '<?php echo osc_esc_js(__('Country code')); ?>';
        var stringCountryName = '<?php echo osc_esc_js(__('Country name')); ?>';
        var stringDelete = '<?php echo osc_esc_js(__('Delete')); ?>';
        var stringDeleteTitle = "<?php echo osc_esc_js(__('Delete selected locations')); ?>";
        var stringDeleteWarning = "<?php echo osc_esc_js(__("This action can't be undone. Items associated to this location will be deleted. "
                                . "Users from this location will be unlinked, but not deleted. Are you sure you want to continue?"));?>";
        var stringEdit = '<?php echo osc_esc_js(__('Edit')); ?>';
        var stringEnter = '<?php echo osc_esc_js(__('Enter')); ?>';
        var stringImport = '<?php echo osc_esc_js(__('Import')); ?>';
        var stringImportLocations = '<?php echo osc_esc_js(__('Import locations')); ?>';
        var stringImportWarning = "<?php echo osc_esc_js(__("Import a country with it's regions and cities from our database. "
                                . "Already imported countries aren't shown.")); ?>";
        var stringName = '<?php echo osc_esc_js(__("Name")); ?>';
        var stringRegion = '<?php echo osc_esc_js(__("Region")); ?>';
        var stringRegionName = '<?php echo osc_esc_js(__("Region name")); ?>';
        var stringSave = '<?php echo osc_esc_js(__("Save")); ?>';
        var stringSelectOption = '<?php echo osc_esc_js(__("Select option")); ?>';
        var stringSlug = '<?php echo osc_esc_js(__("Slug")); ?>';
        var stringSlugError = "<?php echo osc_esc_js(__("The slug is not unique."));?>";
        var stringSlugWarning = "<?php echo osc_esc_js(__("The slug has to be a unique string, could be left blank"));?>"
        var stringViewMore = "<?php echo osc_esc_js(__("View more")); ?>";
    </script>
<?php
osc_enqueue_script('admin-location');
osc_current_admin_theme_path('parts/footer.php'); ?>