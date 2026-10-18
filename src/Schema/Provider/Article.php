<?php
/**
 * BlogPosting node.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * BlogPosting node for single posts, with speakable hints for voice/AI search.
 */
class Article extends Base {

	/**
	 * Only single blog posts.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return is_singular( 'post' );
	}

	/**
	 * Builds the BlogPosting node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$post = get_post();

		if ( ! $post ) {
			return array();
		}

		$node = array(
			'@type'            => 'BlogPosting',
			'@id'              => $this->page_id( 'article' ),
			'headline'         => get_the_title( $post ),
			'url'              => (string) get_permalink( $post ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array( '@id' => $this->page_id( 'webpage' ) ),
			'publisher'        => array( '@id' => $this->site_id( 'organization' ) ),
			'inLanguage'       => get_bloginfo( 'language' ),
			'wordCount'        => str_word_count( wp_strip_all_tags( (string) $post->post_content ) ),
			'speakable'        => array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => array( 'h1', '.entry-content > p:first-of-type' ),
			),
		);

		$author_id = (int) $post->post_author;

		if ( $author_id ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $author_id ),
				'url'   => get_author_posts_url( $author_id ),
			);
		}

		$excerpt = get_the_excerpt( $post );

		if ( $excerpt ) {
			$node['description'] = wp_strip_all_tags( $excerpt );
		}

		$thumbnail_id = get_post_thumbnail_id( $post );

		if ( $thumbnail_id ) {
			$src = wp_get_attachment_image_src( (int) $thumbnail_id, 'full' );

			if ( $src ) {
				$node['image'] = $src[0];
			}
		}

		$categories = get_the_category( $post->ID );

		if ( $categories ) {
			$node['articleSection'] = wp_list_pluck( $categories, 'name' );
		}

		return array( $node );
	}
}
