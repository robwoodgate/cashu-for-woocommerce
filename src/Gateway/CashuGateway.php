<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\LightningAddress;
use WC_Order;

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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Just enqueuing scripts.
		if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
			return;
		}
		if ( ! $this->is_available() ) {
			return;
		}
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

		wp_localize_script(
			'cashu-checkout',
			'cashu_wc',
			array(
				'rest_root'     => esc_url_raw( rest_url( 'cashu-wc/v1/' ) ),
				'confirm_route' => 'confirm-melt-quote',
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
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$total = (float) $order->get_total();

		if ( 0.0 === $total ) {
			// No payment needed
			$order->payment_complete();
		} else {
			$result = $this->setup_cashu_payment( $order );

			if ( is_wp_error( $result ) ) {
				Logger::error( 'Cashu setup failed: ' . $result->get_error_message() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Setup cashu checkout and redirect to payment form
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return true|\WP_Error
	 */
	private function setup_cashu_payment( WC_Order $order ) {
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

		// Determine invoice amount in sats (merchant receives this).
		$invoice_amount_sats = $this->get_or_set_invoice_amount_sats( $order );
		if ( is_wp_error( $invoice_amount_sats ) ) {
			return $invoice_amount_sats;
		}

		// Create or reuse melt quote, store fee reserve, set headline expected amount.
		$this->ensure_melt_quote_for_order( $order, (int) $invoice_amount_sats );

		$order->save();

		return true;
	}

	/**
	 * Determine the invoice amount in sats, reusing any existing value.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return int|\WP_Error
	 */
	private function get_or_set_invoice_amount_sats( WC_Order $order ) {
		// Return existing sats amount?
		$invoice_amount_sats = absint( $order->get_meta( '_cashu_invoice_amount_sats', true ) );
		if ( $invoice_amount_sats > 0 ) {
			return $invoice_amount_sats;
		}

		// Do conversion to sats
		$total = (float) $order->get_total();
		$quote = CashuHelper::fiatToSats( $total, $order->get_currency() );

		$invoice_amount_sats = absint( $quote['sats'] ?? 0 );
		if ( $invoice_amount_sats <= 0 ) {
			Logger::error( 'Cashu quote failed, sats amount is invalid.' );
			return new \WP_Error( 'cashu_quote_failed', 'Cashu quote failed.' );
		}

		// Set order meta
		$order->update_meta_data( '_cashu_invoice_amount_sats', $invoice_amount_sats );
		$order->update_meta_data( '_cashu_expected_unit', 'sat' );
		$order->update_meta_data( '_cashu_btc_price', (string) ( $quote['btc_price'] ?? '' ) );
		$order->update_meta_data( '_cashu_price_source', (string) ( $quote['source'] ?? '' ) );
		$order->update_meta_data( '_cashu_quoted_at', (string) ( $quote['quoted_at'] ?? '' ) );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: Bitcoin symbol, %2$s: Amount in sats, %3$s: ISO 4217 currency code (eg: USD), %4$s: BTC Spot price */
				__( 'Cashu quote: %1$s%2$s (BTC/%3$s: %4$s)', 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$invoice_amount_sats,
				$order->get_currency(),
				(string) ( $quote['btc_price'] ?? '' )
			)
		);

		return $invoice_amount_sats;
	}

	private function ensure_melt_quote_for_order( \WC_Order $order, int $invoice_amount_sats ): void {
		$trusted_mint = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( '' === $trusted_mint ) {
			throw new \RuntimeException( 'Trusted mint not configured.' );
		}

		$ln_address = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $ln_address ) {
			throw new \RuntimeException( 'Lightning address not configured.' );
		}

		$now = time();

		$existing_quote_id     = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$existing_quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$existing_invoice      = (string) $order->get_meta( '_cashu_invoice_bolt11', true );
		$existing_invoice_sats = absint( $order->get_meta( '_cashu_invoice_amount_sats', true ) );
		$existing_fee_reserve  = absint( $order->get_meta( '_cashu_melt_fee_reserve_sats', true ) );

		// Reuse quote if still valid and matches this order amount.
		if (
			$existing_quote_id &&
			$existing_invoice &&
			$existing_invoice_sats === $invoice_amount_sats &&
			$existing_quote_expiry > ( $now + 30 ) // small buffer
		) {
			$order->update_meta_data( '_cashu_trusted_mint', $trusted_mint );
			$order->update_meta_data( '_cashu_expected_amount', $existing_invoice_sats + $existing_fee_reserve );
			return;
		}

		// Create invoice for the headline order amount (merchant receives this).
		$invoice = LightningAddress::get_invoice( $ln_address, $invoice_amount_sats );
		if ( ! is_string( $invoice ) || '' === $invoice ) {
			throw new \RuntimeException( 'Failed to obtain Lightning invoice.' );
		}

		$quote = $this->request_melt_quote_bolt11( $trusted_mint, $invoice );

		$quote_id     = sanitize_text_field( (string) ( $quote['quote'] ?? '' ) );
		$amount       = absint( $quote['amount'] ?? 0 );
		$fee_reserve  = absint( $quote['fee_reserve'] ?? 0 );
		$expiry       = absint( $quote['expiry'] ?? 0 );
		$unit         = (string) ( $quote['unit'] ?? '' );
		$request_echo = (string) ( $quote['request'] ?? '' );

		if ( '' === $quote_id || $amount <= 0 || $expiry <= 0 || 'sat' !== $unit || '' === $request_echo ) {
			throw new \RuntimeException( 'Invalid melt quote response from mint.' );
		}

		// Persist the quote context for confirm step later.
		$order->update_meta_data( '_cashu_trusted_mint', $trusted_mint );
		$order->update_meta_data( '_cashu_invoice_bolt11', $request_echo );
		$order->update_meta_data( '_cashu_melt_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_melt_quote_expiry', $expiry );
		$order->update_meta_data( '_cashu_melt_fee_reserve_sats', $fee_reserve );

		// Headline amount the customer must cover.
		$order->update_meta_data( '_cashu_expected_amount', $amount + $fee_reserve );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: sats invoice amount, %2$s: fee reserve sats, %3$s: total required sats */
				__( 'Cashu melt quote created, invoice=%1$s sats, fee_reserve=%2$s sats, required=%3$s sats', 'cashu-for-woocommerce' ),
				(string) $amount,
				(string) $fee_reserve,
				(string) ( $amount + $fee_reserve )
			)
		);
	}

	private function request_melt_quote_bolt11( string $mint_url, string $bolt11 ): array {
		$endpoint = rtrim( $mint_url, '/' ) . '/v1/melt/quote/bolt11';

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'request' => $bolt11,
					'unit'    => 'sat',
				)
			),
		);

		$res = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( 'Mint quote request failed: ' . sanitize_text_field( $res->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );

		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint quote request failed, HTTP ' . (int) $code );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Mint quote response is not JSON.' );
		}

		return $json;
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

		// Fallback: if user sat on the page and quote expired, you will refresh this in the JS later,
		// but at least keep the PHP view consistent when possible.
		$invoice_amount_sats = absint( $order->get_meta( '_cashu_invoice_amount_sats', true ) );
		if ( $invoice_amount_sats > 0 ) {
			try {
				$this->ensure_melt_quote_for_order( $order, $invoice_amount_sats );
				$order->save();
			} catch ( \Throwable $e ) {
				Logger::debug( 'Could not ensure melt quote on receipt page: ' . $e->getMessage() );
			}
		}

		$pay_amount_sats  = absint( $order->get_meta( '_cashu_expected_amount', true ) );
		$fee_reserve_sats = absint( $order->get_meta( '_cashu_melt_fee_reserve_sats', true ) );
		$quote_id         = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$quote_expiry     = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$invoice_bolt11   = (string) $order->get_meta( '_cashu_invoice_bolt11', true );

		wp_enqueue_script( 'cashu-checkout' );
		wp_enqueue_style( 'cashu-checkout' );

		echo '<div id="cashu-pay-root"
			data-order-id="' . esc_attr( $order_id ) . '"
			data-order-key="' . esc_attr( $order->get_order_key() ) . '"
			data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"
			data-pay-amount-sats="' . esc_attr( $pay_amount_sats ) . '"
			data-invoice-amount-sats="' . esc_attr( $invoice_amount_sats ) . '"
			data-fee-reserve-sats="' . esc_attr( $fee_reserve_sats ) . '"
			data-melt-quote-id="' . esc_attr( $quote_id ) . '"
			data-melt-quote-expiry="' . esc_attr( $quote_expiry ) . '"
			data-invoice-bolt11="' . esc_attr( $invoice_bolt11 ) . '"
		></div>';

		?>
		<section id="cashu-payment" class="cashu-checkout" aria-label="<?php echo esc_attr__( 'Cashu payment', 'cashu-for-woocommerce' ); ?>">
			<div class="cashu-amount-box cashu-center">
				<div class="cashu-paywith"><?php echo esc_html__( 'Pay', 'cashu-for-woocommerce' ); ?></div>
				<h2 class="cashu-amount">
					<?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $pay_amount_sats ); ?>
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

	public function is_available(): bool {
		$enabled = get_option( 'cashu_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return false;
		}

		$lightning_address = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $lightning_address ) {
			return false;
		}

		return 'yes' === $this->enabled;
	}
}
