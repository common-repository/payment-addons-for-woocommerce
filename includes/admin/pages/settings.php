<?php

use Woo_Stripe_Pay_Addons\Core\Stripe_Settings;
use Woo_Stripe_Pay_Addons\Core\Stripe_Webhook_State;

$webhook_latest_message = Stripe_Webhook_State::get_webhook_status_message();
$webhook_no_event = Stripe_Webhook_State::is_no_event_received();
$stripe_settings = Stripe_Settings::get_settings();
$webhook_url = rest_url(WSPA_ADDONS_REST_API . 'stripe/webhook');

if (isset($_REQUEST['action'])) {
  if (isset($_POST['action']) && $_POST['action'] == 'save') {
    $stripe_settings['test_mode'] = $_POST['test_mode'];
    if ($_POST['test_publishable_key'] !== null) {
      $stripe_settings['test_publishable_key'] = $_POST['test_publishable_key'];
    }
    if ($_POST['live_publishable_key'] !== null) {
      $stripe_settings['live_publishable_key'] = $_POST['live_publishable_key'];
    }
    if ($_POST['test_secret_key'] !== null) {
      $stripe_settings['test_secret_key'] = $_POST['test_secret_key'];
    }
    if ($_POST['live_secret_key'] !== null) {
      $stripe_settings['live_secret_key'] = $_POST['live_secret_key'];
    }
    if ($_POST['webhook_secret'] !== null) {
      $stripe_settings['webhook_secret'] = $_POST['webhook_secret'];
    }
    $stripe_settings['enable_logging'] = isset($_POST['enable_logging']) && $_POST['enable_logging'] === 'on' ? 'on' : 'off';
    update_option('wspa_sys_settings', $stripe_settings);
  }
}
?>
<div id="settings" class="wspa-tab-panel p-8 active">
  <div class="py-4">
    <form id="stripe_setting" action="" method="POST">
      <input type="hidden" name="page" value="woo_pay_addons_settings">
      <input type="hidden" name="action" value="save">
      <table class="form-table">
        <tbody>
          <tr>
            <th><label for="test_mode">Mode</label></th>
            <td>
              <fieldset>
                <ul class="flex">
                  <li class="mr-2">
                    <label><input id="live_mode" name="test_mode" value="false" type="radio" <?php echo esc_attr(($stripe_settings['test_mode'] === 'false') ? 'checked="checked"' : ''); ?>>Live</label>
                  </li>
                  <li>
                    <label><input id="test_mode" name="test_mode" value="true" type="radio" <?php echo esc_attr(($stripe_settings['test_mode'] === 'true') ? 'checked="checked"' : ''); ?>> Test</label>
                  </li>
                </ul>
              </fieldset>
            </td>
          </tr>
          <tr>
            <th><label for="test_publishable_key">Test Key</label></th>
            <td>
              <input id="test_publishable_key" placeholder="pk_test_xxxxx" name="test_publishable_key" type="text" class="regular-text" value="<?php echo esc_html($stripe_settings['test_publishable_key']) ?>">
              <div>No account yet, get the test account keys from your <a href="https://dashboard.stripe.com/test/apikeys" class="wspa-button-link" target="_blank" rel="external noreferrer noopener">Stripe Account</a>.</div>
            </td>
          </tr>
          <tr>
            <th><label for="test_secret_key">Test Secret</label></th>
            <td><input id="test_secret_key" placeholder="sk_test_xxxxx" name="test_secret_key" type="text" class="regular-text" value="<?php echo esc_html($stripe_settings['test_secret_key']) ?>"></td>
          </tr>
          <tr>
            <th><label for="live_publishable_key">Live Key</label></th>
            <td><input id="live_publishable_key" placeholder="pk_live_xxxxx" name="live_publishable_key" type="text" class="regular-text" value="<?php echo esc_html($stripe_settings['live_publishable_key']) ?>"></td>
          </tr>
          <tr>
            <th><label for="live_secret_key">Live Secret</label></th>
            <td><input id="live_secret_key" placeholder="sk_live_xxxxx" name="live_secret_key" type="text" class="regular-text" value="<?php echo esc_html($stripe_settings['live_secret_key']) ?>"></td>
          </tr>
          <tr>
            <th><label for="webhook_url">Webhook URL</label></th>
            <td>
              <div>
                Add the following webhook endpoint
                <strong class="bg-gray-200"><?php echo esc_url($webhook_url) ?></strong>
                <button type="button" data-copy-state="copy" class="wspa-button-copy text-xs font-medium text-gray-600 dark:text-gray-400 dark:bg-gray-800 hover:text-blue-700 dark:hover:text-white">
                  <svg class="wspa-webhook-copy w-3.5 h-3.5 mr-2" data-url="<?php echo esc_url($webhook_url) ?>" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20">
                    <path d="M5 9V4.13a2.96 2.96 0 0 0-1.293.749L.879 7.707A2.96 2.96 0 0 0 .13 9H5Zm11.066-9H9.829a2.98 2.98 0 0 0-2.122.879L7 1.584A.987.987 0 0 0 6.766 2h4.3A3.972 3.972 0 0 1 15 6v10h1.066A1.97 1.97 0 0 0 18 14V2a1.97 1.97 0 0 0-1.934-2Z" />
                    <path d="M11.066 4H7v5a2 2 0 0 1-2 2H0v7a1.969 1.969 0 0 0 1.933 2h9.133A1.97 1.97 0 0 0 13 18V6a1.97 1.97 0 0 0-1.934-2Z" />
                  </svg>
                </button>
                to your
                <a class="wspa-button-link" href="https://dashboard.stripe.com/account/webhooks" target="_blank" rel="external noreferrer noopener">Stripe account settings

                </a> (if there isn't one already). This will enable you to receive notifications on the charge statuses.
              </div>
              <div class="p-1 mt-2 text-sm text-gray-800 rounded-lg bg-gray-50 dark:bg-gray-800 dark:text-gray-300" role="alert">
                <div class="<?php echo esc_attr($webhook_no_event ? 'text-gray-400' : '') ?>">
                  <span class="inline-flex items-center justify-center w-2 h-2 mr-1 text-xs font-semibold rounded-full <?php echo esc_attr($webhook_no_event ? 'bg-gray-400' : 'bg-green-500') ?>"></span>
                  <span class="font-medium">status:</span> <?php echo esc_html($webhook_latest_message) ?>
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <th><label for="webhook_secret">Webhook Secret</label></th>
            <td>
              <input id="webhook_secret" placeholder="whsec_js_xxxxx" name="webhook_secret" type="text" class="regular-text" value="<?php echo esc_html($stripe_settings['webhook_secret']) ?>">
              <div>(Reveal the signing secret of the bove webhook and copy it to here to increasing your webhook security)</div>
            </td>
          </tr>
          <tr>
            <th><label for="logging">Enable logging</label></th>
            <td><input id="logging" type="checkbox" name="enable_logging" class="regular-text" value="on" <?php echo esc_attr(($stripe_settings['enable_logging'] === 'on') ? 'checked' : ''); ?>></td>
          </tr>
        </tbody>
      </table>
      <div class="flex justify-end">
        <button name="btnSaveSettings" class="btn-save-setting wspa-button-primary" type="submit">Save changes</button>
      </div>
    </form>
  </div>
</div>