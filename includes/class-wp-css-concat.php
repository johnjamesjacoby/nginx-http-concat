<?php

class WP_CSS_Concat extends WP_Styles {

	private $old_styles;
	public $allow_gzip_compression;

	public function __construct( $styles = '' ) {

		$this->old_styles = ( empty( $styles ) || ! ( $styles instanceof WP_Styles ) )
			? new WP_Styles()
			: $styles;

		// Unset all the object properties except our private copy of the
		// styles object. We have to unset everything so that the overload
		// methods talk to $this->old_styles->whatever instead of $this->whatever.
		foreach ( array_keys( get_object_vars( $this ) ) as $key ) {
			if ( 'old_styles' === $key ) {
				continue;
			}
			unset( $this->$key );
		}

		parent::__construct();
	}

	public function do_items( $handles = false, $group = false ) {

		// Setup some variables
		$index       = 0;
		$stylesheets = array();
		$siteurl     = site_url();
		$handles     = ( false === $handles )
			? $this->queue
			: (array) $handles;

		// Load all dependencies
		$this->all_deps( $handles );

		// Loop through dependencies
		foreach ( $this->to_do as $key => $handle ) {

			$obj      = $this->registered[ $handle ];
			$obj->src = apply_filters( 'style_loader_src', $obj->src, $obj->handle );

			// Core is kind of broken and returns "true" for src of "colors" handle
			// http://core.trac.wordpress.org/attachment/ticket/16827/colors-hacked-fixed.diff
			// http://core.trac.wordpress.org/ticket/20729
			if ( 'colors' === $obj->handle && true === $obj->src ) {
				$css_url = parse_url( wp_style_loader_src( $obj->src, $obj->handle ) );
			} else {
				$css_url = parse_url( $obj->src );
			}

			$extra = $obj->extra;

			// Concat by default
			$do_concat = true;

			// Only try to concat static css files
			if ( false === strpos( $css_url['path'], '.css' ) ) {
				$do_concat = false;
			}

			// Don't try to concat styles which are loaded conditionally (like IE stuff)
			if ( isset( $extra['conditional'] ) ) {
				$do_concat = false;
			}

			// Don't concat rtl stuff for now until concat supports it correctly
			if ( 'rtl' === $this->text_direction && ! empty( $extra['rtl'] ) ) {
				$do_concat = false;
			}

			// Don't try to concat externally hosted scripts
			if ( ( isset( $css_url['host'] ) && ( preg_replace( '/https?:\/\//', '', $siteurl ) !== $css_url['host'] ) ) ) {
				$do_concat = false;
			}

			// Concat and canonicalize the paths only for
			// existing scripts that aren't outside ROOT_DIR
			$css_realpath = realpath( ROOT_DIR . $css_url['path'] );
			if ( empty( $css_realpath ) || 0 !== strpos( $css_realpath, ROOT_DIR ) ) {
				$do_concat = false;
			} else {
				$css_url['path'] = substr( $css_realpath, strlen( ROOT_DIR ) - 1 );
			}

			// Allow plugins to disable concatenation of certain stylesheets.
			$do_concat = apply_filters( 'css_do_concat', $do_concat, $handle );

			if ( true === $do_concat ) {
				$media = $obj->args;
				if ( empty( $media ) ) {
					$media = 'all';
				}

				if ( ! isset( $stylesheets[ $index ] ) || ( isset( $stylesheets[ $index ] ) && ! is_array( $stylesheets[ $index ] ) ) ) {
					$stylesheets[ $index ] = array();
				}

				$stylesheets[ $index ][ $media ][ $handle ] = $css_url['path'];
				$this->done[] = $handle;
			} else {
				$index++;
				$stylesheets[ $index ][ 'noconcat' ][] = $handle;
				$index++;
			}
			unset( $this->to_do[ $key ] );
		}

		foreach ( $stylesheets as $idx => $stylesheets_group ) {
			foreach ( $stylesheets_group as $media => $css ) {
				if ( 'noconcat' === $media ) {
					foreach ( $css as $handle ) {
						if ( $this->do_item( $handle, $group ) ) {
							$this->done[] = $handle;
						}
					}
					continue;
				} elseif ( count( $css ) > 1 ) {
					$paths = array_map( function( $url ) {
						return ROOT_DIR . $url;
					}, $css );

					$mtime    = max( array_map( 'filemtime', $paths ) );
					$path_str = implode( $css, ',' ) . "?m={$mtime}";

					if ( true === $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) ) {
							$path_str = '-' . $path_64;
						}
					}

					$href = $siteurl . '/' . MASHER_SLUG . '/??' . $path_str;
				} else {
					$href = $this->cache_bust_mtime( $siteurl . current( $css ) );
				}

				echo apply_filters( 'style_loader_tag', "<link rel='stylesheet' id='{$media}-css-{$idx}' href='{$href}' type='text/css' media='{$media}' />\n", $handle );

				array_map( array( $this, 'print_inline_style' ), array_keys( $css ) );
			}
		}
		return $this->done;
	}

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

	public function __isset( $key = '' ) {
		return isset( $this->old_styles->{$key} );
	}

	public function __unset( $key = '' ) {
		unset( $this->old_styles->{$key} );
	}

	public function &__get( $key = '' ) {
		return $this->old_styles->{$key};
	}

	public function __set( $key, $value ) {
		$this->old_styles->{$key} = $value;
	}
}
