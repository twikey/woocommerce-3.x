<?php

/**
 * Class TwikeyWCWrapper
 */
class TwikeyWCWrapper extends Twikey {
    public function log($msg){
        TwikeyLoader::log($msg, WC_Log_Levels::NOTICE);
    }
}

class TwikeyGateway extends WC_Payment_Gateway
{
    const TWIKEY_MNDT_ID = 'TwikeyMandate';

    public function __construct() {
        $this->id                   = 'twikey';
        $this->has_fields           = false;
        $this->method_title         = 'Twikey';
        $this->title                = 'Twikey';
        $this->method_description   = __('Activate this module to use Twikey', 'twikey');
        $this->description          = __('Pay via Direct debit', 'twikey');

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

        // allow exit url to work
        add_action( 'woocommerce_api_twikey_exit', array( $this, 'exit_handler' ) );
        add_action( 'woocommerce_api_twikey_webhook', array( $this, 'hook_handler' ) );

        // allow verify of payment
        add_action( 'woocommerce_order_action_order_verify_twikey', array( $this, 'verify_order_action' ) );

        if ( class_exists( 'WC_Subscriptions_Order' ) ) {
            add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

            add_action( 'subscriptions_activated_for_order', array( $this, 'scheduled_subscription_activate' ), 10, 2 );

            add_action( 'woocommerce_subscription_status_updated', array( $this, 'scheduled_subscription_status_updated' ), 10, 2 );
            add_action( 'woocommerce_subscription_cancelled_' . $this->id, array( $this, 'cancel_subscription' ) );
        }

        $this->init_settings();
    }

    public function verify_order_action(WC_Order $order ) {
        try{
            $tc  = $this->getTwikey();
            $status = $tc->getPaymentStatus(null,$order->id);

            $entry = $status->Entries[0];
            $this->updateOrder($order, $entry);
        }
        catch (TwikeyException $e){
            WC_Admin_Meta_Boxes::add_error( "Error verifying with Twikey:  ".$e->getMessage() );
            TwikeyLoader::log("Error verifying with Twikey:  ".$e->getMessage(),WC_Log_Levels::ERROR);
        }
    }

    function updateOrder(WC_Order $order, $entry){
        $orderState = $entry->state;
        if($orderState == 'PAID'){
            TwikeyLoader::log("Set payment date of order #".$entry->bkdate,WC_Log_Levels::INFO);
            $order->add_order_note('Payment received via Twikey on '.$entry->bkdate);
            $order->payment_complete($entry->id);
        }
        else if($orderState == 'ERROR'){
            TwikeyLoader::log("Order was in error : ".$order->id,WC_Log_Levels::WARNING);
            $order->add_order_note('[Twikey] Message from the bank: '.$entry->bkmsg );
            $order->$order->update_status( 'on-hold', 'failed' );
        }
        else {
            TwikeyLoader::log("Order was pending : ".$order->id,WC_Log_Levels::DEBUG);
        }
    }

    public function exit_handler(){

        $mandateNumber = $_GET['mndt'];
        $status = $_GET['status'];
        $signature = strtolower($_GET['s']); // signature coming from twikey is hex uppercase
        $token = $_GET['t'];
        TwikeyLoader::log("Called webhook ". $mandateNumber, WC_Log_Levels::INFO);

        $order_id = wc_clean($mandateNumber);
        $order    = wc_get_order( $token );

        if($order){
            try{
                $tc = $this->getTwikey();
                $tc->validateSignature($mandateNumber,$status,$token,$signature);
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
        if(isset($_GET['type']) && !isset($_GET['id'])){
            $type = $_GET['type'];
            TwikeyLoader::log("Got callback from Twikey type=". $type, WC_Log_Levels::DEBUG);
            if($type === 'payment'){
                $this->verify_payments();
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
            'websitekey' => array(
                'title'       => __( 'Website key', 'twikey' ),
                'type'        => 'text',
                'description' => __( 'Get your Website key from Twikey to allow validating the exiturl.', 'twikey' ),
                'default'     => '',
            ),
            'ct' => array(
                'title'       => __( 'Template ID', 'twikey' ),
                'type'        => 'number',
                'description' => __( 'Template ID description', 'twikey' ),
                'default'     => '',
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description for your customers.', 'twikey' ),
                'default'     => '',
            ),
            'exiturl' => array(
                'title'       => __( 'Twikey Configuration', 'twikey' ),
                'type'        => 'title',
                /* translators: webhook URL */
                'description' => $this-> conf_desc(),
            ),
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

    /**
     * Called by scheduler or via webhook
     */
    public function verify_payments(){
        TwikeyLoader::log("Checking payments",WC_Log_Levels::INFO);
        try{
            $tc   = $this->getTwikey();
        $feed = $tc->getTransactionFeed();
        foreach ( $feed->Entries as $entry ){
            $order = wc_get_order( $entry->ref );
            if($order){
                    $this->updateOrder($order, $entry);
            }
            else {
                    TwikeyLoader::log("No order found for #".$entry->ref,WC_Log_Levels::WARNING);
                }
            }
        }
        catch (TwikeyException $e){
            TwikeyLoader::log("Error while verifying payments",WC_Log_Levels::ERROR);
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

        $tc = $this->getTwikey($lang);
        $description = "Order ".$order->get_order_number();
        $ref = $order->get_order_number();
        $amount = round($order->get_total());

        $ct = apply_filters( 'twikey_template_selection',$tc->getTemplateId(), $order );
        TwikeyLoader::log("ct: ".$ct, WC_Log_Levels::ERROR);

        $my_order = array(
            "ct"                    => $ct,
            "check"                 => true,
            "order_id"              => $ref,
            "token"                  => $ref,
            "_txr"                  => $ref,
            "_txd"                  => $description,
            "amount"                => $amount,
            'email'                 => $order->billing_email,
            'firstname'             => $order->billing_first_name,
            'lastname'              => $order->billing_last_name,
            'company'               => $order->billing_company,
            'address'               => $order->billing_address_1,
            'city'                  => $order->billing_city,
            'zip'                   => $order->billing_postcode,
            'country'               => $order->billing_country,
            'mobile'                => $order->billing_phone,
            'l'                     => $lang
        );

        try {
            $msg = null;
            $tr = $tc->createNew($my_order);

            if(property_exists($tr,'url')){
                $url = $tr->url;
                if(property_exists($tr,'mndtId')){
                    $mndtId = $tr->mndtId;
                    add_post_meta( $order->id, self::TWIKEY_MNDT_ID, $mndtId, true );
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
                    "message"       => $description,
                    "ref"           => $ref,
                    "amount"        => $amount,
                    "place"         => $ip
                ));

                if(property_exists($tx,'Entries')){
                    $txentry = $tx->Entries[0];

                    $order->update_status( 'on-hold', 'Awaiting payment confirmation from bank' );

                    add_post_meta( $order->id, self::TWIKEY_MNDT_ID, $mndtId, true );
                    $order->add_payment_token($txentry->id);

                    // Reduce stock levels
                    $order->reduce_order_stock();

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

    public function scheduled_subscription_payment($amount_to_charge,WC_Order $new_order ) {

        $mndtId = get_post_meta( $new_order->id, self::TWIKEY_MNDT_ID, true );
        $msg  = sprintf( '%1$s - Order %2$s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $new_order->id );
        TwikeyLoader::log("Schedule new tx: ".$msg.' for '.$mndtId, WC_Log_Levels::INFO);

        $tc = $this->getTwikey();

        $my_order = array(
            "mndtId"                 => $mndtId,
            "message"                => $msg,
            "ref"                    => $new_order->id,
            "amount"                 => round($amount_to_charge,2),
            "place"                  => 'Renewal'
        );

        $tx = $tc->newTransaction($my_order);
        TwikeyLoader::log("TX Response from Twikey: ".print_r($tx, true), WC_Log_Levels::INFO);

        if(property_exists($tx,'Entries')){
            $txentry = $tx->Entries[0];

            $new_order->add_payment_token($txentry->id);

            // Reduce stock levels
            $new_order->reduce_order_stock();
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
        $mndtId = get_post_meta( $order_id, self::TWIKEY_MNDT_ID, true );

        TwikeyLoader::log("Activating mndtId=".$mndtId, WC_Log_Levels::INFO);
        $tc = $this->getTwikey();
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

        $mndtId = get_post_meta( $subscription->id, self::TWIKEY_MNDT_ID, true );

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

        $tc = $this->getTwikey();
        $tc->updateMandate($payload);

        TwikeyLoader::log("Sending status $new_status for $mndtId", WC_Log_Levels::INFO);
}

    public function cancel_subscription( $cancel_subscription ){
        $mndtId = get_post_meta( $cancel_subscription->id, self::TWIKEY_MNDT_ID, true );
        TwikeyLoader::log("Cancelling ".$mndtId, WC_Log_Levels::INFO);

        $tc = $this->getTwikey();
        $tc->cancelMandate($mndtId);
    }

    private function conf_desc(){
        $exiturl = add_query_arg( 'wc-api', 'twikey_exit&mndt={0}&status={1}&s={3}&t={4}', trailingslashit( get_home_url() ) );
        $webhook = add_query_arg( 'wc-api', 'twikey_webhook', trailingslashit( get_home_url() ) );

        $base = __("Configure Twikey environment", "twikey");
        return sprintf( $base, $exiturl, $webhook );
    }

    private function getTwikey($lang = 'en') {
        $tc = new TwikeyWCWrapper();
        $tc->setTestmode('yes' === $this->get_option( 'testmode', 'no' ));
        $tc->setApiToken($this->get_option( 'apikey' ));
        $tc->setTemplateId($this->get_option( 'ct' ));
        $tc->setWebsiteKey($this->get_option( 'websitekey' ));
        $tc->setLang($lang);
        return $tc;
    }
}

