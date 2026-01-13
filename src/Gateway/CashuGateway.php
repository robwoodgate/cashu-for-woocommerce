<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\LightningAddress;
use WC_Order;

class CashuGateway extends \WC_Payment_Gateway {

	private const QUOTE_EXPIRY_SECS = 900;  // 15 mins
	/**
	 * Trusted Mint.
	 * @var string
	 */
	protected $trusted_mint = '';

	/**
	 * Vendor Lightning Address.
	 * @var string
	 */
	protected $ln_address = '';

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

		$this->title        = $this->get_option( 'title', 'Cashu ecash' );
		$this->description  = $this->get_option(
			'description',
			__( 'Paste your cashuB… token below.', 'cashu-for-woocommerce' )
		);
		$this->enabled      = $this->get_option( 'enabled' );
		$this->trusted_mint = (string) get_option( 'cashu_trusted_mint', '' );
		$this->ln_address   = (string) get_option( 'cashu_lightning_address', '' );

		// Load / save settings
		$this->init_settings();
		\add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				// Actions expect void return
				$this->process_admin_options();
			}
		);

		// Enqueue gateway scripts / pages
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ), 20 );
		add_action( 'woocommerce_after_my_account', array( $this, 'render_change_section' ), 20 );
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
	 * Limit size of default icon
	 */
	public function get_icon() {
		$icon = parent::get_icon();
		return str_replace( 'src=', 'style="max-width:40px;" src=', $icon );
	}

	/**
	 * Register gateway scripts / styles
	 */
	public function enqueue_scripts() {
		// QR Code
		wp_register_script(
			'cashu-qrcode',
			CASHU_WC_PLUGIN_URL . 'assets/js/frontend/qrcode.min.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);

		// Toastr - non-blocking notifications; https://github.com/CodeSeven/toastr
		// This is the CSS, the script is imported via npm into checkout.ts
		wp_register_style( 'toastr', CASHU_WC_PLUGIN_URL . 'assets/css/toastr.min.css', array(), CASHU_WC_VERSION, false ); // NB: head

		// Main checkout
		wp_register_script(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/cashu/checkout.js',
			array( 'jquery', 'cashu-qrcode' ),
			CASHU_WC_VERSION,
			true
		);
		wp_localize_script(
			'cashu-checkout',
			'cashu_wc',
			array(
				'rest_root'         => esc_url_raw( rest_url( 'cashu-wc/v1/' ) ),
				'confirm_route'     => 'confirm-melt-quote',
				'nonce_invoice'     => wp_create_nonce( 'cashu_generate_invoice' ),
				'nonce_confirm'     => wp_create_nonce( 'cashu_confirm_payment' ),
				'lightning_address' => get_option( 'cashu_lightning_address', '' ),
			)
		);

		// Change box
		wp_register_script(
			'cashu-thanks',
			CASHU_WC_PLUGIN_URL . 'assets/js/frontend/thanks.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);

		// Gateway CSS
		wp_register_style(
			'cashu-public',
			CASHU_WC_PLUGIN_URL . 'assets/css/public.css',
			array(),
			CASHU_WC_VERSION
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
			try {
				$this->setup_cashu_payment( $order );
			} catch ( \Error $e ) {
				Logger::error( 'Could not setup Cashu payment: ' . $e->getMessage() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
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
	 */
	private function setup_cashu_payment( WC_Order $order ): void {
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
		$order_total_sats = $this->get_total_sats( $order );

		// Create or reuse melt quote, store fee reserve, set headline expected amount.
		$this->ensure_melt_quote_for_order( $order, $order_total_sats );

		$order->save();
	}

	/**
	 * Determine the invoice amount in sats, reusing any existing value.
	 */
	private function get_total_sats( WC_Order $order ): int {
		// Return existing sats amount if quote is still valid
		$order_total_sats = absint( $order->get_meta( '_cashu_spot_total', true ) );
		$quoted_at        = absint( $order->get_meta( '_cashu_spot_time', true ) );
		if ( $order_total_sats > 0 && $quoted_at > time() - self::QUOTE_EXPIRY_SECS ) {
			return $order_total_sats;
		}

		// Convert order total to sats
		$total = (float) $order->get_total();
		$quote = CashuHelper::fiatToSats( $total, $order->get_currency() );

		$order_total_sats = absint( $quote['sats'] ?? 0 );
		if ( $order_total_sats <= 0 ) {
			Logger::error( 'Cashu quote failed, sats amount is invalid.' );
			throw new \RuntimeException( 'Could not get price quote in bitcoin.' );
		}

		// Set order meta
		$order->update_meta_data( '_cashu_spot_total', $order_total_sats );
		$order->update_meta_data( '_cashu_spot_time', $quote['quoted_at'] );
		$order->update_meta_data( '_cashu_spot_btc', $quote['btc_price'] );
		$order->update_meta_data( '_cashu_spot_source', $quote['source'] );

		// Remove any old melt quotes
		$order->delete_meta_data( '_cashu_melt_quote_id' );
		$order->delete_meta_data( '_cashu_melt_quote_expiry' );
		$order->delete_meta_data( '_cashu_melt_total' );
		$order->delete_meta_data( '_cashu_melt_mint' );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: Bitcoin symbol, %2$s: Amount in sats, %3$s: ISO 4217 currency code (eg: USD), %4$s: BTC Spot price, %5$s: quote source */
				__( 'Cashu quote: %1$s%2$s (BTC/%3$s: %4$s) from %5$s', 'cashu-for-woocommerce' ),
				CASHU_WC_BIP177_SYMBOL,
				$order_total_sats,
				$order->get_currency(),
				(string) ( $quote['btc_price'] ?? '' ),
				$quote['source']
			)
		);

		return $order_total_sats;
	}

	/**
	 * Ensures we have a melt quote at the trusted mint so we can pay
	 * the order total in sats to the vendor's lightning address.
	 */
	private function ensure_melt_quote_for_order( \WC_Order $order, int $order_total_sats ): void {
		// Check settings are ok
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( 'Cashu gateway is not configured.' );
		}

		// Use existing melt quote?
		$quote_id     = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$melt_mint    = (string) $order->get_meta( '_cashu_melt_mint', true );
		if ( '' !== $quote_id
			&& $quote_expiry > time() + self::QUOTE_EXPIRY_SECS
			&& $this->trusted_mint === $melt_mint
		) {
			return;
		}

		// Create LN invoice for the headline order amount (merchant receives this).
		$invoice = LightningAddress::get_invoice( $this->ln_address, $order_total_sats );
		if ( ! is_string( $invoice ) || '' === $invoice ) {
			throw new \RuntimeException( 'Failed to obtain Lightning invoice.' );
		}

		// Request melt quote to pay the vendor LN invoice
		$quote       = $this->request_melt_quote_bolt11( $invoice );
		$quote_id    = sanitize_text_field( (string) ( $quote['quote'] ?? '' ) );
		$expiry      = absint( $quote['expiry'] ?? 0 );
		$amount      = absint( $quote['amount'] ?? 0 );
		$fee_reserve = absint( $quote['fee_reserve'] ?? 0 );
		$unit        = (string) ( $quote['unit'] ?? '' );

		if ( '' === $quote_id || $amount <= 0 || $expiry <= 0 || 'sat' !== $unit ) {
			throw new \RuntimeException( 'Invalid melt quote response from mint.' );
		}

		// Persist the quote context for confirm step later.
		$order->update_meta_data( '_cashu_melt_quote_id', $quote_id );
		$order->update_meta_data( '_cashu_melt_quote_expiry', $expiry );
		$order->update_meta_data( '_cashu_melt_mint', $this->trusted_mint );

		// Headline amount the customer must cover.
		$ppk_fee = $this->estimate_input_fee_sats( $amount + $fee_reserve );
		$total   = $amount + $fee_reserve + $ppk_fee;
		$order->update_meta_data( '_cashu_melt_total', $total );
		$order->update_meta_data( '_cashu_melt_fees', $fee_reserve + $ppk_fee );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: sats invoice amount, %2$s: fee reserve sats, %3$s: total required sats */
				__( "Cashu melt quote created:\nInvoice: %1\$s\nLightning fee reserve: %2\$s\nMint fee reserve: %3\$s\nTotal: %4\$s", 'cashu-for-woocommerce' ),
				(string) CASHU_WC_BIP177_SYMBOL . $amount,
				(string) CASHU_WC_BIP177_SYMBOL . $fee_reserve,
				(string) CASHU_WC_BIP177_SYMBOL . $ppk_fee,
				(string) CASHU_WC_BIP177_SYMBOL . $total
			)
		);
	}

	private function request_melt_quote_bolt11( string $bolt11 ): array {
		// Setup request
		$endpoint = $this->trusted_mint . '/v1/melt/quote/bolt11';
		$args     = array(
			'timeout' => 10,
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

		// Make request
		$res = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $res ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( 'Mint quote request failed: ' . sanitize_text_field( $res->get_error_message() ) );
		}

		// Check response code is 2xx (OK)
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint quote request failed, HTTP ' . (int) $code );
		}

		// Decode response body
		$body = (string) wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Mint quote response is not JSON.' );
		}

		return $json;
	}

	/**
	 * Get the maximum input_fee_ppk for active "sat" unit keysets from the trusted mint.
	 * Result is cached per mint URL for 1 hour.
	 */
	private function get_max_input_fee_ppk(): int {
		// Return cached if available
		$transient_key = 'cashu_max_sat_input_fee_ppk_' . md5( $this->trusted_mint );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		// Setup and make request
		$endpoint = $this->trusted_mint . '/v1/keysets';
		$res      = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( 'Mint keysets request failed: ' . sanitize_text_field( $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( 'Mint keysets request failed, HTTP ' . $code );
		}
		$body = wp_remote_retrieve_body( $res );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || ! isset( $json['keysets'] ) || ! is_array( $json['keysets'] ) ) {
			throw new \RuntimeException( 'Invalid mint keysets response.' );
		}

		// Filter active sat keysets
		$fees = array_column(
			array_filter(
				$json['keysets'],
				fn( $ks ) => is_array( $ks )
					&& ( $ks['unit'] ?? '' ) === 'sat'
					&& isset( $ks['input_fee_ppk'] )
					&& is_numeric( $ks['input_fee_ppk'] )
			),
			'input_fee_ppk'
		);
		if ( empty( $fees ) ) {
			throw new \RuntimeException( 'No sat keysets with input_fee_ppk found.' );
		}

		// Cache and return
		$max_fee = absint( max( $fees ) );
		set_transient( $transient_key, $max_fee, HOUR_IN_SECONDS );
		return $max_fee;
	}

	private function proof_count_optimal_split( int $amount ): int {
		if ( $amount <= 0 ) {
			return 1;
		}
		$count = 0;
		while ( $amount ) {
			++$count;
			$amount &= ( $amount - 1 ); // clears the lowest set bit
		}
		return $count ?: 1;
	}

	/**
	 * Estimate input fee reserve in sats for paying a given amount using an
	 * optimal split, but using the maximum input_fee_ppk across sat keysets.
	 *
	 * Iterates because covering a fee adds proofs, which increases the fee.
	 */
	private function estimate_input_fee_sats( int $amount_sats ): int {
		$ppk    = $this->get_max_input_fee_ppk();
		$proofs = $this->proof_count_optimal_split( $amount_sats );

		// Set lower bound: fee for paying the amount of proofs
		// plus a "1 proof" ppk fee buffer (ie a rounded up ppk fee).
		$buffer   = intdiv( $ppk + 999, 1000 );
		$fee_sats = intdiv( ( $proofs * $ppk ) + 999, 1000 ) + $buffer;

		// Find the optimal fee that covers "fee-fees" too
		while ( true ) {
			$proofs_for_fee    = $this->proof_count_optimal_split( $fee_sats );
			$total_proofs      = $proofs + $proofs_for_fee;
			$required_fee_sats = intdiv( ( $total_proofs * $ppk ) + 999, 1000 );
			if ( $required_fee_sats <= $fee_sats ) {
				return $fee_sats;
			}
			++$fee_sats;
		}
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

		// Fallback: Ensure spot quote is still valid - in case page is refreshed.
		$quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		$spot_time    = absint( $order->get_meta( '_cashu_spot_time', true ) );
		$spot_expiry  = $spot_time + self::QUOTE_EXPIRY_SECS;
		if ( $spot_expiry < time() || $quote_expiry < $spot_expiry ) {
			try {
				$this->setup_cashu_payment( $order );
				// Reset spot expiry
				$spot_time   = absint( $order->get_meta( '_cashu_spot_time', true ) );
				$spot_expiry = $spot_time + self::QUOTE_EXPIRY_SECS;
			} catch ( \Error $e ) {
				Logger::error( 'Could not setup Cashu payment on receipt page: ' . $e->getMessage() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
			}
		}

		$pay_amount_sats = absint( $order->get_meta( '_cashu_melt_total', true ) );
		$pay_fees_sats   = absint( $order->get_meta( '_cashu_melt_fees', true ) );
		$invoice_total   = $pay_amount_sats - $pay_fees_sats;
		$quote_id        = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$trusted_mint    = (string) $order->get_meta( '_cashu_melt_mint', true );

		wp_enqueue_script( 'cashu-qrcode' );
		wp_enqueue_script( 'cashu-checkout' );
		wp_enqueue_style( 'cashu-public' );
		wp_enqueue_style( 'toastr' );

		echo '<div id="cashu-pay-root"
			data-order-id="' . esc_attr( $order_id ) . '"
			data-order-key="' . esc_attr( $order->get_order_key() ) . '"
			data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"
			data-pay-amount-sats="' . esc_attr( $pay_amount_sats ) . '"
			data-pay-fees-sats="' . esc_attr( $pay_fees_sats ) . '"
			data-melt-quote-id="' . esc_attr( $quote_id ) . '"
			data-spot-quote-expiry="' . esc_attr( $spot_expiry ) . '"
			data-trusted-mint="' . esc_attr( $trusted_mint ) . '"
		></div>';

		?>
		<section id="cashu-payment" class="cashu-checkout" aria-label="<?php echo esc_attr__( 'Cashu payment', 'cashu-for-woocommerce' ); ?>">
			<div class="cashu-amount-box cashu-center">
				<div class="cashu-payamount"><?php esc_html_e( 'Amount Due', 'cashu-for-woocommerce' ); ?></div>
				<h2 class="cashu-amount">
					<?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $pay_amount_sats ); ?>
				</h2>

				<?php $details_id = 'payment-details-' . sanitize_key( $order_id ); ?>
				<div id="<?php echo esc_attr( $details_id ); ?>" class="payment-details js-payment-details" style="display:none;">
					<dl class="payment-dl">
						<div class="payment-row">
							<dt>Total Price</dt>
							<dd><?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $invoice_total ); ?></dd>
						</div>

						<div class="payment-row">
							<dt>Network Cost (est)</dt>
							<dd><?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $pay_fees_sats ); ?></dd>
						</div>

						<div class="payment-row">
							<dt>Amount Due</dt>
							<dd><?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $pay_amount_sats ); ?></dd>
						</div>
					</dl>
					<div class="payment-row">
						<?php
						echo esc_html__( 'Change will be given if network cost is less than estimated.', 'cashu-for-woocommerce' );
						?>
					</div>
				</div>

				<button
					type="button"
					class="payment-details-toggle js-payment-details-toggle"
					aria-controls="<?php echo esc_attr( $details_id ); ?>"
					aria-expanded="false"
				>
					View Details
					<svg class="payment-chevron" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			</div>
			<div class="cashu-box">
				<div id="cashu-status" class="cashu-status" role="status" aria-live="polite">
					<?php
					esc_html_e( 'Status: Waiting for payment...', 'cashu-for-woocommerce' )
					?>
				</div>
				<div class="cashu-qr-wrap">
					<div class="cashu-qr" id="cashu-qr" data-cashu-qr>
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
				</form>
				<div class="cashu-feenote">
					<?php
					printf(
						/* translators: %1$s: Mint hostname */
						esc_html__( 'Tokens accepted from any mint, but fees will be lowest if you use: %1$s', 'cashu-for-woocommerce' ),
						'<strong>' . esc_html( $trusted_mint ) . '</strong>'
					);
					?>
				</div>
			</div>
			<script>
				jQuery(function ($) {
					$(document).on('click', '.js-payment-details-toggle', function () {
						var $btn = $(this);
						var targetId = $btn.attr('aria-controls');
						var $details = $('#' + targetId);

						var isOpen = $btn.attr('aria-expanded') === 'true';

						$btn.attr('aria-expanded', String(!isOpen));
						$btn.toggleClass('is-open', !isOpen);

						// drawer animation
						$details.stop(true, true).slideToggle(180);
					});
				});
			</script>
		</section>
		<?php
	}

	/**
	 * Show change if the order was a Cashu one.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$this->render_change_section();
	}

	/**
	 * Renders the change
	 */
	public function render_change_section() {
		wp_enqueue_style( 'cashu-public' );
		wp_enqueue_script( 'cashu-thanks' );

		echo '<div id="cashu-change-root"></div>';
	}

	public function is_available(): bool {
		// Cashu payment provider enabled
		$enabled = get_option( 'cashu_enabled', 'no' );
		if ( 'yes' !== $enabled ) {
			return false;
		}

		// Global LN address set
		$lightning_address = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $lightning_address ) {
			return false;
		}

		// Global trusted mint set
		$trusted_mint = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( '' === $trusted_mint ) {
			return false;
		}

		// This Gateway enabled
		return 'yes' === $this->enabled;
	}
}
