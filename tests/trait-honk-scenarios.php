<?php
/**
 * Every event, triggered through the module's own hook with fixed fixtures: the shared input of
 * MessagesTest (the 0.1.0 messages, unchanged by default) and DetailsTest (each choice).
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey\Functions;

if ( ! defined( 'WPCF7_VERSION' ) ) {
	define( 'WPCF7_VERSION', '6.1' );
}
if ( ! defined( 'WPFORMS_VERSION' ) ) {
	define( 'WPFORMS_VERSION', '1.9' );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}
if ( ! class_exists( 'WC_Subscriptions' ) ) {
	class WC_Subscriptions {}
}
if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public function get_name() {}
		public function get_quantity() {}
	}
}
if ( ! class_exists( 'WC_Order' ) ) {
	// The getters the modules call, so that mocks have real methods (method_exists()).
	class WC_Order {
		public function get_id() {}
		public function get_order_number() {}
		public function get_total() {}
		public function get_currency() {}
		public function get_item_count() {}
		public function get_status() {}
		public function get_payment_method_title() {}
		public function get_billing_first_name() {}
		public function get_billing_last_name() {}
		public function get_billing_email() {}
		public function get_billing_phone() {}
		public function get_billing_city() {}
		public function get_billing_country() {}
		public function get_customer_note() {}
		public function get_shipping_method() {}
		public function get_total_refunded() {}
		public function get_items( $types = 'line_item' ) {}
		public function get_edit_order_url() {}
		public function get_date_created() {}
		public function get_date_modified() {}
		public function get_meta( $key = '', $single = true ) {}
		public function update_meta_data( $key, $value ) {}
		public function save_meta_data() {}
	}
}

trait Honk_Scenarios {

	/**
	 * Users by id: [ login, display name, email, roles ].
	 */
	protected $users = array(
		1  => array( 'admin', 'Site Admin', 'admin@example.com', array( 'administrator' ) ),
		7  => array( 'lena', 'Lena Weber', 'lena@example.com', array( 'author' ) ),
		21 => array( 'ana.pop', 'Ana Pop', 'ana@example.com', array( 'customer' ) ),
		31 => array( 'mara', 'Mara Ionescu', 'mara@example.com', array( 'subscriber' ) ),
		32 => array( 'sam', 'sam', 'sam@example.com', array( 'subscriber' ) ),
		51 => array( 'ops', 'Ops Team', 'ops@example.com', array( 'administrator' ) ),
		52 => array( 'eve', 'Eve Martin', 'eve@example.com', array( 'administrator' ) ),
		53 => array( 'li', 'Li Wang', 'li@example.com', array( 'editor' ) ),
	);

	/**
	 * The WordPress and WooCommerce functions the modules call.
	 */
	protected function stub_wordpress() {
		$self = $this;
		Functions\when( 'wc_price' )->alias(
			function ( $amount ) {
				return '<span class="amount"><bdi><span>&euro;</span>' . number_format( (float) $amount, 2 ) . '</bdi></span>';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			function ( $status ) {
				return ucwords( str_replace( '-', ' ', preg_replace( '/^wc-/', '', (string) $status ) ) );
			}
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) use ( $self ) {
				$id = (int) $id;
				if ( ! isset( $self->users[ $id ] ) ) {
					return false;
				}
				list( $login, $display, $email, $roles ) = $self->users[ $id ];
				$user                                    = new WP_User( $id, $login, $roles );
				$user->display_name                      = $display;
				$user->user_email                        = $email;
				return $user;
			}
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				$roles = $user instanceof WP_User ? (array) $user->roles : array();
				if ( in_array( 'administrator', $roles, true ) ) {
					return true;
				}
				return 'edit_posts' === $cap && (bool) array_intersect( $roles, array( 'editor', 'author', 'contributor' ) );
			}
		);
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'wp_roles' )->justReturn(
			new class() {
				public function get_names() {
					return array(
						'administrator' => 'Administrator',
						'editor'        => 'Editor',
						'author'        => 'Author',
						'subscriber'    => 'Subscriber',
						'customer'      => 'Customer',
					);
				}
			}
		);
		Functions\when( 'translate_user_role' )->returnArg();
		Functions\when( 'wp_trim_words' )->alias(
			function ( $text, $num = 55, $more = null ) {
				$words = preg_split( '/[\n\r\t ]+/', trim( strip_tags( (string) $text ) ), -1, PREG_SPLIT_NO_EMPTY );
				if ( count( $words ) > $num ) {
					return implode( ' ', array_slice( $words, 0, $num ) ) . ( null === $more ? '&hellip;' : $more );
				}
				return implode( ' ', $words );
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return 41 === (int) $id ? new WP_Post( array( 'ID' => 41, 'post_type' => 'post', 'post_title' => 'Hello world' ) ) : null;
			}
		);
		Functions\when( 'get_post_type' )->alias(
			function ( $id ) {
				return 12 === (int) $id ? 'product' : 'post';
			}
		);
		Functions\when( 'get_the_title' )->alias(
			function ( $post ) {
				if ( $post instanceof WP_Post ) {
					return $post->post_title;
				}
				return 12 === (int) $post ? 'Linen Apron' : 'Post ' . (int) $post;
			}
		);
		Functions\when( 'get_post_type_object' )->alias(
			function ( $type ) {
				return (object) array(
					'public' => true,
					'labels' => (object) array( 'singular_name' => ucfirst( (string) $type ) ),
				);
			}
		);
		Functions\when( 'get_permalink' )->alias(
			function ( $post ) {
				return 'https://shop.example.com/?p=' . ( $post instanceof WP_Post ? $post->ID : (int) $post );
			}
		);
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'excerpt_remove_blocks' )->returnArg();
		Functions\when( 'get_comment_meta' )->alias(
			function ( $id, $key ) {
				return 'rating' === $key && 501 === (int) $id ? '4' : '';
			}
		);
		Functions\when( 'get_plugin_data' )->justReturn( array() );
		Functions\when( 'plugin_basename' )->alias(
			function ( $file ) {
				return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
			}
		);
		Functions\when( 'wp_get_theme' )->alias(
			function ( $stylesheet = '' ) {
				return 'twentytwentyfive' === $stylesheet ? new WP_Theme( array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.3' ) ) : new WP_Theme();
			}
		);
		Functions\when( 'doing_action' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->alias(
			function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'get_theme_root' )->justReturn( WP_CONTENT_DIR . '/themes' );
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'acme/acme.php'                => array( 'Name' => 'Acme Tools', 'Version' => '2.0.1' ),
				'woocommerce/woocommerce.php'  => array( 'Name' => 'WooCommerce', 'Version' => '11.1.2' ),
			)
		);
		Functions\when( 'size_format' )->alias(
			function ( $bytes, $decimals = 0 ) {
				return number_format( $bytes / 1073741824, $decimals ) . ' GB';
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
		Functions\when( 'get_bloginfo' )->alias(
			function ( $what ) {
				return 'version' === $what ? '7.0' : 'Corner Shop';
			}
		);
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		$this->options['date_format'] = 'F j, Y';
		$this->options['time_format'] = 'H:i';
	}

	/**
	 * Static per-request state of the modules, cleared between scenarios.
	 */
	protected function reset_modules() {
		foreach ( array(
			'Honk_Module_Security'    => array( 'deleting', 'editing', 'new_admins', 'registered', 'set_role', 'role_ops' ),
			'Honk_Module_Woocommerce' => array( 'new_orders' ),
		) as $class => $props ) {
			foreach ( $props as $prop ) {
				$p = new ReflectionProperty( $class, $prop );
				$p->setAccessible( true );
				$p->setValue( null, array() );
			}
		}
		Honk_Dedupe::reset_request();
		Honk_Settings::flush();
	}

	/**
	 * A WooCommerce order.
	 *
	 * @param array $o Overrides.
	 * @return \Mockery\MockInterface
	 */
	protected function order( array $o = array() ) {
		$o     = array_merge(
			array(
				'id'       => 77,
				'number'   => '1234',
				'total'    => '84.00',
				'currency' => 'EUR',
				'count'    => 2,
				'status'   => 'processing',
				'payment'  => 'Card',
				'first'    => 'Ana',
				'last'     => 'Pop',
				'email'    => 'ana@example.com',
				'phone'    => '+40 721 000 000',
				'city'     => 'Cluj-Napoca',
				'country'  => 'RO',
				'note'     => 'Please leave it with the neighbours.',
				'shipping' => 'Flat rate',
				'items'    => array( array( 'Linen Apron', 1 ), array( 'Ceramic Mug', 1 ) ),
				'refunded' => '0',
				'created'  => 1791193000,
				'modified' => 1791193100,
				'edit_url' => '',
			),
			$o
		);
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $o['id'] );
		$order->shouldReceive( 'get_order_number' )->andReturn( $o['number'] );
		$order->shouldReceive( 'get_total' )->andReturn( $o['total'] );
		$order->shouldReceive( 'get_currency' )->andReturn( $o['currency'] );
		$order->shouldReceive( 'get_item_count' )->andReturn( $o['count'] );
		$order->shouldReceive( 'get_status' )->andReturn( $o['status'] );
		$order->shouldReceive( 'get_payment_method_title' )->andReturn( $o['payment'] );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( $o['first'] );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( $o['last'] );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $o['email'] );
		$order->shouldReceive( 'get_billing_phone' )->andReturn( $o['phone'] );
		$order->shouldReceive( 'get_billing_city' )->andReturn( $o['city'] );
		$order->shouldReceive( 'get_billing_country' )->andReturn( $o['country'] );
		$order->shouldReceive( 'get_customer_note' )->andReturn( $o['note'] );
		$order->shouldReceive( 'get_shipping_method' )->andReturn( $o['shipping'] );
		$order->shouldReceive( 'get_total_refunded' )->andReturn( $o['refunded'] );
		$items = array();
		foreach ( $o['items'] as $i => $line ) {
			$item = Mockery::mock( 'WC_Order_Item_Product' );
			$item->shouldReceive( 'get_name' )->andReturn( $line[0] );
			$item->shouldReceive( 'get_quantity' )->andReturn( $line[1] );
			$items[ 900 + $i ] = $item;
		}
		$order->shouldReceive( 'get_items' )->andReturn( $items );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( '' !== $o['edit_url'] ? $o['edit_url'] : 'https://shop.example.com/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $o['id'] );
		$order->shouldReceive( 'get_date_created' )->andReturn( new DateTime( '@' . $o['created'] ) );
		$order->shouldReceive( 'get_date_modified' )->andReturn( new DateTime( '@' . $o['modified'] ) );
		$order->shouldReceive( 'get_meta' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->andReturn( null );
		$order->shouldReceive( 'save_meta_data' )->andReturn( null );
		return $order;
	}

	/**
	 * A product with managed stock.
	 *
	 * @param int $qty Units in stock (changed through the reference).
	 * @return \Mockery\MockInterface
	 */
	protected function product( &$qty ) {
		Functions\when( 'wc_get_low_stock_amount' )->justReturn( 3 );
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'managing_stock' )->andReturn( true );
		$product->shouldReceive( 'get_id' )->andReturn( 12 );
		$product->shouldReceive( 'get_name' )->andReturn( 'Linen Apron' );
		$product->shouldReceive( 'get_sku' )->andReturn( 'APR-02' );
		$product->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$product->shouldReceive( 'get_stock_quantity' )->andReturnUsing(
			function () use ( &$qty ) {
				return $qty;
			}
		);
		return $product;
	}

	/**
	 * The scenarios: name => callable that triggers one or more events.
	 *
	 * @return array<string, callable>
	 */
	protected function scenarios() {
		$self = $this;
		return array(
			// WooCommerce.
			'woo_new_order'           => function () use ( $self ) {
				Honk_Module_Woocommerce::report_new_order( $self->order() );
			},
			'woo_new_order_sparse'    => function () use ( $self ) {
				Honk_Module_Woocommerce::report_new_order(
					$self->order( array( 'id' => 78, 'number' => '1235', 'count' => 1, 'payment' => '', 'first' => '', 'last' => '', 'email' => '', 'phone' => '', 'city' => '', 'country' => '', 'note' => '', 'shipping' => '', 'items' => array( array( 'Ceramic Mug', 3 ) ) ) )
				);
			},
			'woo_order_status'        => function () use ( $self ) {
				Honk_Module_Woocommerce::on_status_changed( 77, 'processing', 'completed', $self->order( array( 'status' => 'completed' ) ) );
			},
			'woo_payment_failed'      => function () use ( $self ) {
				Honk_Module_Woocommerce::on_payment_failed( 77, $self->order( array( 'status' => 'failed' ) ) );
			},
			'woo_refund'              => function () use ( $self ) {
				$order   = $self->order( array( 'refunded' => '20.00' ) );
				$partial = Mockery::mock( 'WC_Order_Refund' );
				$partial->shouldReceive( 'get_amount' )->andReturn( '20.00' );
				$partial->shouldReceive( 'get_reason' )->andReturn( 'Damaged in transit' );
				$full = Mockery::mock( 'WC_Order_Refund' );
				$full->shouldReceive( 'get_amount' )->andReturn( '84.00' );
				$full->shouldReceive( 'get_reason' )->andReturn( '' );
				$refunded = $self->order( array( 'id' => 79, 'number' => '1236', 'status' => 'refunded', 'refunded' => '84.00' ) );
				Functions\when( 'wc_get_order' )->alias(
					function ( $id ) use ( $order, $partial, $full, $refunded ) {
						$map = array(
							77 => $order,
							90 => $partial,
							79 => $refunded,
							91 => $full,
						);
						return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : false;
					}
				);
				Honk_Module_Woocommerce::on_refund( 77, 90 );
				Honk_Module_Woocommerce::on_refund( 79, 91 );
			},
			'woo_stock'               => function () use ( $self ) {
				$qty     = 8;
				$product = $self->product( $qty );
				foreach ( array( 8, 2, 0, 10 ) as $value ) {
					$qty = $value;
					Honk_Module_Woocommerce::evaluate_stock( $product );
				}
			},
			'woo_new_customer'        => function () {
				Honk_Module_Woocommerce::on_created_customer( 21 );
			},
			'woo_new_review'          => function () {
				Honk_Module_Woocommerce::on_insert_comment(
					501,
					new WP_Comment(
						array(
							'comment_ID'           => 501,
							'comment_post_ID'      => 12,
							'comment_author'       => 'Ana Pop',
							'comment_author_email' => 'ana@example.com',
							'comment_content'      => 'Lovely fabric, and it washes well. The pocket could be deeper, but I would buy it again.',
							'comment_approved'     => '0',
							'comment_type'         => 'review',
						)
					)
				);
				Honk_Module_Woocommerce::on_insert_comment(
					502,
					new WP_Comment(
						array(
							'comment_ID'      => 502,
							'comment_post_ID' => 12,
							'comment_author'  => 'Tom',
							'comment_content' => 'Fine.',
							'comment_type'    => 'review',
						)
					)
				);
			},
			'woo_subscription_failed' => function () use ( $self ) {
				$subscription = $self->order( array( 'id' => 300, 'number' => '300', 'total' => '19.00', 'edit_url' => 'https://shop.example.com/wp-admin/post.php?post=300&action=edit' ) );
				Honk_Module_Woocommerce::on_subscription_renewal_failed( $subscription, $self->order( array( 'id' => 301, 'number' => '301' ) ) );
			},
			'woo_daily_summary'       => function () use ( $self ) {
				$self->options['honk_settings']['events']['woo_daily_summary'] = array( 'enabled' => true, 'severity' => 'info', 'priority' => 'low' );
				Honk_Settings::flush();
				$orders = array( $self->order( array( 'refunded' => '10.00' ) ), $self->order( array( 'id' => 80, 'total' => '16.00', 'count' => 1 ) ) );
				Functions\when( 'wc_get_orders' )->justReturn( $orders );
				Honk_Module_Woocommerce::send_daily_summary( '2026-10-04' );
				Functions\when( 'wc_get_orders' )->justReturn( array() );
				Honk_Module_Woocommerce::send_daily_summary( '2026-10-03' );
			},

			// Content and users.
			'user_registered'         => function () {
				Honk_Module_Content::on_user_register( 31 );
				Honk_Module_Content::on_user_register( 32 );
			},
			'comment_pending'         => function () {
				Honk_Module_Content::on_insert_comment(
					601,
					new WP_Comment(
						array(
							'comment_ID'           => 601,
							'comment_post_ID'      => 41,
							'comment_author'       => 'Dan',
							'comment_author_email' => 'dan@example.com',
							'comment_content'      => '<p>Great post. Do you ship to Austria as well?</p>',
							'comment_approved'     => '0',
						)
					)
				);
				Honk_Module_Content::on_insert_comment(
					602,
					new WP_Comment(
						array(
							'comment_ID'       => 602,
							'comment_post_ID'  => 41,
							'comment_content'  => 'Anonymous question.',
							'comment_approved' => '0',
						)
					)
				);
			},
			'post_pending'            => function () {
				Honk_Module_Content::on_transition_post_status( 'pending', 'draft', new WP_Post( array( 'ID' => 42, 'post_author' => 7, 'post_title' => 'Autumn sale', 'post_content' => '<!-- wp:paragraph --><p>Everything linen is 20% off until Sunday.</p><!-- /wp:paragraph -->' ) ) );
			},
			'post_published'          => function ( $self_test ) {
				$self_test->options['honk_settings']['events']['post_published'] = array( 'enabled' => true, 'severity' => 'success', 'priority' => 'low' );
				Honk_Settings::flush();
				Honk_Module_Content::on_transition_post_status( 'publish', 'pending', new WP_Post( array( 'ID' => 43, 'post_author' => 31, 'post_title' => 'Opening hours', 'post_excerpt' => 'We are open on Sundays from now on.' ) ) );
			},

			// Security.
			'admin_login_new_device'  => function ( $self_test ) {
				$self_test->user_meta[1]['honk_known_devices'] = array( array( 'd' => 'old-device', 'n' => 'old-network', 't' => time() ) );
				$_SERVER['REMOTE_ADDR']                        = '203.0.113.77';
				$_SERVER['HTTP_USER_AGENT']                    = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';
				Honk_Module_Security::on_login( 'admin', get_userdata( 1 ) );
				unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
			},
			'login_failures_burst'    => function () {
				for ( $i = 0; $i < 10; $i++ ) {
					$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( $i % 4 );
					Honk_Module_Security::on_login_failed( 0 === $i % 3 ? 'root' : 'admin' );
				}
				unset( $_SERVER['REMOTE_ADDR'] );
			},
			'new_administrator'       => function () {
				Honk_Module_Security::on_user_register( 51 );
				Honk_Module_Security::on_set_user_role( 52, 'administrator', array( 'editor' ) );
			},
			'role_changed'            => function () {
				Honk_Module_Security::on_set_user_role( 53, 'editor', array( 'author' ) );
			},
			'site_identity_changed'   => function () {
				Honk_Module_Security::on_identity_option( 'old@example.com', 'owner@example.com', 'admin_email' );
				Honk_Module_Security::on_identity_option( 'https://old.example.com', 'https://shop.example.com', 'home' );
			},
			'plugin_changed'          => function () {
				Honk_Module_Security::on_plugin_activated( 'acme/acme.php', false );
				Honk_Module_Security::on_plugin_deactivated( 'hello-dolly/hello.php', true );
			},
			'theme_changed'           => function () {
				Honk_Module_Security::on_switch_theme( 'Twenty Twenty-Five', new WP_Theme( array( 'Name' => 'Twenty Twenty-Five' ) ), new WP_Theme( array( 'Name' => 'Twenty Twenty-Four' ) ) );
			},
			'file_edited'             => function () {
				$path = tempnam( sys_get_temp_dir(), 'honk' );
				file_put_contents( $path, 'after' );
				$p = new ReflectionProperty( 'Honk_Module_Security', 'editing' );
				$p->setAccessible( true );
				$p->setValue(
					null,
					array(
						$path => array(
							'md5'  => md5( 'before' ),
							'file' => 'acme/acme.php',
							'what' => 'Acme Tools',
							'kind' => 'plugin',
						),
					)
				);
				Honk_Module_Security::after_file_edit();
				unlink( $path );
			},
			'updates_installed'       => function () {
				Honk_Module_Security::report_updates( 'plugin', array( 'WooCommerce 11.2.0', 'Acme Tools 2.0.1' ) );
				Honk_Module_Security::report_updates( 'core', array( 'WordPress 7.1' ), true );
			},

			// Site health.
			'fatal_error'             => function () {
				Honk_Module_Health::report_fatal(
					array(
						'message' => 'Uncaught Error: Call to undefined function acme_boot() in /tmp/wordpress/wp-content/plugins/acme/acme.php:12',
						'file'    => '/tmp/wordpress/wp-content/plugins/acme/acme.php',
						'line'    => 12,
					),
					true
				);
				Honk_Module_Health::report_fatal(
					array(
						'message' => 'Allowed memory size of 134217728 bytes exhausted',
						'file'    => '/tmp/wordpress/wp-includes/class-wpdb.php',
						'line'    => 2300,
					),
					false
				);
			},
			'auto_update_failed'      => function () {
				Honk_Module_Health::on_automatic_updates_complete(
					array(
						'plugin' => array(
							(object) array( 'name' => 'Acme Tools', 'result' => new WP_Error( 'download_failed', 'Download failed.' ) ),
							(object) array( 'name' => 'Akismet', 'result' => true ),
						),
						'theme'  => array( (object) array( 'name' => 'Twenty Twenty-Five', 'result' => false ) ),
					)
				);
			},
			'updates_available'       => function () {
				Functions\when( 'get_core_updates' )->justReturn( array( (object) array( 'response' => 'upgrade', 'current' => '7.1' ) ) );
				Functions\when( 'get_site_transient' )->alias(
					function ( $name ) {
						if ( 'update_plugins' === $name ) {
							return (object) array( 'response' => array( 'woocommerce/woocommerce.php' => (object) array( 'new_version' => '11.2.0' ) ) );
						}
						return (object) array( 'response' => array( 'twentytwentyfive' => array( 'new_version' => '1.4' ) ) );
					}
				);
				Honk_Module_Health::check_updates_available();
			},
			'cron_overdue'            => function () {
				$now = 1791193000;
				Functions\when( '_get_cron_array' )->justReturn( array( $now - 7200 => array( 'wc_cleanup' => array( 'k' => array() ) ) ) );
				Honk_Module_Health::check_cron( $now );
				Honk_Module_Health::check_cron( $now + 16 * 60 );
			},

			// Forms.
			'forms'                   => function () {
				Honk_Module_Forms::report(
					'form_wpforms',
					'wpforms',
					'3',
					'Contact',
					array(
						array( 'key' => '1', 'label' => 'Name', 'value' => array( 'first' => 'Ana', 'last' => 'Pop' ), 'type' => 'name' ),
						array( 'key' => '2', 'label' => 'Email', 'value' => 'ana@example.com', 'type' => 'email' ),
						array( 'key' => '3', 'label' => 'Password', 'value' => 'hunter2', 'type' => 'password' ),
						array( 'key' => '4', 'label' => 'Message', 'value' => "Line one\nLine two", 'type' => 'textarea' ),
					),
					'entry-9'
				);
				Honk_Module_Forms::report( 'form_cf7', 'cf7', '10', '', array(), 'entry-1' );
			},
		);
	}

	/**
	 * Runs one scenario with personal data off or on and returns what was queued: the payloads
	 * and whether each job was flagged as containing personal data.
	 *
	 * @param string $name Scenario.
	 * @param bool   $pii  Include personal data.
	 * @param array  $extra Other settings.
	 * @return array[]
	 */
	protected function run_scenario( $name, $pii, array $extra = array() ) {
		$this->options = array_intersect_key( $this->options, array_flip( array( 'date_format', 'time_format' ) ) );
		$this->configure( array_merge( array( 'include_pii' => $pii ), $extra ) );
		$this->reset_modules();
		$scenarios = $this->scenarios();
		call_user_func( $scenarios[ $name ], $this );
		$out = array();
		foreach ( $this->jobs() as $job ) {
			$out[] = array(
				'event'   => $job['event'],
				'pii'     => $job['pii'],
				'payload' => json_decode( $job['body'], true ),
			);
		}
		return $out;
	}
}
