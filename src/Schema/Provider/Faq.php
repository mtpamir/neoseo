<?php
/**
 * FAQPage node from Details blocks.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an FAQPage node by parsing core/details blocks out of the content.
 *
 * Any theme or page builder that emits standard Details blocks feeds this
 * automatically — the NeoShop FAQ pattern is built exactly this way, with
 * zero coupling between theme and plugin.
 */
class Faq extends Base {

	/**
	 * Minimum number of question/answer pairs before an FAQPage is emitted.
	 */
	private const MIN_QUESTIONS = 2;

	/**
	 * Singular content only.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return is_singular();
	}

	/**
	 * Builds the FAQPage node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes(): array {
		$post = get_post();

		if ( ! $post || ! has_blocks( $post ) ) {
			return array();
		}

		$questions = $this->extract_questions( parse_blocks( (string) $post->post_content ) );

		if ( count( $questions ) < self::MIN_QUESTIONS ) {
			return array();
		}

		return array(
			array(
				'@type'      => 'FAQPage',
				'@id'        => $this->page_id( 'faq' ),
				'mainEntity' => $questions,
			),
		);
	}

	/**
	 * Whether a block-content string yields FAQ schema.
	 *
	 * Exposed so the admin can report coverage using exactly the same rules
	 * that decide whether a node is emitted on the front end.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public function content_has_faq( string $content ): bool {
		if ( '' === $content || ! has_blocks( $content ) ) {
			return false;
		}

		return count( $this->extract_questions( parse_blocks( $content ) ) ) >= self::MIN_QUESTIONS;
	}

	/**
	 * Recursively collects Question nodes from core/details blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Parsed blocks.
	 * @param string[]                         $seen_patterns Pattern slugs already expanded, guarding against cycles.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_questions( array $blocks, array $seen_patterns = array() ): array {
		$questions = array();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';

			if ( 'core/details' === $block_name ) {
				$question = $this->question_from_details( $block );

				if ( $question ) {
					$questions[] = $question;
				}

				continue;
			}

			// A pattern block holds only a reference; the Details blocks live
			// in the registered pattern, so resolve it before looking inside.
			if ( 'core/pattern' === $block_name ) {
				$questions = array_merge( $questions, $this->questions_from_pattern( $block, $seen_patterns ) );

				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$questions = array_merge( $questions, $this->extract_questions( $block['innerBlocks'], $seen_patterns ) );
			}
		}

		return $questions;
	}

	/**
	 * Expands a core/pattern reference and collects questions from it.
	 *
	 * @param array<string, mixed> $block         Parsed core/pattern block.
	 * @param string[]             $seen_patterns Pattern slugs already expanded.
	 * @return array<int, array<string, mixed>>
	 */
	private function questions_from_pattern( array $block, array $seen_patterns ): array {
		$slug = $block['attrs']['slug'] ?? '';

		if ( ! is_string( $slug ) || '' === $slug || in_array( $slug, $seen_patterns, true ) ) {
			return array();
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();

		if ( ! $registry->is_registered( $slug ) ) {
			return array();
		}

		$pattern = $registry->get_registered( $slug );

		if ( empty( $pattern['content'] ) ) {
			return array();
		}

		$seen_patterns[] = $slug;

		return $this->extract_questions( parse_blocks( (string) $pattern['content'] ), $seen_patterns );
	}

	/**
	 * Turns one Details block into a Question node.
	 *
	 * The summary element is the question; the rendered inner blocks are
	 * the answer.
	 *
	 * @param array<string, mixed> $block Parsed core/details block.
	 * @return array<string, mixed> Empty when either half is missing.
	 */
	private function question_from_details( array $block ): array {
		$summary = '';

		if ( ! empty( $block['innerHTML'] ) && preg_match( '#<summary[^>]*>(.*?)</summary>#s', (string) $block['innerHTML'], $matches ) ) {
			$summary = trim( wp_strip_all_tags( $matches[1] ) );
		}

		if ( '' === $summary ) {
			return array();
		}

		$answer = '';

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $inner ) {
				$answer .= render_block( $inner );
			}
		}

		$answer = trim( wp_strip_all_tags( $answer ) );

		if ( '' === $answer ) {
			return array();
		}

		return array(
			'@type'          => 'Question',
			'name'           => $summary,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $answer,
			),
		);
	}
}
