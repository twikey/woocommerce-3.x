<?php
/*
  Plugin Name: Twikey
  Plugin URI: https://www.twikey.com
  Description: Twikey Payment Plugin
  Author: Twikey
  Author URI:http://www.twikey.com
  Version: 2.3

  Copyright: 2018 Twikey(email : support@twikey.com)
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

//Autoloader laden en registreren
require_once dirname(__FILE__) . '/classes/TwikeyLoader.php';

//plugin functies inladen
require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

//textdomain inladen
load_plugin_textdomain( 'twikey', false, plugin_basename( dirname( __FILE__ ) ) . "/i18n/languages" );

function error_woocommerce_not_active() {
    echo '<div class="error"><p>' . __('To use the Twikey plugin it is required that woocommerce is active', 'twikey') . '</p></div>';
}

function error_curl_not_installed() {
    echo '<div class="error"><p>' . __('error_curl_not_installed', 'twikey') . '</p></div>';
}

// Curl is niet geinstalleerd. foutmelding weergeven
if (!function_exists('curl_version')) {
    add_action('admin_notices', __('error_curl_not_installed', 'twikey'));
}

define('TWIKEY_DEBUG',false);
define('TWIKEY_HTTP_DEBUG',false);

add_action('plugins_loaded', 'init_twikey');
function init_twikey(){
    if (function_exists( 'is_woocommerce_active' )) {
        TwikeyLoader::register();
    } else {
        // Woocommerce is not active. raise error
        add_action('admin_notices', "WooCommerce is not yet active");
    }
}

register_activation_hook(__FILE__, 'register');
register_deactivation_hook( __FILE__, 'deregister' );

function register(){
    TwikeyLoader::log("Scheduling tasks",WC_Log_Levels::NOTICE);
    if ( ! wp_next_scheduled( 'twikey_scheduled_verifyPayments' ) ){
        wp_schedule_event(time(),'twicedaily','twikey_scheduled_verifyPayments');
        TwikeyLoader::log("Registered Twikey scheduled tasks",WC_Log_Levels::NOTICE);
    }
    // ensure deactivation can be called
    register_deactivation_hook( __FILE__, array( __CLASS__, 'unschedule_twikey' ) );
}

function deregister(){
    wp_clear_scheduled_hook('twikey_scheduled_verifyPayments');
    TwikeyLoader::log("Unregistering Twikey scheduled tasks",WC_Log_Levels::NOTICE);
}

add_action('twikey_scheduled_verifyPayments', 'twikey_scheduled_verifyPayments');
function twikey_scheduled_verifyPayments(){
    // Start the gateways
    WC()->payment_gateways();
    do_action('verify_payments');
}
