<?php
/**
 * Schema provider contract.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * A provider contributes nodes to the JSON-LD @graph for the current request.
 */
interface Provider {

	/**
	 * Whether the provider has anything to say about the current request.
	 *
	 * Runs at wp_head time, so conditional tags are available.
	 *
	 * @return bool
	 */
	public function applies(): bool;

	/**
	 * The nodes to add to the graph.
	 *
	 * @return array<int, array<string, mixed>> Schema.org nodes.
	 */
	public function nodes(): array;
}
