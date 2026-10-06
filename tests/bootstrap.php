<?php
/**
 * PHPUnit bootstrap: Brain Monkey stubs WordPress; the plugin classes load through its autoloader.
 *
 * @package Honk
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', '/tmp/wordpress/wp-content' );
define( 'WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins' );
define( 'WP_LANG_DIR', '/tmp/wordpress/wp-content/languages' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HONK_VERSION', '0.1.0' );
define( 'HONK_FILE', dirname( __DIR__ ) . '/honk.php' );
define( 'HONK_DIR', dirname( __DIR__ ) . '/' );

require_once HONK_DIR . 'includes/class-honk-plugin.php';
Honk_Plugin::register_autoloader();

require_once __DIR__ . '/class-wp-stubs.php';
require_once __DIR__ . '/class-honk-test-case.php';
require_once __DIR__ . '/trait-honk-scenarios.php';
