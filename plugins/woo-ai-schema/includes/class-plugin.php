<?php
/**
 * Main plugin class.
 *
 * @package CommerceOps\WooAISchema
 */

namespace CommerceOps\WooAISchema;

use CommerceOps\Core\AdminPage;
use CommerceOps\Core\PluginBootstrap;
use CommerceOps\Core\ProductConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Woo AI Schema plugin bootstrap.
 */
final class Plugin extends PluginBootstrap {

	/** @var Plugin|null */
	private static $instance;

	/** @var SchemaGenerator */
	private $generator;

	/** @var SchemaValidator */
	private $validator;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			\CommerceOps\Core\load_from_plugin( CO_WOO_AI_SCHEMA_FILE );
			self::$instance = new self(
				new ProductConfig(
					array(
						'slug'               => 'woo-ai-schema',
						'name'               => 'Woo AI Schema',
						'version'            => CO_WOO_AI_SCHEMA_VERSION,
						'plugin_file'        => CO_WOO_AI_SCHEMA_FILE,
						'text_domain'        => 'woo-ai-schema',
						'license_option_key' => 'co_license_woo_ai_schema',
						'pro_features'       => array(
							'gtin'           => true,
							'faq_schema'     => true,
							'brand_schema'   => true,
							'ai_report'      => true,
							'buy_action'     => true,
						),
						'billing'            => array(
							'checkout_url' => 'https://commerceops.io/checkout/woo-ai-schema',
							'variant_id' => '',
						),
					)
				)
			);
		}

		return self::$instance;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function boot() {
		$this->generator = new SchemaGenerator( $this );
		$this->validator = new SchemaValidator( $this );

		add_action( 'wp_head', array( $this->generator, 'output_product_schema' ), 5 );
		add_action( 'wp_head', array( $this->generator, 'output_breadcrumb_schema' ), 6 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function register_rest_routes() {
		register_rest_route(
			'commerceops/v1/woo-ai-schema',
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->validator, 'rest_get_report' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function render_admin_page() {
		$is_pro  = $this->license() && $this->license()->is_pro();
		$report  = $this->validator->build_store_report();
		$score   = (int) $report['score'];

		AdminPage::open(
			__( 'Woo AI Schema', 'woo-ai-schema' ),
			$is_pro ? 'PRO' : 'FREE'
		);

		if ( $this->license() ) {
			$this->license()->render_license_box();
		}
		?>
		<div class="co-card">
			<h2><?php esc_html_e( 'AI Readiness Score', 'woo-ai-schema' ); ?></h2>
			<p class="co-score"><?php echo esc_html( (string) $score ); ?>/100</p>
			<p><?php esc_html_e( 'Products analyzed:', 'woo-ai-schema' ); ?> <strong><?php echo esc_html( (string) $report['total_products'] ); ?></strong></p>
			<ul class="co-list">
				<?php foreach ( $report['issues'] as $issue ) : ?>
					<li><?php echo esc_html( $issue ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $is_pro ) : ?>
				<p class="description"><?php esc_html_e( 'Upgrade to Pro for GTIN, FAQ schema, Brand entity, BuyAction, and full AI report export.', 'woo-ai-schema' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="co-card">
			<h2><?php esc_html_e( 'Settings', 'woo-ai-schema' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'co_waas_settings' );
				do_settings_sections( 'co_waas_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
		AdminPage::close();
	}

	/**
	 * Product admin fields for GTIN / brand (Pro).
	 */
	public function render_product_fields() {
		global $post;

		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => '_co_brand',
				'label'       => __( 'Brand (Schema)', 'woo-ai-schema' ),
				'desc_tip'    => true,
				'description' => __( 'Used in Product schema JSON-LD.', 'woo-ai-schema' ),
				'value'       => get_post_meta( $post->ID, '_co_brand', true ),
			)
		);

		if ( $this->has_pro_feature( 'gtin' ) ) {
			woocommerce_wp_text_input(
				array(
					'id'          => '_co_gtin',
					'label'       => __( 'GTIN', 'woo-ai-schema' ),
					'desc_tip'    => true,
					'description' => __( 'Global Trade Item Number for AI shopping feeds.', 'woo-ai-schema' ),
					'value'       => get_post_meta( $post->ID, '_co_gtin', true ),
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Save product meta.
	 *
	 * @param int $post_id Product ID.
	 */
	public function save_product_fields( $post_id ) {
		if ( isset( $_POST['_co_brand'] ) ) {
			update_post_meta( $post_id, '_co_brand', sanitize_text_field( wp_unslash( $_POST['_co_brand'] ) ) );
		}
		if ( $this->has_pro_feature( 'gtin' ) && isset( $_POST['_co_gtin'] ) ) {
			update_post_meta( $post_id, '_co_gtin', sanitize_text_field( wp_unslash( $_POST['_co_gtin'] ) ) );
		}
	}
}

// Register settings on admin_init.
add_action(
	'admin_init',
	static function () {
		register_setting(
			'co_waas_settings',
			'co_waas_enable_organization',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $v ) {
					return (bool) $v;
				},
				'default'           => true,
			)
		);

		add_settings_section(
			'co_waas_main',
			'',
			null,
			'co_waas_settings'
		);

		add_settings_field(
			'co_waas_enable_organization',
			__( 'Output Organization schema on homepage', 'woo-ai-schema' ),
			static function () {
				$val = (bool) get_option( 'co_waas_enable_organization', true );
				echo '<label><input type="checkbox" name="co_waas_enable_organization" value="1" ' . checked( true, $val, false ) . ' /> ';
				esc_html_e( 'Recommended for brand entity consistency.', 'woo-ai-schema' );
				echo '</label>';
			},
			'co_waas_settings',
			'co_waas_main'
		);
	}
);
