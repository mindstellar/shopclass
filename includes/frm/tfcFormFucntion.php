<?php
    /*
     * Shopclass - Free and open source theme for Osclass with premium features
     * Maintained and supported by Mindstellar Community
     * https://github.com/mindstellar/shopclass
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

	/**
	 * User: navjottomer
	 * Date: 27/03/19
	 * Time: 2:56 PM
	 */


	function tfc_search_drop_all_js() {
		$sQuery = tfc_getPref( 'keyword_placeholder' );
		?>
        <script>
            //<![CDATA[
            var sQuery = '<?php echo ( osc_search_pattern() != '' ) ? osc_search_pattern() : $sQuery; ?>';

            function doSearch() {
                if ($('input[name=sPattern]').val().length < 3) {
                    $('#querypattern').addClass("has-error");
                    return false;
                }
                return true;
            }

            // ]]>
        </script>
        <script>
            $(document).ready(function () {
                $("#sCountry").on("change", function () {
                    var pk_c_code = $(this).val();
                    var url = '<?php echo osc_base_url( true ) . "?page=ajax&action=regions&countryId="; ?>' + pk_c_code;

                    var result = '';

                    if (pk_c_code != '') {

                        $("#sRegion").attr('disabled', false);
                        $.ajax({
                            type: "GET",
                            url: url,
                            dataType: 'json',
                            success: function (data) {
                                var length = data.length;
                                if (length > 0) {
                                    result += '<option selected value=""><?php _e( 'Select a region...' , 'shopclass' ); ?></option>';
                                    for (key in data) {
                                        result += '<option value="' + data[key].pk_i_id + '">' + data[key].s_name + '</option>';
                                    }

                                    $("#region").before('<select name="sRegion" id="sRegion" ></select>');
                                    $("#region").remove();
                                } else {
                                    result += '<option value=""><?php _e( 'No results' , 'shopclass' ); ?></option>';
                                    $("#sRegion").before('<input type="text" name="region" id="region" />');
                                    $("#sRegion").remove();
                                }
                                $("#sRegion").html(result);
                            }
                        });
                    } else {
                        $("#sRegion").attr('disabled', true);
                    }
                });

                if ($("#sCountry").attr('value') == "") {
                    $("#sRegion").attr('disabled', true);
                }
                $("#sRegion").on("change", function () {
                    var pk_c_code = $(this).val();
                    var url = '<?php echo osc_base_url( true ) . "?page=ajax&action=cities&regionId="; ?>' + pk_c_code;

                    var result = '';

                    if (pk_c_code != '') {

                        $("#sCity").attr('disabled', false);
                        $.ajax({
                            type: "GET",
                            url: url,
                            dataType: 'json',
                            success: function (data) {
                                var length = data.length;
                                if (length > 0) {
                                    result += '<option selected value=""><?php _e( "Select a city..." , 'shopclass' ); ?></option>';
                                    for (key in data) {
                                        result += '<option value="' + data[key].pk_i_id + '">' + data[key].s_name + '</option>';
                                    }

                                    $("#city").before('<select name="sCity" id="sCity" ></select>');
                                    $("#city").remove();
                                } else {
                                    result += '<option value=""><?php _e( 'No results' , 'shopclass' ) ?></option>';
                                    $("#sCity").before('<input type="text" name="city" id="city" />');
                                    $("#sCity").remove();
                                }
                                $("#sCity").html(result);
                            }
                        });
                    } else {
                        $("#sCity").attr('disabled', true);
                    }
                });

                if ($("#sRegion").attr('value') == "") {
                    $("#sCity").attr('disabled', true);
                }


            });

			<?php if (get_search_country()) { ?>
            $('select[name="sCountry"]').find('option[value="<?php echo get_search_country() ?>"]').attr("selected", true);
			<?php } ?>
        </script>
		<?php
	}

	function tfc_search_drop_js() {
		?>
        <script>
            var sQuery = '<?php
				if ( ! empty( $sQuery ) ) {
					echo $sQuery;
				}
				?>';

            function doSearch() {
                if ($('input[name=sPattern]').val().length < 3) {
                    $('#querypattern').addClass("has-error");
                    return false;
                }
                return true;
            }

        </script>
        <script>
            $(document).ready(function () {

                $("#sRegion").on("change", function () {
                    var pk_c_code = $(this).val();

                    var url = '<?php echo osc_base_url( true ) . "?page=ajax&action=cities&regionId="; ?>' + pk_c_code;


                    var result = '';

                    if (pk_c_code != '') {

                        $("#sCity").attr('disabled', false);
                        $.ajax({
                            type: "GET",
                            url: url,
                            dataType: 'json',
                            success: function (data) {
                                var length = data.length;
                                if (length > 0) {
                                    result += '<option selected value=""><?php _e( "Select a city..." , 'shopclass' ); ?></option>';
                                    for (key in data) {
                                        result += '<option value="' + data[key].pk_i_id + '">' + data[key].s_name + '</option>';
                                    }

                                    $("#city").before('<select name="sCity" id="sCity" ></select>');
                                    $("#city").remove();
                                } else {
                                    result += '<option value=""><?php _e( 'No results' , 'shopclass' ) ?></option>';
                                    $("#sCity").before('<input type="text" name="city" id="city" />');
                                    $("#sCity").remove();
                                }
                                $("#sCity").html(result);
                            }
                        });
                    } else {
                        $("#sCity").attr('disabled', true);
                    }
                });

                if ($("#sRegion").attr('value') == "") {
                    $("#sCity").attr('disabled', true);
                }


            });

			<?php if (get_search_country()) { ?>
            $('select[name="sCountry"]').find('option[value="<?php echo get_search_country() ?>"]').attr("selected", true);
			<?php } else { ?>

            $('select[name="sCountry"]').find('option[value="<?php echo tfc_getPref( 'default_country' )?>"]').attr("selected", true);
			<?php } ?>

        </script>
	<?php }

	function tfc_search_default_js() {
		?>
        <script>
			<?php $sQuery = isset( $sQuery ) ? $sQuery : ''; ?>
            var sQuery = '<?php echo $sQuery; ?>';

            function doSearch() {
                if ($('input[name=sPattern]').val().length < 3) {
                    $('#querypattern').addClass("has-error");
                    return false;
                }
                return true;
            }

        </script>
        <script>
            $(document).ready(function () {

                $('#countryName').attr("autocomplete", "off");
                $('#sRegion').attr("autocomplete", "off");
                $('#sCity').attr("autocomplete", "off");

                $('#sCountry').change(function () {
                    $('#regionId').val('');

                    $('#cityId').val('');
                    $('#sRegion').val('');
                });

                $('#countryName').on('keyup.autocomplete', function () {
                    $('#sCountry').val('');
                    $(this).autocomplete({
                        source: "<?php echo osc_base_url( true ); ?>?page=ajax&action=location_countries",
                        minLength: 0,
                        select: function (event, ui) {
                            $('#sCountry').val(ui.item.id);
                            $('#regionId').val('');
                            $('#cityId').val('');

                        }
                    });
                });

                $('#sRegion').on('keyup.autocomplete', function () {
                    $('#regionId').val('');
                    $(this).autocomplete({
                        source: "<?php echo osc_base_url( true ); ?>?page=ajax&action=location_regions&country=" + $('#sCountry').val(),
                        minLength: 2,
                        select: function (event, ui) {
                            $('#cityId').val('');
                            $('#sCity').val('');
                            $('#regionId').val(ui.item.id);
                        }
                    });
                });

                $('#sCity').on('keyup.autocomplete', function () {
                    $('#cityId').val('');
                    $(this).autocomplete({
                        source: "<?php echo osc_base_url( true ); ?>?page=ajax&action=location_cities&region=" + $('#regionId').val(),
                        minLength: 2,
                        select: function (event, ui) {
                            $('#cityId').val(ui.item.id);
                        }
                    });


                });

            });


			<?php if (get_search_country()) { ?>
            $('select[name="sCountry"]').find('option[value="<?php echo get_search_country() ?>"]').attr("selected", true);
			<?php } else { ?>

            $('select[name="sCountry"]').find('option[value="<?php echo tfc_getPref( 'default_country' )?>"]').attr("selected", true);
			<?php } ?>
        </script>
	<?php }