<?php

namespace Cashu\WC;

use Cashu\WC\Admin\GlobalSettings;
use Cashu\WC\Admin\Notice;
use Cashu\WC\Gateway\CashuGateway;
use Cashu\WC\Helpers\CashuHelper;
use Cashu\WC\Helpers\ConfirmMeltQuoteController;
use Cashu\WC\Helpers\Logger;

final class CashuWCPlugin {

	private static ?self $instance = null;

	public function __construct() {
		// Intentionally empty, no side effects.
	}

	/**
	 * Singleton instance
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks, this is the only place that adds actions and filters.
	 */
	public function run(): void {
		$this->includes();

		// Core boot.
		add_action( 'init', array( $this, 'loadTextdomain' ) );

		// WooCommerce integration.
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'initPaymentGateways' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declareWooCompat' ) );

		// Thank you page for cashu_default gateway.
		add_action( 'woocommerce_thankyou_cashu_default', array( self::class, 'orderStatusThankYouPage' ), 10, 1 );

		// Order display extras.
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'addCashuOrderItemTotals' ), 20, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'renderChangeTokenSection' ), 20, 1 );

		// Settings page.
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'registerSettingsPage' ) );

		// Blocks support.
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'blocksSupport' ) );

		// REST Routes
		add_action(
			'rest_api_init',
			function (): void {
				$controller = new ConfirmMeltQuoteController();
				$controller->register_routes();
			}
		);

		// Admin and frontend assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminScripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFrontendScripts' ) );

		// Admin AJAX.
		add_action( 'wp_ajax_cashu_notifications', array( $this, 'processAjaxNotification' ) );

		// Plugin list action links.
		add_filter( 'plugin_action_links_' . CASHU_WC_PLUGIN_FILE_PATH, array( $this, 'addPluginActionLinks' ) );

		// Admin notices, only in wp admin.
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'registerAdminNotices' ) );
		}
	}

	private function includes(): void {
		$autoloader = CASHU_WC_PLUGIN_FILE_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		}

		// Make sure WP internal functions are available.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain(
			'cashu-for-woocommerce',
			false,
			dirname( CASHU_WC_PLUGIN_FILE_PATH ) . '/languages/'
		);
	}

	public static function initPaymentGateways( array $gateways ): array {
		$gateways[] = CashuGateway::class;
		return $gateways;
	}

	public function registerSettingsPage( array $pages ): array {
		if ( ! is_admin() ) {
			return $pages;
		}
		$pages[] = new GlobalSettings();
		return $pages;
	}

	public function declareWooCompat(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				CASHU_WC_PLUGIN_FILE_PATH,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				CASHU_WC_PLUGIN_FILE_PATH,
				true
			);
		}
	}

	public static function blocksSupport(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
				// Keep only the correct class here (see notes below).
				$registry->register( new \Cashu\WC\Blocks\CashuGatewayBlocks() );
			}
		);
	}

	public function enqueueAdminScripts( string $hook ): void {
		// Only load on WooCommerce settings screens, where the notice lives.
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'cashu-notifications',
			CASHU_WC_PLUGIN_URL . 'assets/js/backend/notifications.js',
			array( 'jquery' ),
			CASHU_WC_VERSION,
			true
		);

		wp_localize_script(
			'cashu-notifications',
			'cashuNotifications',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'cashu-notifications-nonce' ),
			)
		);
	}

	public function enqueueFrontendScripts(): void {
		// Only needed during checkout related pages.
		$is_order_pay = function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page();
		$is_checkout  = function_exists( 'is_checkout' ) && is_checkout();

		if ( ! $is_checkout && ! $is_order_pay ) {
			return;
		}

		$script_path = CASHU_WC_PLUGIN_FILE_PATH . 'assets/dist/cashu-checkout.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/dist/cashu-checkout.js',
			array(),
			CASHU_WC_VERSION,
			true
		);

		wp_enqueue_style(
			'cashu-checkout',
			CASHU_WC_PLUGIN_URL . 'assets/css/cashu-modal.css',
			array(),
			CASHU_WC_VERSION
		);

		$order_id = null;
		if ( $is_order_pay ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = isset( $_GET['order-pay'] ) ? absint( wp_unslash( $_GET['order-pay'] ) ) : null;
		}

		wp_localize_script(
			'cashu-checkout',
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

	public function processAjaxNotification(): void {
		if ( ! check_ajax_referer( 'cashu-notifications-nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$dismiss_forever_raw = isset( $_POST['dismiss_forever'] ) ? wp_unslash( $_POST['dismiss_forever'] ) : '0';
		$dismissForever      = filter_var( $dismiss_forever_raw, FILTER_VALIDATE_BOOL );

		if ( $dismissForever ) {
			update_option( 'cashu_review_dismissed_forever', true );
		} else {
			set_transient( 'cashu_review_dismissed', true, DAY_IN_SECONDS * 30 );
		}

		wp_send_json_success();
	}

	public function registerAdminNotices(): void {
		$this->dependenciesNotification();
		$this->notConfiguredNotification();
		$this->submitReviewNotification();
	}

	private function dependenciesNotification(): void {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			Notice::addNotice(
				'error',
				sprintf(
					'Your PHP version is %s but Cashu Payment plugin requires version 7.4+.',
					PHP_VERSION
				)
			);
		}

		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			Notice::addNotice( 'error', 'WooCommerce seems to be not installed. Make sure you do before you activate Cashu Payment Gateway.' );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			Notice::addNotice( 'error', 'The PHP cURL extension is not installed. Make sure it is available otherwise this plugin will not work.' );
		}
	}

	private function notConfiguredNotification(): void {
		if ( ! CashuHelper::getConfig() ) {
			$message = sprintf(
				'Plugin not configured yet, please %1$sconfigure the plugin here%2$s',
				'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=cashu_settings' ) ) . '">',
				'</a>'
			);

			Notice::addNotice( 'error', $message );
		}
	}

	private function submitReviewNotification(): void {
		if ( get_option( 'cashu_review_dismissed_forever' ) || get_transient( 'cashu_review_dismissed' ) ) {
			return;
		}

		$reviewMessage = sprintf(
			'Thank you for using Cashu for WooCommerce! If you like the plugin, we would love if you %1$sleave us a review%2$s. %3$sRemind me later%4$s %5$sStop reminding me forever%6$s',
			'<a href="https://wordpress.org/support/plugin/cashu-for-woocommerce/reviews/?filter=5#new-post" target="_blank" rel="noopener noreferrer">',
			'</a>',
			'<button class="cashu-review-dismiss" type="button">',
			'</button>',
			'<button class="cashu-review-dismiss-forever" type="button">',
			'</button>'
		);

		Notice::addNotice( 'info', $reviewMessage, false, 'cashu-review-notice' );
	}

	public static function orderStatusThankYouPage( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status = $order->get_status();

		switch ( $status ) {
			case 'pending':
				$statusDesc = 'Waiting payment';
				break;
			case 'on-hold':
				$statusDesc = 'Waiting for payment settlement';
				break;
			case 'processing':
				$statusDesc = 'Payment settled';
				break;
			case 'completed':
				$statusDesc = 'Order completed';
				break;
			case 'failed':
			case 'cancelled':
				$statusDesc = 'Payment failed';
				break;
			default:
				$statusDesc = ucfirst( $status );
				break;
		}

		echo '<section class="woocommerce-order-payment-status">';
		echo '<h2 class="woocommerce-order-payment-status-title">Order Status</h2>';
		echo '<p><strong>' . esc_html( $statusDesc ) . '</strong></p>';
		echo '</section>';
	}

	public function addCashuOrderItemTotals( array $totals, \WC_Order $order ): array {
		// Only show for our gateway id
		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return $totals;
		}

		$sats = (int) $order->get_meta( '_cashu_expected_amount' );
		if ( $sats <= 0 ) {
			return $totals;
		}

		$symbol = defined( 'CASHU_WC_BIP177_SYMBOL' ) ? CASHU_WC_BIP177_SYMBOL : '';

		$totals['cashu_expected_amount'] = array(
			'label' => __( 'Cashu Amount', 'cashu-for-woocommerce' ),
			'value' => esc_html( $symbol . number_format_i18n( $sats ) ),
		);

		return $totals;
	}

	public function renderChangeTokenSection( \WC_Order $order ): void {
		// Only show for our gateway id
		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return;
		}

		$change = (string) $order->get_meta( '_cashu_change' );
		if ( ! $change ) {
			return;
		}

		echo '<section class="woocommerce-cashu-details">';
		echo '<h2>' . esc_html__( 'Your Cashu change token', 'cashu-for-woocommerce' ) . '</h2>';
		echo '<textarea name="cashu_change" id="cashu_change" rows="10" cols="50" readonly="readonly" class="large-text" onclick="this.focus();this.select();" style="font-size:1rem;line-height:1.5;padding:1rem;">' . esc_textarea( $change ) . '</textarea>';
		echo '</section>';
	}

	public function addPluginActionLinks( array $links ): array {
		$settings_url = esc_url(
			add_query_arg(
				array(
					'page' => 'wc-settings',
					'tab'  => 'cashu_settings',
				),
				admin_url( 'admin.php' )
			)
		);

		$settings_link = sprintf( '<a href="%s">Settings</a>', $settings_url );

		$logs_link = '';
		if ( class_exists( Logger::class ) && method_exists( Logger::class, 'getLogFileUrl' ) ) {
			$logs_link = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">Debug log</a>',
				esc_url( Logger::getLogFileUrl() )
			);
		}

		$docs_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">Docs</a>',
			esc_url( 'https://dev.cashu.sv/examples/woocommerce-plugin/' )
		);

		$support_link = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">Support</a>',
			esc_url( 'https://www.cashu.sv/en/support' )
		);

		$prepend = array_filter( array( $settings_link, $logs_link, $docs_link, $support_link ) );
		return array_merge( $prepend, $links );
	}
}
