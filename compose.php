<?php
/**
 * Copyright (C) 2015  freakedout (www.freakedout.de)
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 * or write to the Free Software Foundation, Inc., 51 Franklin St,
 * Fifth Floor, Boston, MA  02110-1301  USA
 **/

// no direct access
defined('ABSPATH') or die('Restricted Access');

if ($_SERVER['REQUEST_METHOD'] == 'POST'
    && !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'chimpxpress-compose')
) {
    die('Invalid request!');
}

if (isset($_POST['step'])) {
    $step = (int)$_POST['step'];

} else if (isset($_GET['step'])) {
    $step = (int)$_GET['step'];

} else {
    $step = 1;

    wp_enqueue_media();
    /*wp_enqueue_script( 'common' );
    wp_enqueue_script( 'jquery-color' );
    wp_print_scripts('editor');
    if (function_exists('add_thickbox')) add_thickbox();
    wp_print_scripts('media-upload');
    wp_enqueue_script('media-upload');

    wp_enqueue_script('utils');
    wp_admin_css();
    do_action("admin_print_styles-post-php");
    do_action('admin_print_styles');
    */
}

wp_enqueue_script('chimpx_innerxhtml', plugins_url('js' . DS . 'innerxhtml.js', __FILE__));
wp_enqueue_script('chimpx_jquery_equalwidths', plugins_url('js' . DS . 'jquery.equalwidths.js', __FILE__));
wp_enqueue_script('chimpx_php_default', plugins_url('js' . DS . 'php.default.min.js', __FILE__));
wp_enqueue_script('chimpxpress-colorbox', plugins_url('js' . DS . 'jquery.colorbox-min.js', __FILE__));
wp_enqueue_style('chimpxpress-colorbox', plugins_url('css' . DS . 'colorbox.css', __FILE__));

wp_enqueue_script('chimpxpress-core', plugins_url('js' . DS . 'chimpxpress_core.js', __FILE__));

require_once(CHIMPX_PLUGIN_DIR . 'class-MCAPI.php');
$MCAPI = new chimpxpressMCAPI(); ?>
<div style="display:none">
    <?php wp_editor('', 'CX_dummy_editor', ['tinymce' => true]); ?>
</div>
<div id="chimpxpressCompose">
    <?php wp_add_inline_script('chimpxpress-core', 'function chimpx_gotoStep(from, to) {
            if (parseInt(from) == 1 &&
                (  jQuery.trim(jQuery("#campaignName").val()) == ""
                || jQuery.trim(jQuery("#campaignSubject").val()) == "")
            ) {
                alert("' . esc_html__('Campaign name and subject line must be supplied!', 'chimpxpress') . '");

            } else if (
                (parseInt(to) == 1
                    && confirm("' . esc_html__('Are you sure you want to go back to step one? All entered content will be lost!', 'chimpxpress') . '")
                )
                || parseInt(to) != 1
            ) {
                var stop = false;

                if (parseInt(from) == 1
                    && jQuery.trim(jQuery("#campaignName").val()) != ""
                    && jQuery.trim(jQuery("#campaignSubject").val()) != ""
                ) {
                    var data = {
                        action: "validate_campaign_name_subject",
                        _wpnonce: jQuery("#_wpnonce").val(),
                        campaignName: jQuery.trim(jQuery("#campaignName").val()),
                        subject: jQuery.trim(jQuery("#campaignSubject").val())
                    };

                    jQuery.ajax({
                        url: ajaxurl,
                        type: "post",
                        async: false,
                        dataType: "json",
                        data: data,
                        beforeSend: function () {
                            jQuery("#ajaxLoader").show();
                        },
                        success: function (response) {
                            if (!response.isValid) {
                                alert("' . esc_html__('Campaign name and subject can not consist exclusively of special characters!', 'chimpxpress') . '");
                                stop = true;
                            }
                        },
                        complete: function () {
                            jQuery("#ajaxLoader").hide();
                        }
                    });
                }

                if (!stop) {
                    var sections = jQuery("#sections").val();
                    var editorContent = jQuery("#editorContent").val();
                    if (parseInt(from) != 1 && parseInt(from) != (parseInt(sections) + 2)) {
                        editorContent = editorContent.split("|###|");
                        if (typeof tinyMCE.activeEditor != "undefined") {
                            currentContent = tinyMCE.activeEditor.getContent();
                        }
                        editorContent[parseInt(from) - 2] = currentContent;
                        editorContent = editorContent.join("|###|");
                    }

                    var data = {
                        action: "compose_gotoStep",
                        _wpnonce: jQuery("#_wpnonce").val(),
                        step: to,
                        CX_listId: jQuery("#CX_listId").val(),
                        CX_default_from_name: jQuery("#CX_default_from_name").val(),
                        CX_default_from_email: jQuery("#CX_default_from_email").val(),
                        CX_template: jQuery("#CX_template").val(),
                        templateName: jQuery("#CX_templateName").val(),
                        sections: jQuery("#sections").val(),
                        sectionNames: jQuery("#sectionNames").val(),
                        skipSections: jQuery("#skipSections").val(),
                        editorContent: editorContent,
                        campaignName: htmlentities(jQuery("#campaignName").val()),
                        campaignSubject: htmlentities(jQuery("#campaignSubject").val()),
                        CX_campaignId: jQuery("#CX_campaignId").val()
                    };

                    jQuery.ajax({
                        type: "post",
                        url: ajaxurl,
                        data: data,
                        //async: false,
                        beforeSend: function () {
                            jQuery("#ajaxLoader").show();
                        },
                        success: function (response) {
                            jQuery("#CXwrap").html(response);
                        },
                        complete: function () {
                            if (typeof theEditorID !== "undefined") {
                                quicktags({id: theEditorID}); // REQUIRED to show quick tags and fix tabs
                                tinymce.execCommand("mceAddEditor", true, theEditorID); // REQUIRED to fix editor created in AJAX
                            }
                            jQuery("#ajaxLoader").hide();
                        }
                    });
                }
            }
        }

        function chimpx_removeDraft() {
            var data = {
                action: "compose_removeDraft",
                _wpnonce: jQuery("#_wpnonce").val(),
                cid: jQuery("#CX_campaignId").val()
            };
            jQuery.post(ajaxurl, data, function (response) {
                window.location = "admin.php?page=chimpXpressDashboard";
            });
        }


        jQuery(document).ready(function ($) {
            jQuery("#preloaderContainer").slideUp();
            var sections = 0;
            jQuery("#CX_template").change(function () {
                if (templates[this.value]["preview"]) {
                    //jQuery("#preview a").css( "visibility", "" );
                    jQuery("#CX_preview a").show();
                    jQuery("#CX_preview a").attr("href", templates[this.value]["preview"]);
                    jQuery("#CX_preview a").colorbox();
                } else {
                    //jQuery("#CX_preview a").css( "visibility", "hidden" );
                    jQuery("#CX_preview a").hide();
                }
                jQuery("#CX_preview").css("display", "inline-block");

                skip = substr_count(templates[this.value]["skipSections"], ",");
                if (skip > 0) {
                    skip = skip + 1;
                }

                jQuery("#CX_sectionsValue").html(templates[this.value]["sections"]);
                jQuery("#CX_sectionsText").css("display", "inline-block");
                jQuery("#sections").val(templates[this.value]["sections"]);
                jQuery("#skipSections").val(templates[this.value]["skipSections"]);

                jQuery("#tName").html(templates[this.value]["templateName"]);
                jQuery("#CX_templateName").val(templates[this.value]["templateName"]);

                var sectionNames = "";
                for (i = 0; i < templates[this.value]["sections"]; i++) {
                    sectionNames += templates[this.value]["sectionNames"][i] + "|###|";
                }
                sectionNames = sectionNames.slice(0, -5);
                jQuery("#sectionNames").val(sectionNames);
                var editorContent = "";
                for (i = 0; i < templates[this.value]["sections"]; i++) {
                    editorContent += templates[this.value]["editorContent"][i] + "|###|";
                }
                editorContent = editorContent.slice(0, -5);
                jQuery("#editorContent").val(html_entity_decode(editorContent));

                var sections = templates[this.value]["sections"];
                chimpx_createSteps(this.value, sections);
            });

            jQuery("#preview_image a").colorbox();

            jQuery("#reloadCache a").unbind("click");
            jQuery("#reloadCache a").click(function () {
                jQuery("#ajaxLoader").show();
                var data = {action: "compose_clear_cache"};
                jQuery.post(ajaxurl, data, function (response) {
                    window.location = "admin.php?page=chimpXpressCompose";
                });
            });

            jQuery("#cancel").unbind("click");
            jQuery("#cancel").click(function () {
                if (confirm("' . esc_html__('Are you sure you want to cancel? All entered content will be lost!', 'chimpxpress') . '")) {
                    window.location = "admin.php?page=chimpXpressDashboard";
                } else {
                    return false;
                }
            });

            jQuery("#cancelCompose").unbind("click");
            jQuery("#cancelCompose").click(function () {
                if (confirm("' . esc_html__('Are you sure you want to cancel? All entered content will be lost!', 'chimpxpress') . '")) {
                    chimpx_removeDraft();
                } else {
                    return false;
                }
            });

            jQuery("#gotoMailchimp").unbind("click");
            jQuery("#gotoMailchimp").click(function () {
                window.location = "admin.php?page=chimpXpressDashboard";
            });


            jQuery("#CX_listId").change(function () {
                jQuery("#CX_listSubscribersValue").html(lists[this.value]["member_count"]);
                jQuery("#CX_listSubscribers").css("display", "inline-block");

                jQuery("#CX_default_from_name").val(lists[this.value]["default_from_name"]);
                jQuery("#CX_default_from_email").val(lists[this.value]["default_from_email"]);

            });

            jQuery("#campaignSubject").blur(function () {
                if (this.value) {
                    jQuery("#subjectTitle").html(this.value);
                } else {
                    jQuery("#subjectTitle").html("&nbsp;");
                }
            });
            jQuery("#campaignSubject").keyup(function () {
                if (this.value) {
                    jQuery("#subjectTitle").html(this.value);
                } else {
                    jQuery("#subjectTitle").html("&nbsp;");
                }
            });

        });

        function chimpx_createSteps(id, sections) {
            var stepButton = "";
            if (!sections) {
                sections = templates[id]["sections"];
            }
            // add one step for each template section
            var thisStep = 2;
            var x = 2;

            for (i = 0; i < sections; i++) {
                stepButton += "<div class=\"bgLine\"></div><div id=\"step" + x + "\" class=\"step ";

                if (thisStep == ' . esc_attr($step) . ') {
                    stepButton += "activeStep";
                } else {
                    stepButton += "inactiveStep";
                }

                stepButton += "\"><a href=\"javascript:chimpx_gotoStep(' . esc_attr($step) . '," + x + ");\" title=\"go to step " + thisStep + "\">" + thisStep + "</a>";
                stepButton += "<div class=\"stepSubTitle\">" + templates[id]["sectionNames"][i] + "</div>";
                stepButton += "</div>";
                thisStep++;
                x++;
            }

            // add last step
            var stepSubmit = parseInt(sections) + 2;
            stepButton = stepButton + "<div class=\"bgLine\"></div><div id=\"step" + stepSubmit + "\" class=\"step lastStep ";
            if (stepSubmit == ' . esc_attr($step) . ') {
                stepButton += "activeStep";
            } else {
                stepButton += "inactiveStep";
            }

            stepButton += "\"><a href=\"javascript:chimpx_gotoStep(' . esc_attr($step) . '," + stepSubmit + ");\" title=\"' . esc_html__('go to step', 'chimpxpress') . ' " + stepSubmit + "\">" + stepSubmit + "</a>";
            stepButton += "<div class=\"stepSubTitle\">' . esc_html__('submit', 'chimpxpress') . '</div>";
            stepButton += "</div>";

            jQuery("#stepsTemplateSections").html("").append(stepButton);

            if (' . esc_attr($step) . ' < (parseInt(sections) + 2)) {
                jQuery("#nextStep").unbind("click");
                jQuery("#nextStep").click(function () {
                    chimpx_gotoStep(' . esc_attr($step) . ', ' . esc_attr($step + 1) . ');
                    return;
                });
                jQuery("#next").unbind("click");
                jQuery("#next").click(function () {
                    chimpx_gotoStep(' . esc_attr($step) . ', ' . esc_attr($step + 1) . ' );
                    return;
                });
            } else {
                jQuery("#nextStep a").css("cursor", "no-drop");
            }
            ' . ((($step - 1) >= 1) ? '
            jQuery("#prevStep").unbind("click");
            jQuery("#prevStep").click(function () {
            chimpx_gotoStep(' . esc_attr($step) . ', ' . esc_attr($step - 1) . ' );
            });'
            : 'jQuery("#prevStep a").css("cursor", "no-drop");') . '

            jQuery(".step").hover(function () {
                    if (jQuery(this).attr("id") != "step' . esc_attr($step) . '") {
                        jQuery(this).removeClass("inactiveStep");
                        jQuery(this).addClass("activeStep");
                    }
                },
                function () {
                    if (jQuery(this).attr("id") != "step' . esc_attr($step) . '") {
                        jQuery(this).removeClass("activeStep");
                        jQuery(this).addClass("inactiveStep");
                    }
                });
            jQuery(".prevNext").hover(
                function () {
                jQuery(this).css("opacity", 1);
                },
                function () {
                    jQuery(this).css("opacity", "");
                });

                jQuery(".step").equalWidths();
            }

            var buttons = "";
            document.write = function (e) {
            buttons = buttons + e;
            jQuery("#quicktags").html(buttons);
        };', 'after');

    include(CHIMPX_PLUGIN_DIR . 'loggedInStatus.php'); ?>

    <h1 class="componentHeading">chimpXpress</h1>
    <div class="clr"></div>
    <?php if (!$_SESSION['MCping']) { ?>
        <div class="updated" style="width:100%;text-align:center;padding:10px 0 13px;">
            <a href="options-general.php?page=chimpXpressConfig"><?php esc_html_e('Please connect your Mailchimp account!', 'chimpxpress'); ?></a>
        </div><?php
    }
    global $wp_filesystem;
    if ($wp_filesystem->method != 'direct') {
        $chimpxpress = new chimpxpress;
        $ftpstream = ftp_connect(sanitize_text_field($chimpxpress->settings['ftpHost']));
        $login = ftp_login($ftpstream, sanitize_text_field($chimpxpress->settings['ftpUser']), $chimpxpress->settings['ftpPasswd']);
        $ftproot = ftp_chdir($ftpstream, wp_sanitize_redirect($chimpxpress->settings['ftpPath']));
        $adminDir = ftp_chdir($ftpstream, 'wp-admin');

        if ($wp_filesystem->method != 'direct' &&
            (!$chimpxpress->settings['ftpHost'] || !$chimpxpress->settings['ftpUser']
                || !$chimpxpress->settings['ftpPasswd'] || !$ftpstream || !$login || !$ftproot || !$adminDir)) { ?>
            <div class="updated" style="width:100%;text-align:center;padding:10px 0 13px;">
                <a href="options-general.php?page=chimpXpressConfig"><?php esc_html_e('Direct file access not possible. Please enter valid ftp credentials in the configuration!', 'chimpxpress'); ?></a>
            </div><?php
        }
        ftp_close($ftpstream);
    } ?>

    <div style="display:block;height:3em;"></div>
    <h3><?php esc_html_e('Compose', 'chimpxpress'); ?></h3>

    <hr/>

    <h4 id="subjectTitle"><?php echo (isset($_POST['campaignSubject'])) ? esc_html(sanitize_title($_POST['campaignSubject'])) : '&nbsp;'; ?></h4>
    <div id="tName"><?php echo (isset($_POST['templateName'])) ? esc_html(sanitize_text_field($_POST['templateName'])) : ''; ?></div>

    <div id="stepsContainer">
        <div id="stepsContainerInner">
            <div id="prevStep" class="prevNext">
                <a href="javascript:void(0);"
                   title="<?php esc_attr_e('previous step', 'chimpxpress'); ?>"><?php esc_html_e('previous step', 'chimpxpress'); ?></a>
            </div>
            <div id="step1" class="step <?php echo ($step == 1) ? 'activeStep' : 'inactiveStep'; ?>">
                <a href="javascript:chimpx_gotoStep(<?php echo esc_attr($step); ?>,1);"
                   title="<?php esc_attr_e('go to step', 'chimpxpress'); ?> 1">1</a>
                <div class="stepSubTitle"><?php esc_html_e('settings', 'chimpxpress'); ?></div>
            </div>
            <div id="stepsTemplateSections"></div>
            <div id="nextStep" class="prevNext">
                <a href="javascript:void(0);"
                   title="<?php esc_attr_e('next step', 'chimpxpress'); ?>"><?php esc_html_e('next step', 'chimpxpress'); ?></a>
            </div>
            <div class="clr"></div>
        </div>
        <div class="clr"></div>
    </div>
    <div class="clr"></div>
    <div id="ajaxLoader"
         style="background-image: url(<?php echo esc_attr(plugins_url('/images/ajax-loader.gif', __FILE__)); ?>)"></div>

    <?php
    if ($step == 1) {
        chimpx_step1();

    } else if ($step > ((int)$_POST['sections'] + 1)) {
        chimpx_stepSubmit();

    } else {
        chimpx_stepContent($step);
    }

    function chimpx_step1() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST'
            && !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'chimpxpress-compose')
        ) {
            die('Invalid request!');
        }

        global $wp_filesystem;
        $MCAPI = new chimpxpressMCAPI();

        $cacheDir = chimpxpress::getUploadDir()['absPath'] . DS . 'cache';
        $handler = $wp_filesystem;
        $useFTP = ($wp_filesystem->method !== 'direct');
        if ($useFTP) {
            $chimpxpress = new chimpxpress();
            $handler = ftp_connect(sanitize_text_field($chimpxpress->settings['ftpHost']));
            ftp_login($handler, sanitize_text_field($chimpxpress->settings['ftpUser']), $chimpxpress->settings['ftpPasswd']);
            ftp_chdir($handler, wp_sanitize_redirect($chimpxpress->settings['ftpPath']));
        }

        $cache = new chimpxpressJG_Cache($cacheDir, $useFTP, $handler);
        $templates = $cache->get('templates');

        if ($templates === false) {
            $templates = $MCAPI->templates();
            $cache->set('templates', $templates);
        }
        $templateInfo = [];
        if (isset($templates['templates'])) {
            foreach ($templates['templates'] as $t) {
                $templateInfo[$t['id']] = $cache->get('templateInfo_' . $t['id']);
                if (empty($templateInfo[$t['id']])) {
                    $templateInfo = $MCAPI->templateInfo($t['id']);
                    $defaultContent = $MCAPI->templateDefaultContent($t['id']);
                    $templateInfo[$t['id']] = array_merge($templateInfo ?: [], $defaultContent ?: []);

                    $cache->set('templateInfo_' . $t['id'], $templateInfo[$t['id']]);
                }
            }
        }

        $lists = $cache->get('lists');

        if (empty($lists)) {
            $MCAPI = new chimpxpressMCAPI();
            $lists = $MCAPI->lists();
            $cache->set('lists', $lists);
        }

        if (!$templates || !$lists) {
            esc_html_e('Templates and lists could not be retrieved! Please make sure you have set up templates and lists in Mailchimp.', 'chimpxpress');
            echo ' <span id="reloadCache"><a href="javascript:void(0)">';
                esc_html_e('Click here to re-try.', 'chimpxpress');
            echo '</a></span>';
            do_action('admin_print_scripts');

            return;
        }

         wp_add_inline_script('chimpxpress-core', 'jQuery(document).ready(function ($) {
                if ($("#CX_template").val()) {
                    $("#CX_template").trigger("change");
                }

                if ($("#CX_listId").val()) {
                    $("#CX_listId").trigger("change");
                }
            });');
        ?>

        <label for="CX_template"><?php esc_html_e('select an email template', 'chimpxpress'); ?></label>
        <br/>
        <select id="CX_template" name="CX_template">
            <?php
            $js = "var templates = {};\n";
            foreach ($templates['templates'] as $t) {
                $js .= "templates['" . esc_attr($t['id']) . "'] = {};\n";

                // remove header and footer from template's editable sections
                $i = 0;
                $skipSections = [];
                foreach ($templateInfo[$t['id']]['sections'] ?? [] as $key => $value) {
                    if (in_array(strtolower($key), ['header', 'footer'])
                        /*|| strpos($key, 'header_') === 0
                        || strpos($key, 'footer_') === 0*/
                        || strpos($key, 'repeat_') === 0
                    ) {
                        $skipSections[] = $i;
                    }
                    $i++;
                }
                $skipSectionsCount = count($skipSections);
                $skipSections = implode(',', $skipSections);

                $js .= "templates['" . $t['id'] . "']['skipSections'] = '$skipSections'\n;";

                $selected = (isset($_POST['CX_template']) && $_POST['CX_template'] == $t['id']) ? ' selected="selected"' : '';
                echo '<option value="' . esc_attr($t['id']) . '"' . $selected . '>' . esc_html(sanitize_title($t['name'])) . '</option>';

                $js .= "templates['" . esc_attr($t['id']) . "']['templateName'] = '" . esc_attr(sanitize_title($t['name'])) . "';\n";
                $js .= "templates['" . esc_attr($t['id']) . "']['sections'] = " . intval(count($templateInfo[$t['id']]['sections'] ?? []) - $skipSectionsCount) . ";\n";
                $js .= "templates['" . esc_attr($t['id']) . "']['preview'] = '" . sanitize_url($templateInfo[$t['id']]['thumbnail']) . "';\n";
                $js .= "templates['" . esc_attr($t['id']) . "']['sectionNames'] = {};\n";
                $js .= "templates['" . esc_attr($t['id']) . "']['editorContent'] = {};\n";
                $x = 0;
                foreach ($templateInfo[$t['id']]['sections'] as $key => $value) {
                    if (!in_array(strtolower($key), ['header', 'footer'])
                        /*&& strpos($key, 'header_') !== 0
                        && strpos($key, 'footer_') !== 0*/
                        && strpos($key, 'repeat_') !== 0
                    ) {
                        $js .= "templates['" . esc_attr($t['id']) . "']['sectionNames'][$x] = '" . esc_attr(sanitize_title($key)) . "';\n";
                        $js .= "templates['" . esc_attr($t['id']) . "']['editorContent'][$x] = '" . trim(str_replace(["'", "\n", "\r"], ["\'", " ", " "], esc_attr(wp_kses_post($value)))) . "';\n";
                        $x++;
                    }
                }
            } ?>
        </select>

        <?php wp_add_inline_script('chimpxpress-core', $js); ?>

        <div id="CX_preview" style="display:none;">
            <a class="button" href="" target="_blank"><?php esc_html_e('preview', 'chimpxpress'); ?></a>
        </div>
        <div id="CX_sectionsText" style="display:none;">
            <?php esc_html_e('this template has', 'chimpxpress'); ?>
            <span id="CX_sectionsValue"></span>&nbsp;<?php esc_html_e('editable areas', 'chimpxpress'); ?>
        </div>
        <input type="hidden" name="sections" id="sections" value=""/>
        <div style="clear: both;"></div>

        <br/>
        <br/>

        <label for="CX_listId"><?php esc_html_e('select a subscriber list', 'chimpxpress'); ?></label><br/>
        <select id="CX_listId" name="CX_listId">
            <?php
            $js = "var lists = {};\n";
            foreach ($lists['lists'] as $l) {
                $selected = (isset($_POST['CX_listId']) && $_POST['CX_listId'] == $l['id']) ? ' selected="selected"' : '';
                echo '<option value="' . esc_attr($l['id']) . '"' . $selected . '>' . esc_html(sanitize_title($l['name'])) . '</option>';
                $js .= "lists['" . esc_attr($l['id']) . "'] = {};\n";
                $js .= "lists['" . esc_attr($l['id']) . "']['member_count'] = '" . esc_attr(intval($l['stats']['member_count'])) . "';\n";
                $js .= "lists['" . esc_attr($l['id']) . "']['default_from_name'] = '" . esc_attr(sanitize_title($l['campaign_defaults']['from_name'])) . "';\n";
                $js .= "lists['" . esc_attr($l['id']) . "']['default_from_email'] = '" . esc_attr(sanitize_email($l['campaign_defaults']['from_email'])) . "';\n";
            } ?>
        </select>
        <div id="CX_listSubscribers" style="display:none;">
            <?php esc_html_e('this list has', 'chimpxpress'); ?>
            <span id="CX_listSubscribersValue"></span>&nbsp;<?php esc_html_e('active subscribers', 'chimpxpress'); ?>
        </div>
        <div style="clear: both;"></div>

        <?php wp_add_inline_script('chimpxpress-core', $js); ?>

        <br/>
        <br/>
        <label for="campaignName"><?php esc_html_e('campaign name', 'chimpxpress'); ?></label><br/>
        <input type="text" size="75" maxlength="100" name="campaignName" id="campaignName" class="inputWide"
               value="<?php echo (isset($_POST['campaignName'])) ? esc_attr(sanitize_text_field($_POST['campaignName'])) : ''; ?>"/>
        <br/>
        <br/>
        <label for="campaignSubject"><?php esc_html_e('subject line', 'chimpxpress'); ?></label><br/>
        <input type="text" size="75" maxlength="100" name="campaignSubject" id="campaignSubject" class="inputWide"
               value="<?php echo (isset($_POST['campaignSubject'])) ? esc_attr(sanitize_text_field($_POST['campaignSubject'])) : ''; ?>"/>
        <br/>
        <br/>
        <a class="button" id="next" href="javascript:void(0);"
           title="<?php esc_attr_e('next &raquo;', 'chimpxpress'); ?>"><?php esc_html_e('next &raquo;', 'chimpxpress'); ?></a>
        <a id="cancel" class="grey" href="javascript:void(0);"
           title="<?php esc_attr_e('cancel', 'chimpxpress'); ?>"><?php esc_html_e('cancel', 'chimpxpress'); ?></a>

        <input type="hidden" name="CX_default_from_name" id="CX_default_from_name"
               value="<?php echo (isset($_POST['CX_default_from_name'])) ? esc_attr(sanitize_text_field($_POST['CX_default_from_name'])) : ''; ?>"/>
        <input type="hidden" name="CX_default_from_email" id="CX_default_from_email"
               value="<?php echo (isset($_POST['CX_default_from_email'])) ? esc_attr(sanitize_email($_POST['CX_default_from_email'])) : ''; ?>"/>
        <input type="hidden" name="templateName" id="CX_templateName"
               value="<?php echo (isset($_POST['templateName'])) ? esc_attr(sanitize_text_field($_POST['templateName'])) : ''; ?>"/>
        <input type="hidden" name="sectionNames" id="sectionNames"
               value="<?php echo (isset($_POST['sectionNames'])) ? esc_attr(sanitize_text_field($_POST['sectionNames'])) : ''; ?>"/>
        <input type="hidden" name="skipSections" id="skipSections" value="<?php echo esc_attr(chimpxpress::sanitizePostData('skipSections', $skipSections)); ?>"/>
        <input type="hidden" name="editorContent" id="editorContent"
               value="<?php echo (isset($_POST['editorContent'])) ? esc_attr(str_replace('\"', '', wp_kses_post($_POST['editorContent']))) : ''; ?>"/>

        <input type="hidden" name="CX_campaignId" id="CX_campaignId"
               value="<?php echo (isset($_POST['CX_campaignId'])) ? esc_attr(chimpxpress::sanitizePostData('cid', $_POST['CX_campaignId'])) : '0'; ?>"/>
        <?php wp_nonce_field('chimpxpress-compose');?>

        <br/>
        <br/>
        <div id="reloadCache">
            <i>
                <?php esc_html_e('Note', 'chimpxpress'); ?>:
                <?php esc_html_e('Templates and lists are cached.', 'chimpxpress');?>
                <a href="javascript:void(0);" title="<?php esc_html_e('Reload cache', 'chimpxpress');?>"><?php esc_html_e('Reload cache', 'chimpxpress');?></a> <?php esc_html_e('if an expected entry does not appear in the list.', 'chimpxpress');?>
            </i>
        </div><?php
    }

    function chimpx_stepContent($step) {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'chimpxpress-compose')) {
            die('Invalid request!');
        }

        global $wpdb;

        $template = (isset($_POST['CX_template'])) ? esc_html(chimpxpress::sanitizePostData('templateId', $_POST['CX_template'])) : '';
        $sections = (isset($_POST['sections'])) ? esc_html(sanitize_text_field($_POST['sections'])) : '';
        $sectionNames = (isset($_POST['sectionNames'])) ? esc_html(sanitize_text_field($_POST['sectionNames'])) : '';
        $sectionsArray = explode('|###|', $sectionNames);
        $campaignName = (isset($_POST['campaignName'])) ? esc_html(sanitize_text_field($_POST['campaignName'])) : '';
        $campaignSubject = (isset($_POST['campaignSubject'])) ? esc_html(sanitize_text_field($_POST['campaignSubject'])) : '';
        ?>

        <h4 id="sectionTitleWrapper"><span
                    class="sectionTitle black"><?php echo esc_html($sectionsArray[($step - 2)]); ?></span> <span
                    class="grey"><?php esc_html_e('content section', 'chimpxpress'); ?></span></h4>

        <?php
        wp_enqueue_style('thickbox-css', includes_url() . 'js/thickbox/thickbox.css');

        wp_add_inline_script('chimpxpress-core', '
            var buttons = "";
            var sections = jQuery("#sections").val();
            chimpx_createSteps("' . esc_attr($template) . '", sections);

            function CX_insertContent(value) {
                if (value != "") {
                    newValue = decodeURIComponent((posts[value] + "").replace(/\+/g, "%20"));
                    tinymce.get(theEditorID).setContent(tinymce.get(theEditorID).getContent() + newValue);
                }
            }', 'after');
        wp_print_scripts('quicktags'); // REQUIRED!
        ?>
        <div id="poststuff" class="postarea">
            <?php
            if (isset($_POST['editorContent'])) {
                $editorContent = wp_kses_post($_POST['editorContent']);
                $content = explode('|###|', $editorContent);
                $content = str_replace(["\'", '\"'], "'", $content[$step - 2]);
                $content = str_replace('\\', '', $content);
                //$content = _wp_specialchars($content);
            } else {
                $editorContent = '';
                $content = '';
            }

            $editorId = uniqid('content_');

            $rows = get_option('default_post_edit_rows');
            $rows = (($rows < 3) || ($rows > 100)) ? 15 : $rows;

            wp_editor($content, $editorId, [
                'tab_index'     => '2',
                'teeny'         => true,
                'textarea_rows' => $rows,
                'media_buttons' => true,
                'tinymce'       => [
                    'plugins' => 'fullscreen, wordpress, wplink, wpdialogs'
                ]
            ]); ?>
        </div>
        <?php
        wp_add_inline_script('chimpxpress-core', 'var theEditorID = "' . esc_attr($editorId). '";', 'before');

        $posts = $wpdb->get_results("SELECT {$wpdb->posts}.* FROM {$wpdb->posts} WHERE {$wpdb->posts}.post_status = 'publish'
                AND {$wpdb->posts}.post_type = 'post' AND {$wpdb->posts}.post_date < NOW()
                ORDER BY {$wpdb->posts}.post_date DESC"); ?>

        <div id="insertPost">
            <?php esc_html_e('Insert content from blog post', 'chimpxpress'); ?>:
            <select id="insertPosts" onchange="CX_insertContent(this.value)">
                <option value=""><?php esc_html_e('-- select post --', 'chimpxpress'); ?></option>
                <?php
                $js = "var posts = {};\n";
                foreach ($posts as $p) {
                    echo '<option value="' . esc_attr($p->ID) . '" title="' . esc_attr(sanitize_title(substr($p->post_content, 0, 150))) . ' ...">' . esc_html(sanitize_title($p->post_title)) . '</option>';
                    $js .= "posts['" . esc_attr($p->ID) . "'] = '<h2>" . esc_attr(sanitize_title($p->post_title)) . "</h2>" . esc_attr(sanitize_title(str_replace("\n", '', $p->post_content))) . "';\n";
                } ?>
            </select>
            <?php wp_add_inline_script('chimpxpress-core', $js); ?>
        </div>

        <input type="hidden" name="CX_listId" id="CX_listId"
               value="<?php echo (isset($_POST['CX_listId'])) ? esc_attr(sanitize_text_field($_POST['CX_listId'])) : ''; ?>"/>
        <input type="hidden" name="CX_default_from_name" id="CX_default_from_name"
               value="<?php echo (isset($_POST['CX_default_from_name'])) ? esc_attr(sanitize_text_field($_POST['CX_default_from_name'])) : ''; ?>"/>
        <input type="hidden" name="CX_default_from_email" id="CX_default_from_email"
               value="<?php echo (isset($_POST['CX_default_from_email'])) ? esc_attr(sanitize_text_field($_POST['CX_default_from_email'])) : ''; ?>"/>
        <input type="hidden" name="CX_template" id="CX_template" value="<?php echo esc_attr($template); ?>"/>
        <input type="hidden" name="templateName" id="templateName"
               value="<?php echo (isset($_POST['templateName'])) ? esc_attr(sanitize_text_field($_POST['templateName'])) : ''; ?>"/>
        <input type="hidden" name="sections" id="sections" value="<?php echo esc_attr($sections); ?>"/>
        <input type="hidden" name="sectionNames" id="sectionNames" value="<?php echo esc_attr($sectionNames); ?>"/>
        <input type="hidden" name="skipSections" id="skipSections"
               value="<?php echo esc_attr(chimpxpress::sanitizePostData('skipSections', $_POST['skipSections'])); ?>"/>
        <input type="hidden" name="editorContent" id="editorContent"
               value="<?php echo esc_attr(str_replace('\"', '', $editorContent)); ?>"/>

        <input type="hidden" name="campaignName" id="campaignName" value="<?php echo esc_attr($campaignName); ?>"/>
        <input type="hidden" name="campaignSubject" id="campaignSubject" value="<?php echo esc_attr($campaignSubject); ?>"/>

        <input type="hidden" name="CX_campaignId" id="CX_campaignId"
               value="<?php echo (isset($_POST['CX_campaignId'])) ? esc_attr(chimpxpress::sanitizePostData('cid', $_POST['CX_campaignId'])) : '0'; ?>"/>
        <?php wp_nonce_field('chimpxpress-compose');?>

        <a class="button" id="next" href="javascript:void(0);"
           title="<?php esc_attr_e('next &raquo;', 'chimpxpress'); ?>"><?php esc_attr_e('next &raquo;', 'chimpxpress'); ?></a>
        <a id="cancel" class="grey" href="javascript:void(0);"
           title="<?php esc_attr_e('cancel', 'chimpxpress'); ?>"><?php esc_attr_e('cancel', 'chimpxpress'); ?></a>
        <?php

        do_action('admin_print_scripts');
    }

    function chimpx_stepSubmit() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'chimpxpress-compose')) {
            die('Invalid request!');
        }

        $MCAPI = new chimpxpressMCAPI();

        if (empty($_POST['campaignName']) || empty($_POST['campaignSubject'])) {
            $MCAPI->_addError(["error" => esc_html__('Campaign name and subject line must be supplied!', 'chimpxpress'), "code" => "-99"]);
            $MCAPI->showMessages();
            return;
        }

        $templateId = chimpxpress::sanitizePostData('templateId', $_POST['CX_template']);
        $title = html_entity_decode(sanitize_text_field($_POST['campaignName']));
        $subject = html_entity_decode(sanitize_text_field($_POST['campaignSubject']));

        $replace = ['&#039;', '&amp;'];
        $with = ["'", '&'];
        $title = str_replace($replace, $with, $title);
        $subject = str_replace($replace, $with, $subject);

        // create or update campaign
        if (empty($_POST['CX_campaignId'])) {
            $params = [
                'type'       => 'regular',
                'recipients' => [
                    'list_id' => chimpxpress::sanitizePostData('listId', $_POST['CX_listId'])
                ],
                'settings'   => [
                    'title'        => $title,
                    'subject_line' => $subject,
                    'template_id'  => $templateId,
                    'from_name'    => sanitize_text_field($_POST['CX_default_from_name']),
                    'inline_css'   => true
                ]
            ];
            $campaign = $MCAPI->campaigns($params, 'POST');
            $campaignId = chimpxpress::sanitizePostData('cid', $campaign['id'] ?? '');
        } else {
            $campaignId = chimpxpress::sanitizePostData('cid', $_POST['CX_campaignId']);
        }

        if (empty($campaignId)) {
            $MCAPI->_addError(["error" => esc_html__('Campaign could not be created!', 'chimpxpress'), "code" => "-99"]);
            $MCAPI->showMessages();
            return;
        }

        // set campaign content
        $sections = [];
        $sectionNames = explode('|###|', sanitize_text_field($_POST['sectionNames']));
        $editorContent = explode('|###|', wp_kses_post($_POST['editorContent']));
        for ($i = 0; $i < count($sectionNames); $i++) {
            if (!in_array(strtolower($sectionNames[$i]), ['header', 'footer'])
                /*&& strpos($sectionNames[$i], 'header_') !== 0
                && strpos($sectionNames[$i], 'footer_') !== 0*/
                && strpos($sectionNames[$i], 'repeat_') !== 0
            ) {
                $sections[$sectionNames[$i]] = trim(str_replace(['\\', '\"', "\'"], ['', '"', "'"], $editorContent[$i]));
            }
        }
        $params = [
            'template' => [
                'id'       => $templateId,
                'sections' => $sections
            ]
        ];
        $campaignContent = $MCAPI->campaignsContent($campaignId, $params, 'PUT');

        if (!isset($campaignContent['html'])) {
            $MCAPI->_addError(["error" => esc_html__('Campaign could not be created!', 'chimpxpress'), "code" => "-99"]);
            $MCAPI->showMessages();
            return;
        }

        $iframeUrl = false;
        if ($campaignContent) {
            // create preview file
            global $wp_filesystem;
            $uploadDir = chimpxpress::getUploadDir();

            if ($wp_filesystem->method == 'direct') {
                // remove tmp folder
                if (is_dir($uploadDir['absPath']) . DS . 'tmp') {
                    $wp_filesystem->delete($uploadDir['absPath'] . DS . 'tmp', true);
                }

                // create new (empty) tmp folder
                $wp_filesystem->mkdir($uploadDir['absPath'] . DS . 'tmp');

                // disallow access to other files than the preview
                $wp_filesystem->put_contents($uploadDir['absPath'] . DS . 'tmp' . DS . '.htaccess', "# disallow access to any files\nOrder Allow,Deny\nDeny from all\n\n<Files ~ \"^" . sanitize_file_name($_POST['campaignSubject']) . "\.([Hh][Tt][Mm][Ll])\">\nAllow from all\n</Files>");

                // write preview file
                $wp_filesystem->put_contents($uploadDir['absPath'] . DS . 'tmp' . DS . sanitize_file_name($_POST['campaignSubject']) . '.html', $campaignContent['html']);

            } else {
                $chimpxpress = new chimpxpress();
                $ftpstream = ftp_connect(sanitize_text_field($chimpxpress->settings['ftpHost']));
                ftp_login($ftpstream, sanitize_text_field($chimpxpress->settings['ftpUser']), $chimpxpress->settings['ftpPasswd']);
                ftp_chdir($ftpstream, wp_sanitize_redirect($chimpxpress->settings['ftpPath']));

                // remove tmp folder
                if (is_dir($uploadDir['absPath'] . DS . 'tmp')) {
                    chimpxpress::ftp_delAll($ftpstream, $uploadDir['relPath'] . DS . 'tmp');
                }

                // create new (empty) tmp folder
                ftp_mkdir($ftpstream, $uploadDir['relPath'] . DS . 'tmp');
                ftp_chdir($ftpstream, $uploadDir['relPath'] . DS . 'tmp');

                // disallow access to other files than the preview
                $temp = tmpfile();
                $wp_filesystem->put_contents($temp, "# disallow access to any files\nOrder Allow,Deny\nDeny from all\n\n<Files ~ \"^" . sanitize_file_name($_POST['campaignSubject']) . "\.([Hh][Tt][Mm][Ll])\">\nAllow from all\n</Files>");
                rewind($temp);
                ftp_fput($ftpstream, '.htaccess', $temp, FTP_ASCII);

                // write preview file
                $temp = tmpfile();
                $wp_filesystem->put_contents($temp, $campaignContent['html']);
                rewind($temp);
                ftp_fput($ftpstream, sanitize_file_name($_POST['campaignSubject']) . '.html', $temp, FTP_ASCII);

                ftp_close($ftpstream);
            }

            $iframeUrl = sanitize_url($uploadDir['url'] . '/tmp/' . sanitize_file_name($_POST['campaignSubject']) . '.html');
        }

        $dc = $MCAPI->getDc();

        wp_add_inline_script('chimpxpress-core', '
        jQuery(document).ready(function($) {
                buttons = "";
                sections = jQuery("#sections").val();
                chimpx_createSteps("' . $templateId . '", sections);
            });
        ');?>
        <?php /*jQuery(document).ready(function($) {
                if( jQuery('#chimpxpressCompose').width() > 1175 ){
                    jQuery("#monkeyhead").addClass("scream");
                }
                $(window).resize(function() {
                    if( jQuery('#chimpxpressCompose').width() > 1175 ){
                        jQuery("#monkeyhead").addClass("scream");
                    } else {
                        jQuery("#monkeyhead").removeClass("scream");
                    }
                });
            });*/ ?>
    <?php if ($iframeUrl) { ?>
        <div class="notice notice-success">
            <span class="notice-title">
                <?php esc_html_e('High fives! Your campaign was created in Mailchimp and is ready to send.', 'chimpxpress'); ?>
            </span>
        </div>

        <h4 style="color: #464646;margin:0 0 1em;">
            <?php esc_html_e('What do I do now?', 'chimpxpress'); ?>
        </h4>

        <ol>
            <li><a href="https://<?php echo esc_attr($dc); ?>.admin.mailchimp.com/campaigns/" target="_blank"
                   title="<?php esc_attr_e('login to Mailchimp', 'chimpxpress'); ?>"><?php esc_html_e('login to Mailchimp', 'chimpxpress'); ?></a>
            </li>
            <li><?php
                // translators: %s placeholder represents the campaign name
                echo esc_html(sprintf(__('open the campaign "%s" and click "send now"', 'chimpxpress'), sanitize_text_field($_POST['campaignName']))); ?>
            </li>
        </ol>

        <a class="button button-primary next" id="gotoMailchimp" href="http://<?php echo esc_attr($dc); ?>.admin.mailchimp.com/campaigns/"
           target="_blank"
           title="<?php esc_attr_e('open Mailchimp', 'chimpxpress'); ?>"><?php esc_html_e('open Mailchimp', 'chimpxpress'); ?></a>
        <a id="cancelCompose" class="button button-link grey" href="javascript:void(0);"
           title="<?php esc_attr_e('cancel (and remove draft from Mailchimp)', 'chimpxpress'); ?>"><?php esc_html_e('cancel (and remove draft from Mailchimp)', 'chimpxpress'); ?></a>

        <h4 style="margin: 3em 0 1em 0;">
            <?php esc_html_e('Preview', 'chimpxpress'); ?>
        </h4>

        <?php /*<div id="monkey-ruler">
            <p>
                <strong id="monkeyhead">Note: </strong><?php esc_html_e("Your email shouldn't be much more than 600 pixels wide.", 'chimpxpress');?>
            </p>
        </div>*/ ?>
        <iframe id="CX_previewIframe" src="<?php echo esc_attr($iframeUrl);?>"></iframe><?php
    } ?>
        <input type="hidden" name="CX_listId" id="CX_listId"
               value="<?php echo (isset($_POST['CX_listId'])) ? esc_attr(chimpxpress::sanitizePostData('listId', $_POST['CX_listId'])) : ''; ?>" />
        <input type="hidden" name="default_from_name" id="default_from_name"
               value="<?php echo (isset($_POST['default_from_name'])) ? esc_attr(sanitize_text_field($_POST['default_from_name'])) : ''; ?>" />
        <input type="hidden" name="default_from_email" id="default_from_email"
               value="<?php echo (isset($_POST['default_from_email'])) ? esc_attr(sanitize_email($_POST['default_from_email'])) : ''; ?>" />
        <input type="hidden" name="CX_template" id="CX_template"
               value="<?php echo (isset($_POST['CX_template'])) ? esc_attr(chimpxpress::sanitizePostData('templateId', $_POST['CX_template'])) : ''; ?>" />
        <input type="hidden" name="templateName" id="templateName"
               value="<?php echo (isset($_POST['templateName'])) ? esc_attr(sanitize_text_field($_POST['templateName'])) : ''; ?>" />
        <input type="hidden" name="sections" id="sections"
               value="<?php echo (isset($_POST['sections'])) ? esc_attr(sanitize_text_field($_POST['sections'])) : ''; ?>" />
        <input type="hidden" name="sectionNames" id="sectionNames"
               value="<?php echo (isset($_POST['sectionNames'])) ? esc_attr(sanitize_text_field($_POST['sectionNames'])) : ''; ?>" />
        <input type="hidden" name="skipSections" id="skipSections"
               value="<?php echo esc_attr(chimpxpress::sanitizePostData('skipSections', $_POST['skipSections'])); ?>" />
        <input type="hidden" name="campaignName" id="campaignName"
               value="<?php echo (isset($_POST['campaignName'])) ? esc_attr(sanitize_text_field($_POST['campaignName'])) : ''; ?>" />
        <input type="hidden" name="campaignSubject" id="campaignSubject"
               value="<?php echo (isset($_POST['campaignSubject'])) ? esc_attr(sanitize_text_field($_POST['campaignSubject'])) : ''; ?>" />
        <input type="hidden" name="editorContent" id="editorContent"
               value="<?php echo (isset($_POST['editorContent'])) ? esc_attr(str_replace('\"', '', wp_kses_post($_POST['editorContent']))) : ''; ?>" />
        <input type="hidden" name="CX_campaignId" id="CX_campaignId" value="<?php echo esc_attr($campaignId); ?>" />
        <?php
        wp_nonce_field('chimpxpress-compose');
    }

    include(CHIMPX_PLUGIN_DIR . 'footer.php'); ?>
</div>
<?php
$MCAPI->showMessages();
