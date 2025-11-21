=== Cashu For WooCommerce ===
Contributors: robwoodgate
Donate link: https://donate.cogmentis.com/
Tags: payments, bitcoin, lightning, checkout, cashu
Requires at least: 6.5
Tested up to: 6.8.3
Requires PHP: 8.3
Stable tag: 0.1.0
License: MIT

== Description ==

Cashu For WooCommerce adds a secure Cashu payment gateway to your WooCommerce store.

It allows you to receive private bitcoin payments using Cashu ecash, which is then automatically converted (melted) and sent to your bitcoin lightning address.

== Installation ==

1. Make sure your server is running PHP 8.3 or higher
1. Upload and unzip `cashu-for-woocommerce.zip` to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin settings via the WooCommerce settings page.
5. Ensure you setup your lightning address so Cashu payments can be routed properly.

== Frequently Asked Questions ==

= Does this store private keys in WordPress? =

No, the plugin requires only your public lightning address.

Payments in Cashu ecash are melted to bitcoin and send via lightning in real time, so no sensitive keys are required on the server.

== Changelog ==

= 0.1.0 =
Initial developer release.

== Upgrade Notice ==

= 0.1.0 =
First public release. Test carefully, don't be reckless.
