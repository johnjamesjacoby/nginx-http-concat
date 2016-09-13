<?php

/*
 * Concatenation script inspired by nginx's ngx_http_concat and Apache's
 * modconcat modules.
 *
 * It follows the same pattern for enabling the concatenation. Like this:
 * http://example.com/??style1.css,style2.css,foo/style3.css
 *
 * If a third ? is present it's treated as version string. Like this:
 * http://example.com/??style1.css,style2.css,foo/style3.css?v=102234
 *
 * It will also replace the relative paths in CSS files with absolute paths.
 */

/** Config ********************************************************************/

$concat_max_files = 150;
$concat_unique    = true;
$concat_types     = array(
	'css' => 'text/css',
	'js'  => 'application/x-javascript'
);

/** Constants *****************************************************************/

$dir_name = dirname( __FILE__ );

if ( ! defined( 'CONCAT_CSSMIN_PATH' ) ) {
	define( 'CONCAT_CSSMIN_PATH', "{$dir_name}/wp-content/plugins/wp-enqueue-masher/wp-enqueue-masher/includes/cssmin.php" );
}

// Determine the document root from this scripts path in the plugins
// dir (you can hardcode this define)
if ( ! defined( 'CONCAT_FILES_ROOT' ) ) {
	define( 'CONCAT_FILES_ROOT', $dir_name );
}

/** Functions *****************************************************************/

/**
 * Send an HTTP header with an exit code
 *
 * @since 0.1.0
 *
 * @param string $status
 */
function concat_http_status_exit( $status = '' ) {
	switch ( $status ) {
		case 200:
			$text = 'OK';
			break;
		case 400:
			$text = 'Bad Request';
			break;
		case 403:
			$text = 'Forbidden';
			break;
		case 404:
			$text = 'Not found';
			break;
		case 500:
			$text = 'Internal Server Error';
			break;
		default:
			$text = '';
	}

	$protocol = $_SERVER['SERVER_PROTOCOL'];

	if ( ( 'HTTP/1.1' !== $protocol ) && ( 'HTTP/1.0' !== $protocol ) ) {
		$protocol = 'HTTP/1.0';
	}

	@header( "{$protocol} {$status} {$text}", true, $status );
	exit();
}

/**
 * Get the MIME type of a file
 *
 * @since 0.1.0
 *
 * @global array $concat_types
 *
 * @param  string  $file
 *
 * @return mixed
 */
function concat_get_mime_type( $file ) {
	global $concat_types;

	$lastdot_pos = strrpos( $file, '.' );
	if ( false === $lastdot_pos ) {
		return false;
	}

	$ext = substr( $file, $lastdot_pos + 1 );

	return isset( $concat_types[ $ext ] )
		? $concat_types[ $ext ]
		: false;
}

/**
 * Get the path to a URI
 *
 * @since 0.1.0
 *
 * @param  string $uri
 * @return string
 */
function concat_get_uri_path( $uri = '' ) {

	// Bail if empty URI
	if ( ! strlen( $uri ) ) {
		concat_http_status_exit( 400 );
	}

	// Bail if malformed or directory
	if ( ( false !== strpos( $uri, '..' ) ) || ( false !== strpos( $uri, "\0" ) ) ) {
		concat_http_status_exit( 400 );
	}

	// Is there a chunk in the middle?
	$chunk = ( '/' !== $uri[0] ) ? '/' : '';

	// Return path
	return CONCAT_FILES_ROOT . $chunk . $uri;
}

/** Identify ******************************************************************/

// Main()
if ( ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
	concat_http_status_exit( 400 );
}

// /s/??/foo/bar.css,/foo1/bar/baz.css?m=293847g
// or
// /s/??-eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
$args = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
if ( empty( $args ) || false === strpos( $args, '?' ) ) {
	concat_http_status_exit( 400 );
}

$args = substr( $args, strpos( $args, '?' ) + 1 );

// /foo/bar.css,/foo1/bar/baz.css?m=293847g
// or
// -eJzTT8vP109KLNJLLi7W0QdyDEE8IK4CiVjn2hpZGluYmKcDABRMDPM=
if ( '-' === $args[0] ) {
	$args = gzuncompress( base64_decode( substr( $args, 1 ) ) );
}

// /foo/bar.css,/foo1/bar/baz.css?m=293847g
$version_string_pos = strpos( $args, '?' );
if ( false !== $version_string_pos ) {
	$args = substr( $args, 0, $version_string_pos );
}

// /foo/bar.css,/foo1/bar/baz.css
$args = explode( ',', $args );
if ( empty( $args ) ) {
	concat_http_status_exit( 400 );
}

// array( '/foo/bar.css', '/foo1/bar/baz.css' )
if ( 0 == count( $args ) || count( $args ) > $concat_max_files ) {
	concat_http_status_exit( 400 );
}

/** Mash! *********************************************************************/

// Require the CSSmin class
require CONCAT_CSSMIN_PATH;

// Setup variables
$last_modified = 0;
$pre_output    = '';
$output        = '';
$css_minify    = new CSSmin();

// Loop through arguments & start concatenating
foreach ( $args as $uri ) {

	// WordPress is in a subdirectory
	//$uri = str_replace( 'wp-includes/', 'wordpress/wp-includes/', $uri );
	//$uri = str_replace( 'wp-admin/',    'wordpress/wp-admin/',    $uri );

	// Get the fullpath to
	$fullpath = concat_get_uri_path( $uri );

	// 404 if file does not exist
	if ( ! file_exists( $fullpath ) ) {
		concat_http_status_exit( 404 );
	}

	// 400 if unsuported MIME type
	$mime_type = concat_get_mime_type( $fullpath );
	if ( ! in_array( $mime_type, $concat_types, true ) ) {
		concat_http_status_exit( 400 );
	}

	// Unique requests for each
	if ( true === $concat_unique ) {
		if ( ! isset( $last_mime_type ) ) {
			$last_mime_type = $mime_type;
		}

		// 400 if MIME mismatch
		if ( $last_mime_type !== $mime_type ) {
			concat_http_status_exit( 400 );
		}
	}

	// 500 if file not reachable
	$stat = stat( $fullpath );
	if ( false === $stat ) {
		concat_http_status_exit( 500 );
	}

	// Update last modified time
	if ( $stat['mtime'] > $last_modified ) {
		$last_modified = $stat['mtime'];
	}

	// Attempt to get contents of concatenated file
	$buf = file_get_contents( $fullpath );

	// 500 if file not reachable
	if ( false === $buf ) {
		concat_http_status_exit( 500 );
	}

	// Mash a CSS file
	if ( 'text/css' === $mime_type ) {
		$dirpath = dirname( $uri );

		// url(relative/path/to/file) -> url(/absolute/and/not/relative/path/to/file)
		$buf = preg_replace(
			'/(:?\s*url\s*\()\s*(?:\'|")?\s*([^\/\'"\s\)](?:(?<!data:|http:|https:).)*)[\'"\s]*\)/isU',
			'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
			$buf
		);

		// AlphaImageLoader(...src='relative/path/to/file'...) -> AlphaImageLoader(...src='/absolute/path/to/file'...)
		$buf = preg_replace(
			'/(Microsoft.AlphaImageLoader\s*\([^\)]*src=(?:\'|")?)([^\/\'"\s\)](?:(?<!http:|https:).)*)\)/isU',
			'$1' . ( $dirpath == '/' ? '/' : $dirpath . '/' ) . '$2)',
			$buf
		);

		// The @charset rules must be on top of the output
		if ( 0 === strpos( $buf, '@charset' ) ) {
			preg_replace_callback(
				'/(?P<charset_rule>@charset\s+[\'"][^\'"]+[\'"];)/i',
				function ( $match ) {
					global $pre_output;

					if ( 0 === strpos( $pre_output, '@charset' ) ) {
						return '';
					}

					$pre_output = $match[0] . "\n" . $pre_output;

					return '';
				},
				$buf
			);
		}

		// Move the @import rules on top of the concatenated output.
		// Only @charset rule are allowed before them.
		if ( false !== strpos( $buf, '@import' ) ) {
			$buf = preg_replace_callback(
				'/(?P<pre_path>@import\s+(?:url\s*\()?[\'"\s]*)(?P<path>[^\'"\s](?:https?:\/\/.+\/?)?.+?)(?P<post_path>[\'"\s\)]*;)/i',
				function ( $match ) use ( $dirpath ) {
					global $pre_output;

					if ( 0 !== strpos( $match['path'], 'http' ) && ( '/' !== $match['path'][0] ) ) {
						$pre_output .=
							$match['pre_path']
							. ( '/' !== $dirpath ? '/' : $dirpath . '/' )
							. $match['path'] . $match['post_path'] . "\n";
					} else {
						$pre_output .= $match[0] . "\n";
					}

					return '';
				},
				$buf
			);
		}

		$buf = $css_minify->run( $buf );
	}

	// Mash a JS file
	if ( 'application/x-javascript' === $mime_type ) {
		$output .= "{$buf};\n";
	} else {
		$output .= "{$buf}";
	}
}

/** Headers *******************************************************************/

header( 'Last-Modified: '  . gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT' );
header( 'Content-Length: ' . strlen( $pre_output ) + strlen( $output ) );
header( 'Content-Type: '   . $mime_type );

/** Output ********************************************************************/

echo $pre_output . $output;
