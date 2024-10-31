<?php

namespace Woo_Stripe_Pay_Addons\Admin;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class WooCommerce_Admin
{
  public function __construct()
  {
    add_filter( 'woocommerce_save_settings_checkout_wspa_express_checkout', [ $this, 'wspa_express_checkout_option_updates' ] );
    add_filter('woocommerce_get_settings_checkout', [$this, 'checkout_settings'], 10, 2);
		add_action( 'woocommerce_settings_wspa_express_checkout_after', [$this, 'express_checkout_overview']);
		add_action( 'woocommerce_settings_wspa_express_checkout_title', [$this, 'express_checkout_title_desc']);
  }

	public function express_checkout_overview() {
		?>
		<div class="wspa-express-checkout-preview">
			<img src="<?php echo esc_url(WSPA_ADDONS_URL . 'assets/admin/img/express-checkout.png'); ?>"/>
		</div>
	<?php
	}

	public function express_checkout_title_desc() {
		?>
			<strong>Let your customers use their favorite express payment methods and digital wallets for faster, more secure checkouts across different parts of your store. </strong>
		<?php
	}

  public function wspa_express_checkout_option_updates() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		if ( isset( $_POST['save'] ) ) {
			$express_checkout = [];
			$radio_checkbox   = [
				'express_checkout_enabled'               => 'no',
			];
			foreach ( $_POST as $key => $value ) {
				if ( 0 === strpos( $key, 'wspa_express_checkout' ) ) {
					$k = sanitize_text_field( str_replace( 'wspa_', '', $key ) );
					if ( ! empty( $radio_checkbox ) && in_array( $k, array_keys( $radio_checkbox ), true ) ) {
						$express_checkout[ $k ] = 'yes';
						unset( $radio_checkbox[ $k ] );
					} else {
						if ( is_array( $value ) ) {
							$express_checkout[ $k ] = array_map( 'sanitize_text_field', $value );
						} else {
							$express_checkout[ $k ] = sanitize_text_field( $value );
						}
					}

					unset( $_POST[ $key ] );
				}
			}

			if ( ! empty( $express_checkout ) ) {
				$wspa_stripe                              = get_option( 'woocommerce_wspa_checkout_form_settings' );
				$wspa_stripe                              = array_merge( $wspa_stripe, $radio_checkbox, $express_checkout );
				update_option( 'woocommerce_wspa_checkout_form_settings', $wspa_stripe );
			}
		}

		return false;
	}

  public function checkout_settings($settings, $current_section)
  {
    if ('wspa_express_checkout' === $current_section) {
      $settings = [];
      $values   = self::get_gateway_settings();
     
      if ('no' === $values['enabled']) {
        $settings = [
          'notice' => [
            'title' => '',
            'type'  => 'express_checkout_notice',
            'desc'  => __('Express Checkout is a feature of Checkout Form. Enable Checkout Form to use Express Checkout', 'woo-pay-addons'),
          ],
        ];
      } else {
        $settings = [
          'section_title'               => [
						'name' => __( 'Express Checkout', 'woo-pay-addons' ),
						'type' => 'title',
						/* translators: HTML Markup*/
						'desc' => sprintf( __( '<a href="' . get_site_url() . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wspa_checkout_form" aria-label="Return to payments">Back Checkout Form ⤴</a>') ),
						'id'   => 'wspa_express_checkout_title',
					],
          'enable'  => [
						'name' => __('Enable', 'woo-pay-addons'),
            'id'    => 'wspa_express_checkout_enabled',
            'type'  => 'checkbox',
            'value' => $values['express_checkout_enabled'],
          ],
          'button_type'                => [
						'title'    => __( 'Button Type', 'woo-pay-addons' ),
						'type'     => 'select',
						'id'       => 'wspa_express_checkout_button_type',
						'value'    => $values['express_checkout_button_type'],
						'options'  => [
							'buy'   => __( 'Buy', 'woo-pay-addons' ),
							'book'   => __( 'Book', 'woo-pay-addons' ),
							'checkout'   => __( 'Check-out', 'woo-pay-addons' ),
							'donate'   => __( 'Donate', 'woo-pay-addons' ),
							'order'   => __( 'Order', 'woo-pay-addons' ),
							'plain'   => __( 'Plain', 'woo-pay-addons' ),
						],
            'default'     => 'buy',
            'desc_tip'  => true,
            'desc' => __( 'Select a button label that fits best with the flow of purchase or payment experience on your store.', 'woo-pay-addons' ),
					],
          'button_height'                => [
						'title'    => __( 'Button Size', 'woo-pay-addons' ),
						'type'     => 'select',
						'id'       => 'wspa_express_checkout_button_height',
						'value'    => $values['express_checkout_button_height'],
						'options'  => [
							'40'   => __( 'Default (40 px)', 'woo-pay-addons' ),
							'48'    => __( 'Medium (48 px)', 'woo-pay-addons' ),
							'55'     => __( 'Large (55 px)', 'woo-pay-addons' ),
						],
            'default'     => '40',
            'desc_tip'  => true,
            'desc' => __( 'Note that larger buttons are more suitable for mobile use.', 'woo-pay-addons' ),
					],
          'button_theme'                => [
						'title'    => __( 'Button Theme', 'woo-pay-addons' ),
						'type'     => 'select',
						'id'       => 'wspa_express_checkout_button_theme',
						'desc'     => __( 'Select theme for Express Checkout button.', 'woo-pay-addons' ),
						'value'    => $values['express_checkout_button_theme'],
						'options'  => [
							'black'          => __( 'Dark', 'woo-pay-addons' ),
							'white'         => __( 'Light', 'woo-pay-addons' ),
							'white-outline' => __( 'Light Outline (Apply pay only)', 'woo-pay-addons' ),
						],
						'desc_tip' => true,
					],
          'checkout_page_section_end'   => [
						'type' => 'sectionend',
						'id'   => 'wspa_express_checkout',
					],
        ];
      }
    }

    return apply_filters('wspa_express_checkout_settings', $settings);
  }

  public static function get_gateway_settings($gateway = 'wspa_checkout_form')
  {
    $default_settings = [];
    $setting_name     = 'woocommerce_' . $gateway . '_settings';
    $saved_settings   = is_array(get_option($setting_name, [])) ? get_option($setting_name, []) : [];
    $gateway_defaults = self::get_gateway_defaults();

    if (isset($gateway_defaults[$setting_name])) {
      $default_settings = $gateway_defaults[$setting_name];
    }

    $settings = array_merge($default_settings, $saved_settings);

    return apply_filters('wspa_gateway_settings', $settings);
  }

  public static function get_gateway_defaults()
  {
    return apply_filters(
      'wspa_stripe_gateway_defaults_settings',
      [
        'woocommerce_wspa_checkout_form_settings' => [
          'express_checkout_enabled'            => 'yes',
          'express_checkout_button_type'        => 'buy',
          'express_checkout_button_theme'       => 'black',
          'express_checkout_button_height'      => '40',
        ],
      ]
    );
  }
}
