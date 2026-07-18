<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}
$data = finish_installation($password);
?>
<?php if (Params::getParam('error_location') == 1) { ?>
    <script type="text/javascript">
        setTimeout(function() {
            $('.error-location').fadeOut('slow');
        }, 2500);
    </script>
    <div class="alert alert-warning shadow-sm mb-3">
        <?php _e('The selected location could not been installed'); ?>
    </div>
<?php } ?>
<h2 class="display-6 text-success"><?php _e('Congratulations!'); ?></h2>
<div class="alert alert-success shadow-sm mb3"><?php _e("Shopclass has been installed. Were you expecting more steps? Sorry to disappoint you!");
?></div>
<div class="alert alert-info shadow-sm mb-3">
    <?php echo sprintf(
        __('An e-mail with the password for oc-admin has been sent to: %s'),
        $data['s_email']
    ); ?></div>
<div class="finish">
    <div class="row mb-3">
        <span class="col-md-3 col-sm-6 h6"><?php _e('Username'); ?>: </span>
        <span class="col-md-4 col-sm-6"><?php echo $data['admin_user']; ?></span>
    </div>
    <div class="row mb-3">
        <span class="col-md-3 col-sm-6 h6"><?php _e('Password'); ?>: </span>
        <span class="col-md-4 col-sm-6"><?php echo osc_esc_html($data['password']); ?></span>
    </div>
    <div class="row mb-3">
        <a target="_blank" href="<?php echo get_absolute_url() ?>oc-admin/index.php" class="btn btn-primary"><?php _e('Finish and go to the administration panel'); ?></a>
    </div>
</div>