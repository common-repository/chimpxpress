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
defined( 'ABSPATH' ) or die( 'Restricted Access' );

?>
<div class="wrap" id="CXwrap">

    <?php include(CHIMPX_PLUGIN_DIR . 'loggedInStatus.php'); ?>

	<h1 class="componentHeading">chimpXpress</h1>
	<div class="clr"></div>
	<?php if ( ! $_SESSION['MCping'] ){ ?>
	<div class="updated" style="width:100%;text-align:center;padding:10px 0 13px;">
		<a href="options-general.php?page=chimpXpressConfig"><?php esc_html_e('Please connect your Mailchimp account!', 'chimpxpress');?></a>
	</div>
	<?php }?>
	<?php
	global $wp_filesystem;
	if ($wp_filesystem->method != 'direct') {
        $chimpxpress = new chimpxpress;
        $ftpstream = ftp_connect(sanitize_text_field($chimpxpress->settings['ftpHost']));
        $login = ftp_login($ftpstream, sanitize_text_field($chimpxpress->settings['ftpUser']), $chimpxpress->settings['ftpPasswd']);
        $ftproot = ftp_chdir($ftpstream, wp_sanitize_redirect($chimpxpress->settings['ftpPath']));
        $adminDir = ftp_chdir($ftpstream, 'wp-admin');
        if (   $wp_filesystem->method != 'direct'
            && (
            !$chimpxpress->settings['ftpHost']
            || !$chimpxpress->settings['ftpUser']
            || !$chimpxpress->settings['ftpPasswd']
            || !$ftpstream
            || !$login
            || !$ftproot
            || !$adminDir
            )
         ){ ?>
            <div class="updated" style="width:100%;text-align:center;padding:10px 0 13px;">
                <a href="options-general.php?page=chimpXpressConfig"><?php esc_html_e('Direct file access not possible. Please enter valid ftp credentials in the configuration!', 'chimpxpress');?></a>
            </div><?php
        }
        ftp_close($ftpstream);
	}
	?>
	<div style="display:block;height:3em;"></div>

    <div class="clearfix">
        <div class="CX_left"><?php esc_html_e('Pull content from Mailchimp email campaigns into WordPress or compose an email campaign in WordPress, then pass it to Mailchimp.', 'chimpxpress');?></div>
        <div class="CX_right"><a href="https://chimpxpress.com" target="_blank">chimpXpress.com</a></div>
    </div>

    <div class="mainOptionContainer clearfix">
        <div class="mainOption">
            <h3><?php esc_html_e('Import', 'chimpxpress');?></h3>
            <hr />
            <div class="mainText"><?php esc_html_e('Import content from your Mailchimp Account', 'chimpxpress');?></div>
            <form action="<?php echo esc_attr(admin_url('/admin.php?page=chimpXpressImport'));?>" method="post" id="wp_chimpxpress">
                <input type="submit" class="button-primary" size="4" value="<?php esc_html_e('go', 'chimpxpress');?>" />
                <?php wp_nonce_field('chimpxpress-import');?>
            </form>
        </div>

        <div class="mainOption">
            <h3><?php esc_html_e('Compose', 'chimpxpress');?></h3>
            <hr />
            <div class="mainText"><?php esc_html_e('Compose an email, then pass it to Mailchimp for delivery.', 'chimpxpress');?></div>
            <form action="<?php echo esc_attr(admin_url('/admin.php?page=chimpXpressCompose'));?>" method="post" id="wp_chimpxpress">
                <input type="submit" class="button-primary" size="4" value="<?php esc_html_e('go', 'chimpxpress');?>" />
                <?php wp_nonce_field('chimpxpress-compose');?>
            </form>
        </div>
    </div>

	<a class="button" href="<?php echo esc_attr(admin_url('/admin.php?page=chimpXpressArchive'));?>" style="float:none;" title="<?php esc_html_e('Landing Page Archive', 'chimpxpress');?>"><?php esc_html_e('Landing Page Archive', 'chimpxpress');?></a>

	<?php include(CHIMPX_PLUGIN_DIR . 'footer.php'); ?>
</div>
