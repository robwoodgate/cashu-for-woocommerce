<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper for basic Cashu-related utilities.
 *
 * Right now we only use this for fiat→sats conversion when creating
 * Lightning invoices server-side.
 */
class CashuRestRoutes {

	public function __construct() {
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'cashu-wc/v1',
					'/melt-quote',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'cashu_wc_rest_melt_quote' ),
						'permission_callback' => '__return_true',
					)
				);

				register_rest_route(
					'cashu-wc/v1',
					'/confirm-melt',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'cashu_wc_rest_confirm_melt' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	public function cashu_wc_get_order_checked( $order_id, $order_key ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'cashu_wc_no_wc', 'WooCommerce not loaded.', array( 'status' => 500 ) );
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'cashu_wc_no_order', 'Order not found.', array( 'status' => 404 ) );
		}
		if ( ! \hash_equals( $order->get_order_key(), (string) $order_key ) ) {
			return new \WP_Error( 'cashu_wc_bad_key', 'Invalid order key.', array( 'status' => 403 ) );
		}
		return $order;
	}

	public function cashu_wc_json_post( $url, array $body ) {
		$args = array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 20,
			'body'    => wp_json_encode( $body ),
		);
		$res  = wp_remote_post( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'cashu_wc_http',
				'Mint HTTP error.',
				array(
					'status' => 502,
					'code'   => $code,
					'body'   => $data,
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cashu_wc_bad_json', 'Invalid JSON from mint.', array( 'status' => 502 ) );
		}
		return $data;
	}

	public function cashu_wc_json_get( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'cashu_wc_http',
				'Mint HTTP error.',
				array(
					'status' => 502,
					'code'   => $code,
					'body'   => $data,
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'cashu_wc_bad_json', 'Invalid JSON from mint.', array( 'status' => 502 ) );
		}
		return $data;
	}

	/**
	 * You decide how to get a vendor invoice.
	 * Keep it simple, call a merchant controlled endpoint, pass sats, get bolt11 back.
	 * Example response: { "bolt11": "lnbc..." }
	 */
	public function cashu_wc_get_vendor_bolt11( $amount_sats, $order ) {
		$endpoint = get_option( 'cashu_wc_vendor_invoice_endpoint' ); // you store this in settings
		if ( ! $endpoint ) {
			return new \WP_Error( 'cashu_wc_no_vendor_ep', 'Vendor invoice endpoint not configured.', array( 'status' => 500 ) );
		}

		$url = add_query_arg(
			array(
				'amount_sats' => (int) $amount_sats,
				'order_id'    => $order->get_id(),
			),
			$endpoint
		);
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['bolt11'] ) ) {
			return new \WP_Error(
				'cashu_wc_vendor_inv',
				'Failed to fetch vendor invoice.',
				array(
					'status' => 502,
					'code'   => $code,
					'body'   => $data,
				)
			);
		}
		return (string) $data['bolt11'];
	}

	public function cashu_wc_rest_melt_quote( \WP_REST_Request $req ) {
		$order_id  = $req->get_param( 'order_id' );
		$order_key = $req->get_param( 'order_key' );
		$unit      = $req->get_param( 'unit' ) ?: 'sat';
		$amount    = (int) ( $req->get_param( 'amount_sats' ) ?: 0 );

		$order = $this->cashu_wc_get_order_checked( $order_id, $order_key );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( $amount <= 0 ) {
			return new \WP_Error( 'cashu_wc_bad_amount', 'Invalid amount.', array( 'status' => 400 ) );
		}

		$trusted_mint = get_option( 'cashu_wc_trusted_mint_url' );
		if ( ! $trusted_mint ) {
			return new \WP_Error( 'cashu_wc_no_mint', 'Trusted mint not configured.', array( 'status' => 500 ) );
		}

		$bolt11 = $this->cashu_wc_get_vendor_bolt11( $amount, $order );
		if ( is_wp_error( $bolt11 ) ) {
			return $bolt11;
		}

		$mint_url = rtrim( $trusted_mint, '/' );
		$quote    = $this->cashu_wc_json_post(
			$mint_url . '/v1/melt/quote/bolt11',
			array(
				'request' => $bolt11,
				'unit'    => $unit,
			)
		);
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		// Persist a little context now, it helps debugging.
		$order->update_meta_data( '_cashu_tr_mint_url', $trusted_mint );
		$order->update_meta_data( '_cashu_expected_amount_sats', $amount );
		$order->update_meta_data( '_cashu_unit', $unit );
		$order->update_meta_data( '_cashu_tr_melt_quote_id', $quote['quote'] ?? '' );
		$order->update_meta_data( '_cashu_vendor_bolt11', $bolt11 );
		$order->update_meta_data( '_cashu_settlement_state', 'PENDING' );
		$order->save();

		return array(
			'ok'         => true,
			'melt_quote' => $quote,
		);
	}

	public function cashu_wc_rest_confirm_melt( \WP_REST_Request $req ) {
		$order_id      = $req->get_param( 'order_id' );
		$order_key     = $req->get_param( 'order_key' );
		$mint_url      = (string) $req->get_param( 'mint_url' );
		$melt_quote_id = (string) $req->get_param( 'melt_quote_id' );

		$mint_quote_id = (string) ( $req->get_param( 'mint_quote_id' ) ?: '' );
		$mint_invoice  = (string) ( $req->get_param( 'mint_invoice' ) ?: '' );
		$minted_amount = (int) ( $req->get_param( 'minted_amount_sats' ) ?: 0 );

		$change_token  = $req->get_param( 'change_token' );
		$change_amount = (int) ( $req->get_param( 'change_amount_sats' ) ?: 0 );
		$swap_frame    = $req->get_param( 'swap_frame' );

		$order = cashu_wc_get_order_checked( $order_id, $order_key );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! $mint_url || ! $melt_quote_id ) {
			return new \WP_Error( 'cashu_wc_missing', 'Missing mint_url or melt_quote_id.', array( 'status' => 400 ) );
		}

		$mint_url = rtrim( $mint_url, '/' );
		$quote    = $this->cashu_wc_json_get( $mint_url . '/v1/melt/quote/bolt11/' . rawurlencode( $melt_quote_id ) );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$state = $quote['state'] ?? 'UNKNOWN';

		// Persist metadata regardless of state.
		if ( $mint_quote_id ) {
			$order->update_meta_data( '_cashu_tr_mint_quote_id', $mint_quote_id );
		}
		if ( $mint_invoice ) {
			$order->update_meta_data( '_cashu_tr_mint_bolt11', $mint_invoice );
		}
		if ( $minted_amount ) {
			$order->update_meta_data( '_cashu_tr_minted_amount_sats', $minted_amount );
		}

		if ( ! empty( $change_token ) && is_string( $change_token ) ) {
			$order->update_meta_data( '_cashu_change_token_v4', $change_token );
			$order->update_meta_data( '_cashu_change_amount_sats', $change_amount );
		}
		if ( ! empty( $swap_frame ) && is_string( $swap_frame ) ) {
			$order->update_meta_data( '_cashu_swap_frame_json', $swap_frame );
		}

		if ( 'PAID' === $state ) {
			$order->update_meta_data( '_cashu_settlement_state', 'PAID' );
			$order->update_meta_data( '_cashu_settled_at', time() );
			$order->payment_complete();
			$order->save();

			return array(
				'ok'       => true,
				'state'    => 'PAID',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		$order->update_meta_data( '_cashu_settlement_state', $state );
		$order->save();

		return array(
			'ok'       => true,
			'state'    => $state,
			'redirect' => '',
		);
	}
}
