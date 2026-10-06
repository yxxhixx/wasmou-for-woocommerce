<?php
defined( 'ABSPATH' ) || exit;

/** Turns a Wasmou price (DZD) into the shop price: currency conversion, markup, rounding. */
class Wasmou_WC_Pricing {

	/** DZD per 1 unit of the store currency. */
	public static function rate() {
		if ( function_exists( 'get_woocommerce_currency' ) && 'DZD' === get_woocommerce_currency() ) {
			return 1.0;
		}
		$r = (float) Wasmou_WC_Settings::get( 'exchange_rate', 1 );

		return $r > 0 ? $r : 1.0;
	}

	/** Cost converted to the store currency (no markup). */
	public static function cost_in_store_currency( $cost_dzd ) {
		return (float) $cost_dzd / self::rate();
	}

	public static function sell( $cost_dzd, ?array $s = null ) {
		$s    = $s ? $s : Wasmou_WC_Settings::all();
		$base = self::cost_in_store_currency( $cost_dzd );
		$val  = (float) $s['markup_value'];
		$p    = 'fixed' === $s['markup_type'] ? $base + $val : $base * ( 1 + $val / 100 );
		$p    = self::round_price( $p, $s['rounding'] );

		// Never below cost, whatever the rounding does.
		return max( round( $base, 4 ), $p );
	}

	public static function round_price( $price, $mode ) {
		$dec = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		switch ( $mode ) {
			case 'integer':
				return ceil( $price );
			case 'ends_99':
				$f = floor( $price ) + 0.99;

				return $f < $price ? $f + 1 : $f;
			case 'multiple_5':
				return ceil( $price / 5 ) * 5;
			case 'multiple_10':
				return ceil( $price / 10 ) * 10;
			default:
				return round( $price, $dec );
		}
	}
}
