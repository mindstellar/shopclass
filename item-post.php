<?php

    use shopclass\includes\frm\tfcItemForm;

    $action = 'item_add_post';
    $edit   = false;
    if ( Params::getParam( 'action' ) == 'item_edit' ) {
        $action = 'item_edit_post';
        $edit   = true;
    }
    osc_current_web_theme_path( 'head.php' ); ?>
<body>
<!-- end only item-post.php -->
<?php osc_current_web_theme_path( 'header.php' ); ?>
<form class="tfc-form" name="item" action="<?php echo osc_base_url( true ); ?>" method="post"
      enctype="multipart/form-data">
    <input type="hidden" name="action" value="<?php echo $action; ?>"/>
    <?php if ( $edit ) { ?>
        <input type="hidden" name="id" value="<?php echo osc_item_id(); ?>"/>
        <input type="hidden" name="secret" value="<?php echo osc_item_secret(); ?>"/>
    <?php } ?>
    <input type="hidden" name="page" value="item"/>
    <div class="content add_item row">
        <div class="main-content col-md-8 tfc-item">
            <h3><?php _e( 'Publish a listing' , 'shopclass' ); ?></h3>
            <div class="general_info col-md-12">
                <h4><i class="fa fa-info-circle"></i><?php _e( 'General Information' , 'shopclass' ); ?></h4>
                <div class="form-group">
                    <label><?php _e( 'Category' , 'shopclass' ); ?> *</label>
                    <div>
                        <?php if ( tfc_getPref( 'cat_selection' ) ) {
                            tfcItemForm::category_multiple_selects();
                        } else {
                            tfcItemForm::category_select( null , null , __( 'Select a category' , 'shopclass' ) );
                        } ?>
                    </div>
                </div>

                <?php tfcItemForm::multilanguage_title_description(); ?>

            </div>
            <div class="custom form-group col-md-12">
                <?php
                    if ( $edit ) {
                        ItemForm::plugin_edit_item();
                    } else {
                        ItemForm::plugin_post_item();
                    } ?>
            </div>
            <?php if ( osc_images_enabled_at_items() ) { ?>
                <div class="photos box form-group col-md-12">
                    <h4><i class="fa fa-camera"></i><?php _e( 'Photos' , 'shopclass' ); ?></h4>
                    <div class="form-group">
                        <?php if ( osc_images_enabled_at_items() ) {
                            ItemForm::ajax_photos();
                        } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
        <div class="side-content col-md-4">
            <div class="col-md-12 tfc-item">
                <div class="seller-info form-group">
                    <?php if ( ! osc_is_web_user_logged_in() ) { ?>
                        <h4><i class="fa fa-user"></i><?php _e( 'Your info' , 'shopclass' ); ?></h4>
                        <div class="form-group">
                            <label for="contactName"><?php _e( 'Name' , 'shopclass' ); ?></label>
                            <?php tfcItemForm::contact_name_text(); ?>
                        </div>
                        <div class="form-group">
                            <label for="contactEmail"><?php _e( 'E-mail' , 'shopclass' ); ?> *</label>
                            <?php tfcItemForm::contact_email_text(); ?>
                        </div>
                        <div class="form-group checkbox">
                            <label for="showEmail"><?php tfcItemForm::show_email_checkbox(); ?><?php _e( 'Show e-mail on the item page' , 'shopclass' ); ?></label>
                        </div>
                    <?php }; ?>
                </div>
                <?php if ( osc_price_enabled_at_items() ) { ?>
                    <div class="row">
                        <div class="price form-group">
                            <h4 class="col-xs-12"><i class="fa fa-money"></i><?php _e( 'Price' , 'shopclass' ); ?></h4>
                            <div class="col-xs-7">
                                <?php tfcItemForm::price_input_text(); ?>
                            </div>
                            <div class="col-xs-5">
                                <?php tfcItemForm::currency_select(); ?>
                            </div>
                        </div>
                    </div>
                <?php } ?>
                <div class="location form-group">
                    <h4><i class="fa fa-map-marker"></i><?php _e( 'Item Location' , 'shopclass' ); ?></h4>
                    <div class="form-group">
                        <label for="countryId"><?php _e( 'Country' , 'shopclass' ); ?></label>
                        <?php tfcItemForm::country_select( osc_get_countries() , osc_user() ); ?>
                    </div>
                    <?php if ( ! ( tfc_getPref( 'searchbar_layout' ) ) ) { ?>
                        <div class="form-group">
                            <label for="region"><?php _e( 'State/Region' , 'shopclass' ); ?></label>
                            <?php tfcItemForm::region_text( osc_user() ); ?>
                        </div>
                        <div class="form-group">
                            <label for="city"><?php _e( 'City' , 'shopclass' ); ?></label>
                            <?php tfcItemForm::city_text( osc_user() ); ?>
                        </div>
                    <?php } else {
                        if ( $edit ) { ?>
                            <div class="form-group">
                                <label for="regionId"><?php _e( 'State/Region' , 'shopclass' ); ?></label>
                                <?php tfcItemForm::region_select( osc_get_regions( osc_item_country() ) , osc_user() ); ?>
                            </div>
                            <div class="form-group">
                                <label for="cityId"><?php _e( 'City' , 'shopclass' ); ?></label>
                                <?php tfcItemForm::city_select( osc_get_cities( osc_item_region_id() ) , osc_user() ); ?>
                            </div>
                        <?php } else { ?>
                            <div class="form-group">
                                <label for="regionId"><?php _e( 'State/Region' , 'shopclass' ); ?></label>
                                <?php tfcItemForm::region_select( osc_get_regions( osc_user_country() ) , osc_user() ); ?>
                            </div>
                            <div class="form-group">
                                <label for="cityId"><?php _e( 'City' , 'shopclass' ); ?></label>
                                <?php tfcItemForm::city_select( osc_get_cities( osc_user_region_id() ) , osc_user() ); ?>
                            </div>
                        <?php }
                    } ?>
                    <div class="form-group">
                        <label for="cityArea"><?php _e( 'City Area' , 'shopclass' ); ?></label>
                        <?php tfcItemForm::city_area_text( osc_user() ); ?>
                    </div>
                    <div class="form-group">
                        <label for="address"><?php _e( 'Address' , 'shopclass' ); ?></label>
                        <?php tfcItemForm::address_text( osc_user() ); ?>
                    </div>
                </div>
                <div class=" form-group">
                    <?php osc_run_hook( 'tf_after_form' ); ?>
                </div>
                <?php if ( osc_recaptcha_items_enabled() ) { ?>
                    <div class="form-group">
                        <div class="recaptcha">
                            <?php osc_show_recaptcha(); ?>
                        </div>
                    </div>
                <?php } ?>
                <?php if ( $edit ) { ?>
                    <button class="item-submit btn btn-danger"
                            type="submit"><?php _e( 'Update' , 'shopclass' ); ?></button>
                    <a class=" btn btn-warning" href="javascript:history.back(-1)"
                       class="go_back"><?php _e( 'Cancel' , 'shopclass' ); ?></a>
                <?php } else { ?>
                    <button class="item-submit btn btn-success"
                            type="submit"><?php _e( 'Publish' , 'shopclass' ); ?></button>
                <?php } ?>
                <div class="form-group"></div>
            </div>
        </div>
    </div>
</form>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<!-- only item-post.php -->
<?php $searchbar_layout = tfc_getPref( 'searchbar_layout' );
    switch ( $searchbar_layout ) {
        case 1:
            tfcItemForm::location_javascript();
            break;
        case 2:
            tfcItemForm::location_javascript();
            break;
        default:
            tfcItemForm::location_javascript_new();

    }
?>
<script>
    $(document).ready(function () {

        $('#price').on('keypress', function (ev) {
            var keyCode = window.event ? ev.keyCode : ev.which;
            //codes for 0-9
            if (keyCode < 48 || keyCode > 57) {
                //codes for backspace, delete, enter, decimals
                if (keyCode != 46 && keyCode != 0 && keyCode != 8 && keyCode != 13 && !ev.ctrlKey) {
                    ev.preventDefault();
                }
            }
        });
    });
</script>
</body>
</html>