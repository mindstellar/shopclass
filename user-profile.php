<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-4">
		<?php include 'user-sidebar.php'; ?>
    </div>
    <div class="col-md-8">
        <div class=" tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php _e( 'Manage Your Profile' , 'shopclass' ); ?></div>
            </div>
            <div class="content user_account panel-body">
                <div id="main" class="modify_profile">
                    <legend><?php _e( 'Update your profile' , 'shopclass' ); ?></legend>
					<?php tfc_avatar_upload(); ?>
                    <form class="tfc-form form-group" rol="form" action="<?php echo osc_base_url( true ); ?>"
                          method="post">
                        <input type="hidden" name="page" value="user"/>
                        <input type="hidden" name="action" value="profile_post"/>
                        <fieldset>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="name"><?php _e( 'Name' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::name_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="email"><?php _e( 'E-mail' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-3  col-xs-12">
									<?php echo osc_user_email(); ?>

                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="user_type"><?php _e( 'User type' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-5 col-xs-12">
									<?php UserForm::is_company_select( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="phoneMobile"><?php _e( 'Cell phone' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::mobile_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="phoneLand"><?php _e( 'Phone' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::phone_land_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="country"><?php _e( 'Country' , 'shopclass' ); ?> *</label>
                                <div class="input-group col-md-5 col-xs-12">
									<?php UserForm::country_select( osc_get_countries() , osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="region"><?php _e( 'Region' , 'shopclass' ); ?> *</label>
                                <div class="input-group col-md-5 col-xs-12">
									<?php UserForm::region_select( osc_get_regions() , osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label" for="city"><?php _e( 'City' , 'shopclass' ); ?>
                                    *</label>
                                <div class="input-group col-md-5 col-xs-12">
									<?php UserForm::city_select( osc_get_cities() , osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="s_zip"><?php _e( 'Zip/Pin Code' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::zip_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="city_area"><?php _e( 'City area' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::city_area_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="address"><?php _e( 'Address' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::address_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"
                                       for="webSite"><?php _e( 'Website' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php UserForm::website_text( osc_user() ); ?>
                                </div>
                            </div>
                            <div class="form-group ">
                                <label class="col-md-3 control-label"><?php _e( 'Your Description' , 'shopclass' ); ?></label>
                                <div class="input-group col-md-7 col-xs-12">
									<?php
										$locales     = osc_get_locales();
										$user        = osc_user();
										$num_locales = count( $locales );
										if ( $num_locales > 1 ) {
											echo '<ul class="nav nav-pills" role="tablist">';
											foreach ( $locales as $locale ) {
												$localeId    = osc_sanitize_string( strtolower( $locale[ 's_name' ] . $locale[ 'pk_c_code' ] ) );
												$localeName  = $locale[ 's_name' ];
												$localeCode  = $locale[ 'pk_c_code' ];
												$activeClass = ( osc_current_user_locale() == $localeCode ) ? 'active' : '';
												echo '<li class="nav-item ' . $activeClass . '">';
												echo '<a class="nav-link" id="' . $localeId . '-tab" data-toggle="tab" href="#' . $localeId . '" role="tab" aria-controls="' . $localeId . '" aria-selected="true">' . $localeName . '</a>';
												echo '</li>';
												unset( $localeId , $localeName , $localeCode , $activeClass );
											}
											echo '</ul>';
										}
										if ( $num_locales > 1 ) {
											echo '<div class="tab-content" id="user-info-area-tabs">';
										}
										foreach ( $locales as $locale ) {
											$localeId    = osc_sanitize_string( strtolower( $locale[ 's_name' ] . $locale[ 'pk_c_code' ] ) );
											$localeName  = $locale[ 's_name' ];
											$localeCode  = $locale[ 'pk_c_code' ];
											$activeClass = ( osc_current_user_locale() == $localeCode ) ? 'active' : '';

											if ( $num_locales > 1 ) {
												echo '<div class="tab-pane ' . $activeClass . '" id="' . $localeId . '" role="tab-panel" aria-labelledby="' . $localeId . '-tab">';
											};
											//if($num_locales > 1) { echo '<h4>' . $locale['s_name'] . '</h4>'; }
											$info = '';
											if ( is_array( $user ) ) {
												if ( isset( $user[ 'locale' ][ $locale[ 'pk_c_code' ] ] ) ) {
													if ( isset( $user[ 'locale' ][ $locale[ 'pk_c_code' ] ][ 's_info' ] ) ) {
														$info = $user[ 'locale' ][ $locale[ 'pk_c_code' ] ][ 's_info' ];
													}
												}
											}
											UserForm::info_textarea( 's_info' , $locale[ 'pk_c_code' ] , $info );
											if ( $num_locales > 1 ) {
												echo '</div>';
											};
											unset( $localeId , $localeName , $localeCode , $activeClass );
										}
										if ( $num_locales > 1 ) {
											echo '</div>';
										};

										//UserForm::multilanguage_info(osc_get_locales(), osc_user()); ?>
                                </div>
								<?php osc_run_hook( 'user_form' ); ?>
                            </div>
                        </fieldset>
                        <button class="btn btn-default" type="submit"><?php _e( 'Update' , 'shopclass' ); ?></button>
                    </form>
                    <hr>
                    <button class="btn btn-danger btn-lg" data-toggle="modal" data-target="#delete-account">
						<?php _e( 'Delete Account' , 'shopclass' ) ?>
                    </button>
                    <!-- Modal -->
                    <div class="modal fade" id="delete-account" tabindex="-1" role="dialog"
                         aria-labelledby="deletAccountLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-hidden="true">
                                        &times;
                                    </button>
                                    <h4 class="modal-title"
                                        id="deletAccountLabel"><?php _e( 'Deleting your account.' , 'shopclass' ); ?></h4>
                                </div>
                                <div class="modal-body text-warning">
                                    <strong><?php _e( 'Are you sure you want to delete your account? This cannot be undone.' , 'shopclass' ); ?></strong>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default"
                                            data-dismiss="modal"><?php _e( 'Cancel' , 'shopclass' ) ?></button>
                                    <button id="deleteprofile" type="button"
                                            class="btn btn-danger"><?php _e( 'OK' , 'shopclass' ) ?></button>
                                </div>

                            </div>
                            <!-- /.modal-content -->
                        </div>
                        <!-- /.modal-dialog -->
                    </div>
                    <!-- /.modal -->
                </div>
            </div>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $(document).ready(function () {
        $("button#deleteprofile").click(function () {
            window.location = '<?php echo osc_base_url( 'true' ); ?>?page=user&action=delete&id=<?php echo osc_user_id(); ?>&secret=<?php echo osc_user_field( "s_secret" ); ?>';
        });
    });
</script>
<script>
    $(document).ready(function () {
        $("#countryId").on("change", function () {
            var pk_c_code = $(this).val();
			<?php if($path == "admin") { ?>
            var url = '<?php echo osc_admin_base_url( true ) . "?page=ajax&action=regions&countryId="; ?>' + pk_c_code;
			<?php } else { ?>
            var url = '<?php echo osc_base_url( true ) . "?page=ajax&action=regions&countryId="; ?>' + pk_c_code;
			<?php }; ?>
            var result = '';

            if (pk_c_code != '') {

                $("#regionId").attr('disabled', false);
                $("#cityId").attr('disabled', true);
                $.ajax({
                    type: "POST",
                    url: url,
                    dataType: 'json',
                    success: function (data) {
                        var length = data.length;
                        if (length > 0) {
                            result += '<option value=""><?php _e( "Select a region..." ); ?></option>';
                            for (key in data) {
                                result += '<option value="' + data[key].pk_i_id + '">' + data[key].s_name + '</option>';
                            }
                            $("#region").before('<select name="regionId" id="regionId" ></select>');
                            $("#region").remove();

                            $("#city").before('<select name="cityId" id="cityId" ></select>');
                            $("#city").remove();

                        } else {
                            result += '<option value=""><?php _e( 'No results' ) ?></option>';
                            $("#regionId").before('<input type="text" name="region" id="region" />');
                            $("#regionId").remove();

                            $("#cityId").before('<input type="text" name="city" id="city" />');
                            $("#cityId").remove();
                        }
                        $("#regionId").html(result);
                        $("#cityId").html('<option selected value=""><?php _e( "Select a city..." ); ?></option>');
                    }
                });
            } else {
                // add empty select
                $("#region").before('<select name="regionId" id="regionId" ><option value=""><?php _e( "Select a region..." ); ?></option></select>');
                $("#region").remove();

                $("#city").before('<select name="cityId" id="cityId" ><option value=""><?php _e( "Select a city..." ); ?></option></select>');
                $("#city").remove();

                if ($("#regionId").length > 0) {
                    $("#regionId").html('<option value=""><?php _e( "Select a region..." ); ?></option>');
                } else {
                    $("#region").before('<select name="regionId" id="regionId" ><option value=""><?php _e( "Select a region..." ); ?></option></select>');
                    $("#region").remove();
                }
                if ($("#cityId").length > 0) {
                    $("#cityId").html('<option value=""><?php _e( "Select a city..." ); ?></option>');
                } else {
                    $("#city").before('<select name="cityId" id="cityId" ><option value=""><?php _e( "Select a city..." ); ?></option></select>');
                    $("#city").remove();
                }

                $("#regionId").attr('disabled', true);
                $("#cityId").attr('disabled', true);
            }
        });

        $("#regionId").on("change", function () {
            var pk_c_code = $(this).val();

            var url = '<?php echo osc_base_url( true ) . "?page=ajax&action=cities&regionId="; ?>' + pk_c_code;

            var result = '';

            if (pk_c_code != '') {

                $("#cityId").attr('disabled', false);
                $.ajax({
                    type: "POST",
                    url: url,
                    dataType: 'json',
                    success: function (data) {
                        var length = data.length;
                        if (length > 0) {
                            result += '<option selected value=""><?php _e( "Select a city..." ); ?></option>';
                            for (key in data) {
                                result += '<option value="' + data[key].pk_i_id + '">' + data[key].s_name + '</option>';
                            }
                            $("#city").before('<select name="cityId" id="cityId" ></select>');
                            $("#city").remove();
                        } else {
                            result += '<option value=""><?php _e( 'No results' ) ?></option>';
                            $("#cityId").before('<input type="text" name="city" id="city" />');
                            $("#cityId").remove();
                        }
                        $("#cityId").html(result);
                    }
                });
            } else {
                $("#cityId").attr('disabled', true);
            }
        });


        if ($("#regionId").attr('value') == "") {
            $("#cityId").attr('disabled', true);
        }

        if ($("#countryId").prop('type').match(/select-one/)) {
            if ($("#countryId").attr('value') == "") {
                $("#regionId").attr('disabled', true);
            }
        }
    });
</script>
</body>
</html>
