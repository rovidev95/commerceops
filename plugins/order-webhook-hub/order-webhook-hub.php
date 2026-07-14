<?php
/**
 * Plugin Name: CommerceOps Order Webhook Hub
 * Plugin URI: https://commerceops.io/order-webhook-hub
 * Description: Reliable WooCommerce order webhooks with retry logs, filtering, and multiple endpoints.
 * Version: 1.0.0
 * Author: RoviDev / CommerceOps
 * Text Domain: order-webhook-hub
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * License: GPL v2 or later
 *
 * @package CommerceOps\OrderWebhookHub
 */

defined( 'ABSPATH' ) || exit;

define( 'CO_WEBHOOK_HUB_VERSION', '1.0.0' );
define( 'CO_WEBHOOK_HUB_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\CommerceOps\OrderWebhookHub\Plugin::instance()->init();
	}
);
