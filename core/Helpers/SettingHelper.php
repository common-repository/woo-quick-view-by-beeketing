<?php
/**
 * Plugin setting helper
 *
 * @since      1.0.0
 * @author     Beeketing
 *
 */

namespace Beeketing\QuickView\Helpers;


use Beeketing\QuickView\Data\Constant;

class SettingHelper
{
    /**
     * @var string
     */
    private $app_setting_key = Constant::APP_SETTING;

    /**
     * @var array
     */
    private static $settings = array();

    /**
     * @var string
     */
    private $plugin_version;

    /**
     * @var SettingHelper
     */
    private static $instance = null;

    /**
     * Set singleton instance
     *
     * @param $instance
     * @return SettingHelper
     */
    public static function set_instance( $instance ) {
        self::$instance = $instance;
        // Return instance of class
        return self::$instance;
    }

    /**
     * Singleton instance
     *
     * @return SettingHelper
     */
    public static function get_instance() {
        // Check to see if an instance has already
        // been created
        if ( is_null( self::$instance ) ) {
            // If not, return a new instance
            self::$instance = new self();
            return self::$instance;
        } else {
            // If so, return the previously created
            // instance
            return self::$instance;
        }
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_plugin_version()
    {
        return $this->plugin_version;
    }

    /**
     * Plugin version
     *
     * @param $plugin_version
     */
    public function set_plugin_version( $plugin_version )
    {
        $this->plugin_version = $plugin_version;
    }

    /**
     * Get settings
     *
     * @param null $key
     * @param null $default
     * @return mixed
     */
    public function get_settings( $key = null, $default = null )
    {
        $settings = isset(self::$settings[$this->app_setting_key]) ? self::$settings[$this->app_setting_key] : array();
        if ( !$settings ) {
            $settings = get_option( $this->app_setting_key );
            $settings = $settings ? unserialize( $settings ) : array();
        }

        // Get setting by key
        if ( $key ) {
            if ( isset( $settings[$key] ) ) {
                return $settings[$key];
            }

            return $default;
        }

        return $settings;
    }

    /**
     * Update settings
     *
     * @param $key
     * @param $value
     * @return array|mixed
     */
    public function update_settings( $key, $value )
    {
        $settings = isset(self::$settings[$this->app_setting_key]) ? self::$settings[$this->app_setting_key] : array();
        if ( !$settings ) {
            $settings = $this->get_settings();
        }

        $settings[$key] = $value;
        self::$settings[$this->app_setting_key] = $settings;
        update_option( $this->app_setting_key, serialize( $settings ) );

        return self::$settings;
    }

    /**
     * Delete settings
     */
    public function delete_settings()
    {
        delete_option( $this->app_setting_key );
    }
}