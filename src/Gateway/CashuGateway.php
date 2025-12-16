<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Cashu\WC\Helpers\CashuHelper;
use Automattic\WooCommerce\Enums\OrderStatus;

class CashuGateway extends \WC_Payment_Gateway {
	public function __construct() {
		// Init gateway
		$this->id                 = 'cashu';
		$this->icon               = CASHU_WC_PLUGIN_URL . 'assets/images/cashu-logo.png';
		$this->method_title       = __( 'Cashu ecash', 'cashu-for-woocommerce' );
		$this->method_description = __(
			'Accept Cashu tokens and melt them straight to your Bitcoin lightning address.',
			'cashu-for-woocommerce'
		);
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->init_form_fields();

		// Load / save settings
		$this->init_settings();
		$this->title       = $this->get_option( 'title', 'Cashu ecash' );
		$this->description = $this->get_option(
			'description',
			__( 'Paste your cashuB… token below.', 'cashu-for-woocommerce' )
		);
		$this->enabled     = $this->get_option( 'enabled' );
		// Actions expect void return
		\add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);

		// Enqueue scripts / webhooks
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_' . $this->id, array( $this, 'webhook' ) );
	}

	/**
	 * Gateway specific form fields only. Lightning settings live on the Cashu tab.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'title'       => array(
				'title'   => __( 'Title', 'cashu-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Cashu ecash', 'cashu-for-woocommerce' ),
			),
			'description' => array(
				'title'   => __( 'Checkout instructions', 'cashu-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __(
					'You will be able to complete your purchase using Cashu ecash.',
					'cashu-for-woocommerce'
				),
			),
			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'cashu-for-woocommerce' ),
				'label'       => __( 'Enable Cashu Gateway', 'cashu-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
		);
	}

	/**
	 * Enqueue checkout script on pages where it is needed.
	 */
	public function enqueue_scripts() {
		// Only on cart/checkout pages...
		//phpcs:disable WordPress.Security.NonceVerification.Recommended -- Just enqueuing scripts.
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}

		// If our payment gateway is active ...
		if ( ! $this->is_available() ) {
			return;
		}

		// And in SSL mode ...
		if ( ! is_ssl() ) {
			return;
		}

		// Enqueue and localize
		wp_enqueue_script(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/dist/cashu-checkout.js',
			array(),
			CASHU_WC_VERSION,
			true
		);

	  $order_id = isset($_GET['order-pay']) ? absint($_GET['order-pay']) : 0; // phpcs:ignore
		wp_localize_script(
			'cashu-checkout',
			'cashu_wc',
			array(
				'rest_root' => esc_url_raw( rest_url( 'cashu/v1' ) ),
				'order_id'  => $order_id ?: null,
			)
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );
		$total = (float) $order->get_total();

		if ( $total > 0 ) {
			/**
			 * Filter the order status for cashu payment (Default: 'pending').
			 *
			 * @since 3.6.0
			 *
			 * @param string $status The default status.
			 * @param object $order  The order object.
			 */
			$process_payment_status = apply_filters( 'woocommerce_cashu_process_payment_order_status', OrderStatus::PENDING, $order );
			$order->update_status( $process_payment_status, _x( 'Awaiting Cashu payment', 'Cashu payment method', 'woocommerce' ) );

			// Get the amount in sats
			$amount_sats = CashuHelper::fiatToSats( $total, $order->get_currency() );
			$order->update_meta_data( 'Amount in Bitcoin sats', wc_clean( $amount_sats ) );
			$order->update_meta_data( '_cashu_expected_amount', wc_clean( $amount_sats ) );
			$order->update_meta_data( '_cashu_expected_unit', 'sat' );
			$order->save();
		} else {
			// Zero total order, no payment needed.
			$order->payment_complete();
		}

		// Remove cart.
		WC()->cart->empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p>Order not found.</p>';
			return;
		}

		// If someone hits this URL for a different gateway, don’t show anything.
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		echo '<p>Please complete payment for order #' . esc_html( $order->get_order_number() ) . '.</p>';
		echo '<p>Amount: ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';

		echo '<div id="cashu-pay-root"
		data-order-id="' . esc_attr( $order_id ) . '"
		data-order-key="' . esc_attr( $order->get_order_key() ) . '"
		data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"></div>';

		// Optional, hide Woo’s “Pay for order” button if you don’t want a second click.
		// echo '<style>.woocommerce #order_review .button#place_order{display:none !important;}</style>';

		// Enqueue your JS bundle here or conditionally in wp_enqueue_scripts for is_checkout_pay_page().
		wp_enqueue_script( 'cashu-checkout' );

		// Output your canvas, token UI, etc, here.
	}

	// In case you need a webhook, like PayPal IPN etc
	public function webhook() {
		// ...
	}

	/**
	 * Checks our plugin is good to use.
	 */
	public function is_available(): bool {
		// Check plugin is enabled
		$enabled = get_option( 'cashu_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return false;
		}

		// Check lightning address is set
		$lightning_address = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $lightning_address ) {
			return false;
		}

		// Check gateway is enables
		return 'yes' === $this->enabled;
	}
}
