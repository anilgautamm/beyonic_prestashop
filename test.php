<?php

class product_sync {

    private static $instance = NULL;
    private $product_data, $product_response_data, $sync_options, $shop = array();
    private $images = array();
    private $dest_default_cat = 222;

    static public function getInstance() {
        if (self::$instance === NULL)
            self::$instance = new product_sync();
        return self::$instance;
    }

    public function checkSyncProduct($post_id, $post, $shop) {
        $post_status = get_post_status($post_id);

        if ($post_status == "auto-draft") {
            return;
        }
        // Autosave, do nothing
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        // AJAX? Not used here
        if (defined('DOING_AJAX') && DOING_AJAX)
            return;
        // Check user permissions
        if (!current_user_can('edit_post', $post_id))
            return;
        // Return if it's a post revision
        if (false !== wp_is_post_revision($post_id))
            return;

        if ($post->post_type == 'product') {

            $this->syncProductToDestination($post_id, $shop);
        }
        return;
    }

    public function syncProductToDestination($product_id, $shop) {

        global $TM_WOO_API_LINK_SETTINGS;

        $TM_WOO_API_LINK_SETTINGS['general']['shop_url'] = $shop['shop_url'];
        $TM_WOO_API_LINK_SETTINGS['general']['api_key'] = $shop['api_key'];
        $TM_WOO_API_LINK_SETTINGS['general']['api_secret'] = $shop['api_secret'];
        if (isset($TM_WOO_API_LINK_SETTINGS['product'][$shop['shop_alias']])) {
            $this->sync_options = $TM_WOO_API_LINK_SETTINGS['product'][$shop['shop_alias']];
        }
        $this->shop = $shop;
        init_tm_api_api_link();


        if (!empty($GLOBALS['tm_woo_api_client_conn'])) {
            global $tm_woo_api_client_conn;
            //check if order update
            $dest_product_id = get_post_meta($product_id, 'tm_woo_api_dest_product_id_' . urlencode($this->shop['shop_alias']), TRUE);

            $this->getProductData($product_id);

            if ($this->product_data) {
                //set prices
                $this->setPrices();

                //sync product tags
                $this->syncProductTags();

                //sync product tags
                $this->syncProductImages();

                $this->product_data['categories'] = '';
                $this->product_data['categories'][0]['id'] = $this->dest_default_cat;
                $this->product_data['categories'][0]['name'] = '';
                $this->product_data['categories'][0]['slug'] = '';
                //sync product categories
                //$this->syncProductCategories();
                //sync product attributes 
                $this->syncProductAttributes();
               
                $this->product_data['price'] = (string) $this->product_data['price'];
                $this->product_data['regular_price'] = (string) $this->product_data['regular_price'];
                $this->product_data['sale_price'] = (string) $this->product_data['sale_price'];

                if (!empty($this->product_data['meta_data'])) {

                    foreach ($this->product_data['meta_data'] as $key => $meta_value) {
                        // print_r($meta_value->key);die;
                        $this->product_data['meta_data'][$key] = new stdClass;
                        $this->product_data['meta_data'][$key]->key = $meta_value->key;

                        if (is_array($meta_value->value)) {
                            $this->product_data['meta_data'][$key]->value = serialize($meta_value->value);
                        } else {
                            $this->product_data['meta_data'][$key]->value = (string) $meta_value->value;
                        }
                    }
                }


                //check if product exists on destination website
                if (!empty($dest_product_id)) {
                    $this->product_data['id'] = $dest_product_id;
                    $product_response = $tm_woo_api_client_conn->doRequest('put', 'products/' . $dest_product_id, $this->product_data);
                } else {
                    unset($this->product_data['id']);
                    unset($this->product_data['permalink']);
                    $product_response = $tm_woo_api_client_conn->doRequest('post', 'products', $this->product_data);
                }



                if ($product_response['status']) {
                    $this->product_response_data = $product_response['data'];

                    $dest_product_id = $this->product_response_data['id'];

                    update_post_meta($product_id, 'tm_woo_api_dest_product_id_' . urlencode($this->shop['shop_alias']), $dest_product_id);
                    //maintain sync of image ids
                    $this->saveSyncedProductImages();

                    if (!empty($this->product_data['variations'])) {
                        //sync product variations
                        $this->syncVariations($product_id, $dest_product_id, $shop);
                    }
                }
            }
        }
    }

    private function setPrices() {
        $this->sync_options;

        if (isset($this->sync_options['woo_api_price_amount'])) {
            $options = $this->sync_options;
            $dest_product_regular_price = $this->product_data['regular_price'];
            $dest_product_sale_price = $this->product_data['sale_price'];
            $operator = $options['woo_api_price_operator'];
            if ($operator == "minus") {
                if ($options['woo_api_price_change_type'] == 'fixed') {
                    $this->product_data['regular_price'] = $dest_product_regular_price - $options['woo_api_price_amount'];
                    if (!empty($dest_product_sale_price)) {
                        $this->product_data['price'] = $dest_product_sale_price - $options['woo_api_price_amount'];
                        $this->product_data['sale_price'] = $dest_product_sale_price - $options['woo_api_price_amount'];
                    }
                } else {
                    $this->product_data['regular_price'] = $dest_product_regular_price - (($dest_product_regular_price * $options['woo_api_price_amount']) / 100);
                    if (!empty($dest_product_sale_price)) {
                        $this->product_data['sale_price'] = $dest_product_sale_price - (($dest_product_sale_price * $options['woo_api_price_amount']) / 100);
                        $this->product_data['price'] = $dest_product_sale_price - (($dest_product_sale_price * $options['woo_api_price_amount']) / 100);
                    }
                }
            } else {
                if ($options['woo_api_price_change_type'] == 'fixed') {
                    $this->product_data['regular_price'] = $dest_product_regular_price + $options['woo_api_price_amount'];
                    if (!empty($dest_product_sale_price)) {
                        $this->product_data['sale_price'] = $dest_product_sale_price + $options['woo_api_price_amount'];
                        $this->product_data['price'] = $dest_product_sale_price + $options['woo_api_price_amount'];
                    }
                } else {
                    $this->product_data['regular_price'] = $dest_product_regular_price + (($dest_product_regular_price * $options['woo_api_price_amount']) / 100);
                    if (!empty($dest_product_sale_price)) {
                        $this->product_data['sale_price'] = $dest_product_sale_price + (($dest_product_sale_price * $options['woo_api_price_amount']) / 100);
                        $this->product_data['price'] = $dest_product_sale_price + (($dest_product_sale_price * $options['woo_api_price_amount']) / 100);
                    }
                }
            }
        }

        return;
    }

    /*
     * sync product tags
     */

    private function syncProductTags() {
        global $tm_woo_api_client_conn;

        if (!empty($this->product_data['tags'])) {
            foreach ($this->product_data['tags'] as $key => $tag) {
                $tag_id = 0;
                $check_tag_synced = get_term_meta($tag['id'], 'dest_term_id_' . urlencode($this->shop['shop_alias']), true);

                if (!empty($check_tag_synced)) {
                    $tag_id = $check_tag_synced;
                } else {
                    $tag_data = array('name' => $tag['name']);
                    $tag_create_request = $tm_woo_api_client_conn->doRequest('post', 'products/tags', $tag_data);

                    if ($tag_create_request['status']) {
                        $tag_id = $tag_create_request['data']['id'];
                    } elseif (isset($tag_create_request['resource_id'])) {
                        $tag_id = $tag_create_request['resource_id'];
                    }

                    if ($tag_id) {
                        update_term_meta($tag['id'], 'dest_term_id_' . urlencode($this->shop['shop_alias']), $tag_id);
                    }
                }

                if ($tag_id) {
                    $this->product_data['tags'][$key]['id'] = $tag_id;
                } else {
                    unset($this->product_data['tags'][$key]);
                }
            }
        }
    }

    /*
     * check if images are already synced to destination store, if they are synced, use already created image
     */

    private function syncProductImages() {
        if (!empty($this->product_data['images'])) {
            foreach ($this->product_data['images'] as $key => $image) {
                $this->images[$image['name']]['synced_id'] = 0;
                $this->images[$image['name']]['id'] = $image['id'];
                $check_image_synced = get_post_meta($image['id'], 'dest_attachment_id_' . urlencode($this->shop['shop_alias']), true);
                if (!empty($check_image_synced)) {
                    $this->images[$image['name']]['synced_id'] = $check_image_synced;
                    $this->product_data['images'][$key]['id'] = $check_image_synced;
                } else {
                    unset($this->product_data['images'][$key]['id']);
                }
            }
        }
    }

    /*
     * sync created product images to source store
     */

    private function saveSyncedProductImages() {
        if (!empty($this->product_response_data['images'])) {
            foreach ($this->product_response_data['images'] as $synced_image) {
                if (array_key_exists($synced_image['name'], $this->images) && $this->images[$synced_image['name']]['synced_id'] == 0) {
                    update_post_meta($this->images[$synced_image['name']]['id'], 'dest_attachment_id_' . urlencode($this->shop['shop_alias']), $synced_image['id']);
                }
            }
        }
    }

    private function syncProductCategories() {

//        if (!empty($this->product_data['categories'])) {
//            foreach ($this->product_data['categories'] as $key => $category) {
//                $dest_category_id = get_term_meta($category['id'], 'dest_term_id_' . urlencode($this->shop['shop_alias']), true);
//
//                if (empty($dest_category_id)) {
//                    //check if terms have parent
//                    $term_ancestors = get_ancestors($category['id'], 'product_cat');
//                    if (empty($term_ancestors)) {
//                        $dest_category_id = $this->syncCategory($category['id']);
//                    } else {
//                        $term_ancestors = array_reverse($term_ancestors);
//
//                        $parent_id = 0;
//                        foreach ($term_ancestors as $term_ancestor) {
//                            $check_ancestor_category_id = get_term_meta($term_ancestor, 'dest_term_id_' . urlencode($this->shop['shop_alias']), true);
//                            if (empty($check_ancestor_category_id)) {
//                                $parent_id = $this->syncCategory($term_ancestor, $parent_id);
//                            } else {
//                                $parent_id = $check_ancestor_category_id;
//                            }
//                        }
//
//                        $dest_category_id = $this->syncCategory($category['id'], $parent_id);
//                    }
//                }
//
//                if (!empty($dest_category_id)) {
//                    $this->product_data['categories'][$key]['id'] = $dest_category_id;
//                } else {
//                    unset($this->product_data['categories'][$key]);
//                }
//            }
//        }
    }

    private function syncCategory($category_id, $parent_id = 0) {
        global $tm_woo_api_client_conn;
        $cat_data = get_term($category_id, 'product_cat', ARRAY_A);
        $cat_data_request = array();
        $cat_data_request['name'] = $cat_data['name'];
        $cat_data_request['description'] = $cat_data['description'];

        $category_image = get_term_meta($category_id, 'thumbnail_id', TRUE);

        if ($category_image) {
            $cat_data_request['image'] = array('src' => wp_get_attachment_url($category_image));
        }

        if ($parent_id) {
            $cat_data_request['parent'] = $parent_id;
        }
        $category_create_request = $tm_woo_api_client_conn->doRequest('post', 'products/categories', $cat_data_request);

        if ($category_create_request['status']) {
            if (isset($category_create_request['resource_id'])) {
                $dest_term_id = $category_create_request['resource_id'];
            } else {
                $dest_term_id = $category_create_request['data']['id'];
            }

            update_term_meta($category_id, "dest_term_id_" . urlencode($this->shop['shop_alias']), $dest_term_id);
            return $dest_term_id;
        }

        return 0;
    }

    private function syncProductAttributes() {

        global $tm_woo_api_client_conn, $wpdb;

        if (!empty($this->product_data['attributes'])) {

            foreach ($this->product_data['attributes'] as $key => $attribute) {

                if ($attribute['id']) {

                    $attribute_id = get_option('dest_synced_attr_' . $attribute['id'], 0);
                    if ($attribute_id == 0) {
                        $attribute_to_edit = $wpdb->get_row("SELECT attribute_type, attribute_label, attribute_name, attribute_orderby, attribute_public FROM " . $wpdb->prefix . "woocommerce_attribute_taxonomies WHERE attribute_id = '" . $attribute['id'] . "'");

                        $attr_data = array();
                        $attr_data['type'] = $attribute_to_edit->attribute_type;
                        $attr_data['name'] = $attribute_to_edit->attribute_label;
                        $attr_data['order_by'] = $attribute_to_edit->attribute_orderby;
                        $attr_data['has_archives'] = $attribute_to_edit->attribute_public;

                        $attribute_create_request = $tm_woo_api_client_conn->doRequest('post', 'products/attributes', $attr_data);

                        if ($attribute_create_request['status']) {
                            $attribute_id = $attribute_create_request['data']['id'];
                        } elseif (isset($attribute_create_request['resource_exists'])) {
                            //this is a hack, API doesn't provide a method to retrieve product attributes by name/slug
                            $this->product_data['attributes'][$key]['id'] = 0;
                        } else {
                            unset($this->product_data['attributes'][$key]['id']);
                            continue;
                        }
                    }

                    //attribute taxomony is synced now need to sync attrinute terms
                    if ($attribute_id) {

                        add_option('dest_synced_attr_' . $attribute['id'], $attribute_id);

                        $this->product_data['attributes'][$key]['id'] = $attribute_id;

                        $attribute_terms = wp_get_post_terms($this->product_data['id'], "pa_" . $attribute_to_edit->attribute_name);

                        foreach ($attribute_terms as $key => $attribute_term) {
                            $dest_term_id = get_term_meta($attribute_term->id, 'dest_term_id_' . urlencode($this->shop['shop_alias']), true);
                            if (empty($dest_term_id)) {

                                $attr_term_data['name'] = $attribute_term->name;
                                $attr_term_data['description'] = $attribute_term->description;
                                $attribute_term_create_request = $tm_woo_api_client_conn->doRequest('post', 'products/attributes/' . $attribute_id . '/terms', $attr_term_data);

                                if ($attribute_term_create_request['status']) {
                                    $attribute_term_id = $attribute_term_create_request['data']['id'];
                                    update_term_meta($attribute_term->id, 'dest_term_id_' . urlencode($this->shop['shop_alias']), $attribute_term_id);
                                } elseif (isset($category_create_request['resource_id'])) {
                                    update_term_meta($attribute_term->id, 'dest_term_id_' . urlencode($this->shop['shop_alias']), $category_create_request['resource_id']);
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    private function syncVariations($product_id, $dest_product_id, $shop) {
        global $tm_woo_api_client_conn;
        $WC_REST_Product_Variations_Controller = new WC_REST_Product_Variations_Controller();
        $parent_sku = $this->product_data['sku'];
        foreach ($this->product_data['variations'] as $variation_id) {

            $is_variation_already_synced = get_post_meta($variation_id, 'tm_woo_api_dest_product_id_' . urlencode($shop['shop_alias']), TRUE);

            $var_data = wc_get_product($variation_id);


            $var_data = $WC_REST_Product_Variations_Controller->prepare_object_for_response($var_data, array());

            if ($var_data->data['sku'] == $parent_sku) {
                $var_data->data['sku'] = '';
            }

            $this->product_data = $var_data->data;

            if (!empty($this->product_data)) {
                unset($this->product_data['permalink']);
                $check_image_synced = $var_image_id = '';
                if (!empty($this->product_data['image'])) {
                    if ($this->product_data['image']['name'] == 'Placeholder') {
                        unset($this->product_data['image']);
                    } else {
                        $check_image_synced = get_post_meta($this->product_data['image']['id'], 'dest_attachment_id_' . urlencode($this->shop['shop_alias']), true);

                        if (!empty($check_image_synced)) {
                            $this->product_data['image']['id'] = $check_image_synced;
                        } else {
                            $var_image_id = $this->product_data['image']['id'];
                            unset($this->product_data['image']['id']);
                        }
                    }
                }


                foreach ($this->product_data['attributes'] as $attribute_key => $attribute) {
                    $attribute_id = get_option('dest_synced_attr_' . $attribute['id'], 0);
                    $this->product_data['attributes'][$attribute_key]['id'] = $attribute_id;
                }

                if (!empty($this->product_data['meta_data'])) {

                    foreach ($this->product_data['meta_data'] as $key => $meta_value) {
                        // print_r($meta_value->key);die;
                        $this->product_data['meta_data'][$key] = array();
                        $this->product_data['meta_data'][$key]->key = $meta_value->key;

                        if (is_array($meta_value->value)) {
                            $this->product_data['meta_data'][$key]->value = serialize($meta_value->value);
                        } else {
                            $this->product_data['meta_data'][$key]->value = $meta_value->value;
                        }
                    }
                }


                if (empty($is_variation_already_synced)) {
                    unset($this->product_data['id']);
                    $variation_response = $tm_woo_api_client_conn->doRequest('post', 'products/' . $dest_product_id . '/variations', $this->product_data);
                } else {
                    $this->product_data['id'] = $is_variation_already_synced;

                    $variation_response = $tm_woo_api_client_conn->doRequest('put', 'products/' . $dest_product_id . '/variations/' . $is_variation_already_synced, $this->product_data);
                }

                if ($variation_response['status']) {

                    $dest_variation_id = $variation_response['data']['id'];
                    update_post_meta($variation_id, 'tm_woo_api_dest_product_id_' . urlencode($shop['shop_alias']), $dest_variation_id);
                    if (empty($check_image_synced)) {
                        if (isset($variation_response['data']['image']) && !empty($variation_response['data']['image']) && !empty($var_image_id)) {

                            update_post_meta($var_image_id, 'dest_attachment_id_' . urlencode($this->shop['shop_alias']), $variation_response['data']['image']['id']);
                        }
                    }
                }
            }
        }
    }

    private function getProductData($product_id) {
        $p_data = wc_get_product($product_id);
        $WC_REST_Products_Controller = new WC_REST_Products_Controller();

        $product_object = $WC_REST_Products_Controller->prepare_object_for_response($p_data, array());

//        if (!empty($this->sync_options['disabled_cats']) && isset($product_object->data['categories'])) {
//
//            if (!empty($product_object->data['categories'])) {
//                foreach ($product_object->data['categories'] as $category) {
//                    if (in_array($category['id'], $this->sync_options['disabled_cats'])) {
//
//                        return;
//                        break;
//                    }
//                }
//            }
//        }

        $this->product_data = $product_object->data;
        if (!empty($this->product_data['meta_data'])) {
            foreach ($this->product_data['meta_data'] as $key => $meta) {
                if (strpos($meta->key, 'tm_woo_api') !== false) {
                    unset($this->product_data['meta_data'][$key]);
                }
            }
        }
        $new_meta = new stdClass;
        $new_meta->key = 'tm_woo_api_source_product_id';
        $new_meta->value = $product_id;

        $this->product_data['meta_data'][] = $new_meta;
        $this->product_data['meta_data'] = array_values($this->product_data['meta_data']);
        return;
    }

}
