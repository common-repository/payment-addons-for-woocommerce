<?php

namespace Woo_Stripe_Pay_Addons\Shared;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Woo_Stripe_Pay_Addons\core\Stripe_Helper; 

class order_helper {

  static function build_display_items( $itemized_display_items = false ) {
		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		$line_items = self::build_order_line_items($itemized_display_items);
		
		$items = [];

		foreach ($line_items as $line_item) {
			$items []= [
				'name' => $line_item['price_data']['product_data']['name'],
				'amount' => $line_item['price_data']['unit_amount'] * $line_item['quantity'],
			];
		}

		return $items;
	}

	public static function build_order_line_items($itemized_display_items = false) {
		$display_items = ! apply_filters( 'wspa_stripe_payment_request_hide_itemization', true ) || $itemized_display_items;
		if(!$display_items) {
			return self::create_subtotal_line_items();
		}

		$line_items = [];
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$line_items [] = order_helper::create_normal_line_item($cart_item);	
		}

		$line_items [] = order_helper::create_shipping_line_item();
		$line_items [] = order_helper::create_tax_line_item();
		$line_items = array_values(array_filter($line_items));
		return $line_items;
	}

	static function get_discount_per_item($cart_item) {
		$item_subtotal = $cart_item['line_subtotal'];
		$item_total    = $cart_item['line_total'];

		if ( !empty($cart_item['data']) && $cart_item['data']->is_taxable() &&  WC()->cart->display_prices_including_tax() ) {
				$item_subtotal += $cart_item['line_subtotal_tax'];
				$item_total    += $cart_item['line_tax'];
		}
		$quantity = $cart_item['quantity'];
		$discounts = $item_subtotal - $item_total;
		return wc_format_decimal( $discounts / $quantity, WC()->cart->dp );
	}

	static function create_session_line_item($item) {
		$currency = get_woocommerce_currency();
		return [
			'price_data' => [
				'currency' => strtolower($currency),
				'unit_amount' => Stripe_Helper::get_stripe_amount($item['amount']),
				'product_data' => [
					'name' => $item['name'],
				]
			],
			'quantity' => $item['quantity']??1
		];
	}

	static function create_normal_line_item($cart_item) {
		$product = $cart_item['data'];

		$line_item = self::create_session_line_item(
			self::get_formatted_line_item($cart_item)
		);

		$product_image  = wp_get_attachment_url( $product->get_image_id() );
		if($product_image) {
			$line_item['price_data']['product_data']['images'] = [ $product_image ];
		}
		$tax_code = $cart_item['data']->get_attribute('tax_code');
		if($tax_code) {
			$line_item['price_data']['product_data']['tax_code'] = $tax_code;	
		}
		return $line_item;
	}

	static function get_formatted_line_item($cart_item) {
		$product = $cart_item['data'];
		$include_tax = $product->is_taxable() &&  WC()->cart->display_prices_including_tax();
		$product_price = self::get_product_price_after_discount($cart_item, $include_tax);

		$subtotal_with_discount = $cart_item['line_total'] + ($include_tax ? $cart_item['line_tax'] : 0);
		$can_split_price = floatval($product_price) * $cart_item['quantity'] === $subtotal_with_discount;
		$quantity_label = 1 < $cart_item['quantity'] ? ' (x' . $cart_item['quantity'] . ')' : '';
		
		return $can_split_price ? [
			'name'	=> $product->get_name(),
			'amount' => $product_price,
			'quantity' => $cart_item['quantity'],
		] : [
			'name'	=> $product->get_name() . $quantity_label,
			'amount' => $subtotal_with_discount,
			'quantity' => 1,
		];
	}

	static function get_product_price_after_discount($cart_item, $include_tax = false) {
		$line_total = $cart_item['line_total'] + ($include_tax ? $cart_item['line_tax'] : 0);
		return wc_format_decimal($line_total / $cart_item['quantity']);
	}

	static function create_subtotal_line_items() {
		$total = wc_format_decimal( WC()->cart->get_cart_contents_total(), WC()->cart->dp );
		$total_tax = wc_format_decimal( WC()->cart->get_cart_contents_tax(), WC()->cart->dp );
		$subtotal = self::create_session_line_item([
			'name' => __( 'Subtotal', 'woo-pay-addons' ),
			'amount' => $total + (self::is_tax_in_price() ? $total_tax : 0),
		]);
		$line_items [] = $subtotal;
		$line_items [] = self::create_shipping_line_item();
		$line_items [] = self::create_tax_line_item();
		$line_items = array_values(array_filter($line_items));
		return $line_items;
	}

	static function create_shipping_line_item() {
		$include_tax_in_price = 'incl' === get_option( 'woocommerce_tax_display_cart' );
		$shipping    = wc_format_decimal( WC()->cart->shipping_total, WC()->cart->dp );

		if ( wc_tax_enabled() && $include_tax_in_price ) {
			$shipping += wc_format_decimal(WC()->cart->shipping_tax_total, WC()->cart->dp );
		}

		return WC()->cart->needs_shipping() && $shipping > 0
			? self::create_session_line_item([
				'name'  => esc_html( __( 'Shipping', 'woo-pay-addons' ) ),
				'amount' => $shipping,
			])
			: null;
	}

	static function create_tax_line_item($exlude_tax = 0) {
		if ( wc_tax_enabled() && !self::is_tax_in_price() ) {
			$tax         = wc_format_decimal( WC()->cart->tax_total + WC()->cart->shipping_tax_total - $exlude_tax, WC()->cart->dp );
			return $tax > 0 ? self::create_session_line_item([
				'name'  => esc_html( __( 'Tax', 'woo-pay-addons' ) ),
				'amount' => $tax,
			]) : null;
		}
		return null;
	}

	static function is_tax_in_price() {
		return 'incl' === get_option( 'woocommerce_tax_display_cart' );
	}
}