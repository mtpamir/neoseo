<?php
/**
 * Plugin bootstrap.
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

namespace NeoSEO;

use NeoSEO\Admin\PostMeta;
use NeoSEO\Admin\Settings;
use NeoSEO\Aeo\LlmsTxt;
use NeoSEO\Aeo\MetaTags;
use NeoSEO\Compat\Detector;
use NeoSEO\Schema\GraphManager;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's modules together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * SEO-plugin detector shared by all modules.
	 *
	 * @var Detector
	 */
	private $detector;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots every module. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->detector = new Detector();

		( new GraphManager( $this->detector ) )->register();
		( new MetaTags( $this->detector ) )->register();
		( new LlmsTxt( $this->detector ) )->register();

		if ( is_admin() ) {
			( new Settings( $this->detector ) )->register();
			( new PostMeta( $this->detector ) )->register();
		}
	}

	/**
	 * Returns the shared detector.
	 *
	 * @return Detector
	 */
	public function detector(): Detector {
		return $this->detector;
	}
}
