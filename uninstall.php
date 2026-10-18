<?php
/**
 * Cleans up NeoSEO data on uninstall.
 *
 * @package NeoSEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'neoseo_organization' );
delete_option( 'neoseo_modules' );
delete_option( 'neoseo_returns' );
delete_option( 'neoseo_attribute_map' );
delete_transient( 'neoseo_llms_txt' );
delete_transient( 'neoseo_faq_coverage' );

// Author-written descriptions, stored per post.
delete_post_meta_by_key( '_neoseo_description' );
