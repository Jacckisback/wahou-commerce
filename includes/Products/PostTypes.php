<?php
/**
 * Custom post types and taxonomies: products, product categories/tags,
 * coupons and funnel offers (order bumps, upsells, downsells).
 *
 * @package WahouCommerce
 */

namespace WahouCommerce\Products;

use WahouCommerce\Settings;

defined( 'ABSPATH' ) || exit;

final class PostTypes {

	public const PRODUCT  = 'wahou_product';
	public const CATEGORY = 'wahou_product_cat';
	public const TAG      = 'wahou_product_tag';
	public const COUPON   = 'wahou_coupon';
	public const OFFER    = 'wahou_offer';

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_filter( 'post_updated_messages', array( self::class, 'messages' ) );
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'block_editor' ), 10, 2 );
	}

	public static function product_base(): string {
		$base = Settings::str( 'product_base' );
		return '' !== $base ? sanitize_title( $base ) : _x( 'product', 'URL slug', 'wahou-commerce' );
	}

	public static function category_base(): string {
		$base = Settings::str( 'category_base' );
		return '' !== $base ? sanitize_title( $base ) : _x( 'product-category', 'URL slug', 'wahou-commerce' );
	}

	public static function register(): void {
		$caps = array( 'wahou_product', 'wahou_products' );

		register_post_type(
			self::PRODUCT,
			array(
				'labels'              => array(
					'name'                  => __( 'Products', 'wahou-commerce' ),
					'singular_name'         => __( 'Product', 'wahou-commerce' ),
					'menu_name'             => __( 'Products', 'wahou-commerce' ),
					'all_items'             => __( 'All products', 'wahou-commerce' ),
					'add_new'               => __( 'Add product', 'wahou-commerce' ),
					'add_new_item'          => __( 'Add new product', 'wahou-commerce' ),
					'edit_item'             => __( 'Edit product', 'wahou-commerce' ),
					'new_item'              => __( 'New product', 'wahou-commerce' ),
					'view_item'             => __( 'View product', 'wahou-commerce' ),
					'view_items'            => __( 'View products', 'wahou-commerce' ),
					'search_items'          => __( 'Search products', 'wahou-commerce' ),
					'not_found'             => __( 'No products found', 'wahou-commerce' ),
					'not_found_in_trash'    => __( 'No products found in Trash', 'wahou-commerce' ),
					'featured_image'        => __( 'Main product image', 'wahou-commerce' ),
					'set_featured_image'    => __( 'Set main image', 'wahou-commerce' ),
					'remove_featured_image' => __( 'Remove main image', 'wahou-commerce' ),
					'use_featured_image'    => __( 'Use as main image', 'wahou-commerce' ),
					'item_published'        => __( 'Product published.', 'wahou-commerce' ),
					'item_updated'          => __( 'Product updated.', 'wahou-commerce' ),
				),
				'description'         => __( 'Products sold in your store.', 'wahou-commerce' ),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => 'wahou',
				'show_in_rest'        => true,
				'show_in_nav_menus'   => true,
				'has_archive'         => false,
				'hierarchical'        => false,
				'exclude_from_search' => false,
				'capability_type'     => $caps,
				'map_meta_cap'        => true,
				'menu_icon'           => 'dashicons-cart',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'revisions', 'page-attributes' ),
				'rewrite'             => array(
					'slug'       => self::product_base(),
					'with_front' => false,
					'feeds'      => false,
				),
				'query_var'           => true,
				'delete_with_user'    => false,
			)
		);

		register_taxonomy(
			self::CATEGORY,
			array( self::PRODUCT ),
			array(
				'labels'            => array(
					'name'          => __( 'Product categories', 'wahou-commerce' ),
					'singular_name' => __( 'Category', 'wahou-commerce' ),
					'menu_name'     => __( 'Categories', 'wahou-commerce' ),
					'search_items'  => __( 'Search categories', 'wahou-commerce' ),
					'all_items'     => __( 'All categories', 'wahou-commerce' ),
					'parent_item'   => __( 'Parent category', 'wahou-commerce' ),
					'edit_item'     => __( 'Edit category', 'wahou-commerce' ),
					'update_item'   => __( 'Update category', 'wahou-commerce' ),
					'add_new_item'  => __( 'Add new category', 'wahou-commerce' ),
					'new_item_name' => __( 'New category name', 'wahou-commerce' ),
					'not_found'     => __( 'No categories found', 'wahou-commerce' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'         => self::category_base(),
					'with_front'   => false,
					'hierarchical' => true,
				),
				'capabilities'      => array(
					'manage_terms' => 'edit_wahou_products',
					'edit_terms'   => 'edit_wahou_products',
					'delete_terms' => 'edit_wahou_products',
					'assign_terms' => 'edit_wahou_products',
				),
			)
		);

		register_taxonomy(
			self::TAG,
			array( self::PRODUCT ),
			array(
				'labels'            => array(
					'name'          => __( 'Product tags', 'wahou-commerce' ),
					'singular_name' => __( 'Tag', 'wahou-commerce' ),
					'menu_name'     => __( 'Tags', 'wahou-commerce' ),
					'search_items'  => __( 'Search tags', 'wahou-commerce' ),
					'all_items'     => __( 'All tags', 'wahou-commerce' ),
					'edit_item'     => __( 'Edit tag', 'wahou-commerce' ),
					'add_new_item'  => __( 'Add new tag', 'wahou-commerce' ),
					'not_found'     => __( 'No tags found', 'wahou-commerce' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => _x( 'product-tag', 'URL slug', 'wahou-commerce' ),
					'with_front' => false,
				),
				'capabilities'      => array(
					'manage_terms' => 'edit_wahou_products',
					'edit_terms'   => 'edit_wahou_products',
					'delete_terms' => 'edit_wahou_products',
					'assign_terms' => 'edit_wahou_products',
				),
			)
		);

		register_post_type(
			self::COUPON,
			array(
				'labels'          => array(
					'name'               => __( 'Coupons', 'wahou-commerce' ),
					'singular_name'      => __( 'Coupon', 'wahou-commerce' ),
					'menu_name'          => __( 'Coupons', 'wahou-commerce' ),
					'add_new'            => __( 'Add coupon', 'wahou-commerce' ),
					'add_new_item'       => __( 'Add new coupon', 'wahou-commerce' ),
					'edit_item'          => __( 'Edit coupon', 'wahou-commerce' ),
					'search_items'       => __( 'Search coupons', 'wahou-commerce' ),
					'not_found'          => __( 'No coupons found', 'wahou-commerce' ),
					'not_found_in_trash' => __( 'No coupons found in Trash', 'wahou-commerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wahou',
				'show_in_rest'    => false,
				'capability_type' => $caps,
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);

		register_post_type(
			self::OFFER,
			array(
				'labels'          => array(
					'name'               => __( 'Offers & funnels', 'wahou-commerce' ),
					'singular_name'      => __( 'Offer', 'wahou-commerce' ),
					'menu_name'          => __( 'Offers & funnels', 'wahou-commerce' ),
					'add_new'            => __( 'Add offer', 'wahou-commerce' ),
					'add_new_item'       => __( 'Add new offer', 'wahou-commerce' ),
					'edit_item'          => __( 'Edit offer', 'wahou-commerce' ),
					'search_items'       => __( 'Search offers', 'wahou-commerce' ),
					'not_found'          => __( 'No offers found', 'wahou-commerce' ),
					'not_found_in_trash' => __( 'No offers found in Trash', 'wahou-commerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wahou',
				'show_in_rest'    => false,
				'capability_type' => $caps,
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'page-attributes' ),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Coupons and offers use the classic screen (simple forms, faster). Products
	 * too by default, so the product data form stays visible next to the
	 * description, like most e-commerce back offices; the block editor can be
	 * re-enabled with the wahou_product_block_editor filter.
	 */
	public static function block_editor( bool $use, string $post_type ): bool {
		if ( in_array( $post_type, array( self::COUPON, self::OFFER ), true ) ) {
			return false;
		}
		if ( self::PRODUCT === $post_type ) {
			return (bool) apply_filters( 'wahou_product_block_editor', false );
		}
		return $use;
	}

	/**
	 * @param array<string, array<int, string>> $messages
	 * @return array<string, array<int, string>>
	 */
	public static function messages( array $messages ): array {
		$messages[ self::PRODUCT ] = array(
			1  => __( 'Product updated.', 'wahou-commerce' ),
			4  => __( 'Product updated.', 'wahou-commerce' ),
			6  => __( 'Product published.', 'wahou-commerce' ),
			7  => __( 'Product saved.', 'wahou-commerce' ),
			8  => __( 'Product submitted.', 'wahou-commerce' ),
			10 => __( 'Product draft updated.', 'wahou-commerce' ),
		);
		$messages[ self::COUPON ]  = array(
			1  => __( 'Coupon updated.', 'wahou-commerce' ),
			4  => __( 'Coupon updated.', 'wahou-commerce' ),
			6  => __( 'Coupon published.', 'wahou-commerce' ),
			7  => __( 'Coupon saved.', 'wahou-commerce' ),
			10 => __( 'Coupon draft updated.', 'wahou-commerce' ),
		);
		$messages[ self::OFFER ]   = array(
			1  => __( 'Offer updated.', 'wahou-commerce' ),
			4  => __( 'Offer updated.', 'wahou-commerce' ),
			6  => __( 'Offer published.', 'wahou-commerce' ),
			7  => __( 'Offer saved.', 'wahou-commerce' ),
			10 => __( 'Offer draft updated.', 'wahou-commerce' ),
		);
		return $messages;
	}
}
