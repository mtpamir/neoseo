<?php
/**
 * WebPage node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The per-request WebPage node (#webpage), typed by context.
 */
class WebPage extends Base {

	/**
	 * Every front-end request has a page.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return ! is_404();
	}

	/**
	 * Builds the WebPage node (plus its primary image node when present).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$nodes = array();

		$node = array(
			'@type'      => $this->page_type(),
			'@id'        => $this->page_id( 'webpage' ),
			'url'        => $this->current_url(),
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $this->site_id( 'website' ) ),
			'breadcrumb' => array( '@id' => $this->page_id( 'breadcrumb' ) ),
			'inLanguage' => get_bloginfo( 'language' ),
		);

		if ( is_singular() ) {
			$post = get_post();

			if ( $post ) {
				$node['datePublished'] = get_the_date( 'c', $post );
				$node['dateModified']  = get_the_modified_date( 'c', $post );

				$thumbnail_id = get_post_thumbnail_id( $post );

				if ( $thumbnail_id ) {
					$image = $this->image_node( (int) $thumbnail_id );

					if ( $image ) {
						$node['primaryImageOfPage'] = array( '@id' => $image['@id'] );
						$nodes[]                    = $image;
					}
				}
			}
		}

		array_unshift( $nodes, $node );

		return $nodes;
	}

	/**
	 * Chooses the WebPage subtype for the current request.
	 *
	 * @return string
	 */
	private function page_type(): string {
		$type = 'WebPage';

		if ( is_search() ) {
			$type = 'SearchResultsPage';
		} elseif ( is_archive() || is_home() ) {
			$type = 'CollectionPage';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$type = 'ItemPage';
		} elseif ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			$type = 'CheckoutPage';
		}

		/**
		 * Filters the WebPage subtype.
		 *
		 * @since 1.0.0
		 *
		 * @param string $type Schema.org WebPage subtype.
		 */
		return (string) apply_filters( 'neoseo_webpage_type', $type );
	}
}
