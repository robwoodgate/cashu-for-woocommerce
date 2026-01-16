# Cashu For WooCommerce #
**Contributors:** [robwoodgate](https://profiles.wordpress.org/robwoodgate/)  
**Donate link:** https://donate.cogmentis.com/  
**Tags:** payments, bitcoin, lightning, checkout, cashu  
**Requires at least:** 6.5  
**Tested up to:** 6.8.3  
**Requires PHP:** 8.3  
**Stable tag:** 0.1.0  
**License:** MIT  
**License URI:** https://github.com/robwoodgate/cashu-for-woocommerce/blob/main/license.txt  

## Description ##

Cashu For WooCommerce adds a secure Cashu payment gateway to your WooCommerce store.

It allows you to receive private bitcoin payments using Cashu ecash, which is then automatically converted (melted) and sent to your bitcoin lightning address. It also allows you to receive bitcoin lighting payments from regular bitcoin wallets.

The following payment flows are available:

- Pay by lightning / QR Code: lightning -> trusted mint -> your lightning address
- Pay by trusted mint token: token -> trusted mint -> your lightning address
- Pay by untrusted mint token: token -> untrusted mint -> trusted mint -> your lightning address

##  Features ##

- Receive to any lightning address: Your lightning provider doesn't need any special features.
- Privacy, just like cash: All payments are routed through your trusted Cashu mint. Your lighting address stays private, so do your customer's payments.
- Flexibilty: switch trusted mints at any time to find the best fees and service.
- Pay via Lightning: Customers can pay from a regular bitcoin lightning wallet.
- Pay via Cashu Token: Customers can paste a token from any mint and it will be melted to your trusted mint, then melted to your lightning address.
- Safety: Your only have to trust one Cashu mint (your trusted mint).
- Accurate prices: Spot rates are taken from coinbase / coingecko.
- I18n: Checkout can be translated into any language.

## Installation ##

1. Make sure your server is running PHP 8.3 or higher
2. Upload and unzip `cashu-for-woocommerce.zip` to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin settings via the WooCommerce settings page.
5. Ensure you setup your lightning address so Cashu payments can be routed properly.

## Frequently Asked Questions ##

### Does this store private keys in WordPress? ###

No, the plugin requires only your public lightning address.

Payments in Cashu ecash are melted to bitcoin and send via lightning in real time, so no sensitive keys are required on the server.

## Changelog ##

### 0.1.0 ###
Initial developer release.

## Upgrade Notice ##

### 0.1.0 ###
First public release. Test carefully, don't be reckless.
