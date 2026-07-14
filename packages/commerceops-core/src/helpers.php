<?php
/**
 * Shared helpers.
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize text field with fallback.
 *
 * @param mixed  $value   Raw value.
 * @param string $default Default.
 * @return string
 */
function sanitize_text( $value, $default = '' ) {
	if ( ! is_scalar( $value ) ) {
		return $default;
	}

	$clean = sanitize_text_field( (string) $value );
	return '' !== $clean ? $clean : $default;
}

/**
 * Load core from plugin vendor path.
 *
 * @param string $plugin_file Main plugin file path.
 * @return bool
 */
function load_from_plugin( $plugin_file ) {
	$paths = array(
		plugin_dir_path( $plugin_file ) . 'vendor/commerceops-core/commerceops-core.php',
		dirname( $plugin_file, 3 ) . '/packages/commerceops-core/commerceops-core.php',
	);

	foreach ( $paths as $path ) {
		if ( is_readable( $path ) ) {
			require_once $path;
			return true;
		}
	}

	return false;
}
