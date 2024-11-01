<?php
/**
 * App api
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace Beeketing\QuickView\Api;


use Beeketing\QuickView\Data\Constant;
use Beeketing\QuickView\Helpers\Helper;

class App
{
    /**
     * Default color keys
     */
    public static $DEFAULT_COLOR_KEYS = array( 'Color', 'Colors', 'Colour', 'Colours' );

    /**
     * @var string
     */
    private $beeketing_path;

    /**
     * @var string
     */
    private $beeketing_api;

    /**
     * @var array
     */
    private static $terms = array();

    /**
     * App constructor.
     */
    public function __construct()
    {
        $this->beeketing_path = BEEKETINGQUICKVIEW_PATH;
        $this->beeketing_api = BEEKETINGQUICKVIEW_API;
    }

    /**
     * Get platform url
     *
     * @param $path
     * @param array $params
     * @return string
     */
    public function get_platform_url( $path, $params = array() )
    {
        $url = $this->beeketing_path . '/' . $path;

        if ( $params ) {
            $url .= '?' . http_build_query( $params, '', '&' );
        }

        return $url;
    }

    /**
     * Send tracking event
     *
     * @param $event
     * @param array $params
     * @return bool
     */
    public function send_tracking_event( $event, $params = array() )
    {
        if ( $event ) { // Track shop not logged in
            $current_user = wp_get_current_user();
            $email = $current_user->user_email;
            $headers = array(
                'Content-Type' => 'application/json',
                'X-Beeketing-Plugin-Version' => 999, // Fixed value
            );
            $params['event'] = $event;
            $params['email'] = $email;
            $params['event_params'] = array_merge( $params, array(
                'platform' => Constant::PLATFORM,
                'plugin' => Constant::APP_CODE,
                'shop_domain' => Helper::get_shop_domain(),
            ) );

            $args = array(
                'timeout' => 20,
                'redirection' => 5,
                'httpversion' => '1.0',
                'headers' => $headers,
                'blocking' => true,
                'cookies' => array()
            );
            $url = $this->get_platform_url( 'bk/analytic_tracking', $params );
            $api_response = wp_remote_get( $url, $args );

            // Render response
            $result = array();
            if ( $api_response && !is_wp_error( $api_response ) ) {
                $result = json_decode( wp_remote_retrieve_body( $api_response ), true );
            }

            if ( isset( $result['success'] ) && $result['success'] ) { // Install successfully
                return true;
            }
        }

        return false;
    }

    /**
     * Get product detail
     *
     * @param $id
     * @return array
     */
    public function get_product_detail( $id )
    {
        $product = wc_get_product( $id );
        $post = get_post( $id );
        $variants = $this->get_variants_by_product( $product );
        $images = $this->get_images_by_product( $product );
        $product_options = array();
        $position = 1;
        if ( $product->is_type( 'variable' ) ) {
            $product_attributes = $product->get_variation_attributes();
            foreach ( $product_attributes as $name => $product_attribute_values ) {
                $option_values = array();
                foreach ( $product_attribute_values as $product_attribute_value ) {
                    $term = get_term_by( 'slug', $product_attribute_value, $name );
                    $term_name = $product_attribute_value;
                    if ( $term ) {
                        $term_name = $term->name;
                    }
                    $option_values[] = $term_name;
                }
                $product_options[] = array(
                    'position' => $position,
                    'name' => str_replace( 'pa_', null, $name ),
                    'values' => $option_values,
                );
                $position++;
            }
        }

        return array(
            'id' => $id,
            'title' => $post->post_title,
            'url' => $product->get_permalink(),
            'vendor' => '',
            'images' => $images,
            'variants' => $variants,
            'available' => count( $variants ) ? true : false,
            'description' => $post->post_excerpt,
            'options' => $product_options,
        );
    }

    /**
     * Add cart
     *
     * @param $product_id
     * @param $variant_id
     * @param $quantity
     * @param $params
     * @return array
     */
    public function add_cart( $product_id, $variant_id, $quantity, $params )
    {
        global $woocommerce;
        $woocommerce->session->set_customer_session_cookie( true );
        $cart_item_key = $woocommerce->cart->add_to_cart( $product_id, $quantity, $variant_id, $params );

        $cart = $woocommerce->cart;
        $cart_items = $cart->get_cart();

        // Traverse cart items
        foreach ( $cart_items as $id => $item ) {
            if ( $cart_item_key == $id ) {
                // Base result
                $result = array(
                    'cart_count' => count( $cart_items ),
                    'cart_total' => $cart->subtotal,
                );

                return $result;
            }
        }

        return array();
    }

    /**
     * Get variants by product
     *
     * @param $product
     * @return array
     */
    public function get_variants_by_product( $product )
    {
        $variants = array();
        if ( $product->is_type( 'simple' ) ) {
            $data = $this->format_variant( $product, $product );
            if ( $data ) {
                $variants[] = $data;
            }
        } elseif ( $product->is_type( 'variable' ) ) {
            $product_id = Helper::is_wc3() ? $product->get_id() : $product->id; // Check wc version
            $args = array(
                'post_parent' => $product_id, // Check wc version
                'post_type'   => 'product_variation',
                'orderby'     => 'menu_order',
                'order'       => 'ASC',
                'fields'      => 'ids',
                'post_status' => 'publish',
                'numberposts' => -1
            );
            $variant_ids = get_posts( $args );

            foreach ( $variant_ids as $variant_id ) {
                $variant = wc_get_product( $variant_id );
                if ( !$variant || !$variant->exists() ) {
                    continue;
                }

                $data = $this->format_variant( $variant, $product );
                if ( $data ) {
                    $variants[] = $data;
                }
            }
        }

        return $variants;
    }

    /**
     * Format variant
     *
     * @param $variant
     * @param $product
     * @return array
     */
    public function format_variant( $variant, $product )
    {
        if ( !$variant->is_in_stock() || $variant->get_price() === '' ) {
            return array();
        }
        // Check wc version
        if ( Helper::is_wc3() ) {
            $variant_id = $variant->get_id();
        } else {
            $variant_id = $variant->is_type( 'variation' ) ? $variant->get_variation_id() : $variant->id;
        }

        // Get variant attributes
        $attributes = array();
        $variant_name = array();
        if ( $variant->is_type( 'variation' ) ) {
            $product_attributes = array();
            if ( $product->is_type( 'variable' ) ) {
                $product_attributes = $product->get_variation_attributes();
            }
            // Variation attributes
            foreach ( $variant->get_variation_attributes() as $attribute_name => $attribute ) {
                // Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
                $product_attribute_name = str_replace( 'attribute_', null, $attribute_name );
                if (
                    !$attribute &&
                    isset( $product_attributes[$product_attribute_name] ) &&
                    is_array( $product_attributes[$product_attribute_name] )
                ) {
                    // Get first attribute if any attribute
                    $attribute = array_shift( $product_attributes[$product_attribute_name] );
                }

                $attributes[$attribute_name] = $attribute;
                // Get term by slug
                $term_name = null;
                $term_key = $product_attribute_name . $attribute;
                if ( isset( self::$terms[$term_key] ) ) {
                    $term_name = self::$terms[$term_key];
                } else {
                    $term = get_term_by( 'slug', $attribute, $product_attribute_name );
                    if ($term) {
                        self::$terms[$term_key] = $term->name;
                        $term_name = $term->name;
                    }
                }
                $variant_name[] = $term_name ? $term_name : $attribute;
            }

        }

        $images = array();
        if ( $variant->is_type( 'variation' ) ) {
            $images = $this->get_images_by_product( $variant );
        }

        $result = array(
            'id' => $variant_id,
            'image_id' => isset( $images[0]['id'] ) ? $images[0]['id'] : '',
            'title' => $variant_name ? implode( '/', $variant_name ) : $variant->get_title(),
            'price' => $variant->get_price(),
            'price_compare' => $variant->get_regular_price() ? $variant->get_regular_price() : '',
            'option1' => isset( $variant_name[0] ) ? $variant_name[0] : '',
            'option2' => isset( $variant_name[1] ) ? $variant_name[1] : '',
            'option3' => isset( $variant_name[2] ) ? $variant_name[2] : '',
            'attributes' => $attributes,
        );

        return $result;
    }

    /**
     * Get images by product
     * @param $product
     * @return array
     */
    public function get_images_by_product( $product )
    {
        $images = $attachment_ids = array();

        // Check wc version
        if ( Helper::is_wc3() ) {
            $product_id = $product->get_id();
            $gallery_image_ids = $product->get_gallery_image_ids();
        } else {
            $product_id = $product->id;
            $gallery_image_ids = $product->get_gallery_attachment_ids();
        }

        // Add featured image
        if ( has_post_thumbnail( $product_id ) ) {
            $attachment_ids[] = get_post_thumbnail_id( $product_id );
        }

        // Add gallery images
        $attachment_ids = array_merge( $attachment_ids, $gallery_image_ids );

        // Build image data
        foreach ( $attachment_ids as $position => $attachment_id ) {
            $attachment_post = get_post( $attachment_id );
            if ( is_null( $attachment_post ) ) {
                continue;
            }

            $attachment = wp_get_attachment_image_src( $attachment_id, 'large' );
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            // Update image src if use cdn image
            $image_src = current( $attachment );
            if ( preg_match_all( '/http(s)?:\/\//', $image_src ) > 1 ) {
                $image_src = preg_replace('/(http[s]?:\/\/.*)(http[s]?:\/\/)/', '$2', $image_src);
            }
            $images[] = array(
                'id' => (int)$attachment_id,
                'src' => $image_src,
            );
        }

        // Set a placeholder image if the product has no images set
        if ( empty( $images ) ) {
            $images[] = array(
                'id' => '',
                'src' => wc_placeholder_img_src(),
            );
        }

        return $images;
    }

    /**
     * Sync colors
     * @param string $custom_option_name
     * @param array $color_shop_settings
     * @param bool $merge
     * @return array|string
     */
    public function sync_colors( $custom_option_name = '', $color_shop_settings = array(), $merge = true )
    {
        $colors = $this->map_color_default_with_shop_color( $custom_option_name );

        if ( empty( $colors ) ) {
            return $merge && !empty( $color_shop_settings ) ? $color_shop_settings : '';
        } else if( !$merge || empty( $color_shop_settings ) ) {
            return $colors;
        }

        $names = array();

        foreach( $color_shop_settings  as $color_shop_setting ) {
            $names[] = $this->raw_color_name( $color_shop_setting['name'] );
        }

        $sync = array_filter( $colors, function( $color ) use ($names ) {
            return !in_array($this->raw_color_name( $color['name'] ), $names);
        } );

        return $merge ? array_merge( $color_shop_settings, $sync ) : $sync;
    }

    /**
     * Map color default with color shop using
     *
     * @param string $custom_option_name
     * @return array
     */
    public function map_color_default_with_shop_color( $custom_option_name = '' ) {
        $default_colors = $this->get_setting_color_list();
        $shop_colors = $this->get_all_shop_colors( $custom_option_name );

        if ( empty( $shop_colors ) ) {
            return array();
        }

        $colors = array();

        foreach( $shop_colors as $color ) {
            $search = array_filter( $default_colors, function ( $default_colors ) use ( $color ) {
                return $this->raw_color_name( $default_colors['name'] ) == $this->raw_color_name( $color );
            });

            $search = reset( $search );

            if ( !empty( $search ) ) {
                $search['name'] = $color;
                $colors[] = $search;
            } else {
                $default_unknown_color =  $this->format_color_item( $color, '#ffffff' );
                $colors[] = $default_unknown_color;
            }
        }

        return $colors;
    }

    /**
     * Format color item
     *
     * @param $name
     * @param $code
     * @return array
     */
    public function format_color_item( $name, $code ) {
        return array(
            'name' => $name,
            'type' => 1,
            'colors' => array(
                array( 'value' => $code )
            ),
        );
    }

    /**
     * Raw color name
     *
     * @param $color
     * @return string
     */
    private function raw_color_name( $color ) {
        $color = str_replace( ' ', '', $color );
        return trim( strtolower( $color ) );
    }

    /**
     * Get setting color list
     *
     * @return array|mixed
     */
    public function get_setting_color_list() {
        $colors = Helper::get_file_contents( BEEKETINGQUICKVIEW_PLUGIN_DIR . 'core/Data/colors.json' );
        $colors = json_decode( $colors, true );

        return !empty( $colors ) ? $colors : array();
    }

    /**
     * Get all color shop using
     *
     * @param string $custom_option_name
     * @return array
     */
    public function get_all_shop_colors( $custom_option_name = '' )
    {
        global $wpdb;
        $search = self::$DEFAULT_COLOR_KEYS;
        if ( !empty( $custom_option_name ) ) {
            $search[] = $custom_option_name;
        }

        $colors = array();
        $search_string = array_map( array( $this, 'convert_string' ), $search );
        $search_string = implode( ',', $search_string );
        $results = $wpdb->get_results(
            "
            SELECT t.name
            FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy IN (" . $search_string . ")
            "
        );

        foreach ( $results as $result ) {
            $colors[] = $result->name;
        }

        return array_unique( $colors );
    }

    /**
     * Convert query string
     *
     * @param $key
     * @return string
     */
    public function convert_string( $key )
    {
        return '"' . 'pa_' . strtolower( $key ) . '"';
    }

    /**
     * Format color swatches
     *
     * @param $color_swatches
     * @return array
     */
    public function format_color_swatches( $color_swatches )
    {
        if ( empty( $color_swatches ) ) {
            return array();
        }

        $data = array();
        foreach( $color_swatches as $color_swatch ) {
            $colors = array(
                $color_swatch['colors'][0]['value']
            );

            // Has two colors
            if ( !empty( $color_swatch['colors'][1] ) ) {
                $colors[] = $color_swatch['colors'][1]['value'];
            }

            $data[strtolower( $color_swatch['name'] )] = $colors;
        }

        return $data;
    }
}