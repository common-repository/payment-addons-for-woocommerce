<?php

namespace Woo_Stripe_Pay_Addons\Core;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use \Exception;
use \WP_Error;
use \WC_Order;
use \WC_Customer;
use \Woo_Stripe_Pay_Addons\Shared\Logger;

/**
 *
 * Represents a Stripe Customer.
 */
class Stripe_Customer {
	/**
	 * Stripe customer ID
	 *
	 * @var string
	 */
	private $id = '';

	/**
	 * WP User ID
	 *
	 * @var integer
	 */
	private $user_id = 0;

  /**
	 * Data from API
	 *
	 * @var array
	 */
	private $customer_data = [];

	/**
	 * Constructor
	 *
	 * @param int $user_id The WP user ID
	 */
	public function __construct( $user_id = 0 ) {
		if ( $user_id ) {
			$this->set_user_id( $user_id );
			$this->set_id( $this->get_id_from_meta( $user_id ) );
		}
	}

	/**
	 * Get Stripe customer ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Set Stripe customer ID.
	 *
	 * @param [type] $id [description]
	 */
	public function set_id( $id ) {
		// Backwards compat for customer ID stored in array format. (Pre 3.0)
		if ( is_array( $id ) && isset( $id['customer_id'] ) ) {
			$id = $id['customer_id'];

			$this->update_id_in_meta( $id );
		}

		$this->id = wc_clean( $id );
	}

	/**
	 * User ID in WordPress.
	 *
	 * @return int
	 */
	public function get_user_id() {
		return absint( $this->user_id );
	}

	/**
	 * Set User ID used by WordPress.
	 *
	 * @param int $user_id
	 */
	public function set_user_id( $user_id ) {
		$this->user_id = absint( $user_id );
	}

	/**
	 * Get user object.
	 *
	 * @return WP_User
	 */
	protected function get_user() {
		return $this->get_user_id() ? get_user_by( 'id', $this->get_user_id() ) : false;
	}

	/**
	 * Store data from the Stripe API about this customer
	 */
	public function set_customer_data( $data ) {
		$this->customer_data = $data;
	}

	/**
	 * Generates the customer request, used for both creating and updating customers.
	 *
	 * @param  array $args Additional arguments (optional).
	 * @return array
	 */
	protected function generate_customer_request( $args = [] ) {
		$billing_email = isset( $_POST['billing_email'] ) ? filter_var( wp_unslash( $_POST['billing_email'] ), FILTER_SANITIZE_EMAIL ) : '';
		$user          = $this->get_user();

		if ( $user ) {
			$billing_first_name = get_user_meta( $user->ID, 'billing_first_name', true );
			$billing_last_name  = get_user_meta( $user->ID, 'billing_last_name', true );

			// If billing first name does not exists try the user first name.
			if ( empty( $billing_first_name ) ) {
				$billing_first_name = get_user_meta( $user->ID, 'first_name', true );
			}

			// If billing last name does not exists try the user last name.
			if ( empty( $billing_last_name ) ) {
				$billing_last_name = get_user_meta( $user->ID, 'last_name', true );
			}

			// translators: %1$s First name, %2$s Second name, %3$s Username.
			$description = sprintf( __( 'Name: %1$s %2$s, Username: %3$s', 'woo-pay-addons' ), $billing_first_name, $billing_last_name, $user->user_login );

			$defaults = [
				'email'       => $user->user_email,
				'description' => $description,
			];

			$billing_full_name = trim( $billing_first_name . ' ' . $billing_last_name );
			if ( ! empty( $billing_full_name ) ) {
				$defaults['name'] = $billing_full_name;
			}
		} else {
			$billing_first_name = isset( $_POST['billing_first_name'] ) ? filter_var( wp_unslash( $_POST['billing_first_name'] ), FILTER_SANITIZE_STRING ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$billing_last_name  = isset( $_POST['billing_last_name'] ) ? filter_var( wp_unslash( $_POST['billing_last_name'] ), FILTER_SANITIZE_STRING ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

			// translators: %1$s First name, %2$s Second name.
			$description = sprintf( __( 'Name: %1$s %2$s, Guest', 'woo-pay-addons' ), $billing_first_name, $billing_last_name );

			$defaults = [
				'email'       => $billing_email,
				'description' => $description,
			];

			$billing_full_name = trim( $billing_first_name . ' ' . $billing_last_name );
			if ( ! empty( $billing_full_name ) ) {
				$defaults['name'] = $billing_full_name;
			}
		}

		$metadata                      = [];
		$defaults['metadata']          = apply_filters( 'wspa_stripe_customer_metadata', $metadata, $user );
		$defaults['preferred_locales'] = $this->get_customer_preferred_locale( $user );

		return wp_parse_args( $args, $defaults );
	}

	/**
	 * Create a customer via API.
	 *
	 * @param array $args
	 * @return WP_Error|int
	 */
	public function create_customer( $args = [] ) {
		$args     = $this->generate_customer_request( $args );
		$response = Stripe_API::request( apply_filters( 'wspa_stripe_create_customer_args', $args ), 'customers' );

		if ( ! empty( $response->error ) ) {
      Logger::info( "create stripe customer error:" . $response->error->message );
			throw new Exception( $response->error->message );
		}

		$this->set_id( $response->id );
		$this->set_customer_data( $response );

		if ( $this->get_user_id() ) {
			$this->update_id_in_meta( $response->id );
		}

		return $response->id;
	}
	
	/**
	 * Updates the Stripe customer through the API.
	 *
	 * @param array $args     Additional arguments for the request (optional).
	 * @param bool  $is_retry Whether the current call is a retry (optional, defaults to false). If true, then an exception will be thrown instead of further retries on error.
	 *
	 * @return string Customer ID
	 *
	 * @throws WC_Stripe_Exception
	 */
	public function update_customer( $args = [], $is_retry = false ) {
		if ( empty( $this->get_id() ) ) {
			throw new Exception( 'id_required_to_update_user', __( 'Attempting to update a Stripe customer without a customer ID.', 'woo-pay-addons' ) );
		}

		$args     = $this->generate_customer_request( $args );
		$args     = apply_filters( 'wspa_update_customer_args', $args );
		$response = Stripe_API::request( $args, 'customers/' . $this->get_id() );

		if ( ! empty( $response->error ) ) {
			if ( $this->is_no_such_customer_error( $response->error ) && ! $is_retry ) {
				// This can happen when switching the main Stripe account or importing users from another site.
				// If not already retrying, recreate the customer and then try updating it again.
				$this->recreate_customer();
				return $this->update_customer( $args, true );
			}

			throw new Exception( print_r( $response, true ), $response->error->message );
		}

		$this->clear_cache();
		$this->set_customer_data( $response );

		do_action( 'wspa_update_customer', $args, $response );

		return $this->get_id();
	}

	/**
	 * Updates existing Stripe customer or creates new customer for User through API.
	 *
	 * @param array $args     Additional arguments for the request (optional).
	 * @param bool  $is_retry Whether the current call is a retry (optional, defaults to false). If true, then an exception will be thrown instead of further retries on error.
	 *
	 * @return string Customer ID
	 *
	 * @throws WC_Stripe_Exception
	 */
	public function update_or_create_customer( $args = [], $is_retry = false ) {
		if ( empty( $this->get_id() ) ) {
			return $this->recreate_customer();
		} else {
			return $this->update_customer( $args, true );
		}
	}

	public function is_no_such_customer_error( $error ) {
		return (
			$error &&
			'invalid_request_error' === $error->type &&
			preg_match( '/No such customer/i', $error->message )
		);
	}

	/**
	 * Deletes caches for this users cards.
	 */
	public function clear_cache() {
		delete_transient( 'stripe_sources_' . $this->get_id() );
		delete_transient( 'stripe_customer_' . $this->get_id() );
		$this->customer_data = [];
	}

	/**
	 * Retrieves the Stripe Customer ID from the user meta.
	 *
	 * @param  int $user_id The ID of the WordPress user.
	 * @return string|bool  Either the Stripe ID or false.
	 */
	public function get_id_from_meta( $user_id ) {
		return get_user_option( '_stripe_customer_id', $user_id );
	}

	/**
	 * Updates the current user with the right Stripe ID in the meta table.
	 *
	 * @param string $id The Stripe customer ID.
	 */
	public function update_id_in_meta( $id ) {
		update_user_option( $this->get_user_id(), '_stripe_customer_id', $id, false );
	}

	/**
	 * Deletes the user ID from the meta table with the right key.
	 */
	public function delete_id_from_meta() {
		delete_user_option( $this->get_user_id(), '_stripe_customer_id', false );
	}

	/**
	 * Recreates the customer for this user.
	 *
	 * @return string ID of the new Customer object.
	 */
	private function recreate_customer() {
		$this->delete_id_from_meta();
		return $this->create_customer();
	}

	/**
	 * Get the customer's preferred locale based on the user or site setting.
	 *
	 * @param object $user The user being created/modified.
	 * @return array The matched locale string wrapped in an array, or empty default.
	 */
	public function get_customer_preferred_locale( $user ) {
		$locale = $this->get_customer_locale( $user );

		// Options based on Stripe locales.
		// https://support.stripe.com/questions/language-options-for-customer-emails
		$stripe_locales = [
			'ar'    => 'ar-AR',
			'da_DK' => 'da-DK',
			'de_DE' => 'de-DE',
			'en'    => 'en-US',
			'es_ES' => 'es-ES',
			'es_CL' => 'es-419',
			'es_AR' => 'es-419',
			'es_CO' => 'es-419',
			'es_PE' => 'es-419',
			'es_UY' => 'es-419',
			'es_PR' => 'es-419',
			'es_GT' => 'es-419',
			'es_EC' => 'es-419',
			'es_MX' => 'es-419',
			'es_VE' => 'es-419',
			'es_CR' => 'es-419',
			'fi'    => 'fi-FI',
			'fr_FR' => 'fr-FR',
			'he_IL' => 'he-IL',
			'it_IT' => 'it-IT',
			'ja'    => 'ja-JP',
			'nl_NL' => 'nl-NL',
			'nn_NO' => 'no-NO',
			'pt_BR' => 'pt-BR',
			'sv_SE' => 'sv-SE',
		];

		$preferred = isset( $stripe_locales[ $locale ] ) ? $stripe_locales[ $locale ] : 'en-US';
		return [ $preferred ];
	}

	/**
	 * Gets the customer's locale/language based on their setting or the site settings.
	 *
	 * @param object $user The user we're wanting to get the locale for.
	 * @return string The locale/language set in the user profile or the site itself.
	 */
	public function get_customer_locale( $user ) {
		// If we have a user, get their locale with a site fallback.
		return ( $user ) ? get_user_locale( $user->ID ) : get_locale();
	}

	/**
	 * Given a WC_Order or WC_Customer, returns an array representing a Stripe customer object.
	 * At least one parameter has to not be null.
	 *
	 * @param WC_Order    $wc_order    The Woo order to parse.
	 * @param WC_Customer $wc_customer The Woo customer to parse.
	 *
	 * @return array Customer data.
	 */
	public static function map_customer_data( WC_Order $wc_order = null, WC_Customer $wc_customer = null ) {
		if ( null === $wc_customer && null === $wc_order ) {
			return [];
		}

		// Where available, the order data takes precedence over the customer.
		$object_to_parse = isset( $wc_order ) ? $wc_order : $wc_customer;
		$name            = $object_to_parse->get_billing_first_name() . ' ' . $object_to_parse->get_billing_last_name();
		$description     = '';
		if ( null !== $wc_customer && ! empty( $wc_customer->get_username() ) ) {
			// We have a logged in user, so add their username to the customer description.
			// translators: %1$s Name, %2$s Username.
			$description = sprintf( __( 'Name: %1$s, Username: %2$s', 'woo-pay-addons' ), $name, $wc_customer->get_username() );
		} else {
			// Current user is not logged in.
			// translators: %1$s Name.
			$description = sprintf( __( 'Name: %1$s, Guest', 'woo-pay-addons' ), $name );
		}

		$data = [
			'name'        => $name,
			'description' => $description,
			'email'       => $object_to_parse->get_billing_email(),
			'phone'       => $object_to_parse->get_billing_phone(),
			'address'     => [
				'line1'       => $object_to_parse->get_billing_address_1(),
				'line2'       => $object_to_parse->get_billing_address_2(),
				'postal_code' => $object_to_parse->get_billing_postcode(),
				'city'        => $object_to_parse->get_billing_city(),
				'state'       => $object_to_parse->get_billing_state(),
				'country'     => $object_to_parse->get_billing_country(),
			],
		];

		if ( ! empty( $object_to_parse->get_shipping_postcode() ) ) {
			$data['shipping'] = [
				'name'    => $object_to_parse->get_shipping_first_name() . ' ' . $object_to_parse->get_shipping_last_name(),
				'address' => [
					'line1'       => $object_to_parse->get_shipping_address_1(),
					'line2'       => $object_to_parse->get_shipping_address_2(),
					'postal_code' => $object_to_parse->get_shipping_postcode(),
					'city'        => $object_to_parse->get_shipping_city(),
					'state'       => $object_to_parse->get_shipping_state(),
					'country'     => $object_to_parse->get_shipping_country(),
				],
			];
		}

		return $data;
	}
}
