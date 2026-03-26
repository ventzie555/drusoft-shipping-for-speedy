=== Modern Shipping for Speedy ===
Contributors: drusoftltd
Tags: woocommerce, shipping, speedy, bulgaria, delivery
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, conflict-free Speedy integration for WooCommerce stores in Bulgaria.

== Description ==

**Modern Shipping for Speedy** is a high-performance, conflict-free WooCommerce integration for Speedy delivery services in Bulgaria. Designed for speed, reliability, and ease of use, it provides a seamless shipping experience for both merchants and customers.

= Important Compatibility Note =

This plugin is currently **not compatible** with the WooCommerce Block Cart and Block Checkout pages. Please ensure your store uses the classic shortcode-based Cart (`[woocommerce_cart]`) and Checkout (`[woocommerce_checkout]`) pages.

= For Your Customers =

* **Dynamic Checkout Experience** — Real-time city and office selection directly on the checkout page.
* **Multiple Delivery Types** — Choose between delivery to Address, Speedy Office, or Speedy Automat (APS).
* **Smart Street Search** — Built-in autocomplete for Bulgarian street names with intelligent prefix handling (e.g., stripping "ул.", "бул.").
* **Live Service Selection** — Customers can choose between available services (Economy, Express, etc.) with real-time price updates.
* **Region Mapping** — Automated city filtering based on the selected Bulgarian province.

= For Merchants =

* **HPOS Compatible** — Fully supports WooCommerce High-Performance Order Storage.
* **Automated Data Sync** — Uses Action Scheduler to keep Bulgarian cities and Speedy offices up-to-date in the background.
* **Credential Validation** — Validates API credentials in real-time before saving.
* **Custom Pricing** — Support for custom pricing CSV files for specialized shipping rates.
* **Advanced Order Management** — Dedicated metabox in the order edit screen, integrated waybill generation, and bulk actions for managing multiple Speedy orders.
* **Clean Codebase** — Built with modern PHP standards and conflict-free architecture.

== Installation ==

1. Upload the `speedy-modern-shipping` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure **WooCommerce** is installed and active.
4. Navigate to **WooCommerce > Settings > Shipping > Shipping Zones**.
5. Add or edit a shipping zone (e.g., "Bulgaria").
6. Click **Add shipping method** and select **Speedy Modern**.
7. Enter your **Speedy API Username** and **Password**, then click **Save Changes**.
8. The plugin will validate your credentials and unlock additional configuration options.
9. Background sync for cities and offices starts automatically via the WooCommerce Action Scheduler.

== Frequently Asked Questions ==

= What Speedy API credentials do I need? =

You need the username and password provided by Speedy for their REST API (v1). Contact Speedy Bulgaria to obtain API access.

= Does this plugin support the WooCommerce Block Checkout? =

Not yet. The plugin currently requires the classic shortcode-based Checkout page (`[woocommerce_checkout]`). Block Checkout support is planned for a future release.

= How are cities and offices kept up to date? =

The plugin uses the WooCommerce Action Scheduler to sync cities and offices from the Speedy API in the background. You can monitor the scheduled action (`speedy_modern_sync_locations_event`) under **WooCommerce > Status > Scheduled Actions**.

= What pricing methods are available? =

* **Speedy Calculator** — Real-time API calculation based on weight, destination, and service.
* **Fixed Price** — Configurable per delivery type (Address, Office, Automat).
* **Free Shipping** — Always free, or triggered by a minimum order amount per delivery type.
* **Custom Prices (CSV)** — Upload a CSV file for complex pricing rules based on weight and order total.
* **Calculator + Surcharge** — API price plus a fixed additional fee.

= How does the CSV custom pricing work? =

Upload a CSV with columns: `service_id, delivery_type, max_weight, max_order_total, price`. Delivery type mapping: `0` = Address, `1` = Office, `2` = Automat. The plugin matches rows where the order's weight and subtotal are within the specified limits.

= Can I automatically generate waybills? =

Yes. Enable the **Automatic Waybill** option in the shipping method settings. A waybill will be created automatically when an order reaches the "Processing" or "On Hold" status.

== Screenshots ==

1. Checkout page with Speedy delivery type selection (Address, Office, Automat).
2. Shipping method settings in the WooCommerce shipping zone modal.
3. Speedy Shipment metabox on the order edit screen.
4. Speedy Orders admin page with bulk actions.

== Changelog ==

= 1.0.0 =
* Initial release.
* Full Speedy API integration for shipping calculation and waybill generation.
* Support for delivery to Address, Office, and Automat (APS).
* Dynamic city, office, and street selection on checkout.
* Multiple pricing methods: Speedy Calculator, Fixed Price, Free Shipping, Custom CSV, Calculator + Surcharge.
* Free shipping thresholds configurable per delivery type.
* Background sync of Bulgarian cities and Speedy offices via Action Scheduler.
* HPOS (High-Performance Order Storage) compatibility.
* Admin order management: waybill generation, printing, courier requests, and cancellation.
* Nonce-protected AJAX handlers with separate public and admin scopes.
* Bulgarian (bg_BG) translation included.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

