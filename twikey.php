<?php
/*
  Plugin Name: Twikey
  Plugin URI: https://www.twikey.com
  Description: Twikey Payment Plugin
  Author: Twikey
  Author URI:http://www.twikey.com
  Version: 2.1

  Copyright: 2017 Twikey(email : support@twikey.com)
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
    echo '<div class="error"><p>' . __('Curl is not installed.<br />In order to use the Twikey plug-in, you must install CURL.<br />Ask your system administrator to install php_curl', 'twikey') . '</p></div>';
}

// Curl is niet geinstalleerd. foutmelding weergeven
if (!function_exists('curl_version')) {
    add_action('admin_notices', __('error_curl_not_installed', 'twikey'));
}

define('TWIKEY_DEBUG',false);
define('TWIKEY_HTTP_DEBUG',false);

add_action('plugins_loaded', 'init_twikey');
function init_twikey(){
    if (is_plugin_active('woocommerce/woocommerce.php') || is_plugin_active_for_network('woocommerce/woocommerce.php')) {
        TwikeyLoader::register();
    } else {
        // Woocommerce is niet actief. foutmelding weergeven
        add_action('admin_notices', "WooCommerce is not yet active");
    }
}

register_activation_hook(__FILE__, 'register');
register_deactivation_hook( __FILE__, 'deregister' );

function register(){
    TwikeyLoader::log("Scheduling tasks");
    if ( ! wp_next_scheduled( 'twikey_scheduled_verifyPayments' ) ){
        wp_schedule_event(time(),'twicedaily','twikey_scheduled_verifyPayments');
        TwikeyLoader::log("Registered Twikey scheduled tasks");
    }
    // ensure deactivation can be called
    register_deactivation_hook( __FILE__, array( __CLASS__, 'unschedule_twikey' ) );
}

function deregister(){
    wp_clear_scheduled_hook('twikey_scheduled_verifyPayments');
    TwikeyLoader::log("Unregistering Twikey scheduled tasks");
}

add_action('twikey_scheduled_verifyPayments', 'twikey_scheduled_verifyPayments');
function twikey_scheduled_verifyPayments(){
    // Start the gateways
    WC()->payment_gateways();
    do_action('verify_payments');
}
