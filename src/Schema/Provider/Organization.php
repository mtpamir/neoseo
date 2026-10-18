<?php
/**
 * Organization node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The site-wide Organization node (#organization), settings-driven.
 */
class Organization extends Base {

	/**
	 * The Organization applies to every request.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return true;
	}

	/**
	 * Builds the Organization node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$settings = get_option( 'neoseo_organization', array() );

		$node = array(
			'@type' => 'Organization',
			'@id'   => $this->site_id( 'organization' ),
			'name'  => ! empty( $settings['name'] ) ? $settings['name'] : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo_id = 0;

		if ( ! empty( $settings['logo_id'] ) ) {
			$logo_id = (int) $settings['logo_id'];
		} elseif ( has_site_icon() ) {
			$logo_id = (int) get_option( 'site_icon' );
		} elseif ( has_custom_logo() ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
		}

		if ( $logo_id ) {
			$logo_src = wp_get_attachment_image_src( $logo_id, 'full' );

			if ( $logo_src ) {
				$node['logo'] = array(
					'@type' => 'ImageObject',
					'@id'   => $this->site_id( 'logo' ),
					'url'   => $logo_src[0],
				);
			}
		}

		if ( ! empty( $settings['same_as'] ) && is_string( $settings['same_as'] ) ) {
			$urls = array_filter( array_map( 'trim', explode( "\n", $settings['same_as'] ) ) );

			if ( $urls ) {
				$node['sameAs'] = array_values( array_map( 'esc_url_raw', $urls ) );
			}
		}

		return array( $node );
	}
}
