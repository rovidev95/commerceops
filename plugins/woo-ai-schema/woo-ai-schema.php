<?php
/**
 * Plugin Name: CommerceOps Woo AI Schema
 * Plugin URI: https://commerceops.io/woo-ai-schema
 * Description: Automatic Product, Offer, and Review schema for WooCommerce. AI-shopping ready JSON-LD with validation reports.
 * Version: 1.0.1
 * Author: RoviDev / CommerceOps
 * Author URI: https://commerceops.io
 * Text Domain: woo-ai-schema
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package CommerceOps\WooAISchema
 */

defined( 'ABSPATH' ) || exit;

define( 'CO_WOO_AI_SCHEMA_VERSION', '1.0.1' );
define( 'CO_WOO_AI_SCHEMA_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-plugin.php';
require_once __DIR__ . '/includes/class-schema-generator.php';
require_once __DIR__ . '/includes/class-schema-validator.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'CommerceOps Woo AI Schema requires WooCommerce.', 'woo-ai-schema' );
					echo '</p></div>';
				}
			);
			return;
		}

		\CommerceOps\WooAISchema\Plugin::instance()->init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'commerceops_daily_license_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'commerceops_daily_license_check' );
		}
	}
);
