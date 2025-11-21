<?php
/**
 * Simple PHPUnit bootstrap for the Cashu for WooCommerce plugin.
 * No WordPress test suite is loaded here.
 * @package Cashu_For_Woocommerce
 */

// Path to project root.
$projectRoot = dirname( __DIR__ );

// Composer autoloader.
$autoload = $projectRoot . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

// Load the main plugin file if you want its functions to be available in tests.
// Comment this out if you only test classes in src/.
$pluginFile = $projectRoot . '/cashu-for-woocommerce.php';
if ( file_exists( $pluginFile ) ) {
    require_once $pluginFile;
}

// You can define simple helpers or constants here if needed for tests.
// For example:
// define( 'CASHU_FOR_WOOCOMMERCE_ENV', 'test' );
