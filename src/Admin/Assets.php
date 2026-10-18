<?php
/**
 * Admin assets.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the admin stylesheet and the colour-scheme custom properties.
 *
 * Matches the NeoPlugin family shell: the --neoplugin-* properties follow the
 * current user's WordPress admin colour scheme so the screen never clashes
 * with the surrounding admin.
 */
class Assets {

	/**
	 * Stylesheet handle.
	 */
	private const HANDLE = 'neoseo-admin';

	/**
	 * Accent colours per built-in admin colour scheme.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SCHEME_MAP = array(
		'fresh'     => array(
			'accent' => '#2271b1',
			'hover'  => '#135e96',
		),
		'modern'    => array(
			'accent' => '#3858e9',
			'hover'  => '#183ad6',
		),
		'light'     => array(
			'accent' => '#04a4cc',
			'hover'  => '#0380a0',
		),
		'blue'      => array(
			'accent' => '#096484',
			'hover'  => '#06455b',
		),
		'coffee'    => array(
			'accent' => '#c7a589',
			'hover'  => '#b78b68',
		),
		'ectoplasm' => array(
			'accent' => '#a3b745',
			'hover'  => '#89962c',
		),
		'midnight'  => array(
			'accent' => '#e14d43',
			'hover'  => '#dd382d',
		),
		'ocean'     => array(
			'accent' => '#9ebaa0',
			'hover'  => '#87a68a',
		),
		'sunrise'   => array(
			'accent' => '#dd823b',
			'hover'  => '#d97426',
		),
	);

	/**
	 * Hooks the enqueue.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the stylesheet on the NeoSEO screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		// The NeoSEO screen, plus the editors where the description box lives.
		$screens = array( 'toplevel_page_neoseo', 'post.php', 'post-new.php' );

		if ( ! in_array( $hook_suffix, $screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			NEOSEO_URL . 'assets/css/admin.css',
			array(),
			NEOSEO_VERSION
		);

		wp_add_inline_style( self::HANDLE, $this->color_scheme_css() );
	}

	/**
	 * Builds the custom-property block for the active admin colour scheme.
	 *
	 * @return string CSS.
	 */
	private function color_scheme_css(): string {
		$colors = $this->scheme_colors();

		return sprintf(
			':root{--neoplugin-accent-color:%1$s;--neoplugin-accent-color-hover:%2$s;}',
			esc_attr( $colors['accent'] ),
			esc_attr( $colors['hover'] )
		);
	}

	/**
	 * Resolves the colours for the current user's admin colour scheme.
	 *
	 * @return array<string, string>
	 */
	private function scheme_colors(): array {
		$scheme = (string) get_user_option( 'admin_color', get_current_user_id() );

		if ( '' === $scheme ) {
			$scheme = 'fresh';
		}

		if ( isset( self::SCHEME_MAP[ $scheme ] ) ) {
			return self::SCHEME_MAP[ $scheme ];
		}

		// Third-party scheme: fall back to whatever it registered.
		global $_wp_admin_css_colors;

		$registered = array();

		if ( isset( $_wp_admin_css_colors[ $scheme ] ) && ! empty( $_wp_admin_css_colors[ $scheme ]->colors ) ) {
			$registered = (array) $_wp_admin_css_colors[ $scheme ]->colors;
		}

		return array(
			'accent' => $registered[2] ?? self::SCHEME_MAP['fresh']['accent'],
			'hover'  => $registered[3] ?? self::SCHEME_MAP['fresh']['hover'],
		);
	}
}
