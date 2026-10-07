<?php
/**
 * Plugin Name:          Honk Me – Notifications for Sites, Shops and Forms
 * Plugin URI:           https://honk-me.app
 * Description:          Orders, failed payments, form entries, suspicious sign-ins and site errors from WordPress and WooCommerce, in your Honk inbox on iPhone, Apple Watch and the web.
 * Version:              0.2.0
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Author:               Honk Me
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          honk-me
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

define( 'HONK_VERSION', '0.2.0' );
define( 'HONK_FILE', __FILE__ );
define( 'HONK_DIR', plugin_dir_path( __FILE__ ) );

require_once HONK_DIR . 'includes/class-honk-plugin.php';

Honk_Plugin::register_autoloader();

register_activation_hook( __FILE__, array( 'Honk_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Honk_Plugin', 'deactivate' ) );

Honk_Plugin::boot();
