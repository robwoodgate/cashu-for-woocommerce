<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Cashu\WC\Helpers\CashuHelper;

class CashuGateway extends \WC_Payment_Gateway
{
	public function __construct()
	{
		// Init gateway
		$this->id = 'cashu';
		$this->icon = CASHU_WC_PLUGIN_URL.'assets/images/cashu-logo.svg';
		$this->method_title = __('Cashu ecash', 'cashu-for-woocommerce');
		$this->method_description = __(
			'Accept Cashu tokens and melt them straight to your Bitcoin lightning address.',
			'cashu-for-woocommerce'
		);
		$this->has_fields = true;
		$this->supports = ['products'];
		$this->init_form_fields();

		// Load / save settings
		$this->init_settings();
		$this->title = $this->get_option('title', 'Cashu ecash');
		$this->description = $this->get_option(
			'description',
			__('Paste your cashuB… token below.', 'cashu-for-woocommerce')
		);
		$this->enabled = $this->get_option('enabled');
		\add_action('woocommerce_update_options_payment_gateways_'.$this->id, [
			$this,
			'process_admin_options',
		]);

		// Enqueue scripts / webhooks
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('woocommerce_api_{webhook name}', [$this, 'webhook']);
	}

	/**
	 * Gateway specific form fields only. Lightning settings live on the Cashu tab.
	 */
	public function init_form_fields()
	{
		$this->form_fields = [
			'title' => [
				'title' => __('Title', 'cashu-for-woocommerce'),
				'type' => 'text',
				'default' => __('Cashu ecash', 'cashu-for-woocommerce'),
			],
			'description' => [
				'title' => __('Checkout instructions', 'cashu-for-woocommerce'),
				'type' => 'textarea',
				'default' => __(
					'You will be able to complete your purchase using Cashu ecash.',
					'cashu-for-woocommerce'
				),
			],
			'enabled' => [
				'title' => __('Enable/Disable', 'cashu-for-woocommerce'),
				'label' => __('Enable Cashu Gateway', 'cashu-for-woocommerce'),
				'type' => 'checkbox',
				'description' => '',
				'default' => 'no',
			],
		];
	}

	/**
	 * Enqueue checkout script on pages where it is needed.
	 */
	public function enqueue_scripts()
	{
		// Only on cart/checkout pages...
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			return;
		}

		// If our payment gateway is active ...
		if (!$this->is_available()) {
			return;
		}

		// And in SSL mode ...
		if (!is_ssl()) {
			return;
		}

		// Enqueue and localize
		wp_enqueue_script(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL.'assets/dist/cashu-checkout.js',
			[],
			CASHU_WC_VERSION,
			true
		);

		$order_id = isset($_GET['order-pay']) ? absint($_GET['order-pay']) : 0; // phpcs:ignore
		wp_localize_script('cashu-checkout', 'cashu_wc', [
			'rest_root' => esc_url_raw(rest_url('cashu/v1')),
			'order_id' => $order_id ?: null,
		]);
	}

	/**
	 * You will need it if you want your custom credit card form, Step 4 is about it.
	 */
	public function payment_fields()
	{
		$order = wc_get_order($order_id);
		$amount_sats = $order->get_total();
		update_post_meta($order_id, '_cashu_expected_amount', $amount_sats);
		update_post_meta($order_id, '_cashu_expected_unit', 'sat');

		return <<<EOL
			<p>Please send <?php echo {$this->get_order_total}() ?> USDT TRC-20 by using the QR code or address: <strong><?php echo {$this->address} ?></strong></p>
			<canvas id="qr"></canvas>
			<p>Once you've done that, please enter transaction ID below:</p>
			<p class="form-row form-row-wide">
				<input type="text" name="txid" placeholder="Transaction ID" / >
			</p>
			<script>
			(function() {
				const qr = new QRious({
					element: document.getElementById( 'qr' ),
					value: '<?php echo {$this->address} ?>',
					size: 250
				});
			})();
			</script>
			EOL;
	}

	// Fields validation, more in Step 5
	public function validate_fields()
	{
		// ...
	}

	/**
	 * Renders your UI on the checkout page, token input, QR, messages, buttons, anything visual.
	 *
	 * @param $order_id WooCommerce Order ID
	 *
	 * @return WP_AJAX Success response
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);

		$amount_sats = CashuHelper::fiatToSats($order->get_total(), $order->get_currency());
		update_post_meta($order_id, '_cashu_expected_amount', $amount_sats);
		update_post_meta($order_id, '_cashu_expected_unit', 'sat');

		$order->update_status('on-hold', 'Awaiting Cashu payment');

		return [
			'result' => 'success',
			'redirect' => $this->get_return_url($order),
		];
	}

	// In case you need a webhook, like PayPal IPN etc
	public function webhook()
	{
		// ...
	}

	/**
	 * Checks our plugin is good to use.
	 */
	public function is_available(): bool
	{
		// Check plugin is enabled
		$enabled = get_option('cashu_enabled', 'no');
		if ('yes' !== $enabled) {
			return false;
		}

		// Check lightning address is set
		$lightning_address = trim((string) get_option('cashu_lightning_address', ''));
		if ('' === $lightning_address) {
			return false;
		}

		// Check gateway is enables
		return 'yes' === $this->enabled;
	}
}
