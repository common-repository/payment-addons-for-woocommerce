<?php

namespace Woo_Stripe_Pay_Addons\API;

if (!defined('ABSPATH')) {
    exit();
}

use Woo_Stripe_Pay_Addons\Shared\Logger;
use Woo_Stripe_Pay_Addons\Core\Webhook_Handler;
use Woo_Stripe_Pay_Addons\Core\Stripe_Settings;
use Woo_Stripe_Pay_Addons\Core\Stripe_Webhook_State;

class Stripe_Webhooks_Controller extends \WP_REST_Controller
{
    public function __construct()
    {
        $this->namespace = WSPA_ADDONS_REST_API . 'stripe';
        $this->rest_base = 'webhook';
        add_action('rest_api_init', array($this, 'register_routes'));
        Stripe_Webhook_State::get_monitoring_began_at();
    }

    public function register_routes()
    {
        register_rest_route($this->namespace, $this->rest_base, array(
            array(
                'methods'            => \WP_REST_Server::CREATABLE,
                'callback'          => array($this, 'handle_webhooks'),
                'permission_callback' => array($this, 'verify_access')
            ),
        ));
    }

    public function verify_access(\WP_REST_Request $request)
    {
        return true;
    }

    public function handle_webhooks(\WP_REST_Request $request)
    {
        try {
            // Parse the message body (and check the signature if possible)
            $webhookSecret = Stripe_Settings::get_setting('webhook_secret');
            if (!empty($webhookSecret)) {
                try {
                    $event = \Stripe\Webhook::constructEvent(
                        $request->get_body(),
                        $request->get_header('stripe-signature'),
                        $webhookSecret
                    );
                } catch (\Exception $e) {
                    Logger::error('Exception on StripeWebhook crypto:' . $e->getMessage());
                    Stripe_Webhook_State::set_last_webhook_failure_at( time() );
			        Stripe_Webhook_State::set_last_error_reason( Stripe_Webhook_State::VALIDATION_FAILED_SIGNATURE_INVALID );
                    return http_response_code(403);;
                }
            } else {
                $event = \Stripe\Event::constructFrom(
                    json_decode($request->get_body(), true)
                );
            }

            $event = apply_filters('wspa_webhook_event', $event);

            $webhook = new Webhook_Handler();
            $webhook->process_webhook($event);

			Stripe_Webhook_State::set_last_webhook_success_at( $event['created'] );
            http_response_code(200);
        } catch (\Exception $e) {
            Logger::error('Exception detail:', json_encode($e));
            return new \WP_Error('stripe_error', __($e->getMessage()), array('status' => 400));
        }
    }
}
