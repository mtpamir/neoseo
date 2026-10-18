<?php
/**
 * WebSite node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The site-wide WebSite node (#website) with a site-search SearchAction.
 */
class WebSite extends Base {

	/**
	 * The WebSite applies to every request.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return true;
	}

	/**
	 * Builds the WebSite node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$node = array(
			'@type'     => 'WebSite',
			'@id'       => $this->site_id( 'website' ),
			'name'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'publisher' => array( '@id' => $this->site_id( 'organization' ) ),
		);

		$description = get_bloginfo( 'description' );

		if ( $description ) {
			$node['description'] = $description;
		}

		$node['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		);

		return array( $node );
	}
}
