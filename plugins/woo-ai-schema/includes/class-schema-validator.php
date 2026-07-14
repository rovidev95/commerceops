<?php
/**
 * Schema validation and AI readiness scoring.
 *
 * @package CommerceOps\WooAISchema
 */

namespace CommerceOps\WooAISchema;

defined( 'ABSPATH' ) || exit;

/**
 * Validates store schema coverage.
 */
final class SchemaValidator {

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * REST report endpoint.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_report() {
		return new \WP_REST_Response( $this->build_store_report(), 200 );
	}

	/**
	 * Build store-wide readiness report.
	 *
	 * @return array<string,mixed>
	 */
	public function build_store_report() {
		$products = wc_get_products(
			array(
				'limit'  => 100,
				'status' => 'publish',
				'return' => 'objects',
			)
		);

		$total     = count( $products );
		$with_desc = 0;
		$with_img  = 0;
		$with_sku  = 0;
		$with_rev  = 0;
		$with_gtin = 0;
		$issues    = array();

		$generator = new SchemaGenerator( $this->plugin );

		foreach ( $products as $product ) {
			if ( $product->get_description() || $product->get_short_description() ) {
				$with_desc++;
			}
			if ( $product->get_image_id() ) {
				$with_img++;
			}
			if ( $product->get_sku() ) {
				$with_sku++;
			}
			if ( $product->get_rating_count() > 0 ) {
				$with_rev++;
			}
			if ( get_post_meta( $product->get_id(), '_co_gtin', true ) ) {
				$with_gtin++;
			}

			$schema = $generator->build_product_schema( $product );
			if ( empty( $schema['offers']['price'] ) ) {
				$issues[] = sprintf(
					/* translators: %s: product name */
					__( 'Missing price on: %s', 'woo-ai-schema' ),
					$product->get_name()
				);
			}
		}

		if ( $total > 0 && $with_img < $total ) {
			$issues[] = sprintf(
				/* translators: %d: count */
				__( '%d products missing featured image (required for rich results).', 'woo-ai-schema' ),
				$total - $with_img
			);
		}

		if ( $total > 0 && $with_rev < ( $total * 0.3 ) ) {
			$issues[] = __( 'Less than 30% of products have reviews — AggregateRating boosts AI citations.', 'woo-ai-schema' );
		}

		if ( $this->plugin->has_pro_feature( 'gtin' ) && $total > 0 && $with_gtin < ( $total * 0.2 ) ) {
			$issues[] = __( 'Add GTIN on top sellers to improve AI shopping discoverability.', 'woo-ai-schema' );
		}

		$score = 0;
		if ( $total > 0 ) {
			$score += ( $with_desc / $total ) * 25;
			$score += ( $with_img / $total ) * 25;
			$score += ( $with_sku / $total ) * 15;
			$score += ( $with_rev / $total ) * 25;
			$score += min( 10, ( $with_gtin / max( 1, $total ) ) * 10 );
		}

		$report = array(
			'score'          => (int) round( $score ),
			'total_products' => $total,
			'coverage'       => array(
				'description' => $with_desc,
				'image'       => $with_img,
				'sku'           => $with_sku,
				'reviews'       => $with_rev,
				'gtin'          => $with_gtin,
			),
			'issues'         => array_slice( $issues, 0, 8 ),
			'is_pro'         => $this->plugin->license() && $this->plugin->license()->is_pro(),
		);

		if ( $this->plugin->has_pro_feature( 'ai_report' ) ) {
			$report['export_url'] = rest_url( 'commerceops/v1/woo-ai-schema/report' );
		}

		return $report;
	}
}
