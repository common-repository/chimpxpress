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

?>
<div id="loggedInStatus">
    <?php if ($_SESSION['MCping']) {
        echo esc_html__('connected as', 'chimpxpress')
            . " <a href=\"options-general.php?page=chimpXpressConfig\">" . esc_html(sanitize_text_field($_SESSION['MCusername'])) . "</a>";
    } else { ?>
        <a href="options-general.php?page=chimpXpressConfig">
            <?php esc_html_e('connect your Mailchimp account', 'chimpxpress');?>
        </a><?php
    } ?>
</div>
