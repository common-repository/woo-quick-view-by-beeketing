<?php
/**
 * Plugin Name: WooCommerce Quick View by Beeketing
 * Description: Show Quick View popup when customers click product images on home and collection pages. Show size guide, color swatch and review sections to increase add-to-cart rate. Check out <a href="https://wordpress.org/plugins/beeketing-for-woocommerce/">Beeketing for WooCommerce plugin</a> for more advanced marketing & sales boosting features.
 * Version: 1.0.4
 * Author: Beeketing
 * Author URI: https://beeketing.com
 */

use Beeketing\QuickView\Api\App;
use Beeketing\QuickView\Data\Constant;
use Beeketing\QuickView\Data\Event;
use Beeketing\QuickView\Data\Setting;
use Beeketing\QuickView\Helpers\Helper;
use Beeketing\QuickView\Helpers\SettingHelper;
use Beeketing\QuickView\PageManager\AdminPage;


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Define plugin constants
define( 'BEEKETINGQUICKVIEW_VERSION', '1.0.4' );
define( 'BEEKETINGQUICKVIEW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEEKETINGQUICKVIEW_PLUGIN_DIRNAME', __FILE__ );

// Require plugin autoload
require_once( BEEKETINGQUICKVIEW_PLUGIN_DIR . 'vendor/autoload.php' );

if ( ! class_exists( 'BeeketingQuickView' ) ):

    class BeeketingQuickView {
        /**
         * @var AdminPage $admin_page;
         *
         * @since 1.0.0
         */
        private $admin_page;

        /**
         * @var App $app
         *
         * @since 1.0.0
         */
        private $app;

        /**
         * @var SettingHelper
         *
         * @since 1.0.0
         */
        private $setting_helper;

        /**
         * The single instance of the class
         *
         * @since 1.0.0
         */
        private static $_instance = null;

        /**
         * Get instance
         *
         * @return BeeketingQuickView
         * @since 1.0.0
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        /**
         * Constructor
         *
         * @since 1.0.0
         */
        public function __construct()
        {
            // Init api app
            $this->app = new App();
            $this->setting_helper = new SettingHelper();

            // Plugin hooks
            $this->hooks();
        }

        /**
         * Hooks
         *
         * @since 1.0.0
         */
        private function hooks()
        {
            // Initialize plugin parts
            add_action( 'plugins_loaded', array( $this, 'init' ) );

            // Plugin updates
            add_action( 'admin_init', array( $this, 'admin_init' ) );

            if ( is_admin() ) {
                // Plugin activation
                add_action( 'activated_plugin', array( $this, 'plugin_activation') );
            }
        }

        /**
         * Init
         *
         * @since 1.0.0
         */
        public function init()
        {
            if ( is_admin() ) {
                $this->admin_page = new AdminPage();
            } else {
                // Check app status
                $active = $this->setting_helper->get_settings( Setting::ACTIVE );
                if ( $active ) {
                    add_action('wp_enqueue_scripts', array($this, 'app_register_style'));
                    add_action('wp_enqueue_scripts', array($this, 'app_register_script'));
                }
            }
        }

        /**
         * Admin init
         *
         * @since 1.0.0
         */
        public function admin_init()
        {
            // Listen ajax
            $this->ajax();

            // Add the plugin page Settings and Docs links
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_links' ) );

            // Register plugin deactivation hook
            register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivation' ) );

            // Enqueue scripts
            add_action( 'admin_enqueue_scripts', array( $this, 'register_script' ) );

            // Enqueue styles
            add_action( 'admin_enqueue_scripts', array( $this, 'register_style' ) );
        }

        /**
         * Plugin deactivation
         */
        public function plugin_deactivation()
        {
            // Send tracking
            $this->app->send_tracking_event( Event::PLUGIN_DEACTIVATION );
        }

        /**
         * App register script
         *
         * @since 1.0.0
         */
        public function app_register_script()
        {
            // Enqueue script
            $app_name = Helper::get_env() == 'local' ? 'app' : 'app.min';
            wp_register_script( 'quick_view_app_script', plugins_url( 'dist/js/' . $app_name . '.js', __FILE__ ) , array(), true, false );
            wp_enqueue_script( 'quick_view_app_script' );

            global $woocommerce;

            $setting = $this->setting_helper->get_settings();
            $data = array(
                'currency_format' => Helper::get_currency_format(),
                'cart_url' => Helper::is_wc3() ? wc_get_cart_url() : $woocommerce->cart->get_cart_url(),
                'checkout_url' => Helper::is_wc3() ? wc_get_checkout_url() : $woocommerce->cart->get_checkout_url(),
                'setting' => $setting,
            );

            // Setting color swatches
            if ( isset( $setting[Setting::COLOR_SWATCHES_OPTIONS] ) ) {
                $data['color_swatches'] = $this->app->format_color_swatches( $setting[Setting::COLOR_SWATCHES_OPTIONS] );
                $options = App::$DEFAULT_COLOR_KEYS;

                if ( !empty( $settings[Setting::COLOR_SWATCHES_TITLE] ) ) {
                    $options[] = $settings[Setting::COLOR_SWATCHES_TITLE];
                }
                $options = array_map( 'strtolower', $options );
                $data['color_swatches_options'] = json_encode( $options );
            }

            wp_localize_script( 'quick_view_app_script', 'bqv_app_vars', $data );
        }

        /**
         * App register style
         *
         * @since 1.0.0
         */
        public function app_register_style()
        {
            $app_name = Helper::get_env() == 'local' ? 'app' : 'app.min';
            wp_register_style( 'quick_view_app_style', plugins_url( 'dist/css/' . $app_name . '.css', __FILE__ ), array(), true, 'all' );
            wp_enqueue_style( 'quick_view_app_style' );
        }

        /**
         * Enqueue and localize js
         *
         * @since 1.0.0
         * @param $hook
         */
        public function register_script( $hook )
        {
            if ($hook != 'toplevel_page_' . Constant::PLUGIN_ADMIN_URL) {
                return;
            }

            // Enqueue script
            $app_name = Helper::get_env() == 'local' ? 'app' : 'app.min';
            wp_register_script( 'beeketing_app_script', plugins_url( 'dist/js/backend/' . $app_name . '.js', __FILE__ ) , array(), true, false );
            wp_enqueue_script( 'beeketing_app_script' );

            $current_user = wp_get_current_user();
            $data = array(
                'is_woocommerce_active' => Helper::is_woocommerce_active(),
                'woocommerce_plugin_url' => Helper::get_woocommerce_plugin_url(),
                'user_display_name' => $current_user->display_name,
                'plugin_url' => plugins_url( '/', __FILE__ ),
                'setting' => $this->setting_helper->get_settings(),
            );
            wp_localize_script( 'beeketing_app_script', 'beeketing_app_vars', $data );
        }

        /**
         * Enqueue style
         *
         * @since 1.0.0
         * @param $hook
         */
        public function register_style( $hook )
        {
            // Load only on plugin page
            if ( $hook != 'toplevel_page_' . Constant::PLUGIN_ADMIN_URL ) {
                return;
            }

            $app_name = Helper::get_env() == 'local' ? 'app_backend' : 'app_backend.min';
            wp_register_style( 'beeketing_app_style', plugins_url( 'dist/css/' . $app_name . '.css', __FILE__ ), array(), true, 'all' );
            wp_enqueue_style( 'beeketing_app_style' );
        }

        /**
         * Ajax
         *
         * @since 1.0.0
         */
        public function ajax()
        {
            add_action( 'wp_ajax_bqv_product_detail', array( $this, 'get_product_detail' ) );
            add_action( 'wp_ajax_nopriv_bqv_product_detail', array( $this, 'get_product_detail' ) );

            add_action( 'wp_ajax_bqv_add_product', array( $this, 'add_product' ) );
            add_action( 'wp_ajax_nopriv_bqv_add_product', array( $this, 'add_product' ) );

            add_action( 'wp_ajax_bqv_save_setting', array( $this, 'save_setting' ) );
            add_action( 'wp_ajax_bqv_sync_colors', array( $this, 'sync_colors' ) );
        }

        /**
         * Sync colors
         *
         * @since 1.0.0
         */
        public function sync_colors()
        {
            global $wpdb;
            $custom_option = $this->setting_helper->get_settings( Setting::COLOR_SWATCHES_TITLE );
            $color_swatches_options = $this->setting_helper->get_settings( Setting::COLOR_SWATCHES_OPTIONS );
            $colors = $this->app->sync_colors( $custom_option, $color_swatches_options );
            $this->setting_helper->update_settings( Setting::COLOR_SWATCHES_OPTIONS, $colors );
            $message = null;
            if ( !$colors ) {
                $message = 'No variant found!';
                if ( $custom_option ) {
                    $taxonomyColorResult = $wpdb->get_var(
                        "
                        SELECT *
                        FROM $wpdb->term_taxonomy
                        WHERE taxonomy = '" . $custom_option . "'
                        "
                    );

                    if ( !$taxonomyColorResult ) {
                        $message = 'Invalid variant name!';
                    }
                }
            }

            wp_send_json_success( array(
                'setting' => $this->setting_helper->get_settings(),
                'message' => $message,
            ) );
            wp_die();
        }

        /**
         * Save setting
         *
         * @since 1.0.0
         */
        public function save_setting()
        {
            if ( !isset( $_POST['setting'] ) || !is_array( $_POST['setting'] ) ) {
                wp_send_json_error();
                wp_die();
            }

            $settings = $_POST['setting'];
            foreach ( $settings as $key => $setting ) {
                $booleanFields = array(
                    Setting::ACTIVE,
                    Setting::SIZE_GUIDE,
                    Setting::COLOR_SWATCHES,
                    Setting::REVIEW,
                );

                if ( in_array( $key, $booleanFields ) ) { // Validate boolean field
                    $setting = filter_var($setting, FILTER_VALIDATE_BOOLEAN);
                } else { // Validate text field
                    $setting = Helper::recursive_sanitize( $setting );
                }

                $this->setting_helper->update_settings( $key, $setting );
            }
            wp_send_json_success( $this->setting_helper->get_settings() );
            wp_die();
        }

        /**
         * Get product detail
         *
         * @since 1.0.0
         */
        public function get_product_detail()
        {
            $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
            if ( !$id ) {
                wp_send_json_error();
                wp_die();
            }
            $data = $this->app->get_product_detail( $id );
            wp_send_json_success( $data );
            wp_die();
        }

        /**
         * Add product to cart
         *
         * @since 1.0.0
         */
        public function add_product()
        {
            $product_id = isset( $_POST['product_id'] ) && $_POST['product_id'] ? absint ( $_POST['product_id'] ) : 0;
            $variant_id = isset( $_POST['variant_id'] ) && $_POST['variant_id'] ? absint( $_POST['variant_id'] ) : 0;
            $quantity = isset( $_POST['quantity'] ) && $_POST['quantity'] ? absint( $_POST['quantity'] ) : 1;
            $attributes = isset( $_POST['attributes'] ) && $_POST['attributes'] ? Helper::recursive_sanitize( $_POST['attributes'] ) : array();
            $result = $this->app->add_cart( $product_id, $variant_id, $quantity, $attributes );
            if ( $result ) {
                wp_send_json_success( $result );
            } else {
                wp_send_json_error();
            }
            wp_die();
        }

        /**
         * Plugin links
         *
         * @param $links
         * @return array
         * @since 1.0.0
         */
        public function plugin_links( $links )
        {
            $more_links = array();
            $more_links['settings'] = '<a href="' . admin_url( 'admin.php?page=' . Constant::PLUGIN_ADMIN_URL ) . '">' . __( 'Settings', 'beeketing' ) . '</a>';

            return array_merge( $more_links, $links );
        }

        /**
         * Plugin activation
         *
         * @param $plugin
         * @since 1.0.0
         */
        public function plugin_activation( $plugin )
        {
            if ( $plugin == plugin_basename( __FILE__ ) ) {
                $this->app->send_tracking_event( Event::PLUGIN_ACTIVATION );
                if ( !$this->setting_helper->get_settings() ) {
                    $this->setting_helper->update_settings( Setting::ACTIVE, true );
                }

                exit( wp_redirect( admin_url( 'admin.php?page=' . Constant::PLUGIN_ADMIN_URL ) ) );
            }
        }

        /**
         * Plugin uninstall
         *
         * @since 1.0.0
         */
        public function plugin_uninstall()
        {
            // Send tracking
            $this->app->send_tracking_event( Event::PLUGIN_UNINSTALL );

            delete_option( Constant::APP_SETTING );
        }
    }

    // Run plugin
    new BeeketingQuickView();

endif;