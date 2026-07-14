<?php
/**
 * Plugin bootstrap contract.
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Product configuration for a CommerceOps plugin.
 */
final class ProductConfig {

	/** @var string */
	public $slug;

	/** @var string */
	public $name;

	/** @var string */
	public $version;

	/** @var string */
	public $plugin_file;

	/** @var string */
	public $text_domain;

	/** @var string */
	public $license_option_key;

	/** @var array<string,mixed> */
	public $pro_features;

	/** @var array<string,mixed> */
	public $billing;

	/**
	 * @param array<string,mixed> $args Config args.
	 */
	public function __construct( array $args ) {
		$this->slug               = sanitize_text( $args['slug'] ?? '' );
		$this->name               = sanitize_text( $args['name'] ?? '' );
		$this->version            = sanitize_text( $args['version'] ?? '1.0.0' );
		$this->plugin_file        = (string) ( $args['plugin_file'] ?? '' );
		$this->text_domain        = sanitize_text( $args['text_domain'] ?? $this->slug );
		$this->license_option_key = sanitize_text( $args['license_option_key'] ?? 'co_license_' . $this->slug );
		$this->pro_features       = is_array( $args['pro_features'] ?? null ) ? $args['pro_features'] : array();
		$this->billing            = is_array( $args['billing'] ?? null ) ? $args['billing'] : array();
	}
}

/**
 * Base bootstrap for CommerceOps plugins.
 */
abstract class PluginBootstrap {

	/** @var ProductConfig */
	protected $config;

	/** @var LicenseManager|null */
	protected $license;

	/**
	 * @param ProductConfig $config Product config.
	 */
	public function __construct( ProductConfig $config ) {
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		load_plugin_textdomain( $this->config->text_domain, false, dirname( plugin_basename( $this->config->plugin_file ) ) . '/languages' );

		$this->license = new LicenseManager( $this->config );
		$this->license->register_hooks();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		$this->boot();
	}

	/**
	 * Plugin-specific boot logic.
	 */
	abstract protected function boot();

	/**
	 * Render settings page content.
	 */
	abstract public function render_admin_page();

	/**
	 * REST routes for plugin.
	 */
	public function register_rest_routes() {
		// Override in child plugins.
	}

	/**
	 * Admin menu registration.
	 */
	public function register_admin_menu() {
		AdminPage::register(
			array(
				'slug'       => $this->config->slug,
				'title'      => $this->config->name,
				'capability' => 'manage_options',
				'callback'   => array( $this, 'render_admin_page' ),
				'license'    => $this->license,
			)
		);
	}

	/**
	 * Enqueue shared admin assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, $this->config->slug ) ) {
			return;
		}

		wp_enqueue_style(
			'commerceops-admin',
			plugins_url( 'assets/css/admin.css', $this->config->plugin_file ),
			array(),
			$this->config->version
		);

		wp_enqueue_script(
			'commerceops-admin',
			plugins_url( 'assets/js/admin.js', $this->config->plugin_file ),
			array( 'jquery' ),
			$this->config->version,
			true
		);

		wp_localize_script(
			'commerceops-admin',
			'commerceOpsAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'commerceops/v1/' . $this->config->slug ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'isPro'   => $this->license ? $this->license->is_pro() : false,
				'i18n'    => array(
					'activated'   => __( 'License activated.', 'commerceops-core' ),
					'deactivated' => __( 'License deactivated.', 'commerceops-core' ),
					'error'       => __( 'Something went wrong.', 'commerceops-core' ),
				),
			)
		);
	}

	/**
	 * @return LicenseManager|null
	 */
	public function license() {
		return $this->license;
	}

	/**
	 * Check pro feature availability.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function has_pro_feature( $feature ) {
		if ( ! $this->license || ! $this->license->is_pro() ) {
			return false;
		}

		$features = $this->config->pro_features;
		return ! empty( $features[ $feature ] );
	}
}
