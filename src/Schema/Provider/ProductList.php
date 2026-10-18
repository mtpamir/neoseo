<?php
/**
 * ItemList node for product archives.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the products on a shop, category or tag archive.
 *
 * The WebPage provider already gives these pages the right `CollectionPage`
 * subtype, which says what kind of page it is but not what is on it. This adds
 * the contents.
 *
 * Deliberately the summary form — position and URL per entry, pointing at the
 * product page — rather than inlining whole Product nodes. An archive of 24
 * products would otherwise carry 24 full product descriptions, and the detail
 * already exists one click away on a page that emits it properly.
 */
class ProductList extends Base {

	/**
	 * Maximum entries in one list.
	 */
	private const MAX_ITEMS = 60;

	/**
	 * Product archives only, with WooCommerce loaded.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		if ( ! function_exists( 'is_shop' ) || ! function_exists( 'is_product_category' ) ) {
			return false;
		}

		return is_shop() || is_product_category() || is_product_tag();
	}

	/**
	 * Builds the ItemList node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query || empty( $wp_query->posts ) ) {
			return array();
		}

		$elements = array();
		$position = $this->start_position( $wp_query );

		foreach ( $wp_query->posts as $post ) {
			if ( count( $elements ) >= self::MAX_ITEMS ) {
				break;
			}

			if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
				continue;
			}

			$url = get_permalink( $post );

			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$elements[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => $url,
				'name'     => get_the_title( $post ),
			);

			++$position;
		}

		if ( ! $elements ) {
			return array();
		}

		return array(
			array(
				'@type'            => 'ItemList',
				'@id'              => $this->page_id( 'itemlist' ),
				'mainEntityOfPage' => array( '@id' => $this->page_id( 'webpage' ) ),
				'numberOfItems'    => count( $elements ),
				'itemListElement'  => $elements,
			),
		);
	}

	/**
	 * Position the first item on this page occupies in the whole list.
	 *
	 * Page two of a shop starts at 13, not at 1. Restarting the count on each
	 * page would tell a consumer that every page holds the same top results.
	 *
	 * @param \WP_Query $query Main query.
	 * @return int
	 */
	private function start_position( \WP_Query $query ): int {
		$paged = (int) $query->get( 'paged' );

		if ( $paged < 2 ) {
			return 1;
		}

		$per_page = (int) $query->get( 'posts_per_page' );

		if ( $per_page < 1 ) {
			return 1;
		}

		return ( ( $paged - 1 ) * $per_page ) + 1;
	}
}
