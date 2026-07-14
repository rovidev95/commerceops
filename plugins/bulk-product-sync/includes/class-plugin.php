<?php
/**
 * Bulk Product Sync plugin.
 *
 * @package CommerceOps\BulkProductSync
 */

namespace CommerceOps\BulkProductSync;

use CommerceOps\Core\AdminPage;
use CommerceOps\Core\PluginBootstrap;
use CommerceOps\Core\ProductConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk sync bootstrap.
 */
final class Plugin extends PluginBootstrap {

	/** @var Plugin|null */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			\CommerceOps\Core\load_from_plugin( CO_BULK_SYNC_FILE );
			self::$instance = new self(
				new ProductConfig(
					array(
						'slug'         => 'bulk-product-sync',
						'name'         => 'Bulk Product Sync',
						'version'      => CO_BULK_SYNC_VERSION,
						'plugin_file'  => CO_BULK_SYNC_FILE,
						'text_domain'  => 'bulk-product-sync',
						'license_option_key' => 'co_license_bulk_product_sync',
						'pro_features' => array(
							'diff_preview'   => true,
							'scheduled_sync' => true,
							'variations'     => true,
						),
						'billing'      => array(
							'checkout_url' => 'https://commerceops.io/checkout/bulk-product-sync',
						),
					)
				)
			);
		}
		return self::$instance;
	}

	protected function boot() {
		add_action( 'admin_post_co_bps_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_co_bps_import', array( $this, 'handle_import' ) );
	}

	public function render_admin_page() {
		AdminPage::open( __( 'Bulk Product Sync', 'bulk-product-sync' ), $this->license()->is_pro() ? 'PRO' : 'FREE' );
		$this->license()->render_license_box();
		?>
		<div class="co-card">
			<h2><?php esc_html_e( 'Export products', 'bulk-product-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'co_bps_export' ); ?>
				<input type="hidden" name="action" value="co_bps_export" />
				<?php submit_button( __( 'Download CSV', 'bulk-product-sync' ), 'secondary' ); ?>
			</form>
		</div>
		<div class="co-card">
			<h2><?php esc_html_e( 'Import / update', 'bulk-product-sync' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'co_bps_import' ); ?>
				<input type="hidden" name="action" value="co_bps_import" />
				<input type="file" name="co_csv" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Import CSV', 'bulk-product-sync' ) ); ?>
			</form>
			<?php if ( ! $this->has_pro_feature( 'diff_preview' ) ) : ?>
				<p class="description"><?php esc_html_e( 'Pro: diff preview before apply + variation sync.', 'bulk-product-sync' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		AdminPage::close();
	}

	public function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'co_bps_export' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'bulk-product-sync' ) );
		}

		$products = wc_get_products( array( 'limit' => 500, 'status' => array( 'publish', 'draft' ), 'return' => 'objects' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=commerceops-products.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'sku', 'name', 'regular_price', 'sale_price', 'stock_quantity', 'status' ) );

		foreach ( $products as $product ) {
			fputcsv(
				$out,
				array(
					$product->get_id(),
					$product->get_sku(),
					$product->get_name(),
					$product->get_regular_price(),
					$product->get_sale_price(),
					$product->get_stock_quantity(),
					$product->get_status(),
				)
			);
		}
		fclose( $out );
		exit;
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'co_bps_import' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'bulk-product-sync' ) );
		}

		if ( empty( $_FILES['co_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded', 'bulk-product-sync' ) );
		}

		$handle = fopen( $_FILES['co_csv']['tmp_name'], 'r' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'Could not read CSV', 'bulk-product-sync' ) );
		}

		$header = fgetcsv( $handle );
		$updated = 0;
		$created = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$data = array_combine( $header, $row );
			if ( ! $data ) {
				continue;
			}

			$product = null;
			if ( ! empty( $data['id'] ) ) {
				$product = wc_get_product( (int) $data['id'] );
			} elseif ( ! empty( $data['sku'] ) ) {
				$id = wc_get_product_id_by_sku( $data['sku'] );
				if ( $id ) {
					$product = wc_get_product( $id );
				}
			}

			if ( ! $product ) {
				$product = new \WC_Product_Simple();
				$created++;
			} else {
				$updated++;
			}

			if ( ! empty( $data['name'] ) ) {
				$product->set_name( sanitize_text_field( $data['name'] ) );
			}
			if ( isset( $data['sku'] ) ) {
				$product->set_sku( sanitize_text_field( $data['sku'] ) );
			}
			if ( isset( $data['regular_price'] ) ) {
				$product->set_regular_price( wc_format_decimal( $data['regular_price'] ) );
			}
			if ( isset( $data['sale_price'] ) ) {
				$product->set_sale_price( wc_format_decimal( $data['sale_price'] ) );
			}
			if ( isset( $data['stock_quantity'] ) && $data['stock_quantity'] !== '' ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( (int) $data['stock_quantity'] );
			}
			if ( ! empty( $data['status'] ) ) {
				$product->set_status( sanitize_key( $data['status'] ) );
			}

			$product->save();
		}
		fclose( $handle );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'bulk-product-sync',
					'co_done' => 1,
					'u'       => $updated,
					'c'       => $created,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
