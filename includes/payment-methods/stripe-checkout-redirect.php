<?php

namespace Woo_Stripe_Pay_Addons\Payment_Methods;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use \Exception;
use \WP_Error;
use \WC_AJAX;
use \Woo_Stripe_Pay_Addons\Core\Abstract_Payment_Gateway;
use Woo_Stripe_Pay_Addons\Core\Stripe_Settings;
use \Woo_Stripe_Pay_Addons\Core\Stripe_Helper;
use \Woo_Stripe_Pay_Addons\Core\Stripe_API;
use \Woo_Stripe_Pay_Addons\Core\Stripe_Customer;
use \Woo_Stripe_Pay_Addons\Shared\Logger;
use \Woo_Stripe_Pay_Addons\Shared\order_helper;

class Checkout_Redirect extends Abstract_Payment_Gateway
{
  use \WSPA_Stripe_Subscriptions_Trait;

  public $saved_cards;

  public function __construct()
  {
    parent::__construct();
    $this->id   = 'wspa_checkout_redirect';
    $this->icon = apply_filters('wspa_payment_element_icon', WSPA_ADDONS_URL . 'assets/img/stripe-methods.svg');
    $this->has_fields = false;
    $this->method_title = __('Stripe Checkout Redirect', 'woo-pay-addons');
    $this->method_description = __('Redirect to stripe-hosted checkout page, with over 30+ payment methods, including popular options such as Apple Pay, Google Pay, iDeal, and SEPA, all in one convenient option.', 'woo-pay-addons');

    $this->init_form_fields();
    $this->init_settings();
    
    $this->enabled = $this->get_option('enabled');
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description', ' ');
    $this->icon = $this->get_option('icon', $this->icon);
    $this->saved_cards = 'yes' === $this->get_option( 'saved_cards' );
    $this->supports = array(
      'products',
      'refunds',
      // 'tokenization', // TODO
    );
    
    // Check if subscriptions are enabled and add support for them.
		$this->maybe_init_subscriptions();
    
    add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ] );
    
    add_action('wc_ajax_' . $this->id . '_verify_session_checkout', [$this, 'verify_session_checkout']);
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
  }

  public function admin_options() {
    ?>
      <div class="wspa-payment-method">
        <h2><?php echo esc_html( $this->get_method_title() ); ?></h2>
        <span><?php echo wp_kses_post( wpautop( $this->get_method_description() ) ); ?></span>
        <div class="wspa-payment-method-content">
          <table class="form-table payment-method-settings"><?php echo $this->generate_settings_html( $this->get_form_fields(), false ); // WPCS: XSS ok. ?></table>
          <div class="payment-method-preview">
            <h3>Overview</h3>
            <img src="<?php echo esc_url(WSPA_ADDONS_URL . 'assets/admin/img/checkout-redirect-guide.png'); ?>"/>
          </div>
        </div>
      </div>
    <?php
  }

  public function init_form_fields()
  {

    $this->form_fields = array(
      'enabled' => array(
        'title' => __('Enable/Disable', 'woo-pay-addons'),
        'type' => 'checkbox',
        'label' => __('Enable', 'woo-pay-addons'),
        'default' => 'no'
      ),
      'title' => array(
        'title' => __('Title', 'woo-pay-addons'),
        'type' => 'text',
        'default' => __('Stripe Checkout Redirect', 'woo-pay-addons'),
        'desc_tip' => true,
        'description' => __('The title for the Woo Stripe Gateway that customers will see when they are in the checkout page.', 'woo-pay-addons')
      ),
      'icon' => array(
        'title' => __('Icon', 'woo-pay-addons'),
        'type' => 'text',
        'desc_tip'    => __( '', 'woo-pay-addons' ),
        'description' => __('This icon will display along with the title in the checkout page.', 'woo-pay-addons')
      ),
      'description' => array(
        'title' => __('Description', 'woo-pay-addons'),
        'type' => 'textarea',
        'default' => __('You will be redirected to a checkout page hosted by Stripe..', 'woo-pay-addons'),
        'desc_tip' => true,
        'css'         => 'width: 400px',
        'description' => __('The payment method description.', 'woo-pay-addons'),
      ),
      'payment_methods'        => [
        'title'       => __( 'Payment Methods', 'woo-pay-addons' ),
        'type'        => 'multiselect',
        'class'             => 'wc-enhanced-select',
        'desc_tip'    => __( '`Automatic` means it will collect the best suitable payment methods.', 'woo-pay-addons' ),
        'options'     => $this->get_supported_payment_methods(),
        'custom_attributes' => array(
					'data-placeholder' => __( 'Select payment methods', 'woo-pay-addons' ),
				),
        'default'     => [ 'automatic' ],
        'description' => __( 'Ensure the selected payment methods are activated and available in Stripe dashboard. (Payment methods are unavailable when they don’t support the currency or terms of the current payment.)', 'woo-pay-addons' ),
      ],
      'saved_cards'                         => [
        'title'       => __( 'Saved Cards', 'woo-pay-addons' ),
        'label'       => __( 'Enable Payment via Saved Cards', 'woo-pay-addons' ),
        'type'        => 'checkbox',
        'description' => __( 'If enabled, users will be able to pay with a saved card during checkout. Card details are saved on Stripe servers, not on your store.', 'woo-pay-addons' ),
        'default'     => 'no',
        'desc_tip'    => true,
      ],
      'enable_auto_tax'                         => [
        'title'       => __( 'Stripe-Tax', 'woo-pay-addons' ),
        'label'       => __( 'Enable Stripe Automate Tax', 'woo-pay-addons' ),
        'type'        => 'checkbox',
        'description' => __( 'Note: This feature is only enabled when WooCommerce Tax is turned off. Additionally, ensure that you have set up the Stripe tax settings by following <a href="https://woo-docs.payaddons.com/fundamentals/auto-tax-calculation" class="wspa-button-link" target="_blank" rel="external noreferrer noopener">this guide</a>', 'woo-pay-addons' ),
        'default'     => 'no',
      ],
    );
  }

  public function payment_fields()
  {
    $description = $this->get_description();

    $display_tokenization = $this->supports( 'tokenization' ) && is_checkout();
    if ( $display_tokenization ) {
      $this->tokenization_script();
      $this->saved_payment_methods();
    }
    ?>
    <div class="wspa-checkout-redirect wc-payment-form">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 40" role="presentation"><path opacity=".6" fill-rule="evenodd" clip-rule="evenodd" d="M0 8a4 4 0 014-4h30a4 4 0 014 4v8a1 1 0 11-2 0v-4a2 2 0 00-2-2H4a2 2 0 00-2 2v20a2 2 0 002 2h30a2 2 0 002-2v-6a1 1 0 112 0v6a4 4 0 01-4 4H4a4 4 0 01-4-4V8zm4 0a1 1 0 100-2 1 1 0 000 2zm3 0a1 1 0 100-2 1 1 0 000 2zm4-1a1 1 0 11-2 0 1 1 0 012 0zm29.992 9.409L44.583 20H29a1 1 0 100 2h15.583l-3.591 3.591a1 1 0 101.415 1.416l5.3-5.3a1 1 0 000-1.414l-5.3-5.3a1 1 0 10-1.415 1.416z"></path></svg>
      <span><?php echo esc_html(trim( $description )) ?></span>
    </div>
    <?php
    if ($this->is_saved_cards_enabled()) {
      if (is_user_logged_in()) {
        $force_save_payment = !apply_filters('wspa_stripe_display_save_payment_method_checkbox', true);
        $this->save_payment_method_checkbox($force_save_payment);
      }
    }

    $api_key = Stripe_Settings::get_publishable_key();
    if (empty($api_key)) {
      echo  __('<div class="wspa_error">Stripe API keys are required to process payments through Stripe. Please configure your Stripe publishable and secret API keys in the plugin settings.</div>', 'woo-pay-addons');
      return;
    }

    if ($this->testmode) {
      echo sprintf(__('<div class="wspa_warning">In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date or check the <a href="%s" target="_blank">Testing Stripe documentation</a> for more card numbers.</div>', 'woo-pay-addons'), 'https://stripe.com/docs/testing');
    }
  }

  public function scripts() {
    if (apply_filters('wspa_exclude_frontend_scripts', !is_product() && !is_cart() && !is_checkout() && !is_add_payment_method_page())) {
      return;
    }
    
    wp_register_style( 'wspa_pay_addons', plugins_url( 'assets/css/woo-pay-addons.css', WSPA_ADDONS_FILE ), false, WSPA_PLUGIN_VERSION);
    wp_enqueue_style( 'wspa_pay_addons' );
    if($this->enabled == 'no') return;
  }

  public function javascript_params() {
    $js_params = [
      'title' => $this->title, 
      'test_mode' => $this->testmode,
      'description' => $this->description, 
      'locale' => get_locale(),
      'stripe_publishable_key' => Stripe_Settings::get_publishable_key(),
    ];

    return $js_params;
  }

	public function enable_auto_tax() {
		// enable only if not use wc tax
		return $this->get_option( 'enable_auto_tax' ) === 'yes' && !wc_tax_enabled();
	}

  public function get_product_name() {
    $names = [];
    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
        array_push($names, $_product->get_name() . ' x ' . $cart_item['quantity']); 
      }
    }
    return join(',', $names);
  }

  public function is_customer_valid_for_tax($customer_id) {
    $response = Stripe_API::request( [], 'customers/' . $customer_id . '?expand[]=tax', 'GET' );

		if ( ! empty( $response->error ) ) {
      Logger::info( "retrive stripe customer error:" . $response->error->message );
		}
    return in_array($response->tax->automatic_tax, ['supported', 'not_collecting']);
  }

  	/**
	 * Generates the request when creating a new session checkout
	 *
	 * @param WC_Order $order           The order that is being paid for.
	 * @param object   $prepared_source The source that is used for the payment.
	 * @return array                    The arguments for the request.
	 */
	public function generate_create_session_checkout_request( $order ) {
		$full_request['description'] = sprintf( __( '%1$s - Order %2$s', 'woo-pay-addons' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
		$billing_email            = $order->get_billing_email();
		$billing_first_name       = $order->get_billing_first_name();
		$billing_last_name        = $order->get_billing_last_name();

		if ( ! empty( $billing_email ) && apply_filters( 'wc_stripe_send_stripe_receipt', false ) ) {
			$full_request['receipt_email'] = $billing_email;
		}
		$metadata = [
			__( 'customer_name', 'woo-pay-addons' ) => sanitize_text_field( $billing_first_name ) . ' ' . sanitize_text_field( $billing_last_name ),
			__( 'customer_email', 'woo-pay-addons' ) => sanitize_email( $billing_email ),
			'order_id' => $order->get_order_number(),
			'site_url' => esc_url( get_site_url() ),
		];

    $line_items = order_helper::build_order_line_items(true);

    $checkout_session = array(
      'mode'=> 'payment',
      'line_items' => $line_items,
      'metadata' => $metadata,
      'customer' => $this->get_customer_id( $order ),
      'customer_update' => [
        'name' => 'auto',
        'address' => 'auto',
        'shipping' => 'auto',
      ],
      'billing_address_collection' => 'auto'
    );

    // tax calculation
    $enable_auto_tax = $this->enable_auto_tax();
    $has_tax_code = array_filter($line_items, function($item) {
      return isset($item['price_data']['product_data']['tax_code']);
    });
    if($enable_auto_tax || $has_tax_code) {
      $checkout_session['automatic_tax'] = ['enabled' => 'true'];
      if(!$this->is_customer_valid_for_tax($checkout_session['customer'])) {
        unset($checkout_session['customer']);
        unset($checkout_session['customer_update']);
      }
    }

		$methods = $this->get_payment_methods();
		if ( count($methods) > 0) {
      $checkout_session['payment_method_types'] = $methods; 
      if (in_array('wechat_pay', $methods)) {
        $checkout_session['payment_method_options'] = [
          'wechat_pay' => [
            'client' => "web"
          ],
        ];
      }
		}

		if ( isset( $full_request['receipt_email'] ) ) {
			$checkout_session['receipt_email'] = $full_request['receipt_email'];
			$checkout_session['customer_email'] = $full_request['receipt_email'];
		}
    $checkout_session['payment_intent_data'] = [
      'description' => $full_request['description'],
    ];
    if(!isset($checkout_session['customer'])) {
      $checkout_session['customer_creation'] = 'if_required';
    }

    if($this->is_saved_cards_enabled() && $this->should_save_payment_method()) {
      $checkout_session['payment_method_data'] = [
        'allow_redisplay' => 'always'
      ];
      $checkout_session['payment_intent_data']['setup_future_usage'] = 'off_session';
    }

		$payment_method_option = $this->get_payment_method_options($methods);
		if($payment_method_option) {
			$checkout_session['payment_method_options'] = $payment_method_option;
		}
   
		return apply_filters( 'wspa_create_session_checkout_request', $checkout_session, $order );
	}

  public function create_session_checkout($order)
  {
    $order_id = $order->get_id();
    if ($this->has_subscription($order_id)) {
      // subscription mode
      $request = $this->generate_create_session_checkout_subscription_request($order);
    }
    else {
      // Payment mode
      $this->validate_minimum_order_amount( $order );
      $request = $this->generate_create_session_checkout_request($order);
    }

    $query_params = [
      'order'       => $order_id,
      'session_id'  => '{CHECKOUT_SESSION_ID}',
      'save_payment_method' => $this->should_save_payment_method() ? 'yes' : 'no',
    ];
    $ajax_url = home_url(WC_AJAX::get_endpoint($this->id . '_verify_session_checkout'));
    $request['success_url'] = add_query_arg($query_params, $ajax_url);
    $request['cancel_url'] = wc_get_checkout_url();

    $session_checkout = Stripe_API::request($request, 'checkout/sessions');
    if ( ! empty( $session_checkout->error ) ) {
      if ( $this->is_no_such_customer_error( $session_checkout->error ) ) {
        $user     = $this->get_user_from_order($order);
        $customer = new Stripe_Customer($user->ID);
        $customer->delete_id_from_meta();
        return $this->create_session_checkout($order);
      }
    }
    return $session_checkout;
  }

  public function process_payment($order_id, $retry = true)
  {
    try {
      $order = wc_get_order( $order_id );
      Logger::info( "Info: Begin processing payment for order $order_id for the amount of {$order->get_total()}" );
      $session_checkout = $this->create_session_checkout($order);

      if ($session_checkout->error) {
        Logger::error("Stripe Session Checkout for order $order_id initiated failed");
        wc_add_notice($session_checkout->error->message, 'error');

			  do_action( 'wspa_gateway_stripe_process_payment_error', $session_checkout->error, $order );

        return [
          'result'   => 'fail',
          'redirect' => '',
        ];
      }
      Logger::info("Stripe Session Checkout $session_checkout->id initiated for order $order_id");

      return [
        'result'                => 'success',
        'redirect'              => $session_checkout->url,
      ];
    } catch (Exception $e) {
      Logger::error($e->getMessage(), true);
      return new WP_Error('order-error', '<div class="woocommerce-error">' . $e->getMessage() . '</div>', ['status' => 200]);
    }
  }
  
  public function verify_session_checkout() {

    $session_checkout_id = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if(empty($session_checkout_id)) return;

    $order_id = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $order    = wc_get_order($order_id);

    $session_checkout = Stripe_API::request(
      null,
      'checkout/sessions/' . $session_checkout_id . '?expand[]=payment_intent&expand[]=invoice.payment_intent',
      'GET'
    );

    if($session_checkout->amount_total === 0) {
      $this->process_session_checkout_zero_order( $session_checkout, $order_id );
      exit();
    }

    $intent = $session_checkout->payment_intent ?? $session_checkout->invoice->payment_intent;

    Stripe_Helper::add_payment_intent_to_order( $intent->id, $order );

    $this->handle_verify_intent($order, $intent);
  }
}
