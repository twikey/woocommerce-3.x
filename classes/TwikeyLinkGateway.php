<?php

class TwikeyLinkGateway extends WC_Payment_Gateway
{

    public function __construct() {
        $this->id                   = 'twikey-gateway';
        $this->has_fields           = true;
        $this->method_title         = __('Twikey Payment Gateway', 'twikey');
        $this->title                = __('Twikey Payment Gateway', 'twikey');
        $this->method_description   = __('Activate this module to use Twikey', 'twikey');
        $this->description          = __('Pay via card', 'twikey');
        $this->icon                 = '//www.twikey.com/img/butterfly.svg';

        $this->supports = array('products');


        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // allow exit url to work
        add_action( 'woocommerce_api_twikey_exit', array( $this, 'exit_handler' ) );
        add_action( 'woocommerce_api_twikey_webhook', array( $this, 'hook_handler' ) );

        // allow verify of payment
        add_action( 'woocommerce_order_action_order_verify_twikey', array( $this, 'verify_order_action' ) );

        $this->init_settings();
    }

    public function verify_order_action($orderId ) {
        try{
            $order    = wc_get_order( $orderId );

            $tc  = $this->getTwikey();
            $status = $tc->verifyLink(null,$orderId);

            $entry = $status->Links[0];
            $this->updateOrder($order, $entry);
        }
        catch (TwikeyException $e){
            wp_die("Error verifying with Twikey: ".$e->getMessage());
        }
    }

    function updateOrder(WC_Order $order, $entry){
        $orderState = $entry->state;
        if($orderState == 'paid'){
            TwikeyLoader::log("Set payment date of order #".$entry->bkdate,WC_Log_Levels::INFO);
            $order->add_order_note('Payment received via Twikey on '.$entry->bkdate);
            $order->payment_complete($entry->id);
        }
        else if($orderState == 'declined' || $orderState == 'expired'){
            TwikeyLoader::log("Order was in error : ".$order->get_id());
            $order->add_order_note('[Twikey] Link was : '.$orderState );
            $order->set_date_paid(null);
            $order->set_status('failed');
            $order->save();
        }
        else {
            TwikeyLoader::log("Order# ".$order->get_id()." : ".$orderState);
        }
    }

    public function exit_handler(){

        $settings = $this->getSettings();
        $website_key = $settings['testmode'];

        if(!$website_key){
            TwikeyLoader::log("No website_key set to validate the exit", WC_Log_Levels::ERROR);
            exit();
        }

        $mandateNumber = $_GET['mndt'];
        $status = $_GET['status'];
        $signature = strtolower($_GET['s']); // signature coming from twikey is hex uppercase
        $token = $_GET['t'];
        TwikeyLoader::log("Called webhook ". $mandateNumber, WC_Log_Levels::INFO);

        $order_id = wc_clean($mandateNumber);
        $order    = wc_get_order( $token );

        if($order){
            try{
                Twikey::validateSignature($website_key,$mandateNumber,$status,$token,$signature);
                $order->update_status( 'on-hold', 'Awaiting payment confirmation from bank' );
                // Remove cart.
                WC()->cart->empty_cart();

                // Return thank you page redirect.
                $return_url = $this->get_return_url($order);
                TwikeyLoader::log("Success: Return to thank you page ". $return_url, WC_Log_Levels::DEBUG);
                wp_safe_redirect($return_url);
            }
            catch (Exception $e){
                $checkout_payment_url = $order->get_checkout_payment_url(true);
                TwikeyLoader::log("Abort: Back to checkout page ". $checkout_payment_url, WC_Log_Levels::INFO);
                wp_safe_redirect($checkout_payment_url);
            }
        }
        else {
            TwikeyLoader::log("Abort no order ". $order_id, WC_Log_Levels::INFO);
            status_header( 400 );
        }
        exit;
    }

    public function hook_handler(){
        if(isset($_GET['type'])){
            $type = $_GET['type'];
            $id = $_GET['id'];
            $ref = $_GET['ref'];
            TwikeyLoader::log("Got callback from Twikey paymentlink=". $id, WC_Log_Levels::DEBUG);
            if($type === 'payment' && $id){
                $this->verify_order_action ($ref);
            }
            status_header(200, $type);
        }
        else {
            status_header(200, "All ok");
        }
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
            )
        );

        parent::init_settings();
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

        $tc = $this->getTwikey($lang);
        $description = "Order ".$order->get_order_number();
        $ref = $order->get_order_number();
        $amount = round($order->get_total());

        $exiturl = add_query_arg( 'wc-api', 'twikey_link_exit', trailingslashit( get_home_url() ) );

        $linkData = [
            "name" => $order->get_billing_first_name() . " " . $order->get_billing_last_name(),
            "amount" => $amount,
            "message" => $description,
            "ref" => $ref,
            "redirectUrl" => $exiturl
        ];
        try {
            $paymentlink = $tc->newLink($linkData);
            if($paymentlink->url){
                $order->update_status( 'on-hold', 'Awaiting payment confirmation from bank' );

                $order->set_transaction_id($paymentlink->id);
                $order->save();

                // Reduce stock levels
                wc_reduce_stock_levels($order_id);

                // Remove cart
                $woocommerce->cart->empty_cart();

                return array('result' => 'success','redirect' => $paymentlink->url);
            }
            else {
                TwikeyLoader::log("Error adding transaction : ".json_encode($linkData), WC_Log_Levels::ERROR);
                wc_add_notice($paymentlink->message, 'error');
                return array('result' => 'error','redirect' => wc_get_cart_url());
            }

        } catch (Exception $e) {
            $msg = htmlspecialchars($e->getMessage());
            TwikeyLoader::log($msg,WC_Log_Levels::ERROR);
            wc_add_notice($msg, 'error');
            return array('result' => 'error','redirect'  => wc_get_cart_url() );
        }
    }

    private function getSettings() {
        return (array)get_option($this->get_option_key());
    }

    private function getTwikey($lang = 'en') {
        TwikeyLoader::log("New Twikey instance",WC_Log_Levels::INFO);
        $tc = new TwikeyLinkWoo();
        $tc->setTestmode('yes' === $this->get_option( 'testmode', 'no' ));
        $tc->setApiToken($this->get_option( 'apikey' ));
        $tc->setLang($lang);

        return $tc;
    }
}

class TwikeyLinkWoo extends Twikey {
    /**
     * @throws TwikeyException
     */
    public function checkResponse($curlHandle, $server_output, $context = "No context") {
        if (!curl_errno($curlHandle)) {

            TwikeyLoader::log(sprintf("%s : Error = %s (%s)", $context, curl_error($curlHandle),$this->endpoint),WC_Log_Levels::ERROR);
            if ($http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) >= 400) {
                TwikeyLoader::log(sprintf("%s : Error = %s (%s)", $context, $server_output,$this->endpoint),WC_Log_Levels::ERROR);
                throw new TwikeyException($context);
            }
        }
        if (TWIKEY_DEBUG) {
            TwikeyLoader::log(sprintf("Response %s : %s (%s)", $context, $server_output,$this->endpoint),WC_Log_Levels::INFO);
        }
    }

    public function debugRequest($payload){
        if (TWIKEY_DEBUG) {
            TwikeyLoader::log(sprintf("Request %s ", $payload), WC_Log_Levels::DEBUG);
        }
    }
}