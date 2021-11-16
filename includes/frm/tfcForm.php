<?php

	namespace shopclass\includes\frm;

	use Category;
	use CategoryForm;
	use Form;
	use Params;
	use Session;

	/**
	 * Class tfcForm
	 */
	class tfcForm extends Form {
		/**
		 * @param $categories
		 * @param $category
		 * @param null $default_item
		 * @param string $name
		 */
		static public function category_select( $categories , $category , $default_item = null , $name = "sCategory" ) {
			echo '<select class="form-control" name="' . $name . '" id="' . $name . '">';
			if($category === null){
			    $category_id = null;
            }else{
			    $category_id = $category[ 'pk_i_id' ];
            }
			if ( isset( $default_item ) ) {
				echo '<option value="">' . $default_item . '</option>';
			}
			foreach ( $categories as $c ) {
				echo '<option value="' . $c[ 'pk_i_id' ] . '"' . ( ( $category_id == $c[ 'pk_i_id' ] ) ? 'selected="selected"' : '' ) . '>' . $c[ 's_name' ] . '</option>';
				if ( isset( $c[ 'categories' ] ) && is_array( $c[ 'categories' ] ) ) {
					CategoryForm::subcategory_select( $c[ 'categories' ] , $category , $default_item , 1 );
				}
			}
			echo '</select>';
		}

		/**
		 * @param $categories
		 */
		static public function category_list( $categories ) {
			echo '<ul id="cat_tree">';
			foreach ( $categories as $c ) {
				echo '<li class="catId_' . $c[ 'pk_i_id' ] . '"><a href="' . osc_update_search_url( array ( 'sCategory' => $c[ 'pk_i_id' ] ) ) . '">' . ucfirst( $c[ 's_name' ] ) . '</a>';
				if ( isset( $c[ 'categories' ] ) && is_array( $c[ 'categories' ] ) ) {
					if ( count( $c[ 'categories' ] ) > 0 ) {
						echo '<ul>';
					} else {
						echo '<li>';
					}
					tfcForm::subcategory_list( $c[ 'categories' ] );
					if ( count( $c[ 'categories' ] ) > 0 ) {
						echo '</ul></li>';
					} else {
						echo '</li></li>';
					}
				} else {
					echo '</li>';
				}
			}
			echo '</ul>';
		}

		/**
		 * @param $categories
		 */
		static public function subcategory_list( $categories ) {
			foreach ( $categories as $c ) {
				echo '<li class="' . $c[ 'pk_i_id' ] . '"><a href="' . osc_update_search_url( array ( 'sCategory' => $c[ 'pk_i_id' ] ) ) . '">' . ucfirst( $c[ 's_name' ] ) . '</a>';
				if ( isset( $c[ 'categories' ] ) && is_array( $c[ 'categories' ] ) ) {
					if ( count( $c[ 'categories' ] ) > 0 ) {
						echo '<ul>';
					}
					tfcForm::subcategory_list( $c[ 'categories' ] );
					if ( count( $c[ 'categories' ] ) > 0 ) {
						echo '</ul></li>';
					} else {
						echo '</li>';
					}
				} else {
					echo '</li>';
				}
			}
		}


		/**
		 * @param null $categories
		 * @param null $item
		 * @param null $default_item
		 * @param bool $parent_selectable
		 */
		static public function tfc_category_multiple_selects( $categories = null , $item = null , $default_item = null , $parent_selectable = false ) {
			$categoryID = Params::getParam( 'sCategory' );
			echo '<div id="select_holder"></div>';
			parent::generic_input_hidden( "sCategory" , $categoryID );
			/**
			 *
			 */
			$multi_category_js = function () use ( $categories , $item , $default_item , $parent_selectable , $categoryID ) {

				if ( osc_search_category_id() != null ) {
					$categoryID = implode( osc_search_category_id() );
				}

				if ( Session::newInstance()->_getForm( 'catId' ) != '' ) {
					$categoryID = Session::newInstance()->_getForm( 'catId' );
				}

				if ( $item == null ) {
					$item = osc_item();
				}

				if ( isset( $item[ 'fk_i_category_id' ] ) ) {
					$categoryID = $item[ 'fk_i_category_id' ];
				}

				$tmp_categories_tree = Category::newInstance()->toRootTree( $categoryID );
				$categories_tree     = array ();
				foreach ( $tmp_categories_tree as $t ) {
					$categories_tree[] = $t[ 'pk_i_id' ];
				}
				unset( $tmp_categories_tree );

				if ( $categories == null ) {
					$categories = Category::newInstance()->listEnabled();
				}

				//parent::generic_input_hidden( "sCategory" , $categoryID );

				?>
                <script>
					<?php
					$tmp_cat = array ();
					foreach ( $categories as $c ) {
						if ( $c[ 'fk_i_parent_id' ] == null ) {
							$c[ 'fk_i_parent_id' ] = 0;
						};
						$tmp_cat[ $c[ 'fk_i_parent_id' ] ][] = array ( $c[ 'pk_i_id' ] , $c[ 's_name' ] );
					}
					foreach ( $tmp_cat as $k => $v ) {
						echo 'var categories_' . $k . ' = ' . json_encode( $v ) . ';' . PHP_EOL;
					}
					?>

                    if (osc == undefined) {
                        var osc = {};
                    }
                    if (osc.langs == undefined) {
                        osc.langs = {};
                    }
                    if (osc.langs.select_category == undefined) {
                        osc.langs.select_category = '<?php _e( 'Select Category' , 'shopclass' ) ?>';
                    }
                    if (osc.langs.select_subcategory == undefined) {
                        osc.langs.select_subcategory = '<?php _e( 'Select Subcategory' , 'shopclass' ) ?>';
                    }
                    osc.item_post = {};
                    osc.item_post.category_id = '<?php echo $categoryID; ?>';
                    osc.item_post.category_tree_id = <?php echo json_encode( $categories_tree ); ?>;

                    $(document).ready(function () {
						<?php if($categoryID == array ()) { ?>
                        draw_select(1, 0);
						<?php } else { ?>
                        draw_select(1, 0);
						<?php for($i = 0; $i < count( $categories_tree ); $i ++) { ?>
                        draw_select(<?php echo( $i + 2 ); ?> ,<?php echo $categories_tree[ $i ]; ?>);
						<?php for($i = 1; $i < count( $categories_tree ); $i ++) { ?>
                        draw_select(<?php echo( $i + 2 ); ?> ,<?php echo $categories_tree[ $i ]; ?>);
						<?php } ?>
						<?php } ?>
						<?php } ?>
                        $('body').on("change", '[id^="select_"]', function () {
                            var depth = parseInt($(this).attr("depth"));
                            for (var d = (depth + 1); d <= 4; d++) {
                                $("#select_" + d).trigger('removed');
                                $("#select_" + d).remove();
                            }
                            $("#sCategory").attr("value", $(this).val());
                            //$("#sCategory").change();

                            if ((depth == 1 && $(this).val() != 0) || (depth > 1 && $(this).val() != $("#select_" + (depth - 1)).val())) {
                                draw_select(depth + 1, $(this).val());
                            }
                            return true;
                        });
                    });

                    function draw_select(select, categoryID) {
                        tmp_categories = window['categories_' + categoryID];
                        if (tmp_categories != null && $.isArray(tmp_categories)) {
                            $("#select_holder").before('<select id="select_' + select + '" name="" depth="' + select + '"></select>');

                            if (categoryID == 0) {
                                var options = '<option value="' + categoryID + '" >' + osc.langs.select_category + '</option>';
                            } else {
                                var options = '<option value="' + categoryID + '" >' + osc.langs.select_subcategory + '</option>';
                            }
                            $.each(tmp_categories, function (index, value) {
                                options += '<option value="' + value[0] + '" ' + (value[0] == osc.item_post.category_tree_id[select - 1] ? 'selected="selected"' : '') + '>' + value[1] + '</option>';
                            });
                            osc.item_post.category_tree_id[select - 1] = null;
                            $('#select_' + select).html(options);
                            $('#select_' + select).next("a").find(".select-box-label").text(osc.langs.select_subcategory);
                            $('#select_' + select).trigger("created");
                        }
                        ;

                    }
                </script>
				<?php
			};
			osc_add_hook( 'footer_scripts_loaded' , $multi_category_js );
		}

		/**
		 * @return string
		 */
		static public function search_drop_all_js() {
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
            return;
		}

		/**
		 * @return string
		 */
		static public function search_drop_js() {
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
		<?php
		    return;
		}

		/**
		 * @return string
		 */
		static public function search_default_js() {
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
		<?php
		    return;
		}
	}