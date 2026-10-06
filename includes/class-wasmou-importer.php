<?php
defined( 'ABSPATH' ) || exit;

/** Creates and updates WooCommerce products from the Wasmou catalog. */
class Wasmou_WC_Importer {

	public static function find_product( $wasmou_product_id ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wasmou_product_id' AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status <> 'trash' LIMIT 1",
				(string) $wasmou_product_id
			)
		);

		return $id ? (int) $id : 0;
	}

	/** variant_id => WP post id (simple product or variation) for everything we imported. */
	public static function variant_index() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wasmou_variant_id' AND p.post_status <> 'trash'"
		);
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r->meta_value ] = (int) $r->post_id;
		}

		return $map;
	}

	private static function category_term( $cat_id, $name ) {
		if ( ! $cat_id || '' === $name ) {
			return 0;
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'meta_key' => '_wasmou_cat_id', 'meta_value' => (string) $cat_id, 'number' => 1, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			return (int) $terms[0];
		}
		$existing = term_exists( $name, 'product_cat' );
		if ( ! $existing ) {
			$existing = wp_insert_term( $name, 'product_cat' );
		}
		if ( is_wp_error( $existing ) ) {
			return 0;
		}
		$tid = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
		update_term_meta( $tid, '_wasmou_cat_id', (string) $cat_id );

		return $tid;
	}

	private static function sideload_image( $url, $post_id ) {
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, $post_id, null, 'id' );

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	private static function stock_args( array $v ) {
		$in_stock = $v['in_stock'] ?? null;
		if ( ! empty( $v['available'] ) && is_numeric( $in_stock ) ) {
			return array( true, (int) $in_stock, (int) $in_stock > 0 ? 'instock' : 'outofstock' );
		}

		return array( false, null, ! empty( $v['available'] ) ? 'instock' : 'outofstock' );
	}

	/**
	 * @param array $p  one product of the Wasmou catalog.
	 * @param array $cat_names id => name.
	 * @return array{status:string, id?:int, message?:string, variants?:int}
	 */
	public static function import( array $p, array $cat_names = array(), $refresh = false ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return array( 'status' => 'error', 'message' => 'WooCommerce is not active' );
		}
		$variants = array_values( array_filter( (array) ( $p['variants'] ?? array() ), static function ( $v ) {
			return ! empty( $v['available'] );
		} ) );
		$existing = self::find_product( $p['id'] );
		if ( ! $variants && ! $existing ) {
			return array( 'status' => 'skipped', 'message' => 'no variants in stock' );
		}
		if ( ! $variants ) {
			return self::set_all_out_of_stock( $existing );
		}

		$multi = count( $variants ) > 1;
		$async = true;
		foreach ( $variants as $v ) {
			$async = $async && ! empty( $v['async'] );
		}

		if ( $existing ) {
			$product = wc_get_product( $existing );
			if ( $multi && ! $product->is_type( 'variable' ) ) {
				wp_set_object_terms( $existing, 'variable', 'product_type' );
				$product = wc_get_product( $existing );
			}
		} else {
			$product = $multi ? new WC_Product_Variable() : new WC_Product_Simple();
		}
		$is_new = ! $existing;

		$guide = isset( $p['guide'] ) && is_array( $p['guide'] ) ? $p['guide'] : null;
		// A re-sync only touches prices, stock and plans: your edits to names, texts and categories are kept.
		if ( $is_new || ! $refresh ) {
			$product->set_name( wp_strip_all_tags( (string) $p['name'] ) );
			if ( $is_new ) {
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'visible' );
			}
			$desc = trim( (string) ( $p['description'] ?? '' ) );
			if ( '' !== $desc ) {
				$product->set_description( wpautop( esc_html( $desc ) ) );
			}
			if ( $guide && ! empty( $guide['summary'] ) ) {
				$product->set_short_description( esc_html( $guide['summary'] ) );
			}
			$term = self::category_term( (int) ( $p['category_id'] ?? 0 ), $cat_names[ (int) ( $p['category_id'] ?? 0 ) ] ?? '' );
			if ( $term ) {
				$product->set_category_ids( array( $term ) );
			}
		}
		$product->set_virtual( true );
		$product->set_sold_individually( $async );

		if ( ! $multi ) {
			$v = $variants[0];
			list( $manage, $qty, $status ) = self::stock_args( $v );
			$product->set_regular_price( (string) Wasmou_WC_Pricing::sell( (float) $v['price'] ) );
			$product->set_manage_stock( $manage );
			if ( $manage ) {
				$product->set_stock_quantity( $qty );
			}
			$product->set_stock_status( $status );
		} else {
			$names = array();
			$seen  = array();
			foreach ( $variants as $i => $v ) {
				$n = wp_strip_all_tags( (string) $v['name'] );
				if ( isset( $seen[ $n ] ) ) {
					$n .= ' #' . (int) $v['id'];
				}
				$seen[ $n ]              = true;
				$variants[ $i ]['_label'] = $n;
				$names[]                  = $n;
			}
			$attr = new WC_Product_Attribute();
			$attr->set_id( 0 );
			$attr->set_name( __( 'Plan', 'wasmou-for-woocommerce' ) );
			$attr->set_options( $names );
			$attr->set_position( 0 );
			$attr->set_visible( true );
			$attr->set_variation( true );
			$product->set_attributes( array( $attr ) );
		}

		$id = $product->save();
		update_post_meta( $id, '_wasmou_product_id', (string) $p['id'] );
		update_post_meta( $id, '_wasmou_inputs', array_values( (array) ( $p['input_fields'] ?? array() ) ) );
		update_post_meta( $id, '_wasmou_guide', $guide );
		update_post_meta( $id, '_wasmou_kind', (string) ( $p['kind'] ?? '' ) );
		update_post_meta( $id, '_wasmou_synced_at', time() );

		if ( ! $multi ) {
			self::write_variant_meta( $id, $variants[0] );
			// a product that used to be variable: park its old variations
			foreach ( $product->get_children() as $child ) {
				update_post_meta( $child, '_stock_status', 'outofstock' );
			}
		} else {
			self::sync_variations( $id, $variants );
		}

		if ( ! $refresh && Wasmou_WC_Settings::is_yes( 'import_images' ) && ! $product->get_image_id() && ! empty( $p['image'] ) ) {
			$img = self::sideload_image( $p['image'], $id );
			if ( $img ) {
				$product->set_image_id( $img );
				$product->save();
			}
		}
		wc_delete_product_transients( $id );

		return array( 'status' => $is_new ? 'created' : 'updated', 'id' => $id, 'variants' => count( $variants ) );
	}

	private static function write_variant_meta( $post_id, array $v ) {
		update_post_meta( $post_id, '_wasmou_variant_id', (int) $v['id'] );
		update_post_meta( $post_id, '_wasmou_cost', (float) $v['price'] );
		update_post_meta( $post_id, '_wasmou_async', ! empty( $v['async'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_wasmou_warranty', isset( $v['warranty'] ) && is_array( $v['warranty'] ) ? $v['warranty'] : null );
	}

	private static function sync_variations( $parent_id, array $variants ) {
		$parent   = wc_get_product( $parent_id );
		$by_wid   = array();
		foreach ( $parent->get_children() as $child ) {
			$wid = (int) get_post_meta( $child, '_wasmou_variant_id', true );
			if ( $wid ) {
				$by_wid[ $wid ] = $child;
			}
		}
		$live = array();
		foreach ( $variants as $v ) {
			$wid         = (int) $v['id'];
			$live[ $wid ] = true;
			$vid         = $by_wid[ $wid ] ?? 0;
			$var         = $vid ? new WC_Product_Variation( $vid ) : new WC_Product_Variation();
			$var->set_parent_id( $parent_id );
			$var->set_status( 'publish' );
			$var->set_virtual( true );
			$var->set_attributes( array( sanitize_title( __( 'Plan', 'wasmou-for-woocommerce' ) ) => $v['_label'] ) );
			$var->set_regular_price( (string) Wasmou_WC_Pricing::sell( (float) $v['price'] ) );
			list( $manage, $qty, $status ) = self::stock_args( $v );
			$var->set_manage_stock( $manage );
			if ( $manage ) {
				$var->set_stock_quantity( $qty );
			}
			$var->set_stock_status( $status );
			$vid = $var->save();
			self::write_variant_meta( $vid, $v );
		}
		// plans that are no longer sold
		foreach ( $by_wid as $wid => $child ) {
			if ( empty( $live[ $wid ] ) ) {
				$var = new WC_Product_Variation( $child );
				$var->set_stock_status( 'outofstock' );
				$var->set_manage_stock( false );
				$var->save();
			}
		}
		WC_Product_Variable::sync( $parent_id );
	}

	private static function set_all_out_of_stock( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( $product ) {
			$kids = $product->is_type( 'variable' ) ? $product->get_children() : array( $post_id );
			foreach ( $kids as $k ) {
				$o = wc_get_product( $k );
				if ( $o ) {
					$o->set_manage_stock( false );
					$o->set_stock_status( 'outofstock' );
					$o->save();
				}
			}
			wc_delete_product_transients( $post_id );
		}

		return array( 'status' => 'updated', 'id' => (int) $post_id, 'variants' => 0, 'message' => 'no variants in stock' );
	}

	/**
	 * Refresh price, stock and warranty of everything already imported, from the cached catalog. Returns counts.
	 */
	public static function refresh_imported( array $catalog_data ) {
		$by_product = array();
		foreach ( $catalog_data['products'] as $p ) {
			$by_product[ (int) $p['id'] ] = $p;
		}
		$cat_names = array();
		foreach ( $catalog_data['categories'] as $c ) {
			$cat_names[ (int) $c['id'] ] = (string) $c['name'];
		}
		global $wpdb;
		$rows  = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_wasmou_product_id' AND p.post_type = 'product' AND p.post_status <> 'trash'"
		);
		$stats = array( 'updated' => 0, 'out_of_stock' => 0, 'errors' => 0 );
		foreach ( (array) $rows as $r ) {
			$wid = (int) $r->meta_value;
			if ( empty( $by_product[ $wid ] ) ) {
				self::set_all_out_of_stock( (int) $r->post_id ); // Wasmou stopped selling it.
				++$stats['out_of_stock'];
				continue;
			}
			$res = self::import( $by_product[ $wid ], $cat_names, true );
			'error' === $res['status'] ? ++$stats['errors'] : ++$stats['updated'];
		}
		update_option( 'wasmou_wc_last_sync', array( 'time' => time(), 'stats' => $stats ), false );

		return $stats;
	}
}
