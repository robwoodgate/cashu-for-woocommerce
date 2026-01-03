<?php
declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper for resolving Lightning addresses to BOLT11 invoices.
 */
class LightningAddress {
	/**
	 * Get a BOLT11 invoice for the given Lightning address and amount in sats.
	 *
	 * If $address already looks like a BOLT11 invoice (lnbc* / lntb*), it is returned as-is.
	 *
	 * @param string $address     Lightning address eg "you@example.com" or a BOLT11 invoice.
	 * @param int    $amount_sats amount in sats
	 *
	 * @throws \RuntimeException when the address is invalid or the LNURL flow fails
	 */
	public static function get_invoice( string $address, int $amount_sats ): string {
		$address = trim( $address );

		// If it already looks like a BOLT11, just return it.
		if (
		0 === stripos( $address, 'lnbc' ) || // mainnet
		0 === stripos( $address, 'lntb' ) || // testnet
		0 === stripos( $address, 'lnbcrt' )  // regtest (local)
		) {
			return $address;
		}

		if ( false === strpos( $address, '@' ) ) {
			throw new \RuntimeException( 'Invalid Lightning address.' );
		}

		[$name, $host] = explode( '@', $address, 2 );

		$lnurlp_url = sprintf(
			'https://%s/.well-known/lnurlp/%s',
			$host,
			rawurlencode( $name )
		);

		$meta_response = wp_remote_get(
			$lnurlp_url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $meta_response ) ) {
			throw new \RuntimeException( 'Failed to fetch LNURL metadata.' );
		}

		$meta_code = wp_remote_retrieve_response_code( $meta_response );
		$meta_body = json_decode( wp_remote_retrieve_body( $meta_response ), true );

		if ( 200 !== $meta_code || ! is_array( $meta_body ) || empty( $meta_body['callback'] ) ) {
			throw new \RuntimeException( 'Invalid LNURL metadata response.' );
		}

		$amount_msat = $amount_sats * 1000;

		$invoice_url = add_query_arg(
			array(
				'amount' => $amount_msat,
			),
			$meta_body['callback']
		);

		$inv_response = wp_remote_get(
			$invoice_url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $inv_response ) ) {
			throw new \RuntimeException( 'Failed to request invoice.' );
		}

		$inv_code = wp_remote_retrieve_response_code( $inv_response );
		$inv_body = json_decode( wp_remote_retrieve_body( $inv_response ), true );

		if ( 200 !== $inv_code || empty( $inv_body['pr'] ) ) {
			throw new \RuntimeException( 'Invalid invoice response.' );
		}

		return $inv_body['pr'];
	}

	private function fetch_ln_invoice( int $amount_sats ): ?string {
		$destination = trim( (string) get_option( 'cashu_lightning_address', '' ) );
		if ( '' === $destination ) {
			return null;
		}

		// Lightning address.
		if ( str_contains( $destination, '@' ) ) {
			$parts = explode( '@', $destination );
			if ( 2 !== count( $parts ) ) {
				return null;
			}
			$url  = 'https://' . $parts[1] . '/.well-known/lnurlp/' . $parts[0];
			$resp = wp_remote_get( $url );
			if ( is_wp_error( $resp ) ) {
				Logger::debug( 'Lightning address lnurlp error, ' . $resp->get_error_message() );

				return null;
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( empty( $data['callback'] ) ) {
				return null;
			}

			$callback = add_query_arg( 'amount', $amount_sats * 1000, $data['callback'] );
			$resp     = wp_remote_get( $callback );
			if ( is_wp_error( $resp ) ) {
				Logger::debug( 'Lightning address callback error, ' . $resp->get_error_message() );

				return null;
			}
			$json = json_decode( wp_remote_retrieve_body( $resp ), true );

			return isset( $json['pr'] ) ? (string) $json['pr'] : null;
		}

		// Anything else (raw invoice, bad config, etc) is unsupported for now
		return null;
	}
}
