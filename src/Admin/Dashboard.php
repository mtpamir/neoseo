<?php
/**
 * Dashboard tab: what NeoSEO is doing on this site.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Admin;

use NeoSEO\Compat\Detector;
use NeoSEO\Schema\GraphManager;
use NeoSEO\Schema\Provider\Faq;

defined( 'ABSPATH' ) || exit;

/**
 * Renders coverage stats, module status and the live schema inspector.
 */
class Dashboard {

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
	 * Renders the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_coverage();
		$this->render_modules();
		$this->render_inspector();
	}

	/**
	 * Coverage stats: what content currently receives schema.
	 *
	 * @return void
	 */
	private function render_coverage(): void {
		$posts = (int) wp_count_posts( 'post' )->publish;
		$pages = (int) wp_count_posts( 'page' )->publish;

		$cards = array(
			array(
				'value' => number_format_i18n( $posts ),
				'label' => __( 'Posts with Article schema', 'neoseo' ),
			),
			array(
				'value' => number_format_i18n( $pages ),
				'label' => __( 'Pages with WebPage schema', 'neoseo' ),
			),
		);

		if ( $this->detector->woocommerce_active() ) {
			$products = (int) wp_count_posts( 'product' )->publish;

			$cards[] = array(
				'value' => number_format_i18n( $products ),
				'label' => __( 'Products with Product schema', 'neoseo' ),
			);
		}

		$cards[] = array(
			'value' => number_format_i18n( $this->count_faq_content() ),
			'label' => __( 'Pages with FAQ schema', 'neoseo' ),
		);

		echo '<h2>' . esc_html__( 'Coverage', 'neoseo' ) . '</h2>';
		echo '<div class="neoplugin-grid">';

		foreach ( $cards as $card ) {
			echo '<div class="neoplugin-card neoplugin-card-stat">';
			echo '<div class="neoplugin-stat">' . esc_html( $card['value'] ) . '</div>';
			echo '<div class="neoplugin-label">' . esc_html( $card['label'] ) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Counts published content that actually yields FAQ schema.
	 *
	 * Narrows to plausible candidates in SQL (content holding Details blocks
	 * directly, or a pattern reference that may expand into them), then
	 * confirms each with the provider's own rules so the number on screen
	 * matches what is really emitted.
	 *
	 * @return int
	 */
	private function count_faq_content(): int {
		$cached = get_transient( 'neoseo_faq_coverage' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// A LIKE over post_content has no WP_Query equivalent ('s' searches
		// differently and would miss block comments), so this is a direct
		// query by necessity. The result is cached in the transient above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			AND post_type IN ( 'post', 'page' )
			AND ( post_content LIKE '%<!-- wp:details%' OR post_content LIKE '%<!-- wp:pattern%' )
			LIMIT 200"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$faq   = new Faq();
		$count = 0;

		foreach ( (array) $candidates as $candidate_id ) {
			$candidate = get_post( (int) $candidate_id );

			if ( $candidate && $faq->content_has_faq( (string) $candidate->post_content ) ) {
				++$count;
			}
		}

		set_transient( 'neoseo_faq_coverage', $count, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Module status: what is switched on right now, and why.
	 *
	 * @return void
	 */
	private function render_modules(): void {
		$seo_plugin = $this->detector->seo_plugin();

		$modules = Modules::all();

		// The dashboard links the live endpoint; the shared catalogue keeps
		// its description plain so the settings form can reuse it as-is.
		$modules['llms']['description'] .= ' <a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noreferrer">' . esc_html( home_url( '/llms.txt' ) ) . '</a>';

		echo '<h2>' . esc_html__( 'What NeoSEO is doing', 'neoseo' ) . '</h2>';

		if ( $seo_plugin ) {
			echo '<div class="notice notice-info inline"><p>';
			printf(
				/* translators: %s: detected SEO plugin slug. */
				esc_html__( 'Another SEO plugin (%s) is active, so overlapping modules are off by default to avoid duplicate output. You can re-enable any of them on the Settings tab.', 'neoseo' ),
				'<strong>' . esc_html( $seo_plugin ) . '</strong>'
			);
			echo '</p></div>';
		}

		echo '<div class="neoplugin-card"><ul class="neoseo-status-list">';

		foreach ( $modules as $module => $info ) {
			$enabled = $this->detector->module_enabled( $module );
			$icon    = $enabled ? 'yes-alt' : 'marker';
			$state   = $enabled ? 'neoseo-on' : 'neoseo-off';
			$status  = $enabled ? __( 'Active', 'neoseo' ) : __( 'Off', 'neoseo' );

			echo '<li>';
			echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . ' ' . esc_attr( $state ) . '" aria-hidden="true"></span>';
			echo '<span><strong>' . esc_html( $info['label'] ) . '</strong> — <span class="' . esc_attr( $state ) . '">' . esc_html( $status ) . '</span>';
			echo '<span class="neoseo-status-desc">' . wp_kses(
				$info['description'],
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			) . '</span>';
			echo '</span></li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Live schema inspector: renders the real graph for a chosen page.
	 *
	 * @return void
	 */
	private function render_inspector(): void {
		$post_id = isset( $_GET['neoseo_preview'] ) ? absint( wp_unslash( $_GET['neoseo_preview'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview selector, no state change.

		echo '<h2>' . esc_html__( 'Schema inspector', 'neoseo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pick any published page to see the exact JSON-LD NeoSEO outputs for it — no need to view source.', 'neoseo' ) . '</p>';

		echo '<form method="get" class="neoseo-inspector-form">';
		echo '<input type="hidden" name="page" value="neoseo" />';
		echo '<input type="hidden" name="tab" value="dashboard" />';
		echo '<label for="neoseo-preview-select" class="screen-reader-text">' . esc_html__( 'Choose content to inspect', 'neoseo' ) . '</label>';
		echo '<select name="neoseo_preview" id="neoseo-preview-select">';
		echo '<option value="0">' . esc_html__( '— Select content —', 'neoseo' ) . '</option>';

		foreach ( $this->inspectable_content() as $group => $items ) {
			echo '<optgroup label="' . esc_attr( $group ) . '">';

			foreach ( $items as $id => $title ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $id,
					selected( $post_id, $id, false ),
					esc_html( $title )
				);
			}

			echo '</optgroup>';
		}

		echo '</select>';
		submit_button( __( 'Inspect', 'neoseo' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $post_id ) {
			return;
		}

		if ( ! $this->detector->module_enabled( 'schema' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The structured-data module is currently off, so this graph is a preview of what would be output if you enabled it.', 'neoseo' ) . '</p></div>';
		}

		$graph = $this->preview_graph( $post_id );

		if ( ! $graph ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No schema could be generated for that content.', 'neoseo' ) . '</p></div>';

			return;
		}

		echo '<ul class="neoseo-pills">';

		foreach ( $graph as $node ) {
			if ( ! empty( $node['@type'] ) && is_string( $node['@type'] ) ) {
				echo '<li class="neoseo-pill">' . esc_html( $node['@type'] ) . '</li>';
			}
		}

		echo '</ul>';

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		echo '<pre class="neoseo-json">' . esc_html( (string) $json ) . '</pre>';

		printf(
			'<p><a href="%1$s" target="_blank" rel="noreferrer">%2$s</a> &nbsp;|&nbsp; <a href="%3$s" target="_blank" rel="noreferrer">%4$s</a></p>',
			esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( (string) get_permalink( $post_id ) ) ),
			esc_html__( 'Test this page with Google Rich Results', 'neoseo' ),
			esc_url( 'https://validator.schema.org/' ),
			esc_html__( 'Open the Schema.org validator', 'neoseo' )
		);
	}

	/**
	 * Published content the inspector can preview, grouped by type.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function inspectable_content(): array {
		$groups = array();

		$types = array(
			'page' => __( 'Pages', 'neoseo' ),
			'post' => __( 'Posts', 'neoseo' ),
		);

		if ( $this->detector->woocommerce_active() ) {
			$types['product'] = __( 'Products', 'neoseo' );
		}

		foreach ( $types as $type => $label ) {
			$items = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 20,
					'suppress_filters' => false,
				)
			);

			if ( ! $items ) {
				continue;
			}

			$groups[ $label ] = array();

			foreach ( $items as $item ) {
				$groups[ $label ][ (int) $item->ID ] = get_the_title( $item ) ? get_the_title( $item ) : __( '(no title)', 'neoseo' );
			}
		}

		return $groups;
	}

	/**
	 * Builds the graph for a post by simulating its front-end query.
	 *
	 * The providers read conditional tags (is_singular, is_product, …) from
	 * the main query, so the globals are swapped for the duration of the
	 * build and restored afterwards.
	 *
	 * @param int $post_id Post to preview.
	 * @return array<int, array<string, mixed>>
	 */
	private function preview_graph( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		$original_query = $GLOBALS['wp_query'] ?? null;
		$original_post  = $GLOBALS['post'] ?? null;

		$simulated = new \WP_Query(
			array(
				'p'         => $post_id,
				'post_type' => $post->post_type,
			)
		);

		/*
		 * Swapping the main query is prohibited by the coding standard, and
		 * rightly so for front-end code. This is the one case that has no
		 * alternative: the providers ask conditional tags (is_singular,
		 * is_product) what page this is, and those read the global query. To
		 * show a merchant the schema for a product from an admin screen, that
		 * query has to say "this is that product".
		 *
		 * The swap is bounded and restored in the finally below, so nothing
		 * outside this method ever observes it.
		 */
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Bounded admin-only preview; restored in finally.
		$GLOBALS['wp_query'] = $simulated;

		if ( $simulated->have_posts() ) {
			$simulated->the_post();
		}

		try {
			$graph = ( new GraphManager( $this->detector ) )->build_graph();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the caller's globals.
			$GLOBALS['wp_query'] = $original_query;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the caller's globals.
			$GLOBALS['post'] = $original_post;
		}

		return $graph;
	}
}
