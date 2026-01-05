<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ConfirmMeltQuoteController {

	private const REST_NAMESPACE = 'cashu-wc/v1';

	// Store each token as its own meta row to avoid “first writer wins” races.
	private const META_CHANGE_TOKENS     = '_cashu_change_tokens';
	private const META_CHANGE_LATEST     = '_cashu_change_latest';
	private const META_CHANGE_UPDATED_AT = '_cashu_change_updated_at';

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/confirm-melt-quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm_melt_quote' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'order_key'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'order_id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'change_tokens' => array(
						'type'     => 'array',
						'required' => false,
						'items'    => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	public function permission_callback( WP_REST_Request $request ): bool {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = sanitize_text_field( $order_key );

		if ( '' === $order_key || $order_id <= 0 ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

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

		if ( $order->get_payment_method() !== 'cashu_default' ) {
			return new WP_Error( 'cashu_wrong_gateway', 'Order is not using Cashu.', array( 'status' => 400 ) );
		}

		// Store change tokens (append, dedupe).
		// Do this early so we keep tokens even if paid already.
		$tokens = $this->normalise_change_tokens( $request->get_param( 'change_tokens' ) );
		if ( ! empty( $tokens ) ) {
			$did_add = $this->merge_change_tokens( $order, $tokens );
			if ( $did_add ) {
				$order->update_meta_data( self::META_CHANGE_UPDATED_AT, time() );
				$order->save();
			}
		}

		// Already paid, nothing else to do.
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
		$payment_preimage = '';
		if ( isset( $data['payment_preimage'] ) && is_string( $data['payment_preimage'] ) && '' !== $data['payment_preimage'] ) {
			$payment_preimage = $data['payment_preimage'];
			$order->update_meta_data( '_cashu_payment_preimage', $payment_preimage );
		}
		$order->save();

		if ( 'PAID' === $state ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $quote_id );
				$order->add_order_note(
					sprintf(
						/* translators: %1$s: Melt Quote, %2$s: Payment preimage */
						__( "Cashu melt quote paid: %1\$s\nPayment preimage: %2\$s", 'cashu-for-woocommerce' ),
						$quote_id,
						$payment_preimage
					)
				);
			}

			return rest_ensure_response(
				array(
					'ok'       => true,
					'state'    => 'PAID',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'state'  => $state,
				'expiry' => isset( $data['expiry'] ) ? (int) $data['expiry'] : null,
			)
		);
	}

	/**
	 * @return string[]
	 */
	private function normalise_change_tokens( mixed $param ): array {
		if ( ! is_array( $param ) ) {
			return array();
		}

		$out = array();
		foreach ( $param as $t ) {
			$s = trim( (string) $t );
			if ( '' === $s ) {
				continue;
			}

			// Lightweight sanity checks, avoid accidental garbage and runaway payload sizes.
			if ( strlen( $s ) > 15000 ) {
				continue;
			}
			if ( 0 !== stripos( $s, 'cashu' ) ) {
				continue;
			}

			$out[] = $s;

			// Hard cap per request to prevent abuse.
			if ( count( $out ) >= 10 ) {
				break;
			}
		}

		// Dedupe within the request
		$out = array_values( array_unique( $out ) );

		return $out;
	}

	/**
	 * Append new tokens as separate meta rows (deduped against existing),
	 * this avoids clobbering if two confirms hit close together.
	 *
	 * @param WC_Order $order
	 * @param string[] $tokens
	 */
	private function merge_change_tokens( WC_Order $order, array $tokens ): bool {
		$existing = $this->get_existing_change_tokens( $order );
		$added    = false;

		foreach ( $tokens as $t ) {
			if ( in_array( $t, $existing, true ) ) {
				continue;
			}
			$order->add_meta_data( self::META_CHANGE_TOKENS, $t, false );
			$existing[] = $t;
			$added      = true;
		}

		if ( $added ) {
			$order->update_meta_data( self::META_CHANGE_LATEST, end( $existing ) ?: '' );
		}

		return $added;
	}

	/**
	 * @return string[]
	 */
	private function get_existing_change_tokens( WC_Order $order ): array {
		$vals = $order->get_meta( self::META_CHANGE_TOKENS, false );
		if ( ! is_array( $vals ) ) {
			return array();
		}

		$out = array();
		foreach ( $vals as $val ) {
			$v = ( $val instanceof \WC_Meta_Data ) ? $val->value : $val;
			$s = trim( (string) $v );
			if ( '' !== $s ) {
				$out[] = $s;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
