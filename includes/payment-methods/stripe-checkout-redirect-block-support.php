<?php

namespace Woo_Stripe_Pay_Addons\Payment_Methods;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout_Redirect_Block_Support class.
 *
 * @extends AbstractPaymentMethodType
 */
final class Checkout_Redirect_Block_Support extends AbstractPaymentMethodType {
	/**
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = 'wspa_checkout_redirect';

	static $gateway_name = 'wspa_checkout_redirect';

	/**
	 * Constructor
	 *
	 */
	public function __construct() {
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'add_meta_data' ], 8, 2 );
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . self::$gateway_name . '_settings', [] );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		// If Stripe isn't enabled, then we don't need to check anything else - it isn't active.
		if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {

		$asset_path   = WSPA_ADDONS_PATH . '/build/free.asset.php';
		$version      = WSPA_PLUGIN_VERSION;
		$dependencies = [];
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}
		wp_enqueue_style(
			'wspa-free-blocks-style',
			WSPA_ADDONS_URL . '/build/free.css',
			[],
			$version
		);
		wp_register_script(
			'wspa-free-blocks-js',
			WSPA_ADDONS_URL . '/build/free.js',
			array_merge( [ ], $dependencies ),
			$version,
			true
		);

		return [ 'wspa-free-blocks-js' ];
	}

	private function get_show_saved_cards() {
		return isset( $this->settings['saved_cards'] ) ? 'yes' === $this->settings['saved_cards'] : false;
	}

	private function get_show_save_option() {
		$saved_cards = $this->get_show_saved_cards();
		return apply_filters( 'wspa_stripe_display_save_payment_method_checkbox', filter_var( $saved_cards, FILTER_VALIDATE_BOOLEAN ) );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		// We need to call array_merge_recursive so the blocks 'button' setting doesn't overwrite
		// what's provided from the gateway or payment request configuration.
		return array_replace_recursive(
			$this->get_gateway_javascript_params(),
			// Blocks-specific options
			[
				'icon'                          => $this->get_icons(),
				'supports'                       => $this->get_supported_features(),
				'showSavedCards'                 => $this->get_show_saved_cards(),
				'showSaveOption'                 => $this->get_show_save_option(),
				'isAdmin'                        => is_admin(),
			]
		);
	}

	/**
	 * Returns the Stripe Payment Gateway JavaScript configuration object.
	 *
	 * @return array  the JS configuration from the Stripe Payment Gateway.
	 */
	private function get_gateway_javascript_params() {
		$js_configuration   = [];
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( isset( $available_gateways[self::$gateway_name] ) ) {
			$js_configuration = $available_gateways[self::$gateway_name]->javascript_params();
		}

		return apply_filters(
			'wspa_stripe_params',
			$js_configuration
		);
	}

	/**
	 * Return the icons urls.
	 *
	 * @return array Arrays of icons metadata.
	 */
	private function get_icons() {
		return [
			'id' => self::$gateway_name, 
			'src' => $this->settings['icon'],
			'alt' => __( 'stripe payment element', 'woo-pay-addons' ),
		];
	}

	public function add_meta_data( PaymentContext $context, PaymentResult &$result ) {
		// Hook into Stripe error processing so that we can capture the error to payment details.
		// This error would have been registered via wc_add_notice() and thus is not helpful for block checkout processing.
		add_action(
			'wspa_gateway_stripe_process_payment_error',
			function( $error ) use ( &$result ) {
				$payment_details                 = $result->payment_details;
				$payment_details['errorMessage'] = wp_strip_all_tags( $error->message );
				$result->set_payment_details( $payment_details );
			}
		);
	}

	public function get_supported_features() {
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		return $gateways[self::$gateway_name]->supports;	
	}
}
