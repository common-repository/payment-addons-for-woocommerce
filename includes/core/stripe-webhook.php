<?php

namespace Woo_Stripe_Pay_Addons\Core;

use Woo_Stripe_Pay_Addons\Shared\Logger;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class Webhook_Handler extends Abstract_Payment_Gateway {
	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * The secret to use when verifying webhooks.
	 *
	 * @var string
	 */
	protected $secret;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 * @version 5.0.0
	 */
	public function __construct() {
	}

	/**
	 * Process webhook capture. This is used for an authorized only
	 * transaction that is later captured via Stripe not WC.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function process_webhook_capture( $notification ) {
		$order = Stripe_Helper::get_order_by_charge_id( $notification->data->object->id );

		if ( ! $order ) {
			Logger::info( 'Could not find order via charge ID: ' . $notification->data->object->id );
			return;
		}

		if ( 'stripe' === $order->get_payment_method() ) {
			$charge   = $order->get_transaction_id();
			$captured = $order->get_meta( '_stripe_charge_captured', true );

			if ( $charge && 'no' === $captured ) {
				$order->update_meta_data( '_stripe_charge_captured', 'yes' );

				// Store other data such as fees
				$order->set_transaction_id( $notification->data->object->id );

				if ( isset( $notification->data->object->balance_transaction ) ) {
					$this->update_fees( $order, $notification->data->object->balance_transaction );
				}

				// Check and see if capture is partial.
				if ( $this->is_partial_capture( $notification ) ) {
					$partial_amount = $this->get_partial_amount_to_charge( $notification );
					$order->set_total( $partial_amount );
					$refund_object = $this->get_refund_object( $notification );
					$this->update_fees( $order, $refund_object->balance_transaction );
					/* translators: partial captured amount */
					$order->add_order_note( sprintf( __( 'This charge was partially captured via Stripe Dashboard in the amount of: %s', 'woo-pay-addons' ), $partial_amount ) );
				} else {
					$order->payment_complete( $notification->data->object->id );

					/* translators: transaction id */
					$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woo-pay-addons' ), $notification->data->object->id ) );
				}

				if ( is_callable( [ $order, 'save' ] ) ) {
					$order->save();
				}
			}
		}
	}

	/**
	 * Process webhook charge succeeded. This is used for payment methods
	 * that takes time to clear which is asynchronous. e.g. SEPA, Sofort.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function process_webhook_charge_succeeded( $notification ) {
		// The following payment methods are synchronous so does not need to be handle via webhook.
		if ( ( isset( $notification->data->object->source->type ) && 'card' === $notification->data->object->source->type ) || ( isset( $notification->data->object->source->type ) && 'three_d_secure' === $notification->data->object->source->type ) ) {
			return;
		}

		$order = Stripe_Helper::get_order_by_charge_id( $notification->data->object->id );

		if ( ! $order ) {
			Logger::info( 'Could not find order via charge ID: ' . $notification->data->object->id );
			return;
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		// When the plugin's "Issue an authorization on checkout, and capture later"
		// setting is enabled, Stripe API still sends a "charge.succeeded" webhook but
		// the payment has not been captured, yet. This ensures that the payment has been
		// captured, before completing the payment.
		if ( ! $notification->data->object->captured ) {
			return;
		}

		// Store other data such as fees
		$order->set_transaction_id( $notification->data->object->id );

		if ( isset( $notification->data->object->balance_transaction ) ) {
			$this->update_fees( $order, $notification->data->object->balance_transaction );
		}

		$order->payment_complete( $notification->data->object->id );

		/* translators: transaction id */
		$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woo-pay-addons' ), $notification->data->object->id ) );

		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}
	}

	/**
	 * Process webhook charge failed.
	 *
	 * @since 4.0.0
	 * @since 4.1.5 Can handle any fail payments from any methods.
	 * @param object $notification
	 */
	public function process_webhook_charge_failed( $notification ) {
		$order = Stripe_Helper::get_order_by_charge_id( $notification->data->object->id );

		if ( ! $order ) {
			Logger::info( 'Could not find order via charge ID: ' . $notification->data->object->id );
			return;
		}

		// If order status is already in failed status don't continue.
		if ( $order->has_status( 'failed' ) ) {
			return;
		}

		$message = __( 'This payment failed to clear.', 'woo-pay-addons' );
		if ( ! $order->get_meta( '_stripe_status_final', false ) ) {
			$order->update_status( 'failed', $message );
		} else {
			$order->add_order_note( $message );
		}

		do_action( 'wspa_gateway_stripe_process_webhook_payment_error', $order, $notification );
	}

	/**
	 * Process webhook refund.
	 *
	 * @since 4.0.0
	 * @version 4.9.0
	 * @param object $notification
	 */
	public function process_webhook_refund( $notification ) {
		$order = Stripe_Helper::get_order_by_charge_id( $notification->data->object->id );

		if ( ! $order ) {
			Logger::info( 'Could not find order via charge ID: ' . $notification->data->object->id );
			return;
		}

		$order_id = $order->get_id();

		if ( 'stripe' === $order->get_payment_method() ) {
			$charge        = $order->get_transaction_id();
			$captured      = $order->get_meta( '_stripe_charge_captured' );
			$refund_id     = $order->get_meta( '_stripe_refund_id' );
			$currency      = $order->get_currency();
			$refund_object = $this->get_refund_object( $notification );
			$raw_amount    = $refund_object->amount;

			if ( ! in_array( strtolower( $currency ), Stripe_Helper::no_decimal_currencies(), true ) ) {
				$raw_amount /= 100;
			}

			$amount = wc_price( $raw_amount, [ 'currency' => $currency ] );

			// If charge wasn't captured, skip creating a refund.
			if ( 'yes' !== $captured ) {
				// If the process was initiated from wp-admin,
				// the order was already cancelled, so we don't need a new note.
				if ( 'cancelled' !== $order->get_status() ) {
					/* translators: amount (including currency symbol) */
					$order->add_order_note( sprintf( __( 'Pre-Authorization for %s voided from the Stripe Dashboard.', 'woo-pay-addons' ), $amount ) );
					$order->update_status( 'cancelled' );
				}

				return;
			}

			// If the refund ID matches, don't continue to prevent double refunding.
			if ( $refund_object->id === $refund_id ) {
				return;
			}

			if ( $charge ) {
				$reason = __( 'Refunded via Stripe Dashboard', 'woo-pay-addons' );

				// Create the refund.
				$refund = wc_create_refund(
					[
						'order_id' => $order_id,
						'amount'   => $this->get_refund_amount( $notification ),
						'reason'   => $reason,
					]
				);

				if ( is_wp_error( $refund ) ) {
					Logger::info( $refund->get_error_message() );
				}

				$order->update_meta_data( '_stripe_refund_id', $refund_object->id );

				if ( isset( $refund_object->balance_transaction ) ) {
					$this->update_fees( $order, $refund_object->balance_transaction );
				}

				/* translators: 1) amount (including currency symbol) 2) transaction id 3) refund message */
				$order->add_order_note( sprintf( __( 'Refunded %1$s - Refund ID: %2$s - %3$s', 'woo-pay-addons' ), $amount, $refund_object->id, $reason ) );
			}
		}
	}

	/**
	 * Process a refund update.
	 *
	 * @param object $notification
	 */
	public function process_webhook_refund_updated( $notification ) {
		$refund_object = $notification->data->object;
		$order         = Stripe_Helper::get_order_by_charge_id( $refund_object->charge );

		if ( ! $order ) {
			Logger::info( 'Could not find order to update refund via charge ID: ' . $refund_object->charge );
			return;
		}

		$order_id = $order->get_id();

		if ( 'stripe' === $order->get_payment_method() ) {
			$charge     = $order->get_transaction_id();
			$refund_id  = $order->get_meta( '_stripe_refund_id' );
			$currency   = $order->get_currency();
			$raw_amount = $refund_object->amount;

			if ( ! in_array( strtolower( $currency ), Stripe_Helper::no_decimal_currencies(), true ) ) {
				$raw_amount /= 100;
			}

			$amount = wc_price( $raw_amount, [ 'currency' => $currency ] );

			// If the refund IDs do not match stop.
			if ( $refund_object->id !== $refund_id ) {
				return;
			}

			if ( $charge ) {
				$refunds = wc_get_orders(
					[
						'limit'  => 1,
						'parent' => $order_id,
					]
				);

				if ( empty( $refunds ) ) {
					// No existing refunds nothing to update.
					return;
				}

				$refund = $refunds[0];

				if ( in_array( $refund_object->status, [ 'failed', 'canceled' ], true ) ) {
					if ( isset( $refund_object->failure_balance_transaction ) ) {
						$this->update_fees( $order, $refund_object->failure_balance_transaction );
					}
					$refund->delete( true );
					do_action( 'woocommerce_refund_deleted', $refund_id, $order_id );
					if ( 'failed' === $refund_object->status ) {
						/* translators: 1) amount (including currency symbol) 2) transaction id 3) refund failure code */
						$note = sprintf( __( 'Refund failed for %1$s - Refund ID: %2$s - Reason: %3$s', 'woo-pay-addons' ), $amount, $refund_object->id, $refund_object->failure_reason );
					} else {
						/* translators: 1) amount (including currency symbol) 2) transaction id 3) refund failure code */
						$note = sprintf( __( 'Refund canceled for %1$s - Refund ID: %2$s - Reason: %3$s', 'woo-pay-addons' ), $amount, $refund_object->id, $refund_object->failure_reason );
					}

					$order->add_order_note( $note );
				}
			}
		}
	}

	/**
	 * Checks if capture is partial.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function is_partial_capture( $notification ) {
		return 0 < $notification->data->object->amount_refunded;
	}

	/**
	 * Gets the first refund object from charge notification.
	 *
	 * @since 7.0.2
	 * @param object $notification
	 *
	 * @return object
	 */
	public function get_refund_object( $notification ) {
		// Since API version 2022-11-15, the Charge object no longer expands `refunds` by default.
		// We can remove this once we drop support for API versions prior to 2022-11-15.
		if ( ! empty( $notification->data->object->refunds->data[0] ) ) {
			return $notification->data->object->refunds->data[0];
		}

		$charge = $this->get_charge_object( $notification->data->object->id, [ 'expand' => [ 'refunds' ] ] );
		return $charge->refunds->data[0];
	}

	/**
	 * Gets the amount refunded.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function get_refund_amount( $notification ) {
		if ( $this->is_partial_capture( $notification ) ) {
			$refund_object = $this->get_refund_object( $notification );
			$amount        = $refund_object->amount / 100;

			if ( in_array( strtolower( $notification->data->object->currency ), Stripe_Helper::no_decimal_currencies() ) ) {
				$amount = $refund_object->amount;
			}

			return $amount;
		}

		return false;
	}

	/**
	 * Gets the amount we actually charge.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function get_partial_amount_to_charge( $notification ) {
		if ( $this->is_partial_capture( $notification ) ) {
			$amount = ( $notification->data->object->amount - $notification->data->object->amount_refunded ) / 100;

			if ( in_array( strtolower( $notification->data->object->currency ), Stripe_Helper::no_decimal_currencies() ) ) {
				$amount = ( $notification->data->object->amount - $notification->data->object->amount_refunded );
			}

			return $amount;
		}

		return false;
	}

	/**
	 * Process webhook charge succeeded. This is used for payment methods
	 * that takes time to clear which is asynchronous. e.g. SEPA, Sofort.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param object $notification
	 */
	public function process_charge_succeeded( $notification ) {
		// The following payment methods are synchronous so does not need to be handle via webhook.
		if ( ( isset( $notification->data->object->source->type ) && 'card' === $notification->data->object->source->type ) || ( isset( $notification->data->object->source->type ) && 'three_d_secure' === $notification->data->object->source->type ) ) {
			return;
		}

		$order = Stripe_Helper::get_order_by_charge_id( $notification->data->object->id );

		if ( ! $order ) {
			Stripe_Logger::log( 'Could not find order via charge ID: ' . $notification->data->object->id );
			return;
		}

		if ( ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		if ( ! $notification->data->object->captured ) {
			return;
		}

		// Store other data such as fees
		$order->set_transaction_id( $notification->data->object->id );

		if ( isset( $notification->data->object->balance_transaction ) ) {
			$this->update_fees( $order, $notification->data->object->balance_transaction );
		}

		$order->payment_complete( $notification->data->object->id );

		/* translators: transaction id */
		$order->add_order_note( sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woocommerce-gateway-stripe' ), $notification->data->object->id ) );

		if ( is_callable( [ $order, 'save' ] ) ) {
			$order->save();
		}
	}

	public function append_session_checkout_metadata($order_id, &$session_checkout) {
		if(!$session_checkout->subscription) return;

		method_exists($this, 'append_subscription_id_metadata') && $this->append_subscription_id_metadata($order_id, $session_checkout);
	}

	public function process_checkout_session_success( $notification ) {

		$cs = $notification->data->object;
		$order = Stripe_Helper::get_order_by_checkout_session($cs);
		if ( ! $order ) {
			Logger::info( 'Could not find order via checkout_session ID: ' . $cs->id );
			return;
		}

		if ( ! $order->has_status(
			apply_filters(
				'wspa_allowed_payment_processing_statuses',
				[ 'pending', 'failed', 'on-hold' ],
				$order
				)
				) ) {
			Logger::info( 'lock_order_payment checkout session ID: ', $order->get_status() );
			return;
		}

		$order_id           = $order->get_id();

		$session_checkout = Stripe_API::request(
      null,
      'checkout/sessions/' . $cs->id . '?expand[]=payment_intent&expand[]=invoice.payment_intent',
      'GET'
    );

		if($session_checkout->amount_total === 0) {
			$order->payment_complete( $session_checkout->id );

			// append stripe sub id to order
			$this->append_session_checkout_metadata( $order_id, $session_checkout );

			/* translators: transaction id */
			$message = sprintf( __( 'Stripe charge complete (Charge ID: %s)', 'woo-pay-addons' ), $session_checkout->id );
			$order->add_order_note( $message );
			return;
    }

    $intent = $session_checkout->payment_intent ?? $session_checkout->invoice->payment_intent;

		switch ( $notification->type ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$charge = $this->get_latest_charge_from_intent( $intent );

				Logger::info( "Stripe PaymentIntent $intent->id succeeded for order $order_id" );

				do_action( 'wsap_gateway_stripe_process_payment', $charge, $order );

				// append stripe sub id to order
				$this->append_session_checkout_metadata( $order_id, $session_checkout );

				// Process valid response.
				$this->process_response( $charge, $order );
				break;
			default:
				$error_message = $intent->last_payment_error ? $intent->last_payment_error->message : '';

				/* translators: 1) The error message that was received from Stripe. */
				$message = sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'woo-pay-addons' ), $error_message );

				if ( ! $order->get_meta( '_stripe_status_final', false ) ) {
					$order->update_status( 'failed', $message );
				} else {
					$order->add_order_note( $message );
				}

				do_action( 'wspa_gateway_stripe_process_webhook_payment_error', $order, $notification );

				$this->send_failed_order_email( $order_id );
				break;
		}
	}

	public function process_payment_intent_success( $notification ) {
		$intent = $notification->data->object;
		$order  = Stripe_Helper::get_order_by_intent_id( $intent->id );
		if ( ! $order ) {
			Logger::info( 'Could not find order via intent ID: ' . $intent->id );
			return;
		}

		if ( ! $order->has_status(
			apply_filters(
				'wspa_allowed_payment_processing_statuses',
				[ 'pending', 'failed', 'on-hold' ],
				$order
				)
				) ) {
			Logger::info( 'lock_order_payment intent ID: ', $order->get_status() );
			return;
		}
		if ( $this->lock_order_payment( $order, $intent ) ) {
			return;
		}

		$order_id           = $order->get_id();

		switch ( $notification->type ) {
			case 'payment_intent.requires_action':
				break;
			case 'payment_intent.succeeded':
			case 'payment_intent.amount_capturable_updated':
				$charge = $this->get_latest_charge_from_intent( $intent );

				Logger::info( "Stripe PaymentIntent $intent->id succeeded for order $order_id" );

				do_action( 'wspa_gateway_stripe_process_payment', $charge, $order );

				// Process valid response.
				$this->process_response( $charge, $order );
				break;
			default:
				$error_message = $intent->last_payment_error ? $intent->last_payment_error->message : '';

				/* translators: 1) The error message that was received from Stripe. */
				$message = sprintf( __( 'Stripe SCA authentication failed. Reason: %s', 'woo-pay-addons' ), $error_message );

				if ( ! $order->get_meta( '_stripe_status_final', false ) ) {
					$order->update_status( 'failed', $message );
				} else {
					$order->add_order_note( $message );
				}

				do_action( 'wspa_gateway_stripe_process_webhook_payment_error', $order, $notification );

				$this->send_failed_order_email( $order_id );
				break;
		}

		$this->unlock_order_payment( $order );
	}

	public function process_invoice_success( $notification ) {
		$invoice = $notification->data->object;
		$order  = Stripe_Helper::get_order_by_intent_id( $invoice->payment_intent );
		if ( ! $order ) {
			Logger::info( 'Could not find order via intent ID: ' . $invoice->payment_intent );
			return;
		}
		switch ( $notification->type ) {
			case 'invoice.paid':
				if(!empty($invoice->subscription) && class_exists('WC_Subscriptions_Manager')) {
					Logger::info('subscription process successfully via ' . $order->id);
					\WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
				}
				break;
			case 'invoice.payment_failed':
				if(!empty($invoice->subscription) && class_exists('WC_Subscriptions_Manager')) {
					Logger::info('subscription process failed via ' . $order->id);
					\WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
				}
				break;
		}
	}

	/**
	 * Processes the incoming webhook.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param string $request_body
	 */
	public function process_webhook( $notification ) {
		switch ( $notification->type ) {
			case 'charge.succeeded':
				$this->process_webhook_charge_succeeded( $notification );
				break;
			case 'charge.failed':
				$this->process_webhook_charge_failed( $notification );
				break;

			case 'charge.captured':
				$this->process_webhook_capture( $notification );
				break;
			case 'charge.refunded':
				$this->process_webhook_refund( $notification );
				break;

			case 'charge.refund.updated':
				$this->process_webhook_refund_updated( $notification );
				break;

			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
			case 'checkout.session.async_payment_failed':
			case 'checkout.session.expired':
				$this->process_checkout_session_success( $notification );
				break;

			case 'payment_intent.succeeded':
			case 'payment_intent.payment_failed':
			case 'payment_intent.amount_capturable_updated':
			case 'payment_intent.requires_action':
				$this->process_payment_intent_success( $notification );
				break;
			case 'invoice.paid':
			case 'invoice.payment_failed':
				$this->process_invoice_success( $notification );
				break;
		}
	}
}
