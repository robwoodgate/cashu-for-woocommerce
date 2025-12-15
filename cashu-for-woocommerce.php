<?php
/**
 * Plugin Name: Cashu For WooCommerce
 * Plugin URI:  https://www.github.com/robwoodgate
 * Description: Cashu is a free and open source ecash protocol for Bitcoin, this plugin allows you to receive Cashu payments in Bitcoin, directly to your lightning address, with no fees or transaction costs.
 * Author:      Rob Woodgate
 * Author URI:  https://www.github.com/robwoodgate
 * Text Domain: cashu-for-woocommerce
 * Domain Path: /languages
 * Version:     0.1.0
 *
 * @package     Cashu_For_Woocommerce
 */

defined( 'ABSPATH' ) || exit();

define( 'CASHU_WC_VERSION', '0.1.0' );
define( 'CASHU_WC_VERSION_KEY', 'CASHU_WC_VERSION' );
define( 'CASHU_WC_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CASHU_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHU_WC_PLUGIN_ID', 'cashu-for-woocommerce' );

/**
 * Load Composer autoloader if present, otherwise register a simple PSR-4 autoloader
 * for the Cashu\WC namespace.
 */
$cashu_wc_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $cashu_wc_autoload ) ) {
	require_once $cashu_wc_autoload;
} else {
	spl_autoload_register(
		function ( $classname ) {
			if ( strpos( $classname, 'Cashu\\WC\\' ) !== 0 ) {
				return;
			}
			$relative      = substr( $classname, strlen( 'Cashu\\WC\\' ) );
			$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
			$file          = CASHU_WC_PLUGIN_FILE_PATH . 'src/' . $relative_path;

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Initialise gateway and settings once plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		// Bail if WooCommerce is not active.
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Load textdomain.
		load_plugin_textdomain(
			'cashu-for-woocommerce',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		// Register payment gateway class.
		require_once CASHU_WC_PLUGIN_FILE_PATH . 'src/Gateway/CashuGateway.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'Cashu\\WC\\Gateway\\CashuGateway';
				return $gateways;
			}
		);
	}
);

/**
 * Register global settings page.
 */
add_filter(
	'woocommerce_get_settings_pages',
	function ( $pages ) {
		if ( ! is_admin() || ! class_exists( 'WC_Settings_Page' ) ) {
			return $pages;
		}

		require_once CASHU_WC_PLUGIN_FILE_PATH . 'src/Admin/GlobalSettings.php';

		$pages[] = new \Cashu\WC\Admin\GlobalSettings();

		return $pages;
	}
);

/**
 * Register WooCommerce Blocks integration.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\PaymentMethodRegistry' ) ) {
			return;
		}

		require_once CASHU_WC_PLUGIN_FILE_PATH . 'src/Blocks/CashuGatewayBlocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function (
				$payment_method_registry
			) {
				$payment_method_registry->register( new \Cashu\WC\Blocks\CashuGatewayBlocks() );
			}
		);
	}
);

// Admin scripts for dismissing review notice.
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'cashu-backend',
			CASHU_WC_PLUGIN_URL . 'assets/js/backend/notifications.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);

		wp_localize_script(
			'cashu-backend',
			'cashuNotifications',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cashu-notifications-nonce' ),
			)
		);
	}
);

// AJAX handler for review notice dismissal.
add_action(
	'wp_ajax_cashu_notifications',
	function () {
		if ( ! check_ajax_referer( 'cashu-notifications-nonce', 'nonce', false ) ) {
			wp_die( 'Unauthorized', '', array( 'response' => 401 ) );
		}
		wp_send_json_success();
	}
);

/**
 * Enqueue frontend checkout script that uses the client-side melt flow
 * from assets/dist/cashu-checkout.js (compiled from src/checkout.ts).
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// Only enqueue if Cashu is enabled globally.
		if ( 'yes' !== get_option( 'cashu_enabled', 'no' ) ) {
			return;
		}

		$script_handle = 'cashu-checkout';
		$script_path   = CASHU_WC_PLUGIN_FILE_PATH . 'assets/dist/cashu-checkout.js';
		$script_url    = CASHU_WC_PLUGIN_URL . 'assets/dist/cashu-checkout.js';

		if ( ! file_exists( $script_path ) ) {
			// Do not break the site if the bundle has not been built yet.
			return;
		}

		wp_enqueue_script( $script_handle, $script_url, array(), CASHU_WC_VERSION, true );

		$order_id = null;
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			// Typical order-pay endpoint, eg. ?order-pay=123.
			$order_id = isset( $_GET['order-pay'] ) ? absint( wp_unslash( $_GET['order-pay'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		}

		wp_localize_script(
			$script_handle,
			'cashu_wc',
			array(
				'ajax_url'          => admin_url( 'admin-ajax.php' ),
				'nonce_invoice'     => wp_create_nonce( 'cashu_generate_invoice' ),
				'nonce_confirm'     => wp_create_nonce( 'cashu_confirm_payment' ),
				'lightning_address' => get_option( 'cashu_lightning_address', '' ),
				'order_id'          => $order_id,
			)
		);
	}
);

/**
 * AJAX: generate a BOLT11 invoice for the current order total
 * using the configured Lightning address and Coingecko rates.
 */
function cashu_wc_ajax_generate_invoice() {
	if ( ! check_ajax_referer( 'cashu_generate_invoice', 'nonce', false ) ) {
		wp_send_json_error( __( 'Invalid security token.', 'cashu-for-woocommerce' ) );
	}

	if ( ! class_exists( 'WC_Order' ) ) {
		wp_send_json_error( __( 'WooCommerce is not loaded.', 'cashu-for-woocommerce' ) );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $order_id ) {
		wp_send_json_error( __( 'Missing order ID.', 'cashu-for-woocommerce' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( __( 'Order not found.', 'cashu-for-woocommerce' ) );
	}

	$lightning_address = trim( get_option( 'cashu_lightning_address', '' ) );
	if ( '' === $lightning_address ) {
		wp_send_json_error(
			__( 'Lightning address is not configured.', 'cashu-for-woocommerce' )
		);
	}

	// Convert order total in store currency to sats.
	try {
		$helper   = new \Cashu\WC\Helpers\CashuHelper();
		$total    = (float) $order->get_total();
		$currency = $order->get_currency() ?: 'USD';
		$sats     = $helper->fiatToSats( $total, $currency );
	} catch ( \Throwable $e ) {
		if ( class_exists( '\Cashu\WC\Helpers\Logger' ) ) {
			\Cashu\WC\Helpers\Logger::debug(
				'Invoice sats calculation failed: ' . $e->getMessage(),
				true
			);
		}
		wp_send_json_error( __( 'Failed to calculate sats amount.', 'cashu-for-woocommerce' ) );
	}

	if ( $sats <= 0 ) {
		wp_send_json_error( __( 'Calculated sats amount is invalid.', 'cashu-for-woocommerce' ) );
	}

	// Persist expected amount so we can sanity-check later if desired.
	update_post_meta( $order_id, '_cashu_expected_amount', $sats );
	update_post_meta( $order_id, '_cashu_expected_unit', 'sat' );

	try {
		$invoice = \Cashu\WC\Helpers\LightningAddress::get_invoice( $lightning_address, $sats );
	} catch ( \Throwable $e ) {
		if ( class_exists( '\Cashu\WC\Helpers\Logger' ) ) {
			\Cashu\WC\Helpers\Logger::debug(
				'LNURL invoice fetch failed: ' . $e->getMessage(),
				true
			);
		}
		wp_send_json_error(
			__( 'Failed to request invoice from Lightning address.', 'cashu-for-woocommerce' )
		);
	}

	if ( empty( $invoice ) ) {
		wp_send_json_error(
			__( 'Empty invoice returned from Lightning service.', 'cashu-for-woocommerce' )
		);
	}

	wp_send_json_success(
		array(
			'invoice' => $invoice,
		)
	);
}
add_action( 'wp_ajax_cashu_generate_invoice', 'cashu_wc_ajax_generate_invoice' );
add_action( 'wp_ajax_nopriv_cashu_generate_invoice', 'cashu_wc_ajax_generate_invoice' );

/**
 * AJAX: called after the client-side melt has succeeded.
 * We trust the JS wallet for now and mark the order as paid.
 * If you later wire in server-side proof checks, you can harden this.
 */
function cashu_wc_ajax_confirm_payment() {
	if ( ! check_ajax_referer( 'cashu_confirm_payment', 'nonce', false ) ) {
		wp_send_json_error( __( 'Invalid security token.', 'cashu-for-woocommerce' ) );
	}

	if ( ! class_exists( 'WC_Order' ) ) {
		wp_send_json_error( __( 'WooCommerce is not loaded.', 'cashu-for-woocommerce' ) );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $order_id ) {
		wp_send_json_error( __( 'Missing order ID.', 'cashu-for-woocommerce' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		wp_send_json_error( __( 'Order not found.', 'cashu-for-woocommerce' ) );
	}

	if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
		$order->payment_complete();
		$order->add_order_note(
			__( 'Cashu payment confirmed, client side melt completed.', 'cashu-for-woocommerce' )
		);
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_cashu_confirm_payment', 'cashu_wc_ajax_confirm_payment' );
add_action( 'wp_ajax_nopriv_cashu_confirm_payment', 'cashu_wc_ajax_confirm_payment' );
