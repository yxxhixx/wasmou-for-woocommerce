<?php
defined( 'ABSPATH' ) || exit;

/**
 * Instant updates: Wasmou calls this site when an order reaches a final state, so the customer gets the code in seconds instead of
 * waiting for the next poll. The event is only a nudge: it is verified (HMAC), then the normal fulfillment engine asks the API for
 * the real order, so a fake or repeated event can never deliver or buy anything by itself. Polling keeps running as a safety net.
 */
class Wasmou_WC_Webhook {

	const ROUTE_NS   = 'wasmou/v1';
	const ROUTE      = '/webhook';
	const EVENTS     = array( 'order.completed', 'order.failed', 'order.refunded', 'order.canceled' );
	const TOLERANCE  = 300;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function endpoint() {
		return rest_url( self::ROUTE_NS . self::ROUTE );
	}

	public static function register() {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true', // the signature is the permission.
			)
		);
	}

	/** True when $signature ("v1=hex") matches HMAC-SHA256( "{timestamp}.{raw body}", $secret ) and the timestamp is fresh. */
	public static function valid( $secret, $timestamp, $raw, $signature, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( '' === (string) $secret || '' === (string) $timestamp || ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}
		if ( abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$given    = 0 === strpos( (string) $signature, 'v1=' ) ? substr( (string) $signature, 3 ) : '';
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, (string) $secret );

		return '' !== $given && hash_equals( $expected, $given );
	}

	/** Our own order reference is "WC-{order id}-{item id}": this returns the order id, or 0. */
	public static function order_from_reference( $reference ) {
		return preg_match( '/^WC-(\d+)-\d+$/', (string) $reference, $m ) ? (int) $m[1] : 0;
	}

	public static function receive( WP_REST_Request $req ) {
		$raw = $req->get_body();
		if ( ! self::valid( Wasmou_WC_Settings::get( 'webhook_secret', '' ), (string) $req->get_header( 'X-Wasmou-Timestamp' ), $raw, (string) $req->get_header( 'X-Wasmou-Signature' ) ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}
		if ( 'webhook.test' === $event['type'] ) {
			update_option( 'wasmou_wc_webhook_last', array( 'time' => time(), 'type' => 'webhook.test' ), false );

			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		// at least once: an id seen in the last day is answered but not handled again.
		$eid = isset( $event['id'] ) ? 'wasmou_wc_evt_' . md5( (string) $event['id'] ) : '';
		if ( $eid && get_transient( $eid ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
		}
		if ( $eid ) {
			set_transient( $eid, 1, DAY_IN_SECONDS );
		}
		update_option( 'wasmou_wc_webhook_last', array( 'time' => time(), 'type' => (string) $event['type'] ), false );

		$order_id = self::order_from_reference( $event['data']['reference'] ?? '' );
		if ( $order_id && in_array( $event['type'], self::EVENTS, true ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && Wasmou_WC_Fulfillment::has_wasmou_items( $order ) && ! $order->get_meta( '_wasmou_done' ) ) {
				// answer inside the 8 seconds Wasmou allows: the real work runs in the background, a few seconds from now.
				Wasmou_WC_Fulfillment::schedule( $order_id, 1 );
				if ( function_exists( 'spawn_cron' ) ) {
					spawn_cron();
				}
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// ------------------------------------------------------------------ switching it on and off (admin)

	/** @return array{ok:bool,message:string} */
	public static function enable() {
		$url = self::endpoint();
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return array( 'ok' => false, 'message' => __( 'Instant updates need your site to use https://.', 'wasmou-for-woocommerce' ) );
		}
		$cur = Wasmou_WC_API::webhook();
		if ( ! $cur['ok'] ) {
			return array( 'ok' => false, 'message' => $cur['message'] );
		}
		$have = (array) $cur['data'];
		if ( ! empty( $have['configured'] ) && ! empty( $have['url'] ) && $have['url'] !== $url ) {
			// an account has ONE webhook: never overwrite the one the owner set up for something else.
			return array( 'ok' => false, 'message' => sprintf( /* translators: %s: address */ __( 'Your Wasmou account already sends webhooks to %s. The plugin will not replace it. Remove it in Wasmou (API access) first, or keep using the normal updates.', 'wasmou-for-woocommerce' ), $have['url'] ) );
		}
		$r = Wasmou_WC_API::webhook_set( $url, self::EVENTS );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'message' => $r['message'] );
		}
		$secret = (string) ( $r['data']['secret'] ?? '' );
		if ( '' === $secret ) {
			$n      = Wasmou_WC_API::webhook_new_secret(); // the address existed already: a new secret is the only way to get one.
			$secret = $n['ok'] ? (string) ( $n['data']['secret'] ?? '' ) : '';
		}
		if ( '' === $secret ) {
			return array( 'ok' => false, 'message' => __( 'Wasmou did not return a signing secret. Try again.', 'wasmou-for-woocommerce' ) );
		}
		$s                   = Wasmou_WC_Settings::all();
		$s['webhook_secret'] = $secret;
		$s['webhook_url']    = $url;
		Wasmou_WC_Settings::save( $s );
		$t = Wasmou_WC_API::webhook_test();

		return array( 'ok' => true, 'message' => $t['ok'] ? __( 'Instant updates are on and the test message arrived.', 'wasmou-for-woocommerce' ) : __( 'Instant updates are on, but the test message did not arrive. Check that your site is reachable from the internet.', 'wasmou-for-woocommerce' ) );
	}

	public static function disable() {
		$s = Wasmou_WC_Settings::all();
		if ( '' !== $s['webhook_url'] ) {
			$cur = Wasmou_WC_API::webhook();
			if ( $cur['ok'] && ! empty( $cur['data']['configured'] ) && ( $cur['data']['url'] ?? '' ) === $s['webhook_url'] ) {
				Wasmou_WC_API::webhook_remove(); // only the one this plugin created.
			}
		}
		$s['webhook_secret'] = '';
		$s['webhook_url']    = '';
		Wasmou_WC_Settings::save( $s );

		return array( 'ok' => true, 'message' => __( 'Instant updates are off. Orders are still checked every few minutes.', 'wasmou-for-woocommerce' ) );
	}

	public static function is_on() {
		return '' !== Wasmou_WC_Settings::get( 'webhook_secret', '' ) && '' !== Wasmou_WC_Settings::get( 'webhook_url', '' );
	}
}
