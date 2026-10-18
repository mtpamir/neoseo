<?php
/**
 * Plugin Name: NeoSEO
 * Plugin URI: https://wordpress.org/plugins/neoseo/
 * Description: Gives Google the product data it needs to show your variants, prices, shipping and returns — ProductGroup variant markup, shipping details from your real zones, a connected JSON-LD graph and llms.txt. Stands aside automatically when a full SEO plugin is active.
 * Version: 1.0.0
 * Author: mtpamir
 * Author URI: https://profiles.wordpress.org/mtpamir/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: neoseo
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * WC tested up to: 11.1
 *
 * @package NeoSEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEOSEO_VERSION', '1.0.0' );
define( 'NEOSEO_FILE', __FILE__ );
define( 'NEOSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEOSEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 autoloader for the NeoSEO\ namespace (src/).
 *
 * The plugin ships no composer vendor directory, so the autoloader is local.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function neoseo_autoload( string $class_name ): void {
	if ( strpos( $class_name, 'NeoSEO\\' ) !== 0 ) {
		return;
	}

	$relative = substr( $class_name, strlen( 'NeoSEO\\' ) );
	$path     = NEOSEO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require $path;
	}
}
spl_autoload_register( 'neoseo_autoload' );

/**
 * Declares compatibility with WooCommerce features.
 *
 * NeoSEO only reads product and comment data — it never touches order
 * storage and adds nothing to the checkout — so it is compatible with
 * both HPOS (custom order tables) and the Cart/Checkout blocks.
 *
 * @return void
 */
function neoseo_declare_wc_compatibility(): void {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEOSEO_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NEOSEO_FILE, true );
}
add_action( 'before_woocommerce_init', 'neoseo_declare_wc_compatibility' );

/**
 * Boots the plugin once all plugins are loaded, so SEO-plugin detection
 * and the WooCommerce checks see the real environment.
 *
 * @return void
 */
function neoseo_init(): void {
	\NeoSEO\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'neoseo_init' );

/**
 * Registers the llms.txt rewrite and flushes rules on activation.
 *
 * @return void
 */
function neoseo_activate(): void {
	\NeoSEO\Aeo\LlmsTxt::register_rewrite();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'neoseo_activate' );

/**
 * Drops the stored rewrite rules on deactivation so /llms.txt stops resolving.
 *
 * Flushing here is not enough: the rule was already added on `init` in this
 * same request, so a flush would write it straight back. Deleting the option
 * makes WordPress rebuild the rules on the next request, when NeoSEO is no
 * longer loaded.
 *
 * @return void
 */
function neoseo_deactivate(): void {
	delete_option( 'rewrite_rules' );
	delete_transient( 'neoseo_llms_txt' );
}
register_deactivation_hook( __FILE__, 'neoseo_deactivate' );
