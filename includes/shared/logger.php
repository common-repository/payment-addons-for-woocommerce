<?php

namespace Woo_Stripe_Pay_Addons\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Log all things!
 *
 * @since 1.0.0
 * @version 1.0.0
 */
class Logger {

	public static $logger;
	const WC_LOG_FILENAME = 'woo-pay-addons';

	public static function log( $level, $message, $context = array() ) {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( apply_filters( 'wspa_logging', true, $message ) ) {
			if ( empty( self::$logger ) ) {
				self::$logger = wc_get_logger();
			}

			$sys_settings = get_option('wspa_sys_settings', []);

			if ( empty( $sys_settings ) || isset( $sys_settings['enable_logging'] ) && 'on' !== $sys_settings['enable_logging'] ) {
				return;
			}
			self::$logger->log( $level, $message, array_merge(
				[ 'source' => self::WC_LOG_FILENAME ],
				(array)$context
			));
		}
	}

	public static function info( $message, $context = array() ) {
		self::log('info', $message, $context);
	}

	public static function error( $message, $context = array() ) {
		self::log('error', $message, $context);
	}
}
