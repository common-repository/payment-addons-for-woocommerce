<?php

namespace Woo_Stripe_Pay_Addons\Core;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use \Exception;
use \WP_Error;
use \Woo_Stripe_Pay_Addons\Shared\Logger;

class Stripe_API {

	/**
	 * Stripe API Endpoint
	 */
	const ENDPOINT           = 'https://api.stripe.com/v1/';
	const STRIPE_API_VERSION = '2022-08-01';

  public static function get_user_agent() {
		$app_info = [
			'name'       => WSPA_PLUGIN_NAME,
			'version'    => WSPA_PLUGIN_VERSION,
			'url'        => WSPA_PLUGIN_URL,
		];

		return [
			'lang'         => 'php',
			'lang_version' => phpversion(),
			'publisher'    => 'payaddons',
			'uname'        => function_exists( 'php_uname' ) ? php_uname() : PHP_OS,
			'application'  => $app_info,
		];
	}

	/**
	 * Generates the headers to pass to API request.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public static function get_headers() {
    $user_agent = self::get_user_agent();
		$app_info   = $user_agent['application'];

		$headers = apply_filters(
			'wspa_stripe_request_headers',
			[
				'Authorization'  => 'Basic ' . base64_encode( Stripe_Settings::get_secret_key() . ':' ),
				'Stripe-Version' => self::STRIPE_API_VERSION,
			]
		);

    $headers['User-Agent'] = $app_info['name'] . '/' . $app_info['version'] . ' (' . $app_info['url'] . ')';

		return $headers;
	}

	/**
	 * Send the request to Stripe's API
	 *
	 * @since 3.1.0
	 * @version 4.0.6
	 * @param array  $request
	 * @param string $api
	 * @param string $method
	 * @param bool   $with_headers To get the response with headers.
	 * @return stdClass|array
	 * @throws WC_Stripe_Exception
	 */
	public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {
		// Logger::info( "{$api} request: " . print_r( $request, true ) );

		$headers         = self::get_headers();
		$idempotency_key = '';

		if ( 'charges' === $api && 'POST' === $method ) {
			$customer        = ! empty( $request['customer'] ) ? $request['customer'] : '';
			$source          = ! empty( $request['source'] ) ? $request['source'] : $customer;
			$idempotency_key = apply_filters( 'wspa_stripe_idempotency_key', $request['metadata']['order_id'] . '-' . $source, $request );

			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_safe_remote_post(
			self::ENDPOINT . $api,
			[
				'method'  => $method,
				'headers' => $headers,
				'body'    => apply_filters( 'wspa_stripe_request_body', $request, $api ),
				'timeout' => 70,
			]
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			Logger::info(
				'Error Response: ' . print_r( $response, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
					[
						'api'             => $api,
						'request'         => $request,
						'idempotency_key' => $idempotency_key,
					],
					true
				)
			);

			throw new Exception(  __( 'There was a problem connecting to the Stripe API endpoint.', 'woo-pay-addons' ) );
		}

		if ( $with_headers ) {
			return [
				'headers' => wp_remote_retrieve_headers( $response ),
				'body'    => json_decode( $response['body'] ),
			];
		}

		return json_decode( $response['body'] );
	}

	/**
	 * Retrieve API endpoint.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 * @param string $api
	 */
	public static function retrieve( $api ) {
		Logger::info( "{$api}" );

		$response = wp_safe_remote_get(
			self::ENDPOINT . $api,
			[
				'method'  => 'GET',
				'headers' => self::get_headers(),
				'timeout' => 70,
			]
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			Logger::info( 'Error Response: ' . print_r( $response, true ) );
			return new WP_Error( 'stripe_error', __( 'There was a problem connecting to the Stripe API endpoint.', 'woo-pay-addons' ) );
		}

		return json_decode( $response['body'] );
	}
}
