<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for Subscriptions compatibility.
 */
trait WSPA_Stripe_Subscriptions_Trait {

  public function maybe_init_subscriptions() { }

  public function generate_create_session_checkout_subscription_request() {
    wc_add_notice('premium version only', 'error');
    throw new Exception( __('premium version only.', 'woo-pay-addons') );
  }
}