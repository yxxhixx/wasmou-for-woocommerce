<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client for the Wasmou public API (https://api.wasmou.net/api).
 * Every call returns array( 'ok' => bool, 'kind' => ok|rejected|retry|uncertain|config, 'http' => int, 'message' => string, 'data' => mixed ).
 *  - rejected : Wasmou refused it (nothing was bought).
 *  - retry    : rate limited, nothing was bought, try again later.
 *  - uncertain: network error / gateway timeout. A purchase MAY have happened: retry with the SAME idempotency key.
 */
class Wasmou_WC_API {

	private static function result( $ok, $kind, $http, $message, $data = null ) {
		return compact( 'ok', 'kind', 'http', 'message', 'data' );
	}

	public static function request( $method, $path, ?array $body = null, array $headers = array(), $timeout = 30 ) {
		if ( ! Wasmou_WC_Settings::has_key() ) {
			return self::result( false, 'config', 0, __( 'Add your Wasmou API key in WooCommerce > Wasmou first.', 'wasmou-for-woocommerce' ) );
		}
		$url  = Wasmou_WC_Settings::get( 'api_url' ) . $path;
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'X-Api-Key'    => Wasmou_WC_Settings::get( 'api_key' ),
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Wasmou-WooCommerce/' . WASMOU_WC_VERSION . '; ' . home_url(),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			Wasmou_WC_Logger::log( "API {$method} {$path} network error: " . $res->get_error_message(), 'warning' );

			return self::result( false, 'uncertain', 0, $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		// International accounts are billed in USD: every answer says so, and every price in it is in USD.
		$cur = is_array( $json ) ? ( $json['currency'] ?? ( is_array( $json['data'] ?? null ) ? ( $json['data']['currency'] ?? null ) : null ) ) : null;
		if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
			if ( is_string( $cur ) ) {
				Wasmou_WC_Settings::set_account_currency( $cur );
			} elseif ( '/balance' === $path || 0 === strpos( $path, '/catalog' ) ) {
				Wasmou_WC_Settings::set_account_currency( 'DZD' ); // local accounts carry no currency field: back to dinar.
			}
		}
		$msg  = is_array( $json ) && isset( $json['message'] ) && is_string( $json['message'] ) ? $json['message'] : 'HTTP ' . $code;

		if ( 429 === $code ) {
			return self::result( false, 'retry', 429, __( 'Wasmou is busy (rate limit). Try again in a minute.', 'wasmou-for-woocommerce' ) );
		}
		if ( $code >= 200 && $code < 300 && is_array( $json ) && ( ! isset( $json['status'] ) || 'success' === $json['status'] ) ) {
			return self::result( true, 'ok', $code, $msg, isset( $json['data'] ) ? $json['data'] : $json );
		}
		if ( in_array( $code, array( 502, 503, 504 ), true ) ) {
			return self::result( false, 'uncertain', $code, $msg );
		}
		if ( 401 === $code || 403 === $code ) {
			Wasmou_WC_Logger::error( "API key rejected (HTTP {$code}) on {$path}" );
			$msg = __( 'Your Wasmou API key was rejected. Check the key and, if you restricted it to IP addresses, allow this server.', 'wasmou-for-woocommerce' );
		}

		$data = is_array( $json ) && isset( $json['data'] ) ? $json['data'] : null;
		// The API may wrap a refusal in a generic message; the real reason is in data.message / data.errors.
		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
				$msg = $data['message'];
			} elseif ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$first = reset( $data['errors'] );
				$msg   = is_array( $first ) ? (string) reset( $first ) : (string) $first;
			}
		}

		return self::result( false, 'rejected', $code, $msg, $data );
	}

	public static function balance() {
		$r = self::request( 'GET', '/balance', null, array(), 20 );
		if ( $r['ok'] ) {
			$r['data'] = array(
				'balance'   => (float) ( $r['data']['balance'] ?? 0 ),
				'available' => (float) ( $r['data']['available_balance'] ?? $r['data']['balance'] ?? 0 ),
			);
		}

		return $r;
	}

	public static function catalog() {
		return self::request( 'GET', '/catalog?lang=' . rawurlencode( Wasmou_WC_Settings::lang() ), null, array(), 90 );
	}

	/**
	 * Buy $quantity units of a variant. $inputs = buyer fields by index. The idempotency key makes retries safe.
	 */
	public static function purchase( $variant_id, $quantity, array $inputs, $idempotency_key, $reference = '' ) {
		$body = array( 'quantity' => max( 1, (int) $quantity ) );
		foreach ( $inputs as $i => $v ) {
			$body[ (string) $i ] = (string) $v;
		}
		// Your own order number: it comes back on the order, in lookups and in every webhook.
		if ( '' !== (string) $reference ) {
			$body['reference'] = substr( (string) $reference, 0, 128 );
		}

		return self::request( 'POST', '/products/purchase/' . (int) $variant_id, $body, array( 'Idempotency-Key' => $idempotency_key ), 60 );
	}

	/** Status of several Wasmou orders in one call. Returns data as list keyed by order_id. */
	public static function orders( array $ids ) {
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		if ( ! $ids ) {
			return self::result( true, 'ok', 200, '', array() );
		}
		$r = self::request( 'POST', '/products/orders/retrieve', array( 'order_ids' => $ids ), array(), 30 );
		if ( $r['ok'] ) {
			$by = array();
			foreach ( (array) $r['data'] as $o ) {
				if ( isset( $o['order_id'] ) ) {
					$by[ (string) $o['order_id'] ] = $o;
				}
			}
			$r['data'] = $by;
		}

		return $r;
	}

	/** The webhook configured on the account (one per account), or null. */
	public static function webhook() {
		$r = self::request( 'GET', '/webhook', null, array(), 20 );

		return $r;
	}

	/** Creates the webhook. The answer carries the signing secret once. */
	public static function webhook_set( $url, array $events ) {
		return self::request( 'PUT', '/webhook', array( 'url' => $url, 'events' => array_values( $events ) ), array(), 25 );
	}

	public static function webhook_new_secret() {
		return self::request( 'POST', '/webhook/secret', array(), array(), 20 );
	}

	public static function webhook_remove() {
		return self::request( 'DELETE', '/webhook', null, array(), 20 );
	}

	public static function webhook_test() {
		return self::request( 'POST', '/webhook/test', array(), array(), 25 );
	}

	/** Normalises Wasmou order statuses: delivered | failed | pending. */
	public static function normalise_status( array $o ) {
		$status = strtolower( (string) ( $o['status'] ?? '' ) );
		if ( in_array( $status, array( 'failed', 'refunded', 'canceled', 'cancelled', 'rejected' ), true ) ) {
			return 'failed';
		}
		if ( ! empty( $o['has_key'] ) && ! empty( $o['key'] ) ) {
			return 'delivered';
		}

		return 'pending';
	}
}
