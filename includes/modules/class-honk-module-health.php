<?php
/**
 * Site health events: fatal errors, Site Health, updates, WP-Cron, disk space.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Health module.
 */
final class Honk_Module_Health {

	const STATE_OPTION = 'honk_health_state';

	/**
	 * Minutes a WP-Cron task may be late before it counts as overdue.
	 */
	const CRON_LATE_MINUTES = 30;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'recovery_mode_email', array( __CLASS__, 'on_recovery_mode_email' ), 10, 2 );
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_php_error_message' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'on_automatic_updates_complete' ), 20, 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_check_cron_from_admin' ) );
	}

	/**
	 * Daily checks.
	 *
	 * @return void
	 */
	public static function daily() {
		self::check_site_health();
		self::check_updates_available();
	}

	/**
	 * Hourly checks.
	 *
	 * @return void
	 */
	public static function hourly() {
		self::check_cron();
		self::check_disk();
	}

	/**
	 * State shared by the checks (problem/recovery bookkeeping).
	 *
	 * @param string     $key   Key.
	 * @param mixed|null $value New value, or null to read.
	 * @return mixed
	 */
	private static function state( $key, $value = null ) {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		if ( null === $value ) {
			return isset( $state[ $key ] ) ? $state[ $key ] : null;
		}
		$state[ $key ] = $value;
		update_option( self::STATE_OPTION, $state, false );
		return $value;
	}

	/*
	 * Fatal errors (recovery mode).
	 */

	/**
	 * WordPress is about to email the administrator about a fatal error (recovery mode, in the
	 * dashboard and on the sign-in page): honk too. The email is returned unchanged, so WordPress
	 * still sends it.
	 *
	 * @param array  $email Email: to, subject, message, headers.
	 * @param string $url   Recovery mode link (not sent to Honk: it is a sign-in link).
	 * @return array
	 */
	public static function on_recovery_mode_email( $email, $url ) {
		unset( $url );
		$error = error_get_last();
		if ( is_array( $error ) ) {
			self::report_fatal( $error, true );
		}
		return $email;
	}

	/**
	 * WordPress's fatal error handler shows its "critical error" page (any request, including the
	 * front end, where WordPress sends no email): honk. The message is returned unchanged.
	 *
	 * @param string $message HTML message of the error page.
	 * @param array  $error   Error from error_get_last().
	 * @return string
	 */
	public static function on_php_error_message( $message, $error ) {
		if ( is_array( $error ) ) {
			self::report_fatal( $error, false );
		}
		return $message;
	}

	/**
	 * Queues a fatal error, once per location and message per day (both paths above can fire for
	 * the same error).
	 *
	 * @param array $error    Error: type, message, file, line.
	 * @param bool  $recovery Whether WordPress entered recovery mode and emailed the administrator.
	 * @return void
	 */
	public static function report_fatal( array $error, $recovery ) {
		if ( empty( $error['message'] ) ) {
			return;
		}
		$file      = isset( $error['file'] ) ? (string) $error['file'] : '';
		$line      = isset( $error['line'] ) ? (int) $error['line'] : 0;
		$extension = self::extension_for_file( $file );
		$relative  = self::relative_path( $file );
		$text      = self::relative_path( (string) strtok( (string) $error['message'], "\n" ) );

		Honk_Notifier::emit(
			'fatal_error',
			'fatal-' . md5( $file . ':' . $line . ':' . $text ) . '-' . gmdate( 'Ymd' ),
			function () use ( $extension, $relative, $line, $text, $recovery ) {
				$spec = self::fatal_spec(
					array(
						'type'     => $extension['type'],
						'name'     => $extension['name'],
						'error'    => $text,
						'file'     => $relative,
						'line'     => $line,
						'recovery' => $recovery,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'fatal_error', $spec ) + array(
						'group_key' => 'wp/health/fatal',
						'metadata'  => array(
							'file' => $relative,
							'line' => $line,
						),
					),
					admin_url( 'plugins.php' )
				);
			}
		);
	}

	/**
	 * Which plugin or theme a file belongs to.
	 *
	 * @param string $file Absolute path.
	 * @return array{type: string, name: string}
	 */
	public static function extension_for_file( $file ) {
		$file    = wp_normalize_path( $file );
		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		if ( 0 === strpos( $file, $plugins ) ) {
			$slug = (string) strtok( substr( $file, strlen( $plugins ) ), '/' );
			return array(
				'type' => 'plugin',
				'name' => self::plugin_name_for_slug( $slug ),
			);
		}
		if ( 0 === strpos( $file, $themes ) ) {
			$slug  = strtok( substr( $file, strlen( $themes ) ), '/' );
			$theme = wp_get_theme( (string) $slug );
			return array(
				'type' => 'theme',
				'name' => $theme->exists() ? $theme->get( 'Name' ) : (string) $slug,
			);
		}
		return array(
			'type' => '',
			'name' => '',
		);
	}

	/**
	 * The name of an installed plugin from its directory (or single file) name.
	 *
	 * @param string $slug Directory or file name under wp-content/plugins.
	 * @return string
	 */
	private static function plugin_name_for_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $file => $data ) {
				if ( ( $file === $slug || 0 === strpos( $file, $slug . '/' ) ) && ! empty( $data['Name'] ) ) {
					return Honk_Payload::plain( $data['Name'], true );
				}
			}
		}
		return $slug;
	}

	/**
	 * A path relative to the WordPress root (server paths stay out of messages).
	 *
	 * @param string $text Text that may contain absolute paths.
	 * @return string
	 */
	public static function relative_path( $text ) {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		return str_replace( array( $root . '/', $root ), '', wp_normalize_path( (string) $text ) );
	}

	/*
	 * Site Health.
	 */

	/**
	 * Runs the Site Health tests that work without a browser (as WordPress's own weekly check does)
	 * and reports critical issues as a problem, and their disappearance as a recovery.
	 *
	 * @return void
	 */
	public static function check_site_health() {
		$critical = self::site_health_critical();
		if ( null === $critical ) {
			return;
		}
		$previous = self::state( 'site_health' );
		$previous = is_array( $previous ) ? $previous : array();
		ksort( $critical );
		$ids = array_keys( $critical );

		if ( empty( $ids ) ) {
			if ( ! empty( $previous ) ) {
				self::state( 'site_health', array() );
				Honk_Notifier::emit(
					'site_health_critical',
					'site-health-recovered-' . md5( implode( ',', $previous ) ),
					function () {
						return Honk_Notifier::with_link(
							array(
								'title'      => __( 'Site Health: no critical issues', 'honk' ),
								'message'    => __( 'The critical issues found earlier are resolved.', 'honk' ),
								'group_key'  => 'wp/health/site-health',
								'event_type' => 'recovery',
							),
							admin_url( 'site-health.php' )
						);
					},
					array( 'severity' => 'success' )
				);
			}
			return;
		}

		$new = array_diff( $ids, $previous );
		self::state( 'site_health', $ids );
		if ( empty( $new ) ) {
			return; // Same issues as last time: already reported.
		}
		Honk_Notifier::emit(
			'site_health_critical',
			'site-health-' . md5( implode( ',', $ids ) ) . '-' . gmdate( 'Ymd' ),
			function () use ( $critical ) {
				$count = count( $critical );
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'site_health_critical', self::site_health_spec( array_values( $critical ) ) ) + array(
						'group_key'  => 'wp/health/site-health',
						'event_type' => 'problem',
						'metadata'   => array( 'critical' => $count ),
					),
					admin_url( 'site-health.php' )
				);
			}
		);
	}

	/**
	 * Labels of the critical Site Health results, keyed by test id; null if Site Health is not
	 * available.
	 *
	 * @return array<string, string>|null
	 */
	public static function site_health_critical() {
		// The tests use wp-admin functions (get_core_updates(), get_plugins()…) that WP-Cron does not
		// load, so load them as WordPress's own scheduled check does.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return null;
		}
		$health = WP_Site_Health::get_instance();
		$tests  = WP_Site_Health::get_tests();
		if ( 'production' !== wp_get_environment_type() ) {
			unset( $tests['async']['https_status'] );
		}

		$critical = array();
		$run      = function ( $id, $callback ) use ( &$critical ) {
			if ( ! is_callable( $callback ) ) {
				return;
			}
			try {
				$result = call_user_func( $callback );
			} catch ( Throwable $e ) {
				return; // One broken test (often from another plugin) must not stop the others.
			}
			/** This filter is documented in wp-admin/includes/class-wp-site-health.php */
			$result = apply_filters( 'site_status_test_result', $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as WordPress does.
			if ( is_array( $result ) && isset( $result['status'] ) && 'critical' === $result['status'] ) {
				$key              = ! empty( $result['test'] ) ? (string) $result['test'] : (string) $id;
				$critical[ $key ] = Honk_Payload::plain( isset( $result['label'] ) ? $result['label'] : $key, true );
			}
		};

		foreach ( $tests['direct'] as $id => $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$run( $id, array( $health, 'get_test_' . $test['test'] ) );
			} else {
				$run( $id, $test['test'] );
			}
		}
		foreach ( $tests['async'] as $id => $test ) {
			if ( empty( $test['skip_cron'] ) && ! empty( $test['async_direct_test'] ) ) {
				$run( $id, $test['async_direct_test'] );
			}
		}
		return $critical;
	}

	/*
	 * Updates.
	 */

	/**
	 * Automatic updates finished: failures are reported (Long honk), successes as installed
	 * updates (Light honk).
	 *
	 * @param array $results Results by type: core, plugin, theme, translation.
	 * @return void
	 */
	public static function on_automatic_updates_complete( $results ) {
		if ( ! is_array( $results ) ) {
			return;
		}
		$failed = array();
		$done   = array();
		foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
			if ( empty( $results[ $type ] ) || ! is_array( $results[ $type ] ) ) {
				continue;
			}
			foreach ( $results[ $type ] as $item ) {
				$name = isset( $item->name ) ? Honk_Payload::plain( $item->name, true ) : $type;
				if ( is_wp_error( $item->result ) ) {
					$failed[] = $name . ': ' . Honk_Payload::plain( $item->result->get_error_message(), true );
				} elseif ( false === $item->result ) {
					$failed[] = $name;
				} elseif ( true === $item->result || is_array( $item->result ) ) {
					$done[ $type ][] = $name;
				}
			}
		}
		foreach ( $done as $type => $items ) {
			Honk_Module_Security::report_updates( $type, $items, true );
		}
		if ( empty( $failed ) ) {
			return;
		}
		Honk_Notifier::emit(
			'auto_update_failed',
			'autoupdate-failed-' . md5( implode( '|', $failed ) ),
			function () use ( $failed ) {
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'auto_update_failed', self::update_failed_spec( $failed ), array( 'group_key' => 'wp/health/updates-failed' ) ),
					admin_url( 'update-core.php' )
				);
			}
		);
	}

	/**
	 * Daily: one message listing available updates, sent when the list changed.
	 *
	 * @return void
	 */
	public static function check_updates_available() {
		$lines = self::available_updates();
		$hash  = md5( implode( '|', $lines ) );
		if ( self::state( 'updates_hash' ) === $hash ) {
			return;
		}
		self::state( 'updates_hash', $hash );
		if ( empty( $lines ) ) {
			return;
		}
		Honk_Notifier::emit(
			'updates_available',
			'updates-' . $hash,
			function () use ( $lines ) {
				$count = count( $lines );
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'updates_available', self::updates_available_spec( $lines ) ) + array(
						'group_key' => 'wp/health/updates-available',
						'metadata'  => array( 'updates' => $count ),
					),
					admin_url( 'update-core.php' )
				);
			}
		);
	}

	/**
	 * Available updates, one line each ("WooCommerce 11.1.2 → 11.2.0").
	 *
	 * @return string[]
	 */
	public static function available_updates() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$lines = array();

		$core = get_core_updates( array( 'dismissed' => false ) );
		if ( is_array( $core ) && ! empty( $core[0] ) && isset( $core[0]->response ) && 'upgrade' === $core[0]->response ) {
			$lines[] = 'WordPress ' . get_bloginfo( 'version' ) . ' → ' . $core[0]->current;
		}

		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) ) {
			$installed = get_plugins();
			foreach ( $plugins->response as $file => $update ) {
				$name    = isset( $installed[ $file ]['Name'] ) ? $installed[ $file ]['Name'] : $file;
				$from    = isset( $installed[ $file ]['Version'] ) ? $installed[ $file ]['Version'] . ' → ' : '';
				$lines[] = $name . ' ' . $from . ( isset( $update->new_version ) ? $update->new_version : '' );
			}
		}

		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $update ) {
				$theme   = wp_get_theme( $stylesheet );
				$name    = $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ' → ' : $stylesheet . ' ';
				$lines[] = $name . ( isset( $update['new_version'] ) ? $update['new_version'] : '' );
			}
		}
		return array_map( 'trim', $lines );
	}

	/*
	 * WP-Cron.
	 */

	/**
	 * In the dashboard, at most every 10 minutes: checks WP-Cron (when WP-Cron itself is broken, the
	 * hourly check cannot run).
	 *
	 * @return void
	 */
	public static function maybe_check_cron_from_admin() {
		if ( wp_doing_ajax() || get_transient( 'honk_cron_checked' ) ) {
			return;
		}
		set_transient( 'honk_cron_checked', 1, 10 * MINUTE_IN_SECONDS );
		self::check_cron();
	}

	/**
	 * Reports WP-Cron as a problem when the same task stays more than 30 minutes late across two
	 * checks at least 15 minutes apart (on a quiet site, tasks are late until the next visit starts
	 * WP-Cron; that alone is not a problem), and a recovery when it catches up.
	 *
	 * @param int|null $now Timestamp (tests).
	 * @return void
	 */
	public static function check_cron( $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$overdue = self::overdue_cron( $now );
		$open    = self::state( 'cron_since' );

		if ( empty( $overdue['count'] ) ) {
			self::state( 'cron_suspect', array() );
			if ( $open ) {
				self::state( 'cron_since', 0 );
				Honk_Notifier::emit(
					'cron_overdue',
					'cron-recovered-' . (int) $open,
					function () {
						return array(
							'title'      => __( 'Scheduled tasks are running again', 'honk' ),
							'message'    => __( 'Scheduled tasks are on time again.', 'honk' ),
							'group_key'  => 'wp/health/cron',
							'event_type' => 'recovery',
						);
					},
					array( 'severity' => 'success' )
				);
			}
			return;
		}
		if ( $open ) {
			return; // Already reported.
		}
		$suspect = self::state( 'cron_suspect' );
		if ( ! is_array( $suspect ) || ! isset( $suspect['oldest'], $suspect['hook'], $suspect['seen'] )
			|| (int) $suspect['oldest'] !== (int) $overdue['oldest'] || $suspect['hook'] !== $overdue['hook'] ) {
			self::state(
				'cron_suspect',
				array(
					'oldest' => (int) $overdue['oldest'],
					'hook'   => $overdue['hook'],
					'seen'   => $now,
				)
			);
			return;
		}
		if ( $now - (int) $suspect['seen'] < 15 * MINUTE_IN_SECONDS ) {
			return;
		}

		$since = (int) $overdue['oldest'];
		self::state( 'cron_since', $since );
		self::state( 'cron_suspect', array() );
		Honk_Notifier::emit(
			'cron_overdue',
			'cron-overdue-' . $since,
			function () use ( $overdue, $now ) {
				$late = max( 1, (int) round( ( $now - $overdue['oldest'] ) / MINUTE_IN_SECONDS ) );
				$spec = self::cron_spec(
					array(
						'count'    => $overdue['count'],
						'late'     => human_time_diff( 0, $late * MINUTE_IN_SECONDS ),
						'hook'     => $overdue['hook'],
						'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'cron_overdue', $spec ) + array(
						'group_key'  => 'wp/health/cron',
						'event_type' => 'problem',
						'metadata'   => array(
							'overdue'      => $overdue['count'],
							'late_minutes' => $late,
						),
					),
					admin_url( 'site-health.php' )
				);
			},
			array( 'now' => true )
		);
	}

	/**
	 * Tasks more than 30 minutes late.
	 *
	 * @param int        $now  Timestamp.
	 * @param array|null $cron Cron array (defaults to WordPress's).
	 * @return array{count: int, oldest: int, hook: string}
	 */
	public static function overdue_cron( $now, $cron = null ) {
		$cron   = null === $cron ? _get_cron_array() : $cron;
		$result = array(
			'count'  => 0,
			'oldest' => 0,
			'hook'   => '',
		);
		if ( ! is_array( $cron ) ) {
			return $result;
		}
		$limit = $now - self::CRON_LATE_MINUTES * MINUTE_IN_SECONDS;
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || (int) $timestamp > $limit || ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				$result['count'] += is_array( $events ) ? count( $events ) : 1;
				if ( 0 === $result['oldest'] || (int) $timestamp < $result['oldest'] ) {
					$result['oldest'] = (int) $timestamp;
					$result['hook']   = (string) $hook;
				}
			}
		}
		return $result;
	}

	/*
	 * Disk space.
	 */

	/**
	 * Reports the disk as a problem when less than 5% or 1 GB is free, and a recovery above 10% and
	 * 2 GB. Skipped where the host does not let PHP measure the disk.
	 *
	 * @return void
	 */
	public static function check_disk() {
		$disk = self::disk_usage( WP_CONTENT_DIR );
		if ( null === $disk ) {
			return;
		}
		$open = (bool) self::state( 'disk_low' );
		$low  = $disk['free'] < 1073741824 || $disk['free'] < 0.05 * $disk['total'];
		$ok   = $disk['free'] > 2147483648 && $disk['free'] > 0.10 * $disk['total'];

		if ( $low && ! $open ) {
			self::state( 'disk_low', time() );
			Honk_Notifier::emit(
				'disk_space_low',
				'disk-low-' . gmdate( 'Ymd' ),
				function () use ( $disk ) {
					return Honk_Details::fields( 'disk_space_low', self::disk_spec( $disk ) ) + array(
						'group_key'  => 'wp/health/disk',
						'event_type' => 'problem',
						'metadata'   => array(
							'free_bytes'  => (int) $disk['free'],
							'total_bytes' => (int) $disk['total'],
						),
					);
				}
			);
		} elseif ( $ok && $open ) {
			$since = (int) self::state( 'disk_low' );
			self::state( 'disk_low', 0 );
			Honk_Notifier::emit(
				'disk_space_low',
				'disk-ok-' . $since,
				function () use ( $disk ) {
					return array(
						'title'      => __( 'Disk space is fine again', 'honk' ),
						/* translators: %s: free space */
						'message'    => sprintf( __( '%s free.', 'honk' ), size_format( $disk['free'], 1 ) ),
						'group_key'  => 'wp/health/disk',
						'event_type' => 'recovery',
					);
				},
				array( 'severity' => 'success' )
			);
		}
	}

	/**
	 * Free and total bytes of the disk holding a directory, or null if PHP cannot tell.
	 *
	 * @param string $dir Directory.
	 * @return array{free: float, total: float}|null
	 */
	public static function disk_usage( $dir ) {
		if ( ! function_exists( 'disk_free_space' ) || ! function_exists( 'disk_total_space' ) ) {
			return null;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'disk_free_space', $disabled, true ) || in_array( 'disk_total_space', $disabled, true ) ) {
			return null;
		}
		$free  = disk_free_space( $dir );
		$total = disk_total_space( $dir );
		if ( ! is_numeric( $free ) || ! is_numeric( $total ) || $total <= 0 ) {
			return null;
		}
		return array(
			'free'  => (float) $free,
			'total' => (float) $total,
		);
	}

	/*
	 * Message outlines (Honk_Details): the same for real events and for the settings preview.
	 */

	/**
	 * Critical error (type, name: the plugin or theme; error, file, line, recovery).
	 *
	 * @param array $d Error data.
	 * @return array
	 */
	public static function fatal_spec( array $d ) {
		if ( '' !== $d['name'] ) {
			$title = 'theme' === $d['type']
				/* translators: %s: theme name */
				? sprintf( __( 'Critical error in the %s theme', 'honk' ), $d['name'] )
				/* translators: %s: plugin name */
				: sprintf( __( 'Critical error in the %s plugin', 'honk' ), $d['name'] );
		} else {
			$title = __( 'Critical error', 'honk' );
		}
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => array(
				Honk_Details::text( $d['error'], 'error' ),
				/* translators: 1: file, 2: line number */
				Honk_Details::text( sprintf( __( '%1$s, line %2$d', 'honk' ), $d['file'], $d['line'] ), 'file' ),
				Honk_Details::text( $d['recovery'] ? __( 'WordPress emailed the site administrator a link to fix it in recovery mode.', 'honk' ) : '' ),
			),
		);
	}

	/**
	 * Site Health critical issues.
	 *
	 * @param string[] $issues Labels.
	 * @return array
	 */
	public static function site_health_spec( array $issues ) {
		$count = count( $issues );
		return array(
			/* translators: %d: number of critical issues */
			'title' => array( Honk_Details::part( sprintf( _n( 'Site Health: %d critical issue', 'Site Health: %d critical issues', $count, 'honk' ), $count ) ) ),
			'lines' => array_map( array( 'Honk_Details', 'text' ), $issues ),
		);
	}

	/**
	 * Automatic updates that failed.
	 *
	 * @param string[] $failed "Name: error" lines.
	 * @return array
	 */
	public static function update_failed_spec( array $failed ) {
		$count = count( $failed );
		return array(
			/* translators: %d: number of failed updates */
			'title' => array( Honk_Details::part( sprintf( _n( '%d automatic update failed', '%d automatic updates failed', $count, 'honk' ), $count ) ) ),
			'lines' => array_map( array( 'Honk_Details', 'text' ), $failed ),
		);
	}

	/**
	 * Available updates.
	 *
	 * @param string[] $updates Lines ("WooCommerce 11.1.2 → 11.2.0").
	 * @return array
	 */
	public static function updates_available_spec( array $updates ) {
		$count = count( $updates );
		return array(
			/* translators: %d: number of updates */
			'title' => array( Honk_Details::part( sprintf( _n( '%d update available', '%d updates available', $count, 'honk' ), $count ) ) ),
			'lines' => array_map( array( 'Honk_Details', 'text' ), $updates ),
		);
	}

	/**
	 * Scheduled tasks running late (count, late: a duration, hook, disabled: DISABLE_WP_CRON).
	 *
	 * @param array $d Overdue tasks.
	 * @return array
	 */
	public static function cron_spec( array $d ) {
		return array(
			'title' => array( Honk_Details::part( __( 'Scheduled tasks are running late', 'honk' ) ) ),
			'lines' => array(
				Honk_Details::text(
					sprintf(
						/* translators: 1: number of tasks, 2: duration, e.g. "2 hours" */
						_n( '%1$d scheduled task is late, the oldest by %2$s.', '%1$d scheduled tasks are late, the oldest by %2$s.', $d['count'], 'honk' ),
						$d['count'],
						$d['late']
					)
				),
				/* translators: %s: hook name */
				Honk_Details::text( sprintf( __( 'Oldest task: %s', 'honk' ), $d['hook'] ), 'hook' ),
				Honk_Details::text(
					$d['disabled']
						? __( 'DISABLE_WP_CRON is on, so a server cron job has to run wp-cron.php. Check that it’s still running.', 'honk' )
						: __( 'WordPress runs scheduled tasks when someone visits the site. Check Tools → Site Health for loopback errors, or ask your host to set up a server cron job.', 'honk' ),
					'advice'
				),
			),
		);
	}

	/**
	 * Disk almost full (free, total: bytes).
	 *
	 * @param array $disk Disk usage.
	 * @return array
	 */
	public static function disk_spec( array $disk ) {
		return array(
			'title' => array( Honk_Details::part( __( 'Disk almost full', 'honk' ) ) ),
			'lines' => array(
				Honk_Details::text(
					sprintf(
						/* translators: 1: free space, 2: total space, 3: percent free */
						__( '%1$s free of %2$s (%3$s%%).', 'honk' ),
						size_format( $disk['free'], 1 ),
						size_format( $disk['total'], 1 ),
						number_format_i18n( 100 * $disk['free'] / $disk['total'], 1 )
					)
				),
			),
		);
	}
}
