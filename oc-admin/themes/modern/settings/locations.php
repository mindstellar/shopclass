<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
$aCountries = __get('aCountries');

function addHelp()
{
    echo '<p>'
         . __("Add, edit or delete the countries, regions and cities installed on your Osclass. "
              . '<strong>Be careful</strong>: modifying locations can cause your statistics to be incorrect '
              . "until they're recalculated. Modify only if you're sure what you're doing!")
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-end" data-bs-target="#help-box" data-bs-toggle="collapse" href="#help-box"></a>
        <a id="b_import" class="ms-1 text-success float-end" href="#" title="<?php _e('Import new'); ?>"><i
                    class="bi bi-plus-circle-fill"></i></a>
    </h1>
    <?php
}

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Locations &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');
osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- container -->
    <h1 class="render-title"><?php _e('Locations'); ?></h1>
    <!-- grid close -->
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
                <button class="btn btn-primary btn-sm" type="submit"><?php echo __('Delete'); ?></button>
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