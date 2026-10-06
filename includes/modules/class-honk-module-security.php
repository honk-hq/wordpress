<?php
/**
 * Security events: sign-ins, administrators, roles, site identity, plugins, themes, file edits.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Security module.
 */
final class Honk_Module_Security {

	const BURST_CHECK_HOOK = 'honk_login_burst_check';

	const FAILURES_OPTION = 'honk_login_failures';

	const DEVICES_META = 'honk_known_devices';

	/**
	 * Devices remembered per administrator.
	 */
	const MAX_DEVICES = 20;

	/**
	 * Plugin and theme names captured before deletion.
	 *
	 * @var array<string, string>
	 */
	private static $deleting = array();

	/**
	 * Files being edited in the dashboard: path => md5 before the edit.
	 *
	 * @var array<string, array>
	 */
	private static $editing = array();

	/**
	 * Users already reported as new administrators in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $new_admins = array();

	/**
	 * Users registered in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $registered = array();

	/**
	 * Users whose role was set with WP_User::set_role() in this request.
	 *
	 * @var array<int, bool>
	 */
	private static $set_role = array();

	/**
	 * Roles added or removed directly in this request: user id => [ [ add|remove, role ], … ].
	 *
	 * @var array<int, array>
	 */
	private static $role_ops = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 1 );
		add_action( self::BURST_CHECK_HOOK, array( __CLASS__, 'check_burst' ) );

		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_user_role' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_add_user_role' ), 20, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_remove_user_role' ), 20, 2 );
		add_action( 'granted_super_admin', array( __CLASS__, 'on_granted_super_admin' ), 20, 1 );

		add_action( 'update_option_admin_email', array( __CLASS__, 'on_identity_option' ), 20, 3 );
		add_action( 'update_option_siteurl', array( __CLASS__, 'on_identity_option' ), 20, 3 );
		add_action( 'update_option_home', array( __CLASS__, 'on_identity_option' ), 20, 3 );

		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 20, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 20, 2 );
		add_action( 'delete_plugin', array( __CLASS__, 'before_plugin_delete' ), 10, 1 );
		add_action( 'deleted_plugin', array( __CLASS__, 'on_plugin_deleted' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 20, 2 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'on_core_updated' ), 20, 1 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 20, 3 );
		add_action( 'delete_theme', array( __CLASS__, 'before_theme_delete' ), 10, 1 );
		add_action( 'deleted_theme', array( __CLASS__, 'on_theme_deleted' ), 20, 2 );

		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'before_file_edit' ), 0 );
	}

	/*
	 * Sign-in from a new device.
	 */

	/**
	 * After a successful sign-in: reports administrators on a new device or network.
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user       User.
	 * @return void
	 */
	public static function on_login( $user_login, $user ) {
		if ( ! $user instanceof WP_User || ! ( user_can( $user, 'manage_options' ) || is_super_admin( $user->ID ) ) ) {
			return;
		}
		$ip      = Honk_Notifier::client_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$device  = self::describe_user_agent( $ua );
		$network = Honk_Notifier::mask_ip( $ip );
		$result  = self::remember_device( $user->ID, wp_hash( 'honk-device|' . $device ), wp_hash( 'honk-net|' . $network ) );
		if ( ! $result['new'] ) {
			return;
		}

		$fingerprint = substr( md5( $result['device'] . $result['network'] ), 0, 12 );
		Honk_Notifier::emit(
			'admin_login_new_device',
			'login-' . $user->ID . '-' . $fingerprint,
			function () use ( $user, $device, $ip, $network, $result ) {
				$spec = self::new_device_spec(
					array(
						'login'      => $user->user_login,
						'device'     => $device,
						'ip'         => $ip,
						'network'    => $network,
						'new_device' => $result['new_device'],
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'admin_login_new_device',
						$spec,
						array(
							'group_key' => 'wp/security/admin-login',
							'metadata'  => array( 'user_id' => $user->ID ),
						)
					),
					admin_url( 'profile.php' )
				);
			}
		);
	}

	/**
	 * Records a device and network for a user. The first sign-in after installing only learns.
	 *
	 * @param int    $user_id      User id.
	 * @param string $device_hash  Hash of browser + OS.
	 * @param string $network_hash Hash of the IP network.
	 * @return array{new: bool, new_device: bool, device: string, network: string}
	 */
	public static function remember_device( $user_id, $device_hash, $network_hash ) {
		$known = get_user_meta( $user_id, self::DEVICES_META, true );
		$known = is_array( $known ) ? $known : array();
		$first = empty( $known );

		$devices  = array();
		$networks = array();
		foreach ( $known as $row ) {
			if ( isset( $row['d'], $row['n'] ) ) {
				$devices[ $row['d'] ]  = true;
				$networks[ $row['n'] ] = true;
			}
		}
		$new_device  = ! isset( $devices[ $device_hash ] );
		$new_network = ! isset( $networks[ $network_hash ] );

		$known   = array_values(
			array_filter(
				$known,
				function ( $row ) use ( $device_hash, $network_hash ) {
					return ! ( isset( $row['d'], $row['n'] ) && $row['d'] === $device_hash && $row['n'] === $network_hash );
				}
			)
		);
		$known[] = array(
			'd' => $device_hash,
			'n' => $network_hash,
			't' => time(),
		);
		$cutoff  = time() - 180 * DAY_IN_SECONDS;
		$known   = array_values(
			array_filter(
				$known,
				function ( $row ) use ( $cutoff ) {
					return isset( $row['t'] ) && $row['t'] > $cutoff;
				}
			)
		);
		$known   = array_slice( $known, -self::MAX_DEVICES );
		update_user_meta( $user_id, self::DEVICES_META, $known );

		return array(
			'new'        => ! $first && ( $new_device || $new_network ),
			'new_device' => $new_device,
			'device'     => $device_hash,
			'network'    => $network_hash,
		);
	}

	/**
	 * A coarse description of a User-Agent: "Chrome on macOS". Versions are left out so that a
	 * browser update is not a new device.
	 *
	 * @param string $ua User-Agent.
	 * @return string
	 */
	public static function describe_user_agent( $ua ) {
		$os     = 'unknown OS';
		$os_map = array(
			'iPhone'    => 'iOS',
			'iPad'      => 'iPadOS',
			'Android'   => 'Android',
			'CrOS'      => 'ChromeOS',
			'Windows'   => 'Windows',
			'Mac OS X'  => 'macOS',
			'Macintosh' => 'macOS',
			'Linux'     => 'Linux',
		);
		foreach ( $os_map as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		$browser     = 'unknown browser';
		$browser_map = array(
			'Edg/'     => 'Edge',
			'OPR/'     => 'Opera',
			'Firefox/' => 'Firefox',
			'FxiOS/'   => 'Firefox',
			'CriOS/'   => 'Chrome',
			'Chrome/'  => 'Chrome',
			'Safari/'  => 'Safari',
			'curl/'    => 'curl',
			'WP-CLI'   => 'WP-CLI',
		);
		foreach ( $browser_map as $needle => $name ) {
			if ( false !== strpos( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		if ( '' === trim( $ua ) ) {
			return 'unknown device';
		}
		return $browser . ' on ' . $os;
	}

	/*
	 * Failed sign-in bursts.
	 */

	/**
	 * Counts a failed sign-in; reports a burst (a problem) when the threshold is reached.
	 *
	 * @param string $username Username or email that was tried.
	 * @return void
	 */
	public static function on_login_failed( $username ) {
		$threshold = max( 3, (int) Honk_Settings::get( 'burst_threshold' ) );
		$minutes   = max( 1, (int) Honk_Settings::get( 'burst_minutes' ) );
		$state     = self::record_failure( time(), (string) $username, Honk_Notifier::client_ip(), $threshold, $minutes );
		if ( empty( $state['started_now'] ) ) {
			return;
		}

		$start = (int) $state['burst']['start'];
		if ( ! wp_next_scheduled( self::BURST_CHECK_HOOK ) ) {
			wp_schedule_single_event( time() + $minutes * MINUTE_IN_SECONDS, self::BURST_CHECK_HOOK );
		}
		$count     = (int) $state['window'];
		$ips       = count( $state['burst']['ips'] );
		$usernames = array_keys( $state['burst']['users'] );
		Honk_Notifier::emit(
			'login_failures_burst',
			'login-burst-' . $start,
			function () use ( $count, $minutes, $ips, $usernames ) {
				$spec = self::burst_spec(
					array(
						'count'     => $count,
						'window'    => human_time_diff( 0, $minutes * MINUTE_IN_SECONDS ),
						'ips'       => $ips,
						'usernames' => array_slice( $usernames, 0, 5 ),
					)
				);
				return Honk_Details::fields(
					'login_failures_burst',
					$spec,
					array(
						'group_key'  => 'wp/security/login-burst',
						'event_type' => 'problem',
						'metadata'   => array(
							'failures' => $count,
							'minutes'  => $minutes,
							'ips'      => $ips,
						),
					)
				);
			}
		);
	}

	/**
	 * Updates the failure counters (one-minute buckets) and the burst state.
	 *
	 * @param int    $now       Timestamp.
	 * @param string $username  Username tried.
	 * @param string $ip        Client IP.
	 * @param int    $threshold Failures that make a burst.
	 * @param int    $minutes   Window in minutes.
	 * @return array{window: int, started_now: bool, burst: array|null}
	 */
	public static function record_failure( $now, $username, $ip, $threshold, $minutes ) {
		$state = get_option( self::FAILURES_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$state = array_merge(
			array(
				'buckets' => array(),
				'burst'   => null,
			),
			$state
		);

		$minute                      = (int) floor( $now / 60 );
		$state['buckets'][ $minute ] = ( isset( $state['buckets'][ $minute ] ) ? (int) $state['buckets'][ $minute ] : 0 ) + 1;
		foreach ( array_keys( $state['buckets'] ) as $m ) {
			if ( $m <= $minute - $minutes ) {
				unset( $state['buckets'][ $m ] );
			}
		}
		$window = (int) array_sum( $state['buckets'] );

		$started_now = false;
		if ( null === $state['burst'] && $window >= $threshold ) {
			$state['burst'] = array(
				'start' => $now,
				'total' => $window - 1,
				'ips'   => array(),
				'users' => array(),
			);
			$started_now    = true;
		}
		if ( null !== $state['burst'] ) {
			++$state['burst']['total'];
			$state['burst']['last'] = $now;
			if ( '' !== $ip && count( $state['burst']['ips'] ) < 500 ) {
				$state['burst']['ips'][ substr( wp_hash( 'honk-ip|' . $ip ), 0, 16 ) ] = 1;
			}
			$username = sanitize_user( $username, true );
			if ( '' !== $username && count( $state['burst']['users'] ) < 50 ) {
				$state['burst']['users'][ $username ] = 1;
			}
		}
		update_option( self::FAILURES_OPTION, $state, false );

		$state['window']      = $window;
		$state['started_now'] = $started_now;
		return $state;
	}

	/**
	 * Scheduled after a burst: sends the recovery once a whole window passed without failures,
	 * otherwise checks again later.
	 *
	 * @return void
	 */
	public static function check_burst() {
		$minutes = max( 1, (int) Honk_Settings::get( 'burst_minutes' ) );
		$state   = get_option( self::FAILURES_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['burst'] ) ) {
			return;
		}
		$burst = $state['burst'];
		$last  = isset( $burst['last'] ) ? (int) $burst['last'] : (int) $burst['start'];
		if ( time() - $last < $minutes * MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + $minutes * MINUTE_IN_SECONDS, self::BURST_CHECK_HOOK );
			return;
		}

		$state['burst']   = null;
		$state['buckets'] = array();
		update_option( self::FAILURES_OPTION, $state, false );

		$start = (int) $burst['start'];
		$total = (int) $burst['total'];
		Honk_Notifier::emit(
			'login_failures_burst',
			'login-burst-' . $start . '-recovered',
			function () use ( $start, $last, $total ) {
				return array(
					'title'      => __( 'Failed sign-ins have stopped', 'honk-me' ),
					'message'    => self::burst_summary( $total, $start, $last ),
					'group_key'  => 'wp/security/login-burst',
					'event_type' => 'recovery',
					'metadata'   => array( 'failures' => $total ),
				);
			},
			array( 'severity' => 'success' )
		);
	}

	/**
	 * "15 failed sign-ins between 14:02 and 14:31." (or "at 14:02." within one minute).
	 *
	 * @param int $total Failed sign-ins.
	 * @param int $start First failure.
	 * @param int $last  Last failure.
	 * @return string
	 */
	private static function burst_summary( $total, $start, $last ) {
		$format = (string) get_option( 'time_format' );
		$from   = wp_date( $format, $start );
		$to     = wp_date( $format, $last );
		if ( $from === $to ) {
			/* translators: 1: number of failed sign-ins, 2: time */
			return sprintf( _n( '%1$d failed sign-in at %2$s.', '%1$d failed sign-ins at %2$s.', $total, 'honk-me' ), $total, $from );
		}
		/* translators: 1: number of failed sign-ins, 2: start time, 3: end time */
		return sprintf( _n( '%1$d failed sign-in between %2$s and %3$s.', '%1$d failed sign-ins between %2$s and %3$s.', $total, 'honk-me' ), $total, $from, $to );
	}

	/*
	 * Administrators and roles.
	 */

	/**
	 * A new user: reported when created as an administrator.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		self::$registered[ (int) $user_id ] = true;
		$user                               = get_userdata( $user_id );
		if ( $user && ( in_array( 'administrator', (array) $user->roles, true ) || user_can( $user, 'manage_options' ) ) ) {
			self::report_new_admin( $user, true );
		}
	}

	/**
	 * A role was set (WP_User::set_role(), e.g. Users → Edit): promotion to administrator, or
	 * another role change.
	 *
	 * @param int      $user_id   User id.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles (empty for a new user).
	 * @return void
	 */
	public static function on_set_user_role( $user_id, $role, $old_roles ) {
		self::$set_role[ (int) $user_id ] = true;
		$old_roles                        = array_values( (array) $old_roles );
		if ( empty( $old_roles ) || array( $role ) === $old_roles ) {
			return; // A new user (user_register reports administrators), or no change.
		}
		$user = get_userdata( $user_id );
		if ( ! $user || self::is_new_user( $user ) ) {
			return;
		}
		if ( 'administrator' === $role && ! in_array( 'administrator', $old_roles, true ) ) {
			self::report_new_admin( $user, false );
			return;
		}
		self::report_role_change( $user, $old_roles, array( $role ) );
	}

	/**
	 * A role was added. WP_User::set_role() fires this too, just before set_user_role, so it is
	 * collected and looked at only at the end of the request.
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Added role.
	 * @return void
	 */
	public static function on_add_user_role( $user_id, $role ) {
		self::collect_role_op( (int) $user_id, 'add', (string) $role );
	}

	/**
	 * A role was removed (collected like on_add_user_role()).
	 *
	 * @param int    $user_id User id.
	 * @param string $role    Removed role.
	 * @return void
	 */
	public static function on_remove_user_role( $user_id, $role ) {
		self::collect_role_op( (int) $user_id, 'remove', (string) $role );
	}

	/**
	 * Collects a role added or removed directly (WP_User::add_role() / remove_role()).
	 *
	 * @param int    $user_id User id.
	 * @param string $op      add or remove.
	 * @param string $role    Role.
	 * @return void
	 */
	private static function collect_role_op( $user_id, $op, $role ) {
		if ( empty( self::$role_ops ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_role_ops' ), 4 );
		}
		self::$role_ops[ $user_id ][] = array( $op, $role );
	}

	/**
	 * End of the request: reports roles added or removed outside WP_User::set_role() (which is
	 * reported by on_set_user_role()) for users that existed before this request.
	 *
	 * @return void
	 */
	public static function flush_role_ops() {
		$ops            = self::$role_ops;
		self::$role_ops = array();
		foreach ( $ops as $user_id => $changes ) {
			if ( isset( self::$set_role[ $user_id ] ) || isset( self::$registered[ $user_id ] ) ) {
				continue;
			}
			$user = get_userdata( $user_id );
			if ( ! $user || self::is_new_user( $user ) ) {
				continue;
			}
			$after  = array_values( (array) $user->roles );
			$before = $after;
			foreach ( array_reverse( $changes ) as $change ) {
				if ( 'add' === $change[0] ) {
					$before = array_values( array_diff( $before, array( $change[1] ) ) );
				} elseif ( ! in_array( $change[1], $before, true ) ) {
					$before[] = $change[1];
				}
			}
			if ( in_array( 'administrator', $after, true ) && ! in_array( 'administrator', $before, true ) ) {
				self::report_new_admin( $user, false );
			} else {
				self::report_role_change( $user, $before, $after );
			}
		}
	}

	/**
	 * Multisite: a user became super admin.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function on_granted_super_admin( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user ) {
			self::report_new_admin( $user, false );
		}
	}

	/**
	 * Whether the account was created in the last minute (its first roles are not "changes").
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private static function is_new_user( $user ) {
		return isset( self::$registered[ $user->ID ] ) || strtotime( $user->user_registered . ' UTC' ) >= time() - 60;
	}

	/**
	 * Reports a new administrator (Blast by default).
	 *
	 * @param WP_User $user    User.
	 * @param bool    $created Created as administrator (true) or promoted (false).
	 * @return void
	 */
	private static function report_new_admin( $user, $created ) {
		if ( isset( self::$new_admins[ $user->ID ] ) ) {
			return;
		}
		self::$new_admins[ $user->ID ] = true;
		$actor                         = Honk_Notifier::actor();
		Honk_Notifier::emit(
			'new_administrator',
			'admin-' . $user->ID . '-' . gmdate( 'YmdH' ),
			function () use ( $user, $created, $actor ) {
				$data            = Honk_Notifier::user_data( $user );
				$data['created'] = $created;
				$data['actor']   = $actor;
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'new_administrator',
						self::new_admin_spec( $data ),
						array(
							'group_key' => 'wp/security/admins',
							'metadata'  => array( 'user_id' => $user->ID ),
						)
					),
					admin_url( 'user-edit.php?user_id=' . $user->ID )
				);
			}
		);
	}

	/**
	 * Reports a role change.
	 *
	 * @param WP_User  $user User.
	 * @param string[] $from Previous roles.
	 * @param string[] $to   New roles.
	 * @return void
	 */
	private static function report_role_change( $user, array $from, array $to ) {
		sort( $from );
		sort( $to );
		if ( $from === $to ) {
			return;
		}
		$actor = Honk_Notifier::actor();
		Honk_Notifier::emit(
			'role_changed',
			'role-' . $user->ID . '-' . md5( implode( ',', $from ) . '>' . implode( ',', $to ) ) . '-' . gmdate( 'YmdH' ),
			function () use ( $user, $from, $to, $actor ) {
				$data          = Honk_Notifier::user_data( $user );
				$data['label'] = Honk_Notifier::user_label( $user, false );
				$data['from']  = self::role_names( $from );
				$data['to']    = self::role_names( $to );
				$data['actor'] = $actor;
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'role_changed',
						self::role_spec( $data ),
						array(
							'group_key' => 'wp/security/roles',
							'metadata'  => array( 'user_id' => $user->ID ),
						)
					),
					admin_url( 'user-edit.php?user_id=' . $user->ID )
				);
			}
		);
	}

	/**
	 * Translated role names, comma-separated.
	 *
	 * @param string[] $roles Role slugs.
	 * @return string
	 */
	private static function role_names( array $roles ) {
		if ( empty( $roles ) ) {
			return __( 'no role', 'honk-me' );
		}
		$names = array();
		$all   = wp_roles()->get_names();
		foreach ( $roles as $role ) {
			$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : $role;
		}
		return implode( ', ', $names );
	}

	/*
	 * Site identity.
	 */

	/**
	 * The admin email, the WordPress address or the site address changed.
	 *
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @return void
	 */
	public static function on_identity_option( $old_value, $value, $option ) {
		if ( (string) $old_value === (string) $value ) {
			return;
		}
		$actor = Honk_Notifier::actor();
		Honk_Notifier::emit(
			'site_identity_changed',
			'identity-' . $option . '-' . md5( (string) $value ),
			function () use ( $old_value, $value, $option, $actor ) {
				$spec = self::identity_spec(
					array(
						'option' => $option,
						'old'    => (string) $old_value,
						'new'    => (string) $value,
						'actor'  => $actor,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields(
						'site_identity_changed',
						$spec,
						array(
							'group_key' => 'wp/security/site-identity',
							'metadata'  => array( 'option' => $option ),
						)
					),
					admin_url( 'options-general.php' )
				);
			}
		);
	}

	/*
	 * Plugins and themes.
	 */

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 * @return void
	 */
	public static function on_plugin_activated( $plugin, $network_wide = false ) {
		self::report_plugin( 'activated', $plugin, self::plugin_name( $plugin ), $network_wide );
	}

	/**
	 * A plugin was deactivated. Deactivating Honk itself is sent right away, because its queue
	 * stops with it.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network deactivation.
	 * @return void
	 */
	public static function on_plugin_deactivated( $plugin, $network_wide = false ) {
		if ( plugin_basename( HONK_FILE ) === $plugin ) {
			Honk_Queue::deliver_before_exit();
		}
		self::report_plugin( 'deactivated', $plugin, self::plugin_name( $plugin ), $network_wide );
	}

	/**
	 * Remembers a plugin's name before its files are deleted.
	 *
	 * @param string $plugin Plugin file.
	 * @return void
	 */
	public static function before_plugin_delete( $plugin ) {
		self::$deleting[ 'plugin:' . $plugin ] = self::plugin_name( $plugin );
	}

	/**
	 * A plugin was deleted.
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the files were deleted.
	 * @return void
	 */
	public static function on_plugin_deleted( $plugin, $deleted ) {
		if ( ! $deleted ) {
			return;
		}
		$name = isset( self::$deleting[ 'plugin:' . $plugin ] ) ? self::$deleting[ 'plugin:' . $plugin ] : $plugin;
		self::report_plugin( 'deleted', $plugin, $name, false );
	}

	/**
	 * Reports a plugin change.
	 *
	 * @param string $action       activated, deactivated, deleted or installed.
	 * @param string $plugin       Plugin file.
	 * @param string $name         Plugin name.
	 * @param bool   $network_wide Network-wide.
	 * @return void
	 */
	private static function report_plugin( $action, $plugin, $name, $network_wide ) {
		$actor = Honk_Notifier::actor();
		Honk_Notifier::emit(
			'plugin_changed',
			'plugin-' . $action . '-' . md5( $plugin ) . '-' . gmdate( 'YmdHi' ),
			function () use ( $action, $plugin, $name, $network_wide, $actor ) {
				$spec = self::plugin_spec(
					array(
						'action'  => $action,
						'name'    => $name,
						'file'    => $plugin,
						'network' => $network_wide,
						'actor'   => $actor,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'plugin_changed', $spec ) + array(
						'group_key' => 'wp/security/plugins',
						'metadata'  => array(
							'plugin' => $plugin,
							'action' => $action,
						),
					),
					admin_url( 'plugins.php' )
				);
			}
		);
	}

	/**
	 * Name of an installed plugin.
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private static function plugin_name( $plugin ) {
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_readable( $file ) ) {
			$data = get_plugin_data( $file, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return dirname( $plugin ) !== '.' ? dirname( $plugin ) : basename( $plugin, '.php' );
	}

	/**
	 * Installs and updates through the upgrader (dashboard, WP-CLI). Automatic updates are
	 * reported by the health module from automatic_updates_complete.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader.
	 * @param array       $hook_extra Type, action, plugins/themes.
	 * @return void
	 */
	public static function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || empty( $hook_extra['action'] ) ) {
			return;
		}
		$type   = $hook_extra['type'];
		$action = $hook_extra['action'];

		if ( 'install' === $action && in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$folder = is_array( $upgrader->result ) && ! empty( $upgrader->result['destination_name'] ) ? (string) $upgrader->result['destination_name'] : '';
			$name   = $folder;
			if ( 'plugin' === $type && ! empty( $upgrader->new_plugin_data['Name'] ) ) {
				$name = $upgrader->new_plugin_data['Name'];
			} elseif ( 'theme' === $type && ! empty( $upgrader->new_theme_data['Name'] ) ) {
				$name = $upgrader->new_theme_data['Name'];
			}
			if ( '' === $name ) {
				return;
			}
			if ( 'plugin' === $type ) {
				self::report_plugin( 'installed', '' !== $folder ? $folder : $name, $name, false );
			} else {
				self::report_theme( 'installed', $name, '' );
			}
			return;
		}

		if ( 'update' !== $action || doing_action( 'wp_maybe_auto_update' ) ) {
			return;
		}
		$items = array();
		if ( 'plugin' === $type ) {
			$plugins = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );
			foreach ( $plugins as $plugin ) {
				$items[] = self::plugin_name( $plugin ) . self::plugin_version( $plugin );
			}
		} elseif ( 'theme' === $type ) {
			$themes = isset( $hook_extra['themes'] ) ? (array) $hook_extra['themes'] : ( isset( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );
			foreach ( $themes as $stylesheet ) {
				$theme   = wp_get_theme( $stylesheet );
				$items[] = $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : $stylesheet;
			}
		}
		if ( $items ) {
			self::report_updates( $type, $items );
		}
	}

	/**
	 * Version suffix of an installed plugin (" 1.2.3").
	 *
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private static function plugin_version( $plugin ) {
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( is_readable( $file ) && function_exists( 'get_plugin_data' ) ) {
			$data = get_plugin_data( $file, false, false );
			return empty( $data['Version'] ) ? '' : ' ' . $data['Version'];
		}
		return '';
	}

	/**
	 * WordPress itself was updated from the dashboard or WP-CLI.
	 *
	 * @param string $version New version.
	 * @return void
	 */
	public static function on_core_updated( $version ) {
		if ( doing_action( 'wp_maybe_auto_update' ) ) {
			return;
		}
		self::report_updates( 'core', array( 'WordPress ' . $version ) );
	}

	/**
	 * Reports installed updates (Light honk by default).
	 *
	 * @param string   $type  core, plugin or theme.
	 * @param string[] $items Names with versions.
	 * @param bool     $auto  Whether WordPress installed them automatically.
	 * @return void
	 */
	public static function report_updates( $type, array $items, $auto = false ) {
		$actor = $auto ? '' : Honk_Notifier::actor();
		Honk_Notifier::emit(
			'updates_installed',
			'updated-' . $type . '-' . md5( implode( '|', $items ) ),
			function () use ( $type, $items, $auto, $actor ) {
				$spec = self::updates_spec(
					array(
						'type'  => $type,
						'items' => $items,
						'auto'  => $auto,
						'actor' => $actor,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'updates_installed', $spec, array( 'group_key' => 'wp/security/updates' ) ),
					admin_url( 'update-core.php' )
				);
			}
		);
	}

	/**
	 * The active theme changed.
	 *
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Previous theme.
	 * @return void
	 */
	public static function on_switch_theme( $new_name, $new_theme, $old_theme ) {
		$old = $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '';
		self::report_theme( 'switched', (string) $new_name, (string) $old );
	}

	/**
	 * Remembers a theme's name before its files are deleted.
	 *
	 * @param string $stylesheet Theme directory.
	 * @return void
	 */
	public static function before_theme_delete( $stylesheet ) {
		$theme                                    = wp_get_theme( $stylesheet );
		self::$deleting[ 'theme:' . $stylesheet ] = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
	}

	/**
	 * A theme was deleted.
	 *
	 * @param string $stylesheet Theme directory.
	 * @param bool   $deleted    Whether the files were deleted.
	 * @return void
	 */
	public static function on_theme_deleted( $stylesheet, $deleted ) {
		if ( $deleted ) {
			$name = isset( self::$deleting[ 'theme:' . $stylesheet ] ) ? self::$deleting[ 'theme:' . $stylesheet ] : $stylesheet;
			self::report_theme( 'deleted', $name, '' );
		}
	}

	/**
	 * Reports a theme change.
	 *
	 * @param string $action switched, installed or deleted.
	 * @param string $name   Theme name.
	 * @param string $old    Previous theme (switched).
	 * @return void
	 */
	private static function report_theme( $action, $name, $old ) {
		$actor = Honk_Notifier::actor();
		Honk_Notifier::emit(
			'theme_changed',
			'theme-' . $action . '-' . md5( $name ) . '-' . gmdate( 'YmdHi' ),
			function () use ( $action, $name, $old, $actor ) {
				$spec = self::theme_spec(
					array(
						'action'   => $action,
						'name'     => $name,
						'previous' => $old,
						'actor'    => $actor,
					)
				);
				return Honk_Notifier::with_link(
					Honk_Details::fields( 'theme_changed', $spec ) + array(
						'group_key' => 'wp/security/themes',
						'metadata'  => array( 'action' => $action ),
					),
					admin_url( 'themes.php' )
				);
			}
		);
	}

	/*
	 * File edits in the dashboard (Appearance → Theme File Editor, Plugins → Plugin File Editor).
	 */

	/**
	 * Before WordPress saves a file from the editor: remember the file and its checksum. The
	 * message is sent at shutdown only if the file actually changed (WordPress reverts edits that
	 * break the site).
	 *
	 * @return void
	 */
	public static function before_file_edit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below with the editor's own nonce.
		$file   = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $file || false !== strpos( $file, '..' ) ) {
			return;
		}
		if ( '' !== $theme ) {
			if ( ! wp_verify_nonce( $nonce, 'edit-theme_' . $theme . '_' . $file ) || ! current_user_can( 'edit_themes' ) ) {
				return;
			}
			$path = get_theme_root( $theme ) . '/' . $theme . '/' . $file;
			$what = wp_get_theme( $theme )->get( 'Name' );
		} elseif ( '' !== $plugin ) {
			if ( ! wp_verify_nonce( $nonce, 'edit-plugin_' . $file ) || ! current_user_can( 'edit_plugins' ) ) {
				return;
			}
			$path = WP_PLUGIN_DIR . '/' . $file;
			$what = self::plugin_name( $plugin );
		} else {
			return;
		}
		self::$editing[ $path ] = array(
			'md5'  => is_readable( $path ) ? md5_file( $path ) : '',
			'file' => $file,
			'what' => $what,
			'kind' => '' !== $theme ? 'theme' : 'plugin',
		);
		add_action( 'shutdown', array( __CLASS__, 'after_file_edit' ), 1 );
	}

	/**
	 * At shutdown: reports edited files whose content changed.
	 *
	 * @return void
	 */
	public static function after_file_edit() {
		foreach ( self::$editing as $path => $edit ) {
			clearstatcache( true, $path );
			$after = is_readable( $path ) ? md5_file( $path ) : '';
			if ( $after === $edit['md5'] ) {
				continue;
			}
			$actor = Honk_Notifier::actor();
			Honk_Notifier::emit(
				'file_edited',
				'edit-' . md5( $path ) . '-' . $after,
				function () use ( $edit, $actor ) {
					$spec = self::file_edit_spec(
						array(
							'kind'  => $edit['kind'],
							'what'  => $edit['what'],
							'file'  => $edit['file'],
							'actor' => $actor,
						)
					);
					return Honk_Notifier::with_link(
						Honk_Details::fields( 'file_edited', $spec ) + array(
							'group_key' => 'wp/security/file-edits',
							'metadata'  => array( 'file' => $edit['file'] ),
						),
						admin_url( 'theme' === $edit['kind'] ? 'theme-editor.php' : 'plugin-editor.php' )
					);
				}
			);
		}
		self::$editing = array();
	}

	/*
	 * Message outlines (Honk_Details): the same for real events and for the settings preview.
	 */

	/**
	 * Administrator signed in from a new device (login, device, ip, network, new_device).
	 *
	 * @param array $d Sign-in data.
	 * @return array
	 */
	public static function new_device_spec( array $d ) {
		return array(
			'title' => array(
				/* translators: %s: user login */
				Honk_Details::part( sprintf( __( '%s signed in from a new device', 'honk-me' ), $d['login'] ), 'username' ),
				Honk_Details::part( __( 'An administrator signed in from a new device', 'honk-me' ), array(), 'username' ),
			),
			'lines' => array(
				/* translators: %s: browser and operating system, e.g. "Chrome on macOS" */
				Honk_Details::text( sprintf( __( 'Device: %s', 'honk-me' ), $d['device'] ), 'device' ),
				Honk_Details::line(
					array(
						/* translators: %s: IP address or network, e.g. 203.0.113.0/24 */
						Honk_Details::part( '' !== $d['ip'] ? sprintf( __( 'Network: %s', 'honk-me' ), $d['ip'] ) : '', 'ip' ),
						/* translators: %s: IP address or network, e.g. 203.0.113.0/24 */
						Honk_Details::part( '' !== $d['network'] && '' === $d['ip'] ? sprintf( __( 'Network: %s', 'honk-me' ), $d['network'] ) : '', 'network' ),
						/* translators: %s: IP address or network, e.g. 203.0.113.0/24 */
						Honk_Details::part( '' !== $d['network'] && '' !== $d['ip'] ? sprintf( __( 'Network: %s', 'honk-me' ), $d['network'] ) : '', 'network', 'ip' ),
					)
				),
				Honk_Details::text( $d['new_device'] ? __( 'This account hasn’t used this browser and device before.', 'honk-me' ) : __( 'This account hasn’t signed in from this network before.', 'honk-me' ) ),
				Honk_Details::text( __( 'If this wasn’t you, change the password and click “Log Out Everywhere Else” in Users → Profile.', 'honk-me' ) ),
			),
		);
	}

	/**
	 * Many failed sign-ins (count, window, ips, usernames).
	 *
	 * @param array $d Burst data.
	 * @return array
	 */
	public static function burst_spec( array $d ) {
		$lines = array(
			Honk_Details::text(
				sprintf(
					/* translators: 1: number of failed sign-ins, 2: duration, e.g. "5 mins" */
					_n( '%1$d failed sign-in within %2$s.', '%1$d failed sign-ins within %2$s.', $d['count'], 'honk-me' ),
					$d['count'],
					$d['window']
				)
			),
			Honk_Details::text(
				sprintf(
					/* translators: %d: number of IP addresses */
					_n( 'From %d IP address.', 'From %d IP addresses.', $d['ips'], 'honk-me' ),
					$d['ips']
				),
				'ip_count'
			),
		);
		if ( ! empty( $d['usernames'] ) ) {
			/* translators: %s: comma-separated usernames */
			$lines[] = Honk_Details::text( sprintf( __( 'Usernames tried: %s', 'honk-me' ), implode( ', ', $d['usernames'] ) ), 'usernames' );
		}
		$lines[] = Honk_Details::text( __( 'You’ll get an all-clear once they stop.', 'honk-me' ) );
		return array(
			'title' => array( Honk_Details::part( __( 'Many failed sign-ins', 'honk-me' ) ) ),
			'lines' => $lines,
		);
	}

	/**
	 * New administrator (Honk_Notifier::user_data() plus created and actor).
	 *
	 * @param array $d User data.
	 * @return array
	 */
	public static function new_admin_spec( array $d ) {
		$who = Honk_Notifier::user_line( $d, '' );
		return array(
			/* translators: %s: user login */
			'title' => array( Honk_Details::part( sprintf( __( 'New administrator: %s', 'honk-me' ), $d['login'] ) ) ),
			'lines' => array(
				$who + array(
					'wrap' => $d['created']
						/* translators: 1: user, 2: who did it */
						? sprintf( __( '%1$s was created as an administrator by %2$s.', 'honk-me' ), "\x01", $d['actor'] )
						/* translators: 1: user, 2: who did it */
						: sprintf( __( '%1$s was made an administrator by %2$s.', 'honk-me' ), "\x01", $d['actor'] ),
				),
				Honk_Details::text( __( 'If you didn’t expect this, check the account now.', 'honk-me' ) ),
			),
		);
	}

	/**
	 * Role changed (Honk_Notifier::user_data() plus label, from, to and actor).
	 *
	 * @param array $d Role change data.
	 * @return array
	 */
	public static function role_spec( array $d ) {
		$who        = Honk_Notifier::user_line( $d, '' );
		$who['any'] = array( 'name', 'email' );
		return array(
			/* translators: %s: user */
			'title' => array( Honk_Details::part( sprintf( __( 'Role changed for %s', 'honk-me' ), $d['label'] ) ) ),
			'lines' => array(
				/* translators: 1: previous value, 2: new value, 3: who did it */
				Honk_Details::text( sprintf( __( '%1$s → %2$s, by %3$s.', 'honk-me' ), $d['from'], $d['to'], $d['actor'] ) ),
				$who,
			),
		);
	}

	/**
	 * Admin email or site address changed (option, old, new, actor).
	 *
	 * @param array $d Change data.
	 * @return array
	 */
	public static function identity_spec( array $d ) {
		if ( 'admin_email' === $d['option'] ) {
			$title = __( 'Administration email address changed', 'honk-me' );
			$what  = Honk_Details::line(
				array(
					/* translators: 1: previous value, 2: new value, 3: who did it */
					Honk_Details::part( sprintf( __( '%1$s → %2$s, by %3$s.', 'honk-me' ), $d['old'], $d['new'], $d['actor'] ), 'emails' ),
					/* translators: %s: who did it */
					Honk_Details::part( sprintf( __( 'Changed by %s.', 'honk-me' ), $d['actor'] ), array(), 'emails' ),
				)
			);
		} else {
			$title = 'siteurl' === $d['option'] ? __( 'WordPress address (URL) changed', 'honk-me' ) : __( 'Site address (URL) changed', 'honk-me' );
			/* translators: 1: previous value, 2: new value, 3: who did it */
			$what = Honk_Details::text( sprintf( __( '%1$s → %2$s, by %3$s.', 'honk-me' ), $d['old'], $d['new'], $d['actor'] ) );
		}
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => array(
				$what,
				Honk_Details::text( __( 'If you didn’t expect this, check Settings → General now.', 'honk-me' ) ),
			),
		);
	}

	/**
	 * Plugin installed, activated, deactivated or deleted (action, name, file, network, actor).
	 *
	 * @param array $d Plugin data.
	 * @return array
	 */
	public static function plugin_spec( array $d ) {
		switch ( $d['action'] ) {
			case 'activated':
				/* translators: %s: plugin name */
				$title = sprintf( __( 'Plugin activated: %s', 'honk-me' ), $d['name'] );
				break;
			case 'deactivated':
				/* translators: %s: plugin name */
				$title = sprintf( __( 'Plugin deactivated: %s', 'honk-me' ), $d['name'] );
				break;
			case 'deleted':
				/* translators: %s: plugin name */
				$title = sprintf( __( 'Plugin deleted: %s', 'honk-me' ), $d['name'] );
				break;
			default:
				/* translators: %s: plugin name */
				$title = sprintf( __( 'Plugin installed: %s', 'honk-me' ), $d['name'] );
		}
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => array(
				Honk_Details::line(
					array(
						/* translators: %s: who did it */
						Honk_Details::part( sprintf( __( 'By %s.', 'honk-me' ), $d['actor'] ), 'actor' ),
						Honk_Details::part( $d['network'] ? __( 'Network-wide.', 'honk-me' ) : '' ),
					),
					' '
				),
				Honk_Details::text( $d['file'], 'file' ),
			),
		);
	}

	/**
	 * Theme switched, installed or deleted (action, name, previous, actor).
	 *
	 * @param array $d Theme data.
	 * @return array
	 */
	public static function theme_spec( array $d ) {
		switch ( $d['action'] ) {
			case 'switched':
				/* translators: %s: theme name */
				$title = sprintf( __( 'Theme switched to %s', 'honk-me' ), $d['name'] );
				break;
			case 'deleted':
				/* translators: %s: theme name */
				$title = sprintf( __( 'Theme deleted: %s', 'honk-me' ), $d['name'] );
				break;
			default:
				/* translators: %s: theme name */
				$title = sprintf( __( 'Theme installed: %s', 'honk-me' ), $d['name'] );
		}
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => array(
				/* translators: %s: who did it */
				Honk_Details::text( sprintf( __( 'By %s.', 'honk-me' ), $d['actor'] ), 'actor' ),
				/* translators: %s: previous theme name */
				Honk_Details::text( '' !== $d['previous'] ? sprintf( __( 'Previous theme: %s', 'honk-me' ), $d['previous'] ) : '', 'previous' ),
			),
		);
	}

	/**
	 * A theme or plugin file edited in the dashboard (kind, what, file, actor).
	 *
	 * @param array $d Edit data.
	 * @return array
	 */
	public static function file_edit_spec( array $d ) {
		return array(
			'title' => array(
				Honk_Details::part(
					'theme' === $d['kind']
						/* translators: %s: theme name */
						? sprintf( __( 'Theme file edited: %s', 'honk-me' ), $d['what'] )
						/* translators: %s: plugin name */
						: sprintf( __( 'Plugin file edited: %s', 'honk-me' ), $d['what'] )
				),
			),
			'lines' => array(
				Honk_Details::line(
					array(
						/* translators: 1: file, 2: who did it */
						Honk_Details::part( sprintf( __( '%1$s was edited in the file editor by %2$s.', 'honk-me' ), $d['file'], $d['actor'] ), 'actor' ),
						/* translators: %s: file */
						Honk_Details::part( sprintf( __( '%s was edited in the file editor.', 'honk-me' ), $d['file'] ), array(), 'actor' ),
					)
				),
				Honk_Details::text( __( 'Tip: to turn off the file editors, set DISALLOW_FILE_EDIT to true in wp-config.php.', 'honk-me' ), 'tip' ),
			),
		);
	}

	/**
	 * Updates installed (type, items, auto, actor).
	 *
	 * @param array $d Update data.
	 * @return array
	 */
	public static function updates_spec( array $d ) {
		$count = count( $d['items'] );
		switch ( $d['type'] ) {
			case 'core':
				$title = __( 'WordPress updated', 'honk-me' );
				break;
			case 'theme':
				/* translators: %d: number of themes */
				$title = sprintf( _n( '%d theme updated', '%d themes updated', $count, 'honk-me' ), $count );
				break;
			default:
				/* translators: %d: number of plugins */
				$title = sprintf( _n( '%d plugin updated', '%d plugins updated', $count, 'honk-me' ), $count );
		}
		$lines = array();
		foreach ( $d['items'] as $item ) {
			$lines[] = Honk_Details::text( $item );
		}
		/* translators: %s: who did it */
		$lines[] = $d['auto'] ? Honk_Details::text( __( 'Updated automatically.', 'honk-me' ) ) : Honk_Details::text( sprintf( __( 'By %s.', 'honk-me' ), $d['actor'] ), 'actor' );
		return array(
			'title' => array( Honk_Details::part( $title ) ),
			'lines' => $lines,
		);
	}
}
