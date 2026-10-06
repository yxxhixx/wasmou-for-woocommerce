<?php
defined( 'ABSPATH' ) || exit;

/** Boot, cron, alerts. */
class Wasmou_WC_Plugin {

	const SYNC_HOOK = 'wasmou_wc_sync';

	public static function init() {
		load_plugin_textdomain( 'wasmou-for-woocommerce', false, dirname( plugin_basename( WASMOU_WC_FILE ) ) . '/languages' );
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Wasmou for WooCommerce needs WooCommerce to be installed and active.', 'wasmou-for-woocommerce' ) . '</p></div>';
				}
			);

			return;
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::SYNC_HOOK, array( __CLASS__, 'cron_sync' ) );
		Wasmou_WC_Storefront::hooks();
		Wasmou_WC_Fulfillment::hooks();
		if ( is_admin() ) {
			Wasmou_WC_Admin::hooks();
		}
		self::ensure_schedules();
	}

	public static function schedules( $s ) {
		$s['wasmou_5min']  = array( 'interval' => 300, 'display' => __( 'Every 5 minutes (Wasmou)', 'wasmou-for-woocommerce' ) );
		$s['wasmou_sync'] = array( 'interval' => max( 5, (int) Wasmou_WC_Settings::get( 'sync_minutes', 15 ) ) * 60, 'display' => __( 'Wasmou catalog sync', 'wasmou-for-woocommerce' ) );

		return $s;
	}

	public static function ensure_schedules() {
		if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) {
			wp_schedule_event( time() + 120, 'wasmou_sync', self::SYNC_HOOK );
		}
		if ( ! wp_next_scheduled( Wasmou_WC_Fulfillment::WATCH_HOOK ) ) {
			wp_schedule_event( time() + 180, 'wasmou_5min', Wasmou_WC_Fulfillment::WATCH_HOOK );
		}
	}

	public static function reschedule() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );
		self::ensure_schedules();
	}

	public static function activate() {
		if ( false === get_option( Wasmou_WC_Settings::OPTION ) ) {
			add_option( Wasmou_WC_Settings::OPTION, Wasmou_WC_Settings::defaults(), '', 'no' );
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		self::ensure_schedules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );
		wp_clear_scheduled_hook( Wasmou_WC_Fulfillment::WATCH_HOOK );
	}

	/** Prices, stock and plans of imported products, refreshed in the background. */
	public static function cron_sync() {
		return self::sync_now();
	}

	public static function sync_now() {
		if ( ! Wasmou_WC_Settings::has_key() ) {
			return array( 'ok' => false, 'message' => __( 'No API key', 'wasmou-for-woocommerce' ) );
		}
		$cat = Wasmou_WC_Catalog::get( 0, true );
		if ( ! $cat['ok'] ) {
			update_option( 'wasmou_wc_last_sync', array( 'time' => time(), 'error' => $cat['message'] ), false );

			return array( 'ok' => false, 'message' => $cat['message'] );
		}
		$stats = Wasmou_WC_Importer::refresh_imported( $cat['data'] );
		self::wallet_balance( true );

		return array( 'ok' => true, 'stats' => $stats );
	}

	/** Spendable Wasmou balance in DZD (cached 45 s), or null when it cannot be read. */
	public static function wallet_balance( $force = false ) {
		$c = get_transient( 'wasmou_wc_wallet' );
		if ( ! $force && is_array( $c ) ) {
			return $c['v'];
		}
		$r = Wasmou_WC_API::balance();
		if ( ! $r['ok'] ) {
			return is_array( $c ) ? $c['v'] : null;
		}
		$v = (float) $r['data']['available'];
		set_transient( 'wasmou_wc_wallet', array( 'v' => $v ), 45 );
		$low = (float) Wasmou_WC_Settings::get( 'low_balance_dzd', 0 );
		if ( $low > 0 && $v < $low ) {
			self::alert( 'low-balance', __( 'Your Wasmou balance is low', 'wasmou-for-woocommerce' ), sprintf( /* translators: %s: amount */ __( 'Your Wasmou balance is %s DZD. Add funds so your customers keep getting instant delivery: https://myapp.wasmou.net/deposit', 'wasmou-for-woocommerce' ), number_format_i18n( $v, 2 ) ) );
		}

		return $v;
	}

	/** One e-mail per kind of problem per 6 hours. */
	public static function alert( $key, $subject, $message ) {
		$t = 'wasmou_wc_alert_' . md5( $key );
		if ( get_transient( $t ) ) {
			return;
		}
		set_transient( $t, 1, 6 * HOUR_IN_SECONDS );
		wp_mail( Wasmou_WC_Settings::notify_email(), '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $subject, $message );
	}
}
