<?php
declare(strict_types=1);

// 1. Load Composer autoloader
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
	echo "Run: composer install\n";
	exit(1);
}
require_once $autoload;

// 2. Define plugin constants (so your classes don't explode)
if (!defined('CASHU_WC_VERSION')) {
	define('CASHU_WC_VERSION', '0.1.1');
}
if (!defined('CASHU_WC_PATH')) {
	define('CASHU_WC_PATH', dirname(__DIR__));
}
if (!defined('CASHU_WC_URL')) {
	define('CASHU_WC_URL', 'file://' . dirname(__DIR__));
}
if (!defined('CASHU_WC_BASE')) {
	define('CASHU_WC_BASE', 'cashu-for-woocommerce');
}

echo "Bootstrap loaded – Cashu ready for testing!\n";
