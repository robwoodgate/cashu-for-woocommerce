<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\CashuHelper;

class CashuGateway extends \WC_Payment_Gateway {

	public function __construct() {
		// Init gateway
		$this->id                 = 'cashu_default';
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
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Just enqueuing scripts.
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
	 *
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
			$process_payment_status = apply_filters(
				'woocommerce_cashu_process_payment_order_status',
				OrderStatus::PENDING,
				$order
			);

			// Set order status.
			$order->update_status(
				$process_payment_status,
				_x( 'Awaiting Cashu payment', 'Cashu payment method', 'cashu-for-woocommerce' )
			);

			// If we've already locked sats for this order, reuse them.
			$existing = $order->get_meta( '_cashu_expected_amount', true );
			if ( is_numeric( $existing ) && (int) $existing > 0 ) {
				$amount_sats = (int) $existing;
			} else {
				$quote       = CashuHelper::fiatToSats( $total, $order->get_currency() );
				$amount_sats = absint( $quote['sats'] );

				$order->update_meta_data( '_cashu_expected_amount', $amount_sats );
				$order->update_meta_data( '_cashu_expected_unit', 'sat' );
				$order->update_meta_data( '_cashu_btc_price', (string) $quote['btc_price'] );
				$order->update_meta_data( '_cashu_price_source', $quote['source'] );
				$order->update_meta_data( '_cashu_quoted_at', $quote['quoted_at'] );

				// Add sats amount as an order note
				$msg = sprintf(
					/* translators: %1$s: Bitcoin symbol, %2$s: Amount in sats, %3$s: ISO 4217 currency code (eg: USD), %4$s: BTC Spot price */
					__( 'Cashu quote: %1$s%2$s (BTC/%3$s: %4$s)', 'cashu-for-woocommerce' ),
					CASHU_WC_BIP177_SYMBOL,
					$amount_sats,
					$order->get_currency(),
					(string) $quote['btc_price']
				);
				$order->add_order_note( $msg );
			}

			$order->save();
		} else {
			// Zero total order, no payment needed.
			$order->payment_complete();
		}

		// Empty cart as the order exists.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'cashu-for-woocommerce' ) . '</p>';
			return;
		}

		// Check payment is for our gateway
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$amount_sats = absint( $order->get_meta( '_cashu_expected_amount', true ) );
		if ( $amount_sats <= 0 ) {
			// Fallback
			$total       = (float) $order->get_total();
			$quote       = CashuHelper::fiatToSats( $total, $order->get_currency() );
			$amount_sats = isset( $quote['sats'] ) ? absint( $quote['sats'] ) : 0;
		}

		wp_enqueue_style( 'cashu-checkout' );
		wp_enqueue_script( 'cashu-checkout' );

		// Optional, if your JS needs these.
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'cashu_generate_invoice' );

		echo '<div id="cashu-pay-root"
			data-order-id="' . esc_attr( $order_id ) . '"
			data-order-key="' . esc_attr( $order->get_order_key() ) . '"
			data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"
			data-ajax-url="' . esc_url( $ajax_url ) . '"
			data-nonce="' . esc_attr( $nonce ) . '"
			data-amount-sats="' . esc_attr( $amount_sats ) . '"
			data-fiat-currency="' . esc_attr( $order->get_currency() ) . '"
			data-fiat-total="' . esc_attr( (string) $order->get_total() ) . '"
		></div>';
		?>
		<section id="cashu-payment" class="cashu-checkout" aria-label="<?php echo esc_attr__( 'Cashu payment', 'cashu-for-woocommerce' ); ?>">
			<div class="cashu-amount-box cashu-center">
				<div class="cashu-paywith"><?php echo esc_html__( 'Pay', 'cashu-for-woocommerce' ); ?></div>
				<h2 class="cashu-amount">
					<?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $amount_sats ); ?>
				</h2>
				<div class="cashu-paywith"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></div>
			</div>
			<div class="cashu-paywith cashu-center">
				<div class="cashu-pills" role="tablist" aria-label="<?php echo esc_attr__( 'Payment method', 'cashu-for-woocommerce' ); ?>">
					<button type="button" class="cashu-pill" data-cashu-method="lightning" role="tab" aria-selected="false">
						<?php echo esc_html__( 'Lightning', 'cashu-for-woocommerce' ); ?>
					</button>
					<button type="button" class="cashu-pill is-active" data-cashu-method="cashu" role="tab" aria-selected="true">
						<?php echo esc_html__( 'Cashu', 'cashu-for-woocommerce' ); ?>
					</button>
				</div>
			</div>
			<div class="cashu-box">
				<div class="cashu-qr-wrap">
					<div class="cashu-qr" data-cashu-qr>
						<!-- JS renders QR here, canvas or img is fine -->
					</div>

					<div class="cashu-qr-icon" aria-hidden="true">
						<img src="<?php echo esc_url( $this->icon ); ?>" alt="">
					</div>
				</div>

				<div class="cashu-sep"><span><?php echo esc_html__( 'OR', 'cashu-for-woocommerce' ); ?></span></div>

				<form class="cashu-token">
					<input
						type="text"
						class="cashu-input"
						name="cashu_token"
						autocomplete="off"
						placeholder="<?php echo esc_attr__( 'Enter your cashu token…', 'cashu-for-woocommerce' ); ?>"
						data-cashu-token-input
					/>
					<button type="submit" class="cashu-paybtn">
						<?php echo esc_html__( 'Pay', 'cashu-for-woocommerce' ); ?>
					</button>

					<p class="cashu-foot">
						<?php echo esc_html__( 'Fees will be lower if you use ', 'cashu-for-woocommerce' ); ?>
					</p>
				</form>
			</div>
		</section>
		<?php
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
