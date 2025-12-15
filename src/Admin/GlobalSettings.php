<?php

declare(strict_types=1);

namespace Cashu\WC\Admin;

use Cashu\WC\Helpers\Logger;

class GlobalSettings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'cashu';
		$this->label = __( 'Cashu', 'cashu-for-woocommerce' );
		parent::__construct();
	}

	public function get_settings_for_default_section(): array {
		return array(
			'title'             => array(
				'id'    => 'cashu_settings_title',
				'title' => __( 'Cashu payments', 'cashu-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __(
					'Accept ecash payments directly to your lightning address via any Cashu mint.',
					'cashu-for-woocommerce'
				),
			),
			'enabled'           => array(
				'id'      => 'cashu_enabled',
				'title'   => __( 'Enable Cashu', 'cashu-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Cashu payments', 'cashu-for-woocommerce' ),
				'default' => 'no',
			),
			'lightning_address' => array(
				'id'          => 'cashu_lightning_address',
				'title'       => __( 'Lightning address', 'cashu-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'you@example.com',
				'desc_tip'    => __(
					'Where melted payments are sent, either a lightning address or LNURL.',
					'cashu-for-woocommerce'
				),
				'default'     => '',
			),
			'trusted_mint'      => array(
				'id'          => 'cashu_trusted_mint',
				'title'       => __( 'Trusted Mint URL', 'cashu-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'https://mint.minibits.cash/Bitcoin',
				'desc_tip'    => __(
					'A mint you trust to act as your intermediary.',
					'cashu-for-woocommerce'
				),
				'default'     => 'https://mint.minibits.cash/Bitcoin',
			),
			'debug'             => array(
				'id'      => 'cashu_debug',
				'title'   => __( 'Debug log', 'cashu-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable logging', 'cashu-for-woocommerce' ),
				'default' => 'no',
				'desc'    => sprintf(
					// translators: %s is a link to WooCommerce logs page
					__( 'Log events to the WooCommerce logs, <a href="%s">view logs</a>.', 'cashu-for-woocommerce' ),
					Logger::getLogFileUrl()
				),
			),
			'section_end'       => array(
				'id'   => 'cashu_settings_end',
				'type' => 'sectionend',
			),
		);
	}
}
