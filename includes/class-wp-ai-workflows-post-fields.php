<?php
/**
 * Shared Post node field handling: splits mapped fields into core post fields,
 * WooCommerce product fields and post meta, and writes the last two.
 *
 * @package WP_AI_Workflows
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_AI_Workflows_Post_Fields {

	const CORE_FIELDS = array(
		'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name',
		'post_date', 'post_date_gmt', 'post_author', 'post_parent', 'post_password',
		'menu_order', 'comment_status', 'ping_status', 'post_type',
	);

	const PRODUCT_FIELDS = array(
		'_regular_price', '_sale_price', '_price', '_sku', '_stock', '_stock_quantity',
		'_stock_status', '_manage_stock', '_backorders', '_weight', '_length', '_width',
		'_height', '_virtual', '_downloadable', '_tax_status', '_tax_class',
		'_purchase_note', '_featured', 'product_type', '_product_type',
	);

	/**
	 * @param string $key
	 * @return bool
	 */
	public static function is_core_field( $key ) {
		return in_array( $key, self::CORE_FIELDS, true );
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public static function is_product_field( $key ) {
		return in_array( $key, self::PRODUCT_FIELDS, true );
	}

	/**
	 * @param string $post_type
	 * @return bool
	 */
	public static function is_product_post_type( $post_type ) {
		return 'product' === $post_type && function_exists( 'wc_get_product' );
	}

	/**
	 * Splits resolved field mappings into core, product, meta and ACF buckets.
	 *
	 * @param array  $mappings
	 * @param string $post_type
	 * @return array
	 */
	public static function split_mappings( array $mappings, $post_type ) {
		$buckets = array(
			'core'    => array(),
			'product' => array(),
			'meta'    => array(),
			'acf'     => array(),
		);

		$is_product = self::is_product_post_type( $post_type );

		foreach ( $mappings as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( 0 === strpos( $key, 'acf_' ) ) {
				$buckets['acf'][ substr( $key, 4 ) ] = $value;
				continue;
			}

			if ( self::is_core_field( $key ) ) {
				$buckets['core'][ $key ] = $value;
				continue;
			}

			if ( $is_product && self::is_product_field( $key ) ) {
				$buckets['product'][ $key ] = $value;
				continue;
			}

			$buckets['meta'][ $key ] = $value;
		}

		return $buckets;
	}

	/**
	 * Applies WooCommerce product fields via the CRUD API.
	 *
	 * @param int   $post_id
	 * @param array $values
	 * @return array Field names actually applied.
	 */
	public static function apply_product_fields( $post_id, array $values ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		$product_type = null;
		if ( isset( $values['product_type'] ) ) {
			$product_type = $values['product_type'];
		} elseif ( isset( $values['_product_type'] ) ) {
			$product_type = $values['_product_type'];
		}

		if ( null !== $product_type && is_scalar( $product_type ) && function_exists( 'wc_get_product_types' ) ) {
			$product_type = sanitize_key( $product_type );
			if ( array_key_exists( $product_type, wc_get_product_types() ) ) {
				wp_set_object_terms( $post_id, $product_type, 'product_type', false );
			}
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return array();
		}

		$applied = array();

		try {
			if ( isset( $values['_regular_price'] ) && is_scalar( $values['_regular_price'] ) ) {
				$product->set_regular_price( wc_format_decimal( $values['_regular_price'] ) );
				$applied[] = '_regular_price';
			}

			if ( isset( $values['_sale_price'] ) && is_scalar( $values['_sale_price'] ) ) {
				$product->set_sale_price( wc_format_decimal( $values['_sale_price'] ) );
				$applied[] = '_sale_price';
			}

			if ( isset( $values['_price'] ) && is_scalar( $values['_price'] ) && ! isset( $values['_regular_price'] ) ) {
				$product->set_regular_price( wc_format_decimal( $values['_price'] ) );
				$applied[] = '_price';
			}

			if ( in_array( '_regular_price', $applied, true ) || in_array( '_sale_price', $applied, true ) || in_array( '_price', $applied, true ) ) {
				$regular = $product->get_regular_price();
				$sale    = $product->get_sale_price();

				if ( '' !== $sale && is_numeric( $sale ) && floatval( $sale ) < floatval( $regular ) ) {
					$effective = $sale;
				} else {
					$effective = $regular;
				}

				if ( '' !== $effective ) {
					$product->set_price( $effective );
				}
			}

			if ( isset( $values['_sku'] ) && is_scalar( $values['_sku'] ) ) {
				$sku = sanitize_text_field( $values['_sku'] );
				if ( '' !== $sku ) {
					try {
						$product->set_sku( $sku );
						$applied[] = '_sku';
					} catch ( Exception $e ) {
						WP_AI_Workflows_Utilities::debug_log( 'Product SKU rejected', 'warning', array( 'post_id' => $post_id ) );
					}
				}
			}

			foreach ( array( '_stock', '_stock_quantity' ) as $stock_key ) {
				if ( isset( $values[ $stock_key ] ) && is_scalar( $values[ $stock_key ] ) && '' !== trim( (string) $values[ $stock_key ] ) ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( function_exists( 'wc_stock_amount' ) ? wc_stock_amount( $values[ $stock_key ] ) : (int) $values[ $stock_key ] );
					$applied[] = $stock_key;
				}
			}

			if ( isset( $values['_manage_stock'] ) && is_scalar( $values['_manage_stock'] ) ) {
				$product->set_manage_stock( self::to_bool( $values['_manage_stock'] ) );
				$applied[] = '_manage_stock';
			}

			if ( isset( $values['_stock_status'] ) && is_scalar( $values['_stock_status'] ) ) {
				$stock_status = sanitize_key( $values['_stock_status'] );
				if ( in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					$product->set_stock_status( $stock_status );
					$applied[] = '_stock_status';
				}
			}

			if ( isset( $values['_backorders'] ) && is_scalar( $values['_backorders'] ) ) {
				$backorders = sanitize_key( $values['_backorders'] );
				if ( in_array( $backorders, array( 'no', 'notify', 'yes' ), true ) ) {
					$product->set_backorders( $backorders );
					$applied[] = '_backorders';
				}
			}

			$dimension_setters = array(
				'_weight' => 'set_weight',
				'_length' => 'set_length',
				'_width'  => 'set_width',
				'_height' => 'set_height',
			);
			foreach ( $dimension_setters as $key => $setter ) {
				if ( isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ) {
					$product->$setter( wc_format_decimal( $values[ $key ] ) );
					$applied[] = $key;
				}
			}

			$bool_setters = array(
				'_virtual'      => 'set_virtual',
				'_downloadable' => 'set_downloadable',
				'_featured'     => 'set_featured',
			);
			foreach ( $bool_setters as $key => $setter ) {
				if ( isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ) {
					$product->$setter( self::to_bool( $values[ $key ] ) );
					$applied[] = $key;
				}
			}

			if ( isset( $values['_tax_status'] ) && is_scalar( $values['_tax_status'] ) ) {
				$tax_status = sanitize_key( $values['_tax_status'] );
				if ( in_array( $tax_status, array( 'taxable', 'shipping', 'none' ), true ) ) {
					$product->set_tax_status( $tax_status );
					$applied[] = '_tax_status';
				}
			}

			if ( isset( $values['_tax_class'] ) && is_scalar( $values['_tax_class'] ) ) {
				$product->set_tax_class( sanitize_title( $values['_tax_class'] ) );
				$applied[] = '_tax_class';
			}

			if ( isset( $values['_purchase_note'] ) && is_scalar( $values['_purchase_note'] ) ) {
				$product->set_purchase_note( wp_kses_post( $values['_purchase_note'] ) );
				$applied[] = '_purchase_note';
			}

			$product->save();
		} catch ( Exception $e ) {
			WP_AI_Workflows_Utilities::debug_log( 'Product fields not saved', 'warning', array( 'post_id' => $post_id ) );
		}

		return $applied;
	}

	/**
	 * Writes remaining mapped values as post meta.
	 *
	 * @param int   $post_id
	 * @param array $meta
	 * @return array Meta keys actually written.
	 */
	public static function apply_meta( $post_id, array $meta ) {
		$applied = array();

		foreach ( $meta as $meta_key => $value ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}

			$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', $meta_key );

			if ( '' === $key || 0 === stripos( $key, '_wp_' ) ) {
				continue;
			}

			if ( self::is_core_field( $key ) ) {
				continue;
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( is_string( $value ) ) {
				$value = wp_kses_post( $value );
			} elseif ( is_scalar( $value ) ) {
				$value = (string) $value;
			} else {
				continue;
			}

			update_post_meta( $post_id, $key, $value );
			$applied[] = $key;
		}

		return $applied;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( function_exists( 'wc_string_to_bool' ) ) {
			return wc_string_to_bool( $value );
		}

		return in_array( $value, array( '1', 'yes', 'true', 1, true ), true );
	}
}
