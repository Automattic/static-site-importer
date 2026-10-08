<?php
/**
 * Client script trust policy for imported website artifacts.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Client_Script_Policy_Report' ) ) {
	require_once __DIR__ . '/class-static-site-importer-client-script-policy-report.php';
}

/** Applies an explicit, provenance-bound client-script policy before compilation. */
class Static_Site_Importer_Client_Script_Policy {
	/**
	 * Make executable client code inert unless an isolated preview explicitly opts in.
	 *
	 * @return array{artifact:array<string,mixed>,report:array<string,mixed>}
	 */
	public static function apply( array $artifact, array $args ): array {
		$policy     = self::policy_name( $args );
		$provenance = self::provenance( $args );
		$preserve   = 'isolated_preview' === $policy && ! empty( $args['client_script_isolated'] ) && '' !== $provenance;
		$report     = new Static_Site_Importer_Client_Script_Policy_Report(
			$preserve ? 'isolated_preview' : 'inert',
			'untrusted_imported_code',
			$preserve ? $provenance : ''
		);
		$files      = isset( $artifact['files'] ) && is_array( $artifact['files'] ) ? $artifact['files'] : array();
		$filtered   = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
			if ( self::is_script_file( $file ) ) {
				self::record( $report, $preserve ? 'preserved' : 'dropped', self::file_row( $path, $file ) );
				if ( ! $preserve ) {
					continue;
				}
			}
			if ( self::is_html_file( $file ) ) {
				$structured_data = self::structured_data( self::file_content( $file ) );
				if ( ! empty( $structured_data ) ) {
					$file['metadata']                    = is_array( $file['metadata'] ?? null ) ? $file['metadata'] : array();
					$file['metadata']['structured_data'] = $structured_data;
				}
				$content = self::filter_html( self::file_content( $file ), $path, $preserve, $report );
				if ( ! $preserve ) {
					$file = self::with_file_content( $file, $content );
				}
			}
			$filtered[] = $file;
		}

		$artifact['files'] = $filtered;
		return array(
			'artifact' => $artifact,
			'report'   => $report->to_array(),
		);
	}

	/**
	 * Drop the client scripts a compiled plan cannot prove, as typed losses.
	 *
	 * The producer marks a plan `not_proven` when a local document script builds
	 * asset URLs at runtime (dynamic imports, script injection, runtime URL
	 * construction) and therefore cannot be rewritten onto the theme's asset
	 * surface. Those scripts are removed from the artifact, their `<script>`
	 * tags and script preloads are stripped, and each unproven reference is
	 * returned as a `unproven_dynamic` row so the import receipt can report the
	 * loss instead of failing materialization. Recompiling the filtered
	 * artifact yields a proven plan; proven scripts and every non-script file
	 * are untouched.
	 *
	 * @param array<string,mixed> $artifact Artifact about to be compiled.
	 * @param array<string,mixed> $plan     Compiled WordPress site plan.
	 * @return array{artifact:array<string,mixed>,dropped:array<int,array<string,mixed>>}
	 */
	public static function drop_unproven_dynamic_scripts( array $artifact, array $plan ): array {
		if ( 'not_proven' !== ( $plan['reference_semantics']['dynamic_client_assets']['status'] ?? '' ) || ! is_array( $artifact['files'] ?? null ) ) {
			return array(
				'artifact' => $artifact,
				'dropped'  => array(),
			);
		}
		$tokens = array();
		foreach ( is_array( $plan['reference_tokens'] ?? null ) ? $plan['reference_tokens'] : array() as $reference ) {
			if ( is_array( $reference ) && is_string( $reference['token'] ?? null ) && is_string( $reference['target_path'] ?? null ) ) {
				$tokens[ $reference['token'] ] = $reference['target_path'];
			}
		}
		$asset_sources = array();
		foreach ( is_array( $plan['assets'] ?? null ) ? $plan['assets'] : array() as $asset ) {
			if ( is_array( $asset ) && is_string( $asset['target_path'] ?? null ) && is_string( $asset['source_path'] ?? null ) ) {
				$asset_sources[ $asset['target_path'] ] = $asset['source_path'];
			}
		}
		$pages = array();
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			if ( is_array( $page ) && is_string( $page['source_path'] ?? null ) ) {
				$pages[ $page['source_path'] ] = $page;
			}
		}
		$script_files  = array();
		$inline_orders = array();
		$dropped       = array();
		foreach ( $plan['diagnostics'] ?? array() as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || 'wordpress_site_plan_script_dynamic_references' !== ( $diagnostic['code'] ?? null ) || ! is_string( $diagnostic['source_path'] ?? null ) || ! str_contains( $diagnostic['source_path'], '#' ) ) {
				continue;
			}
			list( $page_path, $order ) = explode( '#', $diagnostic['source_path'], 2 );
			$declaration               = null;
			foreach ( $pages[ $page_path ]['document_metadata']['scripts'] ?? array() as $script ) {
				if ( is_array( $script ) && (string) ( $script['order'] ?? '' ) === $order ) {
					$declaration = $script;
					break;
				}
			}
			if ( null === $declaration ) {
				continue;
			}
			$row = array(
				'path'            => $page_path,
				'class'           => 'unproven_dynamic',
				'type'            => 'inline',
				'source_document' => $page_path,
				'@order'          => (int) $order,
			);
			if ( is_string( $declaration['asset_reference'] ?? null ) && preg_match( '/^\{\{wordpress-site-plan:asset:([^}]+)\}\}/', $declaration['asset_reference'], $match ) && isset( $tokens[ $match[1] ], $asset_sources[ $tokens[ $match[1] ] ] ) ) {
				$script_path                  = $asset_sources[ $tokens[ $match[1] ] ];
				$script_files[ $script_path ] = true;
				$row['type']                  = 'asset';
				$row['path']                  = $script_path;
				$dropped[]                    = $row;
			} elseif ( isset( $pages[ $page_path ] ) ) {
				$inline_orders[ $page_path ][] = (int) $order;
				$dropped[]                     = $row;
			}
		}
		if ( array() === $script_files && array() === $inline_orders ) {
			return array(
				'artifact' => $artifact,
				'dropped'  => array(),
			);
		}
		$contents = self::artifact_file_contents( $artifact['files'] );
		foreach ( $contents as $path => $content ) {
			if ( ! self::is_html_path( $path ) ) {
				continue;
			}
			$contents[ $path ] = self::strip_unproven_dynamic_markup( $content, $path, $script_files, $inline_orders[ $path ] ?? array(), $dropped );
		}
		foreach ( $dropped as $index => $row ) {
			if ( 'asset' === $row['type'] ) {
				$dropped[ $index ]['sha256'] = hash( 'sha256', (string) ( $contents[ $row['path'] ] ?? '' ) );
				unset( $dropped[ $index ]['@order'] );
				continue;
			}
			if ( isset( $row['@order'] ) ) {
				$dropped[ $index ]['sha256'] = hash( 'sha256', (string) ( $row['@tag'] ?? '' ) );
				unset( $dropped[ $index ]['@order'], $dropped[ $index ]['@tag'] );
			}
		}
		foreach ( array_keys( $script_files ) as $script_path ) {
			unset( $contents[ $script_path ] );
		}
		return array(
			'artifact' => array_merge( $artifact, array( 'files' => self::artifact_files_from_contents( $artifact['files'], $contents ) ) ),
			'dropped'  => $dropped,
		);
	}

	/** @param array<int|string,mixed> $files @return array<string,string> */
	private static function artifact_file_contents( array $files ): array {
		$contents = array();
		foreach ( $files as $key => $file ) {
			if ( is_array( $file ) ) {
				$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
				if ( '' !== $path ) {
					$contents[ $path ] = self::file_content( $file );
				}
			} elseif ( is_string( $file ) && is_string( $key ) ) {
				$contents[ $key ] = $file;
			}
		}
		return $contents;
	}

	/** @param array<int|string,mixed> $files @param array<string,string> $contents @return array<int|string,mixed> */
	private static function artifact_files_from_contents( array $files, array $contents ) {
		if ( array() !== $files && ! isset( $files[0] ) && ! is_numeric( key( $files ) ) ) {
			return $contents;
		}
		$filtered = array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = isset( $file['path'] ) && is_scalar( $file['path'] ) ? (string) $file['path'] : '';
			if ( ! isset( $contents[ $path ] ) ) {
				continue;
			}
			$filtered[] = self::with_file_content( $file, $contents[ $path ] );
		}
		return $filtered;
	}

	private static function is_html_path( string $path ): bool {
		$path = strtolower( $path );
		return str_ends_with( $path, '.html' ) || str_ends_with( $path, '.htm' );
	}

	/**
	 * @param array<string,true>             $script_files  Artifact paths of dropped script files.
	 * @param array<int,int>                 $inline_orders Document-order indexes of dropped inline scripts.
	 * @param array<int,array<string,mixed>> $dropped       Loss rows, stamped in place.
	 */
	private static function strip_unproven_dynamic_markup( string $html, string $path, array $script_files, array $inline_orders, array &$dropped ): string {
		$index = -1;
		$html  = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( array $matches ) use ( $path, $script_files, $inline_orders, &$index, &$dropped ): string {
				++$index;
				$source   = self::attribute( $matches[1], 'src' );
				$resolved = null === $source ? null : self::resolve_artifact_path( $path, $source );
				if ( null !== $resolved && isset( $script_files[ $resolved ] ) ) {
					foreach ( $dropped as $row_index => $row ) {
						if ( 'asset' === ( $row['type'] ?? '' ) && ( $row['path'] ?? '' ) === $resolved && ( $row['source_document'] ?? '' ) === $path && ! isset( $row['src'] ) ) {
							$dropped[ $row_index ]['src'] = $source;
						}
					}
					return '';
				}
				if ( null === $source && in_array( $index, $inline_orders, true ) ) {
					foreach ( $dropped as $row_index => $row ) {
						if ( 'inline' === ( $row['type'] ?? '' ) && ( $row['source_document'] ?? '' ) === $path && (int) ( $row['@order'] ?? -1 ) === $index ) {
							$dropped[ $row_index ]['@tag'] = $matches[0];
						}
					}
					return '';
				}
				return $matches[0];
			},
			$html
		);
		return (string) preg_replace_callback(
			'#<link\b([^>]*)/?>#is',
			static function ( array $matches ) use ( $path, $script_files ): string {
				$attributes = $matches[1];
				$relation   = strtolower( trim( (string) self::attribute( $attributes, 'rel' ) ) );
				$as         = strtolower( trim( (string) self::attribute( $attributes, 'as' ) ) );
				$script     = 'modulepreload' === $relation || ( 'preload' === $relation && 'script' === $as );
				if ( ! $script ) {
					return $matches[0];
				}
				$href     = self::attribute( $attributes, 'href' );
				$resolved = null === $href ? null : self::resolve_artifact_path( $path, $href );
				return null !== $resolved && isset( $script_files[ $resolved ] ) ? '' : $matches[0];
			},
			$html
		);
	}

	/** Resolve a document-relative reference to an artifact path, or null when it leaves the artifact. */
	private static function resolve_artifact_path( string $document_path, string $reference ): ?string {
		if ( '' === $reference || preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference ) ) {
			return null;
		}
		$reference = (string) preg_replace( '~[?#].*$~', '', $reference );
		if ( '' === $reference ) {
			return null;
		}
		$base = str_starts_with( $reference, '/' ) ? array() : array_filter( explode( '/', str_replace( '\\', '/', dirname( $document_path ) ) ), static fn( string $segment ): bool => '' !== $segment && '.' !== $segment );
		foreach ( explode( '/', str_replace( '\\', '/', $reference ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $base );
				continue;
			}
			$base[] = $segment;
		}
		$path = implode( '/', $base );
		return '' === $path ? null : $path;
	}

	private static function policy_name( array $args ): string {
		return 'isolated_preview' === (string) ( $args['client_script_policy'] ?? '' ) ? 'isolated_preview' : 'inert';
	}

	private static function provenance( array $args ): string {
		$provenance = $args['client_script_provenance'] ?? null;
		if ( is_scalar( $provenance ) ) {
			return trim( (string) $provenance );
		}
		if ( is_array( $provenance ) && isset( $provenance['ref'] ) && is_scalar( $provenance['ref'] ) ) {
			return trim( (string) $provenance['ref'] );
		}
		return '';
	}

	private static function is_html_file( array $file ): bool {
		$path = strtolower( (string) ( $file['path'] ?? '' ) );
		$mime = strtolower( (string) ( $file['mime_type'] ?? '' ) );
		return str_ends_with( $path, '.html' ) || str_ends_with( $path, '.htm' ) || str_contains( $mime, 'html' );
	}

	private static function is_script_file( array $file ): bool {
		$path = strtolower( (string) ( $file['path'] ?? '' ) );
		$mime = strtolower( (string) ( $file['mime_type'] ?? '' ) );
		return (bool) preg_match( '/\.(?:js|mjs|cjs)$/', $path ) || str_contains( $mime, 'javascript' ) || str_contains( $mime, 'ecmascript' );
	}

	/** Preserve bounded non-executable JSON-LD facts for the producer before stripping script markup. */
	private static function structured_data( string $html ): array {
		$records = array();
		$bytes   = 0;
		preg_match_all( '#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $scripts, PREG_SET_ORDER );
		foreach ( array_slice( $scripts, 0, 32 ) as $script ) {
			if ( null !== self::attribute( $script[1], 'src' ) || 'application/ld+json' !== strtolower( trim( (string) self::attribute( $script[1], 'type' ) ) ) || strlen( $script[2] ) > 262144 ) {
				continue;
			}
			$data = json_decode( $script[2], true, 24 );
			if ( is_array( $data ) ) {
				$bytes += strlen( $script[2] );
				if ( $bytes > 262144 ) {
					break;
				}
				$records[] = array(
					'type' => 'application/ld+json',
					'data' => $data,
				);
			}
		}
		return $records;
	}

	private static function filter_html( string $html, string $path, bool $preserve, Static_Site_Importer_Client_Script_Policy_Report $report ): string {
		$html = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( array $matches ) use ( $path, $preserve, $report ): string {
				$attributes = $matches[1];
				$source     = self::attribute( $attributes, 'src' );
				$type       = strtolower( trim( (string) self::attribute( $attributes, 'type' ) ) );
				$row        = array(
					'path'   => $path,
					'class'  => self::script_class( $source, $type, $matches[2] ),
					'type'   => '' !== $type ? $type : 'classic',
					'sha256' => hash( 'sha256', $matches[0] ),
				);
				if ( null !== $source ) {
					$row['src'] = $source;
				}
				if ( $preserve ) {
					self::record( $report, 'preserved', $row );
					return $matches[0];
				}
				if ( null === $source && self::is_generated_device_selection_script( $attributes, $matches[2] ) ) {
					$row['class'] = 'device_selection';
					self::record( $report, 'preserved', $row );
					return $matches[0];
				}
				self::record( $report, 'data' === $row['class'] ? 'quarantined' : 'dropped', $row );
				return '';
			},
			$html
		);
		return (string) preg_replace_callback(
			'#<link\b([^>]*)/?>#is',
			static function ( array $matches ) use ( $path, $preserve, $report ): string {
				$attributes = $matches[1];
				$relation   = strtolower( trim( (string) self::attribute( $attributes, 'rel' ) ) );
				$as         = strtolower( trim( (string) self::attribute( $attributes, 'as' ) ) );
				$relations  = preg_split( '/\s+/', $relation );
				$relations  = false === $relations ? array() : $relations;
				$script     = in_array( 'modulepreload', $relations, true ) || ( in_array( 'preload', $relations, true ) && 'script' === $as );
				if ( ! $script ) {
					return $matches[0];
				}
				$row = array(
					'path'   => $path,
					'class'  => 'preload',
					'type'   => 'modulepreload' === $relation ? 'modulepreload' : 'preload',
					'href'   => (string) self::attribute( $attributes, 'href' ),
					'sha256' => hash( 'sha256', $matches[0] ),
				);
				self::record( $report, $preserve ? 'preserved' : 'dropped', $row );
				return $preserve ? $matches[0] : '';
			},
			$html
		);
	}

	/**
	 * Is this one of the three scripts Data Liberation Agent generates to select a device document?
	 *
	 * The capture hides every device document behind a default `display:none` rule and relies on these
	 * scripts to reveal the matching one, so dropping them leaves the page blank. The policy still treats
	 * imported code as untrusted: a script is kept only when its attributes are exactly one marker, its body
	 * is the generated template byte for byte, and the data embedded in it passes a strict allowlist.
	 * Anything else, including a near miss, is dropped like every other script.
	 *
	 * @param string $attributes Raw attribute string of the script tag.
	 * @param string $body       Script body.
	 */
	private static function is_generated_device_selection_script( string $attributes, string $body ): bool {
		if ( strlen( $body ) > 262144 || 1 !== preg_match( '/^\s+data-dla-device-(selection|styles|body)(?:=(?:""|\'\'))?\s*$/', $attributes, $marker ) ) {
			return false;
		}
		if ( 'styles' === $marker[1] ) {
			return self::device_styles_script() === $body;
		}
		$open = '(function(){' . self::device_overlay_runtime();
		if ( 'selection' === $marker[1] ) {
			$config = self::device_script_data( $body, $open . 'var c=', self::device_selection_tail() );
			return null !== $config && self::is_valid_device_config( $config );
		}
		$roots = self::device_script_data( $body, $open . 'var roots=', self::device_body_tail() );
		return null !== $roots && self::is_valid_device_roots( $roots, null );
	}

	/**
	 * Decode the JSON object a generated script embeds between its fixed prefix and suffix.
	 *
	 * @return array<string,mixed>|null Null unless the whole middle section is one JSON object.
	 */
	private static function device_script_data( string $body, string $prefix, string $suffix ): ?array {
		if ( strlen( $body ) <= strlen( $prefix ) + strlen( $suffix ) || ! str_starts_with( $body, $prefix ) || ! str_ends_with( $body, $suffix ) ) {
			return null;
		}
		$data = json_decode( substr( $body, strlen( $prefix ), -strlen( $suffix ) ), true, 8 );
		return is_array( $data ) ? $data : null;
	}

	/** @param array<string,mixed> $config Data embedded in the selection script. */
	private static function is_valid_device_config( array $config ): bool {
		$selection = $config['selection'] ?? null;
		if ( array( 'roots', 'selection', 'viewports' ) !== self::sorted_keys( $config ) || ! is_array( $selection ) || ! self::keys_within( $selection, array( 'kind', 'id', 'rules', 'defaultDocument', 'documents', 'evidence' ) ) ) {
			return false;
		}
		$documents = $selection['documents'] ?? null;
		if ( 'device' !== ( $selection['kind'] ?? null ) || ! self::is_device_key( $selection['defaultDocument'] ?? null ) || ! self::is_bounded_list( $documents, 16 ) || ! in_array( $selection['defaultDocument'], $documents, true ) ) {
			return false;
		}
		foreach ( $documents as $key ) {
			if ( ! self::is_device_key( $key ) ) {
				return false;
			}
		}
		$field_limits = array(
			'id'       => 128,
			'evidence' => 4096,
		);
		foreach ( $field_limits as $field => $limit ) {
			if ( isset( $selection[ $field ] ) && ( ! is_string( $selection[ $field ] ) || strlen( $selection[ $field ] ) > $limit ) ) {
				return false;
			}
		}
		if ( ! self::is_bounded_list( $selection['rules'] ?? null, 32 ) ) {
			return false;
		}
		foreach ( $selection['rules'] as $rule ) {
			if ( ! is_array( $rule ) || ! self::keys_within( $rule, array( 'userAgent', 'flags', 'document' ) ) || ! self::is_safe_user_agent_pattern( $rule['userAgent'] ?? null )
				|| ( isset( $rule['flags'] ) && ( ! is_string( $rule['flags'] ) || 1 !== preg_match( '/^[imsu]{0,4}$/', $rule['flags'] ) ) )
				|| ! in_array( $rule['document'] ?? null, $documents, true ) ) {
				return false;
			}
		}
		$viewports = $config['viewports'];
		if ( ! is_array( $viewports ) || ! self::keys_within( $viewports, $documents ) ) {
			return false;
		}
		foreach ( $viewports as $viewport ) {
			// Only these names are ever passed to setAttribute() on the viewport <meta>.
			if ( ! is_array( $viewport ) || ! self::keys_within( $viewport, array( 'name', 'content', 'id' ) ) || ( isset( $viewport['name'] ) && 'viewport' !== $viewport['name'] )
				|| ! is_string( $viewport['content'] ?? null ) || strlen( $viewport['content'] ) > 512 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $viewport['content'] )
				|| ( isset( $viewport['id'] ) && ( ! is_string( $viewport['id'] ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9:._-]{0,127}$/', $viewport['id'] ) ) ) ) {
				return false;
			}
		}
		return self::is_valid_device_roots( $config['roots'], $documents );
	}

	/**
	 * Root attributes the overlay applies to <html> and <body>.
	 *
	 * @param mixed                  $roots     Decoded roots map.
	 * @param array<int,string>|null $documents Declared document keys, when known.
	 */
	private static function is_valid_device_roots( $roots, ?array $documents ): bool {
		if ( ! is_array( $roots ) || count( $roots ) > 16 ) {
			return false;
		}
		foreach ( $roots as $key => $targets ) {
			if ( ! self::is_device_key( (string) $key ) || ( null !== $documents && ! in_array( (string) $key, $documents, true ) ) || ! is_array( $targets ) || ! self::keys_within( $targets, array( 'html', 'body' ) ) ) {
				return false;
			}
			foreach ( $targets as $attributes ) {
				if ( ! is_array( $attributes ) || ! self::keys_within( $attributes, array( 'class', 'style', 'lang', 'dir' ) ) ) {
					return false;
				}
				foreach ( $attributes as $name => $value ) {
					$limit = 'style' === $name ? 8192 : ( 'class' === $name ? 2048 : 35 );
					if ( ! is_string( $value ) || strlen( $value ) > $limit || ( 'lang' === $name && 1 !== preg_match( '/^[A-Za-z0-9-]*$/', $value ) ) || ( 'dir' === $name && ! in_array( $value, array( '', 'ltr', 'rtl', 'auto' ), true ) ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}

	/** The pattern is compiled with `new RegExp()` in the visitor's browser, so keep it small and non-backtracking-prone. */
	private static function is_safe_user_agent_pattern( $pattern ): bool {
		return is_string( $pattern ) && '' !== $pattern && strlen( $pattern ) <= 512 && 1 !== preg_match( '/\)\s*(?:[+*]|\{\d)|\\\\[1-9k]|\(\?[=!<]/', $pattern );
	}

	/** @param mixed $key Candidate document key. */
	private static function is_device_key( $key ): bool {
		return is_string( $key ) && 1 === preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $key );
	}

	/** @param mixed $items Candidate list. */
	private static function is_bounded_list( $items, int $max ): bool {
		return is_array( $items ) && array() !== $items && count( $items ) <= $max && array_keys( $items ) === range( 0, count( $items ) - 1 );
	}

	/** @param array<int|string,mixed> $map Map to check. @param array<int,string> $allowed Allowed keys. */
	private static function keys_within( array $map, array $allowed ): bool {
		return array() === array_diff( array_map( 'strval', array_keys( $map ) ), $allowed );
	}

	/** @param array<int|string,mixed> $map Map to inspect. @return array<int,string> */
	private static function sorted_keys( array $map ): array {
		$keys = array_map( 'strval', array_keys( $map ) );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Template pieces of the scripts Data Liberation Agent's installDeviceSelection() generates.
	 *
	 * Source: data-liberation-agent/src/lib/document-selection.ts. These must stay byte-identical to that
	 * template; the smoke test generates the scripts with the real producer so drift fails loudly.
	 */
	private static function device_overlay_runtime(): string {
		return implode(
			"\n",
			array(
				'function overlayRoot(node,roots,kind,key){',
				'var selected=roots[key]&&roots[key][kind];if(!selected)return;',
				'var parser=document.createElement(\'div\');',
				'Object.keys(roots).forEach(function(id){var attrs=roots[id][kind];',
				'(attrs.class||\'\').split(/\\s+/).filter(Boolean).forEach(function(token){node.classList.remove(token);});',
				'parser.style.cssText=attrs.style||\'\';',
				'for(var i=0;i<parser.style.length;i++)node.style.removeProperty(parser.style.item(i));',
				'[\'lang\',\'dir\'].forEach(function(name){if(Object.prototype.hasOwnProperty.call(attrs,name))node.removeAttribute(name);});',
				'});',
				'(selected.class||\'\').split(/\\s+/).filter(Boolean).forEach(function(token){node.classList.add(token);});',
				'parser.style.cssText=selected.style||\'\';',
				'for(var i=0;i<parser.style.length;i++){var property=parser.style.item(i);node.style.setProperty(property,parser.style.getPropertyValue(property),parser.style.getPropertyPriority(property));}',
				'[\'lang\',\'dir\'].forEach(function(name){if(Object.prototype.hasOwnProperty.call(selected,name))node.setAttribute(name,selected[name]);});',
				'}',
			)
		);
	}

	private static function device_selection_tail(): string {
		return implode(
			"\n",
			array(
				',s=c.selection,ua=navigator.userAgent,key=s.defaultDocument;for(var i=0;i<s.rules.length;i++){var r=s.rules[i];if(new RegExp(r.userAgent,r.flags||\'\').test(ua)){key=r.document;break;}}var root=document.documentElement;root.setAttribute(\'data-dla-selected-document\',key);var vp=c.viewports[key],meta=document.querySelector(\'meta[data-dla-selected-viewport]\');meta.removeAttribute(\'content\');meta.removeAttribute(\'id\');if(vp){overlayRoot(root,c.roots,\'html\',key);Object.keys(vp).forEach(function(k){meta.setAttribute(k,vp[k]);});root.removeAttribute(\'data-dla-document-unavailable\');}else{root.setAttribute(\'data-dla-document-unavailable\',key);}})();',
			)
		);
	}

	private static function device_body_tail(): string {
		return implode(
			"\n",
			array(
				',key=document.documentElement.getAttribute(\'data-dla-selected-document\');overlayRoot(document.body,roots,\'body\',key);})();',
			)
		);
	}

	private static function device_styles_script(): string {
		return implode(
			"\n",
			array(
				'(function(){var node=document.currentScript.previousElementSibling,key=document.documentElement.getAttribute(\'data-dla-selected-document\');if(node.getAttribute(\'data-dla-device-style\')!==key)return;var copy=node.cloneNode(true);copy.setAttribute(\'media\',node.getAttribute(\'data-dla-source-media\'));copy.removeAttribute(\'data-dla-device-style\');document.write(copy.outerHTML);})();',
			)
		);
	}

	private static function attribute( string $attributes, string $name ): ?string {
		if ( ! preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $matches ) ) {
			return null;
		}
		if ( '' !== $matches[1] ) {
			return $matches[1];
		}
		if ( isset( $matches[2] ) && '' !== $matches[2] ) {
			return $matches[2];
		}
		return isset( $matches[3] ) ? $matches[3] : '';
	}

	private static function script_class( ?string $source, string $type, string $content ): string {
		if ( in_array( $type, array( 'application/json', 'application/ld+json', 'application/manifest+json' ), true ) || ( null !== $source && str_starts_with( strtolower( $source ), 'data:' ) ) ) {
			return 'data';
		}
		if ( 'module' === $type ) {
			return 'module';
		}
		if ( preg_match( '/(?:google-analytics|googletagmanager|gtag\s*\(|segment\.|mixpanel|hotjar|clarity|sentry|telemetry|analytics)/i', (string) $source . "\n" . $content ) ) {
			return 'telemetry';
		}
		if ( null === $source ) {
			return 'inline';
		}
		return preg_match( '#^(?:https?:)?//#i', $source ) ? 'remote' : 'local';
	}

	private static function file_row( string $path, array $file ): array {
		return array(
			'path'   => $path,
			'class'  => 'local',
			'type'   => 'asset',
			'sha256' => hash( 'sha256', self::file_content( $file ) ),
		);
	}

	private static function file_content( array $file ): string {
		if ( isset( $file['content'] ) && is_scalar( $file['content'] ) ) {
			return (string) $file['content'];
		}
		if ( isset( $file['content_base64'] ) && is_scalar( $file['content_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes declared artifact content before applying the script policy.
			$decoded = base64_decode( (string) $file['content_base64'], true );
			return false === $decoded ? '' : $decoded;
		}
		return '';
	}

	private static function with_file_content( array $file, string $content ): array {
		if ( array_key_exists( 'content_base64', $file ) && ! array_key_exists( 'content', $file ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Restores filtered declared artifact content to its original representation.
			$file['content_base64'] = base64_encode( $content );
			return $file;
		}
		$file['content'] = $content;
		unset( $file['content_base64'] );
		return $file;
	}

	private static function record( Static_Site_Importer_Client_Script_Policy_Report $report, string $disposition, array $row ): void {
		$report->record( $disposition, $row );
	}
}
