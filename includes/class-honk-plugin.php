<?php
/**
 * Bootstrap: autoloader, activation, scheduled tasks and module wiring.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Honk_Plugin {

	/**
	 * Hook of the daily tasks (Site Health, updates available, clean-up).
	 */
	const DAILY_HOOK = 'honk_daily';

	/**
	 * Hook of the hourly tasks (WP-Cron overdue, disk space).
	 */
	const HOURLY_HOOK = 'honk_hourly';

	/**
	 * Registers the class autoloader for the Honk_ prefix.
	 *
	 * @return void
	 */
	public static function register_autoloader() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Loads includes/class-honk-*.php or includes/modules/class-honk-module-*.php.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Honk_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		foreach ( array( 'includes/', 'includes/modules/' ) as $dir ) {
			$path = HONK_DIR . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}

	/**
	 * Registers every hook. Runs when the plugin file is loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woocommerce_compatibility' ) );

		Honk_Queue::register();
		Honk_Settings::register();

		self::load_modules();
		add_action( 'init', array( __CLASS__, 'ensure_schedules' ) );
		add_action( self::DAILY_HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOURLY_HOOK, array( __CLASS__, 'run_hourly' ) );

		if ( is_admin() ) {
			Honk_Admin::register();
		}
	}

	/**
	 * Declares compatibility with WooCommerce's order tables (HPOS) and the block checkout.
	 *
	 * @return void
	 */
	public static function declare_woocommerce_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HONK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HONK_FILE, true );
		}
	}

	/**
	 * Registers the event modules. Nothing listens until an ingestion key is saved, so no data
	 * leaves the site before that. Registered while the plugin file loads (not on plugins_loaded)
	 * so that a fatal error in a plugin loaded after Honk is still reported.
	 *
	 * @return void
	 */
	public static function load_modules() {
		Honk_Heartbeat::register();
		if ( ! Honk_Settings::is_configured() ) {
			return;
		}
		Honk_Module_Security::register();
		Honk_Module_Health::register();
		Honk_Module_Content::register();
		Honk_Module_Forms::register();
		Honk_Module_Woocommerce::register();
	}

	/**
	 * Plugin activation: a stable site id and the scheduled tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		Honk_Settings::site_id();
		self::ensure_schedules();
		Honk_Queue::maintenance();
	}

	/**
	 * Plugin deactivation: removes scheduled tasks. Settings stay until the plugin is deleted.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
		// Pending deliveries stay stored and are rescheduled by the daily clean-up after a reactivation.
		if ( Honk_Queue::action_scheduler_ready() ) {
			as_unschedule_all_actions( '', array(), Honk_Queue::GROUP );
		}
	}

	/**
	 * Every WP-Cron hook the plugin schedules.
	 *
	 * @return string[]
	 */
	public static function cron_hooks() {
		return array(
			self::DAILY_HOOK,
			self::HOURLY_HOOK,
			Honk_Module_Woocommerce::SUMMARY_HOOK,
			Honk_Module_Security::BURST_CHECK_HOOK,
			Honk_Heartbeat::HOOK,
			Honk_Queue::HOOK,
		);
	}

	/**
	 * Schedules the recurring tasks if they are missing (after a migration, a cron reset…).
	 *
	 * @return void
	 */
	public static function ensure_schedules() {
		if ( wp_installing() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOURLY_HOOK );
		}
		Honk_Module_Woocommerce::ensure_summary_schedule();
	}

	/**
	 * Daily tasks.
	 *
	 * @return void
	 */
	public static function run_daily() {
		Honk_Queue::maintenance();
		if ( ! Honk_Settings::is_configured() ) {
			return;
		}
		Honk_Module_Health::daily();
	}

	/**
	 * Hourly tasks.
	 *
	 * @return void
	 */
	public static function run_hourly() {
		if ( ! Honk_Settings::is_configured() ) {
			return;
		}
		Honk_Module_Health::hourly();
	}
}
