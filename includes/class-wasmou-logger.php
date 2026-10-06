<?php
defined( 'ABSPATH' ) || exit;

/** Writes to WooCommerce > Status > Logs (source "wasmou"). API keys are never logged. */
class Wasmou_WC_Logger {

	public static function log( $message, $level = 'info', array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = 'wasmou';
		wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), $context );
	}

	public static function error( $message ) {
		self::log( $message, 'error' );
	}
}
