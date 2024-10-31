<?php

namespace Woo_Stripe_Pay_Addons\Core;

use \WC_Payment_Gateway;
use \WC_Customer;
use \Exception;
use \WP_Error;
use \Woo_Stripe_Pay_Addons\Shared\Logger;

abstract class Abstract_Payment_Gateway extends WC_Payment_Gateway {

	protected $retry_interval = 1;

  public $testmode;

	/**
	 * Constructor
	 */
	public function __construct() {
    $this->testmode = Stripe_Settings::is_test_mode();
		add_filter( 'woocommerce_payment_gateways', [ $this, 'add_gateway_class' ], 999 );
	}

	public function validate_minimum_order_amount( $order ) {
		if ( $order->get_total() * 100 < Stripe_Helper::get_minimum_amount() ) {
			/* translators: 1) amount (including currency symbol) */
			throw new Exception( 'Did not meet minimum amount', sprintf( __( 'Sorry, the minimum allowed order total is %1$s to use this payment method.', 'woo-pay-addons' ), wc_price( Stripe_Helper::get_minimum_amount() / 100 ) ) );
		}
	}

	/**
	 * Refund a charge.
	 *
	 * @since 3.1.0
	 * @version 4.9.0
	 * @param  int $order_id
	 * @param  float $amount
	 *
	 * @return bool
	 * @throws Exception Throws exception when charge wasn't captured.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$request = [];

		$order_currency = $order->get_currency();
		$captured       = $order->get_meta( '_stripe_charge_captured', true );
		$charge_id      = $order->get_transaction_id();

		if ( ! $charge_id ) {
			return false;
		}

		if ( ! is_null( $amount ) ) {
			$request['amount'] = Stripe_Helper::get_stripe_amount( $amount, $order_currency );
		}

		// If order is only authorized, don't pass amount.
		if ( 'yes' !== $captured ) {
			unset( $request['amount'] );
		}

		if ( $reason ) {
			// Trim the refund reason to a max of 500 characters due to Stripe limits: https://stripe.com/docs/api/metadata.
			if ( strlen( $reason ) > 500 ) {
				$reason = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 450 ) : substr( $reason, 0, 450 );
				// Add some explainer text indicating where to find the full refund reason.
				$reason = $reason . '... [See WooCommerce order page for full text.]';
			}

			$request['metadata'] = [
				'reason' => $reason,
			];
		}

		$request['charge'] = $charge_id;
		Logger::info( "Info: Beginning refund for order {$charge_id} for the amount of {$amount}" );

		$request = apply_filters( 'wsap_stripe_refund_request', $request, $order );

		$intent           = $this->get_intent_from_order( $order );
		$intent_cancelled = false;
		if ( $intent ) {
			// If the order has a Payment Intent pending capture, then the Intent itself must be refunded (cancelled), not the Charge.
			if ( ! empty( $intent->error ) ) {
				$response         = $intent;
				$intent_cancelled = true;
			} elseif ( 'requires_capture' === $intent->status ) {
				$result           = Stripe_API::request(
					[],
					'payment_intents/' . $intent->id . '/cancel'
				);
				$intent_cancelled = true;

				if ( ! empty( $result->error ) ) {
					$response = $result;
				} else {
					$charge   = end( $result->charges->data );
					$response = end( $charge->refunds->data );
				}
			}
		}

		if ( ! $intent_cancelled && 'yes' === $captured ) {
			$response = Stripe_API::request( $request, 'refunds' );
		}

		if ( ! empty( $response->error ) ) {
			Logger::info( 'Error: ' . $response->error->message );

			return new WP_Error(
				'stripe_error',
				sprintf(
					/* translators: %1$s is a stripe error message */
					__( 'There was a problem initiating a refund: %1$s', 'woo-pay-addons' ),
					$response->error->message
				)
			);

		} elseif ( ! empty( $response->id ) ) {
			$formatted_amount = wc_price( $response->amount / 100 );
			if ( in_array( strtolower( $order->get_currency() ), Stripe_Helper::no_decimal_currencies(), true ) ) {
				$formatted_amount = wc_price( $response->amount );
			}

			// If charge wasn't captured, skip creating a refund and cancel order.
			if ( 'yes' !== $captured ) {
				/* translators: amount (including currency symbol) */
				$order->add_order_note( sprintf( __( 'Pre-Authorization for %s voided.', 'woo-pay-addons' ), $formatted_amount ) );
				$order->update_status( 'cancelled' );
				// If amount is set, that means this function was called from the manual refund form.
				if ( ! is_null( $amount ) ) {
					// Throw an exception to provide a custom message on why the refund failed.
					throw new Exception( __( 'The authorization was voided and the order cancelled. Click okay to continue, then refresh the page.', 'woo-pay-addons' ) );
				} else {
					// If refund was initiaded by changing order status, prevent refund without errors.
					return false;
				}
			}

			$order->update_meta_data( '_stripe_refund_id', $response->id );

			if ( isset( $response->balance_transaction ) ) {
				$this->update_fees( $order, $response->balance_transaction );
			}

			/* translators: 1) amount (including currency symbol) 2) transaction id 3) refund message */
			$refund_message = sprintf( __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woo-pay-addons' ), $formatted_amount, $response->id, $reason );

			$order->add_order_note( $refund_message );
			Logger::info( 'Success: ' . html_entity_decode( wp_strip_all_tags( $refund_message ) ) );

			return true;
		}
	}

	/**
	 * Store extra meta data for an order from a Stripe Response.
	 */
	public function process_response( $response, $order ) {
		$order_id = $order->get_id();
		$captured = ( isset( $response->captured ) && $response->captured ) ? 'yes' : 'no';

		// Store charge data.
		$order->update_meta_data( '_stripe_charge_captured', $captured );

		if ( isset( $response->balance_transaction ) ) {
			$this->update_fees( $order, is_string( $response->balance_transaction ) ? $response->balance_transaction : $response->balance_transaction->id );
		}

		if ( isset( $response->payment_method_details->card->mandate ) ) {
			$order->update_meta_data( '_stripe_mandate_id', $response->payment_method_details->card->mandate );
		}

		if ( 'yes' === $captured ) {
			/**
			 * Charge can be captured but in a pending state. Payment methods
			 * that are asynchronous may take couple days to clear. Webhook will
			 * take care of the status changes.
			 */
			if ( 'pending' === $response->status ) {
				$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

				if ( ! $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $response->id );
				/* translators: transaction id */
				$order->update_status( 'on-hold', sprintf( __( 'Stripe charge awaiting payment: %s.', 'woo-pay-addons' ), $response->id ) );
			}

			if ( 'succeeded' === $response->status ) {
				Logger::info( sprintf( __( 'Payment successful Order id - %1s', 'woo-pay-addons' ), $order->get_id() ) );
				$order->payment_complete( $response->id );

				$source_name =  ucfirst( $response->payment_method_details->type );
				$order->add_order_note( __( 'Payment Status: ', 'woo-pay-addons' ) . ucfirst( $response->status ) . ', ' . __( 'Source: Payment is Completed via ', 'woo-pay-addons' ) . $source_name );
			}

			if ( 'failed' === $response->status ) {
				$localized_message = __( 'Payment processing failed. Please retry.', 'woo-pay-addons' );
				$order->add_order_note( $localized_message );
				throw new Exception( print_r( $response, true ), $localized_message );
			}
		} else {
			$order->set_transaction_id( $response->id );

			if ( $order->has_status( [ 'pending', 'failed' ] ) ) {
				wc_reduce_stock_levels( $order_id );
			}

			/* translators: transaction id */
			$order->update_status( 'on-hold', sprintf( __( 'Stripe charge authorized (Charge ID: %s). Process order to take payment, or cancel to remove the pre-authorization. Attempting to refund the order in part or in full will release the authorization and cancel the payment.', 'woo-pay-addons' ), $response->id ) );
		}

		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}

		do_action( 'woo_pay_addons_process_response', $response, $order );

		return $response;
	}

	public function send_failed_order_email( $order_id ) {
		$emails = WC()->mailer()->get_emails();
		if ( ! empty( $emails ) && ! empty( $order_id ) ) {
			$emails['WC_Email_Failed_Order']->trigger( $order_id );
		}
	}

		/**
	 * Locks an order for payment intent processing for 5 minutes.
	 *
	 * @since 4.2
	 * @param WC_Order $order  The order that is being paid.
	 * @param stdClass $intent The intent that is being processed.
	 * @return bool            A flag that indicates whether the order is already locked.
	 */
	public function lock_order_payment( $order, $intent = null ) {
		$order_id       = $order->get_id();
		$transient_name = 'wc_stripe_processing_intent_' . $order_id;
		$processing     = get_transient( $transient_name );

		// Block the process if the same intent is already being handled.
		if ( '-1' === $processing || ( isset( $intent->id ) && $processing === $intent->id ) ) {
			return true;
		}

		// Save the new intent as a transient, eventually overwriting another one.
		set_transient( $transient_name, empty( $intent ) ? '-1' : $intent->id, 5 * MINUTE_IN_SECONDS );

		return false;
	}

		/**
	 * Unlocks an order for processing by payment intents.
	 *
	 * @since 4.2
	 * @param WC_Order $order The order that is being unlocked.
	 */
	public function unlock_order_payment( $order ) {
		$order_id = $order->get_id();
		delete_transient( 'wc_stripe_processing_intent_' . $order_id );
	}

	public function get_latest_charge_from_intent( $intent ) {
		if ( ! empty( $intent->charges->data ) ) {
			return end( $intent->charges->data );
		} else {
			return $this->get_charge_object( $intent->latest_charge );
		}
	}

	public function is_no_such_customer_error( $error ) {
		return (
			$error &&
			'invalid_request_error' === $error->type &&
			preg_match( '/No such customer/i', $error->message )
		);
	}

	public function maybe_remove_non_existent_customer( $error, $order ) {
		if ( ! $this->is_no_such_customer_error( $error ) ) {
			return false;
		}

		delete_user_option( $order->get_customer_id(), '_stripe_customer_id' );
		$order->delete_meta_data( '_stripe_customer_id' );
		$order->save();

		return true;
	}

	/**
	 * Get charge object by charge ID.
	 *
	 * @since 7.0.2
	 * @param string $charge_id The charge ID to get charge object for.
	 * @param array  $params    The parameters to pass to the request.
	 *
	 * @throws WC_Stripe_Exception Error while retrieving charge object.
	 * @return string|object
	 */
	public function get_charge_object( $charge_id = '', $params = [] ) {
		if ( empty( $charge_id ) ) {
			return '';
		}

		$charge_object = Stripe_API::request( $params, 'charges/' . $charge_id, 'GET' );

		if ( ! empty( $charge_object->error ) ) {
			throw new Exception( print_r( $charge_object, true ), $charge_object->error->message );
		}

		return $charge_object;
	}

	/**
	 * Updates Stripe fees/net.
	 * e.g usage would be after a refund.
	 *
	 * @since 4.0.0
	 * @version 4.0.6
	 * @param object $order The order object
	 * @param int    $balance_transaction_id
	 */
	public function update_fees( $order, $balance_transaction_id ) {
		$balance_transaction = Stripe_API::retrieve( 'balance/history/' . $balance_transaction_id );

		if ( empty( $balance_transaction->error ) ) {
			if ( isset( $balance_transaction ) && isset( $balance_transaction->fee ) ) {
				// Fees and Net needs to both come from Stripe to be accurate as the returned
				// values are in the local currency of the Stripe account, not from WC.
				$fee_refund = ! empty( $balance_transaction->fee ) ? Stripe_Helper::format_balance_fee( $balance_transaction, 'fee' ) : 0;
				$net_refund = ! empty( $balance_transaction->net ) ? Stripe_Helper::format_balance_fee( $balance_transaction, 'net' ) : 0;

				// Current data fee & net.
				$fee_current = Stripe_Helper::get_stripe_fee( $order );
				$net_current = Stripe_Helper::get_stripe_net( $order );

				// Calculation.
				$fee = (float) $fee_current + (float) $fee_refund;
				$net = (float) $net_current + (float) $net_refund;

				Stripe_Helper::update_stripe_fee( $order, $fee );
				Stripe_Helper::update_stripe_net( $order, $net );

				$currency = ! empty( $balance_transaction->currency ) ? strtoupper( $balance_transaction->currency ) : null;
				Stripe_Helper::update_stripe_currency( $order, $currency );

				if ( is_callable( [ $order, 'save' ] ) ) {
					$order->save();
				}
			}
		} else {
			Logger::info( 'Unable to update fees/net meta for order: ' . $order->get_id() );
		}
	}

	public function get_payment_methods() {
		$payment_methods = (array)$this->get_option( 'payment_methods' );
		return array_values(array_filter($payment_methods, function($v) {
			return $v != 'automatic';
		}));
	}

	/**
	 * Checks if the current page is the pay for order page and the current user is allowed to pay for the order.
	 *
	 * @return bool
	 */
	public function is_valid_pay_for_order_endpoint(): bool {

		// If not on the pay for order page, return false.
		if ( ! is_wc_endpoint_url( 'order-pay' ) || ! isset( $_GET['key'] ) ) {
			return false;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		$order    = wc_get_order( $order_id );

		// If the order is not found or the param `key` is not set or the order key does not match the order key in the URL param, return false.
		if ( ! $order || ! isset( $_GET['key'] ) || wc_clean( wp_unslash( $_GET['key'] ) ) !== $order->get_order_key() ) {
			return false;
		}

		// If the order doesn't need payment, we don't need to prepare the payment page.
		if ( ! $order->needs_payment() ) {
			return false;
		}

		return current_user_can( 'pay_for_order', $order->get_id() );
	}

	/**
	* Checks if the current page is the order received page and the current user is allowed to manage the order.
	*
	* @return bool
	*/
 public function is_valid_order_received_endpoint(): bool {
	 // Verify nonce. Duplicated here in order to avoid PHPCS warnings.
	 if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'wspa_stripe_process_redirect_order_nonce' ) ) {
		 return false;
	 }

	 // If not on the order-received page, return false.
	 if ( ! is_wc_endpoint_url( 'order-received' ) || ! isset( $_GET['key'] ) ) {
		 return false;
	 }

	 $order_id_from_order_key = absint( wc_get_order_id_by_order_key( wc_clean( wp_unslash( $_GET['key'] ) ) ) );
	 $order_id_from_query_var = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : null;

	 // If the order ID is not found or the order ID does not match the given order ID, return false.
	 if ( ! $order_id_from_order_key || ( $order_id_from_query_var !== $order_id_from_order_key ) ) {
		 return false;
	 }

	 $order = wc_get_order( $order_id_from_order_key );

	 // If the order doesn't need payment, return false.
	 if ( ! $order->needs_payment() ) {
		 return false;
	 }

	 return current_user_can( 'pay_for_order', $order->get_id() );
 }

	/**
	 * Generates the request when creating a new payment intent.
	 *
	 * @param WC_Order $order           The order that is being paid for.
	 * @param object   $prepared_source The source that is used for the payment.
	 * @return array                    The arguments for the request.
	 */
	public function generate_create_intent_request( $order ) {
		$full_request['description'] = sprintf( __( '%1$s - Order %2$s', 'woo-pay-addons' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
		$billing_email            = $order->get_billing_email();
		$billing_first_name       = $order->get_billing_first_name();
		$billing_last_name        = $order->get_billing_last_name();

		if ( method_exists( $order, 'get_shipping_postcode' ) && ! empty( $order->get_shipping_postcode() ) ) {
			$full_request['shipping'] = [
				'name'    => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'address' => [
					'line1'       => $order->get_shipping_address_1(),
					'line2'       => $order->get_shipping_address_2(),
					'city'        => $order->get_shipping_city(),
					'country'     => $order->get_shipping_country(),
					'postal_code' => $order->get_shipping_postcode(),
					'state'       => $order->get_shipping_state(),
				],
			];
		}

		$metadata = [
			'customer_name' => sanitize_text_field( $billing_first_name ) . ' ' . sanitize_text_field( $billing_last_name ),
			'customer_email' => sanitize_email( $billing_email ),
			'order_id' => $order->get_order_number(),
			'site_url' => esc_url( get_site_url() ),
		];
		$full_request['metadata'] = apply_filters( 'wse_stripe_payment_metadata', $metadata, $order );

		$request = [
			'amount'               => Stripe_Helper::get_stripe_amount( $order->get_total() ),
			'currency'             => strtolower( $order->get_currency() ),
			'description'          => $full_request['description'],
			'metadata'             => $full_request['metadata'],
			'capture_method'       => 'automatic',
			'customer'             => $this->get_customer_id( $order ),
		];

		$methods = $this->get_payment_methods();
		// use auto if express checkout
		if ( count($methods) == 0 || $_POST['express_checkout']) {
			$request['automatic_payment_methods']['enabled'] = 'true';
		}
		else {
			$request['payment_method_types'] = $methods; 
		}

		$payment_method_option = $this->get_payment_method_options($methods);
		if($payment_method_option) {
			$request['payment_method_options'] = $payment_method_option;
		}

		if ( isset( $full_request['statement_descriptor'] ) ) {
			$request['statement_descriptor'] = $full_request['statement_descriptor'];
		}

		// ignore express checkout because shipping will be passed in client side.
		if ( isset( $full_request['shipping'] ) && empty($_POST['express_checkout']) ) {
			$request['shipping'] = $full_request['shipping'];
		}
		if($this->is_saved_cards_enabled() && $this->should_save_payment_method()) {
			$request['setup_future_usage'] = 'off_session';
		}
		

		/**
		 * Filter the return value of the WC_Payment_Gateway_CC::generate_create_intent_request.
		 *
		 * @since 3.1.0
		 * @param array $request
		 * @param WC_Order $order
		 * @param object $source
		 */
		return apply_filters( 'wspa_generate_create_intent_request', $request, $order );
	}

	protected function get_payment_method_options($payment_methods) {
		$country = WC()->countries->get_base_country();

		if(
			in_array($country, [ 'BE', 'DE', 'ES', 'FR', 'IE', 'NL', 'GB', 'JP', 'US'], true) && 
			in_array('customer_balance', $payment_methods, true)
		) {
			$bank_transfer = 'eu_bank_transfer';
			if($country === 'GB') {
				$bank_transfer = 'gb_bank_transfer';
			}
			if($country === 'JP') {
				$bank_transfer = 'jp_bank_transfer';
			}
			if($country === 'US') {
				$bank_transfer = 'us_bank_transfer';
			}

			$payment_method_option = [
				'customer_balance' => [
						'funding_type' => 'bank_transfer',
						'bank_transfer' => [
								'type' => $bank_transfer,
						]
				]
			];
			$payment_method_option['customer_balance']['bank_transfer'][$bank_transfer] = ['country' => $country];
			return $payment_method_option; 
		}
	}

	public function update_existing_intent( $intent, $order ) {
		$request = [];

		$new_amount = Stripe_Helper::get_stripe_amount( $order->get_total() );
		if ( $intent->amount !== $new_amount ) {
			$request['amount'] = $new_amount;
		}
		$methods = $this->get_payment_methods();

		if( $intent->payment_method_types != $methods) {
			$request["payment_method_types"] = $methods;
		}

		if ( empty( $request ) ) {
			return $intent;
		}

		return Stripe_API::request($request, "payment_intents/$intent->id");
	}

  public function is_saved_cards_enabled() {
		return 'yes' === $this->get_option( 'saved_cards' );
	}

	public function should_save_payment_method() {
		$save_payment_method_request_arg = 'wc-' . $this->id . '-new-payment-method';
		if ( ! isset( $_POST[ $save_payment_method_request_arg ] ) ) {
			return false;
		}
		// Save it when the checkout checkbox for saving a payment method was checked off.
		$save_payment_method = wc_clean( wp_unslash( $_POST[ $save_payment_method_request_arg ] ) );

		// Its value is 'true' for classic and '1' for block.
		if ( in_array( $save_payment_method, [ 'true', '1' ], true ) ) {
			return true;
		}
		return isset( $_POST['save_payment_method'] ) ? 'yes' === wc_clean( wp_unslash( $_POST['save_payment_method'] ) ) : false;
	}

  public function save_payment_method_checkbox( $force_checked = false ) {
		$id = 'wc-' . $this->id . '-new-payment-method';
		?>
		<fieldset <?php echo $force_checked ? 'style="display:none;"' : ''; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
			<p class="form-row woocommerce-SavedPaymentMethods-saveNew wspa-save-payment-method">
				<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" type="checkbox" value="true" style="width:auto;" <?php echo $force_checked ? 'checked' : ''; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?> />
				<label for="<?php echo esc_attr( $id ); ?>" style="display:inline;">
					<?php echo esc_html( apply_filters( 'wspa_stripe_save_to_account_text', __( 'Save payment information to my account for future purchases.', 'woo-pay-addons' ) ) ); ?>
				</label>
			</p>
		</fieldset>
		<?php
	}

	public function create_intent( $order ) {
		$request = $this->generate_create_intent_request( $order);

		$intent = Stripe_API::request( $request, 'payment_intents' );
		if ( ! empty( $intent->error ) ) {
				if ( $this->is_no_such_customer_error( $intent->error ) ) {
					$user     = $this->get_user_from_order($order);
					$customer = new Stripe_Customer($user->ID);
					$customer->delete_id_from_meta();
					return $this->create_intent($order);
				}
			return $intent;
		}

		$order_id = $order->get_id();
		Logger::info( "Stripe PaymentIntent $intent->id initiated for order $order_id" );

		Stripe_Helper::add_payment_intent_to_order( $intent->id, $order );

		return $intent;
	}

	public function get_customer_id($order) {
		$user        = wp_get_current_user();
		// Get the user/customer from the order.
		$customer_id = $this->get_stripe_customer_id($order);
		if (!empty($customer_id)) {
			return $customer_id;
		}

		// Update customer or create customer if one does not exist.
		$user     = $this->get_user_from_order($order);
		$customer = new Stripe_Customer($user->ID);

		return $customer->update_or_create_customer();
	}

	/**
	 * Get WC User from WC Order.
	 *
	 * @param WC_Order $order
	 *
	 * @return WP_User
	 */
	public function get_user_from_order( $order ) {
		$user = $order->get_user();
		if ( false === $user ) {
			$user = wp_get_current_user();
		}
		return $user;
	}

	/**
	 * Gets the saved customer id if exists.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function get_stripe_customer_id( $order ) {
		// Try to get it via the order first.
		$customer = $order->get_meta( '_stripe_customer_id', true );

		if ( empty( $customer ) ) {
			$customer = get_user_option( '_stripe_customer_id', $order->get_customer_id() );
		}

		return $customer;
	}

	/**
	 * Retrieves intent from Stripe API by intent id.
	 *
	 * @param string $intent_type   Either 'payment_intents' or 'setup_intents'.
	 * @param string $intent_id     Intent id.
	 * @return object|bool          Either the intent object or `false`.
	 * @throws Exception            Throws exception for unknown $intent_type.
	 */
	private function get_intent( $intent_type, $intent_id ) {
		if ( ! in_array( $intent_type, [ 'payment_intents', 'setup_intents' ], true ) ) {
			throw new Exception( "Failed to get intent of type $intent_type. Type is not allowed" );
		}

		$response = Stripe_API::request( [], "$intent_type/$intent_id?expand[]=payment_method", 'GET' );

		if ( $response && isset( $response->{ 'error' } ) ) {
			$error_response_message = print_r( $response, true );
			Logger::info( "Failed to get Stripe intent $intent_type/$intent_id." );
			Logger::info( "Response: $error_response_message" );
			return false;
		}

		return $response;
	}

		/**
	 * Retrieves the payment intent, associated with an order.
	 *
	 * @since 4.2
	 * @param WC_Order $order The order to retrieve an intent for.
	 * @return obect|bool     Either the intent object or `false`.
	 */
	public function get_intent_from_order( $order ) {
		$intent_id = $order->get_meta( '_wspa_intent_id' );

		if ( $intent_id ) {
			return $this->get_intent( 'payment_intents', $intent_id );
		}

		// The order doesn't have a payment intent, but it may have a setup intent.
		$intent_id = $order->get_meta( '_stripe_setup_intent' );

		if ( $intent_id ) {
			return $this->get_intent( 'setup_intents', $intent_id );
		}

		return false;
	}

  /**
	 * Get WooCommerce currency
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_currency() {
		global $wp;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( absint( $wp->query_vars['order-pay'] ) );

			return $order->get_currency();
		}

		return get_woocommerce_currency();
	}

	public function add_gateway_class( $methods ) {
		array_unshift( $methods, $this );
		return $methods;
	}



  public function get_supported_payment_methods($include_google_apple = false) {
    $currency = strtoupper($this->get_currency());
    $default_methods = [
      'automatic' => __('Automatic collect', 'woo-pay-addons'),
      'card' => __('Card', 'woo-pay-addons'), 
    ];

		if($include_google_apple) {
			$default_methods = array_merge($default_methods, [
				'google_pay' => __('Google Pay', 'woo-pay-addons'), 
				'apple_pay' => __('Apple Pay', 'woo-pay-addons'), 
			]);
		}

    $china_methods = array_merge($default_methods, [
      'alipay' => __('Alipay', 'woo-pay-addons'),
      'wechat_pay' => __('WeChat', 'woo-pay-addons'),
    ]);

		$usd_methods = array_merge($china_methods, [
			'affirm' => __('Affirm', 'woo-pay-addons'), 
			'afterpay_clearpay' => __('Afterpay (Clearpay)', 'woo-pay-addons'),  
      'alipay' => __('Alipay', 'woo-pay-addons'),
      'customer_balance' => __('Bank transfers	', 'woo-pay-addons'),
			'klarna' => __('Klarna', 'woo-pay-addons'), 
      'wechat_pay' => __('WeChat', 'woo-pay-addons'),
			'us_bank_account'=> __('ACH Direct Debit', 'woo-pay-addons'), 
		]);

		$aud_methods = array_merge($default_methods, [
      'alipay' => __('Alipay', 'woo-pay-addons'),
			'au_becs_debit' => __('BECS direct debit', 'woo-pay-addons'),  
			'afterpay_clearpay' => __('Afterpay (Clearpay)', 'woo-pay-addons'),  
			'klarna' => __('Klarna', 'woo-pay-addons'), 
			'paypal' => __('PayPal', 'woo-pay-addons'),
      'wechat_pay' => __('WeChat', 'woo-pay-addons'),
		]);

		$gbp_methods = array_merge($default_methods, [
			'bacs_debit' => __('Bacs Direct Debit', 'woo-pay-addons'),  
			'paypal' => __('PayPal', 'woo-pay-addons'),
		]);

		$sgd_methods = array_merge($default_methods, [
      'alipay' => __('Alipay', 'woo-pay-addons'),
			'grabpay' => __('GrabPay', 'woo-pay-addons'),  
			'paynow' => __('PayNow', 'woo-pay-addons'),  
      'wechat_pay' => __('WeChat', 'woo-pay-addons'),
		]);

		$jpy_methods = array_merge($default_methods, [
      'alipay' => __('Alipay', 'woo-pay-addons'),
			'konbini' => __('Konbini', 'woo-pay-addons'),  
      'wechat_pay' => __('WeChat', 'woo-pay-addons'),
		]);

		$eur_methods = array_merge($default_methods, [
			'alipay' => __('Alipay', 'woo-pay-addons'),
			'bancontact' => __('Bancontact', 'woo-pay-addons'),
      'customer_balance' => __('Bank transfers	', 'woo-pay-addons'),
			'eps' => __('EPS', 'woo-pay-addons'),
			'ideal' => __('iDEAL', 'woo-pay-addons'),
			'giropay' => __('giropay', 'woo-pay-addons'),
			'klarna' => __('Klarna', 'woo-pay-addons'), 
			'p24' => __('P24', 'woo-pay-addons'),
			'paypal' => __('PayPal', 'woo-pay-addons'),
			'sepa_debit' => __('SEPA Direct Debit', 'woo-pay-addons'),
			'sofort' => __('SOFORT', 'woo-pay-addons'),
			'wechat_pay' => __('WeChat', 'woo-pay-addons'),
		]);

		$myr_methods = array_merge($default_methods, [
			'fpx' => __('FPX', 'woo-pay-addons'),
			'grabpay' => __('GrabPay', 'woo-pay-addons'),  
		]);

    $payment_methods = array(
      'USD' => $usd_methods,
      'AUD' => $aud_methods,
      'CAD' => $usd_methods,
      'CNY' => $china_methods,
      'HKD' => $china_methods,
      'SGD' => $sgd_methods,
      'JPY' => $jpy_methods,
			'GBP' => $gbp_methods, 
      'EUR' => $eur_methods, 
      'MYR' => $myr_methods, 
    );

    return $payment_methods[$currency] ?? $default_methods;
  }

	public function get_all_payment_methods() {
		return [
			'automatic' => __('Automatic collect', 'woo-pay-addons'),
			'card' => __('Card', 'woo-pay-addons'),
			'us_bank_account'=> __('ACH Direct Debit', 'woo-pay-addons'),
			'affirm' => __('Affirm', 'woo-pay-addons'),
			'afterpay_clearpay' => __('Afterpay (Clearpay)', 'woo-pay-addons'),
			'alipay' => __('Alipay', 'woo-pay-addons'),
			'apple_pay' => __('Apple Pay', 'woo-pay-addons'),
			'au_becs_debit' => __('BECS direct debit', 'woo-pay-addons'),
			'bacs_debit' => __('Bacs Direct Debit', 'woo-pay-addons'),
			'bancontact' => __('Bancontact', 'woo-pay-addons'),
			'customer_balance' => __('Bank transfers ', 'woo-pay-addons'),
			'eps' => __('EPS', 'woo-pay-addons'),
			'fpx' => __('FPX', 'woo-pay-addons'),
			'giropay' => __('giropay', 'woo-pay-addons'),
			'google_pay' => __('Google Pay', 'woo-pay-addons'),
			'grabpay' => __('GrabPay', 'woo-pay-addons'),
			'ideal' => __('iDEAL', 'woo-pay-addons'),
			'klarna' => __('Klarna', 'woo-pay-addons'),
			'konbini' => __('Konbini', 'woo-pay-addons'),
			'p24' => __('P24', 'woo-pay-addons'),
			'paypal' => __('PayPal', 'woo-pay-addons'),	
			'paynow' => __('PayNow', 'woo-pay-addons'),
			'sepa_debit' => __('SEPA Direct Debit', 'woo-pay-addons'),
			'sofort' => __('SOFORT', 'woo-pay-addons'),
			'wechat_pay' => __('WeChat Pay', 'woo-pay-addons'),
		];
	}

	public function has_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}

	/**
	 * Save the selected payment method information to the order and as a payment token for the user.
	 *
	 * @param WC_Order $order               The WC order for which we're saving the payment method.
	 * @param string   $payment_method_id   The ID of the payment method in Stripe, like `pm_xyz`.
	 */
	public function handle_saving_payment_method( \WC_Order $order, string $payment_method_id ) {
		$payment_method_object = Stripe_API::retrieve( "payment_methods/$payment_method_id" );

		if ($payment_method_object->type !== 'card') {
			return;
		}
		// The payment method couldn't be retrieved from Stripe.
		if ( is_wp_error( $payment_method_object ) ) {
			Logger::error(
				sprintf( 'Error retrieving the selected payment method from Stripe for saving it. ID: %s message: %s.', $payment_method_id, $payment_method_object->get_error_message() )
			);
		}

		$user     = $this->get_user_from_order( $order );
		$customer = new Stripe_Customer( $user->ID );
		$customer->clear_cache();

		// Create a payment token for the user in the store.
		$this->create_payment_token_for_user( $user->ID, $payment_method_object );

		do_action( 'wspa_stripe_add_payment_method', $user->ID, $payment_method_object );
	}

	public function create_payment_token_for_user( $user_id, $payment_method ) {
		$token = new \WC_Payment_Token_CC();
		$token->set_expiry_month( $payment_method->card->exp_month );
		$token->set_expiry_year( $payment_method->card->exp_year );
		$token->set_card_type( strtolower( $payment_method->card->brand ) );
		$token->set_last4( $payment_method->card->last4 );
		$token->set_gateway_id( $this->id );
		$token->set_token( $payment_method->id );
		$token->set_user_id( $user_id );
		$token->save();
		return $token;
	}

	public function is_using_saved_payment_method() {
		$payment_method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : $this->id;

		return ( isset( $_POST[ 'wc-' . $payment_method . '-payment-token' ] ) && 'new' !== $_POST[ 'wc-' . $payment_method . '-payment-token' ] );
	}

	public static function get_token_from_request( array $request ) {
		$payment_method    = ! is_null( $request['payment_method'] ) ? $request['payment_method'] : null;
		$token_request_key = 'wc-' . $payment_method . '-payment-token';
		if (
			! isset( $request[ $token_request_key ] ) ||
			'new' === $request[ $token_request_key ]
			) {
			return null;
		}

		//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$token = \WC_Payment_Tokens::get( wc_clean( $request[ $token_request_key ] ) );

		// If the token doesn't belong to this gateway or the current user it's invalid.
		if ( ! $token || $payment_method !== $token->get_gateway_id() || $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return $token;
	}

	public function handle_verify_intent( $order, $intent ) {
    if ( 'succeeded' === $intent->status && ! $this->is_using_saved_payment_method() ) {
      if ($this->is_saved_cards_enabled() && isset($_GET['save_payment_method']) && $_GET['save_payment_method'] === 'yes') {
        // Handle saving the payment method in the store.
        // It's already attached to the Stripe customer at this point.
        $new_intent = Stripe_API::retrieve("payment_intents/$intent->id");
        $this->handle_saving_payment_method(
          $order,
          $new_intent->payment_method,
        );
      }
    }
    
    if (isset($intent->last_payment_error)) {
      $message = $intent->last_payment_error->message ? $intent->last_payment_error->message : '';
      $code    = isset($intent->last_payment_error->code) ? $intent->last_payment_error->code : '';
      $order->update_status('wc-failed');

      wc_add_notice(sprintf(__('Payment failed. %s', 'woo-pay-addons'), Stripe_Helper::get_localized_messages($code, $message)), 'error');
      wp_safe_redirect(wc_get_checkout_url());
      exit();
    }
    if ( ! empty( $intent ) ) {
			// Use the last charge within the intent to proceed.
			$response = end($intent->charges->data);
      // If the intent requires a 3DS flow, redirect to it.
      if ( 'requires_action' === $intent->status ) {
        $this->unlock_order_payment( $order );
        if ( isset( $intent->next_action->type ) && 'redirect_to_url' === $intent->next_action->type && ! empty( $intent->next_action->redirect_to_url->url ) ) {
					wp_safe_redirect($intent->next_action->redirect_to_url->url);
					exit();
				}
      }
			// if bank transfer
			if(empty($intent->charges->data)) {
				$response = $intent;
			}
    }

    // Process valid response.
    $this->process_response($response, $order);

    // Remove cart.
    if (isset(WC()->cart)) {
      WC()->cart->empty_cart();
    }

    // Unlock the order.
    $this->unlock_order_payment($order);

    wp_safe_redirect($this->get_return_url($order));
    exit();
	}
}