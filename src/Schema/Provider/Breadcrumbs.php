<?php
/**
 * BreadcrumbList node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The per-request BreadcrumbList node (#breadcrumb).
 *
 * Trails: home → shop → product category → product for WooCommerce,
 * home → category → post for blog posts, home → ancestors → page for pages.
 */
class Breadcrumbs extends Base {

	/**
	 * Breadcrumbs make no sense on the front page or error pages.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return ! is_front_page() && ! is_404();
	}

	/**
	 * Builds the BreadcrumbList node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$crumbs = $this->trail();

		if ( count( $crumbs ) < 2 ) {
			return array();
		}

		$items    = array();
		$position = 1;

		foreach ( $crumbs as $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $crumb['name'],
			);

			// The last crumb is the current page; schema.org recommends
			// omitting its item URL.
			if ( $position < count( $crumbs ) && ! empty( $crumb['url'] ) ) {
				$item['item'] = $crumb['url'];
			}

			$items[] = $item;
			++$position;
		}

		return array(
			array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $this->page_id( 'breadcrumb' ),
				'itemListElement' => $items,
			),
		);
	}

	/**
	 * Builds the name/url trail for the current request.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function trail(): array {
		$trail = array(
			array(
				'name' => __( 'Home', 'neoseo' ),
				'url'  => home_url( '/' ),
			),
		);

		if ( function_exists( 'is_product' ) && is_product() ) {
			$trail = array_merge( $trail, $this->shop_crumb(), $this->product_category_crumbs(), $this->current_crumb() );
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$trail = array_merge( $trail, $this->shop_crumb() );
		} elseif ( is_singular( 'post' ) ) {
			$categories = get_the_category();

			if ( $categories ) {
				$trail[] = array(
					'name' => $categories[0]->name,
					'url'  => (string) get_category_link( $categories[0] ),
				);
			}

			$trail = array_merge( $trail, $this->current_crumb() );
		} elseif ( is_page() ) {
			$post = get_post();

			if ( $post && $post->post_parent ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
					$trail[] = array(
						'name' => get_the_title( $ancestor_id ),
						'url'  => (string) get_permalink( $ancestor_id ),
					);
				}
			}

			$trail = array_merge( $trail, $this->current_crumb() );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				if ( 'product_cat' === $term->taxonomy || 'product_tag' === $term->taxonomy ) {
					$trail = array_merge( $trail, $this->shop_crumb() );
				}

				$trail[] = array(
					'name' => $term->name,
					'url'  => (string) get_term_link( $term ),
				);
			}
		} elseif ( is_singular() ) {
			$trail = array_merge( $trail, $this->current_crumb() );
		} elseif ( is_search() ) {
			$trail[] = array(
				'name' => __( 'Search results', 'neoseo' ),
				'url'  => '',
			);
		} elseif ( is_archive() ) {
			$trail[] = array(
				'name' => (string) wp_strip_all_tags( get_the_archive_title() ),
				'url'  => '',
			);
		}

		return $trail;
	}

	/**
	 * The shop page crumb, when WooCommerce has one.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function shop_crumb(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}

		$shop_id = wc_get_page_id( 'shop' );

		if ( $shop_id <= 0 ) {
			return array();
		}

		return array(
			array(
				'name' => get_the_title( $shop_id ),
				'url'  => (string) get_permalink( $shop_id ),
			),
		);
	}

	/**
	 * The primary product category crumb for the current product.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function product_category_crumbs(): array {
		$terms = get_the_terms( get_the_ID(), 'product_cat' );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return array();
		}

		$term = $terms[0];

		return array(
			array(
				'name' => $term->name,
				'url'  => (string) get_term_link( $term ),
			),
		);
	}

	/**
	 * The current singular item as the final crumb.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	private function current_crumb(): array {
		return array(
			array(
				'name' => (string) get_the_title(),
				'url'  => (string) get_permalink(),
			),
		);
	}
}
