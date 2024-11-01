<?php
/**
 * Plugin helper
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace Beeketing\QuickView\Helpers;


class Helper
{
    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public static function is_woocommerce_active()
    {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            if ( ! is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get woocommerce plugin url
     *
     * @return string
     */
    public static function get_woocommerce_plugin_url()
    {
        return get_site_url() . '/wp-admin/plugin-install.php?tab=plugin-information&plugin=woocommerce';
    }

    /**
     * Is wc 3 version
     *
     * @return mixed
     */
    public static function is_wc3()
    {
        return version_compare( WOOCOMMERCE_VERSION, '3.0', '>=' );
    }

    /**
     * Is hidden name
     *
     * @param $name
     * @return bool
     */
    public static function is_hidden_name( $name )
    {
        if ((bool) preg_match('/\(BK (\d+)\)/', $name, $matches)) {
            return true;
        }

        return false;
    }

    /**
     * Get currency format
     *
     * @return string|null
     */
    public static function get_currency_format()
    {
        if ( self::is_woocommerce_active() ) {
            $currency_format = wc_price( 11.11 );
            $currency_format = html_entity_decode( $currency_format );
            $currency_format = preg_replace( '/[1]+[.,]{0,1}[1]+/', '{{amount}}', $currency_format, 1 );

            return $currency_format;
        }

        return null;
    }

    /**
     * Get local file contents
     *
     * @param $file_path
     * @return string
     */
    public static function get_file_contents( $file_path ) {
        $contents = @file_get_contents( $file_path );
        if ( !$contents ) {
            ob_start();
            @include_once( $file_path );
            $contents = ob_get_clean();
        }

        return $contents;
    }

    /**
     * Get shop domain
     *
     * @return string
     */
    public static function get_shop_domain()
    {
        $site_url = get_site_url();
        $url_parsed = parse_url( $site_url );
        $host = isset( $url_parsed['host'] ) ? $url_parsed['host'] : '';

        return $host;
    }

    /**
     * Get env
     *
     * @return string
     */
    public static function get_env()
    {
        // Get environment
        $env = self::get_file_contents( BEEKETINGQUICKVIEW_PLUGIN_DIR . 'env' );
        return trim( $env );
    }

    /**
     * Recursive sanitize
     *
     * @param $data
     * @return mixed
     */
    public static function recursive_sanitize( $data )
    {
        if ( is_array( $data ) ) {
            foreach ( $data as &$value ) {
                if ( is_array( $value ) ) {
                    $value = self::recursive_sanitize( $value );
                } else {
                    $value = sanitize_text_field( $value );
                }
            }

            return $data;
        }

        return $data = sanitize_text_field( $data );
    }
}