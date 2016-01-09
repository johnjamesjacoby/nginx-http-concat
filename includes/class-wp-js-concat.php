<?php

class WP_JS_Concat extends WP_Scripts {

	private $old_scripts;
	public $allow_gzip_compression;

	public function __construct( $scripts ) {

		// Setup old scripts
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

	public function do_items( $handles = false, $group = false ) {
		$level       = 0;
		$javascripts = array();
		$siteurl     = site_url();
		$handles     = ( false === $handles )
			? $this->queue
			: (array) $handles;

		$this->all_deps( $handles );

		foreach( $this->to_do as $key => $handle ) {
			if ( in_array( $handle, $this->done ) || !isset( $this->registered[$handle] ) ) {
				continue;
			}

			// Defines a group.
			if ( ! $this->registered[$handle]->src ) {
				$this->done[] = $handle;
				continue;
			}

			if ( 0 === $group && $this->groups[$handle] > 0 ) {
				$this->in_footer[] = $handle;
				unset( $this->to_do[$key] );
				continue;
			}

			if ( false === $group && in_array( $handle, $this->in_footer, true ) ) {
				$this->in_footer = array_diff( $this->in_footer, (array) $handle );
			}

			$obj    = $this->registered[ $handle ];
			$js_url = parse_url( $obj->src );

			// Don't concat by default
			$do_concat = false;

			// Only try to concat static js files
			if ( false !== strpos( $js_url['path'], '.js' ) ) {
				$do_concat = true;
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
				$js_url['path'] = substr( $js_realpath, strlen( ROOT_DIR ) - 1 );
			}

			if ( true === $do_concat ) {
				if ( ! isset( $javascripts[ $level ] ) ) {
					$javascripts[ $level ]['type'] = 'concat';
				}

				$javascripts[ $level ]['paths'][]   = $js_url['path'];
				$javascripts[ $level ]['handles'][] = $handle;
			} else {
				$level++;
				$javascripts[ $level ]['type']   = 'do_item';
				$javascripts[ $level ]['handle'] = $handle;
				$level++;
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
					$paths = array_map( function( $url ) { return ROOT_DIR . $url; }, $js_array['paths'] );
					$mtime = max( array_map( 'filemtime', $paths ) );
					$path_str = implode( $js_array['paths'], ',' ) . "?m=${mtime}j";

					if ( $this->allow_gzip_compression ) {
						$path_64 = base64_encode( gzcompress( $path_str ) );
						if ( strlen( $path_str ) > ( strlen( $path_64 ) + 1 ) ) {
							$path_str = '-' . $path_64;
						}
					}

					$href = $siteurl . '/' . MASHER_SLUG . '/??' . $path_str;
				} else {
					$href = $this->cache_bust_mtime( $siteurl . $js_array['paths'][0] );
				}

				$this->done = array_merge( $this->done, $js_array['handles'] );
				echo "<script type='text/javascript' src='$href'></script>\n";
			}
		}

		return $this->done;
	}

	public function cache_bust_mtime( $url ) {
		if ( strpos( $url, '?m=' ) ) {
			return $url;
		}

		$parts = parse_url( $url );
		if ( ! isset( $parts['path'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$file = ROOT_DIR . ltrim( $parts['path'], '/' );

		$mtime = false;
		if ( file_exists( $file ) ) {
			$mtime = filemtime( $file );
		}

		if ( empty( $mtime ) ) {
			return $url;
		}

		if ( false === strpos( $url, '?' ) ) {
			$q = '';
		} else {
			list( $url, $q ) = explode( '?', $url, 2 );
			if ( strlen( $q ) ) {
				$q = '&amp;' . $q;
			}
		}

		return "$url?m={$mtime}g{$q}";
	}

	public function __isset( $key ) {
		return isset( $this->old_scripts->$key );
	}

	public function __unset( $key ) {
		unset( $this->old_scripts->$key );
	}

	public function &__get( $key ) {
		return $this->old_scripts->$key;
	}

	public function __set( $key, $value ) {
		$this->old_scripts->$key = $value;
	}
}
