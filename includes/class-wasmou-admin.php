<?php
defined( 'ABSPATH' ) || exit;

/** WooCommerce > Wasmou: connection, pricing, import, delivery settings, status. Plus the order box and dashboard widget. */
class Wasmou_WC_Admin {

	const SLUG = 'wasmou';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wasmou_wc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wasmou_wc_order_action', array( __CLASS__, 'order_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 10, 2 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WASMOU_WC_FILE ), array( __CLASS__, 'links' ) );
		foreach ( array( 'test', 'list', 'import', 'sync' ) as $a ) {
			add_action( 'wp_ajax_wasmou_wc_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'wasmou-for-woocommerce' ) . '</a>' );

		return $links;
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Wasmou', 'wasmou-for-woocommerce' ), __( 'Wasmou', 'wasmou-for-woocommerce' ), 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wasmou-wc-admin', WASMOU_WC_URL . 'assets/admin.css', array(), WASMOU_WC_VERSION );
		wp_enqueue_script( 'wasmou-wc-admin', WASMOU_WC_URL . 'assets/admin.js', array(), WASMOU_WC_VERSION, true );
		wp_localize_script(
			'wasmou-wc-admin',
			'WasmouWC',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wasmou_wc_admin' ),
				'i18n'  => array(
					'testing'   => __( 'Testing…', 'wasmou-for-woocommerce' ),
					'loading'   => __( 'Loading the catalog…', 'wasmou-for-woocommerce' ),
					'importing' => __( 'Importing…', 'wasmou-for-woocommerce' ),
					'done'      => __( 'Done', 'wasmou-for-woocommerce' ),
					'selected'  => __( 'selected', 'wasmou-for-woocommerce' ),
					'imported'  => __( 'Imported', 'wasmou-for-woocommerce' ),
					'new'       => __( 'New', 'wasmou-for-woocommerce' ),
					'from'      => __( 'from', 'wasmou-for-woocommerce' ),
					'plans'     => __( 'plans', 'wasmou-for-woocommerce' ),
					'plan'      => __( 'plan', 'wasmou-for-woocommerce' ),
					'all'       => __( 'All categories', 'wasmou-for-woocommerce' ),
					'error'     => __( 'Something went wrong', 'wasmou-for-woocommerce' ),
					'syncing'   => __( 'Syncing…', 'wasmou-for-woocommerce' ),
					'none'      => __( 'No product matches.', 'wasmou-for-woocommerce' ),
				),
			)
		);
	}

	public static function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! Wasmou_WC_Settings::has_key() && $screen && ( false !== strpos( $screen->id, 'woocommerce' ) || 'plugins' === $screen->id ) && ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-warning"><p><strong>Wasmou:</strong> ' . esc_html__( 'add your API key to start selling.', 'wasmou-for-woocommerce' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open settings', 'wasmou-for-woocommerce' ) . '</a></p></div>';
		}
	}

	private static function tabs() {
		return array(
			'connection' => __( 'Connection', 'wasmou-for-woocommerce' ),
			'pricing'    => __( 'Pricing', 'wasmou-for-woocommerce' ),
			'import'     => __( 'Import products', 'wasmou-for-woocommerce' ),
			'delivery'   => __( 'Delivery', 'wasmou-for-woocommerce' ),
			'status'     => __( 'Status', 'wasmou-for-woocommerce' ),
		);
	}

	// ------------------------------------------------------------------ page

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wasmou-for-woocommerce' ) );
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap wasmou-wrap"><h1 class="wasmou-title"><span class="wasmou-logo">W</span> Wasmou <small>v' . esc_html( WASMOU_WC_VERSION ) . '</small></h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wasmou-for-woocommerce' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $k === $tab ? 'nav-tab-active' : '', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $k ) ), esc_html( $label ) );
		}
		echo '</nav><div class="wasmou-panel">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div></div>';
	}

	private static function form_open( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wasmou_wc_save" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( 'wasmou_wc_save', '_wasmou_nonce' );
	}

	private static function row( $label, $html, $help = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function select( $name, $value, array $opts ) {
		$h = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $k => $l ) {
			$h .= '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
		}

		return $h . '</select>';
	}

	private static function tab_connection() {
		$s = Wasmou_WC_Settings::all();
		echo '<p class="wasmou-lead">' . esc_html__( 'Connect your store to Wasmou. Your customers pay you; we deliver the codes and subscriptions automatically from your Wasmou wallet.', 'wasmou-for-woocommerce' ) . '</p>';
		self::form_open( 'connection' );
		echo '<table class="form-table">';
		self::row( __( 'API key', 'wasmou-for-woocommerce' ), '<input type="text" class="regular-text code" name="api_key" value="' . esc_attr( Wasmou_WC_Settings::masked_key() ) . '" autocomplete="off" placeholder="' . esc_attr__( 'Paste your key', 'wasmou-for-woocommerce' ) . '" />', __( 'Find it in your Wasmou account: menu > API access. Leave the masked value to keep the saved key.', 'wasmou-for-woocommerce' ) );
		self::row( __( 'API address', 'wasmou-for-woocommerce' ), '<input type="url" class="regular-text code" name="api_url" value="' . esc_attr( $s['api_url'] ) . '" />', __( 'Only change this if Wasmou tells you to.', 'wasmou-for-woocommerce' ) );
		self::row( __( 'Language of guides', 'wasmou-for-woocommerce' ), self::select( 'catalog_lang', $s['catalog_lang'], array( 'auto' => __( 'Same as the site', 'wasmou-for-woocommerce' ), 'en' => 'English', 'fr' => 'Français', 'ar' => 'العربية' ) ), __( 'Language of activation guides and descriptions shown on your products.', 'wasmou-for-woocommerce' ) );
		echo '</table><p class="submit"><button class="button button-primary">' . esc_html__( 'Save', 'wasmou-for-woocommerce' ) . '</button> <button type="button" class="button" id="wasmou-test">' . esc_html__( 'Test connection', 'wasmou-for-woocommerce' ) . '</button> <span id="wasmou-test-result" class="wasmou-inline"></span></p></form>';
		echo '<div class="wasmou-help"><h3>' . esc_html__( 'Good to know', 'wasmou-for-woocommerce' ) . '</h3><ul><li>' . esc_html__( 'If you restricted your key to IP addresses in Wasmou, allow your hosting server IP, or leave the list empty.', 'wasmou-for-woocommerce' ) . '</li><li>' . esc_html__( 'Keep money in your Wasmou wallet: orders are bought from it when your customer pays.', 'wasmou-for-woocommerce' ) . '</li></ul></div>';
	}

	private static function tab_pricing() {
		$s    = Wasmou_WC_Settings::all();
		$cur  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		echo '<p class="wasmou-lead">' . esc_html__( 'Wasmou prices are in Algerian dinar (DZD). Choose how they become your shop prices.', 'wasmou-for-woocommerce' ) . '</p>';
		self::form_open( 'pricing' );
		echo '<table class="form-table">';
		self::row( __( 'Your margin', 'wasmou-for-woocommerce' ), self::select( 'markup_type', $s['markup_type'], array( 'percent' => __( 'Percent (%) on top of cost', 'wasmou-for-woocommerce' ), 'fixed' => __( 'Fixed amount added to cost', 'wasmou-for-woocommerce' ) ) ) . ' <input type="number" step="0.01" min="0" name="markup_value" value="' . esc_attr( $s['markup_value'] ) . '" class="small-text" />', __( 'A fixed amount is in your store currency.', 'wasmou-for-woocommerce' ) );
		if ( 'DZD' === $cur ) {
			self::row( __( 'Currency', 'wasmou-for-woocommerce' ), '<strong>DZD</strong> — ' . esc_html__( 'same as Wasmou, no conversion.', 'wasmou-for-woocommerce' ) );
		} else {
			self::row( /* translators: %s: currency code */ sprintf( __( 'Exchange rate (DZD per 1 %s)', 'wasmou-for-woocommerce' ), $cur ), '<input type="number" step="0.0001" min="0.0001" name="exchange_rate" value="' . esc_attr( $s['exchange_rate'] ) . '" class="small-text" />', __( 'Example: if 1 USD = 250 DZD, enter 250. Prices are recalculated at every sync.', 'wasmou-for-woocommerce' ) );
		}
		self::row( __( 'Rounding', 'wasmou-for-woocommerce' ), self::select( 'rounding', $s['rounding'], array( 'none' => __( 'None (store decimals)', 'wasmou-for-woocommerce' ), 'integer' => __( 'Up to a whole number', 'wasmou-for-woocommerce' ), 'ends_99' => __( 'End in .99', 'wasmou-for-woocommerce' ), 'multiple_5' => __( 'Up to a multiple of 5', 'wasmou-for-woocommerce' ), 'multiple_10' => __( 'Up to a multiple of 10', 'wasmou-for-woocommerce' ) ) ) );
		echo '</table>';
		$example = Wasmou_WC_Pricing::sell( 1000.0, $s );
		echo '<p class="wasmou-example">' . esc_html__( 'Example: a product that costs 1,000 DZD will sell for', 'wasmou-for-woocommerce' ) . ' <strong>' . wp_kses_post( wc_price( $example ) ) . '</strong></p>';
		echo '<p class="submit"><button class="button button-primary">' . esc_html__( 'Save', 'wasmou-for-woocommerce' ) . '</button></p></form>';
	}

	private static function tab_import() {
		echo '<p class="wasmou-lead">' . esc_html__( 'Pick what you want to sell. Products come with images, prices, warranty and activation guides. You can edit them afterwards: re-syncing only refreshes prices and stock.', 'wasmou-for-woocommerce' ) . '</p>';
		if ( ! Wasmou_WC_Settings::has_key() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Add your API key first.', 'wasmou-for-woocommerce' ) . '</p></div>';

			return;
		}
		?>
		<div id="wasmou-import" class="wasmou-import">
			<div class="wasmou-toolbar">
				<input type="search" id="wasmou-q" placeholder="<?php echo esc_attr__( 'Search products…', 'wasmou-for-woocommerce' ); ?>" />
				<select id="wasmou-cat"></select>
				<label><input type="checkbox" id="wasmou-hide-imported" /> <?php echo esc_html__( 'Hide imported', 'wasmou-for-woocommerce' ); ?></label>
				<span class="wasmou-spacer"></span>
				<span id="wasmou-count"></span>
				<button type="button" class="button" id="wasmou-select-all"><?php echo esc_html__( 'Select all shown', 'wasmou-for-woocommerce' ); ?></button>
				<button type="button" class="button button-primary" id="wasmou-do-import" disabled><?php echo esc_html__( 'Import selected', 'wasmou-for-woocommerce' ); ?></button>
			</div>
			<div id="wasmou-progress" class="wasmou-progress" hidden><div></div></div>
			<div id="wasmou-msg" class="wasmou-inline"></div>
			<div id="wasmou-grid" class="wasmou-grid"></div>
		</div>
		<?php
	}

	private static function tab_delivery() {
		$s = Wasmou_WC_Settings::all();
		self::form_open( 'delivery' );
		echo '<table class="form-table">';
		self::row( __( 'After delivery', 'wasmou-for-woocommerce' ), '<label><input type="checkbox" name="auto_complete" value="yes" ' . checked( 'yes', $s['auto_complete'], false ) . ' /> ' . esc_html__( 'Mark the order as Completed (the customer gets the codes by e-mail)', 'wasmou-for-woocommerce' ) . '</label>' );
		self::row( __( 'If Wasmou cannot deliver', 'wasmou-for-woocommerce' ), self::select( 'on_failure', $s['on_failure'], array( 'hold' => __( 'Put the order On hold and e-mail me', 'wasmou-for-woocommerce' ), 'refund' => __( 'Refund the failed items automatically', 'wasmou-for-woocommerce' ) ) ), __( 'Automatic refunds use your payment gateway when it supports refunds.', 'wasmou-for-woocommerce' ) );
		self::row( __( 'Activation guide', 'wasmou-for-woocommerce' ), '<label><input type="checkbox" name="guide_in_product" value="yes" ' . checked( 'yes', $s['guide_in_product'], false ) . ' /> ' . esc_html__( 'Show the "How to activate" tab on product pages', 'wasmou-for-woocommerce' ) . '</label>' );
		self::row( __( 'Product images', 'wasmou-for-woocommerce' ), '<label><input type="checkbox" name="import_images" value="yes" ' . checked( 'yes', $s['import_images'], false ) . ' /> ' . esc_html__( 'Download product images when importing', 'wasmou-for-woocommerce' ) . '</label>' );
		self::row( __( 'Sync every', 'wasmou-for-woocommerce' ), '<input type="number" min="5" max="1440" name="sync_minutes" value="' . esc_attr( $s['sync_minutes'] ) . '" class="small-text" /> ' . esc_html__( 'minutes', 'wasmou-for-woocommerce' ), __( 'How often prices and stock are refreshed.', 'wasmou-for-woocommerce' ) );
		self::row( __( 'Alert me when my Wasmou balance is below', 'wasmou-for-woocommerce' ), '<input type="number" min="0" step="1" name="low_balance_dzd" value="' . esc_attr( $s['low_balance_dzd'] ) . '" class="small-text" /> DZD' );
		self::row( __( 'Alerts go to', 'wasmou-for-woocommerce' ), '<input type="email" name="notify_email" value="' . esc_attr( $s['notify_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" />' );
		echo '</table><p class="submit"><button class="button button-primary">' . esc_html__( 'Save', 'wasmou-for-woocommerce' ) . '</button></p></form>';
	}

	private static function tab_status() {
		$bal   = Wasmou_WC_Settings::has_key() ? Wasmou_WC_Plugin::wallet_balance( true ) : null;
		$last  = get_option( 'wasmou_wc_last_sync' );
		$pending = count( wc_get_orders( array( 'limit' => 200, 'return' => 'ids', 'meta_key' => '_wasmou_pending', 'meta_value' => '1' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		global $wpdb;
		$imported = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wasmou_product_id'" );
		$rows = array(
			__( 'Wasmou balance', 'wasmou-for-woocommerce' ) => null === $bal ? '—' : number_format_i18n( $bal, 2 ) . ' DZD',
			__( 'Imported products', 'wasmou-for-woocommerce' ) => (string) $imported,
			__( 'Orders being delivered', 'wasmou-for-woocommerce' ) => (string) $pending,
			__( 'Last sync', 'wasmou-for-woocommerce' ) => is_array( $last ) ? human_time_diff( (int) $last['time'] ) . ' ' . __( 'ago', 'wasmou-for-woocommerce' ) . ( ! empty( $last['error'] ) ? ' — ' . $last['error'] : '' ) : __( 'never', 'wasmou-for-woocommerce' ),
			__( 'Store currency', 'wasmou-for-woocommerce' ) => get_woocommerce_currency() . ( 'DZD' === get_woocommerce_currency() ? '' : ' (1 = ' . Wasmou_WC_Pricing::rate() . ' DZD)' ),
			__( 'WP-Cron', 'wasmou-for-woocommerce' ) => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? __( 'Disabled: set up a real cron job calling wp-cron.php every minute', 'wasmou-for-woocommerce' ) : __( 'Active', 'wasmou-for-woocommerce' ),
			'WordPress / WooCommerce / PHP' => get_bloginfo( 'version' ) . ' / ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) . ' / ' . PHP_VERSION,
		);
		echo '<table class="widefat striped wasmou-status"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table><p><button type="button" class="button button-primary" id="wasmou-sync">' . esc_html__( 'Sync prices and stock now', 'wasmou-for-woocommerce' ) . '</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'View logs', 'wasmou-for-woocommerce' ) . '</a> <span id="wasmou-sync-result" class="wasmou-inline"></span></p>';
	}

	// ------------------------------------------------------------------ saving

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wasmou-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wasmou_wc_save', '_wasmou_nonce' );
		$tab = isset( $_POST['tab'] ) && isset( self::tabs()[ $_POST['tab'] ] ) ? sanitize_key( $_POST['tab'] ) : 'connection';
		$old = Wasmou_WC_Settings::all();
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$new = Wasmou_WC_Settings::sanitize( $in, $old );
		// unchecked boxes only belong to their own tab
		if ( 'delivery' !== $tab ) {
			foreach ( array( 'auto_complete', 'import_images', 'guide_in_product' ) as $k ) {
				$new[ $k ] = $old[ $k ];
			}
		}
		Wasmou_WC_Settings::save( $new );
		if ( $new['sync_minutes'] !== $old['sync_minutes'] ) {
			Wasmou_WC_Plugin::reschedule();
		}
		if ( $new['api_key'] !== $old['api_key'] || $new['api_url'] !== $old['api_url'] || $new['catalog_lang'] !== $old['catalog_lang'] ) {
			Wasmou_WC_Catalog::flush();
			delete_transient( 'wasmou_wc_wallet' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab . '&saved=1' ) );
		exit;
	}

	// ------------------------------------------------------------------ ajax

	private static function guard() {
		check_ajax_referer( 'wasmou_wc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wasmou-for-woocommerce' ) ), 403 );
		}
	}

	public static function ajax_test() {
		self::guard();
		$r = Wasmou_WC_API::balance();
		if ( ! $r['ok'] ) {
			wp_send_json_error( array( 'message' => $r['message'] ) );
		}
		set_transient( 'wasmou_wc_wallet', array( 'v' => $r['data']['available'] ), 45 );
		wp_send_json_success( array( 'message' => sprintf( /* translators: %s: amount */ __( 'Connected. Your Wasmou balance: %s DZD', 'wasmou-for-woocommerce' ), number_format_i18n( $r['data']['available'], 2 ) ) ) );
	}

	public static function ajax_list() {
		self::guard();
		@set_time_limit( 120 ); // phpcs:ignore
		wp_send_json_success( Wasmou_WC_Catalog::listing() );
	}

	public static function ajax_import() {
		self::guard();
		@set_time_limit( 180 ); // phpcs:ignore
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ids = array_slice( array_filter( $ids ), 0, 10 );
		$cat = Wasmou_WC_Catalog::get( 900 );
		if ( empty( $cat['data']['products'] ) ) {
			wp_send_json_error( array( 'message' => $cat['message'] ? $cat['message'] : __( 'The catalog is empty.', 'wasmou-for-woocommerce' ) ) );
		}
		$names = array();
		foreach ( $cat['data']['categories'] as $c ) {
			$names[ (int) $c['id'] ] = (string) $c['name'];
		}
		$by = array();
		foreach ( $cat['data']['products'] as $p ) {
			$by[ (int) $p['id'] ] = $p;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = isset( $by[ $id ] ) ? Wasmou_WC_Importer::import( $by[ $id ], $names ) : array( 'status' => 'error', 'message' => 'not in catalog' );
		}
		wp_send_json_success( $out );
	}

	public static function ajax_sync() {
		self::guard();
		$r = Wasmou_WC_Plugin::sync_now();
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( array( 'message' => $r['message'] ) );
		}
		wp_send_json_success( array( 'message' => sprintf( /* translators: 1: updated, 2: out of stock */ __( 'Synced: %1$d products updated, %2$d marked out of stock.', 'wasmou-for-woocommerce' ), $r['stats']['updated'], $r['stats']['out_of_stock'] ) ) );
	}

	// ------------------------------------------------------------------ order screen

	public static function metabox( $post_type, $post ) {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $post_type !== $screen && 'shop_order' !== $post_type ) {
			return;
		}
		add_meta_box( 'wasmou-order', 'Wasmou', array( __CLASS__, 'metabox_html' ), $screen, 'side', 'high' );
	}

	public static function metabox_html( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! Wasmou_WC_Fulfillment::has_wasmou_items( $order ) ) {
			echo '<p>' . esc_html__( 'No Wasmou products in this order.', 'wasmou-for-woocommerce' ) . '</p>';

			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! Wasmou_WC_Fulfillment::item_variant_id( $item ) ) {
				continue;
			}
			$state = (string) $item->get_meta( '_wasmou_state' );
			echo '<div class="wasmou-box"><strong>' . esc_html( $item->get_name() ) . '</strong><br>';
			echo '<span class="wasmou-pill wasmou-' . esc_attr( $state ? $state : 'new' ) . '">' . esc_html( $state ? $state : 'queued' ) . '</span>';
			$ids = Wasmou_WC_Fulfillment::meta_list( $item, '_wasmou_order_ids' );
			if ( $ids ) {
				echo '<br><small>Wasmou #' . esc_html( implode( ', #', $ids ) ) . '</small>';
			}
			if ( $item->get_meta( '_wasmou_error' ) ) {
				echo '<br><span class="wasmou-err">' . esc_html( $item->get_meta( '_wasmou_error' ) ) . '</span>';
			}
			foreach ( Wasmou_WC_Fulfillment::meta_list( $item, '_wasmou_keys' ) as $k ) {
				echo '<pre class="wasmou-key" dir="ltr">' . esc_html( $k ) . '</pre>';
			}
			echo '</div>';
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=wasmou_wc_order_action&do=retry&order=' . $order->get_id() ), 'wasmou_wc_order_' . $order->get_id() );
		$ref = wp_nonce_url( admin_url( 'admin-post.php?action=wasmou_wc_order_action&do=refresh&order=' . $order->get_id() ), 'wasmou_wc_order_' . $order->get_id() );
		echo '<p><a class="button" href="' . esc_url( $ref ) . '">' . esc_html__( 'Check status', 'wasmou-for-woocommerce' ) . '</a> <a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Retry failed items', 'wasmou-for-woocommerce' ) . '</a></p>';
	}

	public static function order_action() {
		$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wasmou-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wasmou_wc_order_' . $order_id );
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		if ( 'retry' === $do ) {
			Wasmou_WC_Fulfillment::retry( $order_id );
		} elseif ( 'refresh' === $do ) {
			$o = wc_get_order( $order_id );
			if ( $o ) {
				$o->update_meta_data( '_wasmou_done', '0' );
				$o->update_meta_data( '_wasmou_pending', '1' );
				$o->save();
				Wasmou_WC_Fulfillment::process( $order_id );
			}
		}
		$o = wc_get_order( $order_id );
		wp_safe_redirect( $o ? $o->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}

	public static function dashboard() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Wasmou_WC_Settings::has_key() ) {
			return;
		}
		wp_add_dashboard_widget(
			'wasmou_wc_widget',
			'Wasmou',
			static function () {
				$bal = Wasmou_WC_Plugin::wallet_balance();
				echo '<p style="font-size:22px;margin:0"><strong>' . ( null === $bal ? '—' : esc_html( number_format_i18n( $bal, 2 ) ) ) . ' DZD</strong></p><p>' . esc_html__( 'Your Wasmou wallet', 'wasmou-for-woocommerce' ) . '</p>';
				echo '<p><a href="https://myapp.wasmou.net/deposit" target="_blank" rel="noopener" class="button button-primary">' . esc_html__( 'Add funds', 'wasmou-for-woocommerce' ) . '</a> <a href="' . esc_url( admin_url( 'admin.php?page=wasmou&tab=status' ) ) . '" class="button">' . esc_html__( 'Status', 'wasmou-for-woocommerce' ) . '</a></p>';
			}
		);
	}
}
