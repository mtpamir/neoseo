<?php
/**
 * The module catalogue.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the toggleable modules.
 *
 * Shared by the settings form and the dashboard status list so the two can
 * never describe the same module differently.
 */
class Modules {

	/**
	 * All modules, keyed by slug.
	 *
	 * Called lazily rather than held in a property: the admin classes are
	 * constructed at plugins_loaded, which is too early to translate on
	 * WordPress 6.7+.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function all(): array {
		return array(
			'schema'   => array(
				'label'       => __( 'JSON-LD structured data', 'neoseo' ),
				'description' => __( 'One connected @graph in the page head: Organization, WebSite, WebPage, breadcrumbs, articles, products and FAQs.', 'neoseo' ),
			),
			'variants' => array(
				'label'       => __( 'Product variant markup', 'neoseo' ),
				'description' => __( 'ProductGroup markup so each variation of a variable product can appear in search with its own price, size and colour. Stays on when another SEO plugin is active, because none of them produce it.', 'neoseo' ),
			),
			'meta'     => array(
				'label'       => __( 'Meta description', 'neoseo' ),
				'description' => __( 'Generated from the excerpt, product short description or term description.', 'neoseo' ),
			),
			'social'   => array(
				'label'       => __( 'Open Graph & Twitter cards', 'neoseo' ),
				'description' => __( 'Title, description, canonical URL and image for social sharing previews.', 'neoseo' ),
			),
			'llms'     => array(
				'label'       => __( 'llms.txt for AI crawlers', 'neoseo' ),
				'description' => __( 'A cached markdown summary of your site — identity, key pages, product categories and popular products.', 'neoseo' ),
			),
		);
	}

	/**
	 * The module slugs.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}
}
