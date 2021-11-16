<?php
	namespace shopclass\includes\frm;

	/**
	 * Class tfcGenForm
	 */
	class tfcGenForm {
		/**
		 * @param $other_data
		 * @param $name
		 * @param $items
		 * @param $fld_key
		 * @param $fld_name
		 * @param $default_item
		 * @param $id
		 */
		static protected function generic_select( $other_data , $name , $items , $fld_key , $fld_name , $default_item , $id ) {
			$name = osc_esc_html( $name );
			echo '<select name="' . $name . '" id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '"  ' . $other_data . '>';
			if ( isset( $default_item ) ) {
				echo '<option value="">' . $default_item . '</option>';
			}
			foreach ( $items as $i ) {
				if ( isset( $fld_key ) && isset( $fld_name ) ) {
					echo '<option value="' . osc_esc_html( $i[ $fld_key ] ) . '"' . ( ( $id == $i[ $fld_key ] ) ? ' selected="selected"' : '' ) . '>' . $i[ $fld_name ] . '</option>';
				}
			}
			echo '</select>';
		}

		/**
		 * @param $other_data
		 * @param $name
		 * @param $value
		 * @param null $maxLength
		 * @param bool $readOnly
		 * @param bool $autocomplete
		 */
		static protected function generic_input_text( $other_data , $name , $value , $maxLength = null , $readOnly = false , $autocomplete = true ) {
			$name = osc_esc_html( $name );
			echo '<input id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" type="text" name="' . $name . '" value="' . osc_esc_html( htmlentities( $value , ENT_COMPAT , "UTF-8" ) ) . '"';
			if ( isset( $maxLength ) ) {
				echo ' maxlength="' . osc_esc_html( $maxLength ) . '"';
			}
			if ( ! $autocomplete ) {
				echo ' autocomplete="off"';
			}
			if ( $readOnly ) {
				echo ' disabled="disabled" readonly="readonly"';
			}
			echo ' ' . $other_data . ' />';
		}

		/**
		 * @param $other_data
		 * @param $name
		 * @param $value
		 * @param null $maxLength
		 * @param bool $readOnly
		 * @param bool $autocomplete
		 */
		static protected function generic_input_number( $other_data , $name , $value , $maxLength = null , $readOnly = false , $autocomplete = true ) {
			$name = osc_esc_html( $name );
			echo '<input id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" type="number" step="any" name="' . $name . '" value="' . osc_esc_html( htmlentities( $value , ENT_COMPAT , "UTF-8" ) ) . '"';
			if ( isset( $maxLength ) ) {
				echo ' maxlength="' . osc_esc_html( $maxLength ) . '"';
			}
			if ( ! $autocomplete ) {
				echo ' autocomplete="off"';
			}
			if ( $readOnly ) {
				echo ' disabled="disabled" readonly="readonly"';
			}
			echo ' ' . $other_data . ' />';
		}

		/**
		 * @param $other_data
		 * @param $name
		 * @param $value
		 * @param null $maxLength
		 * @param bool $readOnly
		 */
		static protected function generic_password( $other_data , $name , $value , $maxLength = null , $readOnly = false ) {
			$name = osc_esc_html( $name );
			echo '<input id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" type="password" name="' . $name . '" value="' . osc_esc_html( htmlentities( $value , ENT_COMPAT , "UTF-8" ) ) . '"';
			if ( isset( $maxLength ) ) {
				echo ' maxlength="' . osc_esc_html( $maxLength ) . '"';
			}
			if ( $readOnly ) {
				echo ' disabled="disabled" readonly="readonly"';
			}
			echo ' ' . $other_data . ' />';
		}

		/**
		 * @param $name
		 * @param $value
		 */
		static protected function generic_input_hidden( $name , $value ) {
			$name = osc_esc_html( $name );
			echo '<input id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" type="hidden" name="' . $name . '" value="' . osc_esc_html( htmlentities( $value , ENT_COMPAT , "UTF-8" ) ) . '" />';
		}

		/**
		 * @param $other_data
		 * @param $name
		 * @param $value
		 * @param bool $checked
		 */
		static protected function generic_input_checkbox( $other_data , $name , $value , $checked = false ) {
			$name = osc_esc_html( $name );
			echo '<input id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" type="checkbox" name="' . $name . '" value="' . osc_esc_html( htmlentities( $value , ENT_COMPAT , "UTF-8" ) ) . '"';
			if ( $checked ) {
				echo ' checked="checked"';
			}
			echo ' ' . $other_data . ' />';
		}

		/**
		 * @param $other_data
		 * @param $name
		 * @param $value
		 */
		static protected function generic_textarea( $other_data , $name , $value ) {
			$name = osc_esc_html( $name );
			echo '<textarea id="' . preg_replace( '|([^_a-zA-Z0-9-]+)|' , '' , $name ) . '" name="' . $name . '" rows="10"  ' . $other_data . '>' . $value . '</textarea>';
		}

	}
