<?php
$templates = [
  [
    "name" => "Checkout Redirect",
    "desc" => "Redirect to SCA-ready stripe-hosted checkout page, with over 30+ payment methods, including popular options such as Apple Pay, Google Pay, iDeal, and SEPA",
    "plan" => "free",
    "preview" => WSPA_ADDONS_URL . 'assets/admin/img/checkout-redirect-overview.png',
    "doc_url" => "https://woo-docs.payaddons.com/payment-methods/checkout-redirect",
    "setup_url" => "?page=wc-settings&tab=checkout&section=wspa_checkout_redirect"
  ],
  [
    "name" => "Checkout Form",
    "desc" => "Standing out by offering 30+ global payment options on a single, easily configurable and nice look checkout form with flexible layouts, multiple themes.",
    "plan" => "pro",
    "preview" => WSPA_ADDONS_URL . 'assets/admin/img/checkout-form-overview.png',
    "doc_url" => "https://woo-docs.payaddons.com/payment-methods/checkout-form-pro",
    "setup_url" => "?page=wc-settings&tab=checkout&section=wspa_checkout_form"
  ],
  [
    "name" => "Express Checkout",
    "desc" => "Accepting payments through one-click payment buttons. Supported payment methods include Apple Pay, Google Pay, Link. Improves checkout conversion by creating a seamless checkout experience for your shoppers. ",
    "plan" => "pro",
    "preview" => WSPA_ADDONS_URL . 'assets/admin/img/express-checkout-overview.png',
    "doc_url" => "https://woo-docs.payaddons.com/payment-methods/express-checkout-pro",
    "setup_url" => "?page=wc-settings&tab=checkout&section=wspa_express_checkout"
  ]
];

$is_pro = wspa_fs()->can_use_premium_code();
?>
<div id="payment-methods" class="wspa-tab-panel p-8 active">
  <div class="mx-auto max-w-2xl py-4 px-4 sm:px-6 lg:max-w-7xl lg:px-8">
    <h2 class="text-2xl font-bold tracking-tight text-gray-900"></h2>
    <div class="mt-6 grid grid-cols-1 gap-y-10 gap-x-6 sm:grid-cols-2 lg:grid-cols-3 xl:gap-x-8">
      <?php foreach ($templates as $key => $template) { ?>
        <div class="relative">
          <div class="relative">
            <?php if ($template['plan'] === 'pro') { ?><span class="z-10 absolute right-1 top-1 bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded dark:bg-gray-700 dark:text-yellow-300 border border-yellow-300">Pro</span><?php } ?>
            <div class="flex items-center min-h-48 w-full overflow-hidden rounded-md hover:opacity-75 bg-gray-200 lg:h-48">
              <img src="<?php echo esc_url($template['preview']) ?>" alt="" class="p-2 h-full w-full object-center">
            </div>
          </div>
          <h3 class="mt-4 w-full font-bold text-center text-sm text-gray-700"><?php echo esc_html($template['name']) ?></h3>
          <div class="mt-2">
            <p class="mt-1 text-sm text-gray-500 h-20 line-clamp-4" title="<?php echo esc_html($template['desc']) ?>"><?php echo esc_html($template['desc']) ?></p>
          </div>
          <div class="mt-4">
            <div class="flex justify-between">
              <a href="<?php echo esc_url($template['doc_url']) ?>" target="_blank" class="inline-flex items-center h-6 px-3 py-2 text-sm font-medium text-center text-gray-500 border border-gray-300 hover:text-gray-700 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-full p-2.5">
                Docs
              </a>
              <?php if($template['plan'] === 'free' || $is_pro) { ?>
              <a href="<?php echo esc_url($template['setup_url']) ?>" data-url="<?php echo esc_url($template['setup_url']) ?>" target="_blank" class="link-template-download inline-flex items-center h-6 px-3 py-2 text-sm font-medium text-center text-gray-500 border border-gray-300 hover:text-gray-700 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-full p-2.5">
                Set up 
                <svg class="rtl:rotate-180 w-3.5 h-3.5 ml-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 10">
                  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5h12m0 0L9 1m4 4L9 9" />
                </svg>
              </a>
              <?php } else {
                if(wspa_fs()->is_not_paying()) {
                  echo '<a class="wspa-button-link" href="' . esc_url(wspa_fs()->get_upgrade_url()) . '">' . __('Upgrade Now!', 'woo-pay-addons') .'</a> ';
                }
              } ?>
            </div>
          </div>
        </div>
      <?php  } ?>
    </div>
  </div>
</div>