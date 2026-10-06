<?php
defined( 'ABSPATH' ) || exit;

/** Plugin settings (one option, not autoloaded). */
class Wasmou_WC_Settings {

	const OPTION = 'wasmou_wc_settings';

	public static function defaults() {
		return array(
			'api_key'            => '',
			'api_url'            => 'https://api.wasmou.net/api',
			'markup_type'        => 'percent', // percent | fixed.
			'markup_value'       => '15',
			'rounding'           => 'none',    // none | integer | ends_99 | multiple_5 | multiple_10.
			'exchange_rate'      => '1',       // Wasmou prices are in DZD: how many DZD equal 1 unit of the store currency.
			'auto_complete'      => 'yes',
			'on_failure'         => 'hold',    // hold | refund.
			'sync_minutes'       => '15',
			'import_images'      => 'yes',
			'guide_in_product'   => 'yes',
			'low_balance_dzd'    => '5000',
			'notify_email'       => '',
			'catalog_lang'       => 'auto',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function is_yes( $key ) {
		return 'yes' === self::get( $key );
	}

	/** Cleans submitted values. Pass the previous array so an empty key field keeps the stored key. */
	public static function sanitize( array $in, array $old ) {
		$d   = self::defaults();
		$out = $old + $d;

		if ( isset( $in['api_key'] ) ) {
			$key = trim( wp_unslash( $in['api_key'] ) );
			if ( '' !== $key && false === strpos( $key, '•' ) ) {
				$out['api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $key );
			}
		}
		if ( isset( $in['api_url'] ) ) {
			$url            = esc_url_raw( trim( wp_unslash( $in['api_url'] ) ), array( 'https', 'http' ) );
			$out['api_url'] = $url ? untrailingslashit( $url ) : $d['api_url'];
		}
		$out['markup_type']  = isset( $in['markup_type'] ) && in_array( $in['markup_type'], array( 'percent', 'fixed' ), true ) ? $in['markup_type'] : $out['markup_type'];
		if ( isset( $in['markup_value'] ) ) {
			$v                   = (float) wp_unslash( $in['markup_value'] );
			$out['markup_value'] = (string) max( 0, min( 100000, $v ) );
		}
		$out['rounding'] = isset( $in['rounding'] ) && in_array( $in['rounding'], array( 'none', 'integer', 'ends_99', 'multiple_5', 'multiple_10' ), true ) ? $in['rounding'] : $out['rounding'];
		if ( isset( $in['exchange_rate'] ) ) {
			$r                    = (float) wp_unslash( $in['exchange_rate'] );
			$out['exchange_rate'] = (string) ( $r > 0 ? $r : 1 );
		}
		foreach ( array( 'auto_complete', 'import_images', 'guide_in_product' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) && 'yes' === $in[ $k ] ? 'yes' : 'no';
		}
		$out['on_failure']  = isset( $in['on_failure'] ) && in_array( $in['on_failure'], array( 'hold', 'refund' ), true ) ? $in['on_failure'] : $out['on_failure'];
		$out['sync_minutes'] = isset( $in['sync_minutes'] ) ? (string) max( 5, min( 1440, (int) $in['sync_minutes'] ) ) : $out['sync_minutes'];
		if ( isset( $in['low_balance_dzd'] ) ) {
			$out['low_balance_dzd'] = (string) max( 0, (float) $in['low_balance_dzd'] );
		}
		if ( isset( $in['notify_email'] ) ) {
			$mail                = sanitize_email( wp_unslash( $in['notify_email'] ) );
			$out['notify_email'] = is_email( $mail ) ? $mail : '';
		}
		$out['catalog_lang'] = isset( $in['catalog_lang'] ) && in_array( $in['catalog_lang'], array( 'auto', 'en', 'fr', 'ar' ), true ) ? $in['catalog_lang'] : $out['catalog_lang'];

		return $out;
	}

	public static function save( array $settings ) {
		update_option( self::OPTION, $settings, false );
	}

	public static function has_key() {
		return '' !== self::get( 'api_key' );
	}

	public static function masked_key() {
		$k = (string) self::get( 'api_key' );
		if ( strlen( $k ) < 9 ) {
			return $k ? str_repeat( '•', strlen( $k ) ) : '';
		}

		return substr( $k, 0, 4 ) . str_repeat( '•', 12 ) . substr( $k, -3 );
	}

	/** Language of activation guides: follows the site language unless forced. */
	public static function lang() {
		$l = self::get( 'catalog_lang', 'auto' );
		if ( 'auto' !== $l ) {
			return $l;
		}
		$loc = strtolower( substr( determine_locale(), 0, 2 ) );

		return in_array( $loc, array( 'ar', 'fr' ), true ) ? $loc : 'en';
	}

	public static function notify_email() {
		$m = self::get( 'notify_email' );

		return $m ? $m : get_option( 'admin_email' );
	}
}
