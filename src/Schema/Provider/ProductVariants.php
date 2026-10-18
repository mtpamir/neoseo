<?php
/**
 * Variant-only ProductGroup node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The per-node back-off: the one node NeoSEO still contributes when a full
 * SEO plugin owns the page's structured data.
 *
 * Yoast, Rank Math, AIOSEO, SEOPress and Slim SEO all emit a Product node and
 * none of them emits ProductGroup variant markup, so a store running one of
 * them gets variant eligibility from nobody. This node fills exactly that gap
 * and claims nothing else — no name, no description, no rating, and crucially
 * no `mainEntityOfPage`, so it never competes with the other plugin's node for
 * what the page is about.
 */
class ProductVariants extends Product {

	/**
	 * Single variable product pages only.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		if ( ! parent::applies() ) {
			return false;
		}

		$product = wc_get_product( get_the_ID() );

		return $product instanceof \WC_Product && $product->is_type( 'variable' );
	}

	/**
	 * Builds the lean ProductGroup node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		$group = $this->variant_group( $product );

		if ( ! $group ) {
			return array();
		}

		$node = array(
			'@type' => 'ProductGroup',
			'@id'   => $this->page_id( 'productgroup' ),
			// `name` is the one property ProductGroup requires.
			'name'  => $product->get_name(),
			'url'   => (string) $product->get_permalink(),
		);

		$node = array_merge( $node, $group );

		/**
		 * Filters the variant-only ProductGroup node.
		 *
		 * Fires only in variants-only mode, when another SEO plugin owns the
		 * rest of the page's schema.
		 *
		 * @since 1.0.0
		 *
		 * @param array       $node    ProductGroup node.
		 * @param \WC_Product $product Parent product object.
		 */
		$node = apply_filters( 'neoseo_product_variants_node', $node, $product );

		return array( $node );
	}
}
