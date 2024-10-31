<?php

namespace Woo_Stripe_Pay_Addons;

if ( !defined( 'ABSPATH' ) ) {
    exit;
    // Exit if accessed directly.
}
class Bootstrap {
    // instance container
    private static $_instance = null;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function is_compatible() {
        // Check if Elementor is installed and activated
        if ( !class_exists( 'WC_Payment_Gateway' ) ) {
            add_action( 'admin_notices', [$this, 'admin_notice_missing_main_plugin'] );
            return false;
        }
        return true;
    }

    public function admin_notice_missing_main_plugin() {
        if ( isset( $_GET['activate'] ) ) {
            unset($_GET['activate']);
        }
        $message = sprintf( 
            /* translators: 1: Plugin name 2: Elementor */
            esc_html__( '"%1$s" requires "%2$s" to be installed and activated.', 'woo-pay-addons' ),
            '<strong>' . WSPA_PLUGIN_NAME . '</strong>',
            '<strong>' . esc_html__( 'WooCommerce', 'woo-pay-addons' ) . '</strong>'
         );
        printf( '<div class="notice notice-warning is-dismissible"><p>%1$s</p></div>', $message );
    }

    public function __construct() {
        if ( $this->is_compatible() ) {
            add_filter(
                'plugin_action_links_' . plugin_basename( WSPA_ADDONS_FILE ),
                [$this, 'add_plugin_action_link'],
                10,
                5
            );
            require_once WSPA_ADDONS_DIR . '/includes/shared/logger.php';
            require_once WSPA_ADDONS_DIR . '/includes/admin/dashboard.php';
            require_once WSPA_ADDONS_DIR . '/includes/admin/admin-controller.php';
            require_once WSPA_ADDONS_DIR . '/includes/rest-api/rest-api-stripe-webhooks-controller.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/abstract-payment-gateway.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-api.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-helper.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-customer.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-settings.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-webhook.php';
            require_once WSPA_ADDONS_DIR . '/includes/core/stripe-webhook-state.php';
            require_once WSPA_ADDONS_DIR . '/includes/shared/order-helper.php';
            require_once WSPA_ADDONS_DIR . '/includes/payment-methods/subscription/trait-wc-stripe-subscriptions-free.php';
            require_once WSPA_ADDONS_DIR . '/includes/payment-methods/stripe-checkout-redirect.php';
            if ( is_admin() ) {
                new \Woo_Stripe_Pay_Addons\Admin\Dashboard();
                new \Woo_Stripe_Pay_Addons\Admin\WooCommerce_Admin();
            }
            new \Woo_Stripe_Pay_Addons\API\Stripe_Webhooks_Controller();
            new \Woo_Stripe_Pay_Addons\Payment_Methods\Checkout_Redirect();
        }
    }

    public function add_plugin_action_link( $actions ) {
        $mylinks = array('<a href="' . admin_url( 'admin.php?page=woo-pay-addons-settings' ) . '">Settings</a>');
        $actions = array_merge( $mylinks, $actions );
        return $actions;
    }

    public static function wspa_woocommerce_block_support() {
        if ( class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
            require_once dirname( __FILE__ ) . '/includes/payment-methods/stripe-checkout-redirect-block-support.php';
            add_action( 'woocommerce_blocks_payment_method_type_registration', function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_gateways = [\Woo_Stripe_Pay_Addons\Payment_Methods\Checkout_Redirect_Block_Support::class];
                $container = \Automattic\WooCommerce\Blocks\Package::container();
                foreach ( $payment_gateways as $gateway_class ) {
                    // registers as shared instance.
                    $container->register( $gateway_class, function () use($gateway_class) {
                        return new $gateway_class();
                    } );
                    $payment_method_registry->register( $container->get( $gateway_class ) );
                }
            }, 5 );
        }
    }

}
