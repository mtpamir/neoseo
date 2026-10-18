<?php
/**
 * SEO-plugin detection and module gating.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Detects other plugins writing structured data and decides which NeoSEO
 * modules may run.
 *
 * Two kinds are detected, and they are not the same problem. A **full SEO
 * plugin** (Yoast, Rank Math, AIOSEO, SEOPress, Slim SEO, The SEO Framework)
 * owns titles, descriptions, social tags and schema, so every overlapping
 * NeoSEO module backs off. A **schema-only plugin** owns nothing but the
 * structured data — it leaves meta and social alone, so only the schema module
 * needs to yield.
 *
 * Duplicate Product markup is the most common support ticket in this category,
 * and it comes from exactly the second group, which most plugins never think to
 * look for.
 *
 * Each module can be re-enabled individually from the settings screen or the
 * neoseo_module_enabled filter.
 */
class Detector {

	/**
	 * Modules that overlap with full SEO plugins and default to off
	 * when one is detected.
	 *
	 * @var string[]
	 */
	private const OVERLAPPING_MODULES = array( 'schema', 'meta', 'social' );

	/**
	 * Cached detection result.
	 *
	 * @var string|null|false False until computed; null when none found.
	 */
	private $seo_plugin = false;

	/**
	 * Cached schema-only plugin detection result.
	 *
	 * @var string|null|false False until computed; null when none found.
	 */
	private $schema_plugin = false;

	/**
	 * Returns the slug of the detected SEO plugin, or null.
	 *
	 * @return string|null
	 */
	public function seo_plugin(): ?string {
		if ( false !== $this->seo_plugin ) {
			return $this->seo_plugin;
		}

		$checks = array(
			'yoast'         => defined( 'WPSEO_VERSION' ),
			'rank-math'     => defined( 'RANK_MATH_VERSION' ),
			'aioseo'        => defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ),
			'seopress'      => defined( 'SEOPRESS_VERSION' ),
			'slim-seo'      => defined( 'SLIM_SEO_VER' ) || class_exists( 'SlimSEO\\Plugin' ),
			'seo-framework' => defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' ),
			'squirrly'      => defined( 'SQ_VERSION' ),
		);

		$this->seo_plugin = null;

		foreach ( $checks as $slug => $active ) {
			if ( $active ) {
				$this->seo_plugin = $slug;
				break;
			}
		}

		return $this->seo_plugin;
	}

	/**
	 * Whether a full SEO plugin is active.
	 *
	 * @return bool
	 */
	public function seo_plugin_active(): bool {
		return null !== $this->seo_plugin();
	}

	/**
	 * Returns the slug of a detected schema-only plugin, or null.
	 *
	 * These write structured data and nothing else, so they collide with the
	 * schema module alone — the meta and social modules can carry on.
	 *
	 * @return string|null
	 */
	public function schema_plugin(): ?string {
		if ( false !== $this->schema_plugin ) {
			return $this->schema_plugin;
		}

		$checks = array(
			'schema-and-structured-data-for-wp' => defined( 'SASWP_VERSION' ) || function_exists( 'saswp_schema_markup_output' ),
			'schema-pro'                        => defined( 'BSF_AIOSRS_PRO_VER' ),
			'schema'                            => defined( 'SCHEMAWP_VERSION' ) || function_exists( 'schema_wp_get_schema' ),
			'slim-seo-schema'                   => defined( 'SLIM_SEO_SCHEMA_VER' ),
			'wp-seo-structured-data-schema'     => defined( 'WPSEO_SCHEMA_VERSION' ) || class_exists( 'WP_SEO_Structured_Data_Schema' ),
		);

		$this->schema_plugin = null;

		foreach ( $checks as $slug => $active ) {
			if ( $active ) {
				$this->schema_plugin = $slug;
				break;
			}
		}

		return $this->schema_plugin;
	}

	/**
	 * Whether anything else on the site is writing structured data.
	 *
	 * @return bool
	 */
	public function schema_conflict(): bool {
		return $this->seo_plugin_active() || null !== $this->schema_plugin();
	}

	/**
	 * Whether a NeoSEO module should run.
	 *
	 * @param string $module Module slug: schema, meta, social, llms.
	 * @return bool
	 */
	public function module_enabled( string $module ): bool {
		$settings = get_option( 'neoseo_modules', array() );

		if ( isset( $settings[ $module ] ) ) {
			$enabled = (bool) $settings[ $module ];
		} elseif ( 'schema' === $module ) {
			// The schema module yields to anything writing structured data,
			// including a schema-only plugin that leaves meta and social alone.
			$enabled = ! $this->schema_conflict();
		} else {
			// Other overlapping modules yield only to a full SEO plugin;
			// llms.txt and variant markup are additive and stay on.
			$enabled = ! ( in_array( $module, self::OVERLAPPING_MODULES, true ) && $this->seo_plugin_active() );
		}

		/**
		 * Filters whether a NeoSEO module runs.
		 *
		 * @since 1.0.0
		 *
		 * @param bool        $enabled    Whether the module runs.
		 * @param string      $module     Module slug.
		 * @param string|null $seo_plugin Detected SEO plugin slug, or null.
		 */
		return (bool) apply_filters( 'neoseo_module_enabled', $enabled, $module, $this->seo_plugin() );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
