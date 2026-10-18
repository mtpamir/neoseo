<?php
/**
 * JSON-LD graph assembly and output.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Schema;

use NeoSEO\Compat\Detector;
use NeoSEO\Schema\Provider\Article;
use NeoSEO\Schema\Provider\Breadcrumbs;
use NeoSEO\Schema\Provider\Faq;
use NeoSEO\Schema\Provider\Organization;
use NeoSEO\Schema\Provider\Product;
use NeoSEO\Schema\Provider\ProductList;
use NeoSEO\Schema\Provider\ProductVariants;
use NeoSEO\Schema\Provider\Provider;
use NeoSEO\Schema\Provider\WebPage;
use NeoSEO\Schema\Provider\WebSite;

defined( 'ABSPATH' ) || exit;

/**
 * Collects nodes from the providers and prints a single @graph in wp_head.
 *
 * Every node is @id-linked (#organization, #website, #webpage, #breadcrumb,
 * #product) so consumers see one connected graph rather than loose blobs.
 */
class GraphManager {

	/**
	 * SEO-plugin detector.
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param Detector $detector Module gate.
	 */
	public function __construct( Detector $detector ) {
		$this->detector = $detector;
	}

	/**
	 * Hooks the output. No-op when the schema module is off.
	 *
	 * @return void
	 */
	public function register(): void {
		$schema = $this->detector->module_enabled( 'schema' );

		if ( ! $schema && ! $this->variants_only() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'output' ), 5 );

		// WooCommerce prints its own (thinner) Product JSON-LD in wp_footer.
		// NeoSEO's Product node replaces it, so exactly one Product exists.
		//
		// Only when the full schema module runs. In variants-only mode there
		// is nothing replacing it, and removing it would leave the page with
		// less markup than before NeoSEO was installed.
		if ( $schema && $this->detector->woocommerce_active() ) {
			add_action( 'init', array( $this, 'unhook_woocommerce_structured_data' ), 20 );
		}
	}

	/**
	 * Whether only the variant markup should run.
	 *
	 * This is the per-node back-off. When a full SEO plugin owns the page's
	 * schema, NeoSEO stands down from everything it would duplicate — but
	 * none of Yoast, Rank Math, AIOSEO, SEOPress or Slim SEO emits
	 * ProductGroup variant markup, so standing down from that too would
	 * leave the store with no variant eligibility from anyone.
	 *
	 * @return bool
	 */
	private function variants_only(): bool {
		return $this->detector->woocommerce_active()
			&& $this->detector->module_enabled( 'variants' );
	}

	/**
	 * Removes WooCommerce's structured-data footer output.
	 *
	 * @return void
	 */
	public function unhook_woocommerce_structured_data(): void {
		if ( function_exists( 'WC' ) && isset( WC()->structured_data ) ) {
			remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		}
	}

	/**
	 * The provider list for the current request.
	 *
	 * @return Provider[]
	 */
	private function providers(): array {
		if ( ! $this->detector->module_enabled( 'schema' ) ) {
			// Variants-only: contribute the one node the active SEO plugin
			// does not produce, and nothing else.
			$providers = array( new ProductVariants() );

			/**
			 * Filters the schema providers.
			 *
			 * @since 1.0.0
			 *
			 * @param Provider[] $providers Provider instances.
			 */
			return apply_filters( 'neoseo_schema_providers', $providers );
		}

		$providers = array(
			new Organization(),
			new WebSite(),
			new WebPage(),
			new Breadcrumbs(),
			new Article(),
			new Faq(),
		);

		if ( $this->detector->woocommerce_active() ) {
			$providers[] = new Product();
			$providers[] = new ProductList();
		}

		/**
		 * Filters the schema providers.
		 *
		 * @since 1.0.0
		 *
		 * @param Provider[] $providers Provider instances.
		 */
		return apply_filters( 'neoseo_schema_providers', $providers );
	}

	/**
	 * Builds the graph for the current request.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function build_graph(): array {
		$graph = array();

		foreach ( $this->providers() as $provider ) {
			if ( ! $provider instanceof Provider || ! $provider->applies() ) {
				continue;
			}

			foreach ( $provider->nodes() as $node ) {
				if ( ! empty( $node ) ) {
					$graph[] = $node;
				}
			}
		}

		/**
		 * Filters the assembled JSON-LD graph before output.
		 *
		 * @since 1.0.0
		 *
		 * @param array $graph Schema.org nodes.
		 */
		return apply_filters( 'neoseo_schema_graph', $graph );
	}

	/**
	 * Prints the graph as one application/ld+json script tag.
	 *
	 * @return void
	 */
	public function output(): void {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		$graph = $this->build_graph();

		if ( empty( $graph ) ) {
			return;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}
}
