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

	namespace shopclass\includes\classes;

	use OSCLocale;
	use Params;

	/**
	 * User: navjottomer
	 * Date: 10/11/17
	 * Time: 11:30 AM
	 */
	class tfcAdminFormRegister {
		public static $instance;
		private $forms = array ();
		private $inputs = array ();
		private $locales;
		private $localesCount;

		/**
		 * tfcAdminFormRegister constructor.
		 */
		public function __construct() {
			$this->locales      = OSCLocale::newInstance()->listAllEnabled();
			$this->localesCount = count( $this->locales );
		}

		/**
		 * @return tfcAdminFormRegister
		 */
		public static function newInstance() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		public function createAdminAllFormActions() {
			if ( OC_ADMIN ) {
				$formOptions = $this->getForms();
				osc_run_hook( 'before_tfcAdminForm' );
				foreach ( $formOptions as $form ) {
					if ( $form[ 'type' ] == 'form-action' ) {
						$newForm = function () use ( $form ) {
							$this->renderSingleForm( $form[ 'id' ] , $form[ 'div-class' ] , $form[ 'form-title' ] , $form[ 'form-data' ] );
						};
						osc_add_hook( 'tfc-' . $form[ 'id' ] , $newForm , 4 );
					} elseif ( $form[ 'type' ] == 'setting-page' ) {
						$newForm = function () use ( $form ) {
							$this->renderSinglePage( $form[ 'id' ] , $form[ 'div-class' ] , $form[ 'form-title' ] , $form[ 'form-data' ] );
						};
						osc_add_hook( 'tfc-' . $form[ 'id' ] , $newForm , 4 );
					}
				}
				$this->processActionForForms();
				$this->resetInputs();
				$this->resetForms();
			}
		}

		/**
		 * @return array
		 */
		public function getForms() {
			return $this->forms;
		}

		/**
		 * @param array $forms
		 *
		 * @return array
		 */
		public function setForms( array $forms ) {
			return $this->forms[] = $forms;
		}

		/**
		 * @param $formId
		 * @param $formclass
		 * @param $formtitle
		 * @param array $formdata
		 */
		private function renderSingleForm( $formId , $formclass , $formtitle , $formdata = array () ) {
			?>
            <div class="tfc-dashboard">
                <h2 class="render-title "> <?php echo $formtitle; ?> </h2>
                <form id="form-<?php echo $formId; ?>"
                      action="<?php echo tfcAdminDashboard::newInstance()->getRouteAdminURL( $formId ); ?>"
                      method="post" class="<?php echo $formclass; ?>">
                    <input type="hidden" name="tfc-admin-form-action" value="<?php echo $formId; ?>"/>
                    <fieldset>
                        <div class="form-horizontal"><?php
								$this->renderInputFromData( $formdata );
							?>
                            <div class="form-actions">
                                <input type="submit"
                                       value="<?php echo osc_esc_html( __( 'Save changes' , 'shopclass' ) ); ?>"
                                       class="btn btn-submit"/>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>
			<?php
		}

		/**
		 * @param $formdata
		 */
		private function renderInputFromData( $formdata ) {
			foreach ( $formdata as $input ) {
				if ( array_key_exists( 'type' , $input ) ) {
					switch ( $input[ 'type' ] ) {
						case 'text-input':
							$this->createTextInput(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'label' ] ,
								$input[ 'placeholder' ] ,
								$input[ 'help-text' ] ,
								$input[ 'translate' ]
							);
							break;
						case 'checkbox-input':
							$this->createCheckboxInput(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'label' ] ,
								$input[ 'help-text' ]
							);
							break;
						case 'select-input':
							$this->createSelectInput(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'label' ] ,
								$input[ 'placeholder' ] ,
								$input[ 'options' ] ,
								$input[ 'help-text' ]
							);
							break;
						case 'textarea-input':
							$this->createTextareaInput(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'label' ] ,
								$input[ 'placeholder' ] ,
								$input[ 'help-text' ] ,
								$input[ 'translate' ]
							);
							break;
						case 'custom-input':
							$this->createCustomInput(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'label' ] ,
								$input[ 'help-text' ] ,
								$input[ 'content' ]
							);
							break;
						case 'custom-section':
							$this->createCustomSection(
								$input[ 'id' ] ,
								$input[ 'div-class' ] ,
								$input[ 'field-function' ]
							);
							break;
						case 'form-subheading':
							$this->createFormSubHeading( $input[ 'title' ] );
							break;
						case 'custom-content':
							$this->renderCustomContent( $input[ 'content' ] );
							break;
					}
				}
			}
		}

		/**
		 * @param $id
		 * @param string $class
		 * @param $label
		 * @param string $placeholder
		 * @param $helptext
		 * @param $translate
		 */
		public function createTextInput( $id , $class = '' , $label , $placeholder = '' , $helptext , $translate ) {
			if ( $translate == true && $this->localesCount > 1 ) {
				echo '<div id="' . $id . '"><ul>';
				for ( $i = 1; $i < ( $this->localesCount + 1 ); $i ++ ) {
				    $active_class = (osc_current_admin_locale() == $this->locales[$i-1]['pk_c_code'])?'ui-tabs-active ui-state-active':'';
					echo '<li class="'.$active_class. '"><a href="#tabs-' . $id . $i . '">' . $this->locales[ $i - 1 ][ 's_name' ] . '</a></li>';
				}
				echo '</ul>';
				$i = 1;
				foreach ( $this->locales as $locale ) {
					?>
                    <div id="tabs-<?php echo $id . $i; ?>" class="form-row <?php echo $class; ?>">
                        <div class="form-label"><?php echo $label . ' ' . $locale[ 's_name' ]; ?></div>
                        <div class="form-controls"><input id="<?php echo $id . $locale[ 'pk_c_code' ]; ?>" type="text"
                                                          class="xlarge"
                                                          name="<?php echo $id . $locale[ 'pk_c_code' ]; ?>"
                                                          value="<?php echo tfc_getPref( $id . $locale[ 'pk_c_code' ] ); ?>"
                                                          placeholder="<?php echo osc_esc_html( $placeholder . ' (' . $locale[ 's_name' ] . ')' ); ?>">
							<?php if ( is_array( $helptext ) ) {
								foreach ( $helptext as $value ) {
									?>
                                    <div class="help-box"> <?php echo $value; ?></div>
								<?php }
							} else {
								?>
                                <div class="help-box"> <?php echo $helptext; ?></div>
							<?php }
							?>
                        </div>
                    </div>
					<?php $i ++;
				}
				echo '</div>';
				?>
                <script>
                    $(function () {
                        $("#<?php echo $id; ?>").tabs();
                    });
                </script>
				<?php
			} else {
				?>
                <div class="form-row <?php echo $class; ?>">
                    <div class="form-label"><?php echo $label; ?></div>
                    <div class="form-controls"><input id="<?php echo $id; ?>" type="text" class="xlarge"
                                                      name="<?php echo $id; ?>"
                                                      value="<?php echo tfc_getPref( $id ); ?>"
                                                      placeholder="<?php echo osc_esc_html( $placeholder ); ?>">
						<?php if ( is_array( $helptext ) ) {
							foreach ( $helptext as $value ) {
								?>
                                <div class="help-box"> <?php echo $value; ?></div>
							<?php }
						} else {
							?>
                            <div class="help-box"> <?php echo $helptext; ?></div>
						<?php }
						?>
                    </div>
                </div>
				<?php
			}

		}

		/**
		 * @param $id
		 * @param $class
		 * @param $label
		 * @param $helptext
		 */
		public function createCheckboxInput( $id , $class = '' , $label , $helptext ) {
			?>
            <div class="form-row <?php echo $class; ?>">
                <div class="form-label"> <?php echo $label; ?> </div>
                <div class="form-controls">
                    <div class="form-label-checkbox">
                        <input id="<?php echo $id; ?>" type="checkbox" name="<?php echo $id ?>"
                               value="1" <?php echo( tfc_getPref( $id ) ? 'checked' : '' ); ?>>
						<?php if ( is_array( $helptext ) ) {
							foreach ( $helptext as $value ) {
								?>
                                <div class="help-box"> <?php echo $value; ?></div>
							<?php }
						} else {
							?>
                            <div class="help-box"> <?php echo $helptext; ?></div>
						<?php }
						?>
                    </div>
                </div>
            </div>
			<?php
		}

		/**
		 * @param $id
		 * @param $class
		 * @param $label
		 * @param $placeholder
		 * @param $options
		 * @param $helptext
		 */
		public function createSelectInput( $id , $class = '' , $label , $placeholder = '' , $options , $helptext ) { ?>
            <div class="form-row <?php echo $class; ?>">
                <div class="form-label"><?php echo $label; ?></div>
                <div class="form-controls">
                    <select id="<?php echo $id; ?>" name="<?php echo $id; ?>">
						<?php if ( $placeholder !== '' ) { ?>
                            <option value="" <?php if ( ! tfc_getPref( $id ) ) {
								echo 'selected="selected"';
							} ?>>
								<?php echo osc_esc_html( $placeholder ); ?>
                            </option>
						<?php } ?>
						<?php foreach ( $options as $option ) { ?>
                            <option value="<?php echo $option[ 'option-value' ]; ?>" <?php if ( tfc_getPref( $id ) == $option[ 'option-value' ] ) {
								echo 'selected="selected"';
							} ?>>
								<?php echo $option[ 'option-name' ]; ?>
                            </option>
						<?php } ?>

                    </select>
					<?php if ( is_array( $helptext ) ) {
						foreach ( $helptext as $value ) {
							?>
                            <div class="help-box"> <?php echo $value; ?></div>
						<?php }
					} else {
						?>
                        <div class="help-box"> <?php echo $helptext; ?></div>
					<?php }
					?>
                </div>
            </div>
			<?php
		}

		/**
		 * @param $id
		 * @param string $class
		 * @param $label
		 * @param string $placeholder
		 * @param $helptext
		 * @param $translate
		 */
		public function createTextareaInput( $id , $class = '' , $label , $placeholder = '' , $helptext , $translate ) {
			if ( $translate == true && $this->localesCount > 1 ) {
				echo '<div id="' . $id . '"><ul>';
				for ( $i = 1; $i < ( $this->localesCount + 1 ); $i ++ ) {
					$active_class = (osc_current_admin_locale() == $this->locales[$i-1]['pk_c_code'])?'ui-tabs-active ui-state-active':'';
					echo '<li class="'.$active_class.'"><a href="#tabs-' . $id . $i . '">' . $this->locales[ $i - 1 ][ 's_name' ] . '</a></li>';
				}
				echo '</ul>';
				$i = 1;
				foreach ( $this->locales as $locale ) {
					?>
                    <div id="tabs-<?php echo $id . $i; ?>"
                         class="form-row input-description-wide <?php echo $class; ?>">
                        <div class="form-label"> <?php echo $label . ' ' . $locale[ 's_name' ]; ?> </div>
                        <div class="form-controls"><textarea id="<?php echo $id . $locale[ 'pk_c_code' ]; ?>"
                                                             name="<?php echo $id . $locale[ 'pk_c_code' ]; ?>"
                                                             rows="4"
                                                             placeholder="<?php echo osc_esc_html( $placeholder . ' (' . $locale[ 's_name' ] . ')' ); ?>"><?php echo tfc_getPref( $id . $locale[ 'pk_c_code' ] ); ?></textarea>
							<?php if ( is_array( $helptext ) ) {
								foreach ( $helptext as $value ) {
									?>
                                    <div class="help-box"> <?php echo $value; ?></div>
								<?php }
							} else {
								?>
                                <div class="help-box"> <?php echo $helptext; ?></div>
							<?php }
							?>
                        </div>
                    </div>
					<?php $i ++;
				}
				echo '</div>';
				?>
                <script>
                    $(function () {
                        $("#<?php echo $id; ?>").tabs();
                    });
                </script>
				<?php
			} else {
				?>
                <div class="form-row input-description-wide <?php echo $class; ?>">
                    <div class="form-label"> <?php echo $label; ?> </div>
                    <div class="form-controls"><textarea id="<?php echo $id; ?>" name="<?php echo $id; ?>"
                                                         rows="4"
                                                         placeholder="<?php echo osc_esc_html( $placeholder ); ?>"><?php echo tfc_getPref( $id ); ?></textarea>
						<?php if ( is_array( $helptext ) ) {
							foreach ( $helptext as $value ) {
								?>
                                <div class="help-box"> <?php echo $value; ?></div>
							<?php }
						} else {
							?>
                            <div class="help-box"> <?php echo $helptext; ?></div>
						<?php }
						?>
                    </div>
                </div>
			<?php }
		}

		/**
		 * @param $id
		 * @param $class
		 * @param $label
		 * @param $helptext
		 * @param $content
		 */
		public function createCustomInput( $id , $class , $label , $helptext , $content ) { ?>
            <div class="form-row input-description-wide <?php echo $class; ?>">
                <div class="form-label"> <?php echo $label; ?> </div>
                <div id="<?php echo $id; ?>" class="form-controls">
					<?php echo $content; ?>
					<?php if ( is_array( $helptext ) ) {
						foreach ( $helptext as $value ) {
							?>
                            <div class="help-box"> <?php echo $value; ?></div>
						<?php }
					} else {
						?>
                        <div class="help-box"> <?php echo $helptext; ?></div>
					<?php }
					?>
                </div>
            </div>
		<?php }

		/**
		 * @param $id
		 * @param string $class
		 * @param $fieldFunction
		 */
		public function createCustomSection( $id , $class = '' , $fieldFunction ) {
			?>
            <div id="<?php echo $id; ?>" class="form-row input-description-wide <?php echo $class; ?>">
				<?php call_user_func( $fieldFunction ); ?>
            </div>
		<?php }

		/**
		 * @param $title
		 */
		public function createFormSubHeading( $title ) {
			echo '<h3 class="render-title"><i class="fas fa-certificate"></i> ' . $title . '</h3>';
		}

		/**
		 * @param $html
		 */
		public function renderCustomContent( $html ) {
			echo $html;
		}

		/**
		 * @param $formId
		 * @param $formclass
		 * @param $formtitle
		 * @param array $formdata
		 */
		private function renderSinglePage( $formId , $formclass , $formtitle , $formdata = array () ) {
			?>
            <div class="tfc-dashboard">
                <h2 class="render-title "> <?php echo $formtitle; ?> </h2>
                <div id="form-<?php echo $formId; ?>" class="<?php echo $formclass; ?>">
                    <div class="form-horizontal"><?php
							$this->renderInputFromData( $formdata );
						?>
                    </div>
                </div>
            </div>
			<?php
		}

		private function processActionForForms() {
			if ( Params::getParam( 'tfc-admin-form-action' ) ) {
				$formActionId = Params::getParam( 'tfc-admin-form-action' );
				$allForm      = $this->getForms();
				foreach ( $allForm as $form ) {
					if ( $form[ 'form-type' ] = 'form-action' && $formActionId == $form[ 'id' ] ) {
						foreach ( $form[ 'form-data' ] as $input ) {
							switch ( $input[ 'type' ] ) {
								case 'text-input':
									if ( $this->localesCount > 1 && $input[ 'translate' ] ) {
										foreach ( $this->locales as $locale ) {
											if(osc_get_preference($input[ 'id' ] . $locale[ 'pk_c_code' ],'shopclass_theme') !== Params::getParam( $input[ 'id' ] . $locale[ 'pk_c_code' ] )){
												osc_set_preference( $input[ 'id' ] . $locale[ 'pk_c_code' ] , Params::getParam( $input[ 'id' ] . $locale[ 'pk_c_code' ] ) , 'shopclass_theme' );
											}
										}
									} else {
										if(osc_get_preference($input[ 'id' ], 'shopclass_theme') !== Params::getParam( $input[ 'id' ] )){
											osc_set_preference( $input[ 'id' ] , Params::getParam( $input[ 'id' ] ) , 'shopclass_theme' );
										}
									}
									break;
								case 'select-input':
									if(osc_get_preference($input[ 'id' ], 'shopclass_theme') !== Params::getParam( $input[ 'id' ] )){
										osc_set_preference( $input[ 'id' ] , Params::getParam( $input[ 'id' ] ) , 'shopclass_theme' );
									}
									break;
								case 'checkbox-input':
									if(osc_get_preference($input[ 'id' ], 'shopclass_theme') !== Params::getParam( $input[ 'id' ] )){
										osc_set_preference( $input[ 'id' ] , Params::getParam( $input[ 'id' ] ) , 'shopclass_theme' , 'BOOL' );
									}
									break;
								case 'textarea-input':
									if ( $this->localesCount > 1 && $input[ 'translate' ] ) {
										foreach ( $this->locales as $locale ) {
											if(osc_get_preference($input[ 'id' ] . $locale[ 'pk_c_code' ],'shopclass_theme') !== Params::getParam( $input[ 'id' ] . $locale[ 'pk_c_code' ] )){
												osc_set_preference( $input[ 'id' ] . $locale[ 'pk_c_code' ] , Params::getParam( $input[ 'id' ] . $locale[ 'pk_c_code' ] , false , false , false ) , 'shopclass_theme' );
											}
										}
									} else {
										if(osc_get_preference($input[ 'id' ], 'shopclass_theme') !== Params::getParam( $input[ 'id' ] )){
											osc_set_preference( $input[ 'id' ] , Params::getParam( $input[ 'id' ] , false , false , false ) , 'shopclass_theme' );
										}
									}
									break;
								case 'custom-section':
									call_user_func( $input[ 'action-function' ] );
									break;
							}

						}
						osc_add_flash_ok_message( $form[ 'form-success-msg' ] , 'admin' );
						osc_redirect_to( tfcAdminDashboard::newInstance()->getRouteAdminURL( $formActionId ) );
						break;
					}
				}

			}

		}

		public function resetInputs() {
			$this->inputs = array ();
		}

		public function resetForms() {
			$this->forms = array ();
		}

		public function createAdminPage() {
			if ( OC_ADMIN ) {
				$formOptions = $this->getForms();
				osc_run_hook( 'before_tfcAdminForm' );
				//print_r($formOptions);
				//$formOptions = tfcCache::runCache ()->tfcGet ('tfc_admin_form',$this->formOptions,300);
				foreach ( $formOptions as $form ) {
					//file_put_contents ('formData.txt',print_r($form,true));
					if ( $form[ 'type' ] == 'setting-page' ) {
						$newForm = function () use ( $form ) {
							$this->renderSinglePage( $form[ 'id' ] , $form[ 'div-class' ] , $form[ 'form-title' ] , $form[ 'form-data' ] );
						};
						osc_add_hook( 'tfc-' . $form[ 'id' ] , $newForm , 4 );
					}
				}
				//$this->addActionForForms();
			}
		}

		/**
		 * @param $id
		 * @param $type
		 * @param $divclass
		 * @param $formtitle
		 * @param $formsuccessmsg
		 */
		public function addNewAdminForm( $id , $type , $divclass , $formtitle , $formsuccessmsg ) {
			$id = trim( $id );
			if ( $id != '' ) {
				$formdata = $this->getInputs();
				$newForm  = array (
					'id'               => $id ,
					'type'             => $type ,
					'div-class'        => $divclass ,
					'form-title'       => $formtitle ,
					'form-success-msg' => $formsuccessmsg ,
					'form-data'        => $formdata ,
				);
			}
			if ( isset( $newForm ) ) {
				$this->setForms( $newForm );
			}
		}

		/**
		 * @return array
		 */
		public function getInputs() {
			return $this->inputs;
		}

		/**
		 * @param array $input
		 */
		public function setInputs( array $input ) {
			$this->inputs[] = $input;
		}

		/**
		 * @param $id
		 * @param $type
		 * @param $divclass
		 * @param $formtitle
		 */
		public function addNewAdminPage( $id , $type , $divclass , $formtitle ) {
			$id = trim( $id );
			if ( $id != '' ) {
				$formdata = $this->getInputs();
				$newForm  = array (
					'id'         => $id ,
					'type'       => $type ,
					'div-class'  => $divclass ,
					'form-title' => $formtitle ,
					'form-data'  => $formdata ,
				);
			}
			if ( isset( $newForm ) ) {
				$this->setForms( $newForm );
			}
		}

		/**
		 * @param $id
		 * @param $type
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 * @param string $placeholder
		 * @param array $options
		 * @param bool $translate
		 */
		public function addNewInputToForm( $id , $type , $divclass , $label , $helptext , $placeholder = '' , $options = array () , $translate = false ) {
			$id = trim( $id );
			if ( $id != '' ) {
				switch ( $type ) {
					case 'text-input' || 'textarea-input':
						$input = array (
							'id'          => $id ,
							'type'        => $type ,
							'div-class'   => $divclass ,
							'label'       => $label ,
							'placeholder' => $placeholder ,
							'help-text'   => $helptext ,
							'translate'   => $translate
						);
						break;
					case 'checkbox-input':
						$input = array (
							'id'        => $id ,
							'type'      => $type ,
							'div-class' => $divclass ,
							'label'     => $label ,
							'help-text' => $helptext
						);
						break;
					case 'select-input':
						$input = array (
							'id'          => $id ,
							'type'        => $type ,
							'div-class'   => $divclass ,
							'label'       => $label ,
							'options'     => $options ,
							'placeholder' => $placeholder ,
							'help-text'   => $helptext
						);
						break;

				}
				if ( isset( $input ) ) {
					$this->setInputs( $input );
				}
			}

		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 * @param string $placeholder
		 * @param bool $translate
		 *
		 * @return $this
		 */
		public function addTextInput( $id , $divclass , $label , $helptext , $placeholder = '' , $translate = false ) {
			$input = array (
				'id'          => $id ,
				'type'        => 'text-input' ,
				'div-class'   => $divclass ,
				'label'       => $label ,
				'placeholder' => $placeholder ,
				'help-text'   => $helptext ,
				'translate'   => $translate
			);
			$this->setInputs( $input );

			return $this;

		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 * @param string $placeholder
		 * @param bool $translate
		 *
		 * @return $this
		 */
		public function addTextAreaInput( $id , $divclass , $label , $helptext , $placeholder = '' , $translate = false ) {
			$input = array (
				'id'          => $id ,
				'type'        => 'textarea-input' ,
				'div-class'   => $divclass ,
				'label'       => $label ,
				'placeholder' => $placeholder ,
				'help-text'   => $helptext ,
				'translate'   => $translate
			);
			$this->setInputs( $input );

			return $this;

		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 *
		 * @return $this
		 */
		public function addCheckboxInput( $id , $divclass , $label , $helptext ) {
			$input = array (
				'id'        => $id ,
				'type'      => 'checkbox-input' ,
				'div-class' => $divclass ,
				'label'     => $label ,
				'help-text' => $helptext
			);
			$this->setInputs( $input );

			return $this;

		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 * @param string $placeholder
		 * @param array $options
		 *
		 * @return $this
		 */
		public function addSelectInput( $id , $divclass , $label , $helptext , $placeholder = '' , $options = array () ) {
			$input = array (
				'id'          => $id ,
				'type'        => 'select-input' ,
				'div-class'   => $divclass ,
				'label'       => $label ,
				'options'     => $options ,
				'placeholder' => $placeholder ,
				'help-text'   => $helptext
			);
			$this->setInputs( $input );

			return $this;

		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $label
		 * @param $helptext
		 * @param $content
		 *
		 * @return $this
		 */
		public function addCustomInput( $id , $divclass , $label , $helptext , $content ) {
			$input = array (
				'id'        => $id ,
				'type'      => 'custom-input' ,
				'div-class' => $divclass ,
				'label'     => $label ,
				'content'   => $content ,
				'help-text' => $helptext
			);
			$this->setInputs( $input );

			return $this;
		}

		/**
		 * @param $id
		 * @param $divclass
		 * @param $fieldFunction
		 * @param $actionFucntion
		 *
		 * @return $this
		 */
		public function addCustomSection( $id , $divclass , $fieldFunction , $actionFucntion ) {
			$input = array (
				'id'              => $id ,
				'type'            => 'custom-section' ,
				'div-class'       => $divclass ,
				'field-function'  => $fieldFunction ,
				'action-function' => $actionFucntion
			);
			$this->setInputs( $input );

			return $this;
		}

		/**
		 * @param $id
		 * @param $content
		 *
		 * @return $this
		 */
		public function addCustomContent( $id , $content ) {
			$input = array (
				'id'      => $id ,
				'type'    => 'custom-content' ,
				'content' => $content ,
			);
			$this->setInputs( $input );

			return $this;
		}

		/**
		 * @param $title
		 *
		 * @return $this
		 */
		public function addNewSubheading( $title ) {
			$title = trim( $title );

			if ( $title != '' ) {
				$input = array (
					'type'  => 'form-subheading' ,
					'title' => $title
				);
			}
			if ( isset( $input ) ) {
				$this->setInputs( $input );
			}

			return $this;
		}

	}