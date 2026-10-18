<?php
/**
 * /llms.txt endpoint for generative-engine (GEO) discovery.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Aeo;

use NeoSEO\Compat\Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a cached markdown summary of the site at /llms.txt so AI crawlers
 * and answer engines get a compact, accurate picture of the store.
 */
class LlmsTxt {

	/**
	 * Query var flagging the endpoint.
	 */
	private const QUERY_VAR = 'neoseo_llms';

	/**
	 * Transient key for the rendered document.
	 */
	private const CACHE_KEY = 'neoseo_llms_txt';

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
	 * Hooks the endpoint. No-op when the llms module is off.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->detector->module_enabled( 'llms' ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'skip_canonical_redirect' ) );

		$this->register_cache_invalidation();
	}

	/**
	 * Registers the /llms.txt rewrite rule. Also called on activation.
	 *
	 * @return void
	 */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Hooks every event that can change what the document says.
	 *
	 * The document lists pages, product categories, popular products and
	 * recent posts, so publishing is only half the story: deletions, term
	 * changes and a renamed site all have to invalidate it too. Popularity
	 * ordering shifts with each sale and is left to the cache lifetime.
	 *
	 * @return void
	 */
	private function register_cache_invalidation(): void {
		// Content: created, updated, trashed, restored or deleted outright.
		add_action( 'save_post', array( $this, 'flush_cache_for_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'flush_cache' ) );
		add_action( 'trashed_post', array( $this, 'flush_cache' ) );
		add_action( 'untrashed_post', array( $this, 'flush_cache' ) );

		// Product categories are listed by name and link.
		add_action( 'created_term', array( $this, 'flush_cache_for_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'flush_cache_for_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'flush_cache_for_term' ), 10, 3 );

		// The Pages section follows the site navigation: classic menus and
		// their location assignments, or a block theme's wp_navigation post
		// (which saves through save_post, already hooked above).
		add_action( 'wp_update_nav_menu', array( $this, 'flush_cache' ) );
		add_action( 'wp_delete_nav_menu', array( $this, 'flush_cache' ) );
		add_action( 'update_option_theme_mods_' . get_option( 'stylesheet' ), array( $this, 'flush_cache' ) );

		// Site identity heads the document.
		add_action( 'update_option_blogname', array( $this, 'flush_cache' ) );
		add_action( 'update_option_blogdescription', array( $this, 'flush_cache' ) );
	}

	/**
	 * Flushes for a real content change, ignoring autosaves and revisions.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function flush_cache_for_post( $post_id, $post = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// wp_navigation is included: on block themes it is the Pages section.
		if ( $post instanceof \WP_Post && ! in_array( $post->post_type, array( 'post', 'page', 'product', 'wp_navigation' ), true ) ) {
			return;
		}

		$this->flush_cache();
	}

	/**
	 * Flushes when a listed taxonomy changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function flush_cache_for_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		$this->flush_cache();
	}

	/**
	 * Deletes the cached document.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Keeps WordPress from 301-redirecting /llms.txt to a trailing-slash URL.
	 *
	 * @param string|false $redirect_url Proposed redirect URL.
	 * @return string|false
	 */
	public function skip_canonical_redirect( $redirect_url ) {
		if ( '1' === get_query_var( self::QUERY_VAR ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Serves the document when the request is for /llms.txt.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		if ( '1' !== get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$body = get_transient( self::CACHE_KEY );

		if ( ! is_string( $body ) || '' === $body ) {
			$body = $this->render();
			set_transient( self::CACHE_KEY, $body, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text document assembled from sanitized parts below.
		exit;
	}

	/**
	 * Renders the markdown document.
	 *
	 * @return string
	 */
	public function render(): string {
		$lines   = array();
		$lines[] = '# ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
		$lines[] = '';

		$tagline = wp_strip_all_tags( get_bloginfo( 'description' ) );

		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		$lines[] = esc_url_raw( home_url( '/' ) );
		$lines[] = '';

		$pages = $this->key_pages();

		if ( $pages ) {
			$lines[] = '## Pages';
			$lines[] = '';

			foreach ( $pages as $title => $url ) {
				$lines[] = '- [' . $title . '](' . $url . ')';
			}

			$lines[] = '';
		}

		if ( $this->detector->woocommerce_active() ) {
			$lines = array_merge( $lines, $this->store_sections() );
		}

		// Recent posts.
		$posts = get_posts(
			array(
				'numberposts' => 10,
				'post_status' => 'publish',
			)
		);

		if ( $posts ) {
			$lines[] = '## Recent articles';
			$lines[] = '';

			foreach ( $posts as $post ) {
				$lines[] = '- [' . wp_strip_all_tags( get_the_title( $post ) ) . '](' . esc_url_raw( (string) get_permalink( $post ) ) . ')';
			}

			$lines[] = '';
		}

		$body = implode( "\n", $lines );

		/**
		 * Filters the rendered llms.txt document.
		 *
		 * @since 1.0.0
		 *
		 * @param string $body Markdown document.
		 */
		return (string) apply_filters( 'neoseo_llms_txt', $body );
	}

	/**
	 * The key pages to advertise, title => URL.
	 *
	 * The site's primary navigation is the best available statement of what
	 * matters: it is curated, it reaches child pages, and it leaves out the
	 * functional cart and checkout pages that a crawler has no use for. Sites
	 * with no menu assigned fall back to top-level pages.
	 *
	 * @return array<string, string>
	 */
	private function key_pages(): array {
		// Classic themes: a menu assigned to a location.
		$pages = array();

		foreach ( $this->primary_menu_items() as $item ) {
			$this->add_page( $pages, (string) $item->title, (string) $item->url );
		}

		// Block themes register no menu locations at all; their navigation
		// lives in a wp_navigation post.
		if ( ! $pages ) {
			$pages = $this->block_navigation_pages();
		}

		if ( ! $pages ) {
			$pages = $this->top_level_pages();
		}

		return array_slice( $pages, 0, 15, true );
	}

	/**
	 * Adds a page to the list if it is worth advertising.
	 *
	 * @param array<string, string> $pages Collected pages, by reference.
	 * @param string                $title Link label.
	 * @param string                $url   Link URL.
	 * @return void
	 */
	private function add_page( array &$pages, string $title, string $url ): void {
		$title = trim( wp_strip_all_tags( $title ) );
		$url   = trim( $url );

		// Separators and placeholder links point nowhere useful.
		if ( '' === $title || '' === $url || '#' === $url ) {
			return;
		}

		$pages[ $title ] = esc_url_raw( $url );
	}

	/**
	 * Pages named by the block theme's navigation.
	 *
	 * @return array<string, string>
	 */
	private function block_navigation_pages(): array {
		$navigation = get_posts(
			array(
				'post_type'        => 'wp_navigation',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		if ( ! $navigation ) {
			return array();
		}

		$pages = array();

		$this->collect_navigation_links( parse_blocks( (string) $navigation[0]->post_content ), $pages );

		return $pages;
	}

	/**
	 * Walks navigation blocks, collecting links.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<string, string>            $pages  Collected pages, by reference.
	 * @return void
	 */
	private function collect_navigation_links( array $blocks, array &$pages ): void {
		foreach ( $blocks as $block ) {
			switch ( $block['blockName'] ?? '' ) {
				case 'core/navigation-link':
				case 'core/navigation-submenu':
					$this->add_page(
						$pages,
						(string) ( $block['attrs']['label'] ?? '' ),
						(string) ( $block['attrs']['url'] ?? '' )
					);
					break;

				// A page list renders every top-level page, so expand it to
				// the same entries the reader would see.
				case 'core/page-list':
					$pages = array_merge( $pages, $this->top_level_pages() );
					break;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_navigation_links( $block['innerBlocks'], $pages );
			}
		}
	}

	/**
	 * Published top-level pages, the fallback ordering.
	 *
	 * @return array<string, string>
	 */
	private function top_level_pages(): array {
		$pages = array();

		// Cart, checkout and account pages are per-visitor screens with nothing
		// for a crawler to read. A menu that links them is the merchant's
		// choice; this fallback only ever lists them by accident.
		$exclude = array();

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
				$page_id = (int) wc_get_page_id( $wc_page );

				if ( $page_id > 0 ) {
					$exclude[] = $page_id;
				}
			}
		}

		// Over-fetch by the excluded count and skip them here, rather than
		// passing an exclusion list to the query.
		$top_level = get_pages(
			array(
				'parent'      => 0,
				'sort_column' => 'menu_order',
				'number'      => 15 + count( $exclude ),
			)
		);

		foreach ( (array) $top_level as $page ) {
			if ( in_array( (int) $page->ID, $exclude, true ) || count( $pages ) >= 15 ) {
				continue;
			}

			$this->add_page( $pages, (string) $page->post_title, (string) get_permalink( $page ) );
		}

		return $pages;
	}

	/**
	 * Items from the primary menu, or the first menu with a location.
	 *
	 * @return \WP_Post[]
	 */
	private function primary_menu_items(): array {
		$locations = get_nav_menu_locations();

		if ( ! is_array( $locations ) || ! $locations ) {
			return array();
		}

		// Prefer a location that names itself primary; block themes commonly
		// register a single location instead.
		$menu_id = 0;

		foreach ( array( 'primary', 'menu-1', 'main' ) as $preferred ) {
			if ( ! empty( $locations[ $preferred ] ) ) {
				$menu_id = (int) $locations[ $preferred ];
				break;
			}
		}

		if ( ! $menu_id ) {
			$menu_id = (int) reset( $locations );
		}

		if ( ! $menu_id ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu_id );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Product category and best-seller sections.
	 *
	 * @return string[]
	 */
	private function store_sections(): array {
		$lines = array();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 15,
			)
		);

		if ( is_array( $terms ) && $terms ) {
			$lines[] = '## Product categories';
			$lines[] = '';

			foreach ( $terms as $term ) {
				$lines[] = '- [' . wp_strip_all_tags( $term->name ) . '](' . esc_url_raw( (string) get_term_link( $term ) ) . ')';
			}

			$lines[] = '';
		}

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 20,
				'orderby' => 'popularity',
			)
		);

		if ( $products ) {
			$lines[] = '## Popular products';
			$lines[] = '';

			foreach ( $products as $product ) {
				$price   = html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
				$lines[] = '- [' . wp_strip_all_tags( $product->get_name() ) . '](' . esc_url_raw( (string) $product->get_permalink() ) . ') — ' . $price;
			}

			$lines[] = '';
		}

		return $lines;
	}
}
