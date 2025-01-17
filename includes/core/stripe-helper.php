<?php

namespace Woo_Stripe_Pay_Addons\Core;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Provides static methods as helpers.
 *
 * @since 4.0.0
 */
class Stripe_Helper {
	const LEGACY_META_NAME_FEE      = 'Stripe Fee';
	const LEGACY_META_NAME_NET      = 'Net Revenue From Stripe';
	const META_NAME_FEE             = '_stripe_fee';
	const META_NAME_NET             = '_stripe_net';
	const META_NAME_STRIPE_CURRENCY = '_stripe_currency';

	/**
	 * Gets the Stripe currency for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @return string $currency
	 */
	public static function get_stripe_currency( $order = null ) {
		if ( is_null( $order ) ) {
			return false;
		}

		return $order->get_meta( self::META_NAME_STRIPE_CURRENCY, true );
	}

	/**
	 * Updates the Stripe currency for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @param string $currency
	 */
	public static function update_stripe_currency( $order, $currency ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$order->update_meta_data( self::META_NAME_STRIPE_CURRENCY, $currency );
	}

	/**
	 * Gets the Stripe fee for order. With legacy check.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @return string $amount
	 */
	public static function get_stripe_fee( $order = null ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$amount = $order->get_meta( self::META_NAME_FEE, true );

		// If not found let's check for legacy name.
		if ( empty( $amount ) ) {
			$amount = $order->get_meta( self::LEGACY_META_NAME_FEE, true );

			// If found update to new name.
			if ( $amount ) {
				self::update_stripe_fee( $order, $amount );
			}
		}

		return $amount;
	}

	/**
	 * Updates the Stripe fee for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @param float  $amount
	 */
	public static function update_stripe_fee( $order = null, $amount = 0.0 ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$order->update_meta_data( self::META_NAME_FEE, $amount );
	}

	/**
	 * Deletes the Stripe fee for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 */
	public static function delete_stripe_fee( $order = null ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$order_id = $order->get_id();

		delete_post_meta( $order_id, self::META_NAME_FEE );
		delete_post_meta( $order_id, self::LEGACY_META_NAME_FEE );
	}

	/**
	 * Gets the Stripe net for order. With legacy check.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @return string $amount
	 */
	public static function get_stripe_net( $order = null ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$amount = $order->get_meta( self::META_NAME_NET, true );

		// If not found let's check for legacy name.
		if ( empty( $amount ) ) {
			$amount = $order->get_meta( self::LEGACY_META_NAME_NET, true );

			// If found update to new name.
			if ( $amount ) {
				self::update_stripe_net( $order, $amount );
			}
		}

		return $amount;
	}

	/**
	 * Updates the Stripe net for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 * @param float  $amount
	 */
	public static function update_stripe_net( $order = null, $amount = 0.0 ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$order->update_meta_data( self::META_NAME_NET, $amount );
	}

	/**
	 * Deletes the Stripe net for order.
	 *
	 * @since 4.1.0
	 * @param object $order
	 */
	public static function delete_stripe_net( $order = null ) {
		if ( is_null( $order ) ) {
			return false;
		}

		$order_id = $order->get_id();

		delete_post_meta( $order_id, self::META_NAME_NET );
		delete_post_meta( $order_id, self::LEGACY_META_NAME_NET );
	}

	/**
	 * Get Stripe amount to pay
	 *
	 * @param float  $total Amount due.
	 * @param string $currency Accepted currency.
	 *
	 * @return float|int
	 */
	public static function get_stripe_amount( $total, $currency = '' ) {
		if ( ! $currency ) {
			$currency = get_woocommerce_currency();
		}

		if ( in_array( strtolower( $currency ), self::no_decimal_currencies() ) ) {
			return absint( $total );
		} else {
			return absint( wc_format_decimal( ( (float) $total * 100 ), wc_get_price_decimals() ) ); // In cents.
		}
	}

	public static function get_localized_messages( $code = '', $message = '' ) {
		$localized_messages = apply_filters(
			'wspa_stripe_localized_messages',
			[
				'account_country_invalid_address'        => __( 'The business address that you provided does not match the country set in your account. Please enter an address that falls within the same country.', 'woo-pay-addons' ),
				'account_invalid'                        => __( 'The account ID provided in the Stripe-Account header is invalid. Please check that your requests specify a valid account ID.', 'woo-pay-addons' ),
				'amount_too_large'                       => __( 'The specified amount is greater than the maximum amount allowed. Use a lower amount and try again.', 'woo-pay-addons' ),
				'amount_too_small'                       => __( 'The specified amount is less than the minimum amount allowed. Use a higher amount and try again.', 'woo-pay-addons' ),
				'api_key_expired'                        => __( 'Your API Key has expired. Please update your integration with the latest API key available in your Dashboard.', 'woo-pay-addons' ),
				'authentication_required'                => __( 'The payment requires authentication to proceed. If your customer is off session, notify your customer to return to your application and complete the payment. If you provided the error_on_requires_action parameter, then your customer should try another card that does not require authentication.', 'woo-pay-addons' ),
				'balance_insufficient'                   => __( 'The transfer or payout could not be completed because the associated account does not have a sufficient balance available. Create a new transfer or payout using an amount less than or equal to the account’s available balance.', 'woo-pay-addons' ),
				'bank_account_declined'                  => __( 'The bank account provided can not be used either because it is not verified yet or it is not supported.', 'woo-pay-addons' ),
				'bank_account_unusable'                  => __( 'The bank account provided cannot be used. Please try a different bank account.', 'woo-pay-addons' ),
				'setup_intent_unexpected_state'          => __( 'The SetupIntent\'s state was incompatible with the operation you were trying to perform.', 'woo-pay-addons' ),
				'payment_intent_action_required'         => __( 'The provided payment method requires customer action to complete. If you\'d like to add this payment method, please upgrade your integration to handle actions.', 'woo-pay-addons' ),
				'payment_intent_authentication_failure'  => __( 'The provided payment method failed authentication. Provide a new payment method to attempt this payment again.', 'woo-pay-addons' ),
				'payment_intent_incompatible_payment_method' => __( 'The Payment expected a payment method with different properties than what was provided.', 'woo-pay-addons' ),
				'payment_intent_invalid_parameter'       => __( 'One or more provided parameters was not allowed for the given operation on the Payment.', 'woo-pay-addons' ),
				'payment_intent_mandate_invalid'         => __( 'The provided mandate is invalid and can not be used for the payment intent.', 'woo-pay-addons' ),
				'payment_intent_payment_attempt_expired' => __( 'The latest attempt for this Payment has expired. Provide a new payment method to attempt this Payment again.', 'woo-pay-addons' ),
				'payment_intent_unexpected_state'        => __( 'The PaymentIntent\'s state was incompatible with the operation you were trying to perform.', 'woo-pay-addons' ),
				'payment_method_billing_details_address_missing' => __( 'The PaymentMethod\'s billing details is missing address details. Please update the missing fields and try again.', 'woo-pay-addons' ),
				'payment_method_currency_mismatch'       => __( 'The currency specified does not match the currency for the attached payment method. A payment can only be created for the same currency as the corresponding payment method.', 'woo-pay-addons' ),
				'processing_error'                       => __( 'An error occurred while processing the card. Use a different payment method or try again later.', 'woo-pay-addons' ),
				'token_already_used'                     => __( 'The token provided has already been used. You must create a new token before you can retry this request.', 'woo-pay-addons' ),
				'invalid_number'                         => __( 'The card number is invalid. Check the card details or use a different card.', 'woo-pay-addons' ),
				'invalid_card_type'                      => __( 'The card provided as an external account is not supported for payouts. Provide a non-prepaid debit card instead.', 'woo-pay-addons' ),
				'invalid_charge_amount'                  => __( 'The specified amount is invalid. The charge amount must be a positive integer in the smallest currency unit, and not exceed the minimum or maximum amount.', 'woo-pay-addons' ),
				'invalid_cvc'                            => __( 'The card\'s security code is invalid. Check the card\'s security code or use a different card.', 'woo-pay-addons' ),
				'invalid_expiry_year'                    => __( 'The card\'s expiration year is incorrect. Check the expiration date or use a different card.', 'woo-pay-addons' ),
				'invalid_source_usage'                   => __( 'The source cannot be used because it is not in the correct state.', 'woo-pay-addons' ),
				'incorrect_address'                      => __( 'The address entered for the card is invalid. Please check the address or try a different card.', 'woo-pay-addons' ),
				'incorrect_cvc'                          => __( 'The security code entered is invalid. Please try again.', 'woo-pay-addons' ),
				'incorrect_number'                       => __( 'The card number entered is invalid. Please try again with a valid card number or use a different card.', 'woo-pay-addons' ),
				'incorrect_zip'                          => __( 'The postal code entered for the card is invalid. Please try again.', 'woo-pay-addons' ),
				'missing'                                => __( 'Both a customer and source ID have been provided, but the source has not been saved to the customer. To create a charge for a customer with a specified source, you must first save the card details.', 'woo-pay-addons' ),
				'email_invalid'                          => __( 'The email address is invalid. Check that the email address is properly formatted and only includes allowed characters.', 'woo-pay-addons' ),
				// Card declined started here.
				'card_declined'                          => __( 'The card has been declined. When a card is declined, the error returned also includes the decline_code attribute with the reason why the card was declined.', 'woo-pay-addons' ),
				'insufficient_funds'                     => __( 'The card has insufficient funds to complete the purchase.', 'woo-pay-addons' ),
				'generic_decline'                        => __( 'The card has been declined. Please try again with another card.', 'woo-pay-addons' ),
				'lost_card'                              => __( 'The card has been declined (Lost card). Please try again with another card.', 'woo-pay-addons' ),
				'stolen_card'                            => __( 'The card has been declined (Stolen card). Please try again with another card.', 'woo-pay-addons' ),
				// Card declined end here.
				'parameter_unknown'                      => __( 'The request contains one or more unexpected parameters. Remove these and try again.', 'woo-pay-addons' ),
				'incomplete_number'                      => __( 'Your card number is incomplete.', 'woo-pay-addons' ),
				'incomplete_expiry'                      => __( 'Your card\'s expiration date is incomplete.', 'woo-pay-addons' ),
				'incomplete_cvc'                         => __( 'Your card\'s security code is incomplete.', 'woo-pay-addons' ),
				'incomplete_zip'                         => __( 'Your card\'s zip code is incomplete.', 'woo-pay-addons' ),
				'stripe_cc_generic'                      => __( 'There was an error processing your credit card.', 'woo-pay-addons' ),
				'invalid_expiry_year_past'               => __( 'Your card\'s expiration year is in the past.', 'woo-pay-addons' ),
				'bank_account_verification_failed'       => __(
					'The bank account cannot be verified, either because the microdeposit amounts provided do not match the actual amounts, or because verification has failed too many times.',
					'woo-pay-addons'
				),
				'card_decline_rate_limit_exceeded'       => __(
					'This card has been declined too many times. You can try to charge this card again after 24 hours. We suggest reaching out to your customer to make sure they have entered all of their information correctly and that there are no issues with their card.',
					'woo-pay-addons'
				),
				'charge_already_captured'                => __( 'The charge you\'re attempting to capture has already been captured. Update the request with an uncaptured charge ID.', 'woo-pay-addons' ),
				'charge_already_refunded'                => __(
					'The charge you\'re attempting to refund has already been refunded. Update the request to use the ID of a charge that has not been refunded.',
					'woo-pay-addons'
				),
				'charge_disputed'                        => __(
					'The charge you\'re attempting to refund has been charged back. Check the disputes documentation to learn how to respond to the dispute.',
					'woo-pay-addons'
				),
				'charge_exceeds_source_limit'            => __(
					'This charge would cause you to exceed your rolling-window processing limit for this source type. Please retry the charge later, or contact us to request a higher processing limit.',
					'woo-pay-addons'
				),
				'charge_expired_for_capture'             => __(
					'The charge cannot be captured as the authorization has expired. Auth and capture charges must be captured within seven days.',
					'woo-pay-addons'
				),
				'charge_invalid_parameter'               => __(
					'One or more provided parameters was not allowed for the given operation on the Charge. Check our API reference or the returned error message to see which values were not correct for that Charge.',
					'woo-pay-addons'
				),
				'account_number_invalid'                 => __( 'The bank account number provided is invalid (e.g., missing digits). Bank account information varies from country to country. We recommend creating validations in your entry forms based on the bank account formats we provide.', 'woo-pay-addons' ),
			]
		);

		// if need all messages.
		if ( empty( $code ) ) {
			return $localized_messages;
		}

		return isset( $localized_messages[ $code ] ) ? $localized_messages[ $code ] : $message;
	}

	/**
	 * List of currencies supported by Stripe that has no decimals
	 * https://stripe.com/docs/currencies#zero-decimal from https://stripe.com/docs/currencies#presentment-currencies
	 *
	 * @return array $currencies
	 */
	public static function no_decimal_currencies() {
		return [
			'bif', // Burundian Franc
			'clp', // Chilean Peso
			'djf', // Djiboutian Franc
			'gnf', // Guinean Franc
			'jpy', // Japanese Yen
			'kmf', // Comorian Franc
			'krw', // South Korean Won
			'mga', // Malagasy Ariary
			'pyg', // Paraguayan Guaraní
			'rwf', // Rwandan Franc
			'ugx', // Ugandan Shilling
			'vnd', // Vietnamese Đồng
			'vuv', // Vanuatu Vatu
			'xaf', // Central African Cfa Franc
			'xof', // West African Cfa Franc
			'xpf', // Cfp Franc
		];
	}

	/**
	 * Stripe uses smallest denomination in currencies such as cents.
	 * We need to format the returned currency from Stripe into human readable form.
	 * The amount is not used in any calculations so returning string is sufficient.
	 *
	 * @param object $balance_transaction
	 * @param string $type Type of number to format
	 * @return string
	 */
	public static function format_balance_fee( $balance_transaction, $type = 'fee' ) {
		if ( ! is_object( $balance_transaction ) ) {
			return;
		}

		if ( in_array( strtolower( $balance_transaction->currency ), self::no_decimal_currencies() ) ) {
			if ( 'fee' === $type ) {
				return $balance_transaction->fee;
			}

			return $balance_transaction->net;
		}

		if ( 'fee' === $type ) {
			return number_format( $balance_transaction->fee / 100, 2, '.', '' );
		}

		return number_format( $balance_transaction->net / 100, 2, '.', '' );
	}

	/**
	 * Checks Stripe minimum order value authorized per currency
	 */
	public static function get_minimum_amount() {
		// Check order amount
		switch ( get_woocommerce_currency() ) {
			case 'USD':
			case 'CAD':
			case 'EUR':
			case 'CHF':
			case 'AUD':
			case 'SGD':
				$minimum_amount = 50;
				break;
			case 'GBP':
				$minimum_amount = 30;
				break;
			case 'DKK':
				$minimum_amount = 250;
				break;
			case 'NOK':
			case 'SEK':
				$minimum_amount = 300;
				break;
			case 'JPY':
				$minimum_amount = 5000;
				break;
			case 'MXN':
				$minimum_amount = 1000;
				break;
			case 'HKD':
				$minimum_amount = 400;
				break;
			default:
				$minimum_amount = 50;
				break;
		}

		return $minimum_amount;
	}


	/**
	 * Checks if WC version is less than passed in version.
	 *
	 * @since 4.1.11
	 * @param string $version Version to check against.
	 * @return bool
	 */
	public static function is_wc_lt( $version ) {
		return version_compare( WC_VERSION, $version, '<' );
	}

	/**
	 * Gets the webhook URL for Stripe triggers. Used mainly for
	 * asyncronous redirect payment methods in which statuses are
	 * not immediately chargeable.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @return string
	 */
	public static function get_webhook_url() {
		return add_query_arg( 'wc-api', 'wc_stripe', trailingslashit( get_home_url() ) );
	}

	/**
	 * Gets the order by Stripe source ID.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param string $source_id
	 */
	public static function get_order_by_source_id( $source_id ) {
		global $wpdb;

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders   = wc_get_orders(
				[
					'limit'      => 1,
					'meta_query' => [
						[
							'key'   => '_stripe_source_id',
							'value' => $source_id,
						],
					],
				]
			);
			$order_id = current( $orders ) ? current( $orders )->get_id() : false;
		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $source_id, '_stripe_source_id' ) );
		}

		if ( ! empty( $order_id ) ) {
			return wc_get_order( $order_id );
		}

		return false;
	}

	/**
	 * Gets the order by Stripe charge ID.
	 *
	 * @since 4.0.0
	 * @since 4.1.16 Return false if charge_id is empty.
	 * @param string $charge_id
	 */
	public static function get_order_by_charge_id( $charge_id ) {
		global $wpdb;

		if ( empty( $charge_id ) ) {
			return false;
		}

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders   = wc_get_orders(
				[
					'transaction_id' => $charge_id,
					'limit'          => 1,
				]
			);
			$order_id = current( $orders ) ? current( $orders )->get_id() : false;
		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $charge_id, '_transaction_id' ) );
		}

		if ( ! empty( $order_id ) ) {
			return wc_get_order( $order_id );
		}

		return false;
	}

	/**
	 * Gets the order by Stripe PaymentIntent ID.
	 *
	 * @since 4.2
	 * @param string $intent_id The ID of the intent.
	 * @return WC_Order|bool Either an order or false when not found.
	 */
	public static function get_order_by_intent_id( $intent_id ) {
		global $wpdb;

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders   = wc_get_orders(
				[
					'limit'      => 1,
					'meta_query' => [
						[
							'key'   => '_wspa_intent_id',
							'value' => $intent_id,
						],
					],
				]
			);
			$order_id = current( $orders ) ? current( $orders )->get_id() : false;
		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $intent_id, '_wspa_intent_id' ) );
		}

		if ( ! empty( $order_id ) ) {
			return wc_get_order( $order_id );
		}

		return false;
	}

	public static function get_order_by_checkout_session($checkout_session) {
		$order_id = $checkout_session['metadata']['order_id'];
		if ( ! empty( $order_id ) ) {
			return wc_get_order( $order_id );
		}

		return false;
	}

	/**
	 * Gets the order by Stripe SetupIntent ID.
	 *
	 * @since 4.3
	 * @param string $intent_id The ID of the intent.
	 * @return WC_Order|bool Either an order or false when not found.
	 */
	public static function get_order_by_setup_intent_id( $intent_id ) {
		global $wpdb;

		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders   = wc_get_orders(
				[
					'limit'      => 1,
					'meta_query' => [
						[
							'key'   => '_stripe_setup_intent',
							'value' => $intent_id,
						],
					],
				]
			);
			$order_id = current( $orders ) ? current( $orders )->get_id() : false;
		} else {
			$order_id = $wpdb->get_var( $wpdb->prepare( "SELECT DISTINCT ID FROM $wpdb->posts as posts LEFT JOIN $wpdb->postmeta as meta ON posts.ID = meta.post_id WHERE meta.meta_value = %s AND meta.meta_key = %s", $intent_id, '_stripe_setup_intent' ) );
		}

		if ( ! empty( $order_id ) ) {
			return wc_get_order( $order_id );
		}

		return false;
	}

	/**
	 * Sanitize and retrieve the shortened statement descriptor concatenated with the order number.
	 *
	 * @param string   $statement_descriptor Shortened statement descriptor.
	 * @param WC_Order $order Order.
	 * @param string   $fallback_descriptor (optional) Fallback of the shortened statement descriptor in case it's blank.
	 * @return string $statement_descriptor Final shortened statement descriptor.
	 */
	public static function get_dynamic_statement_descriptor( $statement_descriptor = '', $order = null, $fallback_descriptor = '' ) {
		$actual_descriptor = ! empty( $statement_descriptor ) ? $statement_descriptor : $fallback_descriptor;
		$prefix            = self::clean_statement_descriptor( $actual_descriptor );
		$suffix            = '';

		if ( empty( $prefix ) ) {
			return '';
		}

		if ( method_exists( $order, 'get_order_number' ) && ! empty( $order->get_order_number() ) ) {
			$suffix = '* #' . $order->get_order_number();
		}

		// Make sure it is limited at 22 characters.
		$statement_descriptor = substr( $prefix . $suffix, 0, 22 );

		return $statement_descriptor;
	}

	/**
	 * Sanitize statement descriptor text.
	 *
	 * Stripe requires max of 22 characters and no special characters.
	 *
	 * @since 4.0.0
	 * @param string $statement_descriptor Statement descriptor.
	 * @return string $statement_descriptor Sanitized statement descriptor.
	 */
	public static function clean_statement_descriptor( $statement_descriptor = '' ) {
		$disallowed_characters = [ '<', '>', '\\', '*', '"', "'", '/', '(', ')', '{', '}' ];

		// Strip any tags.
		$statement_descriptor = strip_tags( $statement_descriptor );

		// Strip any HTML entities.
		// Props https://stackoverflow.com/questions/657643/how-to-remove-html-special-chars .
		$statement_descriptor = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $statement_descriptor );

		// Next, remove any remaining disallowed characters.
		$statement_descriptor = str_replace( $disallowed_characters, '', $statement_descriptor );

		// Trim any whitespace at the ends and limit to 22 characters.
		$statement_descriptor = substr( trim( $statement_descriptor ), 0, 22 );

		return $statement_descriptor;
	}

	/**
	 * Converts a WooCommerce locale to the closest supported by Stripe.js.
	 *
	 * Stripe.js supports only a subset of IETF language tags, if a country specific locale is not supported we use
	 * the default for that language (https://stripe.com/docs/js/appendix/supported_locales).
	 * If no match is found we return 'auto' so Stripe.js uses the browser locale.
	 *
	 * @param string $wc_locale The locale to convert.
	 *
	 * @return string Closest locale supported by Stripe ('auto' if NONE).
	 */
	public static function convert_wc_locale_to_stripe_locale( $wc_locale ) {
		// List copied from: https://stripe.com/docs/js/appendix/supported_locales.
		$supported = [
			'ar',     // Arabic.
			'bg',     // Bulgarian (Bulgaria).
			'cs',     // Czech (Czech Republic).
			'da',     // Danish.
			'de',     // German (Germany).
			'el',     // Greek (Greece).
			'en',     // English.
			'en-GB',  // English (United Kingdom).
			'es',     // Spanish (Spain).
			'es-419', // Spanish (Latin America).
			'et',     // Estonian (Estonia).
			'fi',     // Finnish (Finland).
			'fr',     // French (France).
			'fr-CA',  // French (Canada).
			'he',     // Hebrew (Israel).
			'hu',     // Hungarian (Hungary).
			'id',     // Indonesian (Indonesia).
			'it',     // Italian (Italy).
			'ja',     // Japanese.
			'lt',     // Lithuanian (Lithuania).
			'lv',     // Latvian (Latvia).
			'ms',     // Malay (Malaysia).
			'mt',     // Maltese (Malta).
			'nb',     // Norwegian Bokmål.
			'nl',     // Dutch (Netherlands).
			'pl',     // Polish (Poland).
			'pt-BR',  // Portuguese (Brazil).
			'pt',     // Portuguese (Brazil).
			'ro',     // Romanian (Romania).
			'ru',     // Russian (Russia).
			'sk',     // Slovak (Slovakia).
			'sl',     // Slovenian (Slovenia).
			'sv',     // Swedish (Sweden).
			'th',     // Thai.
			'tr',     // Turkish (Turkey).
			'zh',     // Chinese Simplified (China).
			'zh-HK',  // Chinese Traditional (Hong Kong).
			'zh-TW',  // Chinese Traditional (Taiwan).
		];

		// Stripe uses '-' instead of '_' (used in WordPress).
		$locale = str_replace( '_', '-', $wc_locale );

		if ( in_array( $locale, $supported, true ) ) {
			return $locale;
		}

		// The plugin has been fully translated for Spanish (Ecuador), Spanish (Mexico), and
		// Spanish(Venezuela), and partially (88% at 2021-05-14) for Spanish (Colombia).
		// We need to map these locales to Stripe's Spanish (Latin America) 'es-419' locale.
		// This list should be updated if more localized versions of Latin American Spanish are
		// made available.
		$lowercase_locale                  = strtolower( $wc_locale );
		$translated_latin_american_locales = [
			'es_co', // Spanish (Colombia).
			'es_ec', // Spanish (Ecuador).
			'es_mx', // Spanish (Mexico).
			'es_ve', // Spanish (Venezuela).
		];
		if ( in_array( $lowercase_locale, $translated_latin_american_locales, true ) ) {
			return 'es-419';
		}

		// Finally, we check if the "base locale" is available.
		$base_locale = substr( $wc_locale, 0, 2 );
		if ( in_array( $base_locale, $supported, true ) ) {
			return $base_locale;
		}

		// Default to 'auto' so Stripe.js uses the browser locale.
		return 'auto';
	}

	/**
	 * Checks if this page is a cart or checkout page.
	 *
	 * @since 5.2.3
	 * @return boolean
	 */
	public static function has_cart_or_checkout_on_current_page() {
		return is_cart() || is_checkout();
	}

	/**
	 * Return true if the current_tab and current_section match the ones we want to check against.
	 *
	 * @param string $tab
	 * @param string $section
	 * @return boolean
	 */
	public static function should_enqueue_in_current_tab_section( $tab, $section ) {
		global $current_tab, $current_section;

		if ( ! isset( $current_tab ) || $tab !== $current_tab ) {
			return false;
		}

		if ( ! isset( $current_section ) || $section !== $current_section ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns true if the Stripe JS should be loaded on product pages.
	 *
	 * The critical part here is running the filter to allow merchants to disable Stripe's JS to
	 * improve their store's performance when PRBs are disabled.
	 *
	 * @since 5.8.0
	 * @return boolean True if Stripe's JS should be loaded, false otherwise.
	 */
	public static function should_load_scripts_on_product_page() {
		if ( self::should_load_scripts_for_prb_location( 'product' ) ) {
			return true;
		}

		return apply_filters( 'wc_stripe_load_scripts_on_product_page_when_prbs_disabled', true );
	}


	/**
	 * Adds payment intent id and order note to order if payment intent is not already saved
	 *
	 * @param $payment_intent_id
	 * @param $order
	 */
	public static function add_payment_intent_to_order( $payment_intent_id, $order ) {

		$old_intent_id = $order->get_meta( '_wspa_intent_id' );

		if ( $old_intent_id === $payment_intent_id ) {
			return;
		}

		$order->add_order_note(
			sprintf(
			/* translators: $1%s payment intent ID */
				__( 'Stripe payment intent created (Payment Intent ID: %1$s)', 'woo-pay-addons' ),
				$payment_intent_id
			)
		);

		$order->update_meta_data( '_wspa_intent_id', $payment_intent_id );
		$order->save();
	}

	/**
	 * Adds a source or payment method argument to the request array depending on what sort of
	 * payment method ID is provided. If ID is neither a source or a payment method ID then nothing
	 * is added.
	 *
	 * @param string $payment_method_id  The payment method ID that should be added to the request array.
	 * @param array $request             The request representing the arguments that will be sent in the request.
	 *
	 * @return array  The updated request array.
	 */
	public static function add_payment_method_to_request_array( string $payment_method_id, array $request ): array {
		if ( 0 === strpos( $payment_method_id, 'src_' ) ) {
			$request['source'] = $payment_method_id;
		} elseif ( 0 === strpos( $payment_method_id, 'pm_' ) ) {
			$request['payment_method'] = $payment_method_id;
		}

		return $request;
	}

	/**
	 * Evaluates whether the object passed to this function is a Stripe Payment Method.
	 *
	 * @param stdClass $object  The object that should be evaluated.
	 * @return bool             Returns true if the object is a Payment Method; false otherwise.
	 */
	public static function is_payment_method_object( stdClass $payment_method ): bool {
		return isset( $payment_method->object ) && 'payment_method' === $payment_method->object;
	}

	/**
	 * Evaluates whether a given Stripe Source (or Stripe Payment Method) is reusable.
	 * Payment Methods are always reusable; Sources are only reusable when the appropriate
	 * usage metadata is provided.
	 *
	 * @param stdClass $payment_method  The source or payment method to be evaluated.

	 * @return bool  Returns true if the source is reusable; false otherwise.
	 */
	public static function is_reusable_payment_method( stdClass $payment_method ): bool {
		return self::is_payment_method_object( $payment_method ) || ( isset( $payment_method->usage ) && 'reusable' === $payment_method->usage );
	}

	/**
	 * Returns true if the provided payment method is a card, false otherwise.
	 *
	 * @param stdClass $payment_method  The provided payment method object. Can be a Source or a Payment Method.
	 *
	 * @return bool  True if payment method is a card, false otherwise.
	 */
	public static function is_card_payment_method( stdClass $payment_method ): bool {
		if ( ! isset( $payment_method->object ) || ! isset( $payment_method->type ) ) {
			return false;
		}

		if ( 'payment_method' !== $payment_method->object && 'source' !== $payment_method->object ) {
			return false;
		}

		return 'card' === $payment_method->type;
	}

	/**
	 * Returns a source or payment method from a given intent object.
	 *
	 * @param stdClass|object $intent  The intent that contains the payment method.
	 *
	 * @return stdClass|string|null  The payment method if found, null otherwise.
	 */
	public static function get_payment_method_from_intent( $intent ) {
		if ( ! empty( $intent->source ) ) {
			return $intent->source;
		}

		if ( ! empty( $intent->payment_method ) ) {
			return $intent->payment_method;
		}

		return null;
	}
}
