<?php
/**
 * Shared provider helpers.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Common @id and URL helpers for providers.
 */
abstract class Base implements Provider {

	/**
	 * Site-level @id (Organization, WebSite live at the home URL).
	 *
	 * @param string $fragment Fragment, e.g. 'organization'.
	 * @return string
	 */
	protected function site_id( string $fragment ): string {
		return home_url( '/' ) . '#' . $fragment;
	}

	/**
	 * Page-level @id (WebPage, BreadcrumbList, Product live at the current URL).
	 *
	 * @param string $fragment Fragment, e.g. 'webpage'.
	 * @return string
	 */
	protected function page_id( string $fragment ): string {
		return $this->current_url() . '#' . $fragment;
	}

	/**
	 * Canonical-ish URL of the current request.
	 *
	 * @return string
	 */
	protected function current_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();

			if ( is_string( $permalink ) ) {
				return $permalink;
			}
		}

		global $wp;

		return home_url( isset( $wp->request ) ? '/' . ltrim( (string) $wp->request, '/' ) : '/' );
	}

	/**
	 * ImageObject node for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $id_fragment   Fragment for the node @id.
	 * @return array<string, mixed> Empty when the attachment has no URL.
	 */
	protected function image_node( int $attachment_id, string $id_fragment = 'primaryimage' ): array {
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( ! $src ) {
			return array();
		}

		$node = array(
			'@type' => 'ImageObject',
			'@id'   => $this->page_id( $id_fragment ),
			'url'   => $src[0],
		);

		if ( ! empty( $src[1] ) && ! empty( $src[2] ) ) {
			$node['width']  = (int) $src[1];
			$node['height'] = (int) $src[2];
		}

		return $node;
	}
}
