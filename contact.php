<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="col-md-12 tfc-item">
    <div class="col-md-8">
        <h3 class="tfc-title"><?php _e( 'Contact us' , 'shopclass' ); ?></h3>
        <div class="well well-sm">
            <form action="<?php echo osc_base_url( true ); ?>" method="post" name="contact_form" id="contact">
                <fieldset>
                    <input type="hidden" name="page" value="contact"/>
                    <input type="hidden" name="action" value="contact_post"/>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="yourName">
                                    <?php _e( 'Your name' , 'shopclass' ); ?></label>
                                <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-user"></span>
												</span>
                                    <input class="form-control" id="yourName"
                                           placeholder="<?php echo osc_esc_html( __( 'Enter name' , 'shopclass' ) ); ?>"
                                           type="text" name="yourName" value=""/>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="yourEmail">
                                    <?php _e( 'E-mail address' , 'shopclass' ); ?></label>
                                <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-envelope"></span>
												</span>
                                    <input id="yourEmail" type="text" name="yourEmail" class="form-control"
                                           placeholder="<?php echo osc_esc_html( __( 'Enter E-mail' , 'shopclass' ) ); ?>"
                                           value=""/>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="subject">
                                    <?php _e( 'Subject' , 'shopclass' ); ?></label>
                                <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-inbox"></span>
												</span>
                                    <input class="form-control" id="subject" type="text" name="subject"
                                           placeholder="<?php echo osc_esc_html( __( 'Enter Subject' , 'shopclass' ) ); ?>"
                                           value=""/>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="message">
                                    <?php _e( 'Message' , 'shopclass' ); ?></label>
                                <textarea id="message" name="message" class="form-control" rows="9" cols="25"
                                          placeholder="<?php echo osc_esc_html( __( 'Enter your message' , 'shopclass' ) ); ?>"></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php osc_run_hook( 'tf_after_form' ); ?>
                            <?php osc_show_recaptcha(); ?>
                            <?php osc_run_hook( 'admin_contact_form' ); ?>
                            <hr>
                            <button type="submit" class="btn btn-primary col-md-offset-3">
                                <?php _e( 'Send Message' , 'shopclass' ); ?></button>
                        </div>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>
    <div class="col-md-4 padding-top">
        <?php if ( ! tfc_getPref( 'tfc-address' ) ) {
            ?>
            <address>
                <strong>ShopClass.com</strong>
                234- Local Street, Bareilly
                Just Location, INDIA 243xxx
                <abbr title="Phone">
                    P:</abbr>
                (+91 581) 258-xxxx
            </address>
            <div>
                <strong>Full Name</strong><br>
                Navjot Tomer
            </div>
        <?php } else {
            echo tfc_getPref( 'tfc-address' );
        }
        ?>
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
                },

                subject: {
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
                },
                subject: {
                    required: "<?php _e( "This field is required" ); ?>.",
                    minlength: "<?php _e( "Your Subject is too short" ); ?>."
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
