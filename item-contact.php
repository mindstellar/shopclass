<?php use shopclass\includes\frm\tfcContactForm;

	osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="container">

    <div class="content tfc-form user_forms">
        <div id="contact" class="inner">
            <legend><?php _e( 'Contact seller' , 'shopclass' ); ?></legend>
            <form class="form-horizontal col-md-8 col-md-offset-2" action="<?php echo osc_base_url( true ); ?>"
                  method="post" name="contact_form" id="contact_form">
                <fieldset class="form-group">
					<?php ContactForm::primary_input_hidden(); ?>
					<?php ContactForm::action_hidden(); ?>
					<?php ContactForm::page_hidden(); ?>
                    <h5><?php _e( 'To (seller)' , 'shopclass' ); ?>: <?php echo osc_item_contact_name(); ?></h5>
                    <h5><?php _e( 'Item' , 'shopclass' ); ?>: <a
                                href="<?php echo osc_item_url(); ?>"><?php echo osc_item_title(); ?></a></h5>
					<?php if ( osc_is_web_user_logged_in() ) { ?>
                        <input type="hidden" name="yourName" value="<?php echo osc_esc_html(osc_logged_user_name()); ?>"/>
                        <input type="hidden" name="yourEmail" value="<?php echo osc_logged_user_email(); ?>"/>
					<?php } else { ?>
                        <div class="form-group">
                            <label for="yourName"><?php _e( 'Your name' , 'shopclass' ); ?>:</label>
                            <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-user"></span>
												</span>
								<?php tfcContactForm::your_name(); ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="yourEmail"><?php _e( 'Your e-mail address' , 'shopclass' ); ?>:</label>
                            <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-envelope"></span>
												</span>
								<?php tfcContactForm::your_email(); ?>
                            </div>
                        </div>
					<?php }; ?>
                    <div class="form-group">
                        <label for="phoneNumber"><?php _e( 'Phone number' , 'shopclass' ); ?>
                            (<?php _e( 'optional' , 'shopclass' ); ?>):</label>
                        <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-phone"></span>
												</span>
							<?php tfcContactForm::your_phone_number(); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="message"><?php _e( 'Message' , 'shopclass' ); ?>:</label>
                        <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-comment"></span>
												</span>
							<?php tfcContactForm::your_message(); ?>
                        </div>
                    </div>
					<?php osc_run_hook( 'tf_after_form' ); ?>
					<?php osc_show_recaptcha(); ?>
                    <button type="submit" class="btn btn-primary"><?php _e( 'Send message' , 'shopclass' ); ?></button>
                </fieldset>
            </form>
        </div>
    </div>

</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $(document).ready(function () {
        $("form[name=contact_form]").validate({
            rules: {
                message: {
                    required: true,
                    minlength: 20
                },
                yourEmail: {
                    required: true,
                    email: true
                },
                yourName: {
                    required: true,
                    minlength: 5
                }

            },
            messages: {
                yourEmail: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    email: "<?php _e( "Invalid email address" ); ?>."
                },
                message: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    minlength: "<?php _e( "Your Message is too short" ); ?>."
                },
                yourName: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    minlength: "<?php _e( "Your Name is too short" ); ?>."
                }
            },
            highlight: function (element) {
                $(element).closest('.form-group').addClass('has-error');
            },
            unhighlight: function (element) {
                $(element).closest('.form-group').removeClass('has-error');
            },
            errorElement: 'span',
            errorClass: 'help-block',
            errorPlacement: function (error, element) {
                if (element.parent('.input-group').length) {
                    error.insertAfter(element.parent());
                } else {
                    error.insertAfter(element);
                }
            }
        });
    });
</script>
</body>
</html>