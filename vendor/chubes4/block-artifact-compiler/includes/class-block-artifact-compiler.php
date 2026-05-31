<?php
/**
 * Website artifact to WordPress artifact compiler.
 *
 * @package BlockArtifactCompiler
 */

/**
 * Compiles arbitrary website artifact bundles into WordPress-native artifacts.
 */
class Block_Artifact_Compiler {
	private const RESULT_SCHEMA = 'chubes4/block-artifact-compiler-result/v1';
	private const INPUT_SCHEMA  = 'chubes4/website-artifact/v1';

	private const DEFAULT_MAX_FILES      = 200;
	private const DEFAULT_MAX_FILE_BYTES = 2097152;
	private const DEFAULT_MAX_TOTAL_BYTES = 10485760;

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$normalized  = $this->normalize_artifact( $artifact, $options );
		$entry       = $this->entry_file( $normalized );
		$html        = is_array( $entry ) ? $entry['content'] : '';
		$entry_path  = is_array( $entry ) ? $entry['path'] : '';
		$diagnostics = $normalized['diagnostics'];

		if ( '' === trim( $html ) ) {
			$diagnostics[] = $this->diagnostic( 'missing_entry_html', 'error', 'No HTML entry file was available to compile.' );
		}

		$conversion = '' !== trim( $html ) ? $this->convert_html_to_blocks( $html, $options ) : array(
			'serialized_blocks' => '',
			'blocks'            => array(),
			'diagnostics'       => array(),
			'report'            => array(),
		);

		$diagnostics = array_merge( $diagnostics, $conversion['diagnostics'] );
		$components  = $this->detect_components( $normalized, $entry_path );
		$files       = $this->wordpress_files_from_artifact( $normalized );

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics( $diagnostics ),
			'input'               => array(
				'schema'          => self::INPUT_SCHEMA,
				'entry_path'      => $entry_path,
				'file_count'      => count( $normalized['files'] ),
				'accepted_count'  => count( $normalized['files'] ),
				'rejected_count'  => $normalized['rejected_count'],
				'bytes'           => $normalized['bytes'],
				'files_by_kind'   => $this->count_files_by_kind( $normalized['files'] ),
				'original_schema' => (string) ( $artifact['schema'] ?? '' ),
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_types'  => array(),
				'components'   => $components,
				'files'        => $files,
			),
			'provenance'          => array(
				'source_hash' => hash( 'sha256', $this->artifact_hash_payload( $normalized ) ),
				'source'      => $entry_path,
			),
			'diagnostics'         => $diagnostics,
			'bfb_report'          => $conversion['report'],
		);
	}

	/**
	 * Compile a single content fragment.
	 *
	 * @param string               $content Source content.
	 * @param string               $source  Source label or path.
	 * @param string               $format  Source format.
	 * @param array<string, mixed> $options Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
		$path = $this->virtual_fragment_path( $source, $format );

		return $this->compile(
			array(
				'files' => array(
					array(
						'path'    => $path,
						'kind'    => $format,
						'content' => $content,
					),
				),
			),
			$options
		);
	}

	/**
	 * Summarize a compiler result for upstream import reports.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	public function summarize_result( array $compiled ): array {
		$artifacts   = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$block_types = isset( $artifacts['block_types'] ) && is_array( $artifacts['block_types'] ) ? $artifacts['block_types'] : array();
		$components  = isset( $artifacts['components'] ) && is_array( $artifacts['components'] ) ? $artifacts['components'] : array();
		$files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$diagnostics = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();

		return array(
			'schema'           => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
			'status'           => isset( $compiled['status'] ) ? (string) $compiled['status'] : '',
			'source'           => isset( $compiled['provenance']['source'] ) ? (string) $compiled['provenance']['source'] : '',
			'block_type_count' => count( $block_types ),
			'component_count'  => count( $components ),
			'file_count'       => count( $files ),
			'diagnostic_count' => count( $diagnostics ),
		);
	}

	/**
	 * Normalize supported website artifact input shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>,diagnostics:array<int,array<string,mixed>>,rejected_count:int,bytes:int}
	 */
	private function normalize_artifact( array $artifact, array $options ): array {
		$limits = array(
			'max_files'      => max( 1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES ) ),
			'max_file_bytes' => max( 1, (int) ( $options['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES ) ),
			'max_total_bytes' => max( 1, (int) ( $options['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ),
		);
		$raw_files   = $this->extract_raw_files( $artifact );
		$files       = array();
		$diagnostics = array();
		$total_bytes = 0;
		$rejected    = 0;
		$seen_paths  = array();

		foreach ( $raw_files as $index => $file ) {
			if ( count( $files ) >= $limits['max_files'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'file_limit_exceeded', 'warning', 'Additional artifact files were ignored because the file limit was reached.', array( 'max_files' => $limits['max_files'] ) );
				break;
			}

			$path = $this->safe_relative_path( (string) ( $file['path'] ?? '' ) );
			if ( '' === $path ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'index' => $index ) );
				continue;
			}

			$content = $this->normalize_content( $file['content'] ?? '' );
			$bytes   = strlen( $content );
			if ( $bytes > $limits['max_file_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'artifact_file_too_large', 'warning', 'An artifact file was ignored because it exceeds the per-file byte limit.', array( 'path' => $path, 'bytes' => $bytes, 'max_file_bytes' => $limits['max_file_bytes'] ) );
				continue;
			}

			if ( $total_bytes + $bytes > $limits['max_total_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'artifact_total_too_large', 'warning', 'An artifact file was ignored because the bundle byte limit was reached.', array( 'path' => $path, 'bytes' => $bytes, 'max_total_bytes' => $limits['max_total_bytes'] ) );
				continue;
			}

			$deduped_path = $this->dedupe_path( $path, $seen_paths );
			$seen_paths[ $deduped_path ] = true;
			$total_bytes += $bytes;

			$files[] = array(
				'path'    => $deduped_path,
				'content' => $content,
				'kind'    => $this->normalize_kind( (string) ( $file['kind'] ?? '' ), $deduped_path, $content ),
				'bytes'   => $bytes,
				'source'  => (string) ( $file['source'] ?? 'artifact' ),
			);
		}

		return array(
			'files'          => $files,
			'diagnostics'    => $this->dedupe_diagnostics( $diagnostics ),
			'rejected_count' => $rejected,
			'bytes'          => $total_bytes,
		);
	}

	/**
	 * Extract file-like entries from common AI artifact shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function extract_raw_files( array $artifact ): array {
		$files = array();
		foreach ( array( 'files', 'artifacts', 'outputs' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_array( $artifact[ $key ] ) ) {
				$files = array_merge( $files, $this->normalize_file_collection( $artifact[ $key ], $key ) );
			}
		}

		foreach ( array( 'html', 'generated_html', 'content', 'body' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) && '' !== trim( $artifact[ $key ] ) ) {
				$files[] = array(
					'path'    => 'index.html',
					'content' => $artifact[ $key ],
					'kind'    => 'html',
					'source'  => $key,
				);
			}
		}

		foreach ( array( 'css' => 'style.css', 'styles' => 'style.css', 'javascript' => 'site.js', 'js' => 'site.js', 'script' => 'site.js' ) as $key => $path ) {
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) && '' !== trim( $artifact[ $key ] ) ) {
				$files[] = array(
					'path'    => $path,
					'content' => $artifact[ $key ],
					'kind'    => str_contains( $path, '.css' ) ? 'css' : 'js',
					'source'  => $key,
				);
			}
		}

		return $files;
	}

	/**
	 * Normalize a list or path=>content map into file entries.
	 *
	 * @param array<mixed> $collection File collection.
	 * @param string       $source     Source key.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function normalize_file_collection( array $collection, string $source ): array {
		$files = array();
		foreach ( $collection as $key => $file ) {
			if ( is_array( $file ) ) {
				$path = (string) ( $file['path'] ?? $file['name'] ?? $key );
				$files[] = array(
					'path'    => $path,
					'content' => $file['content'] ?? $file['body'] ?? $file['text'] ?? '',
					'kind'    => $file['kind'] ?? $file['type'] ?? '',
					'source'  => $source,
				);
				continue;
			}

			if ( is_string( $file ) ) {
				$path = is_string( $key ) ? $key : 'artifact-' . (string) $key . '.html';
				$files[] = array(
					'path'    => $path,
					'content' => $file,
					'kind'    => '',
					'source'  => $source,
				);
			}
		}

		return $files;
	}

	/**
	 * Return the HTML entry file.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 * @return array{path:string,content:string,kind:string,bytes:int,source:string}|null
	 */
	private function entry_file( array $artifact ): ?array {
		$preferred = array( 'index.html', 'index.htm', 'static-site/index.html', 'public/index.html' );
		foreach ( $preferred as $path ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $path === strtolower( $file['path'] ) ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convert HTML to block markup through BFB/H2BC when available.
	 *
	 * @param string              $html    Source HTML.
	 * @param array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_html_to_blocks( string $html, array $options ): array {
		if ( str_contains( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $html );
			return array(
				'serialized_blocks' => serialize_blocks( $blocks ),
				'blocks'            => $blocks,
				'diagnostics'       => array(),
				'report'            => array( 'status' => 'success_native', 'source' => 'blocks' ),
			);
		}

		if ( function_exists( 'bfb_convert' ) ) {
			$block_markup = (string) bfb_convert( $html, 'html', 'blocks', $options );
			$report       = array( 'status' => '' === trim( $block_markup ) ? 'failed' : 'success_native' );
			if ( ! empty( $options['include_bfb_report'] ) && function_exists( 'bfb_conversion_report' ) ) {
				$report = bfb_conversion_report( $html, 'html', $options );
			}

			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => function_exists( 'parse_blocks' ) && '' !== trim( $block_markup ) ? parse_blocks( $block_markup ) : array(),
				'diagnostics'       => isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array(),
				'report'            => $report,
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic( 'bfb_unavailable', 'warning', 'BFB is unavailable; preserved source HTML as a core/html fallback.' ),
			),
			'report'            => array( 'status' => 'success_with_fallbacks' ),
		);
	}

	/**
	 * Build component candidates from explicit markers and repeated class tokens.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 * @param string $entry_path Entry path.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_components( array $artifact, string $entry_path ): array {
		$candidates = array();
		$classes    = array();

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' !== $file['kind'] ) {
				continue;
			}

			if ( preg_match_all( '/data-component\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $name ) {
					$key = sanitize_key( $name );
					if ( '' !== $key ) {
						$candidates[ 'explicit:' . $key ] = array(
							'name'       => $key,
							'source'     => $file['path'],
							'signal'     => 'data-component',
							'occurrences' => ( $candidates[ 'explicit:' . $key ]['occurrences'] ?? 0 ) + 1,
						);
					}
				}
			}

			if ( preg_match_all( '/class\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $class_list ) {
					foreach ( preg_split( '/\s+/', trim( $class_list ) ) ?: array() as $class ) {
						$class = sanitize_key( $class );
						if ( '' === $class || strlen( $class ) < 3 ) {
							continue;
						}
						$classes[ $class ] = ( $classes[ $class ] ?? 0 ) + 1;
					}
				}
			}
		}

		foreach ( $classes as $class => $count ) {
			if ( $count < 2 && ! preg_match( '/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class ) ) {
				continue;
			}

			$candidates[ 'class:' . $class ] = array(
				'name'        => $class,
				'source'      => $entry_path,
				'signal'      => 'class-token',
				'occurrences' => $count,
			);
		}

		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				return ( $right['occurrences'] <=> $left['occurrences'] ) ?: strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return array_slice( array_values( $candidates ), 0, 25 );
	}

	/**
	 * Return non-entry files that SSI or another materializer may consume later.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 * @return array<int,array<string,mixed>> Files.
	 */
	private function wordpress_files_from_artifact( array $artifact ): array {
		$files = array();
		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				continue;
			}

			$files[] = array(
				'path'    => $file['path'],
				'kind'    => $file['kind'],
				'bytes'   => $file['bytes'],
				'content' => $file['content'],
			);
		}

		return $files;
	}

	/**
	 * Normalize a relative artifact path and reject unsafe locations.
	 */
	private function safe_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = preg_replace( '/\0+/', '', $path );
		$path = ltrim( (string) $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Normalize content from scalar-ish inputs.
	 *
	 * @param mixed $content Raw content.
	 */
	private function normalize_content( mixed $content ): string {
		if ( is_scalar( $content ) || null === $content ) {
			return (string) $content;
		}

		$encoded = wp_json_encode( $content, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Normalize file kind from explicit kind, path, and content.
	 */
	private function normalize_kind( string $kind, string $path, string $content ): string {
		$kind = sanitize_key( $kind );
		if ( in_array( $kind, array( 'html', 'css', 'js', 'json', 'markdown', 'asset', 'blocks' ), true ) ) {
			return $kind;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'html', 'htm'       => 'html',
			'css'               => 'css',
			'js', 'mjs'          => 'js',
			'json'              => 'json',
			'md', 'markdown'    => 'markdown',
			default             => str_contains( $content, '<!-- wp:' ) ? 'blocks' : 'asset',
		};
	}

	/**
	 * Build a virtual path from an arbitrary fragment source label.
	 */
	private function virtual_fragment_path( string $source, string $format ): string {
		$path      = $this->safe_relative_path( str_replace( array( ':', '#' ), '-', $source ) );
		$extension = match ( sanitize_key( $format ) ) {
			'css'      => 'css',
			'js'       => 'js',
			'markdown' => 'md',
			default    => 'html',
		};

		return ( '' === $path ? 'fragment' : preg_replace( '/\.[A-Za-z0-9]+$/', '', $path ) ) . '.' . $extension;
	}

	/**
	 * Dedupe normalized paths deterministically.
	 *
	 * @param array<string,bool> $seen Seen paths.
	 */
	private function dedupe_path( string $path, array $seen ): string {
		if ( ! isset( $seen[ $path ] ) ) {
			return $path;
		}

		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		$base      = '' === $extension ? $path : substr( $path, 0, -1 - strlen( $extension ) );
		$suffix    = '' === $extension ? '' : '.' . $extension;
		$index     = 2;
		while ( isset( $seen[ $base . '-' . $index . $suffix ] ) ) {
			++$index;
		}

		return $base . '-' . $index . $suffix;
	}

	/**
	 * Count normalized files by kind.
	 *
	 * @param array<int,array{kind:string}> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_kind( array $files ): array {
		$counts = array();
		foreach ( $files as $file ) {
			$counts[ $file['kind'] ] = ( $counts[ $file['kind'] ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Build a normalized diagnostic entry.
	 *
	 * @param array<string,mixed> $details Diagnostic details.
	 * @return array<string,mixed>
	 */
	private function diagnostic( string $code, string $severity, string $message, array $details = array() ): array {
		$diagnostic = array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
		if ( ! empty( $details ) ) {
			$diagnostic['details'] = $details;
		}

		return $diagnostic;
	}

	/**
	 * Remove duplicate diagnostics emitted while rejecting many files.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>> Diagnostics.
	 */
	private function dedupe_diagnostics( array $diagnostics ): array {
		$deduped = array();
		$seen    = array();
		foreach ( $diagnostics as $diagnostic ) {
			$key = (string) ( $diagnostic['code'] ?? '' ) . '|' . md5( wp_json_encode( $diagnostic['details'] ?? array() ) ?: '' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$deduped[]    = $diagnostic;
		}

		return $deduped;
	}

	/**
	 * Resolve result status from diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 */
	private function status_from_diagnostics( array $diagnostics ): string {
		foreach ( $diagnostics as $diagnostic ) {
			if ( 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'failed';
			}
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( 'warning' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'success_with_warnings';
			}
		}

		return 'success';
	}

	/**
	 * Build a stable hash payload for provenance.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 */
	private function artifact_hash_payload( array $artifact ): string {
		$payload = '';
		foreach ( $artifact['files'] as $file ) {
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . $file['content'] . "\0";
		}

		return $payload;
	}
}
