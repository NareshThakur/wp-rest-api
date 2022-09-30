<?php
/**
 * Plugin name: WP Rest API
 * Author: Naresh Kumar
*/

/**
 * 
 */
class MgsqWCRestAPI
{
  
    function __construct()
    {
        add_action('rest_api_init', function () {
            register_rest_route( 'product', 'create',array(
                'methods'  => 'POST',
                'callback' => [$this, 'wcProductCreate'],
                'permission_callback' => [$this, 'wcRestCheckUser'] 
            ));

            register_rest_route( 'product', 'delete',array(
                'methods'  => 'POST',
                'callback' => [$this, 'apiProductDelete'],
                'permission_callback' => [$this, 'wcRestCheckUser'] 
            ));
        });

        add_filter( 'woocommerce_get_sections_advanced', [ $this, 'wcConnectorSettingsTab' ] );
        add_filter( 'woocommerce_get_settings_advanced', [$this, 'wcConnectorSettingsFields' ], 10, 2 );
    }

    function wcConnectorSettingsTab( $sections ) {
    
        $sections['connector_engine'] = __( 'Connector Engine', 'text-domain' );
        return $sections;
        
    }


    function wcConnectorSettingsFields( $settings, $current_section ) {
        /**
         * Check the current section is what we want
         **/
        if ( $current_section == 'connector_engine' ) {
            $settings_slider = array();
            // Add Title to the Settings
            $settings_slider[] = array( 'name' => __( 'WC Slider Settings', 'text-domain' ), 'type' => 'title', 'desc' => __( 'The following options are used to configure of Connector engine', 'text-domain' ), 'id' => 'connector_engine' );
            // Add first checkbox option
            $settings_slider[] = array(
                'name'     => __( 'Rest API key', 'text-domain' ),
                'desc_tip' => __( 'This API Key will be used to connect to connector engine.', 'text-domain' ),
                'id'       => 'wcrest_api_key',
                'type'     => 'text',
                'desc'     => __( 'This API Key will be used to connect to connector engine.', 'text-domain' ),
            );
            
            $settings_slider[] = array( 'type' => 'sectionend', 'id' => 'connector_engine' );
            return $settings_slider;
        
        /**
         * If not, return the standard settings
         **/
        } else {
            return $settings;
        }
    }

    public function wcRestCheckUser(WP_REST_Request $request)
    {

        // print_r( get_class_methods($request));

        if ( $request->get_param('API_KEY') == get_option('wcrest_api_key') ) {
            return true;
        }
        return false;
    }

    public function getProductIDBySKU($sku='')
    {
        if ( $sku == "" ) return false;

        global $wpdb;
        $metadata = $wpdb->get_row("SELECT `post_id` FROM {$wpdb->postmeta} WHERE `meta_key`='_sku' AND `meta_value`='$sku'",ARRAY_A);

        if ( empty($metadata) ) {
            return false;
        } else {
            return $metadata['post_id'];
        }

    }

    function wcProductCreate(\WP_REST_Request $request)
    {

        $product_data = $request->get_body();

        $product_data = json_decode($product_data,true);
        // die();

        if( !isset($product_data['product_type']) || $product_data['product_type'] == '' ) {
            return new WP_Error( 'invalid_request', 'Empty product type.', array('status' => 404) );
        }

        if ( empty($product_data['children'] ) ) {
            return new WP_Error( 'invalid_request', 'No child products.', array('status' => 404) );
        }

        /*Process categories*/
        $categories = [];
        if ( !empty($product_data['product_categories']) ) {
            foreach ($product_data['product_categories'] as $pc_key => $pc_value) {
                
                $term_id = $this->createCategory($pc_value);
                if ( $term_id != false ) {
                    $categories[] = $term_id;
                }

            }

            if ( !empty($categories) ) {
                $product_data['term_ids'] = $categories;
            }
        }

        // pr($product_data);

        $product_response = "";
        if ( $product_data['product_type'] == 'grouped' ) {
            
            $product_response = $this->wcCreateGroupedProduct($product_data);
        } else if ( $product_data['product_type'] == 'simple' ) {
            // coming soon
        } else if ( $product_data['product_type'] == 'variable' ) {
            // coming soon
        } 

        if ( $product_response != false ) {

            $posts = [ 'code' => 'success', 'product_id' => $product_response ];    
            $response = new WP_REST_Response($posts);
            $response->set_status(200);

            return $response;

        } else {

            return new WP_Error( 'invalid_request', 'Error while creating product.', array('status' => 404) );

        }
        
    }

    function apiProductDelete(\WP_REST_Request $request)
    {

        $product_data = $request->get_body();

        $product_data = json_decode($product_data,true);
        // die();

        if( !isset($product_data['product_id']) || $product_data['product_id'] == '' ) {
            return new WP_Error( 'invalid_request', 'Empty Product ID', array('status' => 404) );
        }

        $deleteResponse = $this->wcProductDelete($product_data['product_id'], true);


        if ( $deleteResponse != false ) {

            $posts = [ 'code' => 'success', 'product_id' => $product_data['product_id'] ];    
            $response = new WP_REST_Response($posts);
            $response->set_status(200);
        
            return $response;

        } else {

            return new WP_Error( 'invalid_request', 'Invalid request', array('status' => 404) );

        }

        return false;
    }

    public function wcCreateGroupedProduct( $product_data = [] )
    {
        global $wpdb;
        $children = [];
        $attributes = [];

        try {

            /*Can't create an empty grouped product*/
            if ( empty($product_data['children']) ) return false;

            foreach ($product_data['children'] as $pc_key => $child) {
            
                $child_product = [
                    'details' => [
                        'title' => $product_data['details']['title'],
                        'short_description' => $product_data['details']['short_description'],
                        'description' => $product_data['details']['description'],
                        'status' => $child['status'],
                        'sku' => $child['sku'],
                        'catalog_visiblity' => isset($child['catalog_visiblity']) ? $child['catalog_visiblity'] : 'hidden'
                    ],
                    'price' => $child['price'],
                    'regular_price' => $child['regular_price'],
                    'sale_price' => $child['sale_price'],
                    'stock' => $child['stock'],
                    'images' => $child['images'],
                    'meta' => $child['meta'],
                    'term_ids' => isset($product_data['term_ids']) ? $product_data['term_ids'] : [],
                    'attributes' => $child['attributes'],
                    'taxonomy' => $product_data['taxonomy'],
                ];

                if ( isset($child['title_suffix']) && $child['title_suffix'] != '' ) {
                    $child_product['details']['title'] = $child_product['details']['title'] . ' ' . implode(' ', $child['title_suffix']);
                }

                $simple_product = $this->wcCreateSimpleProduct($child_product);
                if ( $simple_product != false ) {
                    
                    $children[] = $simple_product['id'];
                        
                }
                
            }


            /*Check if the product exists or not*/
            $remote_id = $product_data['meta']['remote_identity'];
            $product_id = 0;
            
            if ( $remote_id == "" && $product_data['details']['sku'] != "" ) {
                $product_id = $this->getProductIDBySKU($product_data['details']['sku']); 
            } else {
                $metadata = $wpdb->get_row("SELECT `post_id` FROM {$wpdb->postmeta} WHERE `meta_key`='remote_identity' AND `meta_value`='$remote_id'",ARRAY_A);    
                if ( !empty($metadata) ) {
                    $product_id = $metadata['post_id'];
                }
            }

            $product = null;
            if ( $product_id > 0 ) {
                $product = new WC_Product_Grouped($product_id);    
            } else {
                $product = new WC_Product_Grouped();    
            }

            // pr($attributes);

            /*Set attributes for the grouped product*/
            $attribute_object = [];
            if ( !empty($product_data['attributes']) ) {
                foreach ($product_data['attributes'] as $att_type => $att_value) {
                    
                    $attribute_type = 'pa_'.$att_type;
                    $options = [];
                    foreach ($att_value as $av_key => $av_value) {

                        $options[] = $av_value['label'];
                    }
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_id( wc_attribute_taxonomy_id_by_name( $attribute_type ) );
                    $attribute->set_name($attribute_type);
                    $attribute->set_visible(true);
                    $attribute->set_options($options);
                    $attribute_object[] = $attribute;
                }

                if ( !empty($attribute_object)) {
                    $product->set_attributes($attribute_object);
                }
            }
            
            $product->set_name( $product_data['details']['title'] );
            $product->set_description( $product_data['details']['description'] );
            $product->set_short_description( $product_data['details']['short_description'] );

            if ( isset($product_data['term_ids']) ) {
                $product->set_category_ids( $product_data['term_ids'] ); 
            }
            
            $product->set_status( $product_data['details']['status'] ); 
            // $product->set_catalog_visibility( 'visible' );

            if ( isset($product_data['details']['catalog_visiblity']) && $product_data['details']['catalog_visiblity'] != "" ) {
                $product->set_catalog_visibility($product_data['details']['catalog_visiblity']);                
            }
                
            // pr($product_data['images']);
            /*Save Images*/
            if ( !empty($product_data['images']) ) {
                $images = [];
                foreach ($product_data['images'] as $img_key => $image) {
                    $images[] = $this->saveImageFromURL($image);
                }

                if ( !empty($images) ) {
                    $product->set_image_id( $images[0] );

                    /*Set gallery images if there are more than 1 image in the array*/
                    if ( count($images) > 1 ) {
                        unset($images[0]);
                        /*Set other images as gallery images*/
                        $product->set_gallery_image_ids($images);
                    }
                }

            }

            $product->set_downloadable( false );
            $product->set_virtual( false );    
            $product->set_children($children);

            $product->save(); 

            /*Assign Terms*/
            $this->assignTerms($product, $product_data['taxonomy']);

            $this->updateMeta($product->get_id(), $product_data['meta']);

            return $product->get_id();

        } catch (\Exception $e) {
            return false;
        }
        
    }

    public function wcCreateSimpleProduct($product_data=[])
    {

        global $wpdb;

        $remote_id = $product_data['meta']['remote_identity'];
        $product_id = 0;
        if ( $product_data['details']['sku'] != "" ) {
            $product_id = $this->getProductIDBySKU($product_data['details']['sku']);
        } else {

            $metadata = $wpdb->get_row("SELECT `post_id` FROM {$wpdb->postmeta} WHERE `meta_key`='remote_identity' AND `meta_value`='$remote_id'",ARRAY_A);    
            if ( !empty($metadata) ) {
                $product_id = $metadata['post_id'];
            }
        }
        
        
        if ( $product_id > 0 ) {
            $product = new WC_Product_Simple($product_id);
        } else {
            $product = new WC_Product_Simple();
        }
        
        
        /*Set basic details*/
        $product->set_name( $product_data['details']['title'] );
        $product->set_description( $product_data['details']['description'] );
        $product->set_short_description( $product_data['details']['short_description'] );
        $product->set_status( $product_data['details']['status'] ); 

        if ( isset($product_data['details']['catalog_visiblity']) && $product_data['details']['catalog_visiblity'] ) {
            $product->set_catalog_visibility( $product_data['details']['catalog_visiblity'] );
        }
        
        
        $product->set_price( $product_data['price'] );
        $product->set_sale_price( $product_data['sale_price'] );
        $product->set_regular_price( $product_data['regular_price'] );
        $product->set_stock( $product_data['stock'] );
        
        $product->set_category_ids( $product_data['term_ids'] ); 
        $product->set_downloadable( false );
        $product->set_virtual( false ); 

        // $product->set_sold_individually( true );

        /*Save Images*/
        if ( !empty($product_data['images']) ) {

            $images = [];
            foreach ($product_data['images'] as $img_key => $image) {
                $images[] = $this->saveImageFromURL($image);
            }

            if ( !empty($images) ) {
                $product->set_image_id( $images[0] );

                /*if there are more than 1 image in the array*/
                if ( count($images) > 1 ) {
                    unset($images[0]);
                    /*Set other images as gallery images*/
                    $product->set_gallery_image_ids($images);
                }
            }

        }

        /*Process attributes*/
        $attribute_object = [];
        $term_ids = [];
        if ( !empty($product_data['attributes']) ) {
            foreach ($product_data['attributes'] as $att_key => $att_value) {
                
                $att_value['taxonomy'] = 'pa_'.$att_value['type'];

                $term_id = $this->createCategory($att_value);
                if ( $term_id != false ) {
                    $term_ids[] = $term_id;

                    $attribute = new WC_Product_Attribute();
                    $attribute->set_id( wc_attribute_taxonomy_id_by_name( $att_value['taxonomy'] ) );
                    $attribute->set_name($att_value['taxonomy']);
                    $attribute->set_visible(true);
                    $attribute->set_options([$att_value['label']]);
                    $attribute_object[] = $attribute;

                    
                }

            }

            if ( !empty($attribute_object) ) {
                $product->set_attributes($attribute_object);
            }
        }
        
        $product->save();

        $this->updateMeta($product->get_id(), $product_data['meta']);

        /*Assign Terms*/
        $this->assignTerms($product, $product_data['taxonomy']);

        return [
            'id' => $product->get_id(),
            'attributes' => $attribute_object
        ];

    }

    public function wcProductDelete($id, $force=false)
    {
        global $wpdb;


        $product_row = $wpdb->get_row("SELECT post_id FROM {$wpdb->postmeta} WHERE `meta_key`='remote_identity' AND `meta_value`='Product $id'", ARRAY_A);
        if ( empty($product_row) ) {
            return false;
        }

        $product = wc_get_product($product_row['post_id']);

        if(empty($product))
            return false;

        // If we're forcing, then delete permanently.
        if ($force)
        {
            if ($product->is_type('variable'))
            {
                foreach ($product->get_children() as $child_id)
                {
                    $child = wc_get_product($child_id);
                    $child->delete(true);
                }
            }
            elseif ($product->is_type('grouped'))
            {

                /*Delete children*/
                foreach ($product->get_children() as $child_id)
                {
                    $child = wc_get_product($child_id);
                    $child->delete(true);
                    /*$child->set_parent_id(0);
                    $child->save();*/
                }
            }

            $product->delete(true);
            $result = $product->get_id() > 0 ? false : true;
        }
        else
        {
            $product->delete();
            $result = 'trash' === $product->get_status();
        }

        if (!$result)
        {
            return false;
        }

        // Delete parent product transients.
        if ($parent_id = wp_get_post_parent_id($id))
        {
            wc_delete_product_transients($parent_id);
        }
        return true;
    }

    public function updateMeta($post_id, $meta_data=[])
    {
        if ( !empty($meta_data) ) {
            foreach ($meta_data as $meta_key => $meta_value) {
                update_post_meta($post_id, $meta_key, $meta_value);
            }
            
        }
    }

    /**
     * Assign term taxonomy. If the taxonomy doesn't exists then ignore.
    */
    public function assignTerms($product, $term_data)
    {
        if ( !empty($term_data) ) {
            foreach ($term_data as $taxonomy_key => $terms) {

                if ( taxonomy_exists($taxonomy_key) ) {
                    
                    foreach ($terms as $key => $term) {

                        $found_term = get_term_by( 'name', $term, $taxonomy_key, ARRAY_A );
                        
                        if ( $found_term === false  ) {
                            $found_term = wp_insert_term( $term, $taxonomy_key );

                        }   

                        // pr($found_term); die();

                        if ( !is_wp_error($found_term) ) {
                            if ( !has_term( $found_term['term_id'], $taxonomy_key, $product->get_id() ) ) {
                                wp_set_object_terms($product->get_id(), $found_term['term_id'], $taxonomy_key, true );
                            }
                        }
                        
                    }
                }
                
            }
        }
    }

    public function saveImageFromURL($image_data)
    {
        
        if ( $image_data['url'] == '' ) return false;

        /**
         * Check if the image already exists in the db
        */
        global $wpdb;
        $image = $wpdb->get_row("SELECT ID FROM {$wpdb->posts} AS posts 
            LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.ID=postmeta.post_id 
            WHERE `post_type`='attachment' AND `meta_key`='remote_id' AND `meta_value`='$image_data[remote_id]'", ARRAY_A);

        /*return POST id if image found in the db*/
        if ( !empty($image) ) {
            return $image['ID'];
        }


        $filename = basename($image_data['url']);

        $uploaddir = wp_upload_dir();
        $uploadfile = $uploaddir['path'] . '/' . $filename;

        $contents= file_get_contents($image_data['url']);
        $savefile = fopen($uploadfile, 'w');
        fwrite($savefile, $contents);
        fclose($savefile);

        $wp_filetype = wp_check_filetype(basename($filename), null );

        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => $filename,
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment( $attachment, $uploadfile );

        $imagenew = get_post( $attach_id );
        $fullsizepath = get_attached_file( $imagenew->ID );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $fullsizepath );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        update_post_meta($attach_id,'remote_id', $image_data['remote_id']);

        return $attach_id;

    }

    public function createCategory($category_data)
    {
        
        global $wpdb;

        if ( empty($category_data) || $category_data['label'] == "" || $category_data['remote_id'] == "" ) {
            return false;
        }

        $category_exists = $wpdb->get_row("SELECT `term_id` FROM {$wpdb->termmeta} WHERE `meta_key`='remote_id' AND `meta_value`='$category_data[remote_id]'");

        if ( !empty($category_exists) ) {
            return $category_exists->term_id;
        } else {

            $category = wp_insert_term( $category_data['label'], $category_data['taxonomy'] );
            if( is_wp_error($category) ) {
                
                return false;

            } else {

                update_term_meta($category['term_id'], 'remote_id', $category_data['remote_id']);
                return $category['term_id'];

            }

        }

        return false; 

    }
}

if ( !function_exists('pr')) {
    function pr($array, $var_dump=false)
    {
        echo "<pre>";

        if ($var_dump == true) {
            var_dump($array);
        } else {
            print_r($array);
        }
        
        echo "</pre>";
    }
}

$obj = new MgsqWCRestAPI();
