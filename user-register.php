<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-8 col-md-offset-2 tfc-item">
        <p class="text-center"><i class="fa fa-user fa-5x"></i></p>
        <div class="col-md-6">
            <div id="signupbox">
                <form class="form-horizontal" name="register" id="register" action="<?php echo osc_base_url( true ); ?>"
                      method="post">
                    <input type="hidden" name="page" value="register"/>
                    <input type="hidden" name="action" value="register_post"/>
                    <p class="lead form-group"><?php _e( 'Register now for' , 'shopclass' ); ?> <span
                                class="text-success"><?php _e( 'FREE' , 'shopclass' ); ?></span></p>
                    <ul id="error_list"></ul>
                    <div class="form-group">
                        <label for="s_name" class="control-label"><?php _e( 'Your Name' , 'shopclass' ); ?></label>

                        <input class="form-control" id="s_name" type="text" name="s_name" value=""
                               placeholder="<?php echo osc_esc_html( __( 'Enter your name' , 'shopclass' ) ); ?>">

                    </div>
                    <div class="form-group">
                        <label for="s_username" class="control-label"><?php _e( 'Username' , 'shopclass' ); ?></label>

                        <input class="form-control" id="s_username" type="text" value="" name="s_username"
                               placeholder="<?php echo osc_esc_html( __( 'Enter you username' , 'shopclass' ) ); ?>">
                        <div id="available" class="help-box"></div>

                    </div>
                    <div class="form-group">
                        <label for="s_password" class="control-label"><?php _e( 'Password' , 'shopclass' ); ?></label>

                        <input class="form-control" id="s_password" type="password" name="s_password"
                               placeholder="<?php echo osc_esc_html( __( 'Enter your password' , 'shopclass' ) ); ?>">

                    </div>
                    <div class="form-group">
                        <label for="s_password2"
                               class="control-label"><?php _e( 'Re-type password' , 'shopclass' ); ?></label>

                        <input class="form-control" id="s_password2" type="password" name="s_password2"
                               placeholder="<?php echo osc_esc_html( __( 'Re Enter your Password' , 'shopclass' ) ); ?>">

                    </div>
                    <div class="form-group">
                        <label for="s_email" class="control-label"><?php _e( 'E-mail' , 'shopclass' ); ?></label>

                        <input class="form-control" id="s_email" type="text" name="s_email"
                               placeholder="<?php echo osc_esc_html( __( 'Enter you email address' , 'shopclass' ) ); ?>">

                    </div>
                    <?php osc_run_hook( 'user_register_form' ); ?>
                    <div class="form-group"><?php osc_run_hook( 'tf_after_form' ); ?></div>
                    <div class="form-group">
                        <?php osc_show_recaptcha( 'register' ); ?>
                    </div>
                    <div class="form-group">

                        <button class="btn btn-block btn-primary"
                                type="submit"><?php _e( 'Create Account' , 'shopclass' ); ?></button>
                        <?php if ( function_exists( 'fbc_login_url' ) ) { ?>
                            <div class="btn-block text-center h3"><?php _e( 'Or' , 'shopclass' ); ?></div>
                            <a href="<?php echo fbc_login_url(); ?>" class="btn btn-block btn-facebook"><i
                                        class="fa fa-facebook"></i> <?php _e( 'Sign Up with Facebook' , 'shopclass' ); ?>
                            </a>
                        <?php }
                            echo '<div class="btn-block text-center h3">' . __( 'Or' , 'shopclass' ) . '</div>';
                            echo ( osc_get_preference( 'tfcGoogleEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Google" class="btn btn-block btn-googleplus"><i class="fa fa-google-plus"></i> ' . __( 'Register with Google' , 'shopclass' ) . '</a>' : '';

                            echo ( osc_get_preference( 'tfcFacebookEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Facebook" class="btn btn-block btn-facebook"><i class="fa fa-facebook"></i> ' . __( 'Register with Facebook' , 'shopclass' ) . '</a>' : '';

                            echo ( osc_get_preference( 'tfcTwitterEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Twitter" class=" btn btn-block btn-twitter"><i class="fa fa-twitter"></i> ' . __( 'Register with Twitter' , 'shopclass' ) . '</a>' : '';
                        ?>

                    </div>
                </form>
            </div>
        </div>
        <div class="visible-xs-block visible-sm-block">
            <hr>
        </div>
        <div class="col-md-6">

            <p class="lead"><?php _e( 'Registration Benefits' , 'shopclass' ); ?></p>
            <ul class="list-unstyled" style="line-height: 2">
                <li><span class="fa fa-check text-success"></span> <?php _e( 'See all your listings' , 'shopclass' ); ?>
                </li>
                <li>
                    <span class="fa fa-check text-success"></span> <?php _e( 'Upload your avatar/logo' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Move listing to top' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Save your favorites' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Fast submission' , 'shopclass' ); ?></li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Edit your listings' , 'shopclass' ); ?>
                </li>

            </ul>
            <div class="btn-block text-center h3"><?php _e( 'Already Registered ?' , 'shopclass' ); ?></div>
            <p><a href="<?php echo osc_user_login_url(); ?>"
                  class="btn btn-info btn-block"><?php _e( 'Login now!' , 'shopclass' ); ?></a></p>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $(document).ready(function () {
        var cInterval;
        $("#s_username").keydown(function (event) {
            if ($("#s_username").val() != '') {
                clearInterval(cInterval);
                cInterval = setInterval(function () {
                    $.getJSON(
                        "<?php echo osc_base_url( true ); ?>?page=ajax&action=check_username_availability",
                        {"s_username": $("#s_username").val()},
                        function (data) {
                            clearInterval(cInterval);
                            if (data.exists == 0) {
                                $("#available").closest('.form-group').addClass('has-success').removeClass('has-error');
                                $("#available").text('<?php echo osc_esc_js( __( "The username is available" , "shopclass" ) ); ?>').addClass('text-success').removeClass('text-danger');
                            } else {
                                $("#available").closest('.form-group').removeClass('has-success').addClass('has-error');
                                $("#available").text('<?php echo osc_esc_js( __( "The username is NOT available" , "shopclass" ) ); ?>').addClass('text-danger').removeClass('text-success');
                            }
                        }
                    );
                }, 1000);
            }
        });
        $("form[name=register]").validate({
            rules: {
                s_name: {
                    required: true,
                    minlength: 5
                },
                s_username: {
                    required: true,
                    minlength: 5
                },
                s_email: {
                    required: true,
                    email: true
                },
                s_password: {
                    required: true,
                    minlength: 5
                },
                s_password2: {
                    required: true,
                    minlength: 5,
                    equalTo: "#s_password"
                }
            },
            messages: {
                s_name: {
                    required: "<?php _e( "Name: this field is required" ); ?>.",
                    minlength: "<?php _e( "Enter at least 5 characters" ); ?>."
                },
                s_username: {
                    required: "<?php _e( "Username: this field is required" ); ?>.",
                    minlength: "<?php _e( "Enter at least 5 characters" ); ?>."
                },
                s_email: {
                    required: "<?php _e( "Email: this field is required" ); ?>.",
                    email: "<?php _e( "Invalid email address" ); ?>."
                },
                s_password: {
                    required: "<?php _e( "Password: this field is required" ); ?>.",
                    minlength: "<?php _e( "Password: enter at least 5 characters" ); ?>."
                },
                s_password2: {
                    required: "<?php _e( "Second password: this field is required" ); ?>.",
                    minlength: "<?php _e( "Second password: enter at least 5 characters" ); ?>.",
                    equalTo: "<?php _e( "Passwords don't match" ); ?>."
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