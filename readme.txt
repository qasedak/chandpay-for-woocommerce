=== Chandpay for WooCommerce ===
Contributors: chandpay
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pay one WooCommerce order across several installment providers.

== Description ==

Chandpay adds one checkout method. The shopper chooses the first installment provider and amount there, pays that provider, and returns to a pay-remaining page only while part of the order is still unpaid.

The order stays Pending until the first part is verified, On hold until the total is collected, then Processing after every part is settled. Each captured part on the order screen can be reverted, cancelled, or refunded on its own. That does not change the order status. Cancel the order, or set a paid order back to on hold, from the order screen. The pay-remaining page collects a gap only while the order is pending or on hold.

Credentials are read from the official TorobPay, SnappPay, and DigiPay plugins when those settings exist.

== Installation ==

1. Install and activate WooCommerce.
2. Activate Chandpay for WooCommerce.
3. Open WooCommerce, Settings, Payments, and enable Chandpay.
4. Save credentials in the official provider plugins, or enter them on the Chandpay settings screen. Official plugin credentials are used when that plugin has a complete set.

== Changelog ==

= 0.1.0 =
* First payment flow: pay-remaining page, TorobPay, SnappPay, and DigiPay adapters.
