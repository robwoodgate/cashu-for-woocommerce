<?php

declare(strict_types=1);

namespace Cashu\WC\Gateway;

use Automattic\WooCommerce\Enums\OrderStatus;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\Logger;
use Cashu\WC\Helpers\LightningAddress;
use WC_Order;
use WP_Error;

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
		$mint               = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		$this->trusted_mint = rtrim( $mint, '/' );
		$this->ln_address   = trim( (string) get_option( 'cashu_lightning_address', '' ) );

		// Load / save settings
		$this->init_settings();
		\add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				// Actions expect void return
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
	 * Limit size of default icon
	 */
	public function get_icon() {
		$icon = parent::get_icon();
		return str_replace( 'src=', 'style="max-width:40px;" src=', $icon );
	}

	/**
	 * Enqueue checkout script on pages where it is needed.
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

		// Enqueue and localize
		wp_register_script(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/dist/cashu-checkout.js',
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

		wp_register_style(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/css/cashu-modal.css',
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
		$order_total_sats = $this->get_total_sats( $order );
		if ( is_wp_error( $order_total_sats ) ) {
			return $order_total_sats;
		}

		// Create or reuse melt quote, store fee reserve, set headline expected amount.
		$this->ensure_melt_quote_for_order( $order, $order_total_sats );

		$order->save();

		return true;
	}

	/**
	 * Determine the invoice amount in sats, reusing any existing value.
	 */
	private function get_total_sats( WC_Order $order ): int|\WP_Error {
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
			return new \WP_Error( 'cashu_quote_failed', 'Cashu quote failed.' );
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
		$order->update_meta_data( '_cashu_melt_total', $amount + $fee_reserve );

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: sats invoice amount, %2$s: fee reserve sats, %3$s: total required sats */
				__( 'Cashu melt quote created, invoice: %1$s sats, fee_reserve: %2$s sats, total: %3$s sats', 'cashu-for-woocommerce' ),
				(string) $amount,
				(string) $fee_reserve,
				(string) ( $amount + $fee_reserve )
			)
		);
	}

	private function request_melt_quote_bolt11( string $bolt11 ): array {
		// Setup request
		$endpoint = $this->trusted_mint . '/v1/melt/quote/bolt11';
		$args     = array(
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

		// Fallback: Ensure quote is still valid - in case receipt page is refreshed later.
		// Recreate if it expires within the next 15 minutes (or already expired)
		$quote_expiry = absint( $order->get_meta( '_cashu_melt_quote_expiry', true ) );
		if ( $quote_expiry <= time() + self::QUOTE_EXPIRY_SECS ) {
			$result = $this->setup_cashu_payment( $order );
			if ( is_wp_error( $result ) ) {
				Logger::error( 'Could not setup Cashu payment on receipt page: ' . $result->get_error_message() );
				wc_add_notice( __( 'Cashu payment setup failed, please try again.', 'cashu-for-woocommerce' ), 'error' );
				return;
			}
		}

		$pay_amount_sats = absint( $order->get_meta( '_cashu_melt_total', true ) );
		$quote_id        = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		$trusted_mint    = (string) $order->get_meta( '_cashu_melt_mint', true );
		$mint_host       = preg_replace( '/^www\./i', '', (string) wp_parse_url( $trusted_mint, PHP_URL_HOST ) );

		wp_enqueue_script( 'cashu-qrcode' );
		wp_enqueue_script( 'cashu-checkout' );
		wp_enqueue_style( 'cashu-checkout' );
		wp_enqueue_style( 'toastr' );

		echo '<div id="cashu-pay-root"
			data-order-id="' . esc_attr( $order_id ) . '"
			data-order-key="' . esc_attr( $order->get_order_key() ) . '"
			data-return-url="' . esc_url( $this->get_return_url( $order ) ) . '"
			data-pay-amount-sats="' . esc_attr( $pay_amount_sats ) . '"
			data-melt-quote-id="' . esc_attr( $quote_id ) . '"
			data-melt-quote-expiry="' . esc_attr( $quote_expiry ) . '"
			data-trusted-mint="' . esc_attr( $trusted_mint ) . '"
		></div>';

		?>
		<section id="cashu-payment" class="cashu-checkout" aria-label="<?php echo esc_attr__( 'Cashu payment', 'cashu-for-woocommerce' ); ?>">
			<div class="cashu-amount-box cashu-center">
				<div class="cashu-paywith"><?php esc_html_e( 'Pay', 'cashu-for-woocommerce' ); ?></div>
				<h2 class="cashu-amount">
					<?php echo esc_html( CASHU_WC_BIP177_SYMBOL . $pay_amount_sats ); ?>
				</h2>
				<div class="cashu-paywith"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></div>
			</div>
			<div class="cashu-box">
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

					<p class="cashu-foot">
						<?php
						printf(
							/* translators: %1$s: Mint hostname */
							esc_html__( 'Fees will be lower if you use %1$s', 'cashu-for-woocommerce' ),
							'<strong>' . esc_html( $mint_host ) . '</strong>'
						);
						?>
					</p>
				</form>
			</div>
		</section>
		<?php
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
