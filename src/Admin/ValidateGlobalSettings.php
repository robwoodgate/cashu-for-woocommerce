<?php
declare(strict_types=1);

namespace Cashu\WC\Admin;

use WC_Admin_Settings;

final class ValidateGlobalSettings {

	private static bool $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_filter(
			'woocommerce_admin_settings_sanitize_option_cashu_trusted_mint',
			array( self::class, 'sanitize_trusted_mint' ),
			10,
			3
		);

		add_filter(
			'woocommerce_admin_settings_sanitize_option_cashu_lightning_address',
			array( self::class, 'sanitize_lightning_address' ),
			10,
			3
		);
	}

	public static function sanitize_trusted_mint( $value, array $option, $raw_value ) {
		$value = trim( (string) $value );

		if ( $value === '' ) {
			return '';
		}

		$validated = wp_http_validate_url( $value );
		if ( ! $validated ) {
			WC_Admin_Settings::add_error( __( 'Trusted Mint URL must be a valid URL.', 'cashu-for-woocommerce' ) );
			return null;
		}

		$parts = wp_parse_url( $validated );
		if ( empty( $parts['scheme'] ) || strtolower( $parts['scheme'] ) !== 'https' ) {
			WC_Admin_Settings::add_error( __( 'Trusted Mint URL must use https.', 'cashu-for-woocommerce' ) );
			return null;
		}

		return untrailingslashit( $validated );
	}

	public static function sanitize_lightning_address( $value, array $option, $raw_value ) {
		$value = trim( (string) $value );

		if ( $value === '' ) {
			return '';
		}

		$email = is_email( $value );
		if ( $email ) {
			return strtolower( $email );
		}

		WC_Admin_Settings::add_error(
			__( 'Lightning address must be a valid lightning address (name@domain).', 'cashu-for-woocommerce' )
		);

		return null;
	}
}
