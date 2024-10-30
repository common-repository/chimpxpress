<?php
/**
 * Plugin Name: chimpXpress
 * Plugin URI: https://chimpxpress.com
 * Description: WordPress Mailchimp Integration - Create Mailchimp campaign drafts from within WordPress and include blog posts or import recent campaigns into WordPress to create blog posts or landing pages. Requires PHP7.4. If you're having trouble with the plugin visit our forums https://chimpxpress.com/support Thank you!
 * Version: 2.0.0
 * Requires at least: 3.3
 * Requires PHP: 7.4
 * Author: freakedout
 * Author URI: https://www.freakedout.de
 * License: GPL v2
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: chimpxpress
 * Domain Path: /languages
 *
 * Copyright (C) 2015-2024  freakedout (www.freakedout.de)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 **/

// no direct access
defined('ABSPATH') or die('Restricted Access');

defined('DS') or define('DS', DIRECTORY_SEPARATOR);
define('CHIMPX_PLUGIN_DIR', plugin_dir_path(__FILE__));

if (!is_admin()) {
    return;
}

class chimpxpress {

    public $settings;
    private $_errors = [];
    private $_notices = [];
    static $instance = false;

    public static $pluginSlug = 'chimpxpress';

    private $optionsName = 'chimpxpress';
    private $optionsGroup = 'chimpxpress-options';

    private $_listener_query_var = 'chimpxpressListener';

    private $MCAPI = false;


    function __construct() {
        require_once(CHIMPX_PLUGIN_DIR . 'class-MCAPI.php');
        if (!$this->MCAPI) {
            $this->MCAPI = new chimpxpressMCAPI;
            if (!isset($_SESSION['MCping']) || !$_SESSION['MCping']) {
                $ping = $this->MCAPI->ping();
                $_SESSION['MCping'] = sanitize_text_field($ping);
                if ($ping) {
                    $MCname = $this->MCAPI->getAccountDetails();
                    $_SESSION['MCusername'] = sanitize_text_field($MCname['username'] ?? '-');
                }
            }
        }

        $this->getSettings();

        // include cache library
        require_once(CHIMPX_PLUGIN_DIR . 'class-JG_Cache.php');

        // include WP filesystem
        if (!function_exists('WP_Filesystem')) {
            require(WP_PLUGIN_DIR . DS . '..' . DS . '..' . DS . 'wp-admin' . DS . 'includes' . DS . 'file.php');
            WP_Filesystem();
        }
        global $wp_filesystem;

        /**
         * Add filters and actions
         */
        add_filter('init', [$this, 'chimpxpressLoadLanguage']);
        add_action('admin_init', [$this, 'registerOptions']);
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_menu', [$this, 'chimpxpress_add_box']);
        //add_action('template_redirect', [$this, 'listener']);
        //add_filter( 'query_vars', array( $this, 'addMailchimpListenerVar' ));
        register_activation_hook(__FILE__, [$this, 'activatePlugin']);
        add_filter('pre_update_option_' . $this->optionsName, [$this, 'optionUpdate'], null, 2);
        //	add_action( 'admin_notices', array($this->MCAPI, 'showMessages') );


        // compose ajax callbacks
        add_action('wp_ajax_compose_clear_cache', [$this, 'compose_clear_cache_callback']);
        add_action('wp_ajax_compose_gotoStep', [$this, 'compose_gotoStep_callback']);
        add_action('wp_ajax_compose_removeDraft', [$this, 'compose_removeDraft_callback']);
        add_action('wp_ajax_validate_campaign_name_subject', [$this, 'validate_campaign_name_subject_callback']);
        // import ajax callbacks
        add_action('wp_ajax_import', [$this, 'import_callback']);
        // archive ajax callbacks
        add_action('wp_ajax_archive_deleteLP', [$this, 'archive_deleteLP_callback']);
        // archive post box callback
        add_action('wp_ajax_load_campaigns', [$this, 'load_campaigns_callback']);

        add_action('wp_ajax_ftp_find_root', [$this, 'ftp_find_root']);
        add_action('wp_ajax_ftp_test', [$this, 'ftp_test_callback']);
    }

    public function optionUpdate($newvalue, $oldvalue) {
        // clear error messages
        $this->MCAPI->_emptyErrors();
        $this->MCAPI->_emptyNotices();

        $this->clearCache();

        return $newvalue;
    }

    public function clearCache() {
        // clear cache if present
        $cacheDir = self::getUploadDir()['absPath'] . DS . 'cache';
        if (is_dir($cacheDir)) {
            $cache = new chimpxpressJG_Cache($cacheDir);
            $templates = $cache->get('templates');
            $lists = $cache->get('lists');
            if ($templates || $lists) {
                $this->compose_clear_cache_callback();
            }
        }
    }

    public function activatePlugin() {
        $this->updateSettings();
    }

    public function getSetting($settingName, $default = false) {
        if (empty($this->settings)) {
            $this->getSettings();
        }
        if (isset($this->settings[$settingName])) {
            return $this->settings[$settingName];
        } else {
            return $default;
        }
    }

    public function getSettings() {
        if (empty($this->settings)) {
            $this->settings = get_option($this->optionsName);
        }
        if (!is_array($this->settings)) {
            $this->settings = [];
        }

        $defaults = [
            'username'              => '',
            'password'              => '',
            'apiKey'                => '',
            'CEaccess'              => 'manage_options',
            'debugging'             => 'off',
            'debugging_email'       => '',
            'listener_security_key' => $this->generateSecurityKey(),
            'version'               => $this->MCAPI->apiVersion,
            'GAprofile'             => '',
            'ftpHost'               => '',
            'ftpUser'               => '',
            'ftpPasswd'             => '',
            'ftpPath'               => ''
        ];
        $this->settings = wp_parse_args($this->settings, $defaults);
    }

    private function generateSecurityKey() {
        return sha1(time());
    }

    private function updateSettings() {
        update_option($this->optionsName, $this->settings);
    }

    public function registerOptions() {
        register_setting($this->optionsGroup, $this->optionsName);
    }

    public function adminMenu() {
        $pages = [];
        $pages[] = add_menu_page(
            esc_html__('Dashboard', 'chimpxpress'),
            'chimpXpress',
            $this->settings['CEaccess'],
            'chimpXpressDashboard',
            [$this, 'main'],
            plugins_url('images' . DS . 'logo_16.png', __FILE__)
        );

        $pages[] = add_submenu_page(
            'chimpXpressDashboard',
            esc_html__('Import', 'chimpxpress'),
            esc_html__('Import', 'chimpxpress'),
            $this->settings['CEaccess'],
            'chimpXpressImport',
            [$this, 'import'],
            null
        );
        $pages[] = add_submenu_page(
            'chimpXpressDashboard',
            esc_html__('Compose', 'chimpxpress'),
            esc_html__('Compose', 'chimpxpress'),
            $this->settings['CEaccess'],
            'chimpXpressCompose',
            [$this, 'compose'],
            null
        );
        $pages[] = add_submenu_page(
            'chimpXpressDashboard',
            esc_html__('Landing Page Archive', 'chimpxpress'),
            esc_html__('Landing Pages', 'chimpxpress'),
            $this->settings['CEaccess'],
            'chimpXpressArchive',
            [$this, 'archive'],
            null
        );
        // invisible menus
        $pages[] = add_submenu_page(
            'chimpXpressArchive',
            esc_html__('Edit Landing Page', 'chimpxpress'),
            esc_html__('Edit Landing Page', 'chimpxpress'),
            $this->settings['CEaccess'],
            'chimpXpressEditLandingPage',
            [$this, 'editLP'],
            null
        );

        $pages[] = add_options_page(
            esc_html__('Settings', 'chimpxpress'),
            'chimpXpress',
            'manage_options',
            'chimpXpressConfig',
            [$this, 'options'],
            null
        );

        // enqueue css and js files
        foreach ($pages as $page) {
            add_action('admin_print_styles-' . $page, [$this, 'chimpxpressAddAdminHead']);
        }
    }

    function chimpxpressAddAdminHead() {
        // add css files
        wp_enqueue_style('chimpxpress', plugins_url('css' . DS . 'chimpxpress.css', __FILE__));
    }


    function compose_clear_cache_callback() {
        global $wp_filesystem;
        if ($wp_filesystem->method == 'direct') {
            $wp_filesystem->delete(self::getUploadDir()['absPath'] . DS . 'cache', true);
        } else {
            $ftpstream = ftp_connect(sanitize_text_field($this->settings['ftpHost']));
            ftp_login($ftpstream, sanitize_text_field($this->settings['ftpUser']), $this->settings['ftpPasswd']);
            ftp_chdir($ftpstream, wp_sanitize_redirect($this->settings['ftpPath']));
            $this->ftp_delAll($ftpstream, self::getUploadDir()['relPath'] . DS . 'cache');
            ftp_close($ftpstream);
        }

        return;
    }

    public function compose_gotoStep_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-compose')) {
            die('Invalid request!');
        }

        include(CHIMPX_PLUGIN_DIR . 'compose.php');
        exit;
    }

    public function compose_removeDraft_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-compose')) {
            die('Invalid request!');
        }

        // get campaign ID and remove anything except lower case letters and numbers
        $cid = $this->sanitizePostData('cid', $_POST['cid']);

        $this->MCAPI->campaigns(['campaign_id' => $cid], 'DELETE');

        if (is_dir(CHIMPX_PLUGIN_DIR . 'tmp')) {
            global $wp_filesystem;
            if ($wp_filesystem->method == 'direct') {
                $wp_filesystem->delete(CHIMPX_PLUGIN_DIR . 'tmp', true);
            } else {
                $ftpstream = ftp_connect(sanitize_text_field($this->settings['ftpHost']));
                ftp_login($ftpstream, sanitize_text_field($this->settings['ftpUser']), $this->settings['ftpPasswd']);
                ftp_chdir($ftpstream, wp_sanitize_redirect($this->settings['ftpPath']));
                $this->ftp_delAll($ftpstream, basename(WP_CONTENT_DIR) . DS . 'plugins' . DS . basename(CHIMPX_PLUGIN_DIR) . DS . 'tmp');
                ftp_close($ftpstream);
            }
        }
        exit;
    }

    public function validate_campaign_name_subject_callback() {
        header('Content-type: application/json');

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-compose')) {
            echo wp_json_encode(['isValid' => false]);
            exit;
        }

        if (empty(sanitize_title($_POST['campaignName'] ?? ''))) {
            echo wp_json_encode(['isValid' => false]);
            exit;
        }
        if (empty(sanitize_title($_POST['subject'] ?? ''))) {
            echo wp_json_encode(['isValid' => false]);
            exit;
        }
        echo wp_json_encode(['isValid' => true]);
        exit;
    }

    public static function getUploadDir() {
        $uploads = wp_upload_dir();

        $res = [
            'absPath' => $uploads['basedir'] . DS . self::$pluginSlug,
            'relPath' => str_replace(ABSPATH, '', $uploads['basedir'] . DS . self::$pluginSlug),
            'url'     => $uploads['baseurl'] . '/' . self::$pluginSlug
        ];

        // create chimpxpress directory if it doesn't exist
        global $wp_filesystem;
        if ($wp_filesystem && !file_exists($res['absPath'])) {
            $wp_filesystem->mkdir($res['absPath'], 0755);

            // disallow direct file access
            $wp_filesystem->put_contents($res['absPath'] . DS . '.htaccess', "# disallow access to any files\nOrder Allow,Deny\nDeny from all");
        }

        return $res;
    }

    public static function sanitizePostData($key, $value) {
        switch ($key) {
            // campaign ID
            case 'campaignID':
            case 'cid':
            case 'listId':
                // remove anything except lower case letters and numbers
                return preg_replace('/[^a-z0-9]/', '', $value);
            case 'templateId':
                return intval($value);
            case 'skipSections':
                // remove anything except numbers and comma
                return preg_replace('/[^,0-9]/', '', $value);
            default:
                return sanitize_text_field($value);
        }
    }

    function import_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-import')) {
            header('Content-type: application/json');
            $result = [
                'error' => 1,
                'msg'   => '<span style="color: #ff0000;">' . esc_html__('Invalid request!', 'chimpxpress') . '</span>'
            ];
            echo wp_json_encode($result);
            exit;
        }

        global $wpdb;

        $currentUser = wp_get_current_user();

        $type = $_POST['type'] == 'post' ? 'post' : 'page';
        $dataType = $_POST['datatype'] == 'html' ? 'html' : 'text';
        $cid = $this->sanitizePostData('cid', $_POST['cid']);
        $subject = sanitize_title(html_entity_decode($_POST['subject']));
        $fileName = sanitize_file_name(html_entity_decode($_POST['fileName']));

        // get next post/page id
        $tableStatus = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS LIKE %s", $wpdb->posts));
        $nextIncrement = $tableStatus->Auto_increment;

        if ($type == 'post') {
            // create permalink
            $guid = get_option('home') . '/?p=' . $nextIncrement;
            //	var_dump($campaignContent['html']);die;
            if ($dataType == 'html') {
                // get campaign contents
                $campaign = $this->MCAPI->campaignsContent($cid);

                if (empty($campaign) || empty($campaign['html'])) {
                    header('Content-type: application/json');
                    $result = [
                        'error' => 1,
                        'msg' => '<span style="color: #ff0000;">' . esc_html__('Campaign could not be retrieved. Please try again.', 'chimpxpress') . '</span>'
                    ];
                    echo wp_json_encode($result);
                    exit;
                }

                // process html contents
                $html = $campaign['html'];
                preg_match('#<s[t]yle(.*)>.*</style>#is', $html, $styles);
                $html = preg_replace('/<!DOCTYPE[^>]*?>/is', '', $html);
                $html = preg_replace('!<head>(.*)</head>!is', '', $html);
                $html = preg_replace('!<body[^>]*>!is', '', $html);
                $html = preg_replace('!</body>!is', '', $html);
                $html = str_replace(['<html>', '</html>'], '', $html);

                $style = '';
                for ($i = 0; $i < count($styles); $i++) {
                    $style .= $styles[$i];
                }

                // move style declarations inline
                require_once(CHIMPX_PLUGIN_DIR . 'class-css_to_inline_styles.php');
                $CSSToInlineStyles = new chimpx_CSSToInlineStyles();
                $CSSToInlineStyles->setHTML($html);
                $CSSToInlineStyles->setCSS($style);
                $html = $CSSToInlineStyles->convert();

                $html = preg_replace('/<!DOCTYPE[^>]*?>/is', '', $html);
                $html = preg_replace('!<head>(.*)</head>!is', '', $html);
                $html = preg_replace('!<body[^>]*>!is', '', $html);
                $html = preg_replace('!</body>!is', '', $html);
                $html = preg_replace('!<s[t]yle(.*)>(.*?)</style>!is', '', $html);
                $html = str_replace(['<html>', '</html>'], '', $html);

                // remove MERGE tags
                // anchors containing a merge tag
                $html = preg_replace('!<a(.*)(\*(\||%7C)(.*)(\||%7C)\*)(.*)</a>!', '', $html);
                // all other merge tags
                $html = preg_replace('!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);
            } else {
                // get campaign contents
                $campaign = $this->MCAPI->campaignsContent($cid);

                if (empty($campaign) || empty($campaign['plain_text'])) {
                    $result['error'] = 1;
                    $result['msg'] = '<span style="color: #ff0000;">' . esc_html__('Campaign could not be retrieved. Please try again.', 'chimpxpress') . '</span>';
                    header("Content-type: application/json");
                    echo wp_json_encode($result);
                    exit;
                }

                // process html contents
                $html = $campaign['plain_text'];
                // convert links to html anchors
                $html = preg_replace('!(http://(.*)(<|\s))!isU', '<a href="$1">$1</a>', $html);
                // remove MERGE tags
                $html = preg_replace('!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);
            }


            /*
            $campaignTemplateContent = $this->MCAPI->campaignTemplateContent( $cid );
            if($campaignTemplateContent){
                $html = $campaignTemplateContent['main'];
                // append sidecolumn content if exists
                if( isset($campaignTemplateContent['sidecolumn']) && $campaignTemplateContent['sidecolumn'] != '' ){
                    $html .= '<br />'.$campaignTemplateContent['sidecolumn'];
                }
            } else {
                // clear errors (we dont need to be notified that this campaign doesn't use a template)
                $this->MCAPI->_emptyErrors();
                // campaign didn't use a template so we have to use the text version
                $html = $this->MCAPI->generateText( 'cid', $cid );
                // convert links to html anchors
                $html = preg_replace( '!(http://(.*)(<|\s))!isU', '<a href="$1">$1</a>', $html);

                // remove MERGE tags
                // sentences containing a merge tag
                $html = preg_replace( '!\.\s(.*)\*\|(.*)\|\*(.*)\.!sU', '.', $html);
                $html = preg_replace( '!\.\s(.*)\*%7C(.*)%7C\*(.*)\.!sU', '.', $html);

                $html = preg_replace( '!>(.*)\*\|(.*)\|\*(.*)\.!sU', '>', $html);
                $html = preg_replace( '!>(.*)\*%7C(.*)%7C\*(.*)\.!sU', '>', $html);
                // anchors containing a merge tag
                $html = preg_replace( '!<a(.*)\*\|(.*)\|\*(.*)(</a>)?!isU', '', $html);
                $html = preg_replace( '!<a(.*)(\*%7C)(.*)(%7C\*)(</a>)?!isU', '', $html);
                // all other merge tags
                $html = preg_replace( '!\*\|(.*)\|\*!isU', '', $html);
                $html = preg_replace( '!(\*%7C)(.*)(%7C\*)!isU', '', $html);
            }
            */

            $now = date('Y-m-d H:i:s');
            $now_gmt = gmdate('Y-m-d H:i:s');

            $data = [
                'post_author'           => $currentUser->ID,
                'post_date'             => $now,
                'post_date_gmt'         => $now_gmt,
                'post_content'          => $html,
                'post_excerpt'          => '',
                'post_status'           => 'draft',
                'post_title'            => sanitize_title_for_query($subject),
                'post_type'             => $type,
                'post_name'             => sanitize_title($subject),
                'post_modified'         => $now,
                'post_modified_gmt'     => $now_gmt,
                'guid'                  => $guid,
                'comment_count'         => 0,
                'to_ping'               => '',
                'pinged'                => '',
                'post_content_filtered' => ''
            ];
            $wpdb->insert($wpdb->posts, $data);

            echo esc_attr($nextIncrement);
            exit;

        } else {
            // create landing page

            // throw error if page title is empty or consists only of special characters
            $safeSubject = sanitize_file_name($fileName);
            if ($safeSubject == '') {
                $result['error'] = 1;
                $result['msg'] = '<span style="color: #ff0000;">' . esc_html__('Page title must not be empty or consist exclusively of special characters!', 'chimpxpress') . '</span>';
                header("Content-type: application/json");
                echo wp_json_encode($result);
                exit;
            }

            if (!intval($_POST['force']) && file_exists(ABSPATH . 'archive' . DS . $safeSubject . '.html')) {
                $result['error'] = 1;
                $result['msg'] = '<span style="color: #ff0000;">' . esc_html__('A landing page with the supplied name already exists!', 'chimpxpress') . '<br /><a href="javascript:jQuery(\'#force\').val(1);jQuery(\'#next\').trigger(\'click\');void(0)">' . esc_html__('Click here to overwrite the existing landing page', 'chimpxpress') . '</a></span>';
                header("Content-type: application/json");
                echo wp_json_encode($result);
                exit;
            }

            // get campaign content
            $campaign = $this->MCAPI->campaignsContent($cid);

            if (empty($campaign) || empty($campaign['html'])) {
                $result['error'] = 1;
                $result['msg'] = '<span style="color: #ff0000;">' . esc_html__('Campaign could not be retrieved. Please try again.', 'chimpxpress') . '</span>';
                header("Content-type: application/json");
                echo wp_json_encode($result);
                exit;
            }

            $html = $campaign['html'];

            // set page title
            if (!preg_match('!<title>(.*)</title>!i', $html)) {
                $html = str_replace('</head>', "<title>" . $subject . "</title>\n</head>", $html);
            } else {
                $html = preg_replace('!<title>(.*)</title>!i', '<title>' . $subject . '</title>', $html);
            }

            // insert google analytics
            if ($this->settings['GAprofile']) {
                $script = "\n<script type=\"text/javascript\">\n" .
                    "var _gaq = _gaq || [];\n" .
                    "_gaq.push(['_setAccount', '" . sanitize_text_field($this->settings['GAprofile']) . "']);\n" .
                    "_gaq.push(['_trackPageview']);\n" .
                    "(function() {\n" .
                    "var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;\n" .
                    "ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';\n" .
                    "var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);\n" .
                    "})();\n" .
                    "</script>";
                $html = str_replace('</head>', $script . "\n</head>", $html);
            }

            // remove MERGE tags
            // sentences containing a merge tag
            //	$html = preg_replace( '!\.\s(.*)\*(\||%7C)(.*)(\||%7C)\*(.*)\.!sU', '###', $html);
            //	$html = preg_replace( '!>(.*)\*(\||%7C)(.*)(\||%7C)\*(.*)\.!sU', '>', $html);
            // anchors containing a merge tag
            $html = preg_replace('!<a(.*)(\*(\||%7C)(.*)(\||%7C)\*)(.*)</a>!', '', $html);
            // all other merge tags
            $html = preg_replace('!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);

            // create html file
            $archiveDirAbs = ABSPATH . 'archive/';
            $archiveDirRel = get_option('home') . '/archive/';

            global $wp_filesystem;
            if ($wp_filesystem->method == 'direct') {
                if (!is_dir($archiveDirAbs)) {
                    $wp_filesystem->mkdir(ABSPATH . 'archive');
                }
                $wp_filesystem->put_contents(ABSPATH . 'archive' . DS . $safeSubject . '.html', $html);
            } else {
                $ftpstream = ftp_connect(sanitize_text_field($this->settings['ftpHost']));
                ftp_login($ftpstream, sanitize_text_field($this->settings['ftpUser']), $this->settings['ftpPasswd']);
                ftp_chdir($ftpstream, wp_sanitize_redirect($this->settings['ftpPath']));

                // create archive directory if it doesn't exist
                if (!is_dir($archiveDirAbs)) {
                    ftp_mkdir($ftpstream, 'archive');
                }

                // write landing page html file
                $temp = tmpfile();
                $wp_filesystem->put_contents($temp, $html);
                rewind($temp);

                ftp_fput($ftpstream, 'archive' . DS . $safeSubject . '.html', $temp, FTP_ASCII);
                ftp_close($ftpstream);
            }

            $fileName = $archiveDirRel . $safeSubject . '.html';
            echo esc_attr($fileName);
            exit;
        }

        echo esc_attr($nextIncrement);
        exit;
    }

    function load_campaigns_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-load-campaigns')) {
            die('Invalid request!');
        }

        $page = (is_numeric($_POST['nextPage'])) ? intval($_POST['nextPage']) : 1;

        require_once(CHIMPX_PLUGIN_DIR . 'class-MCAPI.php');
        $MCAPI = new chimpxpressMCAPI;
        $campaigns = $MCAPI->campaigns(['status' => 'sent']);

        $result = [];
        if (isset($campaigns['campaigns'][0])) {
            $result['again'] = 1;
            $result['page'] = $page + 1;
            $result['html'] = '';
            $i = 0;
            foreach ($campaigns['campaigns'] as $c) {
                $result['html'] .= '<li><a title="' . esc_html__('open campaign in popup window', 'chimpxpress') . '" href="javascript:window.open(\'' . $c['archive_url'] . '\',\'preview\',\'status=0,toolbar=0,scrollbars=1,resizable=1,location=0,menubar=0,directories=0,width=800,height=600\');void(0)">' . $c['settings']['title'] . ' (' . $c['settings']['subject_line'] . ")</a></li>\n";
                $i++;
            }
            if ($i < 10) {
                $result['again'] = 0;
            }
        } else {
            $result['again'] = 0;
        }

        header('Content-type: application/json');
        echo wp_json_encode($result);
        exit;
    }

    function archive_deleteLP_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsName . '-archive')) {
            die('Invalid request!');
        }

        global $wp_filesystem;
        if ($wp_filesystem->method != 'direct') {
            $ftpstream = ftp_connect(sanitize_text_field($this->settings['ftpHost']));
            ftp_login($ftpstream, sanitize_text_field($this->settings['ftpUser']), $this->settings['ftpPasswd']);
            ftp_chdir($ftpstream, wp_sanitize_redirect($this->settings['ftpPath']));
        }

        $filenames = array_map('sanitize_file_name', $_POST['filenames']);

        foreach ($filenames as $name) {
            $name = sanitize_file_name($name);
            if ($wp_filesystem->method == 'direct') {
                $wp_filesystem->delete(ABSPATH . 'archive' . DS . $name);
            } else {
                $this->ftp_delAll($ftpstream, 'archive' . DS . $name);
            }
        }

        if ($wp_filesystem->method != 'direct') {
            ftp_close($ftpstream);
        }
    }

    function rrmdir($dir) {
        global $wp_filesystem;
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir") {
                        $this->rrmdir($dir . "/" . $object);
                    } else {
                        wp_delete_file($dir . "/" . $object);
                    }
                }
            }
            reset($objects);

            $wp_filesystem->rmdir($dir);
        }
    }

    function ftp_delAll($ftpstream, $dst_dir) {
        $ar_files = ftp_nlist($ftpstream, $dst_dir);

        if (is_array($ar_files)) { // makes sure there are files
            for ($i = 0; $i < sizeof($ar_files); $i++) { // for each file
                $st_file = basename($ar_files[$i]);
                if ($st_file == '.' || $st_file == '..') {
                    continue;
                }
                if (ftp_size($ftpstream, $dst_dir . '/' . $st_file) == -1) { // check if it is a directory
                    ftp_delAll($ftpstream, $dst_dir . '/' . $st_file); // if so, use recursion
                } else {
                    ftp_delete($ftpstream, $dst_dir . '/' . $st_file); // if not, delete the file
                }
            }
            sleep(1);
        }
        $flag = ftp_rmdir($ftpstream, $dst_dir); // delete empty directories

        return $flag;
    }

    public function ftp_find_root() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsGroup . '-options')) {
            die('Invalid request!');
        }

        if (empty($_POST['ftpHost']) || empty($_POST['ftpUser']) || empty($_POST['ftpPasswd'])) {
            esc_html_e('Invalid ftp credentials!', 'chimpxpress');
            exit;
        }
        try {
            $ftpStream = ftp_connect(sanitize_text_field($_POST['ftpHost']));
            if (!$ftpStream) {
                esc_html_e('Invalid ftp credentials!', 'chimpxpress');
                exit;
            }

            $ftplogin = ftp_login($ftpStream, sanitize_text_field($_POST['ftpUser']), $_POST['ftpPasswd']);
            if (!$ftplogin) {
                esc_html_e('Invalid ftp credentials!', 'chimpxpress');
                ftp_close($ftpStream);
                exit;
            }

            $paths = explode(DS, ABSPATH);
            $paths = array_filter($paths);

            for ($i = 0; $i < count($paths); $i++) {
                if (ftp_chdir($ftpStream, $paths[$i])) {
                    break;
                }
            }
            for ($x = $i; $x <= count($paths); $x++) {
                ftp_chdir($ftpStream, $paths[$x]);
            }
            echo esc_attr(ftp_pwd($ftpStream));

            ftp_close($ftpStream);
            exit;
        } catch (Throwable $e) {
            echo esc_html($e->getMessage());
            die;
        }
    }

    function ftp_test_callback() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), $this->optionsGroup . '-options')) {
            die('Invalid request!');
        }

        $ftpStream = ftp_connect(sanitize_text_field($_POST['ftpHost']));
        if (!$ftpStream) {
            echo '<span style="color: red;">' . esc_html__('Invalid FTP host!', 'chimpxpress') . '</span>';
            exit;
        }

        $ftpLogin = ftp_login($ftpStream, sanitize_text_field($_POST['ftpUser']), $_POST['ftpPasswd']);
        if (!$ftpLogin) {
            echo '<span style="color: red;">' . esc_html__('Invalid username / password!', 'chimpxpress') . '</span>';
            ftp_close($ftpStream);
            exit;
        }

        $ftproot = ftp_chdir($ftpStream, wp_sanitize_redirect($_POST['ftpPath']));
        $adminDir = ftp_chdir($ftpStream, 'wp-admin');
        ftp_close($ftpStream);

        if (!$ftproot || !$adminDir) {
            echo '<span style="color: red;">' . esc_html__('Invalid FTP path!', 'chimpxpress') . '</span>';
            exit;
        }

        echo '<span style="color: green;">' . esc_html__('FTP test successful!', 'chimpxpress') . '</span>';
        exit;
    }

    public function main() {
        require_once(CHIMPX_PLUGIN_DIR . 'main.php');
    }

    function compose() {
        global $wp_filesystem;

        $useFTP = ($wp_filesystem->method == 'direct') ? false : true;
        $handler = $wp_filesystem;
        $cacheDir = self::getUploadDir()['absPath'] . DS . 'cache';

        echo '<div class="wrap" id="CXwrap">';

        if ($useFTP) {
            $handler = ftp_connect(sanitize_text_field($this->settings['ftpHost']));
            $login = ftp_login($handler, sanitize_text_field($this->settings['ftpUser']), $this->settings['ftpPasswd']);
            ftp_chdir($handler, wp_sanitize_redirect($this->settings['ftpPath']));
        }

        $cache = new chimpxpressJG_Cache($cacheDir, $useFTP, $handler);
        $templates = $cache->get('templates');

        if ($templates === false) {
            echo '<div id="preloaderContainer"><div id="preloader">' . esc_html__('Retrieving templates and lists ...', 'chimpxpress') . '</div></div>';
        }
        require_once(CHIMPX_PLUGIN_DIR . 'compose.php');
        echo '</div>';
    }

    function import() {
        require_once(CHIMPX_PLUGIN_DIR . 'import.php');
    }

    function archive() {
        require_once(CHIMPX_PLUGIN_DIR . 'archive.php');
    }

    function editLP() {
        require_once(CHIMPX_PLUGIN_DIR . 'editLP.php');
    }

    public function options() {
        wp_enqueue_script('chimpxpress-core', plugins_url('js' . DS . 'chimpxpress_core.js', __FILE__)); ?>

        <div class="wrap" id="CXwrap">
            <div id="dashboardButton">
                <a class="button" id="next" href="admin.php?page=chimpXpressDashboard"
                   title="chimpXpress <?php esc_html_e('Dashboard', 'chimpxpress'); ?> &raquo;">chimpXpress <?php esc_html_e('Dashboard', 'chimpxpress'); ?>
                    &raquo;</a>
            </div>
            <h1 class="componentHeading">chimpXpress <?php esc_html_e('Settings', 'chimpxpress') ?></h1>

            <?php $this->MCAPI->showMessages();?>

            <form action="options.php" method="post" id="wp_chimpxpress">
                <?php settings_fields($this->optionsGroup); ?>
                <table class="form-table">
                    <?php /*
		    <tr valign="top">
			<th scope="row">
			    <label for="<?php echo $this->_optionsName; ?>_username">
				<?php esc_html_e('Mailchimp Username', 'chimpxpress'); ?>
			    </label>
			</th>
			<td>
			    <input type="text" name="<?php echo $this->_optionsName; ?>[username]" value="<?php echo esc_attr($this->_settings['username']); ?>" id="<?php echo $this->_optionsName; ?>_username" class="regular-text code" />
			    <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_username').toggle(); return false;">
				<?php esc_html_e('[?]', 'chimpxpress'); ?></a>
			    <div style="display:inline-block;">
			    <ol id="mc_username" style="display:none; list-style-type:decimal;">
				<li>
				    <?php echo sprintf(__('You need a Mailchimp account. If you do not have one, <a href="%s" target="_blank">sign up for free</a>', 'chimpxpress'), 'https://www.mailchimp.com/signup'); ?>
				</li>
			    </ol>
			    </div>
			</td>
		    </tr>
		    <tr valign="top">
			<th scope="row">
			    <label for="<?php echo $this->_optionsName; ?>_password">
				<?php esc_html_e('Mailchimp Password', 'chimpxpress') ?>
			    </label>
			</th>
			<td>
			    <input type="password" name="<?php echo $this->_optionsName; ?>[password]" value="<?php echo esc_attr($this->_settings['password']); ?>" id="<?php echo $this->_optionsName; ?>_password" class="regular-text code" />
			    <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_password').toggle(); return false;">
				<?php esc_html_e('[?]', 'chimpxpress'); ?></a>
			    <div style="display:inline-block;">
			    <ol id="mc_password" style="display:none; list-style-type:decimal;">
				<li>
				    <?php echo sprintf(__('You need a Mailchimp account. If you do not have one, <a href="%s" target="_blank">sign up for free</a>', 'chimpxpress'), 'https://www.mailchimp.com/signup'); ?>
				</li>
			    </ol>
			    </div>
			</td>
		    </tr>
		    */ ?>
                    <tr valign="top">
                        <th scope="row">
                            <label for="<?php echo esc_attr($this->optionsName); ?>_apikey">
                                <?php esc_html_e('Mailchimp API Key', 'chimpxpress') ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->optionsName); ?>[apiKey]"
                                   style="text-align:center;width:320px;" maxlength="36"
                                   value="<?php echo esc_attr(sanitize_text_field($this->settings['apiKey'] ?? '')); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_apikey" class="regular-text code" />

                            <?php
                            wp_add_inline_script('chimpxpress-core', "
                                jQuery(document).ready(function ($) {
                                    if (jQuery('#" . esc_attr($this->optionsName) . "_apikey').val() == '') {
                                        jQuery('#mc_apikey').toggle();
                                    }
                                });"); ?>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#mc_apikey').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div>
                                <ol id="mc_apikey" style="display:none; list-style-type:decimal; margin-top: 1em;">
                                    <li>
                                        <?php echo esc_html_e('You need a Mailchimp account. If you do not have one', 'chimpxpress'); ?>
                                        <a href="https://www.mailchimp.com/signup" target="_blank">
                                            <?php echo esc_html_e('sign up for free', 'chimpxpress'); ?>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="https://admin.mailchimp.com/account/api-key-popup/" target="_blank">
                                            <?php esc_html_e('Grab your API Key', 'chimpxpress'); ?>
                                        </a>
                                    </li>
                                </ol>
                            </div>
                        </td>
                    </tr>
                    <?php /*
		    <tr valign="top">
			<th scope="row">
			    <label for="<?php echo $this->_optionsName; ?>_version">
				<?php esc_html_e('Mailchimp API version', 'chimpxpress') ?>
				<a title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_version').toggle(); return false;">
				    <?php esc_html_e('[?]', 'chimpxpress'); ?>
				</a>
			    </label>
			</th>
			<td>
			    <input type="text" name="<?php echo $this->_optionsName; ?>[version]" value="<?php echo esc_attr($this->_settings['version']); ?>" id="<?php echo $this->_optionsName; ?>_version" class="small-text" />
			    <small id="mc_version" style="display:none;">
				This is the default version to use if one isn't
				specified.
			    </small>
			</td>
		    </tr>
		    <tr valign="top">
			<th scope="row">
			    <?php esc_html_e('Debugging Mode', 'chimpxpress') ?>
			    <a title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_debugging').toggle(); return false;">
				<?php esc_html_e('[?]', 'chimpxpress'); ?>
			    </a>
			</th>
			<td>
			    <input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="on" id="<?php echo $this->_optionsName; ?>_debugging-on"<?php checked('on', $this->_settings['debugging']); ?> />
			    <label for="<?php echo $this->_optionsName; ?>_debugging-on"><?php esc_html_e('On', 'chimpxpress'); ?></label><br />
			    <input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="webhooks" id="<?php echo $this->_optionsName; ?>_debugging-webhooks"<?php checked('webhooks', $this->_settings['debugging']); ?> />
			    <label for="<?php echo $this->_optionsName; ?>_debugging-webhooks"><?php esc_html_e('Partial - Only WebHook Messages', 'chimpxpress'); ?></label><br />
			    <input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="off" id="<?php echo $this->_optionsName; ?>_debugging-off"<?php checked('off', $this->_settings['debugging']); ?> />
			    <label for="<?php echo $this->_optionsName; ?>_debugging-off"><?php esc_html_e('Off', 'chimpxpress'); ?></label><br />
			    <small id="mc_debugging" style="display:none;">
				<?php esc_html_e('If this is on, debugging messages will be sent to the E-Mail addresses set below.', 'chimpxpress'); ?>
			    </small>
			</td>
		    </tr>
		    <tr valign="top">
			<th scope="row">
			    <label for="<?php echo $this->_optionsName; ?>_debugging_email">
				<?php esc_html_e('Debugging E-Mail', 'chimpxpress') ?>
				<a title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_debugging_email').toggle(); return false;">
				    <?php esc_html_e('[?]', 'chimpxpress'); ?>
				</a>
			    </label>
			</th>
			<td>
			    <input type="text" name="<?php echo $this->_optionsName; ?>[debugging_email]" value="<?php echo esc_attr($this->_settings['debugging_email']); ?>" id="<?php echo $this->_optionsName; ?>_debugging_email" class="regular-text" />
			    <small id="mc_debugging_email" style="display:none;">
				<?php esc_html_e('This is a comma separated list of E-Mail addresses that will receive the debug messages.', 'chimpxpress'); ?>
			    </small>
			</td>
		    </tr>
		    <tr valign="top">
			<th scope="row">
			    <label for="<?php echo $this->_optionsName; ?>_listener_security_key">
				<?php esc_html_e('Mailchimp WebHook Listener Security Key', 'chimpxpress'); ?>
				<a title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_listener_security_key').toggle(); return false;">
				    <?php esc_html_e('[?]', 'chimpxpress'); ?>
				</a>
			    </label>
			</th>
			<td>
			    <input type="text" name="<?php echo $this->_optionsName; ?>[listener_security_key]" value="<?php echo esc_attr($this->_settings['listener_security_key']); ?>" id="<?php echo $this->_optionsName; ?>_listener_security_key" class="regular-text code" />
			    <input type="submit" name="regenerate-security-key" value="<?php esc_html_e('Regenerate Security Key', 'chimpxpress'); ?>" />
			    <div id="mc_listener_security_key" style="display:none; list-style-type:decimal;">
				<p><?php echo esc_html_e('This is used to make the listener a little more secure. Usually the key that was randomly generated for you is fine, but you can make this whatever you want.', 'chimpxpress'); ?></p>
				<p class="error"><?php echo esc_html_e('Warning: Changing this will change your WebHook Listener URL below and you will need to update it in your Mailchimp account!', 'chimpxpress'); ?></p>
			    </div>
			</td>
		    </tr>
		    <tr valign="top">
			<th scope="row">
			    <?php esc_html_e('Mailchimp WebHook Listener URL', 'chimpxpress') ?>
			    <a title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>" href="#" onclick="jQuery('#mc_listener_url').toggle(); return false;">
				<?php esc_html_e('[?]', 'chimpxpress'); ?>
			    </a>
			</th>
			<td>
			    <?php echo $this->getListenerUrl(); ?>
			    <div id="mc_listener_url" style="display:none;">
				<p><?php esc_html_e('To set this in your Mailchimp account:', 'chimpxpress'); ?></p>
				<ol style="list-style-type:decimal;">
				    <li>
					<?php echo sprintf(__('<a href="%s">Log into your Mailchimp account</a>', 'chimpxpress'), 'https://admin.mailchimp.com/'); ?>
				    </li>
				    <li>
					<?php esc_html_e('Navigate to your <strong>Lists</strong>', 'chimpxpress'); ?>
				    </li>
				    <li>
					<?php esc_html_e("Click the <strong>View Lists</strong> button on the list you want to configure.", 'chimpxpress'); ?>
				    </li>
				    <li>
					<?php esc_html_e('Click the <strong>List Tools</strong> menu option at the top.', 'chimpxpress'); ?>
				    </li>
				    <li>
					<?php esc_html_e('Click the <strong>WebHooks</strong> link.', 'chimpxpress'); ?>
				    </li>
				    <li>
					<?php echo sprintf(__("Configuration should be pretty straight forward. Copy/Paste the URL shown above into the callback URL field, then select the events and event sources (see the <a href='%s'>Mailchimp documentation for more information on events and event sources) you'd like to have sent to you.", 'chimpxpress'), 'http://www.mailchimp.com/api/webhooks/'); ?>
				    </li>
				    <li>
					<?php esc_html_e("Click save and you're done!", 'chimpxpress'); ?>
				    </li>
				</ol>
			    </div>
			</td>
		    </tr>
		    */ ?>

                    <?php if ($_SESSION['MCping']) { ?>
                        <tr valign="top">
                            <th scope="row">
                                <label><?php esc_html_e('Connected as', 'chimpxpress'); ?></label>
                            </th>
                            <td>
                                <span style="font-size:12px;"><?php echo esc_html(sanitize_text_field($_SESSION['MCusername'])); ?></span>
                            </td>
                        </tr>
                    <?php } ?>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('Current Mailchimp Status', 'chimpxpress') ?></label>
                        </th>
                        <td>
                            <span id="mc_ping">
                            <?php echo ($_SESSION['MCping'])
                                ? '<span style="color:green;">' . esc_html(sanitize_text_field($_SESSION['MCping'])) . '</span>'
                                : '<span style="color:red;">' . esc_html__('Not connected', 'chimpxpress') . '</span>'; ?>
                            </span>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#mc_status').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="mc_status" style="display:none;">
                                    <?php esc_html_e("The current status of your server's connection to Mailchimp", 'chimpxpress'); ?>
                                </span>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td></td>
                        <td></td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('Grant Access for', 'chimpxpress') ?></label>
                        </th>
                        <td>
                            <select name="<?php echo esc_attr($this->optionsName); ?>[CEaccess]" style="width:320px;">
                                <option value="manage_options" <?php echo ($this->settings['CEaccess'] == 'manage_options') ? 'selected="selected"' : ''; ?>><?php esc_html_e('Administrators', 'chimpxpress'); ?></option>
                                <option value="publish_pages" <?php echo ($this->settings['CEaccess'] == 'publish_pages') ? 'selected="selected"' : ''; ?>><?php esc_html_e('Editors', 'chimpxpress'); ?></option>
                                <option value="publish_posts" <?php echo ($this->settings['CEaccess'] == 'publish_posts') ? 'selected="selected"' : ''; ?>><?php esc_html_e('Authors', 'chimpxpress'); ?></option>
                            </select>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#mc_access').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="mc_access"
                                      style="display:none;"><?php esc_html_e("Select the role, which is supposed to have access to the plugin. All roles above the selected will have access as well.", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td></td>
                        <td></td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('FTP Host', 'chimpxpress'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->optionsName); ?>[ftpHost]"
                                   style="text-align:center;width:320px;" maxlength="36"
                                   value="<?php echo esc_attr(sanitize_text_field($this->settings['ftpHost'])); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_ftpHost" class="regular-text code"/>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#ftpHost').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="ftpHost"
                                      style="display:none;"><?php esc_html_e("If chimpXpress can't write files directly to the server it will prompt you to enter your ftp credentials.", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('FTP Username', 'chimpxpress'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->optionsName); ?>[ftpUser]"
                                   style="text-align:center;width:320px;" maxlength="36"
                                   value="<?php echo esc_attr(sanitize_text_field($this->settings['ftpUser'])); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_ftpUser" class="regular-text code"/>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#ftpUser').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="ftpUser"
                                      style="display:none;"><?php esc_html_e("If chimpXpress can't write files directly to the server it will prompt you to enter your ftp credentials.", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('FTP Password', 'chimpxpress'); ?></label>
                        </th>
                        <td>
                            <input type="password" name="<?php echo esc_attr($this->optionsName); ?>[ftpPasswd]"
                                   style="text-align:center;width:320px;" maxlength="36"
                                   value="<?php echo esc_attr($this->settings['ftpPasswd']); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_ftpPasswd" class="password code"/>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#ftpPasswd').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="ftpPasswd"
                                      style="display:none;"><?php esc_html_e("If chimpXpress can't write files directly to the server it will prompt you to enter your ftp credentials.", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('FTP Path', 'chimpxpress'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->optionsName); ?>[ftpPath]"
                                   style="text-align:center;width:320px;"
                                   value="<?php echo esc_attr(wp_sanitize_redirect($this->settings['ftpPath'])); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_ftpPath" class="regular-text code" />
                            &nbsp;&nbsp;
                            <a href="javascript:chimpx_ftp_find_root()"><?php esc_html_e('Find FTP Path', 'chimpxpress'); ?></a>
                            <?php
                            wp_add_inline_script('chimpxpress-core', "
                                function chimpx_ftp_find_root() {
                                    var data = {
                                        action: 'ftp_find_root',
                                        _wpnonce: jQuery('#_wpnonce').val(),
                                        ftpHost: jQuery('#" . esc_attr($this->optionsName) . "_ftpHost').val(),
                                        ftpUser: jQuery('#" . esc_attr($this->optionsName) . "_ftpUser').val(),
                                        ftpPasswd: jQuery('#" . esc_attr($this->optionsName) . "_ftpPasswd').val()
                                    };
                                    jQuery.post(ajaxurl, data, function (response) {
                                        jQuery('#" . esc_attr($this->optionsName) . "_ftpPath').val(response);
                                    });
                                }"); ?>
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#ftpPath').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="ftpPath"
                                      style="display:none;"><?php esc_html_e("Enter the path from the ftp root directory to your WordPress installation. To find it you can login with your ftp client and navigate to the WordPress directory. This would be your ftp path.", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('Test FTP Connection', 'chimpxpress') ?></label>
                        </th>
                        <td>
                            <a href="javascript:chimpx_testFtp()"
                               style="float:left; margin-right: 20px;"><?php esc_html_e('run test', 'chimpxpress'); ?></a>
                            <div id="ajaxLoader" style="display:none;float: left;padding-top: 5px;width: 30px;">
                                <img src="<?php echo esc_attr(plugins_url('/images/ajax-loader.gif', __FILE__)); ?>" alt=""/>
                            </div>
                            <div id="ftpResponse" style="float:left;"></div>
                            <div style="clear:both;"></div>
                            <?php
                            wp_add_inline_script('chimpxpress-core', "
                                function chimpx_testFtp() {
                                    jQuery('#ftpResponse').html('');
                                    jQuery('#ajaxLoader').css('display', '');
                                    var data = {
                                        action: 'ftp_test',
                                        _wpnonce: jQuery('#_wpnonce').val(),
                                        ftpHost: jQuery('#" . esc_attr($this->optionsName) . "_ftpHost').val(),
                                        ftpUser: jQuery('#" . esc_attr($this->optionsName) . "_ftpUser').val(),
                                        ftpPasswd: jQuery('#" . esc_attr($this->optionsName) . "_ftpPasswd').val(),
                                        ftpPath: jQuery('#" . esc_attr($this->optionsName) . "_ftpPath').val()
                                    };
                                    jQuery.post(ajaxurl, data, function (response) {
                                        jQuery('#ajaxLoader').css('display', 'none');
                                        jQuery('#ftpResponse').html(response);
                                    });
                                }"); ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"></th>
                        <td></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label><?php esc_html_e('Google Analytics Profile ID', 'chimpxpress') ?></label>
                        </th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->optionsName); ?>[GAprofile]"
                                   style="text-align:center;width:320px;" maxlength="36"
                                   value="<?php echo esc_attr(sanitize_text_field($this->settings['GAprofile'])); ?>"
                                   id="<?php echo esc_attr($this->optionsName); ?>_GAprofile" class="regular-text code" />
                            <a class="chimpxpress_help" title="<?php esc_html_e('Click for Help!', 'chimpxpress'); ?>"
                               href="#" onclick="jQuery('#ga_info').toggle(); return false;">
                                <?php esc_html_e('[?]', 'chimpxpress'); ?></a>
                            <div style="display:inline-block;">
                                <span id="ga_info"
                                      style="display:none;"><?php esc_html_e("Enter your Google Analytics Profile ID if you want to be able to track your landing pages in Analytics. The ID should look like: UA-1234567-8", 'chimpxpress'); ?></span>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"></th>
                        <td>
                            <input type="submit" name="Submit" class="button"
                                   value="<?php esc_html_e('Update Settings', 'chimpxpress'); ?> &raquo;" />
                        </td>
                    </tr>
                </table>
            </form>
        </div>
        <?php
    }

    private function getListenerUrl() {
        return get_bloginfo('url') . '/?' . $this->_listener_query_var . '=' . urlencode($this->settings['listener_security_key']);
    }

    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    // load language files
    function chimpxpressLoadLanguage() {
        if (function_exists('load_plugin_textdomain')) {
            load_plugin_textdomain($this->optionsName, false, basename(CHIMPX_PLUGIN_DIR) . DS . 'languages');
        }
    }

    // Add meta box
    function chimpxpress_add_box() {
        // add meta box to blog post edit page
        $meta_box = [
            'id'       => 'chimpxpress-meta-box',
            'title'    => esc_html__('Import from Mailchimp campaign', 'chimpxpress'),
            'page'     => 'post',
            'context'  => 'side',
            'priority' => 'default'
        ];
        add_meta_box($meta_box['id'], $meta_box['title'], [$this, 'chimpxpress_show_box'], $meta_box['page'], $meta_box['context'], $meta_box['priority']);

        // add meta box to page edit page
        $meta_box = [
            'id'       => 'chimpxpress-meta-box',
            'title'    => esc_html__('Import from Mailchimp campaign', 'chimpxpress'),
            'page'     => 'page',
            'context'  => 'side',
            'priority' => 'default'
        ];
        add_meta_box($meta_box['id'], $meta_box['title'], [$this, 'chimpxpress_show_box'], $meta_box['page'], $meta_box['context'], $meta_box['priority']);
    }


    function chimpxpress_show_box() {
        require_once(CHIMPX_PLUGIN_DIR . 'class-MCAPI.php');
        $MCAPI = new chimpxpressMCAPI();
        $campaigns = $MCAPI->campaigns(['status' => 'sent']);

        echo '<div style="margin: 10px;">';
        echo '<p style="margin-left:0;margin-right:0;">' . esc_html__('Choose a campaign to copy content into your post', 'chimpxpress') . ':</p>';
        echo '<div style="height: 21em; overflow: auto;">';
        echo '<ul id="MCcampaigns">';
        foreach ($campaigns['campaigns'] as $c) {
            echo '<li><a title="' . esc_html__('open campaign in popup window', 'chimpxpress')
                . '" href="javascript:window.open(\'' . esc_attr($c['archive_url'])
                . '\',\'preview\',\'status=0,scrollbars=1,resizable=1,toolbar=0,location=0,menubar=0,directories=0,width=800,height=600\');void(0)">'
                . esc_html($c['settings']['title']) . ' (' . esc_html($c['settings']['subject_line']) . ')</a></li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '<div style="text-align:right; margin: 15px 0;">';
        echo '<span id="CEajaxLoader" style="visibility:hidden;margin-right: 10px;"><img src="' . esc_attr(plugins_url('/images/ajax-loader.gif', __FILE__)) . '" style="position: relative;top: 1px;"/></span>';
        echo '<a id="load_campaigns_link" class="button" href="javascript:loadCampaigns(1)" title="' . esc_html__('load more campaigns', 'chimpxpress') . '">' . esc_html__('more', 'chimpxpress') . '</a>';
        echo '</div>';
        wp_nonce_field('chimpxpress-load-campaigns', 'CX_nonce');
        echo '</div>';

        wp_add_inline_script('wp-edit-post', 'function loadCampaigns( page ){
		    jQuery("#CEajaxLoader").css( "visibility", "visible" );
		    var data = { 
		         action: "load_campaigns",
                 _wpnonce: jQuery("#CX_nonce").val(),
				 nextPage : page
		    };
		    jQuery.post(ajaxurl, data, function(response) {
			    jQuery("#CEajaxLoader").css( "visibility", "hidden" );
			    jQuery("#MCcampaigns").append( response.html );
			    jQuery("#load_campaigns_link").attr("href", "javascript:loadCampaigns("+response.page+")" );
			if( !response.again ){
			    jQuery("#load_campaigns_link").css("display", "none" );
			    jQuery("#CEajaxLoader").css("display", "none" );
			}
		    });
		}');
    }
}

$chimpxpress = chimpxpress::getInstance();
