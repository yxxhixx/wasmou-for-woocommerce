<?php
defined( 'ABSPATH' ) || exit;

/**
 * Buys from Wasmou when a customer's order is paid, stores the delivered codes on the order and shows them to the customer.
 *
 * Item states (order item meta _wasmou_state):  '' -> purchasing -> ordered -> delivered | failed
 * Safety: one lock per order, one stable Idempotency-Key per order line (a retry can never buy twice).
 */
class Wasmou_WC_Fulfillment {

	const POLL_HOOK  = 'wasmou_wc_poll_order';
	const WATCH_HOOK = 'wasmou_wc_watch';
	const MAX_TRIES  = 8;

	private static $needs_copy = false;
	private static $rendered   = array();

	public static function hooks() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );
		add_action( self::POLL_HOOK, array( __CLASS__, 'process' ) );
		add_action( self::WATCH_HOOK, array( __CLASS__, 'watch' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 15, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'account_block' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'copy_script' ) );
		// block-based order confirmation page (block themes / block checkout)
		add_filter( 'render_block_woocommerce/order-confirmation-totals', array( __CLASS__, 'confirmation_block' ), 10, 1 );
	}

	// ------------------------------------------------------------------ helpers

	/** A list stored in order item meta (never [''] for a missing value). */
	public static function meta_list( $item, $key ) {
		$v = $item->get_meta( $key );

		return array_values( array_filter( array_map( 'strval', (array) $v ), 'strlen' ) );
	}

	public static function item_variant_id( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return 0;
		}
		$id = (int) get_post_meta( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id(), '_wasmou_variant_id', true );

		return $id;
	}

	public static function has_wasmou_items( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( self::item_variant_id( $item ) ) {
				return true;
			}
		}

		return false;
	}

	private static function lock( $order_id ) {
		$name = 'wasmou_wc_lock_' . (int) $order_id;
		if ( add_option( $name, time(), '', 'no' ) ) {
			return true;
		}
		if ( time() - (int) get_option( $name ) > 120 ) { // a crashed run: take over.
			delete_option( $name );

			return add_option( $name, time(), '', 'no' );
		}

		return false;
	}

	private static function unlock( $order_id ) {
		delete_option( 'wasmou_wc_lock_' . (int) $order_id );
	}

	public static function schedule( $order_id, $delay ) {
		$args = array( (int) $order_id );
		if ( ! wp_next_scheduled( self::POLL_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 10, (int) $delay ), self::POLL_HOOK, $args );
		}
	}

	private static function backoff( $polls ) {
		$steps = array( 20, 45, 60, 90, 120, 300, 600, 900, 1800 );

		return $steps[ min( (int) $polls, count( $steps ) - 1 ) ];
	}

	// ------------------------------------------------------------------ entry points

	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::has_wasmou_items( $order ) || $order->get_meta( '_wasmou_done' ) ) {
			return;
		}
		if ( ! $order->get_meta( '_wasmou_pending' ) ) {
			$order->update_meta_data( '_wasmou_pending', '1' );
			$order->save();
		}
		self::process( $order_id );
	}

	/** Safety net (every 5 min): anything still pending gets another look. */
	public static function watch() {
		$ids = wc_get_orders( array( 'limit' => 30, 'return' => 'ids', 'status' => array( 'processing', 'on-hold', 'completed' ), 'meta_key' => '_wasmou_pending', 'meta_value' => '1' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $ids as $id ) {
			self::process( $id );
		}
	}

	/** Customers opening their order page nudge a pending order (helps hosts where WP-Cron is slow). */
	public static function nudge( $order ) {
		if ( $order->get_meta( '_wasmou_pending' ) && ! get_transient( 'wasmou_wc_nudge_' . $order->get_id() ) ) {
			set_transient( 'wasmou_wc_nudge_' . $order->get_id(), 1, 10 );
			self::process( $order->get_id() );
		}
	}

	// ------------------------------------------------------------------ the engine

	public static function process( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::has_wasmou_items( $order ) || $order->get_meta( '_wasmou_done' ) ) {
			return;
		}
		if ( ! self::lock( $order_id ) ) {
			self::schedule( $order_id, 30 );

			return;
		}
		try {
			$order = wc_get_order( $order_id ); // fresh copy inside the lock.
			self::run( $order );
		} catch ( Throwable $e ) {
			Wasmou_WC_Logger::error( "Order {$order_id}: " . $e->getMessage() );
			self::schedule( $order_id, 120 );
		} finally {
			self::unlock( $order_id );
		}
	}

	private static function run( WC_Order $order ) {
		$order_id = $order->get_id();
		$pending  = false;

		// 1) buy what has not been bought yet
		foreach ( $order->get_items() as $item_id => $item ) {
			$vid = self::item_variant_id( $item );
			if ( ! $vid ) {
				continue;
			}
			$state = (string) $item->get_meta( '_wasmou_state' );
			if ( in_array( $state, array( 'ordered', 'delivered', 'failed' ), true ) ) {
				continue;
			}
			$retry = (int) $item->get_meta( '_wasmou_retry' );
			$idem  = 'wc-' . substr( md5( home_url() ), 0, 8 ) . '-' . $order_id . '-' . $item_id . ( $retry ? '-r' . $retry : '' );
			if ( '' === $state ) {
				$item->update_meta_data( '_wasmou_state', 'purchasing' );
				$item->update_meta_data( '_wasmou_idem', $idem );
				$item->save();
			}
			$inputs = $item->get_meta( '_wasmou_inputs' );
			$res    = Wasmou_WC_API::purchase( $vid, (int) $item->get_quantity(), is_array( $inputs ) ? $inputs : array(), $idem );

			if ( $res['ok'] ) {
				$ids = array_map( 'strval', (array) ( $res['data']['order_ids'] ?? array() ) );
				if ( ! $ids ) {
					$item->update_meta_data( '_wasmou_state', 'failed' );
					$item->update_meta_data( '_wasmou_error', 'Wasmou returned no order id' );
				} else {
					$item->update_meta_data( '_wasmou_state', 'ordered' );
					$item->update_meta_data( '_wasmou_order_ids', $ids );
					$order->add_order_note( sprintf( /* translators: 1: product, 2: ids */ __( 'Wasmou: purchased "%1$s" (Wasmou order %2$s).', 'wasmou-for-woocommerce' ), $item->get_name(), implode( ', ', $ids ) ) );
				}
			} elseif ( 'rejected' === $res['kind'] || 'config' === $res['kind'] ) {
				$item->update_meta_data( '_wasmou_state', 'failed' );
				$item->update_meta_data( '_wasmou_error', $res['message'] );
				$order->add_order_note( sprintf( /* translators: 1: product, 2: error */ __( 'Wasmou: could not buy "%1$s": %2$s', 'wasmou-for-woocommerce' ), $item->get_name(), $res['message'] ) );
				Wasmou_WC_Logger::error( "Order {$order_id} item {$item_id} rejected: " . $res['message'] );
			} else {
				// uncertain network problem or rate limit: same idempotency key next time, nothing is bought twice.
				$tries = (int) $item->get_meta( '_wasmou_tries' ) + ( 'retry' === $res['kind'] ? 0 : 1 );
				$item->update_meta_data( '_wasmou_tries', $tries );
				if ( $tries >= self::MAX_TRIES ) {
					$item->update_meta_data( '_wasmou_state', 'failed' );
					$item->update_meta_data( '_wasmou_error', 'Wasmou could not be reached: ' . $res['message'] );
					Wasmou_WC_Plugin::alert( 'unreachable-' . $order_id, __( 'Wasmou could not be reached for an order', 'wasmou-for-woocommerce' ), sprintf( /* translators: %d: order number */ __( 'Order #%d could not be sent to Wasmou after several tries. Check the order in Wasmou before refunding: the purchase may have gone through.', 'wasmou-for-woocommerce' ), $order->get_order_number() ) );
				} else {
					$pending = true;
				}
			}
			$item->save();
		}

		// 2) ask Wasmou about what was bought
		$wanted = array();
		foreach ( $order->get_items() as $item ) {
			if ( 'ordered' === $item->get_meta( '_wasmou_state' ) ) {
				$wanted = array_merge( $wanted, self::meta_list( $item, '_wasmou_order_ids' ) );
			}
		}
		$remote = array();
		if ( $wanted ) {
			$r = Wasmou_WC_API::orders( $wanted );
			if ( $r['ok'] ) {
				$remote = $r['data'];
			} else {
				$pending = true; // try again later
			}
		}
		foreach ( $order->get_items() as $item ) {
			if ( 'ordered' !== $item->get_meta( '_wasmou_state' ) || ! $remote ) {
				$pending = $pending || 'ordered' === $item->get_meta( '_wasmou_state' );
				continue;
			}
			$keys   = array();
			$failed = false;
			$wait   = false;
			$guide  = null;
			foreach ( self::meta_list( $item, '_wasmou_order_ids' ) as $oid ) {
				$o = $remote[ (string) $oid ] ?? null;
				if ( ! $o ) {
					$wait = true;
					continue;
				}
				$st = Wasmou_WC_API::normalise_status( $o );
				if ( 'delivered' === $st ) {
					$keys[] = (string) $o['key'];
					$guide  = $guide ? $guide : ( $o['activation'] ?? null );
				} elseif ( 'failed' === $st ) {
					$failed = true;
				} else {
					$wait = true;
				}
			}
			$vpost = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			if ( $keys && get_post_meta( $vpost, '_wasmou_async', true ) ) {
				// a subscription added to the buyer's own account: there is no code, only a confirmation (shown in the shop's language)
				$item->update_meta_data( '_wasmou_activated', 1 );
				$keys = array();
			}
			if ( $keys ) {
				$item->update_meta_data( '_wasmou_keys', $keys );
			}
			if ( $failed ) {
				$item->update_meta_data( '_wasmou_state', 'failed' );
				$item->update_meta_data( '_wasmou_error', 'Wasmou could not fulfil this order' );
			} elseif ( ! $wait ) {
				$item->update_meta_data( '_wasmou_state', 'delivered' );
				if ( $guide ) {
					$item->update_meta_data( '_wasmou_activation', $guide );
				}
			} else {
				$pending = true;
			}
			$item->save();
		}
		foreach ( $order->get_items() as $item ) {
			if ( self::item_variant_id( $item ) && in_array( (string) $item->get_meta( '_wasmou_state' ), array( '', 'purchasing' ), true ) ) {
				$pending = true;
			}
		}

		if ( $pending ) {
			$polls = (int) $order->get_meta( '_wasmou_polls' );
			if ( $polls > 400 ) { // ~ days of waiting
				Wasmou_WC_Plugin::alert( 'slow-' . $order_id, __( 'A Wasmou order is taking very long', 'wasmou-for-woocommerce' ), sprintf( /* translators: %d: order number */ __( 'Order #%d is still waiting for Wasmou. Please check it in your Wasmou account.', 'wasmou-for-woocommerce' ), $order->get_order_number() ) );
			}
			$order->update_meta_data( '_wasmou_polls', $polls + 1 );
			$order->save();
			self::schedule( $order_id, self::backoff( $polls ) );

			return;
		}
		self::finish( $order );
	}

	private static function finish( WC_Order $order ) {
		$failed = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( self::item_variant_id( $item ) && 'failed' === $item->get_meta( '_wasmou_state' ) ) {
				$failed[ $item_id ] = $item;
			}
		}
		$order->update_meta_data( '_wasmou_pending', '0' );
		$order->update_meta_data( '_wasmou_done', '1' );

		if ( ! $failed ) {
			$was_completed = $order->has_status( 'completed' );
			$order->add_order_note( __( 'Wasmou: everything was delivered.', 'wasmou-for-woocommerce' ) );
			$order->save();
			if ( Wasmou_WC_Settings::is_yes( 'auto_complete' ) && ! $was_completed ) {
				$order->update_status( 'completed', __( 'Wasmou delivered all codes.', 'wasmou-for-woocommerce' ) ); // the "completed" e-mail already contains the codes.
			} else {
				self::send_delivery_email( $order );
			}
			do_action( 'wasmou_wc_order_delivered', $order->get_id() );

			return;
		}

		$lines = array();
		foreach ( $failed as $item ) {
			$lines[] = $item->get_name() . ': ' . $item->get_meta( '_wasmou_error' );
		}
		$order->add_order_note( __( 'Wasmou: part of this order could not be delivered:', 'wasmou-for-woocommerce' ) . ' ' . implode( ' | ', $lines ) );
		$order->save();

		if ( 'refund' === Wasmou_WC_Settings::get( 'on_failure' ) ) {
			self::refund_failed( $order, $failed );
		} else {
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->update_status( 'on-hold', __( 'Wasmou could not fulfil this order: needs your attention.', 'wasmou-for-woocommerce' ) );
			}
		}
		Wasmou_WC_Plugin::alert( 'failed-' . $order->get_id(), sprintf( /* translators: %d: order number */ __( 'Order #%d could not be fulfilled by Wasmou', 'wasmou-for-woocommerce' ), $order->get_order_number() ), implode( "\n", $lines ) . "\n\n" . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
		do_action( 'wasmou_wc_order_failed', $order->get_id() );
	}

	private static function refund_failed( WC_Order $order, array $failed ) {
		$amount = 0.0;
		$lines  = array();
		foreach ( $failed as $item_id => $item ) {
			$tax                  = array_sum( $item->get_taxes()['total'] ?? array() );
			$amount              += (float) $item->get_total() + (float) $tax;
			$lines[ $item_id ] = array( 'qty' => $item->get_quantity(), 'refund_total' => (float) $item->get_total(), 'refund_tax' => (array) ( $item->get_taxes()['total'] ?? array() ) );
		}
		try {
			$refund = wc_create_refund( array( 'amount' => $amount, 'reason' => __( 'Wasmou could not deliver this product.', 'wasmou-for-woocommerce' ), 'order_id' => $order->get_id(), 'line_items' => $lines, 'refund_payment' => true ) );
			if ( is_wp_error( $refund ) ) {
				throw new RuntimeException( $refund->get_error_message() );
			}
			$order->add_order_note( __( 'Wasmou: failed items were refunded automatically.', 'wasmou-for-woocommerce' ) );
		} catch ( Throwable $e ) {
			$order->add_order_note( __( 'Wasmou: automatic refund failed, please refund manually:', 'wasmou-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->update_status( 'on-hold' );
		}
	}

	/** Manual retry from the order screen: failed items that were never bought can be tried again. */
	public static function retry( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( self::item_variant_id( $item ) && 'failed' === $item->get_meta( '_wasmou_state' ) && ! self::meta_list( $item, '_wasmou_order_ids' ) ) {
				$item->update_meta_data( '_wasmou_state', '' );
				$item->update_meta_data( '_wasmou_tries', 0 );
				$item->update_meta_data( '_wasmou_error', '' );
				// a fresh idempotency key: the previous attempt was refused, nothing was bought.
				$item->update_meta_data( '_wasmou_retry', (int) $item->get_meta( '_wasmou_retry' ) + 1 );
				$item->save();
			}
		}
		$order->update_meta_data( '_wasmou_done', '0' );
		$order->update_meta_data( '_wasmou_pending', '1' );
		$order->save();
		self::process( $order_id );
	}

	// ------------------------------------------------------------------ what the customer sees

	/** @return array<int, array{name:string, keys:array, state:string, guide:mixed, qty:int}> */
	public static function delivery_rows( WC_Order $order ) {
		$rows = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! self::item_variant_id( $item ) ) {
				continue;
			}
			$pid    = $item->get_product_id();
			$rows[] = array(
				'name'  => $item->get_name(),
				'keys'  => self::meta_list( $item, '_wasmou_keys' ),
				'activated' => (bool) $item->get_meta( '_wasmou_activated' ),
				'state' => (string) $item->get_meta( '_wasmou_state' ),
				'guide' => get_post_meta( $pid, '_wasmou_guide', true ),
				'qty'   => (int) $item->get_quantity(),
			);
		}

		return $rows;
	}

	private static function state_label( $state ) {
		switch ( $state ) {
			case 'delivered':
				return __( 'Delivered', 'wasmou-for-woocommerce' );
			case 'failed':
				return __( 'Delivery problem: we are looking into it', 'wasmou-for-woocommerce' );
			default:
				return __( 'Being prepared. This page updates by itself.', 'wasmou-for-woocommerce' );
		}
	}

	public static function html_block( WC_Order $order, $interactive = false ) {
		$rows = self::delivery_rows( $order );
		if ( ! $rows ) {
			return '';
		}
		$out = '<section class="wasmou-delivery"><h2>' . esc_html__( 'Your delivery', 'wasmou-for-woocommerce' ) . '</h2>';
		foreach ( $rows as $r ) {
			$out .= '<div class="wasmou-item"><h3>' . esc_html( $r['name'] ) . ( $r['qty'] > 1 ? ' × ' . (int) $r['qty'] : '' ) . '</h3>';
			if ( $r['keys'] ) {
				foreach ( $r['keys'] as $k ) {
					$out .= '<div class="wasmou-key"><pre dir="ltr">' . esc_html( $k ) . '</pre>' . ( $interactive ? '<button type="button" class="button wasmou-copy" data-copy="' . esc_attr( $k ) . '">' . esc_html__( 'Copy', 'wasmou-for-woocommerce' ) . '</button>' : '' ) . '</div>';
				}
				if ( is_array( $r['guide'] ) && ! empty( $r['guide']['steps'] ) ) {
					$out .= '<details class="wasmou-steps"' . ( $interactive ? ' open' : '' ) . '><summary>' . esc_html__( 'How to activate', 'wasmou-for-woocommerce' ) . '</summary><ol>';
					foreach ( $r['guide']['steps'] as $s ) {
						$out .= '<li>' . esc_html( $s ) . '</li>';
					}
					$out .= '</ol></details>';
				}
			} elseif ( $r['activated'] ) {
				$out .= '<p class="wasmou-state wasmou-delivered">✓ ' . esc_html__( 'Your subscription was added to your account. Check your e-mail and follow the steps below.', 'wasmou-for-woocommerce' ) . '</p>';
				if ( is_array( $r['guide'] ) && ! empty( $r['guide']['steps'] ) ) {
					$out .= '<details class="wasmou-steps"' . ( $interactive ? ' open' : '' ) . '><summary>' . esc_html__( 'How to activate', 'wasmou-for-woocommerce' ) . '</summary><ol>';
					foreach ( $r['guide']['steps'] as $st ) {
						$out .= '<li>' . esc_html( $st ) . '</li>';
					}
					$out .= '</ol></details>';
				}
			} else {
				$out .= '<p class="wasmou-state wasmou-' . esc_attr( $r['state'] ? $r['state'] : 'pending' ) . '">' . esc_html( self::state_label( $r['state'] ) ) . '</p>';
			}
			$out .= '</div>';
		}

		return $out . '</section>';
	}

	public static function account_block( $order ) {
		if ( ! $order instanceof WC_Order || ! self::has_wasmou_items( $order ) ) {
			return;
		}
		if ( isset( self::$rendered[ $order->get_id() ] ) ) {
			return;
		}
		self::$rendered[ $order->get_id() ] = true;
		self::nudge( $order );
		self::$needs_copy = true;
		$order = wc_get_order( $order->get_id() );
		echo wp_kses( self::html_block( $order, true ), self::allowed_html() );
		if ( $order->get_meta( '_wasmou_pending' ) ) {
			echo '<script>setTimeout(function(){location.reload();},15000);</script>';
		}
	}

	/** The block-based "order received" page: add our section under the order totals. */
	public static function confirmation_block( $content ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) || ! self::has_wasmou_items( $order ) || isset( self::$rendered[ $order_id ] ) ) {
			return $content;
		}
		self::$rendered[ $order_id ] = true;
		self::nudge( $order );
		self::$needs_copy = true;
		$order = wc_get_order( $order_id );
		$html  = wp_kses( self::html_block( $order, true ), self::allowed_html() );
		if ( $order->get_meta( '_wasmou_pending' ) ) {
			$html .= '<script>setTimeout(function(){location.reload();},15000);</script>';
		}

		return $content . $html;
	}

	public static function allowed_html() {
		return array_merge( wp_kses_allowed_html( 'post' ), array( 'button' => array( 'type' => true, 'class' => true, 'data-copy' => true ), 'details' => array( 'class' => true, 'open' => true ), 'summary' => array(), 'pre' => array( 'dir' => true ), 'section' => array( 'class' => true ) ) );
	}

	public static function email_block( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! self::has_wasmou_items( $order ) ) {
			return;
		}
		$rows = self::delivery_rows( $order );
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Your delivery', 'wasmou-for-woocommerce' ) . "\n----------------------------------------\n";
			foreach ( $rows as $r ) {
				echo esc_html( $r['name'] ) . "\n";
				if ( $r['keys'] ) {
					echo esc_html( implode( "\n", $r['keys'] ) ) . "\n\n";
				} elseif ( $r['activated'] ) {
					echo esc_html__( 'Your subscription was added to your account. Check your e-mail and follow the steps below.', 'wasmou-for-woocommerce' ) . "\n\n";
				} else {
					echo esc_html( self::state_label( $r['state'] ) ) . "\n\n";
				}
			}

			return;
		}
		echo wp_kses( self::html_block( $order, false ), self::allowed_html() );
	}

	public static function send_delivery_email( WC_Order $order ) {
		$to = $order->get_billing_email();
		if ( ! $to ) {
			return;
		}
		$mailer  = WC()->mailer();
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your order from %s is ready', 'wasmou-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = '<p>' . esc_html__( 'Good news, your order has been delivered:', 'wasmou-for-woocommerce' ) . '</p>' . wp_kses( self::html_block( $order, false ), self::allowed_html() );
		$message = $mailer->wrap_message( $subject, $body );
		$mailer->send( $to, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	public static function copy_script() {
		if ( ! self::$needs_copy ) {
			return;
		}
		?>
<script>document.addEventListener('click',function(e){var b=e.target.closest('.wasmou-copy');if(!b)return;var t=b.getAttribute('data-copy');(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){var o=b.textContent;b.textContent='✓';setTimeout(function(){b.textContent=o;},1400);}).catch(function(){var r=document.createRange();r.selectNodeContents(b.previousElementSibling);var s=getSelection();s.removeAllRanges();s.addRange(r);});});</script>
		<?php
	}
}
