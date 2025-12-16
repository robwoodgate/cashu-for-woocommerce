<?php

declare(strict_types=1);

namespace Cashu\WC\Helpers;

/**
 * Helper for basic Cashu-related utilities.
 *
 * Right now we only use this for fiat→sats conversion when creating
 * Lightning invoices server-side.
 */
class CashuHelper {
	/**
	 * Stored Lightning address (for future use / convenience).
	 */
	private string $lightningAddress;

	/**
	 * Whether modal checkout is enabled (for future use).
	 */
	private bool $modal;

	public function __construct( ?string $lightningAddress = null ) {
		$this->lightningAddress =
		trim( $lightningAddress ) ?? (string) get_option( 'cashu_lightning_address', '' );
		$this->modal            = 'yes' === get_option( 'cashu_modal_checkout', 'yes' );
	}

	public function isConfigured(): bool {
		return '' !== $this->lightningAddress;
	}

	/**
	 * Convert a fiat amount to sats using Coingecko.
	 *
	 * @param float  $amount amount in fiat
	 * @param string $fiat   ISO 4217 code, eg "USD", "GBP", "EUR"
	 *
	 * @throws \RuntimeException when the API call fails or returns bad data
	 */
	public static function fiatToSats( float $amount, string $fiat = 'USD' ): int {
		$fiat = strtolower( $fiat );

		$url = add_query_arg(
			array(
				'ids'           => 'bitcoin',
				'vs_currencies' => $fiat,
			),
			'https://api.coingecko.com/api/v3/simple/price'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Failed to fetch BTC price.' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		//phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r
		Logger::debug( 'Coingecko response: ' . print_r( $data, true ) );

		if ( ! isset( $data['bitcoin'][ $fiat ] ) || $data['bitcoin'][ $fiat ] <= 0 ) {
			throw new \RuntimeException( 'Invalid BTC price response.' );
		}

		$btc_price = (float) $data['bitcoin'][ $fiat ];

		// amount_in_btc = amount_fiat / price_fiat_per_btc
		// sats = btc * 1e8
		return (int) round( ( $amount / $btc_price ) * 1e8 );
	}
}
