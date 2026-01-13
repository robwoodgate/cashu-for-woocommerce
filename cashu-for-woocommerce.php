<?php
/**
 * Plugin Name: Cashu For WooCommerce
 * Plugin URI:  https://www.github.com/robwoodgate
 * Description: Cashu is a free and open source ecash protocol for Bitcoin, this plugin allows you to receive Cashu payments in Bitcoin, directly to your lightning address, with no fees or transaction costs.
 * Author:      Rob Woodgate
 * Author URI:  https://www.github.com/robwoodgate
 * License:     MIT
 * License URI: https://github.com/robwoodgate/cashu-for-woocommerce/blob/main/license.txt
 * Text Domain: cashu-for-woocommerce
 * Domain Path: /languages
 * Version:     0.1.0
 *
 * @package     Cashu_For_Woocommerce
 */

// * No direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CASHU_WC_VERSION', '0.1.0' );
define( 'CASHU_WC_VERSION_KEY', 'CASHU_WC_VERSION' );
define( 'CASHU_WC_PLUGIN_FILE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CASHU_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CASHU_WC_PLUGIN_ID', 'cashu-for-woocommerce' );
define( 'CASHU_WC_BIP177_SYMBOL', '₿' );

// * Instantiate main plugin
require_once CASHU_WC_PLUGIN_FILE_PATH . 'src/CashuWCPlugin.php';
( new \Cashu\WC\CashuWCPlugin() )->run();
