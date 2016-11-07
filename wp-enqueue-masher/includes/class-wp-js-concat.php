<?php

/**
 * Enqueue Masher JS Concatenation
 *
 * This class extends WordPress's WP_Scripts class, and replaces a few methods
 * to better help with combining scripts together.
 *
 * @package Plugins/Masher/Classes/CSS
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class WP_JS_Concat extends WP_Scripts {

	/**
	 * Original global instance of WP_Scripts
	 *
	 * @since 0.1.0
	 *
	 * @var WP_Scripts
	 */
	private $old_scripts = array();

	/**
	 * Whether to use GZip compression on end result
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public $allow_gzip_compression = true;

	/**
	 * Class constructor, used to juggle the globally enqueued scripts
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Scripts $scripts
	 */
	public function __construct( $scripts = '' ) {

		$this->old_scripts = ( empty( $scripts ) || ! ( $scripts instanceof WP_Scripts ) )
			? new WP_Scripts()
			: $scripts;

		// Unset all the object properties except our private copy of the
		// scripts object. We have to unset everything so that the overload
		// methods talk to $this->old_scripts->whatever instead of $this->whatever.
		foreach ( array_keys( get_object_vars( $this ) ) as $key ) {
			if ( 'old_scripts' === $key ) {
				continue;
			}
			unset( $this->$key );
		}

		parent::__construct();
	}

	/**
	 * Process all of the enqueued scripts
	 *
	 * @since 0.1.0
	 *
	 * @param array $handles
	 * @param string $group
	 *
	 * @return array
	 */
	public function do_items( $handles = false, $group = false ) {

		// Setup some variables
		$index       = 0;
		$javascripts = array();
		$siteurl     = site_url();
		$handles     = ( false === $handles )
			? $this->queue
			: (array) $handles;

		// Load all dependencies
		$this->all_deps( $handles );

		// Loop through dependencies
		foreach ( $this->to_do as $key => $handle ) {

			// Defines a group.
			if ( ! $this->registered[ $handle ]->src ) {
				$this->done[] = $handle;
				continue;
			}

			if ( ( 0 === $group ) && $this->groups[ $handle ] > 0 ) {
				$this->in_footer[] = $handle;
				unset( $this->to_do[ $key ] );
				continue;
			}

			if ( ( false === $group ) && in_array( $handle, $this->in_footer, true ) ) {
				$this->in_footer = array_diff( $this->in_footer, (array) $handle );
			}

			$obj    = $this->registered[ $handle ];
			$js_url = parse_url( $obj->src );

			// Concat by default
			$do_concat = true;

			// Only try to concat static js files
			if ( false === strpos( $js_url['path'], '.js' ) ) {
				$do_concat = false;
			}

			// Don't try to concat externally hosted scripts
			if ( ( isset( $js_url['host'] ) && ( preg_replace( '/https?:\/\//', '', $siteurl ) != $js_url['host'] ) ) ) {
				$do_concat = false;
			}

			// Concat and canonicalize the paths only for
			// existing scripts that aren't outside ROOT_DIR
			$js_realpath = realpath( ROOT_DIR . $js_url['path'] );
			if ( empty( $js_realpath ) || 0 !== strpos( $js_realpath, ROOT_DIR ) ) {
				$do_concat = false;
			} else {
				$js_url['path'] = str_replace( ROOT_DIR, '', $js_realpath );
			}

			// Allow plugins to disable concatenation of certain scripts.
			$do_concat = apply_filters( 'js_do_concat', $do_concat, $handle );

			if ( true === $do_concat ) {
				if ( ! isset( $javascripts[ $index ] ) ) {
					$javascripts[ $index ]['type'] = 'concat';
				}

				$javascripts[ $index ]['paths'][]   = $js_url['path'];
				$javascripts[ $index ]['handles'][] = $handle;
			} else {
				$index++;
				$javascripts[ $index ]['type']   = 'do_item';
				$javascripts[ $index ]['handle'] = $handle;
				$index++;
			}
			unset( $this->to_do[ $key ] );
		}

		if ( empty( $javascripts ) ) {
			return $this->done;
		}

		foreach ( $javascripts as $js_array ) {
			if ( 'do_item' === $js_array['type'] ) {
				if ( $this->do_item( $js_array['handle'], $group ) ) {
					$this->done[] = $js_array['handle'];
				}
			} else if ( 'concat' === $js_array['type'] ) {
				array_map( array( $this, 'print_extra_script' ), $js_array['handles'] );

				if ( count( $js_array['paths'] ) > 1) {
					$paths = array_map( function( $url ) {
						return ROOT_DIR . $url;
					}, $js_array['paths'] );

					$mtime    = max( array_map( 'filemtime', $paths ) );
					$path_str = implode( $js_array['paths'], ',' ) . "?m={$mtime}";

					if ( true === $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) ) {
							$path_str = '-' . $path_64;
						}
					}

					$href = $siteurl . '/' . MASHER_SLUG . '/??' . $path_str;
				} else {
					$href = $this->cache_bust_mtime( $siteurl . '/' . ltrim( $js_array['paths'][0], '/' ) );
				}

				$this->done = array_merge( $this->done, $js_array['handles'] );
				echo "<script type='text/javascript' src='{$href}'></script>\n";
			}
		}

		return $this->done;
	}

	/**
	 * Determine the time when the cache for this request was last changed
	 *
	 * @since 0.1.0
	 *
	 * @param string $url
	 * @return string
	 */
	public function cache_bust_mtime( $url ) {

		// Bail if no modified time
		if ( strpos( $url, '?m=' ) ) {
			return $url;
		}

		// Get parts, bail if no path
		$parts = parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}

		// Put file together
		$file = ROOT_DIR . ltrim( $parts['path'], '/' );
		$mtime = false;

		// Get modified time if file exists
		if ( file_exists( $file ) ) {
			$mtime = filemtime( $file );
		}

		// Bail if no modified time
		if ( empty( $mtime ) ) {
			return $url;
		}

		// No version at end of URL
		if ( false === strpos( $url, '?' ) ) {
			$q = '';

		// Attempt to use version at end of URL
		} else {
			list( $url, $q ) = explode( '?', $url, 2 );
			if ( strlen( $q ) ) {
				$q = '&amp;' . $q;
			}
		}

		return "{$url}?m={$mtime}{$q}";
	}

	/**
	 * Magic issetter for checking if variables are set
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 * @return bool
	 */
	public function __isset( $key = '' ) {
		return isset( $this->old_scripts->{$key} );
	}

	/**
	 * Magic unsetter for nullifying an object variable
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 */
	public function __unset( $key = '' ) {
		unset( $this->old_scripts->{$key} );
	}

	/**
	 * Magic byref getter for object variables
	 *
	 * Must be byref, or the sky will fall on your head.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function &__get( $key = '' ) {
		return $this->old_scripts->{$key};
	}

	/**
	 * Magic setter for setting object variables
	 *
	 * @since 0.1.0
	 *
	 * @param string $key
	 * @param mixed $value
	 */
	public function __set( $key, $value ) {
		$this->old_scripts->{$key} = $value;
	}
}
