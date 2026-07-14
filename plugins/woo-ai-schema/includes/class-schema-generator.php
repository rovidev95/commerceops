<?php
/**
 * JSON-LD schema generator for WooCommerce products.
 *
 * @package CommerceOps\WooAISchema
 */

namespace CommerceOps\WooAISchema;

defined( 'ABSPATH' ) || exit;

/**
 * Generates schema.org Product markup.
 */
final class SchemaGenerator {

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Output BreadcrumbList on product and shop archives.
	 */
	public function output_breadcrumb_schema() {
		if ( ! is_product() && ! is_shop() && ! is_product_category() ) {
			return;
		}

		$items = array();
		$pos   = 1;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => get_bloginfo( 'name' ),
			'item'     => home_url( '/' ),
		);

		if ( is_product_category() ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => $term->name,
					'item'     => get_term_link( $term ),
				);
			}
		}

		if ( is_product() ) {
			global $post;
			$terms = get_the_terms( $post->ID, 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$term = $terms[0];
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => $term->name,
					'item'     => get_term_link( $term ),
				);
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => get_the_title( $post ),
				'item'     => get_permalink( $post ),
			);
		}

		if ( count( $items ) < 2 ) {
			return;
		}

		$this->echo_json_ld(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $items,
			)
		);
	}

	/**
	 * Output JSON-LD on single product pages.
	 */
	public function output_product_schema() {
		if ( ! is_product() ) {
			if ( is_front_page() && get_option( 'co_waas_enable_organization', true ) ) {
				$this->echo_json_ld( $this->build_organization_schema() );
			}
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$graphs = array( $this->build_product_schema( $product ) );

		if ( $this->plugin->has_pro_feature( 'faq_schema' ) ) {
			$faq = $this->build_faq_schema( $product );
			if ( $faq ) {
				$graphs[] = $faq;
			}
		}

		$this->echo_json_ld(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graphs,
			)
		);
	}

	/**
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	public function build_product_schema( $product ) {
		$product_id = $product->get_id();
		$currency   = get_woocommerce_currency();
		$image_ids  = $product->get_gallery_image_ids();
		$images     = array();

		$thumb = wp_get_attachment_url( $product->get_image_id() );
		if ( $thumb ) {
			$images[] = $thumb;
		}
		foreach ( $image_ids as $img_id ) {
			$url = wp_get_attachment_url( $img_id );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$schema = array(
			'@type'       => 'Product',
			'@id'         => get_permalink( $product_id ) . '#product',
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'sku'         => $product->get_sku() ?: (string) $product_id,
			'url'         => get_permalink( $product_id ),
			'image'       => $images,
			'offers'      => array(
				'@type'         => 'Offer',
				'price'         => wc_format_decimal( $product->get_price(), wc_get_price_decimals() ),
				'priceCurrency' => $currency,
				'availability'  => $product->is_in_stock()
					? 'https://schema.org/InStock'
					: 'https://schema.org/OutOfStock',
				'url'           => get_permalink( $product_id ),
			),
		);

		$brand = get_post_meta( $product_id, '_co_brand', true );
		if ( $brand ) {
			$schema['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		} elseif ( $this->plugin->has_pro_feature( 'brand_schema' ) ) {
			$schema['brand'] = array(
				'@type' => 'Brand',
				'name'  => get_bloginfo( 'name' ),
			);
		}

		if ( $this->plugin->has_pro_feature( 'gtin' ) ) {
			$gtin = get_post_meta( $product_id, '_co_gtin', true );
			if ( $gtin ) {
				$schema['gtin13'] = $gtin;
			}
		}

		$rating = $this->get_aggregate_rating( $product );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		if ( $this->plugin->has_pro_feature( 'buy_action' ) ) {
			$schema['potentialAction'] = array(
				'@type'  => 'BuyAction',
				'target' => get_permalink( $product_id ),
			);
		}

		return $schema;
	}

	/**
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>|null
	 */
	private function build_faq_schema( $product ) {
		$content = $product->get_description();
		if ( ! $content || false === strpos( $content, '?' ) ) {
			return null;
		}

		$lines = preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $content ) );
		$items = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '?' !== substr( $line, -1 ) ) {
				continue;
			}
			$items[] = array(
				'@type'          => 'Question',
				'name'           => $line,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => __( 'See product description for details.', 'woo-ai-schema' ),
				),
			);
			if ( count( $items ) >= 5 ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'@type'      => 'FAQPage',
			'mainEntity' => $items,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_organization_schema() {
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);
	}

	/**
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>|null
	 */
	private function get_aggregate_rating( $product ) {
		$count = (int) $product->get_rating_count();
		$avg   = (float) $product->get_average_rating();

		if ( $count <= 0 || $avg <= 0 ) {
		 return null;
		}

		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $avg,
			'reviewCount' => $count,
			'bestRating'  => '5',
			'worstRating' => '1',
		);
	}

	/**
	 * @param array<string,mixed> $data Schema data.
	 */
	private function echo_json_ld( array $data ) {
		echo "\n<script type=\"application/ld+json\">";
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo "</script>\n";
	}
}
