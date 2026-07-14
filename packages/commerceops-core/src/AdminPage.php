<?php
/**
 * Shared admin UI.
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page helpers.
 */
final class AdminPage {

	/**
	 * Register submenu under WooCommerce or Settings.
	 *
	 * @param array<string,mixed> $args Menu args.
	 */
	public static function register( array $args ) {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

		add_submenu_page(
			$parent,
			$args['title'],
			$args['title'],
			$args['capability'] ?? 'manage_options',
			$args['slug'],
			$args['callback']
		);
	}

	/**
	 * Open admin wrap.
	 *
	 * @param string $title Page title.
	 * @param string $badge Optional badge.
	 */
	public static function open( $title, $badge = '' ) {
		echo '<div class="wrap commerceops-wrap">';
		echo '<h1>' . esc_html( $title );
		if ( $badge ) {
			echo ' <span class="co-badge">' . esc_html( $badge ) . '</span>';
		}
		echo '</h1>';
	}

	/**
	 * Close admin wrap.
	 */
	public static function close() {
		echo '</div>';
	}

	/**
	 * Render license activation box.
	 *
	 * @param array<string,mixed> $args Form args.
	 */
	public static function render_license_form( array $args ) {
		$is_pro      = ! empty( $args['is_pro'] );
		$masked      = $is_pro ? self::mask_key( (string) ( $args['license_key'] ?? '' ) ) : '';
		$expires     = ! empty( $args['expires_at'] ) ? (string) $args['expires_at'] : '';
		$checkout    = esc_url( (string) ( $args['checkout_url'] ?? '#' ) );
		$nonce       = esc_attr( (string) ( $args['nonce'] ?? '' ) );

		?>
		<div class="co-card co-license-card">
			<h2><?php esc_html_e( 'License', 'commerceops-core' ); ?></h2>
			<?php if ( $is_pro ) : ?>
				<p class="co-status co-status--ok"><?php esc_html_e( 'Pro active', 'commerceops-core' ); ?></p>
				<p><code><?php echo esc_html( $masked ); ?></code></p>
				<?php if ( $expires ) : ?>
					<p><?php printf( esc_html__( 'Expires: %s', 'commerceops-core' ), esc_html( $expires ) ); ?></p>
				<?php endif; ?>
				<button type="button" class="button" id="co-deactivate-license" data-nonce="<?php echo $nonce; ?>">
					<?php esc_html_e( 'Deactivate', 'commerceops-core' ); ?>
				</button>
			<?php else : ?>
				<p class="co-status co-status--free"><?php esc_html_e( 'Free plan', 'commerceops-core' ); ?></p>
				<p>
					<input type="text" id="co-license-key" class="regular-text" placeholder="<?php esc_attr_e( 'Enter license key', 'commerceops-core' ); ?>" />
					<button type="button" class="button button-primary" id="co-activate-license" data-nonce="<?php echo $nonce; ?>">
						<?php esc_html_e( 'Activate', 'commerceops-core' ); ?>
					</button>
				</p>
				<p><a href="<?php echo $checkout; ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Get Pro', 'commerceops-core' ); ?>
				</a></p>
				<p class="description"><?php esc_html_e( 'Dev mode: use key CO-DEV-TEST without API key configured.', 'commerceops-core' ); ?></p>
			<?php endif; ?>
			<div id="co-license-message" class="co-message" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Mask license key for display.
	 *
	 * @param string $key License key.
	 * @return string
	 */
	private static function mask_key( $key ) {
		if ( strlen( $key ) <= 8 ) {
			return $key;
		}

		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, strlen( $key ) - 8 ) ) . substr( $key, -4 );
	}
}
