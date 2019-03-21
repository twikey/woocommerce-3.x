<?php

class TwikeyLinkGateway extends WC_Payment_Gateway
{
    public function __construct() {
        $this->id                   = 'twikey-gateway';
        $this->has_fields           = false;
        $this->method_title         = __('Twikey Payment Gateway', 'twikey');
        $this->title                = __('Twikey Payment Gateway', 'twikey');
        $this->method_description   = __('Activate this module to use Twikey', 'twikey');
        $this->description          = __('Pay via card', 'twikey');

        $this->supports = array('products');


        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // allow exit url to work
        add_action( 'woocommerce_api_twikey_link_exit', array( $this, 'exit_handler' ) );
        add_action( 'woocommerce_api_twikey_webhook', array( $this, 'hook_handler' ) );

        // allow verify of payment
        add_action( 'woocommerce_order_action_order_verify_twikey', array( $this, 'verify_order_action' ) );

        $this->init_settings();
    }

    public function verify_order_action(WC_Order $order ) {
        try{
            $tc  = $this->getTwikey();
            $status = $tc->verifyLink(null,$order->get_id());

            $entry = $status->Links[0];
            $this->updateOrder($order, $entry);
        }
        catch (TwikeyException $e){
            WC_Admin_Meta_Boxes::add_error( "Error verifying with Twikey:  ".$e->getMessage() );
            TwikeyLoader::log("Error verifying with Twikey:  ".$e->getMessage(),WC_Log_Levels::ERROR);
        }
    }

    function updateOrder(WC_Order $order, $entry){
        $orderState = $entry->state;
        if($orderState == 'paid'){
            TwikeyLoader::log("Set payment date of order #".$entry->bkdate,WC_Log_Levels::INFO);
            $order->add_order_note('Payment received via Twikey');
            $order->payment_complete($entry->id);
        }
        else if($orderState == 'declined' || $orderState == 'expired'){
            TwikeyLoader::log("Order was in error : ".$order->get_id(),WC_Log_Levels::WARNING);
            $order->add_order_note('[Twikey] Link was : '.$orderState );
            $order->set_date_paid(null);
            $order->set_status('failed');
            $order->save();
        }
        else {
            TwikeyLoader::log("Order# ".$order->get_id()." : ".$orderState,WC_Log_Levels::DEBUG);
        }
    }

    public function exit_handler(){

        $combined = $_GET['o'];
        if(!$combined){
            TwikeyLoader::log("Abort no valid order ". $combined, WC_Log_Levels::INFO);
            status_header( 400 );
        }
        $items = explode('-',$combined);
        if(count($items) != 2){
            TwikeyLoader::log("Abort no valid order ". $combined, WC_Log_Levels::INFO);
            status_header( 400 );
        }
        $order_id = wc_clean($items[0]);
        $signature = $items[1]; // signature coming from twikey is hex uppercase
        TwikeyLoader::log("Called exiturl of paymentlink for ". $order_id, WC_Log_Levels::DEBUG);

        $order    = wc_get_order( $order_id );
        if($order){
            try{
                $tc = $this->getTwikey();
                $amount = round($order->get_total(),2);
                $calculated = $this->calculateSig($order_id,$amount, $tc->getApiToken());
                if(!hash_equals($signature,$calculated)){
                    TwikeyLoader::log("Invalid link signature : expected=".$calculated.' was='.$signature,WC_Log_Levels::ERROR);
                    throw new TwikeyException('Invalid signature');
                }
                $order->update_status( 'on-hold', 'Awaiting payment confirmation from bank' );
                // Remove cart.
                WC()->cart->empty_cart();

                // Return thank you page redirect.
                $return_url = $this->get_return_url($order);
                TwikeyLoader::log("Success for link: Return to thank you page ". $return_url, WC_Log_Levels::DEBUG);
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
        if(isset($_GET['type']) && isset($_GET['ref'])){
            $type = $_GET['type'];
            $ref = $_GET['ref'];
            TwikeyLoader::log("Got callback from Twikey paymentlink=". $ref, WC_Log_Levels::DEBUG);
            if($type === 'payment' && $ref){
                $order    = wc_get_order( $ref );
                $this->verify_order_action ($order);
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
                'label'   => __( 'Enable Twikey', 'twikey' ),
                'default' => 'yes',
            ),
            'testmode' => array(
                'title'       => __( 'Twikey sandbox', 'twikey' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Twikey sandbox', 'twikey' ),
                'default'     => 'no',
                'description' => __( 'Twikey sandbox description', 'twikey' ),
            ),
            'apikey' => array(
                'title'       => __( 'API key', 'twikey' ),
                'type'        => 'text',
                'description' => __( 'Get your API credentials from Twikey.', 'twikey' ),
                'default'     => '',
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description for your customers.', 'twikey' ),
                'default'     => '',
            )
        );

        parent::init_settings();
    }

    public function get_icon(){
        return "<img src=\"//www.twikey.com/img/butterfly.svg\" alt=\"Twikey\" style=\"height: 1em;display: inline;\">";
    }

    public function get_description() {
        return $this->get_option('description');
    }

    public function payment_fields() {
        $description = '';
        $description_text = $this->get_option('description');
        if (!empty($description_text)){
            $description .= '<p>'.$description_text.'</p>';
        }
        echo $description;
    }

    private function getUserLang(){
        $default_lang='en';
        $langs=array('en', 'nl', 'fr', 'de', 'pt', 'it', 'es');
        $client_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        return in_array($client_lang, $langs) ? $client_lang : $default_lang;
    }

    private function calculateSig($order_id,$amount,$websiteKey){
        return hash_hmac('sha256', sprintf("%d/%d",$order_id,$amount), $websiteKey);
    }

    public function process_payment($order_id){

        global $woocommerce;

        $order = new WC_Order($order_id);
        $lang = $this->getUserLang();

        $tc = $this->getTwikey($lang);
        $description = "Order ".$order->get_order_number();
        $ref = $order->get_order_number();
        $amount = round($order->get_total());

        $sig = $this->calculateSig($order_id,$amount, $tc->getApiToken());
        $exiturl = add_query_arg(
            array(
                'wc-api' => 'twikey_link_exit',
                'o' => $order_id.'-'.urlencode($sig)
            ),get_home_url() );

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

    private function getTwikey($lang = 'en') {
        $tc = new TwikeyWCWrapper();
        $tc->setTestmode('yes' === $this->get_option( 'testmode', 'no' ));
        $tc->setApiToken($this->get_option( 'apikey' ));
        //$tc->setTemplateId($this->get_option( 'ct' ));
        //$tc->setWebsiteKey($this->get_option( 'websitekey' ));
        $tc->setLang($lang);
        return $tc;
    }
}
