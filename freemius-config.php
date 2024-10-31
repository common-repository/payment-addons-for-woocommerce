<?php

if ( !function_exists( 'wspa_fs' ) ) {
    // Create a helper function for easy SDK access.
    function wspa_fs() {
        global $wspa_fs;
        if ( !isset( $wspa_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/freemius/start.php';
            $wspa_fs = fs_dynamic_init( array(
                'id'             => '14386',
                'slug'           => 'woo-pay-addons',
                'type'           => 'plugin',
                'public_key'     => 'pk_6684b9c7a864ab82e0b1c0e7419b0',
                'is_premium'     => false,
                'has_addons'     => false,
                'has_paid_plans' => true,
                'trial'          => array(
                    'days'               => 14,
                    'is_require_payment' => true,
                ),
                'menu'           => array(
                    'slug'    => 'woo-pay-addons',
                    'support' => false,
                ),
                'is_live'        => true,
            ) );
        }
        return $wspa_fs;
    }

    // Init Freemius.
    wspa_fs();
    // Signal that SDK was initiated.
    do_action( 'wspa_fs_loaded' );
}