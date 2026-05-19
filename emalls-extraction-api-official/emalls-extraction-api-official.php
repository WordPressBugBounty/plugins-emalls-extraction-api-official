<?php
/**
 * Plugin Name: Emalls Extraction API - Official
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس برای ایمالز
 * Version: 1.3.0
 * Author: ایمالز
 * Author URI: https://emalls.ir/
 * License: MIT
 * Text Domain: emalls-extraction-api-official
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Timeout تنظیمات سفارشی در درخواست HTTP
add_filter( 'http_request_timeout', function ( $timeout ) { return 12; }, 9999 );
add_filter( 'http_request_args', function ( $args ) { $args['timeout'] = 12; return $args; }, 9999 );

// بررسی فعال بودن ووکامرس
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	class EmallsWooCommerceExtraction extends WP_REST_Controller {

		private $emalls_ext_version = '1.3.0';
		private $token_cache_expiry = 3600; // یک ساعت
		private $plugin_slug        = 'emalls_extraction/emalls_ext.php';
		private $text_domain_slug   = 'emalls-extraction-api-official';

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		private function php_version() {
			return defined( 'PHP_VERSION' ) ? PHP_VERSION : phpversion();
		}

		private function woocommerce_version() {
			if ( defined( 'WC_VERSION' ) ) { return WC_VERSION; }
			if ( function_exists( 'WC' ) && WC() && isset( WC()->version ) ) { return WC()->version; }
			return null;
		}

		private function libsodium_version() {
			if ( defined( 'SODIUM_LIBRARY_VERSION' ) ) return SODIUM_LIBRARY_VERSION;
			if ( class_exists( 'ParagonIE_Sodium_Compat', false ) ) return ParagonIE_Sodium_Compat::VERSION_STRING . '-compat';
			return null;
		}

		public function register_routes() {
			register_rest_route(
				'emalls_ext/v1',
				'/products',
				array(
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'get_products' ),
						'permission_callback' => '__return_true',
					)
				)
			);
		}

		public function check_request( $request ) {
			$site_url    = wp_parse_url( get_site_url() );
			$shop_domain = str_replace( 'www.', '', $site_url['host'] ?? '' );
			$token       = sanitize_text_field( $request->get_param( 'token' ) );

			$response = wp_safe_remote_post(
				'https://emalls.ir/swservice/wp_plugin.ashx',
				array(
					'timeout' => 5,
					'body'    => array(
						'token'       => $token,
						'shop_domain' => $shop_domain,
						'version'     => $this->emalls_ext_version,
					),
				)
			);

			return $response;
		}

		private function fill_image_caches( array $products ) {
			$attachment_ids = array();
			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) continue;
				$image_id = $product->get_image_id();
				if ( $image_id ) $attachment_ids[] = $image_id;
				$gallery_ids = $product->get_gallery_image_ids();
				if ( ! empty( $gallery_ids ) ) $attachment_ids = array_merge( $attachment_ids, $gallery_ids );
			}
			$attachment_ids = array_unique( array_filter( $attachment_ids ) );
			if ( ! empty( $attachment_ids ) ) _prime_post_caches( $attachment_ids, false, true );
		}

		public function get_product_values( $product, $is_child = false ) {
			$temp_product = new \stdClass();
			$parent       = null;

			if ( $is_child ) {
				$parent                  = wc_get_product( $product->get_parent_id() );
				$temp_product->title     = $parent ? $parent->get_name() : $product->get_name();
				$temp_product->subtitle  = get_post_meta( $product->get_parent_id(), 'product_english_name', true );
				$cat_ids                 = $parent ? $parent->get_category_ids() : $product->get_category_ids();
				$temp_product->parent_id = $parent ? $parent->get_id() : 0;
			} else {
				$temp_product->title     = $product->get_name();
				$temp_product->subtitle  = get_post_meta( $product->get_id(), 'product_english_name', true );
				$cat_ids                 = $product->get_category_ids();
				$temp_product->parent_id = 0;
			}

			$temp_product->page_unique   = $product->get_id();
			$temp_product->current_price = $product->get_price();
			$temp_product->old_price     = $product->get_regular_price();
			$temp_product->availability  = $product->get_stock_status();
			$temp_product->image_links   = array();

			if ( $cat_ids ) {
				$term = get_term_by( 'id', end( $cat_ids ), 'product_cat', 'ARRAY_A' );
				$temp_product->category_name = $term['name'] ?? '';
			} else {
				$temp_product->category_name = '';
			}

			foreach ( $product->get_gallery_image_ids() as $attachment_id ) {
				$t_link = wp_get_attachment_image_src( $attachment_id, 'full' );
				if ( $t_link ) $temp_product->image_links[] = $t_link[0];
			}

			$t_image = wp_get_attachment_image_src( $product->get_image_id(), 'full' );
			if ( $t_image ) {
				$temp_product->image_link = $t_image[0];
				if ( ! in_array( $t_image[0], $temp_product->image_links, true ) )
					$temp_product->image_links[] = $t_image[0];
			} else $temp_product->image_link = null;

			$temp_product->page_url   = $product->get_permalink();
			$temp_product->short_desc = $product->get_short_description();
			$temp_product->spec       = array();

			$temp_product->date_added   = $product->get_date_created() ? $product->get_date_created()->format( DATE_ATOM ) : null;
			$temp_product->date_updated = $product->get_date_modified() ? $product->get_date_modified()->format( DATE_ATOM ) : null;

			$temp_product->product_type = $product->get_type();
			$temp_product->registry     = '';
			$temp_product->guarantee    = '';

			// تمام بخش ویژه از my-file.txt حفظ شده
			if ( ! $is_child ) {
				foreach ( $product->get_attributes() as $attribute ) {
					if ( isset( $attribute['visible'] ) && $attribute['visible'] == 1 ) {
						$name   = wc_attribute_label( $attribute['name'] );
						$values = substr( $attribute['name'], 0, 3 ) === 'pa_' ?
							wc_get_product_terms( $product->get_id(), $attribute['name'], array( 'fields' => 'names' ) )
							: $attribute['options'];
						$temp_product->spec[ $name ] = implode( ', ', $values );
					}
				}
			}

			if ( ! empty( $temp_product->spec['رجیستری'] ) )
				$temp_product->registry = $temp_product->spec['رجیستری'];
			elseif ( ! empty( $temp_product->spec['registry'] ) )
				$temp_product->registry = $temp_product->spec['registry'];
			elseif ( ! empty( $temp_product->spec['ریجیستری'] ) )
				$temp_product->registry = $temp_product->spec['ریجیستری'];
			elseif ( ! empty( $temp_product->spec['ریجستری'] ) )
				$temp_product->registry = $temp_product->spec['ریجستری'];

			$guarantee_keys = [ "گارانتی", "guarantee", "warranty", "garanty", "گارانتی محصول", "ضمانت" ];
			foreach ( $guarantee_keys as $guarantee ) {
				if ( ! empty( $temp_product->spec[ $guarantee ] ) )
					$temp_product->guarantee = $temp_product->spec[ $guarantee ];
			}

			if ( ! array_key_exists( 'شناسه کالا', $temp_product->spec ) ) {
				$sku = $product->get_sku();
				if ( $sku ) $temp_product->spec['شناسه کالا'] = $sku;
			}

			if ( count( $temp_product->spec ) > 0 ) $temp_product->spec = [ $temp_product->spec ];

			return $temp_product;
		}

		private function get_all_products( $show_variations, $limit, $page ) {
			global $wpdb;
			$limit = min( intval( $limit ), 100 );
			$page  = max( intval( $page ), 1 );

			$query  = new WP_Query( array(
				'posts_per_page' => $limit,
				'paged'          => $page,
				'post_status'    => 'publish',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'post_type'      => array( 'product', 'product_variation' ),
			) );

			$products = array_filter( array_map( 'wc_get_product', $query->posts ) );
			$this->fill_image_caches( $products );

			$data = array(
				'count'     => intval( $query->found_posts ),
				'max_pages' => intval( $query->max_num_pages ),
				'products'  => array(),
			);

			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) continue;
				$data['products'][] = $this->get_product_values( $product, $product->get_parent_id() != 0 );
			}

			return $data;
		}

		public function get_products( $request ) {
			$show_variations = rest_sanitize_boolean( $request->get_param( 'variation' ) );
			$limit           = intval( $request->get_param( 'limit' ) );
			$page            = intval( $request->get_param( 'page' ) );

			$emalls_token   = sanitize_text_field( $request->get_param( 'token' ) );
			$cached_token   = get_option( 'emalls_connection' );
			$cached_time    = get_option( 'emalls_connection_time' );
			$need_request   = true;

			if ( $cached_token === $emalls_token && $cached_time && ( time() - intval( $cached_time ) ) < $this->token_cache_expiry )
				$need_request = false;

			if ( ! $need_request ) {
				$data = $this->get_all_products( $show_variations, $limit, $page );
				$data['Version']           = $this->emalls_ext_version;
				$data['NeedSession']       = false;
				$data['TokenSendByEmalls'] = $emalls_token;
				$data['SignedBy']      = 'chegeni.top';
				$data['metadata']          = array(
					'wordpress_version'   => get_bloginfo( 'version' ),
					'php_version'         => $this->php_version(),
					'plugin_version'      => $this->emalls_ext_version,
					'woocommerce_version' => $this->woocommerce_version(),
					'libsodium_version'   => $this->libsodium_version(),
				);
				return new WP_REST_Response( $data, 200 );
			}

			$response = $this->check_request( $request );

			if ( is_wp_error( $response ) ) {
				update_option( 'emalls_connection', '---', false );
				return new WP_REST_Response( array( 'Error' => $response->get_error_message() ), 500 );
			}

			$result = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $result['success'] ) && $result['success'] && $result['message'] === 'the token is valid' ) {
				update_option( 'emalls_connection', $emalls_token, false );
				update_option( 'emalls_connection_time', time(), false );
				$data = $this->get_all_products( $show_variations, $limit, $page );

				$data['Version']           = $this->emalls_ext_version;
				$data['NeedSession']       = true;
				$data['TokenSendByEmalls'] = $emalls_token;
				$data['SignedBy']      = 'chegeni.top';
				$data['metadata']          = array(
					'wordpress_version'   => get_bloginfo( 'version' ),
					'php_version'         => $this->php_version(),
					'plugin_version'      => $this->emalls_ext_version,
					'woocommerce_version' => $this->woocommerce_version(),
					'libsodium_version'   => $this->libsodium_version(),
				);

				return new WP_REST_Response( $data, 200 );
			}

			update_option( 'emalls_connection', '---', false );
			return new WP_REST_Response( array( 
				'Error' => 'Invalid token',
				'wordpress_version'   => get_bloginfo( 'version' ),
				'php_version'         => $this->php_version(),
				'plugin_version'      => $this->emalls_ext_version,
				'woocommerce_version' => $this->woocommerce_version(),
				'libsodium_version'   => $this->libsodium_version()
			), 401 );
		}
	}

	new EmallsWooCommerceExtraction();
}
