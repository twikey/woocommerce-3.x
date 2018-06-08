<?php

class TwikeyGateway extends WC_Payment_Gateway
{
    const TWIKEY_MNDT_ID = 'TwikeyMandate';

    public function __construct()
    {
        $this->id                   = 'twikey';
        $this->has_fields           = true;
        $this->method_title         = __('Twikey', 'twikey');
        $this->title                = __('Twikey', 'twikey');
        $this->method_description   = __('Activate this module to use Twikey', 'twikey');
        $this->description          = __('Pay via Direct debit', 'twikey');
        $this->icon                 = '//www.twikey.com/img/butterfly.svg';

        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_payment_method_change',
        );

        add_action( 'verify_payments', array($this, 'verify_payments') );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
//        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        
        if ( class_exists( 'WC_Subscriptions_Order' ) ) {
            add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
            
            add_action( 'subscriptions_activated_for_order', array( $this, 'scheduled_subscription_activate' ), 10, 2 );
            
            add_action( 'woocommerce_subscription_status_updated', array( $this, 'scheduled_subscription_status_updated' ), 10, 2 );
            add_action( 'woocommerce_subscription_cancelled_' . $this->id, array( $this, 'cancel_subscription' ) );
        }
        
        $this->init_settings();
    }

    public function getEndpoint($test){
        if($test == 'yes'){
            return "https://api.beta.twikey.com";
        }
        return "https://api.twikey.com";
    }
    
    public function init_settings($form_fields = array()) {
        
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Twikey', 'woocommerce' ),
                'default' => 'yes',
            ),
            'testmode' => array(
                'title'       => __( 'Twikey sandbox', 'twikey' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Twikey sandbox', 'twikey' ),
                'default'     => 'no',
                'description' => __( 'Twikey sandbox can be used to test. <a href="https://www.beta.twikey.com">Sign up</a>.', 'twikey' ),
            ),
            'apikey' => array(
                'title'       => __( 'API key', 'twikey' ),
                'type'        => 'text',
                'description' => __( 'Get your API credentials from Twikey.', 'twikey' ),
                'default'     => '',
            ),
            'ct' => array(
                'title'       => __( 'Template ID', 'twikey' ),
                'type'        => 'number',
                'description' => __( 'Twikey Template ID', 'twikey' ),
                'default'     => '',
            )
        );

        parent::init_settings();
    }

    public function get_method_title() {
        echo "<img src=\"https://www.twikey.com/img/logo.png\" style='margin: 10px'/>";
    }
    
    public function get_method_description() {
        echo file_get_contents(dirname(__FILE__) . '/../include/description.html');
    }

    public function get_icon() {
        echo '<img src="//www.twikey.com/img/butterfly.svg" alt="Twikey" width="65"/>';
    }
    
    public function payment_fields() {        
        $description = '';
        $description_text = $this->get_option('description');
        if (!empty($description_text))
            $description .= '<p>'.$description_text.'</p>';

        echo $description;
    }
    
    function getSettings() {
        return (array)get_option($this->get_option_key());
    }
    
    public function verify_payments(){
        TwikeyLoader::log("Checking payments");
        $settings = $this->getSettings();
        $tc   = new TwikeyWoo();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        
        $feed = $tc->getTransactionFeed();
        foreach ( $feed->Entries as $entry ){
            $order = wc_get_order( $entry->ref );
            if($order){
                TwikeyLoader::log("Update payment status of order #".$entry->ref);
                if($entry->state == 'PAID'){
                    TwikeyLoader::log("Set payment date of order #".$entry->bkdate,WC_Log_Levels::INFO);
                    $order->add_order_note('Payment received via Twikey on '.$entry->bkdate);
                    $order->set_date_paid($entry->bkdate);
                    $order->payment_complete();
                }
                else if($entry->state != 'OPEN'){
                    $order->update_status('failed','Payment failed via Twikey : '.$entry->bkmsg);
                }
            }
            else {
                TwikeyLoader::log("No order found for #".$entry->ref,WC_Log_Levels::WARNING);
            }
        }
    }
    
    private function getUserLang(){
        $default_lang='en';
        $langs=array('en', 'nl', 'fr', 'de', 'pt', 'it', 'es');
        $client_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        return in_array($client_lang, $langs) ? $client_lang : $default_lang;
    }
    
    public function process_payment($order_id){
        
        global $woocommerce;
        $order = new WC_Order($order_id);
        $lang = $this->getUserLang();
        $settings = $this->getSettings();
        
        $tc   = new TwikeyWoo();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        $tc->setTemplateId($settings['ct']);
        $tc->setLang($lang);

        $my_order = array(
            "ct"                    => $settings['ct'],
            "check"                 => true,
            "order_id"              => $order->get_order_number(),
            "amount"                => round($order->get_total()),
            'id'                    => 0,
            'email'                 => $order->get_billing_email(),
            'firstname'             => $order->get_billing_first_name(),
            'lastname'              => $order->get_billing_last_name(),
            'company'               => $order->get_billing_company(),
            'address'               => $order->get_billing_address_1(),
            'city'                  => $order->get_billing_city(),
            'zip'                   => $order->get_billing_postcode(),
            'country'               => $order->get_billing_country(),
            'mobile'                => $order->get_billing_phone(),
            'l'                     => $lang
        );
        
        try {
            $msg = null;
            $tr = $tc->createNew($my_order);
            
            if(property_exists($tr,'url')){
                $url = $tr->url;
                if(property_exists($tr,'mndtId')){
                    $mndtId = $tr->mndtId;
                    $order->update_meta_data(self::TWIKEY_MNDT_ID, $mndtId);
                    $order->save();
                }
                return array(   'result'    => 'success','redirect'  => $url);
            }
            else if(property_exists($tr,'mndtId')){
                 $ip = $_SERVER["REMOTE_ADDR"];
                 if ( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
                     $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
                 }
                     
                $mndtId = $tr->mndtId;
                 $tx = $tc->newTransaction(array(
                     "mndtId"        => $mndtId,
                     "message"       => "Order ".$order->get_order_number(),
                     "ref"           => $order->get_order_number(),
                     "amount"        => $order->get_total(),
                     "place"         => $ip
                 ));
    
                 if(property_exists($tx,'Entries')){
                     $txentry = $tx->Entries[0];
                 
                     $order->update_meta_data(self::TWIKEY_MNDT_ID, $mndtId);
                     $order->set_transaction_id($txentry->id);
                     $order->save();
                     
                     // Reduce stock levels
                     wc_reduce_stock_levels($order_id);
    
                     // Remove cart
                     $woocommerce->cart->empty_cart();
    
                     // Return thankyou redirect
                     return array(
                         'result' => 'success',
                         'redirect' => $this->get_return_url( $order )
                     );
                 }
                 else {
                     TwikeyLoader::log("Error adding transaction : ".json_encode($tx), WC_Log_Levels::ERROR);
                     wc_add_notice($tx->message, 'error');
                     return array('result' => 'error','redirect' => wc_get_cart_url());
                 }
            }
            else {
                TwikeyLoader::log("Invalid response from Twikey: ".print_r($tr, true), WC_Log_Levels::ERROR);
                throw new Exception("Invalid response from Twikey");
            }
            
        } catch (Exception $e) {
            $msg = htmlspecialchars($e->getMessage());
            TwikeyLoader::log($msg,WC_Log_Levels::ERROR);
            wc_add_notice($msg, 'error');
            return array('result' => 'error','redirect'  => wc_get_cart_url() );
        }
    }
    
    public function scheduled_subscription_payment($amount_to_charge, $new_order ) {


        $mndtId = $new_order->get_meta(self::TWIKEY_MNDT_ID);
        $msg  = sprintf( '%1$s - Order %2$s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $new_order->get_id() );
        TwikeyLoader::log("Schedule new tx: ".$msg.' for '.$mndtId, WC_Log_Levels::INFO);

        $settings = $this->getSettings();

        $tc   = new TwikeyWoo();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        $tc->setTemplateId($settings['ct']);

        $my_order = array(
            "mndtId"                 => $mndtId,
            "message"                => $msg,
            "ref"                    => $new_order->get_id(),
            "amount"                 => round($amount_to_charge),
            "place"                  => 'Renewal'
        );

        $tx = $tc->newTransaction($my_order);
        TwikeyLoader::log("TX Response from Twikey: ".print_r($tx, true), WC_Log_Levels::INFO);

        if(property_exists($tx,'Entries')){
            $txentry = $tx->Entries[0];

            $new_order->set_transaction_id($txentry->id);
            $new_order->save();

            // Reduce stock levels
            wc_reduce_stock_levels($new_order);
            WC_Subscriptions_Manager::process_subscription_payments_on_order( $new_order );
        }
        else {
            TwikeyLoader::log("Error adding transaction : ".json_encode($tx), WC_Log_Levels::ERROR);
            wc_add_notice($tx->message, 'error');
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $new_order );
        }    
    }
    
    public function scheduled_subscription_activate($order_id){
        $order = new WC_Order($order_id);
        TwikeyLoader::log("Activating order ".$order_id, WC_Log_Levels::INFO);
        $mndtId = $order->get_meta(self::TWIKEY_MNDT_ID);
        
        $settings = $this->getSettings();

        TwikeyLoader::log("Activating mndtId=".$mndtId, WC_Log_Levels::INFO);
        $tc = new TwikeyWoo();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        $tc->updateMandate(array(
                'mndtId' => $mndtId,
                '_state' => 'active'
            )
        );
        TwikeyLoader::log("Activated ".$order_id, WC_Log_Levels::INFO);

        // Also store it on the subscriptions being purchased in the order
        foreach ( wcs_get_subscriptions_for_order( $order_id ) as $subscription ) {
            $subscription->set_payment_method($this->id,array(
                "post_meta" => array(
                    self::TWIKEY_MNDT_ID => array(
                        'value' => $mndtId,
                        'label' => 'Twikey Mandate ID'
                    )
                )
            ));
            TwikeyLoader::log("Updated subscription with ".$mndtId, WC_Log_Levels::INFO);
            $subscription->save();
        }
    }

    public function scheduled_subscription_status_updated($subscription, $new_status){
        
        $mndtId = $subscription->get_meta(self::TWIKEY_MNDT_ID);
        
        if(!$mndtId)
            return;

        $payload = null;
        switch ($new_status){
//            case 'pending': just signed 
            case 'active': {
                $payload = array(
                    'mndtId' => $mndtId,
                    '_state' => 'active'
                );
                break;
            }
            //case 'on-hold': every time after payment 
            case 'pending-cancel': {
                $payload = array(
                    'mndtId' => $mndtId,
                    '_state' => 'passive'
                );
                break;
            }
            case 'cancelled': 
            case 'expired': {
                // explicit via cancel
                break;
            }
        }

        $tc = new TwikeyWoo();
        $settings = $this->getSettings();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        $tc->updateMandate($payload);
        
        TwikeyLoader::log("Sending status $new_status for $mndtId", WC_Log_Levels::INFO);
    }

    public function cancel_subscription( $cancel_subscription ){
        $mndtId = $cancel_subscription->get_meta(self::TWIKEY_MNDT_ID);
        TwikeyLoader::log("Cancelling ".$mndtId, WC_Log_Levels::INFO);

        $tc = new TwikeyWoo();
        $settings = $this->getSettings();
        $tc->setEndpoint($this->getEndpoint($settings['testmode']));
        $tc->setApiToken($settings['apikey']);
        $tc->cancelMandate($mndtId);
    }
    
}

class TwikeyWoo extends Twikey {
    public function checkResponse($curlHandle, $server_output, $context = "No context") {
        if (!curl_errno($curlHandle)) {
            if ($http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) >= 400) {
                TwikeyLoader::log(sprintf("%s : Error = %s", $context, $server_output),WC_Log_Levels::ERROR);
                throw new Exception($context);
            }
        }
        if (TWIKEY_DEBUG) {
            TwikeyLoader::log(sprintf("Response %s : %s", $context, $server_output),WC_Log_Levels::INFO);
        }
    }
    
    public function debugRequest($payload){
        if (TWIKEY_DEBUG) {
            TwikeyLoader::log(sprintf("Request %s ", $payload), WC_Log_Levels::DEBUG);
        }
    }
}