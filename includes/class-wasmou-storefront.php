<?php
defined( 'ABSPATH' ) || exit;

/** Product page, cart and checkout behaviour for Wasmou products. */
class Wasmou_WC_Storefront {

	public static function hooks() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_inputs' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 10, 4 );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'product_tabs' ) );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_info' ), 10, 3 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'simple_info' ), 25 );
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'no_ajax_add' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'verify_cart' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_register_style( 'wasmou-wc-front', WASMOU_WC_URL . 'assets/front.css', array(), WASMOU_WC_VERSION );
		wp_enqueue_style( 'wasmou-wc-front' );
	}

	// ------------------------------------------------------------------ helpers

	private static function variant_id_of( $post_id ) {
		return (int) get_post_meta( $post_id, '_wasmou_variant_id', true );
	}

	/** The Wasmou product id meta lives on the parent for variable products. */
	private static function parent_id( $product ) {
		return $product && $product->is_type( 'variation' ) ? $product->get_parent_id() : ( $product ? $product->get_id() : 0 );
	}

	public static function inputs_for( $product_id ) {
		$in = get_post_meta( $product_id, '_wasmou_inputs', true );

		return is_array( $in ) ? array_values( $in ) : array();
	}

	public static function is_wasmou_product( $product_id ) {
		return (bool) get_post_meta( $product_id, '_wasmou_product_id', true );
	}

	public static function warranty_text( $w ) {
		if ( ! is_array( $w ) || empty( $w['type'] ) ) {
			return '';
		}
		if ( 'full' === $w['type'] ) {
			return __( 'Full warranty', 'wasmou-for-woocommerce' );
		}
		if ( 'none' === $w['type'] ) {
			return __( 'No warranty', 'wasmou-for-woocommerce' );
		}
		$d = (int) ( $w['days'] ?? 0 );
		if ( $d <= 1 ) {
			return __( '24-hour warranty', 'wasmou-for-woocommerce' );
		}
		if ( $d < 30 ) {
			/* translators: %d: number of days */
			return sprintf( _n( '%d-day warranty', '%d-day warranty', $d, 'wasmou-for-woocommerce' ), $d );
		}
		if ( $d < 365 ) {
			/* translators: %d: number of months */
			return sprintf( __( '%d-month warranty', 'wasmou-for-woocommerce' ), (int) round( $d / 30 ) );
		}
		$y = round( $d / 365, 1 );

		/* translators: %s: number of years */
		return 1.0 === (float) $y ? __( '1-year warranty', 'wasmou-for-woocommerce' ) : sprintf( __( '%s-year warranty', 'wasmou-for-woocommerce' ), (string) $y );
	}

	private static function info_html( $async, $warranty ) {
		$bits = array();
		$bits[] = $async ? __( 'Added to your account, usually the same day', 'wasmou-for-woocommerce' ) : __( 'Instant delivery after payment', 'wasmou-for-woocommerce' );
		$w      = self::warranty_text( $warranty );
		if ( $w ) {
			$bits[] = $w;
		}
		$out = '<ul class="wasmou-badges">';
		foreach ( $bits as $b ) {
			$out .= '<li>' . esc_html( $b ) . '</li>';
		}

		return $out . '</ul>';
	}

	// ------------------------------------------------------------------ product page

	public static function render_inputs() {
		global $product;
		if ( ! $product || ! self::is_wasmou_product( $product->get_id() ) ) {
			return;
		}
		$fields = self::inputs_for( $product->get_id() );
		if ( ! $fields ) {
			return;
		}
		echo '<div class="wasmou-inputs">';
		foreach ( $fields as $i => $f ) {
			$label = isset( $f['label'] ) ? (string) $f['label'] : sprintf( 'Field %d', $i + 1 );
			$type  = ( isset( $f['type'] ) && 'email' === $f['type'] ) ? 'email' : 'text';
			$val   = isset( $_POST['wasmou_input'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['wasmou_input'][ $i ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			printf(
				'<p class="form-row"><label for="wasmou_input_%1$d">%2$s <abbr class="required" title="%6$s">*</abbr></label><input type="%3$s" id="wasmou_input_%1$d" name="wasmou_input[%1$d]" value="%4$s" placeholder="%5$s" required class="input-text" maxlength="190" autocomplete="off" /></p>',
				(int) $i,
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $val ),
				esc_attr( isset( $f['placeholder'] ) ? (string) $f['placeholder'] : '' ),
				esc_attr__( 'required', 'wasmou-for-woocommerce' )
			);
		}
		echo '</div>';
	}

	public static function simple_info() {
		global $product;
		if ( ! $product || ! $product->is_type( 'simple' ) || ! self::is_wasmou_product( $product->get_id() ) ) {
			return;
		}
		echo wp_kses_post( self::info_html( (bool) get_post_meta( $product->get_id(), '_wasmou_async', true ), get_post_meta( $product->get_id(), '_wasmou_warranty', true ) ) );
	}

	public static function variation_info( $data, $product, $variation ) {
		if ( self::variant_id_of( $variation->get_id() ) ) {
			$html                         = self::info_html( (bool) get_post_meta( $variation->get_id(), '_wasmou_async', true ), get_post_meta( $variation->get_id(), '_wasmou_warranty', true ) );
			$data['variation_description'] = ( $data['variation_description'] ?? '' ) . $html;
		}

		return $data;
	}

	public static function product_tabs( $tabs ) {
		global $product;
		if ( ! $product || ! self::is_wasmou_product( $product->get_id() ) || ! Wasmou_WC_Settings::is_yes( 'guide_in_product' ) ) {
			return $tabs;
		}
		$g = get_post_meta( $product->get_id(), '_wasmou_guide', true );
		if ( ! is_array( $g ) || empty( $g['steps'] ) ) {
			return $tabs;
		}
		$tabs['wasmou_guide'] = array(
			'title'    => __( 'How to activate', 'wasmou-for-woocommerce' ),
			'priority' => 15,
			'callback' => array( __CLASS__, 'guide_tab' ),
		);

		return $tabs;
	}

	public static function guide_tab() {
		global $product;
		echo wp_kses_post( self::guide_html( get_post_meta( $product->get_id(), '_wasmou_guide', true ) ) );
	}

	public static function guide_html( $g ) {
		if ( ! is_array( $g ) ) {
			return '';
		}
		$out = '<div class="wasmou-guide">';
		if ( ! empty( $g['includes'] ) ) {
			$out .= '<h3>' . esc_html__( 'What you get', 'wasmou-for-woocommerce' ) . '</h3><ul>';
			foreach ( (array) $g['includes'] as $x ) {
				$out .= '<li>' . esc_html( $x ) . '</li>';
			}
			$out .= '</ul>';
		}
		if ( ! empty( $g['steps'] ) ) {
			$out .= '<h3>' . esc_html__( 'How to activate', 'wasmou-for-woocommerce' ) . '</h3><ol>';
			foreach ( (array) $g['steps'] as $x ) {
				$out .= '<li>' . esc_html( $x ) . '</li>';
			}
			$out .= '</ol>';
		}
		if ( ! empty( $g['notes'] ) ) {
			$out .= '<h3>' . esc_html__( 'Warranty & notes', 'wasmou-for-woocommerce' ) . '</h3><ul>';
			foreach ( (array) $g['notes'] as $x ) {
				$out .= '<li>' . esc_html( $x ) . '</li>';
			}
			$out .= '</ul>';
		}

		return $out . '</div>';
	}

	/** Products that need buyer details (e-mail, player ID) must be added from their own page. */
	public static function no_ajax_add( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $product && self::is_wasmou_product( self::parent_id( $product ) ) && self::inputs_for( self::parent_id( $product ) ) ) {
			return false;
		}

		return $supports;
	}

	// ------------------------------------------------------------------ cart

	private static function posted_inputs() {
		$raw = isset( $_POST['wasmou_input'] ) && is_array( $_POST['wasmou_input'] ) ? wp_unslash( $_POST['wasmou_input'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$out = array();
		foreach ( $raw as $i => $v ) {
			if ( is_scalar( $v ) ) {
				$out[ (int) $i ] = trim( sanitize_text_field( (string) $v ) );
			}
		}
		ksort( $out );

		return $out;
	}

	private static function input_error( array $fields, array $given ) {
		foreach ( $fields as $i => $f ) {
			$v     = $given[ $i ] ?? '';
			$label = isset( $f['label'] ) ? (string) $f['label'] : 'Field';
			if ( '' === $v ) {
				/* translators: %s: field label */
				return sprintf( __( '%s is required.', 'wasmou-for-woocommerce' ), $label );
			}
			$is_mail = ( isset( $f['type'] ) && 'email' === $f['type'] ) || preg_match( '/e-?mail|gmail/i', $label );
			if ( $is_mail && ! is_email( $v ) ) {
				/* translators: %s: field label */
				return sprintf( __( '%s must be a valid e-mail address.', 'wasmou-for-woocommerce' ), $label );
			}
		}

		return '';
	}

	public static function validate_add_to_cart( $passed, $product_id, $qty, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! self::is_wasmou_product( $product_id ) ) {
			return $passed;
		}
		$err = self::input_error( self::inputs_for( $product_id ), self::posted_inputs() );
		if ( '' !== $err ) {
			wc_add_notice( $err, 'error' );

			return false;
		}
		$post_id = $variation_id ? $variation_id : $product_id;
		if ( get_post_meta( $post_id, '_wasmou_async', true ) && (int) $qty > 1 ) {
			wc_add_notice( __( 'This subscription is added to one account: order one at a time.', 'wasmou-for-woocommerce' ), 'error' );

			return false;
		}

		return $passed;
	}

	public static function cart_item_data( $data, $product_id, $variation_id ) {
		if ( self::is_wasmou_product( $product_id ) && self::inputs_for( $product_id ) ) {
			$given                 = self::posted_inputs();
			$data['wasmou_inputs'] = $given;
			$data['wasmou_uid']    = md5( wp_json_encode( $given ) ); // same product with other details = another line.
		}

		return $data;
	}

	public static function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['wasmou_inputs'] ) ) {
			$fields = self::inputs_for( $cart_item['product_id'] );
			foreach ( $cart_item['wasmou_inputs'] as $i => $v ) {
				$item_data[] = array( 'key' => isset( $fields[ $i ]['label'] ) ? $fields[ $i ]['label'] : __( 'Details', 'wasmou-for-woocommerce' ), 'value' => esc_html( $v ) );
			}
		}

		return $item_data;
	}

	public static function save_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['wasmou_inputs'] ) ) {
			$item->add_meta_data( '_wasmou_inputs', $values['wasmou_inputs'], true );
			$fields = self::inputs_for( $values['product_id'] );
			foreach ( $values['wasmou_inputs'] as $i => $v ) {
				$item->add_meta_data( isset( $fields[ $i ]['label'] ) ? $fields[ $i ]['label'] : __( 'Details', 'wasmou-for-woocommerce' ), $v, true );
			}
		}
	}

	/** Re-prices one imported product/variation from a fresh cost. */
	public static function reprice( $post_id, $cost ) {
		$p = wc_get_product( $post_id );
		if ( $p ) {
			$p->set_regular_price( (string) Wasmou_WC_Pricing::sell( (float) $cost ) );
			$p->save();
			update_post_meta( $post_id, '_wasmou_cost', (float) $cost );
			if ( $p->is_type( 'variation' ) ) {
				WC_Product_Variable::sync( $p->get_parent_id() );
			}
			wc_delete_product_transients( $p->is_type( 'variation' ) ? $p->get_parent_id() : $post_id );
		}
	}

	/** Cart / checkout gate: availability, price protection and Wasmou wallet. Works for classic and block checkout. */
	public static function verify_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $ci ) {
			$post_id = $ci['variation_id'] ? $ci['variation_id'] : $ci['product_id'];
			$vid     = self::variant_id_of( $post_id );
			if ( $vid ) {
				$items[] = array( 'ci' => $ci, 'post' => $post_id, 'vid' => $vid );
			}
		}
		if ( ! $items ) {
			return;
		}
		$snap  = Wasmou_WC_Catalog::snapshot( 120 );
		$stale = ! $snap['fresh'] && ( time() - $snap['fetched'] ) > 1800;
		$need  = 0.0;
		foreach ( $items as $it ) {
			$name = $it['ci']['data']->get_name();
			$live = $snap['map'][ $it['vid'] ] ?? null;
			if ( $stale || ! $live || ! $live['available'] ) {
				/* translators: %s: product name */
				wc_add_notice( sprintf( __( '"%s" is temporarily unavailable. Please remove it from your cart or try again later.', 'wasmou-for-woocommerce' ), $name ), 'error' );
				continue;
			}
			if ( is_numeric( $live['in_stock'] ) && (int) $live['in_stock'] < (int) $it['ci']['quantity'] ) {
				/* translators: %s: product name */
				wc_add_notice( sprintf( __( 'Not enough stock for "%s". Please lower the quantity.', 'wasmou-for-woocommerce' ), $name ), 'error' );
			}
			$fields = self::inputs_for( $it['ci']['product_id'] );
			if ( $fields && '' !== self::input_error( $fields, isset( $it['ci']['wasmou_inputs'] ) ? $it['ci']['wasmou_inputs'] : array() ) ) {
				/* translators: %s: product name */
				wc_add_notice( sprintf( __( 'Details are missing for "%s". Remove it and add it again from its product page.', 'wasmou-for-woocommerce' ), $name ), 'error' );
			}
			$sell = Wasmou_WC_Pricing::sell( $live['cost'] );
			if ( $sell > (float) $it['ci']['data']->get_price() + 0.009 ) {
				self::reprice( $it['post'], $live['cost'] );
				/* translators: %s: product name */
				wc_add_notice( sprintf( __( 'The price of "%s" changed. Please review your cart and try again.', 'wasmou-for-woocommerce' ), $name ), 'error' );
			}
			$need += $live['cost'] * (int) $it['ci']['quantity'];
		}
		if ( ! $stale && $need > 0 ) {
			$bal = Wasmou_WC_Plugin::wallet_balance();
			if ( null !== $bal && $bal < $need ) {
				wc_add_notice( __( 'These products cannot be delivered right now. Please try again later.', 'wasmou-for-woocommerce' ), 'error' );
				Wasmou_WC_Plugin::alert( 'wallet-low', __( 'Wasmou wallet too low for a customer order', 'wasmou-for-woocommerce' ), __( 'A customer tried to buy but your Wasmou wallet cannot cover it. Add funds at myapp.wasmou.net.', 'wasmou-for-woocommerce' ) );
			}
		}
	}
}
