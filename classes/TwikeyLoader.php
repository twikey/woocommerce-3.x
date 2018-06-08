<?php

class TwikeyLoader {
    
    private static $log;
    
    public static function register(){
        require_once dirname(__FILE__) . '/Twikey.php';
        require_once dirname(__FILE__) . '/TwikeyGateway.php';

        add_filter( 'woocommerce_payment_gateways'  , array(__CLASS__, 'addTwikeyGateway'));
    }

    public static function addTwikeyGateway($methods){
        $methods[] = 'TwikeyGateway';
        return $methods;
    }

    public static function log( $message , $level = WC_Log_Levels::NOTICE )  {
        if ( empty( self::$log ) ) {
            self::$log = new WC_Logger();
        }
        self::$log->add( 'Twikey', $message ,$level);
    }

}