<?php

class TwikeyLoader {

    private static $log;

    public static function register(){
        require_once dirname(__FILE__) . '/Twikey.php';
        require_once dirname(__FILE__) . '/TwikeyGateway.php';
        require_once dirname(__FILE__) . '/TwikeyLinkGateway.php';

        add_filter('woocommerce_payment_gateways'  , array(__CLASS__, 'addTwikeyGateways'));
        add_filter('woocommerce_order_actions', array( __CLASS__, 'add_verify_order_action' ));
        add_filter('woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ), 1);
    }

    public static function addTwikeyGateways($methods){
        $methods[] = 'TwikeyGateway';
        $methods[] = 'TwikeyLinkGateway';
        return $methods;
    }

    public static function add_verify_order_action( $actions ) {
        if ( is_array( $actions ) ) {
            $actions['order_verify_twikey'] = __('Verify payment with Twikey', 'twikey');
        }
        return $actions;
    }

    public static function filter_gateways( $gateways ) {
        global $theorder;
        /* add your user role in condition and payment method which you need to unset
        $current_user = wp_get_current_user();
        $role = $current_user->roles;
        if ($role[0] == 'administrator') {
            unset($gateways['cod']);
        }*/

        if ( is_cart() ||  is_checkout()  ) {

            $isCard = false;
            // Loop through all products in the Cart
//            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
//                $productId = $cart_item['product_id'];
//                $term_list = wp_get_post_terms($productId, 'product_cat');
////                SELF::log("$term_list = ".print_r($term_list,true),WC_Log_Levels::NOTICE);
//                $cat = $term_list[0] -> slug;
//                if ($cat === 'hoodies') {
//                    $isCard = true;
////                    SELF::log("CARD = $cat -> ".print_r($isCard,true));
//                    break;
//                }
//            }

            if ($isCard) {
                if (isset($gateways['twikey-gateway'])) {
                    unset($gateways['twikey-gateway']);
                }
            } else {
                if (isset($gateways['twikey'])) {
                    unset($gateways['twikey']);
                }
            }
        }
        return $gateways;
    }

    public static function log( $message , $level )  {
        if ( empty( self::$log ) ) {
            self::$log = wc_get_logger();
        }
        if(!$level)
            $level = WC_Log_Levels::NOTICE;
        self::$log->log($level, $message,array ( 'source' => 'Twikey' ) );
    }

    public static function logHttp( $message , $level )  {
        if ( empty( self::$log ) ) {
            self::$log = wc_get_logger();
        }
        if(!$level)
            $level = WC_Log_Levels::NOTICE;
        self::$log->log($level, $message ,array ( 'source' => 'Twikey-Http' ));
    }
}

