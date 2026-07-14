<?php
/**
 * Order Webhook Hub plugin.
 *
 * @package CommerceOps\OrderWebhookHub
 */

namespace CommerceOps\OrderWebhookHub;

use CommerceOps\Core\AdminPage;
use CommerceOps\Core\PluginBootstrap;
use CommerceOps\Core\ProductConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook hub bootstrap.
 */
final class Plugin extends PluginBootstrap {

	/** @var Plugin|null */
	private static $instance;

	/** @var WebhookDispatcher */
	private $dispatcher;

	public static function instance() {
		if ( null === self::$instance ) {
			\CommerceOps\Core\load_from_plugin( CO_WEBHOOK_HUB_FILE );
			self::$instance = new self(
				new ProductConfig(
					array(
						'slug'         => 'order-webhook-hub',
						'name'         => 'Order Webhook Hub',
						'version'      => CO_WEBHOOK_HUB_VERSION,
						'plugin_file'  => CO_WEBHOOK_HUB_FILE,
						'text_domain'  => 'order-webhook-hub',
						'license_option_key' => 'co_license_order_webhook_hub',
						'pro_features' => array(
							'multi_endpoint' => true,
							'filters'        => true,
							'retry_queue'    => true,
						),
						'billing'      => array(
							'checkout_url' => 'https://commerceops.io/checkout/order-webhook-hub',
						),
					)
				)
			);
		}
		return self::$instance;
	}

	protected function boot() {
		$this->dispatcher = new WebhookDispatcher( $this );

		add_action( 'woocommerce_new_order', array( $this->dispatcher, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this->dispatcher, 'on_status_changed' ), 20, 4 );
		add_action( 'co_webhook_retry_cron', array( $this->dispatcher, 'process_retry_queue' ) );

		if ( ! wp_next_scheduled( 'co_webhook_retry_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'co_webhook_retry_cron' );
		}

		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting( 'co_owh_settings', 'co_owh_endpoint', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );

		register_setting( 'co_owh_settings', 'co_owh_secret', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		if ( $this->has_pro_feature( 'multi_endpoint' ) ) {
			register_setting( 'co_owh_settings', 'co_owh_endpoints_extra', array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			) );
		}
	}

	public function render_admin_page() {
		$endpoint = get_option( 'co_owh_endpoint', '' );
		$logs     = $this->dispatcher->get_recent_logs( 15 );

		AdminPage::open( __( 'Order Webhook Hub', 'order-webhook-hub' ), $this->license()->is_pro() ? 'PRO' : 'FREE' );
		$this->license()->render_license_box();
		?>
		<div class="co-card">
			<h2><?php esc_html_e( 'Primary webhook URL', 'order-webhook-hub' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'co_owh_settings' ); ?>
				<input type="url" name="co_owh_endpoint" value="<?php echo esc_attr( $endpoint ); ?>" class="large-text" placeholder="https://hooks.example.com/orders" />
				<p><label><?php esc_html_e( 'Signing secret (optional HMAC-SHA256)', 'order-webhook-hub' ); ?></label><br>
				<input type="text" name="co_owh_secret" value="<?php echo esc_attr( get_option( 'co_owh_secret', '' ) ); ?>" class="regular-text" autocomplete="off" /></p>
				<?php if ( $this->has_pro_feature( 'multi_endpoint' ) ) : ?>
					<p><label><?php esc_html_e( 'Additional endpoints (one per line)', 'order-webhook-hub' ); ?></label><br>
					<textarea name="co_owh_endpoints_extra" class="large-text" rows="3"><?php echo esc_textarea( get_option( 'co_owh_endpoints_extra', '' ) ); ?></textarea></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Pro: multiple endpoints + advanced filters.', 'order-webhook-hub' ); ?></p>
				<?php endif; ?>
				<?php submit_button( __( 'Save', 'order-webhook-hub' ) ); ?>
			</form>
		</div>
		<div class="co-card">
			<h2><?php esc_html_e( 'Delivery log', 'order-webhook-hub' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time', 'order-webhook-hub' ); ?></th><th><?php esc_html_e( 'Order', 'order-webhook-hub' ); ?></th><th><?php esc_html_e( 'Event', 'order-webhook-hub' ); ?></th><th><?php esc_html_e( 'Status', 'order-webhook-hub' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['time'] ); ?></td>
						<td>#<?php echo esc_html( (string) $log['order_id'] ); ?></td>
						<td><?php echo esc_html( $log['event'] ); ?></td>
						<td><?php echo esc_html( $log['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		AdminPage::close();
	}
}

/**
 * Dispatches order payloads to configured endpoints.
 */
final class WebhookDispatcher {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function on_new_order( $order_id ) {
		$this->dispatch( (int) $order_id, 'order.created' );
	}

	public function on_status_changed( $order_id, $from, $to, $order ) {
		unset( $order );
		$this->dispatch( (int) $order_id, 'order.status_' . $to );
	}

	public function dispatch( $order_id, $event ) {
		$endpoints = $this->get_endpoints();
		if ( empty( $endpoints ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$payload = array(
			'event'     => $event,
			'order_id'  => $order_id,
			'status'    => $order->get_status(),
			'total'     => $order->get_total(),
			'currency'  => $order->get_currency(),
			'email'     => $order->get_billing_email(),
			'items'     => array_map(
				static function ( $item ) {
					return array(
						'name'     => $item->get_name(),
						'qty'      => $item->get_quantity(),
						'subtotal' => $item->get_subtotal(),
					);
				},
				$order->get_items()
			),
			'site'      => home_url(),
			'timestamp' => gmdate( 'c' ),
		);

		foreach ( $endpoints as $url ) {
			$result = $this->post_json( $url, $payload );
			$this->log( $order_id, $event, $result );

			if ( ! $result['ok'] && $this->plugin->has_pro_feature( 'retry_queue' ) ) {
				$this->enqueue_retry( $url, $payload );
			}
		}
	}

	private function get_endpoints() {
		$list = array();
		$primary = get_option( 'co_owh_endpoint', '' );
		if ( $primary ) {
			$list[] = $primary;
		}
		if ( $this->plugin->has_pro_feature( 'multi_endpoint' ) ) {
			$extra = get_option( 'co_owh_endpoints_extra', '' );
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $extra ) as $line ) {
				$line = trim( $line );
				if ( $line && filter_var( $line, FILTER_VALIDATE_URL ) ) {
					$list[] = $line;
				}
			}
		}
		return array_values( array_unique( $list ) );
	}

	private function post_json( $url, array $payload ) {
		$body = wp_json_encode( $payload );
		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'CommerceOps-WebhookHub/1.0',
		);

		$secret = get_option( 'co_owh_secret', '' );
		if ( $secret ) {
			$headers['X-CommerceOps-Signature'] = hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return array( 'ok' => $code >= 200 && $code < 300, 'status' => (string) $code );
	}

	private function log( $order_id, $event, array $result ) {
		$logs = get_option( 'co_owh_logs', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		array_unshift(
			$logs,
			array(
				'time'     => gmdate( 'Y-m-d H:i:s' ),
				'order_id' => $order_id,
				'event'    => $event,
				'status'   => $result['ok'] ? 'OK' : ( 'FAIL ' . $result['status'] ),
			)
		);
		update_option( 'co_owh_logs', array_slice( $logs, 0, 100 ), false );
	}

	public function get_recent_logs( $limit = 20 ) {
		$logs = get_option( 'co_owh_logs', array() );
		return array_slice( is_array( $logs ) ? $logs : array(), 0, $limit );
	}

	private function enqueue_retry( $url, array $payload ) {
		$queue = get_option( 'co_owh_retry_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue[] = array(
			'url'      => $url,
			'payload'  => $payload,
			'attempts' => 0,
			'next'     => time() + 300,
		);
		update_option( 'co_owh_retry_queue', $queue, false );
	}

	public function process_retry_queue() {
		$queue = get_option( 'co_owh_retry_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$remaining = array();
		foreach ( $queue as $job ) {
			if ( empty( $job['next'] ) || $job['next'] > time() ) {
				$remaining[] = $job;
				continue;
			}
			$result = $this->post_json( $job['url'], $job['payload'] );
			if ( $result['ok'] ) {
				continue;
			}
			$attempts = (int) ( $job['attempts'] ?? 0 ) + 1;
			if ( $attempts < 5 ) {
				$job['attempts'] = $attempts;
				$job['next']     = time() + ( 300 * $attempts );
				$remaining[]     = $job;
			}
		}
		update_option( 'co_owh_retry_queue', $remaining, false );
	}
}
