<?php
/**
 * uninstall.php removes everything the plugin stored, the details included (they live in
 * honk_settings).
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

class UninstallTest extends Honk_Test_Case {

	public function test_deleting_the_plugin_removes_its_options() {
		global $wpdb;
		$this->configure(
			array(
				'include_pii' => true,
				'details'     => array(
					'woo_new_order' => array( 'phone' => true ),
					'form_cf7'      => array( 'values' => true, 'skip' => array( '10' => array( 'your-email' ) ) ),
				),
			)
		);
		foreach ( array( 'honk_log', 'honk_jobs', 'honk_recent_keys', 'honk_login_failures', 'honk_health_state', 'honk_stock_state', 'honk_heartbeat_last', 'honk_heartbeat_scheduled', 'honk_job_0123456789abcdef0123456789abcdef' ) as $name ) {
			$this->options[ $name ] = array( 'x' );
		}
		$this->options['blogname'] = 'Corner Shop';

		$self = $this;
		$wpdb = Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->prefix  = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( function ( $text ) {
			return addcslashes( $text, '_%\\' );
		} );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query, $arg ) {
			return array( $query, $arg );
		} );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( function ( $prepared ) use ( $self ) {
			$prefix = stripslashes( rtrim( $prepared[1], '%' ) );
			return array_values( array_filter( array_keys( $self->options ), function ( $name ) use ( $prefix ) {
				return 0 === strpos( $name, $prefix );
			} ) );
		} );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_unschedule_hook' )->justReturn( 0 );
		Functions\when( 'delete_metadata' )->justReturn( true );

		define( 'WP_UNINSTALL_PLUGIN', 'honk/honk.php' );
		require HONK_DIR . 'uninstall.php';

		$this->assertSame( array( 'blogname' ), array_keys( $this->options ), 'only the site\'s own options are left' );
	}
}
