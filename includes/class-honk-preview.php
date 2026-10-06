<?php
/**
 * The settings screen's previews: each event's message outline, built from sample data by the
 * same functions that build the real messages.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sample outlines for the settings screen.
 */
final class Honk_Preview {

	/**
	 * Outlines of this request, by event id.
	 *
	 * @var array|null
	 */
	private static $specs = null;

	/**
	 * The outline of every event that can be sent on this site, in the notification language.
	 *
	 * @return array<string, array>
	 */
	public static function specs() {
		if ( null === self::$specs ) {
			self::$specs = Honk_I18n::with_locale(
				function () {
					$out = array();
					foreach ( array_keys( Honk_Events::DEFAULTS ) as $id ) {
						if ( Honk_Events::is_available( $id ) ) {
							$out[ $id ] = self::spec( $id );
						}
					}
					return $out;
				}
			);
		}
		return self::$specs;
	}

	/**
	 * Forgets the outlines (tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$specs = null;
	}

	/**
	 * Details that are on in the preview when the screen loads: the saved choices, and for form
	 * fields "field:<form>:<key>" pseudo-details (on unless left out).
	 *
	 * @param string $event_id Event id.
	 * @return array<string, bool>
	 */
	public static function states( $event_id ) {
		$states = Honk_Details::states( $event_id );
		if ( in_array( $event_id, Honk_Details::FORM_FIELD_EVENTS, true ) ) {
			foreach ( Honk_Module_Forms::forms( $event_id ) as $form_id => $form ) {
				$skip = Honk_Details::skipped_fields( $event_id, (string) $form_id );
				foreach ( array_keys( $form['fields'] ) as $key ) {
					$states[ self::field_fact( $form_id, $key ) ] = ! in_array( Honk_Details::field_key( $key ), $skip, true ) && ! empty( $states['values'] );
				}
			}
		}
		return $states;
	}

	/**
	 * The pseudo-detail of a form field in the preview.
	 *
	 * @param string $form_id Form id.
	 * @param string $key     Field key.
	 * @return string
	 */
	public static function field_fact( $form_id, $key ) {
		return 'field:' . Honk_Details::form_key( $form_id ) . ':' . Honk_Details::field_key( $key );
	}

	/**
	 * One event's sample outline.
	 *
	 * @param string $event_id Event id.
	 * @return array
	 */
	public static function spec( $event_id ) {
		$person = self::person();
		$actor  = self::actor();
		switch ( $event_id ) {
			case 'admin_login_new_device':
				return Honk_Module_Security::new_device_spec(
					array(
						'login'      => $actor,
						'device'     => 'Firefox on Windows',
						'ip'         => '203.0.113.77',
						'network'    => '203.0.113.0/24',
						'new_device' => true,
					)
				);
			case 'login_failures_burst':
				return Honk_Module_Security::burst_spec(
					array(
						'count'     => 12,
						'window'    => human_time_diff( 0, max( 1, (int) Honk_Settings::get( 'burst_minutes' ) ) * MINUTE_IN_SECONDS ),
						'ips'       => 3,
						'usernames' => array( 'admin', 'test' ),
					)
				);
			case 'new_administrator':
				return Honk_Module_Security::new_admin_spec(
					self::user(
						array(
							'created' => true,
							'actor'   => $actor,
						)
					)
				);
			case 'role_changed':
				return Honk_Module_Security::role_spec(
					self::user(
						array(
							'label' => $person['login'],
							'from'  => self::role( 'author' ),
							'to'    => self::role( 'editor' ),
							'actor' => $actor,
						)
					)
				);
			case 'site_identity_changed':
				return Honk_Module_Security::identity_spec(
					array(
						'option' => 'admin_email',
						'old'    => 'office@example.com',
						'new'    => $person['email'],
						'actor'  => $actor,
					)
				);
			case 'plugin_changed':
				return Honk_Module_Security::plugin_spec(
					array(
						'action'  => 'activated',
						'name'    => 'Contact Form 7',
						'file'    => 'contact-form-7/wp-contact-form-7.php',
						'network' => false,
						'actor'   => $actor,
					)
				);
			case 'theme_changed':
				return Honk_Module_Security::theme_spec(
					array(
						'action'   => 'switched',
						'name'     => 'Twenty Twenty-Five',
						'previous' => 'Twenty Twenty-Four',
						'actor'    => $actor,
					)
				);
			case 'file_edited':
				return Honk_Module_Security::file_edit_spec(
					array(
						'kind'  => 'theme',
						'what'  => 'Twenty Twenty-Five',
						'file'  => 'twentytwentyfive/functions.php',
						'actor' => $actor,
					)
				);
			case 'updates_installed':
				return Honk_Module_Security::updates_spec(
					array(
						'type'  => 'plugin',
						'items' => array( 'WooCommerce 11.2.0', 'Contact Form 7 6.1.2' ),
						'auto'  => false,
						'actor' => $actor,
					)
				);
			case 'fatal_error':
				return Honk_Module_Health::fatal_spec(
					array(
						'type'     => 'plugin',
						'name'     => self::example_plugin(),
						'error'    => 'Uncaught Error: Call to undefined function example_boot()',
						'file'     => 'wp-content/plugins/example/example.php',
						'line'     => 12,
						'recovery' => true,
					)
				);
			case 'site_health_critical':
				/* translators: sample Site Health issue in the notification preview */
				return Honk_Module_Health::site_health_spec( array( __( 'Your site couldn’t complete a loopback request', 'honk' ) ) );
			case 'auto_update_failed':
				/* translators: sample update error in the notification preview */
				return Honk_Module_Health::update_failed_spec( array( self::example_plugin() . ': ' . __( 'Download failed.', 'honk' ) ) );
			case 'updates_available':
				return Honk_Module_Health::updates_available_spec( array( 'WooCommerce 11.1.2 → 11.2.0', 'Contact Form 7 6.1.1 → 6.1.2' ) );
			case 'cron_overdue':
				return Honk_Module_Health::cron_spec(
					array(
						'count'    => 3,
						'late'     => human_time_diff( 0, 2 * HOUR_IN_SECONDS ),
						'hook'     => 'woocommerce_cleanup_sessions',
						'disabled' => false,
					)
				);
			case 'disk_space_low':
				return Honk_Module_Health::disk_spec(
					array(
						'free'  => 858993459,
						'total' => 42949672960,
					)
				);
			case 'user_registered':
				return Honk_Module_Content::registration_spec( self::user( array( 'roles' => self::role( 'subscriber' ) ) ) );
			case 'comment_pending':
				return Honk_Module_Content::comment_spec(
					array(
						'post'  => self::post_title(),
						'name'  => $person['name'],
						'email' => $person['email'],
						/* translators: sample comment in the notification preview */
						'text'  => __( 'Do you also ship abroad?', 'honk' ),
					)
				);
			case 'post_pending':
			case 'post_published':
				$type = function_exists( 'get_post_type_object' ) ? get_post_type_object( 'post' ) : null;
				return Honk_Module_Content::post_spec(
					array(
						'title'   => self::post_title(),
						'type'    => $type && isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : 'Post',
						'author'  => $actor,
						/* translators: sample post excerpt in the notification preview */
						'excerpt' => __( 'All linen is on sale until Sunday.', 'honk' ),
					),
					'post_pending' === $event_id
				);
			case 'woo_new_order':
				return Honk_Module_Woocommerce::new_order_spec( self::order() );
			case 'woo_order_status':
				return Honk_Module_Woocommerce::status_spec(
					self::order(
						array(
							'from' => self::status( 'processing' ),
							'to'   => self::status( 'completed' ),
						)
					)
				);
			case 'woo_payment_failed':
				return Honk_Module_Woocommerce::payment_failed_spec( self::order() );
			case 'woo_refund':
				return Honk_Module_Woocommerce::refund_spec(
					self::order(
						array(
							'amount' => Honk_Notifier::money( 20, self::currency() ),
							'full'   => false,
							/* translators: sample refund reason in the notification preview */
							'reason' => __( 'Damaged in transit', 'honk' ),
						)
					)
				);
			case 'woo_low_stock':
			case 'woo_out_of_stock':
				$low = 'woo_low_stock' === $event_id;
				return Honk_Module_Woocommerce::stock_spec(
					array(
						'state' => $low ? 'low' : 'out',
						'qty'   => $low ? 2 : 0,
						'name'  => self::product( 0 ),
						'sku'   => 'APR-02',
					)
				);
			case 'woo_new_customer':
				return Honk_Module_Woocommerce::customer_spec( self::user() );
			case 'woo_new_review':
				return Honk_Module_Woocommerce::review_spec(
					array(
						'product' => self::product( 0 ),
						'rating'  => 4,
						'pending' => true,
						'name'    => $person['name'],
						'email'   => $person['email'],
						/* translators: sample product review in the notification preview */
						'text'    => __( 'Lovely fabric, and it washes well.', 'honk' ),
					)
				);
			case 'woo_subscription_failed':
				return Honk_Module_Woocommerce::subscription_spec(
					array(
						'number'  => '300',
						'amount'  => Honk_Notifier::money( 19, self::currency() ),
						'renewal' => '301',
						'name'    => $person['name'],
						'email'   => $person['email'],
					)
				);
			case 'woo_daily_summary':
				return Honk_Module_Woocommerce::summary_spec(
					array(
						'date'    => wp_date( (string) get_option( 'date_format' ), time() - DAY_IN_SECONDS ),
						'orders'  => 12,
						'items'   => 19,
						'revenue' => Honk_Notifier::money( 1048, self::currency() ),
						'refunds' => Honk_Notifier::money( 36, self::currency() ),
					)
				);
		}
		if ( 'forms' === Honk_Events::section( $event_id ) ) {
			return self::form( $event_id );
		}
		return array( 'title' => array( Honk_Details::part( Honk_Events::label( $event_id )[0] ) ) );
	}

	/**
	 * A form submission: the first form the plugin lists, with sample answers, or a sample form.
	 * Each field's line also needs its own "field:<form>:<key>" pseudo-detail.
	 *
	 * @param string $event_id Event id.
	 * @return array
	 */
	private static function form( $event_id ) {
		$person = self::person();
		$forms  = Honk_Module_Forms::forms( $event_id );
		foreach ( $forms as $form_id => $form ) {
			if ( empty( $form['fields'] ) ) {
				continue;
			}
			$spec = Honk_Module_Forms::form_spec( '' !== $form['title'] ? $form['title'] : __( 'Form', 'honk' ), array() );
			foreach ( $form['fields'] as $key => $field ) {
				$lines = Honk_Module_Forms::field_lines(
					array(
						array(
							'label' => $field['label'],
							'value' => self::answer( $field, (string) $key ),
							'type'  => $field['type'],
						),
					)
				);
				if ( $lines ) {
					$spec['lines'][] = Honk_Details::text( $lines[0], array( 'values', self::field_fact( (string) $form_id, (string) $key ) ) );
				}
			}
			return $spec;
		}
		/* translators: sample form name in the notification preview */
		$name = __( 'Contact', 'honk' );
		return Honk_Module_Forms::form_spec(
			$name,
			Honk_Module_Forms::field_lines(
				array(
					array(
						'label' => __( 'Name', 'honk' ),
						'value' => $person['name'],
					),
					array(
						/* translators: a form field's label in the notification preview */
						'label' => __( 'Email', 'honk' ),
						'value' => $person['email'],
					),
					array(
						/* translators: a form field's label in the notification preview */
						'label' => __( 'Message', 'honk' ),
						'value' => self::message(),
					),
				)
			)
		);
	}

	/**
	 * A sample answer for a form field, by its type (and, for plain text fields, its name).
	 *
	 * @param array  $field label, type.
	 * @param string $key   Field key.
	 * @return string
	 */
	private static function answer( array $field, $key ) {
		$person = self::person();
		$type   = strtolower( $field['type'] );
		$hint   = strtolower( $key . ' ' . $field['label'] );
		if ( 'email' === $type || false !== strpos( $hint, 'mail' ) ) {
			return $person['email'];
		}
		if ( in_array( $type, array( 'tel', 'phone' ), true ) || false !== strpos( $hint, 'phone' ) ) {
			return $person['phone'];
		}
		if ( 'textarea' === $type || false !== strpos( $hint, 'message' ) ) {
			return self::message();
		}
		if ( 'name' === $type || false !== strpos( $hint, 'name' ) ) {
			return $person['name'];
		}
		if ( in_array( $type, array( 'url', 'website' ), true ) ) {
			return 'https://example.com';
		}
		if ( in_array( $type, array( 'file', 'file-upload', 'fileupload', 'upload' ), true ) ) {
			return 'example.pdf';
		}
		return '…';
	}

	/**
	 * The sample person (customer, user, commenter).
	 *
	 * @return array{name: string, login: string, email: string, phone: string, city: string}
	 */
	private static function person() {
		return array(
			/* translators: sample customer name in the notification preview */
			'name'  => __( 'Jane Doe', 'honk' ),
			/* translators: sample username in the notification preview: the sample first name in lowercase */
			'login' => __( 'jane', 'honk' ),
			/* translators: sample email address in the notification preview, matching the sample name */
			'email' => __( 'jane@example.com', 'honk' ),
			/* translators: sample phone number in the notification preview */
			'phone' => __( '+1 503 555 0142', 'honk' ),
			/* translators: sample billing city and country in the notification preview */
			'city'  => __( 'Portland, United States', 'honk' ),
		);
	}

	/**
	 * The sample person as Honk_Notifier::user_data(), with more fields.
	 *
	 * @param array $extra Other fields.
	 * @return array
	 */
	private static function user( array $extra = array() ) {
		$person = self::person();
		return array_merge(
			array(
				'display' => $person['name'],
				'login'   => $person['login'],
				'email'   => $person['email'],
			),
			$extra
		);
	}

	/**
	 * A sample order (Honk_Module_Woocommerce::order_data()).
	 *
	 * @param array $extra Other fields.
	 * @return array
	 */
	private static function order( array $extra = array() ) {
		$person = self::person();
		return array_merge(
			array(
				'number'   => '1234',
				'total'    => Honk_Notifier::money( 84, self::currency() ),
				'count'    => 3,
				'status'   => self::status( 'processing' ),
				/* translators: sample payment method in the notification preview */
				'payment'  => __( 'Credit card', 'honk' ),
				'products' => array( array( self::product( 0 ), 1 ), array( self::product( 1 ), 2 ) ),
				/* translators: sample shipping method in the notification preview (WooCommerce's own name for it) */
				'shipping' => __( 'Flat rate', 'honk' ),
				'name'     => $person['name'],
				'email'    => $person['email'],
				'phone'    => $person['phone'],
				'city'     => $person['city'],
				/* translators: sample note a customer left at checkout, in the notification preview */
				'note'     => __( 'Please leave it with the neighbors.', 'honk' ),
			),
			$extra
		);
	}

	/**
	 * Sample product names.
	 *
	 * @param int $index 0 or 1.
	 * @return string
	 */
	private static function product( $index ) {
		/* translators: sample product name in the notification preview */
		return 0 === $index ? __( 'Linen Apron', 'honk' ) : __( 'Ceramic Mug', 'honk' );
	}

	/**
	 * A sample post title.
	 *
	 * @return string
	 */
	private static function post_title() {
		/* translators: sample post title in the notification preview */
		return __( 'Autumn sale', 'honk' );
	}

	/**
	 * A sample form message.
	 *
	 * @return string
	 */
	private static function message() {
		/* translators: sample message sent through a contact form, in the notification preview */
		return __( 'Hi, do you deliver on Saturdays?', 'honk' );
	}

	/**
	 * A sample plugin name.
	 *
	 * @return string
	 */
	private static function example_plugin() {
		/* translators: sample plugin name in the notification preview */
		return __( 'Example Plugin', 'honk' );
	}

	/**
	 * Who makes changes in the samples: the current user's login.
	 *
	 * @return string
	 */
	private static function actor() {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return $user && $user->exists() ? $user->user_login : 'admin';
	}

	/**
	 * A WooCommerce order status name.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status( $status ) {
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : ucfirst( $status );
	}

	/**
	 * A role's translated name.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	private static function role( $role ) {
		$all = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();
		return isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : ucfirst( $role );
	}

	/**
	 * The store's currency ('' without WooCommerce).
	 *
	 * @return string
	 */
	private static function currency() {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}
}
