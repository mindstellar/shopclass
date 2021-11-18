<div class="panel panel-default tfc-item alert-form">
    <div class="panel-heading">
        <h4 class="panel-title">
            <?php _e( 'Subscribe to this search' , 'shopclass' ); ?>
        </h4>
    </div>
    <div class="panel-body">
        <form action="<?php echo osc_base_url( true ); ?>" method="post" name="sub_alert" id="sub_alert">
            <fieldset class="form-group">
                <?php AlertForm::page_hidden(); ?>
                <?php AlertForm::alert_hidden(); ?>
                <?php if ( osc_is_web_user_logged_in() ) { ?>
                    <?php AlertForm::user_id_hidden(); ?>
                    <?php AlertForm::email_hidden(); ?>
                <?php } else { ?>
                    <?php AlertForm::user_id_hidden(); ?>
                    <?php AlertForm::email_text(); ?>
                <?php }; ?>
            </fieldset>
            <button type="submit" class="btn btn-danger sub_button"><?php _e( 'Subscribe now' , 'shopclass' ); ?>!
            </button>
        </form>
    </div>
</div>
<?php osc_add_hook( 'footer_scripts_loaded' , 'tfc_alert_subscribe_js' ); ?>
