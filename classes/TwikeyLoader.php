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
        if ( is_cart() ||  is_checkout()  ) {

            $isCard = false;
            $selected_gateway = apply_filters( 'twikey_gateway_selection', WC()->cart->get_cart() );
            // Reverse logic, if you chose one, you need to unset the other one
	        if ( empty( $selected_gateway ) ){
                # unset the paylink gateway so the DD is chosen by default
                if (isset($gateways['twikey-paylink'])) {
                    unset($gateways['twikey-paylink']);
                }
	        }
	        else {
                if ($selected_gateway == 'twikey') {
                    if (isset($gateways['twikey-paylink'])) {
                        unset($gateways['twikey-paylink']);
                    }
                } else {
                    if (isset($gateways['twikey'])) {
                        unset($gateways['twikey']);
                    }
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

