<?php
/**
 * Meta description and social cards.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Aeo;

use NeoSEO\Compat\Detector;
use NeoSEO\Content\Description;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs a meta description plus Open Graph / Twitter cards.
 *
 * Both modules back off when a full SEO plugin is active (Detector default).
 */
class MetaTags {

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
	 * Hooks the outputs that are enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->detector->module_enabled( 'meta' ) ) {
			add_action( 'wp_head', array( $this, 'output_description' ), 2 );
		}

		if ( $this->detector->module_enabled( 'social' ) ) {
			add_action( 'wp_head', array( $this, 'output_social' ), 3 );
		}
	}

	/**
	 * Prints the meta description.
	 *
	 * @return void
	 */
	public function output_description(): void {
		$description = $this->description();

		if ( '' === $description ) {
			return;
		}

		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
	}

	/**
	 * URL of the current request for og:url, without its query string.
	 *
	 * Built from the parsed request path rather than REQUEST_URI: the raw URI
	 * already carries the install's subdirectory, so home_url() would double it,
	 * and it would also copy tracking parameters into the shared URL.
	 *
	 * @return string
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();

			if ( is_string( $permalink ) ) {
				return $permalink;
			}
		}

		global $wp;

		$path = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';

		return '' === $path ? home_url( '/' ) : home_url( user_trailingslashit( '/' . $path ) );
	}

	/**
	 * Prints Open Graph and Twitter card tags.
	 *
	 * @return void
	 */
	public function output_social(): void {
		$title       = wp_get_document_title();
		$description = $this->description();
		$url         = $this->current_url();
		$type        = 'website';

		if ( is_singular( 'post' ) ) {
			$type = 'article';
		} elseif ( function_exists( 'is_product' ) && is_product() ) {
			$type = 'product';
		}

		printf( '<meta property="og:type" content="%s" />' . "\n", esc_attr( $type ) );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );

		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		if ( is_singular() ) {
			$thumbnail_id = get_post_thumbnail_id();

			if ( $thumbnail_id ) {
				$src = wp_get_attachment_image_src( (int) $thumbnail_id, 'large' );

				if ( $src ) {
					printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $src[0] ) );
				}
			}
		}

		if ( 'product' === $type ) {
			$this->output_product_meta();
		}

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}

	/**
	 * Prints the product Open Graph namespace tags.
	 *
	 * Facebook's catalogue and Pinterest's rich pins read these rather than
	 * the JSON-LD, so a store with perfect schema can still share as a bare
	 * link without them.
	 *
	 * @return void
	 */
	private function output_product_meta(): void {
		$product = wc_get_product( get_the_ID() );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$price = wc_get_price_to_display( $product );

		if ( '' !== (string) $price ) {
			printf( '<meta property="product:price:amount" content="%s" />' . "\n", esc_attr( (string) wc_format_decimal( $price, wc_get_price_decimals() ) ) );
			printf( '<meta property="product:price:currency" content="%s" />' . "\n", esc_attr( get_woocommerce_currency() ) );
		}

		printf( '<meta property="product:availability" content="%s" />' . "\n", esc_attr( $this->product_availability( $product ) ) );
	}

	/**
	 * Open Graph availability for a product.
	 *
	 * The Open Graph vocabulary is not schema.org's: these are the values
	 * Facebook's catalogue accepts, spaces and all.
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	private function product_availability( \WC_Product $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'instock':
				return 'in stock';
			case 'onbackorder':
				return 'available for order';
			default:
				return 'out of stock';
		}
	}

	/**
	 * The meta description for the current request.
	 *
	 * @return string
	 */
	public function description(): string {
		// An author-written description always wins.
		if ( is_singular() ) {
			$override = Description::override( (int) get_the_ID() );

			if ( '' !== $override ) {
				/**
				 * Filters the meta description.
				 *
				 * @since 1.0.0
				 *
				 * @param string $description Meta description.
				 */
				return (string) apply_filters( 'neoseo_meta_description', $override );
			}
		}

		$description = $this->generated_description();

		if ( '' === $description ) {
			return '';
		}

		$description = wp_trim_words( $description, 30, '…' );

		/**
		 * Filters the meta description.
		 *
		 * @since 1.0.0
		 *
		 * @param string $description Meta description.
		 */
		return (string) apply_filters( 'neoseo_meta_description', $description );
	}

	/**
	 * The description derived from the content itself.
	 *
	 * Public so the editor can show the author what would be used if they
	 * write nothing.
	 *
	 * @return string
	 */
	public function generated_description(): string {
		$description = '';

		if ( is_front_page() && ! is_singular() ) {
			$description = Description::from_content( (string) get_bloginfo( 'description' ) );
		} elseif ( is_singular() ) {
			$post = get_post();

			$description = $post instanceof \WP_Post ? Description::for_post( $post ) : '';
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$description = Description::from_content( (string) term_description() );
		}

		// A page assembled entirely from patterns or a product with no copy
		// of its own leaves nothing behind; the site tagline at least
		// describes the business rather than shipping an empty tag.
		if ( '' === $description && is_front_page() ) {
			$description = Description::from_content( (string) get_bloginfo( 'description' ) );
		}

		return $description;
	}
}
