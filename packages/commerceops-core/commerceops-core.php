<?php
/**
 * CommerceOps Core loader.
 *
 * @package CommerceOps\Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'COMMERCEOPS_CORE_VERSION' ) ) {
	define( 'COMMERCEOPS_CORE_VERSION', '1.0.0' );
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'CommerceOps\\Core\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = __DIR__ . '/src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/WebhookEndpoint.php';
