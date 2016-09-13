<?php

/**
 * Plugin Name: WP Enqueue Masher
 * Plugin URI:  https://wordpress.org/plugins/wp-enqueue-masher/
 * Author:      John James Jacoby
 * Author URI:  https://jjj.me/
 * Version:     0.1.0
 * Description: Minify & concatenate enqueued scripts & styles
 * License:     GPL v2 or later
 */

/**
 * This plugin is based on Automattic's nginx-http-concat plugin.
 *
 * https://github.com/Automattic/nginx-http-concat/
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Allow Gzip compression by default
if ( ! defined( 'ALLOW_GZIP_COMPRESSION' ) ) {
	define( 'ALLOW_GZIP_COMPRESSION', true );
}

// Allow custom slug (changing this requires nginx restart)
if ( ! defined( 'MASHER_SLUG' ) ) {
	define( 'MASHER_SLUG', 's' );
}

// Allow custom slug (changing this requires nginx restart)
if ( ! defined( 'ROOT_DIR' ) ) {
	define( 'ROOT_DIR', ABSPATH );
}

/**
 * Load the masher
 *
 * @since 0.1.0
 *
 * @global  WP_CSS_Concat  $wp_styles
 * @global  WP_JS_Concat   $wp_scripts
 */
function _wp_enqueue_masher() {
	global $wp_styles, $wp_scripts;

	// Get include path
	$path = plugin_dir_path( __FILE__ ) . 'wp-enqueue-masher/';

	// Include files
	require_once $path . 'includes/class-wp-css-concat.php';
	require_once $path . 'includes/class-wp-js-concat.php';

	// Styles
	$wp_styles = new WP_CSS_Concat( $wp_styles );
	$wp_styles->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;

	// Scripts
	$wp_scripts = new WP_JS_Concat( $wp_scripts );
	$wp_scripts->allow_gzip_compression = ALLOW_GZIP_COMPRESSION;
}
add_action( 'plugins_loaded', '_wp_enqueue_masher', -PHP_INT_MAX );
