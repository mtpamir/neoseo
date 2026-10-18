<?php
/**
 * Description resolution: the manual override and the text extractor.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Shared description helpers.
 *
 * Both the meta tag and the structured data describe the same page, so they
 * read the same override and the same extracted text rather than each
 * deriving their own.
 */
class Description {

	/**
	 * Post meta key holding an author-written description.
	 */
	public const META_KEY = '_neoseo_description';

	/**
	 * Blocks that carry prose worth describing a page with.
	 *
	 * Deliberately an allowlist: buttons, navigation and site titles are text
	 * too, and including them produced descriptions like "Shop now Learn more".
	 *
	 * @var string[]
	 */
	private const TEXT_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/pullquote',
		'core/verse',
		'core/preformatted',
		'core/table',
	);

	/**
	 * The author-written description for a post, if any.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty when nothing was written.
	 */
	public static function override( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		return trim( (string) get_post_meta( $post_id, self::META_KEY, true ) );
	}

	/**
	 * The description a post would get from its own content.
	 *
	 * Shared by the front-end meta tag and the editor preview so the author
	 * is shown exactly what would ship.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Plain text, not yet trimmed to length.
	 */
	public static function for_post( \WP_Post $post ): string {
		$raw = '';

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$raw = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
			}
		} elseif ( has_excerpt( $post ) ) {
			$raw = get_the_excerpt( $post );
		} else {
			$raw = $post->post_content;
		}

		return self::from_content( (string) $raw );
	}

	/**
	 * Extracts readable prose from block content.
	 *
	 * Works on the parsed blocks rather than the raw string for two reasons:
	 * a pattern block holds only a reference, so its text is invisible to a
	 * plain strip_tags, and stripping tags without a separator runs the last
	 * word of one block into the first word of the next.
	 *
	 * @param string $content Post content.
	 * @return string Plain text, whitespace normalised.
	 */
	public static function from_content( string $content ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( ! has_blocks( $content ) ) {
			return self::normalise( $content );
		}

		return self::normalise( self::walk( parse_blocks( $content ) ) );
	}

	/**
	 * Collects the inner HTML of prose blocks, expanding pattern references.
	 *
	 * @param array<int, array<string, mixed>> $blocks        Parsed blocks.
	 * @param string[]                         $seen_patterns Pattern slugs already expanded, guarding against cycles.
	 * @return string Concatenated HTML, space separated.
	 */
	private static function walk( array $blocks, array $seen_patterns = array() ): string {
		$text = '';

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			if ( 'core/pattern' === $name ) {
				$text .= ' ' . self::expand_pattern( $block, $seen_patterns );

				continue;
			}

			if ( in_array( $name, self::TEXT_BLOCKS, true ) && ! empty( $block['innerHTML'] ) ) {
				$text .= ' ' . $block['innerHTML'];
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$text .= ' ' . self::walk( $block['innerBlocks'], $seen_patterns );
			}
		}

		return $text;
	}

	/**
	 * Resolves a pattern reference and walks its blocks.
	 *
	 * @param array<string, mixed> $block         Parsed core/pattern block.
	 * @param string[]             $seen_patterns Pattern slugs already expanded.
	 * @return string
	 */
	private static function expand_pattern( array $block, array $seen_patterns ): string {
		$slug = $block['attrs']['slug'] ?? '';

		if ( ! is_string( $slug ) || '' === $slug || in_array( $slug, $seen_patterns, true ) ) {
			return '';
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();

		if ( ! $registry->is_registered( $slug ) ) {
			return '';
		}

		$pattern = $registry->get_registered( $slug );

		if ( empty( $pattern['content'] ) ) {
			return '';
		}

		$seen_patterns[] = $slug;

		return self::walk( parse_blocks( (string) $pattern['content'] ), $seen_patterns );
	}

	/**
	 * Strips markup and collapses whitespace.
	 *
	 * The space before every tag is what keeps "…Story" and "We hand-make…"
	 * from becoming "StoryWe hand-make".
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function normalise( string $html ): string {
		$text = strip_shortcodes( $html );
		$text = str_replace( '<', ' <', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}
}
