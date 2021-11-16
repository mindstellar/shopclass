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
namespace shopclass\includes\frm;
use Session;

/**
 * Class tfcContactForm
 */
class tfcContactForm extends tfcGenForm {

    /**
     * @return bool
     */
    static public function your_name() {
            if( Session::newInstance()->_getForm("yourName") != "" ) {
                $name = Session::newInstance()->_getForm("yourName");
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Name','shopclass')).'" class="form-control"', "yourName", $name, null, false);
            } else {
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Name','shopclass')).'" class="form-control"', "yourName", osc_logged_user_name(), null, false);
            }
            return true;
        }

    /**
     * @return bool
     */
    static public function your_email() {
             if( Session::newInstance()->_getForm("yourEmail") != "" ) {
                $email = Session::newInstance()->_getForm("yourEmail");
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Email','shopclass')).'" class="form-control"', "yourEmail", $email, null, false);
            } else {
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Email','shopclass')).'" class="form-control"', "yourEmail", osc_logged_user_email(), null, false);
            }
            return true;
        }

    /**
     * @return bool
     */
    static public function your_phone_number() {
            if( Session::newInstance()->_getForm("phoneNumber") != "" ) {
                $phoneNumber = Session::newInstance()->_getForm("phoneNumber");
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Phone Number','shopclass')).'" class="form-control"', "phoneNumber", $phoneNumber, null, false);
            } else {
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Phone Number','shopclass')).'" class="form-control"', "phoneNumber", osc_logged_user_phone(), null, false);
            }
            return true;
        }

    /**
     * @return bool
     */
    static public function the_subject() {
            if( Session::newInstance()->_getForm("subject") != "" ) {
                $subject = Session::newInstance()->_getForm("subject");
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Subject','shopclass')).'" class="form-control"', "subject", $subject, null, false);
            } else {
                parent::generic_input_text('placeholder="'.osc_esc_html(__('Enter Your Subject','shopclass')).'" class="form-control"', "subject", "", null, false);
            }
            return true;
        }

    /**
     * @return bool
     */
    static public function your_message() {
            if( Session::newInstance()->_getForm("message_body") != "" ) {
                $message = Session::newInstance()->_getForm("message_body");
                parent::generic_textarea('placeholder="'.osc_esc_html(__('Enter Your Message','shopclass')).'" class="form-control"', "message", $message);
            } else {
                parent::generic_textarea('placeholder="'.osc_esc_html(__('Enter Your Message','shopclass')).'" class="form-control"', "message", "");
            }
            return true;
        }

    /**
     *
     */
    static public function your_attachment() {
            echo '<input type="file" name="attachment" />';
        }

    }