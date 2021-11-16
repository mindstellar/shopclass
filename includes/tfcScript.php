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

    use shopclass\includes\classes\tfcEnqueueStyleScript;

    function shopclass_add_js_styles()
    {
        osc_register_script('jquery', tfc_theme_url('assets/js/jquery.min.js'));
        //tfc_register_script('jquery', tfc_theme_url('assets/js/jquery.min.js'));
        tfc_register_script('bootstrap', tfc_theme_url('assets/js/bootstrap.min.js'), 'jquery');

        tfc_register_script('masonry', tfc_theme_url('assets/js/masonry.pkgd.min.js'), 'images-loaded');
        tfc_register_script('default-js', tfc_theme_url('assets/js/default.js'), 'masonry');
        tfc_register_script('images-loaded', tfc_theme_url('assets/js/imagesloaded.pkgd.js'), 'match-height');
        tfc_register_script('match-height', tfc_theme_url('assets/js/jquery.matchHeight-min.js'), 'bootstrap');
        tfc_register_script('responsive-carousel', tfc_theme_url('assets/js/responsiveCarousel-patched.min.js'), 'bootstrap');


        tfc_register_script('jquery-ui', tfc_theme_url('assets/js/jquery-ui.min.js'), 'bootstrap');
        tfc_register_script('typeahead', tfc_theme_url('assets/js/bootstrap3-typeahead.min.js'), 'bootstrap');
        tfc_register_script('jquery-validate', tfc_theme_url('assets/js/jquery.validate.min.js'), 'bootstrap');
        tfc_register_script('magnific-popup', tfc_theme_url('assets/js/jquery.magnific-popup.min.js'), 'bootstrap');


        tfc_register_script('bootsidemenu', tfc_theme_url('assets/js/BootSideMenu.js'), 'bootstrap');
        tfc_register_script('bootbox', tfc_theme_url('assets/js/bootbox.js'), 'bootstrap');
        //Scripts Style enqueue start

        tfc_enqueue_script('jquery');
        tfc_enqueue_script('jquery-ui');
        tfc_enqueue_script('typeahead');
        tfc_enqueue_script('bootstrap');
        tfc_enqueue_script('default-js');
        tfc_enqueue_script('responsive-carousel');


        $location = Rewrite::newInstance()->get_location();
        $section = Rewrite::newInstance()->get_section();

        if (Params::getParam('style')) {
            $default_style = Params::getParam('style');
        } elseif (tfc_getPref('default_style')) {
            $default_style = tfc_getPref('default_style');
        } else {
            $default_style = 'bluemone';
        }
        $scver = tfc_filetime('assets/css/theme/' . $default_style . '.min.css');

        tfcEnqueueStyleScript::newInstance()->addStyle('google-font', 'https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap');

        tfcEnqueueStyleScript::newInstance()->addStyle('font-awesome-4.7', tfc_theme_url('assets/css/font-awesome.min.css') . '?v=' . TFC_VER);

        tfcEnqueueStyleScript::newInstance()->addStyle('main-css', tfc_theme_url('assets/css/theme/' . $default_style) . '.min.css?v=' . $scver);

        if (osc_current_user_locale() == 'ar_SY') {
            tfcEnqueueStyleScript::newInstance()->addStyle('bootstrap-rtl', tfc_theme_url('assets/css/bootstrap-rtl.min.css'));
        }

        if (osc_is_ad_page()) {
            tfcEnqueueStyleScript::newInstance()->addStyle('magnific-popup', tfc_theme_url('assets/css/magnific-popup.css'));

            tfc_enqueue_script('magnific-popup');
        }

        if (in_array($location, array('item', 'register', 'contact', 'login', 'user'))) {
            tfc_enqueue_script('jquery-validate');
        }
        if (osc_is_publish_page() || tfc_is_item_edit_page()) {
            tfc_enqueue_script('jquery-fineuploader');
            tfcEnqueueStyleScript::newInstance()->addStyle('fineuploader', tfc_theme_url('assets/css/fineuploader.css'));
        }
        //Scripts Style Enqueue End
        //Unset Variable
        unset($default_style, $scver, $location, $section);

    }

    osc_add_hook('header', 'shopclass_add_js_styles', 1);

    //Function to Enable shift+ Multiple Select on Admin lisiting Mangagment Page
    function checkbox_shift_select()
    {
        ?>
        <script>

            var lastChecked = null;

            $(document).ready(function () {
                var $chkboxes = $('input:checkbox');
                $chkboxes.click(function (e) {
                    if (!lastChecked) {
                        lastChecked = this;
                        return;
                    }

                    if (e.shiftKey) {
                        var start = $chkboxes.index(this);
                        var end = $chkboxes.index(lastChecked);

                        $chkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', lastChecked.checked);

                    }

                    lastChecked = this;
                });
            });
        </script>
    <?php }

    osc_add_hook('admin_footer', 'checkbox_shift_select');


    //Function to load Javascript Needed on Search Page to Subscribe Alert
    function tfc_alert_subscribe_js()
    { ?>

        <script>
            $(document).ready(function () {
                $(".sub_button").click(function () {
                    $.post('<?php echo osc_base_url(true); ?>', {
                            email: $("#alert_email").val(),
                            userid: $("#alert_userId").val(),
                            alert: $("#alert").val(),
                            page: "ajax",
                            action: "alerts"
                        },
                        function (data) {
                            if (data == 1) {
                                alert('<?php _e('You have sucessfully subscribed to the alert', 'shopclass'); ?>');
                            } else if (data == -1) {
                                alert('<?php _e('Invalid email address', 'shopclass'); ?>');
                            } else {
                                alert('<?php _e('There was a problem with the alert', 'shopclass');?>');
                            }
                            ;
                        });
                    return false;
                });

                var sQuery = '<?php echo AlertForm::default_email_text(); ?>';

                if ($('input[name=alert_email]').val() == sQuery) {
                    $('input[name=alert_email]').css('color', 'gray');
                }
                $('input[name=alert_email]').click(function () {
                    if ($('input[name=alert_email]').val() == sQuery) {
                        $('input[name=alert_email]').val('');
                        $('input[name=alert_email]').css('color', '');
                    }
                });
                $('input[name=alert_email]').blur(function () {
                    if ($('input[name=alert_email]').val() == '') {
                        $('input[name=alert_email]').val(sQuery);
                        $('input[name=alert_email]').css('color', 'gray');
                    }
                });
                $('input[name=alert_email]').keypress(function () {
                    $('input[name=alert_email]').css('background', '');
                })
            });
        </script>
    <?php }

    function main_tfc_carousel_js()
    { ?>
        <script>
            jQuery(document).ready(function ($) {
                $('#tfc-caraousel').show();
                $('.crsl-items').carousel({
                    autoRotate: <?php if (tfc_getPref("carousel_rotate")) {
                        echo tfc_getPref("carousel_rotate");
                    } else {
                        echo "false";
                    }?>,
                    visible: 4,
                    itemMinWidth: 260,
                    //itemWidth: 260,
                    itemMargin: 20,
                    itemEqualHeight: true
                });
            });
        </script>
    <?php }

    function tfc_panel_group_state_change_js()
    { ?>
        <script>
            var selectIds = $("*[id*='collapse']");
            $(function ($) {
                selectIds.on('show.bs.collapse hidden.bs.collapse', function () {
                    $(this).prev().find('.fa-plus,.fa-minus').toggleClass('fa-plus fa-minus');
                });
            });
        </script>
    <?php }

    function tfc_scroll_top_js()
    { ?>
        <script>
            $(document).ready(function () {

                // hide #back-top first
                $("#back-top").hide();

                // fade in #back-top
                $(function () {
                    $(window).scroll(function () {
                        if ($(this).scrollTop() > 100) {
                            $('#back-top').fadeIn();
                        } else {
                            $('#back-top').fadeOut();
                        }
                    });

                    // scroll body to 0px on click
                    $('#back-top a').click(function () {
                        $('body,html').animate({
                            scrollTop: 0
                        }, 800);
                        return false;
                    });
                });

            });
        </script>
    <?php }

    osc_add_hook('footer_scripts_loaded', 'tfc_scroll_top_js');

    function tfc_demo_css_js()
    { ?>
        <script>
            $(document).ready(function () {
                $('#demo_layout').BootSideMenu({
                    side: "left", autoClose: true
                });
            });
            $(document).ready(function () {
                $("#nav-css li a").click(function () {
                    $("link.changeme").attr("href", $(this).attr('rel'));
                    return false;
                });
            });
        </script>
    <?php }

    function tfc_gallery_js()
    {
        ?>
        <script>
            $(document).ready(function () {
                $('#show_gallery').imagesLoaded(function () {
                    var container = document.querySelector('#show_gallery');
                    var msnry = new Masonry(container, {
                        // options
                        columnWidth: '.adbox_gallery',
                        itemSelector: '.adbox_gallery'
                    });
                });
            });
        </script>
    <?php }

    function tfc_load_more_js()
    {
        ?>
        <script>
            $(document).ready(function () {
                // bind change event to click
                $('button.tfc_load_more').on('click', function () {
                    var getId = $(this).attr('id');
                    var secure_data = {
                        offset: +getId,
                        limit: 5,
                        showas: '<?php echo tfc_show_as();?>',
                        listclass: $(this).attr('data-listclass'),
                        galleryclass: $(this).attr('data-galleryclass'),

                    };

                    $.ajax({
                        type: "POST",
                        url: '<?php echo osc_base_url(true) . "?page=ajax&action=runhook&hook=load_listing"; ?>',//get response from this file
                        data: secure_data,
                        cache: false,
                        success: function (html, textStatus, jqXHR) {
                            if (jqXHR.status == 200) {
                                <?php if (tfc_show_as() == 'gallery')
                                { ?>        $('#' + getId).attr('id', parseInt(getId) + 5);
                                $(html).hide().appendTo("#show_gallery").fadeIn(1000);
                                $('#show_gallery').imagesLoaded(function () {
                                    var container = document.querySelector('#show_gallery');
                                    var msnry = new Masonry(container, {
                                        // options
                                        columnWidth: '.adbox_gallery',
                                        itemSelector: '.adbox_gallery'
                                    });
                                    msnry.appended(html);
                                });
                                <?php }
                                else
                                {?>            $('#' + getId).attr('id', parseInt(getId) + 5);
                                $(html).hide().appendTo("#show_list").fadeIn(1000);//send response to textarea
                                $('.adbox_ads').matchHeight({remove: true});
                                $('.adbox_ads').matchHeight();
                                <?php }?>
                            } else {
                                $('#' + getId).text('No More Ads').addClass('disabled');
                            }
                        }

                    });
                });
            });
        </script>

    <?php }

    function ajax_suggestion_sphinx_js()
    {
        if (defined('SPHINX_SEARCH')) { ?>
            <script>
                $(document).ready(function () {
                    $('#query').typeahead({

                        source: function (request, response) {
                            $.ajax({
                                url: '<?php echo osc_base_url(true) . "?page=ajax&action=runhook&hook=tfc-suggest"; ?>',
                                type: 'POST',
                                data: {term: request},
                                dataType: 'json',
                                success: function (data) {
                                    response(data);
                                }
                            });

                        },
                        matcher: function (item) {
                            return true;
                        },
                        items: 10,
                        autoSelect: false,
                        minLength: 2,
                        //hint:true,
                        fitToElement: true
                    });
                });

            </script>
        <?php }
    }

    function tfc_ajax_comment_js()
    {
        ?>
        <script>
            $(document).ready(function () {
                $('#comment_form').submit(function (event) {
                    $(".add_comment").text("Processing");

                    var formData = $("#comment_form").serialize();

                    // process the form
                    $.ajax({
                        type: 'POST',
                        url: '<?php echo osc_base_url(true) . "?page=ajax&action=runhook&hook=tfc-comment"; ?>',
                        data: formData,

                        beforeSend: function () {
                            $('.comment-processing').show();
                        },
                        complete: function () {
                            $('.comment-processing').hide();
                        },
                        success: function (html) {
                            //console.log(html);
                            $('#ajax-alert').empty().append(html);//send response to textarea
                            $('html, body').animate({
                                scrollTop: $(".comment-form").offset().top
                            }, 2000);
                            grecaptcha.reset('0');
                            $(".add_comment").text("Submit");
                        }
                    });
                    event.preventDefault();

                });

            });
        </script>
    <?php }

    function ajax_recent_ads_js()
    { ?>
        <script>
            $(".ajax-recent").load("<?php echo osc_base_url(true);?>?page=ajax&action=runhook&hook=recent_ads");
        </script>
    <?php }

    function ga_analytics()
    { ?>
        <script type="text/javascript">

            var _gaq = _gaq || [];
            _gaq.push(['_setAccount', '<?php echo tfc_getPref('google_analytic'); ?>']);
            _gaq.push(['_trackPageview']);

            (function () {
                var ga = document.createElement('script');
                ga.type = 'text/javascript';
                ga.async = true;
                ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
                var s = document.getElementsByTagName('script')[0];
                s.parentNode.insertBefore(ga, s);
            })();

        </script>
    <?php }

    if (tfc_getPref('google_analytic')) {
        osc_add_hook('footer_scripts_loaded', 'ga_analytics');;
    }