<?php
/**
 * Plugin Name: CommerceOps Bulk Product Sync
 * Plugin URI: https://commerceops.io/bulk-product-sync
 * Description: Fast WooCommerce CSV export/import with diff preview and safe bulk updates.
 * Version: 1.0.0
 * Author: RoviDev / CommerceOps
 * Text Domain: bulk-product-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * License: GPL v2 or later
 *
 * @package CommerceOps\BulkProductSync
 */

defined( 'ABSPATH' ) || exit;

define( 'CO_BULK_SYNC_VERSION', '1.0.0' );
define( 'CO_BULK_SYNC_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\CommerceOps\BulkProductSync\Plugin::instance()->init();
	}
);
