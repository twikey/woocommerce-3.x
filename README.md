<a href="https://www.twikey.com"><img src="https://www.twikey.com/img/logo.svg" width="110"></a>
  
Want to allow your customers to pay in the most convenient way, then Twikey is right what you need.

<blockquote>
    <p>Recurring or occasional payments via SEPA electronic Direct Debit mandates, for WooCommerce</p>
</blockquote>

Twikey offers a simple and safe multichannel solution to negotiate and collect recurring (or even occasional) payments.
Twikey has integrations with most accounting and CRM packages. It is the first and only provider to operate on a European level
for Direct Debit and can work directly with all major Belgian and Dutch Banks. However you can use the payment options of your
favorite PSP to allow other customers to pay as well.

### FEATURES

* Compatible with WooCommerce subscriptions for recurring (and occasional) transactions
* Launch other payment types with affiliated PSP’s
* Omni-channel possibilities besides webshop: email, Sms, WhatsApp, face-2-face, phone, call center, in App negotiating direct debits
* Advanced dunning system for payment follow-up
* Advanced view on all types of payments
* Multiple languages available for end-customers: English, Dutch, French, German, Italian, Spanish, and Portuguese
* Reconciliation output covering all payments for accounting packages
* Redirect URLs

  
### For the validations of Sepa Direct Debit mandates

Core: All 3500 European banks
B2B: ABN-Amro, Bank J. Van Breda, Belfius, BNP Paribas Fortis, Crelan, ING (Belgium and the Netherlands), KBC, Rabobank

### For the execution of transactions : Affiliated banks and PSP's

Affiliated banks:

* ABN-Amro (international)
* Belfius 
* BNP Paribas Fortis (Belgium & Netherlands)
* BNP Paribas (international)
* KBC
* ING (international)
* Non-automated flows: all European banks

Affiliated PSP's:
* MultiSafePay
* Mollie
* EMS
* Stripe
* PayPal
* Adyen
* Ingenico

### Supporting transactions

Recurring transactions:
* Sepa Direct Debit (Europe)

Backup payments:
* iDEAL (the Netherlands)
* Bancontact (Belgium)
* Tikkie (the Netherlands)
* Credit transfers

Please go to the Twikey [signup page](https://www.twikey.com) to start with Twikey. 
Contact info@twikey.com if you have questions or comments about this plugin.

> We offer you the "payment building blocks" for constructing your own cheap payment gateway.

### Gateway selection

You can decide which gateway to use (based on the items in the cart) by adding the filter 'twikey_gateway_selection'
The outcome should either be 'twikey' for Direct debit or 'twikey-paylink' in case you want to use a payment link.
eg.

```$php
    // For hoodies we use direct debit as they are returning customers :)
    public function selectGatewayBasedOnCart($cart){
        // Loop through all products in the Cart
        foreach ($cart as $cart_item_key => $cart_item) {
            $productId = $cart_item['product_id'];
            $term_list = wp_get_post_terms($productId, 'product_cat');
            // SELF::log("$term_list = ".print_r($term_list,true),WC_Log_Levels::NOTICE);
            $cat = $term_list[0] -> slug;
            if ($cat === 'hoodies') {
                // SELF::log("CARD = $cat -> ".print_r($isCard,true));
                return 'twikey';
            }
        }
        return 'twikey-paylink';
    }
    add_filter('twikey_gateway_selection', array( $this, 'selectGatewayBasedOnCart') );
```

### Template selection

You can decide which template to use (based on the items in the order) by adding the filter 'twikey_template_selection'
The outcome should be a template id.
eg.

```$php
    // For hoodies we use direct debit as they are returning customers :)
    public function selectCtBasedOnOrder($order){
        if($order->get_billing_country() === 'BE')
            return 123;
        return 321;
    }
    add_filter('twikey_template_selection', array( $this, 'selectCtBasedOnOrder') );
```
