<?php
/**
 * Plugin Name: Emalls Extraction API - Official
 * Description: افزونه ای برای استخراج تمامی محصولات ووکامرس برای ایمالز
 * Version: 1.1.0
 * Author: ایمالز
 * Author URI: https://emalls.ir/
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: emalls-extraction-api-official
 */

if(!defined('ABSPATH')){
    exit; // Exit if accessed directly
}

// Setting custom timeout for the HTTP request
add_filter('http_request_timeout', 'emalls_ext_http_request_timeout', 9999);
function emalls_ext_http_request_timeout($timeout_value){
	return 12;
}

// Setting custom timeout in HTTP request args
add_filter('http_request_args', 'emalls_ext_http_request_args', 9999, 1);
function emalls_ext_http_request_args($r){
	$r['timeout'] = 12;
	return $r;
}

// Check if WooCommerce is active
if(in_array( 'woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
{
    class EmallsWooCommerceExtraction extends WP_REST_Controller
    {
        private $emalls_ext_version = "1.0.0";
        private $plugin_slug = "emalls_extraction/emalls_ext.php";
        private $text_domain_slug = "emalls-extraction-api-official";

        public function __construct(){
            add_action('rest_api_init', array($this, 'register_routes'));
        }

        

        /**
         * find mathing product and variation
         */
        private function find_matching_variation($product, $attributes){
            foreach($attributes as $key => $value){
        	    if(strpos($key, 'attribute_') === 0){
        		    continue;
        	    }
        	    unset($attributes[ $key ]);
        	    $attributes[sprintf('attribute_%s', $key)] = $value;
            }
            if(class_exists('WC_Data_Store')){
                $data_store = WC_Data_Store::load('product');
                return $data_store->find_matching_product_variation($product, $attributes);
            }else{
                return $product->get_matching_variation($attributes);
            }
        }

        /**
         * Register rout: https://domain.com/emalls_ext/v1/products
         */
        public function register_routes()
        {
            $version   = '1';
            $namespace = 'emalls_ext/v' . $version;
            $base = 'products';
            register_rest_route($namespace, '/' . $base, array(
                array(
                    'methods' => 'POST',
                    'callback' => array(
                        $this,
                        'get_products'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                )
            ));
        }

        /**
         * Check update and validate the request
         * @param request
         * @return wp_safe_remote_post
         */
        public function check_request($request)
        {
            // Get shop domain
            $site_url = wp_parse_url(get_site_url());
            $shop_domain = str_replace('www.','',$site_url['host']);

            // emalls verify token url
            $endpoint_url = 'https://emalls.ir/swservice/wp_plugin.ashx';

            // Get Parameters
            $token = sanitize_text_field($request->get_param('token'));

            // Get Headers
            $header = $request->get_header('X-Authorization');
            if(empty($header)){
                $header = $request->get_header('Authorization');
            }

            // Verify token
            $response = wp_safe_remote_post( $endpoint_url, array(
                'method' => 'POST',
                'timeout' => 5,
                'redirection' => 0,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(
                    'AUTHORIZATION' => $header,
                ),
                'body' => array(
                    'token' => $token,
                    'shop_domain' => $shop_domain,
                    'version' => $this->emalls_ext_version
                ),
                'cookies' => array()
                )
            );

            return $response;
        }


        /**
         * Get single product values
         */
        public function get_product_values($product, $is_child=FALSE)
        {
            $temp_product = new \stdClass();
            $parent = NULL;
            if($is_child){
                $parent = wc_get_product($product->get_parent_id());
                $temp_product->title = $parent->get_name();
                $temp_product->subtitle = get_post_meta($product->get_parent_id(), 'product_english_name', true);
                $cat_ids = $parent->get_category_ids();
                $temp_product->parent_id = $parent->get_id();
            }else{
                $temp_product->title = $product->get_name();
                $temp_product->subtitle = get_post_meta($product->get_id(), 'product_english_name', true);
                $cat_ids = $product->get_category_ids();
                $temp_product->parent_id = 0;
            }
            $temp_product->page_unique = $product->get_id();
            $temp_product->current_price = $product->get_price();
            $temp_product->old_price = $product->get_regular_price();
            $temp_product->availability = $product->get_stock_status();
            $temp_product->category_name = get_term_by('id', end($cat_ids), 'product_cat', 'ARRAY_A')['name'];
            $temp_product->image_links = [];
            $attachment_ids = $product->get_gallery_image_ids();
            foreach( $attachment_ids as $attachment_id ){
                $t_link = wp_get_attachment_image_src($attachment_id, 'full');
                if($t_link){
                    array_push($temp_product->image_links, $t_link[0]);
                }
            }
            


            //new
            $temp_product->image_link = wp_get_attachment_url($product->get_image_id());
            if($temp_product->image_link){
                array_unshift($temp_product->image_links, $temp_product->image_link);
            }



            //$t_image = wp_get_attachment_image_src($product->get_image_id(), 'full');
            //if($t_image){
            //    $temp_product->image_link = $t_image[0];
            //    if (!in_array($t_image[0], $temp_product->image_links)){
            //        array_push($temp_product->image_links, $t_image[0]);
            //    }
            //}else{
            //    $temp_product->image_link = null;
            //}
            $temp_product->page_url = get_permalink($product->get_id());
            $temp_product->short_desc = $product->get_short_description();
            $temp_product->spec = array();
            $temp_product->date = $product->get_date_created();
            $temp_product->registry = '';
            $temp_product->guarantee = '';

            if(!$is_child){
                if($product->is_type('variable')){
                    // Set prices to 0 then calcualte them
                    $temp_product->current_price = 0;
                    $temp_product->old_price = 0;

                    // Find price for default attributes. If can't find return max price of variations
                    $variation_id = $this->find_matching_variation($product, $product->get_default_attributes());
                    if($variation_id != 0){
                        $variation = wc_get_product($variation_id);
                        $temp_product->current_price = $variation->get_price();
                        $temp_product->old_price = $variation->get_regular_price();
            		    $temp_product->availability = $variation->get_stock_status();
                    }else{
                        $temp_product->current_price = $product->get_variation_price('max');
                        $temp_product->old_price = $product->get_variation_regular_price('max');
                    }

                    // Extract default attributes
                    foreach($product->get_default_attributes() as $key => $value)
                    {
                        if(!empty($value)){
                            if(substr($key ,0, 3) === 'pa_'){
                                $value = get_term_by('slug', $value, $key);
                                if($value){
                                    $value = $value->name;
                                }else{
                                    $value = '';
                                }
                                $key = wc_attribute_label($key);
                                $temp_product->spec[urldecode($key)] = rawurldecode($value);
                            }
                            else{
                                $temp_product->spec[urldecode($key)] = rawurldecode($value);
                            }
                        }
                    }
                }
                // add remain attributes
                foreach($product->get_attributes() as $attribute){
                    if($attribute['visible'] == 1){
                        $name = wc_attribute_label($attribute['name']);
                        if(substr($attribute['name'] ,0, 3) === 'pa_'){
                            $values = wc_get_product_terms($product->get_id(), $attribute['name'], array('fields' => 'names'));
                        }
                        else{
                            $values = $attribute['options'];
                        }
                        if(!array_key_exists($name, $temp_product->spec)){
                            $temp_product->spec[$name] = implode(', ', $values);
                        }
                    }
                }
            }else{
                foreach($product->get_attributes() as $key => $value){
                    if(!empty($value)){
                        if(substr($key ,0, 3) === 'pa_'){
                            $value = get_term_by('slug', $value, $key);
                            if($value){
                                $value = $value->name;
                            }else{
                                $value = '';
                            }
                            $key = wc_attribute_label($key);
                            $temp_product->spec[urldecode($key)] = rawurldecode($value);
                        }
                        else{
                            $temp_product->spec[urldecode($key)] = rawurldecode($value);
                        }
                    }
                }
            }

            // Set registry and guarantee
            if(!empty($temp_product->spec['رجیستری'])){
                $temp_product->registry = $temp_product->spec['رجیستری'];
            }elseif(!empty($temp_product->spec['registry'])){
                $temp_product->registry = $temp_product->spec['registry'];
            }elseif(!empty($temp_product->spec['ریجیستری'])){
                $temp_product->registry = $temp_product->spec['ریجیستری'];
            }elseif(!empty($temp_product->spec['ریجستری'])){
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
            
            foreach($guarantee_keys as $guarantee){
                if(!empty($temp_product->spec[$guarantee])){
                    $temp_product->guarantee = $temp_product->spec[$guarantee];
                }
            }
            
            if(!array_key_exists('شناسه کالا', $temp_product->spec)){
                $sku = $product->get_sku();
                if($sku != ""){
                    $temp_product->spec['شناسه کالا'] = $sku;
                }
            }
            
            if(count($temp_product->spec) > 0){
                $temp_product->spec = [$temp_product->spec];
            }

            return $temp_product;
        }

        /**
         * Get all products
         *
         * @param WP_REST_Request $request Full data about the request.
         * @return WP_Error|WP_REST_Response
         */
        private function get_all_products($show_variations, $limit, $page){
            $parent_ids = array();
            if($show_variations){
                // Get all posts have children
                $query = new WP_Query(array(
                    'post_type' => array('product_variation'),
                    'post_status' => 'publish'
                ));
                $products = $query->get_posts();
                $parent_ids = array_column($products,'post_parent');

                // Make query
                $query = new WP_Query(array(
                    'posts_per_page' => $limit,
                    'paged'  => $page,
                    'post_status' => 'publish',
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'post_type' => array('product', 'product_variation'),
                    'post__not_in' => $parent_ids
                ));
                $products = $query->get_posts();
            }else{
                // Make query
                $query = new WP_Query(array(
                    'posts_per_page' => $limit,
                    'paged'  => $page,
                    'post_status' => 'publish',
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'post_type' => array('product')
                ));
                $products = $query->get_posts();
            }

            // Count products
            $data['count'] = $query->found_posts;

            // Total pages
            $data['max_pages'] = $query->max_num_pages;

            $data['products'] = array();

            // Retrive and send data in json
            foreach($products as $product){
                $product = wc_get_product($product->ID);
                $parent_id = $product->get_parent_id();
                // Process for parent product
                if($parent_id == 0){
                    // Exclude the variable product. (variations of it will be inserted.)
                    if($show_variations){
                        if(!$product->is_type('variable')){
                            $temp_product = $this->get_product_values($product);
                            $data['products'][] = $this->prepare_response_for_collection($temp_product);
                        }
                    }else{
                        $temp_product = $this->get_product_values($product);
                        $data['products'][] = $this->prepare_response_for_collection($temp_product);
                    }
                }else{
                    // Process for visible child
                    if($product->get_price()){
                        $temp_product = $this->get_product_values($product, TRUE);
                        $data['products'][] = $this->prepare_response_for_collection($temp_product);
                    }
                }
            }
            return $data;
        }

        /**
         * Get a product or list of products
         *
         * @param WP_REST_Request $request Full data about the request.
         * @return WP_Error|WP_REST_Response
         */
        private function get_list_products($product_list)
        {
            $data['products'] = array();

            // Retrive and send data in json
            foreach($product_list as $pid){
                $product = wc_get_product($pid);
                if($product && $product->get_status() === "publish"){
                    $parent_id = $product->get_parent_id();
                    // Process for parent product
                    if($parent_id == 0){
                        $temp_product = $this->get_product_values($product);
                        $data['products'][] = $this->prepare_response_for_collection($temp_product);
                    }else{
                        // Process for visible child
                        if($product->get_price()){
                            $temp_product = $this->get_product_values($product, TRUE);
                            $data['products'][] = $this->prepare_response_for_collection($temp_product);
                        }
                    }
                }
            }
            return $data;
        }

        /**
         * Get a slugs or list of slugs. For getting product's data by its link
         *
         * @param WP_REST_Request $request Full data about the request.
         * @return WP_Error|WP_REST_Response
         */
        private function get_list_slugs($slug_list)
        {
            $data['products'] = array();

            // Retrive and send data in json
            foreach($slug_list as $sid){
                $product = get_page_by_path($sid, OBJECT, 'product');
                if($product && $product->post_status === "publish"){
                    $temp_product = $this->get_product_values(wc_get_product($product->ID));
                    $data['products'][] = $this->prepare_response_for_collection($temp_product);
                }
            }
            return $data;
        }

        /**
         * Get all or a collection of products
         *
         * @param WP_REST_Request $request Full data about the request.
         * @return WP_Error|WP_REST_Response
         */
        public function get_products($request)
        {
            // Get Parameters
            $show_variations = rest_sanitize_boolean($request->get_param('variation'));
            $limit = intval($request->get_param('limit'));
            $page = intval($request->get_param('page'));
            if(!empty($request->get_param('products'))){
                $product_list = explode(',', (sanitize_text_field($request->get_param('products'))));
                if(is_array($product_list)){
                    foreach($product_list as $key => $field){
                        $product_list[$key] = intval($field);
                    }
                }
            }
            if(!empty($request->get_param('slugs'))){
                $slug_list = explode(',', (sanitize_text_field(urldecode($request->get_param('slugs')))));
            }


			$need_request = true;
		 	$emalls_token =	$request->get_param('token');
  			session_start();
			
            $site_wp_token = get_option('emalls_connection');
			if($site_wp_token == $emalls_token){
				$need_request = false;
			}


			if($need_request == false){
				if(!empty($product_list)){
                    $data = $this->get_list_products($product_list);
                }elseif(!empty($slug_list)){
                	$data = $this->get_list_slugs($slug_list);
                }else{
                	$data = $this->get_all_products($show_variations, $limit, $page);
                }
                $response_code = 200;
				$data['Version'] = $this->emalls_ext_version;
				$data['NeedSession'] = $need_request;
				$data['TokenSendByEmalls'] = $emalls_token;
				$data['SignedByEasy'] = 'chegeni';
				$data['EasyMode'] = true;
            	return new WP_REST_Response($data, $response_code);
			}

            // Check request is valid and update
            $response = $this->check_request($request);
            if(!is_array($response)){
                update_option('emalls_connection', '---');	
                $data['Response'] = '';
                $data['Error'] = $response;
                $response_code = 500;
            }
            else{
                $response_body = $response['body'];
                $response = json_decode($response_body, true);

                if($response['success'] === TRUE && $response['message'] === 'the token is valid'){
                    update_option('emalls_connection', $emalls_token);

                    if(!empty($product_list)){
                        $data = $this->get_list_products($product_list);
                    }elseif(!empty($slug_list)){
                        $data = $this->get_list_slugs($slug_list);
                    }else{
                        $data = $this->get_all_products($show_variations, $limit, $page);
                    }
                    $response_code = 200;
                }
                else{
                    update_option('emalls_connection', '---');	

                    $data['Response'] = $response_body;
                    $data['Error'] = $response['error'];

 					$site_url = wp_parse_url(get_site_url());
            		$shop_domain = str_replace('www.','',$site_url['host']);

					$data['shop_domain'] = $shop_domain;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->emalls_ext_version;
			$data['NeedSession'] = $need_request;
			$data['TokenSendByEmalls'] = $emalls_token;
			$data['SignedBy'] = 'chegeni';
            return new WP_REST_Response($data, $response_code);
        }
    }
    $EmallsWooCommerceExtraction = new EmallsWooCommerceExtraction;
}
