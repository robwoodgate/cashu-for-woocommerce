<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ConfirmMeltQuoteController {

	private const REST_NAMESPACE = 'cashu-wc/v1';

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/confirm-melt-quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm_melt_quote' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'order_key' => array(
						'type'     => 'string',
						'required' => true,
					),
					'order_id'  => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		// Check WooCommerce is loaded.
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		// Check we got an order ID & Key
		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = sanitize_text_field( (string) $request->get_param( 'order_key' ) );
		if ( '' === $order_key || $order_id <= 0 ) {
			return false;
		}

		// Check this is a valid order.
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Check order key is valid for order.
		return $order->key_is_valid( $order_key );
	}

	public function confirm_melt_quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = (int) $request->get_param( 'order_id' );
		if ( $order_id <= 0 ) {
			return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'cashu_no_order', 'Order not found.', array( 'status' => 404 ) );
		}

		// Only for this gateway.
		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return new WP_Error( 'cashu_wrong_gateway', 'Order is not using Cashu.', array( 'status' => 400 ) );
		}

		// Store any change (idempotent)
		$change_token = (string) $request->get_param( 'change_token' );
		$change_saved = (string) $order->get_meta( '_cashu_change', true );
		if ( '' === $change_saved && '' !== $change_token ) {
			$order->update_meta_data( '_cashu_change', $change_token );
		}
		$order->save();

		// Is already paid?
		if ( $order->is_paid() ) {
			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		$trusted_mint = trim( (string) get_option( 'cashu_trusted_mint', '' ) );
		if ( '' === $trusted_mint ) {
			return new WP_Error( 'cashu_no_mint', 'Trusted mint not configured.', array( 'status' => 500 ) );
		}

		$quote_id = (string) $order->get_meta( '_cashu_melt_quote_id', true );
		if ( '' === $quote_id ) {
			return new WP_Error( 'cashu_no_quote', 'Missing melt quote id on order.', array( 'status' => 400 ) );
		}

		$expiry = (int) $order->get_meta( '_cashu_melt_quote_expiry', true );
		if ( $expiry > 0 && time() > $expiry ) {
			return rest_ensure_response(
				array(
					'ok'     => true,
					'state'  => 'EXPIRED',
					'expiry' => $expiry,
				)
			);
		}

		$trusted_mint = rtrim( $trusted_mint, '/' );
		$url          = $trusted_mint . '/v1/melt/quote/bolt11/' . rawurlencode( $quote_id );

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			Logger::debug( 'Mint quote state request failed: ' . $res->get_error_message() );
			return new WP_Error( 'cashu_mint_error', 'Failed to query mint quote state.', array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );

		if ( 200 !== $code ) {
			Logger::debug( 'Mint quote state HTTP ' . $code . ' body: ' . $body );
			return new WP_Error( 'cashu_mint_http', 'Mint returned a non 200 response.', array( 'status' => 502 ) );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['state'] ) ) {
			Logger::debug( 'Mint quote state invalid JSON: ' . $body );
			return new WP_Error( 'cashu_mint_json', 'Mint returned invalid JSON.', array( 'status' => 502 ) );
		}

		$state = (string) $data['state'];

		// Persist last seen state for debugging.
		$order->update_meta_data( '_cashu_melt_quote_state', $state );
		if ( isset( $data['payment_preimage'] ) && is_string( $data['payment_preimage'] ) && '' !== $data['payment_preimage'] ) {
			$order->update_meta_data( '_cashu_payment_preimage', $data['payment_preimage'] );
		}
		$order->save();

		if ( 'PAID' === $state ) {
			// Idempotent, do nothing if already completed.
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $quote_id );
				$order->add_order_note( sprintf( 'Cashu melt quote paid: %s', $quote_id ) );
			}

			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		// UNPAID, PENDING, FAILED, etc.
		return rest_ensure_response(
			array(
				'ok'     => true,
				'state'  => $state,
				'expiry' => isset( $data['expiry'] ) ? (int) $data['expiry'] : null,
			)
		);
	}
}
