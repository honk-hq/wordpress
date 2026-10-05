<?php
/**
 * WooCommerce events: orders, payments, refunds, stock, customers, reviews, subscriptions,
 * the daily sales summary.
 *
 * Orders are read only through WooCommerce's CRUD objects (wc_get_order, WC_Order getters and
 * meta methods), so the module works with both order storages (HPOS and posts).
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce module.
 */
final class Honk_Module_Woocommerce {

	const SUMMARY_HOOK = 'honk_daily_summary';

	const STOCK_OPTION = 'honk_stock_state';

	/**
	 * Order meta set once the new-order message is queued.
	 */
	const NEW_ORDER_META = '_honk_new_order_sent';

	/**
	 * Statuses that make an order "new" for the merchant (as WooCommerce's new order email).
	 */
	const PLACED_STATUSES = array( 'processing', 'on-hold', 'completed' );

	/**
	 * Statuses an order has before it is placed or paid.
	 */
	const UNPAID_STATUSES = array( 'pending', 'failed', 'cancelled', 'checkout-draft', 'draft', 'auto-draft' );

	/**
	 * Orders to report as new at the end of the request (their final status is known then).
	 *
	 * @var array<int, bool>
	 */
	private static $new_orders = array();

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// New orders: classic checkout, block checkout (Store API), and any other way an order is
		// created (admin, REST). All are collected and reported once, at the end of the request.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order_processed' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );

		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_payment_failed' ), 20, 2 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 20, 2 );

		add_action( 'woocommerce_low_stock', array( __CLASS__, 'on_stock_hook' ), 20, 1 );
		add_action( 'woocommerce_no_stock', array( __CLASS__, 'on_stock_hook' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_hook' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_hook' ), 20, 1 );

		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_created_customer' ), 20, 3 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_insert_comment' ), 20, 2 );
		add_action( 'woocommerce_subscription_renewal_payment_failed', array( __CLASS__, 'on_subscription_renewal_failed' ), 20, 2 );
		add_action( self::SUMMARY_HOOK, array( __CLASS__, 'send_daily_summary' ) );
	}

	/*
	 * New orders.
	 */

	/**
	 * Classic checkout placed an order.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_checkout_order_processed( $order_id ) {
		self::defer_new_order( $order_id );
	}

	/**
	 * Block checkout (Store API) placed an order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function on_store_api_order_processed( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			self::defer_new_order( $order->get_id() );
		}
	}

	/**
	 * An order was created (any way).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_new_order( $order_id ) {
		self::defer_new_order( $order_id );
	}

	/**
	 * Marks an order to be checked at the end of the request.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function defer_new_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! Honk_Settings::is_enabled( 'woo_new_order' ) ) {
			return;
		}
		if ( empty( self::$new_orders ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_new_orders' ), 5 );
		}
		self::$new_orders[ $order_id ] = true;
	}

	/**
	 * End of the request: reports each collected order that is now paid or on hold. Orders still
	 * waiting for payment (e.g. a redirect to the payment provider) are reported when their status
	 * changes.
	 *
	 * @return void
	 */
	public static function flush_new_orders() {
		$ids              = array_keys( self::$new_orders );
		self::$new_orders = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! is_a( $order, 'WC_Order' ) || is_a( $order, 'WC_Order_Refund' ) ) {
				continue;
			}
			if ( ! in_array( $order->get_status(), self::PLACED_STATUSES, true ) || $order->get_meta( self::NEW_ORDER_META ) ) {
				continue;
			}
			self::report_new_order( $order );
		}
	}

	/**
	 * Queues the new-order message and marks the order (so it is never reported twice).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function report_new_order( $order ) {
		$queued = Honk_Notifier::emit(
			'woo_new_order',
			'order-' . $order->get_id() . '-new',
			function () use ( $order ) {
				$pii   = Honk_Settings::include_pii();
				$lines = array( self::order_summary( $order ) );
				$extra = array( wc_get_order_status_name( $order->get_status() ) );
				if ( $order->get_payment_method_title() ) {
					$extra[] = $order->get_payment_method_title();
				}
				$lines[] = implode( ' · ', $extra );
				if ( $pii ) {
					$customer = self::customer_line( $order );
					if ( '' !== $customer ) {
						$lines[] = $customer;
					}
				}
				return self::order_fields(
					$order,
					array(
						/* translators: %s: order number */
						'title'     => sprintf( __( 'New order #%s', 'honk' ), $order->get_order_number() ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/orders',
						'pii'       => $pii,
					)
				);
			}
		);
		if ( $queued ) {
			$order->update_meta_data( self::NEW_ORDER_META, time() );
			$order->save_meta_data();
		}
	}

	/*
	 * Status changes.
	 */

	/**
	 * An order changed status. Transitions covered by other events are skipped: placing and
	 * paying (new order), failed (failed payment), refunded (refund).
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Previous status (without "wc-").
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( in_array( $to, self::PLACED_STATUSES, true ) && in_array( $from, self::UNPAID_STATUSES, true ) ) {
			self::defer_new_order( $order_id );
			if ( Honk_Settings::is_enabled( 'woo_new_order' ) ) {
				return;
			}
		}
		if ( in_array( $to, array( 'failed', 'refunded', 'checkout-draft', 'trash' ), true ) ) {
			return;
		}
		if ( in_array( $from, array( 'checkout-draft', 'draft', 'auto-draft' ), true ) ) {
			return; // An order being placed.
		}
		if ( 'pending' === $from && 'cancelled' === $to ) {
			return; // An unpaid order that timed out (WooCommerce sends no email for it either).
		}
		$order = is_object( $order ) ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$modified = $order->get_date_modified();
		Honk_Notifier::emit(
			'woo_order_status',
			'order-' . $order->get_id() . '-' . $from . '-' . $to . '-' . ( $modified ? $modified->getTimestamp() : '' ),
			function () use ( $order, $from, $to ) {
				$pii   = Honk_Settings::include_pii();
				$lines = array(
					wc_get_order_status_name( $from ) . ' → ' . wc_get_order_status_name( $to ),
					self::order_summary( $order ),
				);
				if ( $pii ) {
					$customer = self::customer_line( $order );
					if ( '' !== $customer ) {
						$lines[] = $customer;
					}
				}
				return self::order_fields(
					$order,
					array(
						/* translators: 1: order number, 2: new status */
						'title'     => sprintf( __( 'Order #%1$s: %2$s', 'honk' ), $order->get_order_number(), wc_get_order_status_name( $to ) ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/orders/status',
						'pii'       => $pii,
					)
				);
			}
		);
	}

	/*
	 * Payments and refunds.
	 */

	/**
	 * An order's payment failed.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 * @return void
	 */
	public static function on_payment_failed( $order_id, $order = null ) {
		$order = is_object( $order ) ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$modified = $order->get_date_modified();
		Honk_Notifier::emit(
			'woo_payment_failed',
			'order-' . $order->get_id() . '-failed-' . ( $modified ? $modified->getTimestamp() : '' ),
			function () use ( $order ) {
				$pii   = Honk_Settings::include_pii();
				$lines = array( self::order_summary( $order ) );
				if ( $order->get_payment_method_title() ) {
					/* translators: %s: payment method */
					$lines[] = sprintf( __( 'Payment method: %s', 'honk' ), $order->get_payment_method_title() );
				}
				if ( $pii ) {
					$customer = self::customer_line( $order );
					if ( '' !== $customer ) {
						$lines[] = $customer;
					}
				}
				return self::order_fields(
					$order,
					array(
						/* translators: %s: order number */
						'title'     => sprintf( __( 'Payment failed for order #%s', 'honk' ), $order->get_order_number() ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/payments/failed',
						'pii'       => $pii,
					)
				);
			}
		);
	}

	/**
	 * A refund was made (full or partial).
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public static function on_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		Honk_Notifier::emit(
			'woo_refund',
			'refund-' . absint( $refund_id ),
			function () use ( $order, $refund ) {
				$amount = Honk_Notifier::money( abs( (float) $refund->get_amount() ), $order->get_currency() );
				$full   = 'refunded' === $order->get_status() || (float) $order->get_total() - (float) $order->get_total_refunded() <= 0;
				$lines  = array(
					$full
						/* translators: 1: amount, 2: order number */
						? sprintf( __( 'Full refund of %1$s on order #%2$s.', 'honk' ), $amount, $order->get_order_number() )
						/* translators: 1: amount, 2: order number */
						: sprintf( __( 'Partial refund of %1$s on order #%2$s.', 'honk' ), $amount, $order->get_order_number() ),
					self::order_summary( $order ),
				);
				if ( $refund->get_reason() ) {
					/* translators: %s: refund reason */
					$lines[] = sprintf( __( 'Reason: %s', 'honk' ), $refund->get_reason() );
				}
				return self::order_fields(
					$order,
					array(
						/* translators: 1: amount, 2: order number */
						'title'     => sprintf( __( 'Refund of %1$s for order #%2$s', 'honk' ), $amount, $order->get_order_number() ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/refunds',
						'metadata'  => array( 'refund' => (float) $refund->get_amount() ),
					)
				);
			}
		);
	}

	/*
	 * Stock.
	 */

	/**
	 * A stock hook fired: re-evaluates the product's stock level.
	 *
	 * @param WC_Product|int $product Product.
	 * @return void
	 */
	public static function on_stock_hook( $product ) {
		$product = is_object( $product ) ? $product : wc_get_product( $product );
		if ( $product && is_a( $product, 'WC_Product' ) ) {
			self::evaluate_stock( $product );
		}
	}

	/**
	 * Compares a product's stock level (ok, low, out) with the last one seen and reports changes:
	 * low and out of stock as problems, restocking as a recovery. Grouped per product.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public static function evaluate_stock( $product ) {
		if ( ! $product->managing_stock() ) {
			return;
		}
		$qty   = (int) $product->get_stock_quantity();
		$state = self::stock_level( $qty, self::low_stock_amount( $product ), (int) get_option( 'woocommerce_notify_no_stock_amount', 0 ) );

		$id     = $product->get_id();
		$all    = get_option( self::STOCK_OPTION, array() );
		$all    = is_array( $all ) ? $all : array();
		$before = isset( $all[ $id ] ) ? $all[ $id ] : array(
			's' => 'ok',
			'n' => 0,
		);
		if ( $before['s'] === $state ) {
			return;
		}
		// The sequence number keeps each change's key unique (low, back in stock, low again…).
		$seq        = (int) $before['n'] + 1;
		$all[ $id ] = array(
			's' => $state,
			'n' => $seq,
		);
		update_option( self::STOCK_OPTION, $all, false );

		$event = 'out' === $state ? 'woo_out_of_stock' : 'woo_low_stock';
		if ( 'ok' === $state ) {
			$event = 'out' === $before['s'] ? 'woo_out_of_stock' : 'woo_low_stock';
		}
		$name = Honk_Payload::plain( $product->get_name(), true );
		$sku  = $product->get_sku();
		Honk_Notifier::emit(
			$event,
			'stock-' . $id . '-' . $state . '-' . $seq,
			function () use ( $state, $qty, $name, $sku, $id, $product ) {
				switch ( $state ) {
					case 'out':
						/* translators: %s: product name */
						$title = sprintf( __( 'Out of stock: %s', 'honk' ), $name );
						break;
					case 'low':
						/* translators: %s: product name */
						$title = sprintf( __( 'Low stock: %s', 'honk' ), $name );
						break;
					default:
						/* translators: %s: product name */
						$title = sprintf( __( 'Back in stock: %s', 'honk' ), $name );
				}
				$lines = array(
					'ok' === $state
						/* translators: %d: units in stock */
						? sprintf( _n( '%d unit in stock.', '%d units in stock.', $qty, 'honk' ), $qty )
						/* translators: %d: units in stock */
						: sprintf( _n( '%d unit left.', '%d units left.', $qty, 'honk' ), $qty ),
				);
				if ( '' !== (string) $sku ) {
					/* translators: %s: SKU */
					$lines[] = sprintf( __( 'SKU: %s', 'honk' ), $sku );
				}
				$edit = $product->get_parent_id() ? $product->get_parent_id() : $id;
				return Honk_Notifier::with_link(
					array(
						'title'      => $title,
						'message'    => implode( "\n", $lines ),
						'group_key'  => 'woo/stock/' . $id,
						'event_type' => 'ok' === $state ? 'recovery' : 'problem',
						'metadata'   => array(
							'product_id' => $id,
							'stock'      => $qty,
						),
					),
					admin_url( 'post.php?post=' . $edit . '&action=edit' )
				);
			},
			'ok' === $state ? array( 'severity' => 'success' ) : array()
		);
	}

	/**
	 * Stock level name for a quantity.
	 *
	 * @param int $qty Quantity.
	 * @param int $low Low stock threshold.
	 * @param int $out Out of stock threshold.
	 * @return string ok, low or out.
	 */
	public static function stock_level( $qty, $low, $out ) {
		if ( $qty <= $out ) {
			return 'out';
		}
		if ( $qty <= $low ) {
			return 'low';
		}
		return 'ok';
	}

	/**
	 * Low stock threshold of a product (its own, or the store's).
	 *
	 * @param WC_Product $product Product.
	 * @return int
	 */
	private static function low_stock_amount( $product ) {
		if ( function_exists( 'wc_get_low_stock_amount' ) ) {
			return (int) wc_get_low_stock_amount( $product );
		}
		return (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
	}

	/*
	 * Customers, reviews, subscriptions.
	 */

	/**
	 * A customer account was created (checkout or My account).
	 *
	 * @param int   $customer_id       Customer (user) id.
	 * @param array $new_customer_data Data.
	 * @param bool  $password_generated Whether WooCommerce generated the password.
	 * @return void
	 */
	public static function on_created_customer( $customer_id, $new_customer_data = array(), $password_generated = false ) {
		unset( $new_customer_data, $password_generated );
		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			return;
		}
		Honk_Notifier::emit(
			'woo_new_customer',
			'customer-' . absint( $customer_id ),
			function () use ( $user ) {
				$pii = Honk_Settings::include_pii();
				return Honk_Notifier::with_link(
					array(
						'title'     => __( 'New customer', 'honk' ),
						'message'   => $pii ? Honk_Notifier::user_label( $user, true ) : __( 'A customer account was created.', 'honk' ),
						'group_key' => 'woo/customers',
						'pii'       => $pii,
						'metadata'  => array( 'customer_id' => $user->ID ),
					),
					admin_url( 'user-edit.php?user_id=' . $user->ID )
				);
			}
		);
	}

	/**
	 * A product review was saved.
	 *
	 * @param int        $comment_id Comment id.
	 * @param WP_Comment $comment    Comment.
	 * @return void
	 */
	public static function on_insert_comment( $comment_id, $comment ) {
		if ( ! $comment instanceof WP_Comment || 'spam' === (string) $comment->comment_approved || 'trash' === (string) $comment->comment_approved ) {
			return;
		}
		if ( ! in_array( $comment->comment_type, array( 'review', '', 'comment' ), true ) || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return;
		}
		$rating = (int) get_comment_meta( $comment_id, 'rating', true );
		Honk_Notifier::emit(
			'woo_new_review',
			'review-' . absint( $comment_id ),
			function () use ( $comment, $rating ) {
				$pii     = Honk_Settings::include_pii();
				$product = Honk_Payload::plain( get_the_title( (int) $comment->comment_post_ID ), true );
				$lines   = array();
				if ( $rating > 0 ) {
					/* translators: %d: rating from 1 to 5 */
					$lines[] = str_repeat( '★', min( 5, $rating ) ) . str_repeat( '☆', max( 0, 5 - $rating ) ) . ' ' . sprintf( __( '%d of 5', 'honk' ), $rating );
				}
				if ( '0' === (string) $comment->comment_approved ) {
					$lines[] = __( 'Awaiting moderation.', 'honk' );
				}
				if ( $pii ) {
					$lines[] = trim( $comment->comment_author . ( $comment->comment_author_email ? ' · ' . $comment->comment_author_email : '' ) );
					$lines[] = wp_trim_words( Honk_Payload::plain( $comment->comment_content, true ), 40, '…' );
				}
				return Honk_Notifier::with_link(
					array(
						/* translators: %s: product name */
						'title'     => sprintf( __( 'New review: %s', 'honk' ), $product ),
						'message'   => $lines ? implode( "\n", array_filter( $lines ) ) : __( 'A new product review.', 'honk' ),
						'group_key' => 'woo/reviews',
						'pii'       => $pii,
						'metadata'  => array(
							'product_id' => (int) $comment->comment_post_ID,
							'rating'     => $rating,
						),
					),
					admin_url( 'edit.php?post_type=product&page=product-reviews' )
				);
			}
		);
	}

	/**
	 * WooCommerce Subscriptions: a renewal payment failed.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 * @param WC_Order        $last_order   Renewal order.
	 * @return void
	 */
	public static function on_subscription_renewal_failed( $subscription, $last_order = null ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return;
		}
		$renewal_id = is_object( $last_order ) && method_exists( $last_order, 'get_id' ) ? $last_order->get_id() : 0;
		Honk_Notifier::emit(
			'woo_subscription_failed',
			'subscription-' . $subscription->get_id() . '-renewal-' . $renewal_id . '-failed',
			function () use ( $subscription, $last_order ) {
				$pii   = Honk_Settings::include_pii();
				$lines = array(
					/* translators: %s: amount */
					sprintf( __( 'The %s renewal couldn’t be charged.', 'honk' ), Honk_Notifier::money( (float) $subscription->get_total(), $subscription->get_currency() ) ),
				);
				if ( is_object( $last_order ) && method_exists( $last_order, 'get_order_number' ) ) {
					/* translators: %s: order number */
					$lines[] = sprintf( __( 'Renewal order #%s', 'honk' ), $last_order->get_order_number() );
				}
				if ( $pii ) {
					$customer = self::customer_line( $subscription );
					if ( '' !== $customer ) {
						$lines[] = $customer;
					}
				}
				return Honk_Notifier::with_link(
					array(
						/* translators: %s: subscription number */
						'title'     => sprintf( __( 'Subscription #%s: renewal failed', 'honk' ), $subscription->get_order_number() ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/subscriptions/renewal-failed',
						'pii'       => $pii,
						'metadata'  => array( 'subscription_id' => $subscription->get_id() ),
					),
					method_exists( $subscription, 'get_edit_order_url' ) ? $subscription->get_edit_order_url() : ''
				);
			}
		);
	}

	/*
	 * Daily sales summary.
	 */

	/**
	 * Schedules the summary for 08:00 site time (only while the event is on).
	 *
	 * @return void
	 */
	public static function ensure_summary_schedule() {
		$next = wp_next_scheduled( self::SUMMARY_HOOK );
		$want = class_exists( 'WooCommerce' ) && Honk_Settings::is_configured() && Honk_Settings::is_enabled( 'woo_daily_summary' );
		if ( $want && ! $next ) {
			$tomorrow = new DateTimeImmutable( 'tomorrow 08:00', wp_timezone() );
			$today    = new DateTimeImmutable( 'today 08:00', wp_timezone() );
			$at       = $today->getTimestamp() > time() ? $today : $tomorrow;
			wp_schedule_event( $at->getTimestamp(), 'daily', self::SUMMARY_HOOK );
		} elseif ( ! $want && $next ) {
			wp_unschedule_hook( self::SUMMARY_HOOK );
		}
	}

	/**
	 * Sends yesterday's sales: orders, revenue, items, refunds (one line per currency).
	 *
	 * @param string $day Day as Y-m-d in the site's time zone (default: yesterday).
	 * @return void
	 */
	public static function send_daily_summary( $day = '' ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$tz    = wp_timezone();
		$start = '' !== $day ? new DateTimeImmutable( $day . ' 00:00:00', $tz ) : new DateTimeImmutable( 'yesterday 00:00:00', $tz );
		$end   = $start->modify( '+1 day' );
		$stats = self::sales_stats( $start->getTimestamp(), $end->getTimestamp() - 1 );
		$date  = $start->format( 'Y-m-d' );

		Honk_Notifier::emit(
			'woo_daily_summary',
			'summary-' . $date,
			function () use ( $stats, $start ) {
				$label = wp_date( get_option( 'date_format' ), $start->getTimestamp() );
				if ( 0 === $stats['orders'] ) {
					return array(
						/* translators: %s: date */
						'title'     => sprintf( __( 'Sales on %s', 'honk' ), $label ),
						'message'   => __( 'No orders.', 'honk' ),
						'group_key' => 'woo/summary',
					);
				}
				$revenue = array();
				$lines   = array(
					/* translators: %d: number of orders */
					sprintf( _n( '%d order', '%d orders', $stats['orders'], 'honk' ), $stats['orders'] ) . ' · ' .
					/* translators: %d: number of items */
					sprintf( _n( '%d item', '%d items', $stats['items'], 'honk' ), $stats['items'] ),
				);
				foreach ( $stats['revenue'] as $currency => $amount ) {
					$revenue[] = Honk_Notifier::money( $amount, $currency );
				}
				/* translators: %s: revenue */
				$lines[] = sprintf( __( 'Revenue: %s', 'honk' ), implode( ' + ', $revenue ) );
				$refunds = array();
				foreach ( $stats['refunds'] as $currency => $amount ) {
					if ( $amount > 0 ) {
						$refunds[] = Honk_Notifier::money( $amount, $currency );
					}
				}
				if ( $refunds ) {
					/* translators: %s: refunded amount */
					$lines[] = sprintf( __( 'Refunded: %s', 'honk' ), implode( ' + ', $refunds ) );
				}
				return Honk_Notifier::with_link(
					array(
						/* translators: 1: date, 2: revenue */
						'title'     => sprintf( __( 'Sales on %1$s: %2$s', 'honk' ), $label, implode( ' + ', $revenue ) ),
						'message'   => implode( "\n", $lines ),
						'group_key' => 'woo/summary',
						'metadata'  => array(
							'orders' => $stats['orders'],
							'items'  => $stats['items'],
						),
					),
					admin_url( 'admin.php?page=wc-admin' )
				);
			}
		);
	}

	/**
	 * Orders, items, revenue and refunds per currency for orders created in a time range.
	 *
	 * @param int $from Start timestamp.
	 * @param int $to   End timestamp.
	 * @return array{orders: int, items: int, revenue: array<string, float>, refunds: array<string, float>}
	 */
	public static function sales_stats( $from, $to ) {
		$stats = array(
			'orders'  => 0,
			'items'   => 0,
			'revenue' => array(),
			'refunds' => array(),
		);
		$page  = 1;
		do {
			$orders  = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
					'date_created' => $from . '...' . $to,
					'limit'        => 100,
					'paged'        => $page,
					'return'       => 'objects',
				)
			);
			$fetched = count( $orders );
			foreach ( $orders as $order ) {
				$currency = $order->get_currency();
				++$stats['orders'];
				$stats['items']               += (int) $order->get_item_count();
				$stats['revenue'][ $currency ] = ( isset( $stats['revenue'][ $currency ] ) ? $stats['revenue'][ $currency ] : 0 ) + (float) $order->get_total();
				$stats['refunds'][ $currency ] = ( isset( $stats['refunds'][ $currency ] ) ? $stats['refunds'][ $currency ] : 0 ) + (float) $order->get_total_refunded();
			}
			++$page;
		} while ( 100 === $fetched && $page <= 100 );
		return $stats;
	}

	/*
	 * Helpers.
	 */

	/**
	 * "Order #1234 · €84.00 · 2 items" (no personal data).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_summary( $order ) {
		$items = (int) $order->get_item_count();
		return implode(
			' · ',
			array(
				/* translators: %s: order number */
				sprintf( __( 'Order #%s', 'honk' ), $order->get_order_number() ),
				Honk_Notifier::money( (float) $order->get_total(), $order->get_currency() ),
				/* translators: %d: number of items */
				sprintf( _n( '%d item', '%d items', $items, 'honk' ), $items ),
			)
		);
	}

	/**
	 * "Jane Doe · jane@example.com" from the billing address (only used when personal data is
	 * allowed).
	 *
	 * @param WC_Order $order Order or subscription.
	 * @return string
	 */
	public static function customer_line( $order ) {
		$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email = (string) $order->get_billing_email();
		return trim( $name . ( '' !== $name && '' !== $email ? ' · ' : '' ) . $email );
	}

	/**
	 * Adds the order's link and metadata to message fields.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $fields Message fields.
	 * @return array
	 */
	private static function order_fields( $order, array $fields ) {
		$fields['metadata'] = array_merge(
			array(
				'order_id'     => $order->get_id(),
				'order_number' => (string) $order->get_order_number(),
				'status'       => $order->get_status(),
				'total'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'items'        => (int) $order->get_item_count(),
			),
			isset( $fields['metadata'] ) ? $fields['metadata'] : array()
		);
		$created            = $order->get_date_created();
		if ( $created && 'woo/orders' === $fields['group_key'] ) {
			$fields['occurred_at'] = $created->getTimestamp();
		}
		return Honk_Notifier::with_link( $fields, $order->get_edit_order_url() );
	}
}
