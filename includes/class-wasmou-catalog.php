<?php
defined( 'ABSPATH' ) || exit;

/** The Wasmou catalog, cached locally so pages never wait on the API. */
class Wasmou_WC_Catalog {

	const OPTION = 'wasmou_wc_catalog_cache';

	/**
	 * @return array{ok:bool, message:string, data:array, fetched:int} data = array( 'categories' => [], 'products' => [] )
	 */
	public static function get( $max_age = 300, $force = false ) {
		$cache = get_option( self::OPTION );
		if ( ! $force && is_array( $cache ) && ! empty( $cache['data'] ) && ( time() - (int) $cache['fetched'] ) <= $max_age && ( $cache['lang'] ?? '' ) === Wasmou_WC_Settings::lang() ) {
			return array( 'ok' => true, 'message' => '', 'data' => $cache['data'], 'fetched' => (int) $cache['fetched'] );
		}
		$r = Wasmou_WC_API::catalog();
		if ( $r['ok'] && is_array( $r['data'] ) && isset( $r['data']['products'] ) ) {
			update_option(
				self::OPTION,
				array(
					'fetched' => time(),
					'lang'    => Wasmou_WC_Settings::lang(),
					'data'    => array(
						'categories' => (array) ( $r['data']['categories'] ?? array() ),
						'products'   => (array) $r['data']['products'],
					),
				),
				false
			);
			delete_transient( 'wasmou_wc_snapshot' );

			return array( 'ok' => true, 'message' => '', 'data' => get_option( self::OPTION )['data'], 'fetched' => time() );
		}
		// API failed: fall back to what we have (may be stale), but say so.
		if ( is_array( $cache ) && ! empty( $cache['data'] ) ) {
			return array( 'ok' => false, 'message' => $r['message'], 'data' => $cache['data'], 'fetched' => (int) $cache['fetched'] );
		}

		return array( 'ok' => false, 'message' => $r['message'], 'data' => array( 'categories' => array(), 'products' => array() ), 'fetched' => 0 );
	}

	/** variant_id => facts, built from the cached catalog. */
	public static function snapshot( $max_age = 120 ) {
		$cat = self::get( $max_age );
		$map = array();
		foreach ( $cat['data']['products'] as $p ) {
			foreach ( (array) ( $p['variants'] ?? array() ) as $v ) {
				$map[ (int) $v['id'] ] = array(
					'cost'       => (float) $v['price'],
					'available'  => ! empty( $v['available'] ),
					'in_stock'   => array_key_exists( 'in_stock', $v ) ? $v['in_stock'] : null,
					'async'      => ! empty( $v['async'] ),
					'warranty'   => $v['warranty'] ?? null,
					'product_id' => (int) $p['id'],
					'name'       => (string) $v['name'],
				);
			}
		}

		return array( 'map' => $map, 'fetched' => $cat['fetched'], 'fresh' => $cat['ok'] );
	}

	public static function flush() {
		delete_option( self::OPTION );
		delete_transient( 'wasmou_wc_snapshot' );
	}

	/** Slim list for the import screen. */
	public static function listing() {
		$cat   = self::get( 600 );
		$names = array();
		foreach ( $cat['data']['categories'] as $c ) {
			$names[ (int) $c['id'] ] = (string) $c['name'];
		}
		$out = array();
		foreach ( $cat['data']['products'] as $p ) {
			$prices = array_map(
				static function ( $v ) {
					return (float) $v['price'];
				},
				(array) ( $p['variants'] ?? array() )
			);
			$out[]  = array(
				'id'       => (int) $p['id'],
				'name'     => (string) $p['name'],
				'category' => (int) ( $p['category_id'] ?? 0 ),
				'cat_name' => $names[ (int) ( $p['category_id'] ?? 0 ) ] ?? '',
				'variants' => count( $prices ),
				'from'     => $prices ? min( $prices ) : 0,
				'image'    => (string) ( $p['image'] ?? '' ),
				'kind'     => (string) ( $p['kind'] ?? '' ),
				'imported' => (bool) Wasmou_WC_Importer::find_product( (int) $p['id'] ),
			);
		}

		return array( 'ok' => $cat['ok'], 'message' => $cat['message'], 'fetched' => $cat['fetched'], 'categories' => $names, 'products' => $out );
	}
}
