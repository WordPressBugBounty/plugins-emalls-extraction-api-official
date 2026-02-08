<?php
/**
 * Plugin Name: Emalls Extraction API - Official
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس برای ایمالز
 * Version: 1.2.0
 * Author: ایمالز
 * Author URI: https://emalls.ir/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: emalls-extraction-api-official
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Setting custom timeout for the HTTP request
add_filter( 'http_request_timeout', 'emalls_ext_http_request_timeout', 9999 );
function emalls_ext_http_request_timeout( $timeout_value ) {
	return 12;
}

// Setting custom timeout in HTTP request args
add_filter( 'http_request_args', 'emalls_ext_http_request_args', 9999, 1 );
function emalls_ext_http_request_args( $r ) {
	$r['timeout'] = 12;

	return $r;
}

// Check if WooCommerce is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	class EmallsWooCommerceExtraction extends WP_REST_Controller {
		private $emalls_ext_version = "1.2.0";
		private $plugin_slug        = "emalls_extraction/emalls_ext.php";
		private $text_domain_slug   = "emalls-extraction-api-official";

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/*
		 * نسخه PHP
		 */
		private function php_version() {
			if ( defined( 'PHP_VERSION' ) ) {
				return PHP_VERSION;
			} elseif ( function_exists( 'phpversion' ) ) {
				return phpversion();
			}

			return null;
		}

		/*
		 * نسخه ووکامرس
		 */
		private function woocommerce_version() {
			if ( defined( 'WC_VERSION' ) ) {
				return WC_VERSION;
			} else {
				return WC()->version;
			}
		}

		/*
		 * نسخه libsodium
		 */
		private function libsodium_version() {
			// Native sodium extension (bundled in PHP 7.2+, PECL libsodium for older)
			if ( extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' ) ) {
				if ( defined( 'SODIUM_LIBRARY_VERSION' ) ) {
					return SODIUM_LIBRARY_VERSION;
				}

				return 'unknown';
			}

			// Polyfill/compat library (WordPress includes sodium_compat)
			if ( class_exists( 'ParagonIE_Sodium_Compat', false ) ) {
				return ParagonIE_Sodium_Compat::VERSION_STRING . '-compat';
			}

			return null;
		}

		/**
		 * find matching product and variation
		 */
		private function find_matching_variation( $product, $attributes ) {
			foreach ( $attributes as $key => $value ) {
				if ( strpos( $key, 'attribute_' ) === 0 ) {
					continue;
				}
				unset( $attributes[ $key ] );
				$attributes[ sprintf( 'attribute_%s', $key ) ] = $value;
			}
			if ( class_exists( 'WC_Data_Store' ) ) {
				$data_store = WC_Data_Store::load( 'product' );

				return $data_store->find_matching_product_variation( $product, $attributes );
			} else {
				return $product->get_matching_variation( $attributes );
			}
		}

		/**
		 * Register rout: https://domain.com/emalls_ext/v1/products
		 */
		public function register_routes() {
			$version   = '1';
			$namespace = 'emalls_ext/v' . $version;
			$base      = 'products';
			register_rest_route( $namespace, '/' . $base, array(
				array(
					'methods'             => 'POST',
					'callback'            => array(
						$this,
						'get_products'
					),
					'permission_callback' => '__return_true',
					'args'                => array()
				)
			) );
		}

		/**
		 * Check update and validate the request
		 * @param request
		 * @return wp_safe_remote_post
		 */
		public function check_request( $request ) {
			// Get shop domain
			$site_url    = wp_parse_url( get_site_url() );
			$shop_domain = str_replace( 'www.', '', $site_url['host'] );

			// emalls verify token url
			$endpoint_url = 'https://emalls.ir/swservice/wp_plugin.ashx';

			// Get Parameters
			$token = sanitize_text_field( $request->get_param( 'token' ) );

			// Get Headers
			$header = $request->get_header( 'X-Authorization' );
			if ( empty( $header ) ) {
				$header = $request->get_header( 'Authorization' );
			}

			// Verify token
			$response = wp_safe_remote_post( $endpoint_url, array(
					'method'      => 'POST',
					'timeout'     => 5,
					'redirection' => 0,
					'httpversion' => '1.0',
					'blocking'    => true,
					'headers'     => array(
						'AUTHORIZATION' => $header,
					),
					'body'        => array(
						'token'       => $token,
						'shop_domain' => $shop_domain,
						'version'     => $this->emalls_ext_version
					),
					'cookies'     => array()
				)
			);

			return $response;
		}

		/**
		 * پرایم کردن کش تصاویر برای کاهش کوئری‌ها
		 *
		 * @param WC_Product[] $products
		 */
		private function fill_image_caches( array $products ) {
			$attachment_ids = array();
			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$attachment_ids[] = $image_id;
				}
				$gallery_ids = $product->get_gallery_image_ids();
				if ( ! empty( $gallery_ids ) ) {
					$attachment_ids = array_merge( $attachment_ids, $gallery_ids );
				}
			}

			$attachment_ids = array_unique( array_filter( $attachment_ids ) );
			if ( ! empty( $attachment_ids ) ) {
				_prime_post_caches( $attachment_ids, false, true );
			}
		}

		/**
		 * Get single product values
		 */
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

			if ( $cat_ids ) {
				$term = get_term_by( 'id', end( $cat_ids ), 'product_cat', 'ARRAY_A' );
				$temp_product->category_name = $term && isset( $term['name'] ) ? $term['name'] : '';
			} else {
				$temp_product->category_name = '';
			}

			$temp_product->image_links = array();
			$attachment_ids            = $product->get_gallery_image_ids();
			foreach ( $attachment_ids as $attachment_id ) {
				$t_link = wp_get_attachment_image_src( $attachment_id, 'full' );
				if ( $t_link ) {
					$temp_product->image_links[] = $t_link[0];
				}
			}

			// تصویر اصلی (با تضمین یکتا بودن در image_links)
			$t_image = wp_get_attachment_image_src( $product->get_image_id(), 'full' );
			if ( $t_image ) {
				$temp_product->image_link = $t_image[0];
				if ( ! in_array( $t_image[0], $temp_product->image_links, true ) ) {
					$temp_product->image_links[] = $t_image[0];
				}
			} else {
				$temp_product->image_link = null;
			}

			$temp_product->page_url   = $product->get_permalink();
			$temp_product->short_desc = $product->get_short_description();
			$temp_product->spec       = array();

			// تاریخ‌ها به فرمت استاندارد
			$temp_product->date_added   = $product->get_date_created() ? $product->get_date_created()->format( DATE_ATOM ) : null;
			$temp_product->date_updated = $product->get_date_modified() ? $product->get_date_modified()->format( DATE_ATOM ) : null;

			$temp_product->product_type = $product->get_type();
			$temp_product->registry     = '';
			$temp_product->guarantee    = '';

			if ( ! $is_child ) {
				if ( $product->is_type( 'variable' ) ) {
					// Set prices to 0 then calculate them
					$temp_product->current_price = 0;
					$temp_product->old_price     = 0;

					// Find price for default attributes. If can't find return max price of variations
					$variation_id = $this->find_matching_variation( $product, $product->get_default_attributes() );
					if ( $variation_id != 0 ) {
						$variation                   = wc_get_product( $variation_id );
						$temp_product->current_price = $variation->get_price();
						$temp_product->old_price     = $variation->get_regular_price();
						$temp_product->availability  = $variation->get_stock_status();
					} else {
						$temp_product->current_price = $product->get_variation_price( 'max' );
						$temp_product->old_price     = $product->get_variation_regular_price( 'max' );
					}

					// Extract default attributes
					foreach ( $product->get_default_attributes() as $key => $value ) {
						if ( ! empty( $value ) ) {
							if ( substr( $key, 0, 3 ) === 'pa_' ) {
								$value = get_term_by( 'slug', $value, $key );
								if ( $value ) {
									$value = $value->name;
								} else {
									$value = '';
								}
								$key = wc_attribute_label( $key );
							}
							$temp_product->spec[ urldecode( $key ) ] = rawurldecode( $value );
						}
					}
				}
				// add remain attributes
				foreach ( $product->get_attributes() as $attribute ) {
					if ( isset( $attribute['visible'] ) && $attribute['visible'] == 1 ) {
						$name = wc_attribute_label( $attribute['name'] );
						if ( substr( $attribute['name'], 0, 3 ) === 'pa_' ) {
							$values = wc_get_product_terms( $product->get_id(), $attribute['name'], array( 'fields' => 'names' ) );
						} else {
							$values = $attribute['options'];
						}
						if ( ! array_key_exists( $name, $temp_product->spec ) ) {
							$temp_product->spec[ $name ] = implode( ', ', $values );
						}
					}
				}
			} else {
				foreach ( $product->get_attributes() as $key => $value ) {
					if ( ! empty( $value ) ) {
						if ( substr( $key, 0, 3 ) === 'pa_' ) {
							$value = get_term_by( 'slug', $value, $key );
							if ( $value ) {
								$value = $value->name;
							} else {
								$value = '';
							}
							$key = wc_attribute_label( $key );
						}
						$temp_product->spec[ urldecode( $key ) ] = rawurldecode( $value );
					}
				}
			}

			// Set registry
			if ( ! empty( $temp_product->spec['رجیستری'] ) ) {
				$temp_product->registry = $temp_product->spec['رجیستری'];
			} elseif ( ! empty( $temp_product->spec['registry'] ) ) {
				$temp_product->registry = $temp_product->spec['registry'];
			} elseif ( ! empty( $temp_product->spec['ریجیستری'] ) ) {
				$temp_product->registry = $temp_product->spec['ریجیستری'];
			} elseif ( ! empty( $temp_product->spec['ریجستری'] ) ) {
				$temp_product->registry = $temp_product->spec['ریجستری'];
			}

			$guarantee_keys = [
				"گارانتی",
				"guarantee",
				"warranty",
				"garanty",
				"گارانتی:",
				"گارانتی محصول",
				"گارانتی محصول:",
				"ضمانت",
				"ضمانت:"
			];

			foreach ( $guarantee_keys as $guarantee ) {
				if ( ! empty( $temp_product->spec[ $guarantee ] ) ) {
					$temp_product->guarantee = $temp_product->spec[ $guarantee ];
				}
			}

			if ( ! array_key_exists( 'شناسه کالا', $temp_product->spec ) ) {
				$sku = $product->get_sku();
				if ( $sku != "" ) {
					$temp_product->spec['شناسه کالا'] = $sku;
				}
			}

			if ( count( $temp_product->spec ) > 0 ) {
				$temp_product->spec = [ $temp_product->spec ];
			}

			return $temp_product;
		}

		/**
		 * Get all products
		 *
		 * @param bool $show_variations
		 * @param int  $limit
		 * @param int  $page
		 *
		 * @return array
		 */
		private function get_all_products( $show_variations, $limit, $page ) {
			$args = [
				'posts_per_page'         => $limit,
				'paged'                  => $page,
				'post_status'            => 'publish',
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'post_type'              => [ 'product' ],
				"update_post_term_cache" => true,
				"update_post_meta_cache" => true,
				"cache_results"          => false,
			];

			if ( $show_variations ) {
				$args['post_type'] = [ 'product', 'product_variation' ];
			}

			$query    = new WP_Query( $args );
			$products = array_filter( array_map( 'wc_get_product', $query->posts ) );

			$data              = array();
			$data['count']     = $query->found_posts;
			$data['max_pages'] = $query->max_num_pages;
			$data['products']  = array();

			// پرایم کش تصاویر
			$this->fill_image_caches( $products );

			foreach ( $products as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$parent_id = $product->get_parent_id();

				if ( $parent_id == 0 ) {
					// Exclude the variable product. (variations of it will be inserted.)
					if ( $show_variations ) {
						if ( ! $product->is_type( 'variable' ) ) {
							$temp_product        = $this->get_product_values( $product );
							$data['products'][] = $temp_product;
						}
					} else {
						$temp_product        = $this->get_product_values( $product );
						$data['products'][] = $temp_product;
					}
				} else {
					// Process for visible child
					if ( $product->get_price() ) {
						$temp_product        = $this->get_product_values( $product, true );
						$data['products'][] = $temp_product;
					}
				}
			}

			return $data;
		}

		/**
		 * Get a product or list of products
		 *
		 * @param array $product_list
		 *
		 * @return array
		 */
		private function get_list_products( $product_list ) {
			$data['products'] = array();

			foreach ( $product_list as $pid ) {
				$product = wc_get_product( $pid );
				if ( $product && $product->get_status() === "publish" ) {
					$parent_id = $product->get_parent_id();
					if ( $parent_id == 0 ) {
						$temp_product        = $this->get_product_values( $product );
						$data['products'][] = $temp_product;
					} else {
						if ( $product->get_price() ) {
							$temp_product        = $this->get_product_values( $product, true );
							$data['products'][] = $temp_product;
						}
					}
				}
			}

			return $data;
		}

		/**
		 * Get a slugs or list of slugs. For getting product's data by its link
		 *
		 * @param array $slug_list
		 *
		 * @return array
		 */
		private function get_list_slugs( $slug_list ) {
			$data['products'] = array();

			foreach ( $slug_list as $sid ) {
				$product = get_page_by_path( $sid, OBJECT, 'product' );
				if ( $product && $product->post_status === "publish" ) {
					$temp_product        = $this->get_product_values( wc_get_product( $product->ID ) );
					$data['products'][] = $temp_product;
				}
			}

			return $data;
		}

		/**
		 * Get all or a collection of products
		 *
		 * @param WP_REST_Request $request Full data about the request.
		 *
		 * @return WP_Error|WP_REST_Response
		 */
		public function get_products( $request ) {
			// Get Parameters
			$show_variations = rest_sanitize_boolean( $request->get_param( 'variation' ) );
			$limit           = intval( $request->get_param( 'limit' ) );
			$page            = intval( $request->get_param( 'page' ) );
			if ( ! empty( $request->get_param( 'products' ) ) ) {
				$product_list = explode( ',', ( sanitize_text_field( $request->get_param( 'products' ) ) ) );
				if ( is_array( $product_list ) ) {
					foreach ( $product_list as $key => $field ) {
						$product_list[ $key ] = intval( $field );
					}
				}
			}
			if ( ! empty( $request->get_param( 'slugs' ) ) ) {
				$slug_list = explode( ',', ( sanitize_text_field( urldecode( $request->get_param( 'slugs' ) ) ) ) );
			}

			$need_request = true;
			$emalls_token = $request->get_param( 'token' );
			session_start();

			$site_wp_token = get_option( 'emalls_connection' );
			if ( $site_wp_token == $emalls_token ) {
				$need_request = false;
			}

			$data = array();

			if ( $need_request == false ) {
				if ( ! empty( $product_list ) ) {
					$data = $this->get_list_products( $product_list );
				} elseif ( ! empty( $slug_list ) ) {
					$data = $this->get_list_slugs( $slug_list );
				} else {
					$data = $this->get_all_products( $show_variations, $limit, $page );
				}
				$response_code = 200;

				// متادیتا و اطلاعات نسخه
				$data['Version']          = $this->emalls_ext_version;
				$data['NeedSession']      = $need_request;
				$data['TokenSendByEmalls'] = $emalls_token;
				$data['SignedByEasy']     = 'chegeni.top';
				$data['EasyMode']         = true;
				$data['metadata']         = array(
					'wordpress_version'   => get_bloginfo( 'version' ),
					'php_version'         => $this->php_version(),
					'plugin_version'      => $this->emalls_ext_version,
					'woocommerce_version' => $this->woocommerce_version(),
					'libsodium_version'   => $this->libsodium_version(),
				);

				return new WP_REST_Response( $data, $response_code );
			}

			// Check request is valid and update
			$response = $this->check_request( $request );
			if ( ! is_array( $response ) ) {
				update_option( 'emalls_connection', '---' );
				$data['Response'] = '';
				$data['Error']    = $response;
				$response_code    = 500;
			} else {
				$response_body = $response['body'];
				$response      = json_decode( $response_body, true );

				if ( $response['success'] === true && $response['message'] === 'the token is valid' ) {
					update_option( 'emalls_connection', $emalls_token );

					if ( ! empty( $product_list ) ) {
						$data = $this->get_list_products( $product_list );
					} elseif ( ! empty( $slug_list ) ) {
						$data = $this->get_list_slugs( $slug_list );
					} else {
						$data = $this->get_all_products( $show_variations, $limit, $page );
					}
					$response_code = 200;
				} else {
					update_option( 'emalls_connection', '---' );

					$data['Response'] = $response_body;
					$data['Error']    = $response['error'];

					$site_url    = wp_parse_url( get_site_url() );
					$shop_domain = str_replace( 'www.', '', $site_url['host'] );

					$data['shop_domain'] = $shop_domain;
					$response_code       = 401;
				}
			}

			$data['Version']           = $this->emalls_ext_version;
			$data['NeedSession']       = $need_request;
			$data['TokenSendByEmalls'] = $emalls_token;
			$data['SignedBy']          = 'chegeni.top';
			$data['metadata']          = array(
				'wordpress_version'   => get_bloginfo( 'version' ),
				'php_version'         => $this->php_version(),
				'plugin_version'      => $this->emalls_ext_version,
				'woocommerce_version' => $this->woocommerce_version(),
				'libsodium_version'   => $this->libsodium_version(),
			);

			return new WP_REST_Response( $data, $response_code );
		}
	}

	$EmallsWooCommerceExtraction = new EmallsWooCommerceExtraction;
}