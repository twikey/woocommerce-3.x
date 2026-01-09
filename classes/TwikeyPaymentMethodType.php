<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class TwikeyPaymentMethodType extends AbstractPaymentMethodType
{
    protected $name = 'twikey';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_twikey_settings', [] );
    }

    public function is_active() {
        return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'twikey-payment-method',
            plugins_url('../resources/twikey-payment-method.js', __FILE__),
            ['wc-settings', 'wc-blocks-registry', 'wp-element'],
            // Use file modification time to ensure cache busting when the file changes
            filemtime(plugin_dir_path(__FILE__) . '../resources/twikey-payment-method.js') ?: '1.0.0',
            true
        );
        return [ 'twikey-payment-method' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting('title', 'Twikey'),
            'description' => $this->get_setting('description'),
            'supports'    => $this->get_supported_features(),
        ];
    }
}
