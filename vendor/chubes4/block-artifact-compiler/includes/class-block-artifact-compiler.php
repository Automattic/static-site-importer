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

	private const RESULT_SCHEMA = 'block-artifact-compiler/result/v1';
	private const INPUT_SCHEMA  = 'block-artifact-compiler/website-artifact/v1';

	private const DEFAULT_MAX_FILES       = 200;
	private const DEFAULT_MAX_FILE_BYTES  = 2097152;
	private const DEFAULT_MAX_TOTAL_BYTES = 10485760;

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param  array<string,mixed> $artifact Website artifact input.
	 * @param  array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$normalized  = $this->normalize_artifact( $artifact, $options );
		$documents   = $this->compile_source_documents( $normalized, $options );
		$entry       = $this->entry_file( $normalized );
		$html        = is_array( $entry ) ? $entry['content'] : '';
		$entry_path  = is_array( $entry ) ? $entry['path'] : '';
		$diagnostics = array_merge( $normalized['diagnostics'], $documents['diagnostics'] );

		if ( '' === trim( $html ) && empty( $documents['documents'] ) ) {
			$diagnostics[] = $this->diagnostic( 'missing_entry_html', 'error', 'No HTML entry file was available to compile.' );
		}

		$conversion = '' !== trim( $html ) ? $this->convert_html_to_blocks( $html, $options ) : array(
			'serialized_blocks' => '',
			'blocks'            => array(),
			'diagnostics'       => array(),
			'report'            => array(),
		);

		$diagnostics = array_merge( $diagnostics, $conversion['diagnostics'] );
		$components  = $this->detect_components( $normalized, $entry_path, $documents['components'] );
		$block_types = $this->build_block_types( $normalized, $diagnostics );
		$files       = $this->wordpress_files_from_artifact( $normalized );
		if ( '' === trim( $html ) && ! empty( $documents['documents'][0]['block_markup'] ) ) {
			$conversion['serialized_blocks'] = (string) $documents['documents'][0]['block_markup'];
		}

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics( $diagnostics ),
			'input'               => array(
				'schema'          => self::INPUT_SCHEMA,
				'entry_path'      => $entry_path,
				'entrypoints'     => $normalized['entrypoints'],
				'file_count'      => count( $normalized['files'] ),
				'accepted_count'  => count( $normalized['files'] ),
				'rejected_count'  => $normalized['rejected_count'],
				'bytes'           => $normalized['bytes'],
				'files_by_kind'   => $this->count_files_by_kind( $normalized['files'] ),
				'files_by_role'   => $this->count_files_by_field( $normalized['files'], 'role' ),
				'files_by_mime'   => $this->count_files_by_field( $normalized['files'], 'mime_type' ),
				'original_schema' => (string) ( $artifact['schema'] ?? '' ),
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_types'  => $block_types,
				'components'   => $components,
				'documents'    => $documents['documents'],
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
	 * @param  string               $content Source content.
	 * @param  string               $source  Source label or path.
	 * @param  string               $format  Source format.
	 * @param  array<string, mixed> $options Compiler options.
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
	 * @param  array<string,mixed> $compiled Compiler result envelope.
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
	 * @return array{files:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int,bytes:int,entrypoints:array<int,string>}
	 */
	private function normalize_artifact( array $artifact, array $options ): array {
		$limits      = array(
			'max_files'       => max( 1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES ) ),
			'max_file_bytes'  => max( 1, (int) ( $options['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES ) ),
			'max_total_bytes' => max( 1, (int) ( $options['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ),
		);
		$raw_entrypoints = $this->extract_entrypoints( $artifact );
		$raw_files       = $this->extract_raw_files( $artifact );
		$files       = array();
		$diagnostics = array();
		$total_bytes = 0;
		$rejected    = 0;
		$seen_paths  = array();
		$entrypoints = array();

		foreach ( $raw_entrypoints as $entrypoint ) {
			$path = $this->safe_relative_path( $entrypoint );
			if ( '' === $path ) {
				$diagnostics[] = $this->diagnostic( 'unsafe_entrypoint_path', 'warning', 'An artifact entrypoint was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'path' => $entrypoint ) );
				continue;
			}
			$entrypoints[ $path ] = true;
		}

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

			$payload = $this->normalize_file_payload( $file, $path );
			$diagnostics = array_merge( $diagnostics, $payload['diagnostics'] );
			if ( ! $payload['accepted'] ) {
				++$rejected;
				continue;
			}

			$content = $payload['content'];
			$bytes   = $payload['bytes'];
			if ( $bytes > $limits['max_file_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic(
					'artifact_file_too_large',
					'warning',
					'An artifact file was ignored because it exceeds the per-file byte limit.',
					array(
						'path'           => $path,
						'bytes'          => $bytes,
						'max_file_bytes' => $limits['max_file_bytes'],
					)
				);
				continue;
			}

			if ( $total_bytes + $bytes > $limits['max_total_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic(
					'artifact_total_too_large',
					'warning',
					'An artifact file was ignored because the bundle byte limit was reached.',
					array(
						'path'            => $path,
						'bytes'           => $bytes,
						'max_total_bytes' => $limits['max_total_bytes'],
					)
				);
				continue;
			}

			$deduped_path                = $this->dedupe_path( $path, $seen_paths );
			$seen_paths[ $deduped_path ] = true;
			$total_bytes += $bytes;
			$mime_type   = $this->normalize_mime_type( (string) ( $file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ( str_contains( (string) ( $file['type'] ?? '' ), '/' ) ? $file['type'] : '' ) ), $deduped_path );
			$kind        = $this->normalize_kind( (string) ( $file['kind'] ?? $file['type'] ?? '' ), $deduped_path, $content, $mime_type );
			$is_binary   = $payload['binary'] || $this->is_binary_mime_type( $mime_type );
			$role        = $this->normalize_role( (string) ( $file['role'] ?? '' ), $kind, $mime_type, $deduped_path );
			$intent      = $this->normalize_intent( (string) ( $file['intent'] ?? '' ), $kind, $role );
			$is_entry    = ! empty( $entrypoints[ $deduped_path ] ) || ! empty( $file['entrypoint'] ) || 'entry' === $role;
			$content_base64 = $payload['content_base64'];
			if ( $is_binary && '' === $content_base64 ) {
				$content_base64 = base64_encode( $content );
			}

			if ( $is_entry ) {
				$entrypoints[ $deduped_path ] = true;
			}

			$normalized_file = array(
				'path'    => $deduped_path,
				'content' => $content,
				'kind'    => $kind,
				'bytes'   => $bytes,
				'source'  => (string) ( $file['source'] ?? 'artifact' ),
				'mime_type' => $mime_type,
				'role'    => $role,
				'encoding' => $payload['encoding'],
				'binary'  => $is_binary,
				'entrypoint' => $is_entry,
				'provenance' => array(
					'source_path' => $deduped_path,
					'source'      => (string) ( $file['source'] ?? 'artifact' ),
					'hash'        => hash( 'sha256', '' !== $content_base64 ? $content_base64 : $content ),
				),
			);

			if ( '' !== $content_base64 ) {
				$normalized_file['content_base64'] = $content_base64;
			}
			if ( '' !== $intent ) {
				$normalized_file['intent'] = $intent;
			}
			$files[] = $normalized_file;

			if ( 'mdx' === $kind ) {
				$diagnostics[] = $this->diagnostic( 'mdx_source_document_detected', 'warning', 'MDX source document support is partial; BAC preserved the source and extracted inspectable document/component metadata.', array( 'path' => $deduped_path ) );
			}
		}

		return array(
			'files'          => $files,
			'diagnostics'    => $this->dedupe_diagnostics( $diagnostics ),
			'rejected_count' => $rejected,
			'bytes'          => $total_bytes,
			'entrypoints'    => array_keys( $entrypoints ),
		);
	}

	/**
	 * Extract file-like entries from common AI artifact shapes.
	 *
	 * @param  array<string,mixed> $artifact Raw artifact.
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

		foreach ( array(
			'css'        => 'style.css',
			'styles'     => 'style.css',
			'javascript' => 'site.js',
			'js'         => 'site.js',
			'script'     => 'site.js',
		) as $key => $path ) {
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
	 * Extract explicit bundle entrypoints from common artifact shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @return array<int,string> Entrypoint paths.
	 */
	private function extract_entrypoints( array $artifact ): array {
		$entrypoints = array();
		foreach ( array( 'entrypoint', 'entry', 'main' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) ) {
				$entrypoints[] = $artifact[ $key ];
			}
		}

		if ( isset( $artifact['entrypoints'] ) && is_array( $artifact['entrypoints'] ) ) {
			foreach ( $artifact['entrypoints'] as $entrypoint ) {
				if ( is_string( $entrypoint ) ) {
					$entrypoints[] = $entrypoint;
				}
			}
		}

		return array_values( array_unique( $entrypoints ) );
	}

	/**
	 * Normalize a list or path=>content map into file entries.
	 *
	 * @param  array<mixed> $collection File collection.
	 * @param  string       $source     Source key.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function normalize_file_collection( array $collection, string $source ): array {
		$files = array();
		foreach ( $collection as $key => $file ) {
			if ( is_array( $file ) ) {
				$path_source    = $file['path'] ?? $file['name'] ?? $key;
				$artifact_source = $file['source'] ?? $source;
				$file['path']   = is_scalar( $path_source ) ? (string) $path_source : '';
				$file['source'] = is_scalar( $artifact_source ) ? (string) $artifact_source : $source;
				$files[] = $file;
				continue;
			}

			if ( is_string( $file ) ) {
				$path    = is_string( $key ) ? $key : 'artifact-' . (string) $key . '.html';
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
	 * @param array{files:array<int,array<string,mixed>>,entrypoints?:array<int,string>} $artifact Normalized artifact.
	 * @return array<string,mixed>|null
	 */
	private function entry_file( array $artifact ): ?array {
		$entrypoints = isset( $artifact['entrypoints'] ) && is_array( $artifact['entrypoints'] ) ? $artifact['entrypoints'] : array();
		foreach ( $entrypoints as $entrypoint ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $entrypoint === $file['path'] && 'html' === $file['kind'] && empty( $file['binary'] ) ) {
					return $file;
				}
			}
		}

		$preferred = array( 'index.html', 'index.htm', 'static-site/index.html', 'public/index.html' );
		foreach ( $preferred as $path ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $path === strtolower( (string) $file['path'] ) && empty( $file['binary'] ) ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] && empty( $file['binary'] ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convert HTML to block markup through BFB/H2BC when available.
	 *
	 * @param  string              $html    Source HTML.
	 * @param  array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_html_to_blocks( string $html, array $options ): array {
		if ( str_contains( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $html );
			return array(
				'serialized_blocks' => serialize_blocks( $blocks ),
				'blocks'            => $blocks,
				'diagnostics'       => array(),
				'report'            => array(
					'status' => 'success_native',
					'source' => 'blocks',
				),
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
	 * Compile Markdown and MDX content documents into WordPress-shaped artifacts.
	 *
	 * @param  array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string,mime_type:string,provenance:array<string,mixed>}>} $artifact Normalized artifact.
	 * @param  array<string,mixed>                                                                                                                           $options  Compiler options.
	 * @return array{documents:array<int,array<string,mixed>>,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function compile_source_documents( array $artifact, array $options ): array {
		$documents   = array();
		$components  = array();
		$diagnostics = array();

		foreach ( $artifact['files'] as $file ) {
			if ( ! in_array( $file['kind'], array( 'markdown', 'mdx' ), true ) ) {
				continue;
			}

			$parsed               = $this->parse_frontmatter( $file['content'] );
			$body                 = $parsed['body'];
			$frontmatter          = $parsed['frontmatter'];
			$document_diagnostics = array();

			if ( 'mdx' === $file['kind'] ) {
				$mdx                  = $this->extract_mdx_semantics( $body, $file, $artifact );
				$body                 = $mdx['markdown_body'];
				$components           = array_merge( $components, $mdx['components'] );
				$document_diagnostics = array_merge( $document_diagnostics, $mdx['diagnostics'] );
			}

			$conversion           = $this->convert_markdown_to_blocks( $body, $options );
			$document_diagnostics = array_merge( $document_diagnostics, $conversion['diagnostics'] );
			$diagnostics          = array_merge( $diagnostics, $document_diagnostics );

			$documents[] = array(
				'source_path'  => $file['path'],
				'kind'         => $file['kind'],
				'post_type'    => $this->frontmatter_string( $frontmatter, array( 'post_type', 'type' ), 'page' ),
				'slug'         => $this->frontmatter_string( $frontmatter, array( 'slug' ), $this->slug_from_path( $file['path'] ) ),
				'title'        => $this->frontmatter_string( $frontmatter, array( 'title' ), $this->title_from_path( $file['path'] ) ),
				'excerpt'      => $this->frontmatter_string( $frontmatter, array( 'excerpt', 'description' ), '' ),
				'date'         => $this->frontmatter_string( $frontmatter, array( 'date', 'published', 'published_at' ), '' ),
				'template'     => $this->frontmatter_string( $frontmatter, array( 'template', 'layout' ), '' ),
				'taxonomies'   => $this->frontmatter_taxonomies( $frontmatter ),
				'frontmatter'  => $frontmatter,
				'block_markup' => $conversion['serialized_blocks'],
				'diagnostics'  => $document_diagnostics,
				'provenance'   => $file['provenance'],
			);
		}

		return array(
			'documents'   => $documents,
			'components'  => $components,
			'diagnostics' => $this->dedupe_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Convert Markdown through BFB when present, otherwise preserve it in a block fallback.
	 *
	 * @param  array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_markdown_to_blocks( string $markdown, array $options ): array {
		if ( function_exists( 'bfb_convert' ) ) {
			$block_markup = (string) bfb_convert( $markdown, 'markdown', 'blocks', $options );
			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => function_exists( 'parse_blocks' ) && '' !== trim( $block_markup ) ? parse_blocks( $block_markup ) : array(),
				'diagnostics'       => array(),
				'report'            => array(
					'status' => '' === trim( $block_markup ) ? 'failed' : 'success_native',
					'source' => 'markdown',
				),
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic( 'bfb_unavailable', 'warning', 'BFB is unavailable; preserved source Markdown as a core/html fallback.' ),
			),
			'report'            => array(
				'status' => 'success_with_fallbacks',
				'source' => 'markdown',
			),
		);
	}

	/**
	 * Build component candidates from explicit markers and repeated class tokens.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @param string                                      $entry_path Entry path.
	 * @param array<int,array<string,mixed>>              $source_document_components Source document components.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_components( array $artifact, string $entry_path, array $source_document_components = array() ): array {
		$candidates = array();
		$classes    = array();

		foreach ( $source_document_components as $component ) {
			$key                = 'mdx:' . (string) ( $component['source'] ?? '' ) . ':' . (string) ( $component['name'] ?? '' );
			$candidates[ $key ] = $component;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( in_array( $file['kind'], array( 'jsx', 'tsx' ), true ) ) {
				foreach ( $this->detect_jsx_file_components( $file ) as $component ) {
					$candidates[ 'jsx-file:' . (string) $component['source'] . ':' . (string) $component['name'] ] = $component;
				}
			}

			if ( 'html' !== $file['kind'] ) {
				continue;
			}

			if ( preg_match_all( '/data-component\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $name ) {
					$key = sanitize_key( $name );
					if ( '' !== $key ) {
						$candidates[ 'explicit:' . $key ] = array(
							'name'        => $key,
							'source'      => $file['path'],
							'signal'      => 'data-component',
							'occurrences' => ( $candidates[ 'explicit:' . $key ]['occurrences'] ?? 0 ) + 1,
						);
					}
				}
			}

			if ( preg_match_all( '/class\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $class_list ) {
					$class_tokens = preg_split( '/\s+/', trim( $class_list ) );
					foreach ( false === $class_tokens ? array() : $class_tokens as $class ) {
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
				$occurrence_comparison = $right['occurrences'] <=> $left['occurrences'];
				return 0 !== $occurrence_comparison ? $occurrence_comparison : strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return array_slice( $candidates, 0, 25 );
	}

	/**
	 * Build generated custom block artifacts from block.json roots.
	 *
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics collected during compilation.
	 * @return array<int,array<string,mixed>> Block type artifacts.
	 */
	private function build_block_types( array $artifact, array &$diagnostics ): array {
		$block_types = array();
		$block_roots = array();

		foreach ( $artifact['files'] as $file ) {
			if ( 'block.json' !== basename( $file['path'] ) ) {
				continue;
			}

			$directory                 = dirname( $file['path'] );
			$directory                 = '.' === $directory ? '' : $directory;
			$block_roots[ $directory ] = $file;
		}

		foreach ( $block_roots as $directory => $block_json_file ) {
			$decoded = json_decode( $block_json_file['content'], true );
			if ( ! is_array( $decoded ) ) {
				$decoded       = array();
				$diagnostics[] = $this->diagnostic( 'invalid_block_json', 'warning', 'A generated block.json file could not be decoded.', array( 'path' => $block_json_file['path'] ) );
			}

			$name = isset( $decoded['name'] ) && is_string( $decoded['name'] ) ? trim( $decoded['name'] ) : '';
			if ( '' === $name ) {
				$name          = 'generated/' . ( '' === $directory ? 'block' : sanitize_key( basename( $directory ) ) );
				$diagnostics[] = $this->diagnostic(
					'block_json_missing_name',
					'warning',
					'A generated block.json file did not declare a name; a stable generated name was assigned.',
					array(
						'path' => $block_json_file['path'],
						'name' => $name,
					)
				);
			}

			$block_files   = $this->files_under_directory( $artifact['files'], $directory );
			$block_types[] = array(
				'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
				'name'            => $name,
				'slug'            => sanitize_key( basename( $name ) ),
				'directory'       => $directory,
				'block_json_path' => $block_json_file['path'],
				'block_json'      => $decoded,
				'metadata'        => $this->block_metadata_contract( $decoded ),
				'assets'          => $this->block_asset_contract( $decoded, $block_files ),
				'dependencies'    => $this->block_dependency_contract( $decoded, $block_files ),
				'provenance'      => array(
					'source'      => $block_json_file['source'],
					'source_hash' => hash( 'sha256', $this->file_hash_payload( $block_files ) ),
					'files'       => array_values( array_map( static fn ( array $file ): string => $file['path'], $block_files ) ),
				),
				'files'           => array_values(
					array_map(
						static function ( array $file ): array {
							return array(
								'path'  => $file['path'],
								'kind'  => $file['kind'],
								'bytes' => $file['bytes'],
							);
						},
						$block_files
					)
				),
			);
		}

		usort(
			$block_types,
			static function ( array $left, array $right ): int {
				return strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return $block_types;
	}

	/**
	 * Return files that belong to a block root directory.
	 *
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Files.
	 * @return array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> Files.
	 */
	private function files_under_directory( array $files, string $directory ): array {
		$matched = array();
		$prefix  = '' === $directory ? '' : $directory . '/';
		foreach ( $files as $file ) {
			if ( '' === $prefix || str_starts_with( $file['path'], $prefix ) ) {
				$matched[] = $file;
			}
		}

		return $matched;
	}

	/**
	 * Normalize block.json metadata into the block artifact contract.
	 *
	 * @param array<string,mixed> $block_json Decoded block.json.
	 * @return array<string,mixed> Metadata contract.
	 */
	private function block_metadata_contract( array $block_json ): array {
		$metadata = array();
		foreach ( array( 'apiVersion', 'title', 'category', 'description', 'keywords', 'attributes', 'supports', 'usesContext', 'providesContext', 'textdomain', 'example', 'variations', 'parent', 'ancestor', 'allowedBlocks' ) as $key ) {
			if ( array_key_exists( $key, $block_json ) ) {
				$metadata[ $key ] = $block_json[ $key ];
			}
		}

		return $metadata;
	}

	/**
	 * Normalize render, editor, style, and script references from block.json.
	 *
	 * @param array<string,mixed> $block_json Decoded block.json.
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Block files.
	 * @return array<string,array<int,array<string,mixed>>> Asset contract.
	 */
	private function block_asset_contract( array $block_json, array $files ): array {
		$assets = array(
			'render'        => array(),
			'editor_script' => array(),
			'script'        => array(),
			'view_script'   => array(),
			'editor_style'  => array(),
			'style'         => array(),
			'view_style'    => array(),
		);

		foreach (
			array(
				'render'       => 'render',
				'editorScript' => 'editor_script',
				'script'       => 'script',
				'viewScript'   => 'view_script',
				'editorStyle'  => 'editor_style',
				'style'        => 'style',
				'viewStyle'    => 'view_style',
			) as $source_field => $target_field
		) {
			foreach ( $this->normalize_asset_references( $block_json[ $source_field ] ?? null, $files, $source_field ) as $reference ) {
				$assets[ $target_field ][] = $reference;
			}
		}

		return $assets;
	}

	/**
	 * Normalize block.json asset references while preserving handles and generated file paths.
	 *
	 * @param mixed $value Asset reference value.
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Block files.
	 * @return array<int,array<string,mixed>> Asset references.
	 */
	private function normalize_asset_references( mixed $value, array $files, string $source_field ): array {
		$references = array();
		$values     = is_array( $value ) ? array_values( $value ) : array( $value );
		foreach ( $values as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) ) {
				continue;
			}

			$item        = trim( $item );
			$is_file_ref = str_starts_with( $item, 'file:' );
			$relative    = $is_file_ref ? substr( $item, 5 ) : '';
			$file        = $is_file_ref ? $this->find_block_file_by_relative_path( $files, $relative ) : null;

			$reference = array(
				'reference'    => $item,
				'source_field' => $source_field,
				'type'         => $is_file_ref ? 'file' : 'handle',
			);
			if ( is_array( $file ) ) {
				$reference['path']  = $file['path'];
				$reference['kind']  = $file['kind'];
				$reference['bytes'] = $file['bytes'];
			}

			$references[] = $reference;
		}

		return $references;
	}

	/**
	 * Return dependency references declared by block.json and generated .asset.php files.
	 *
	 * @param array<string,mixed> $block_json Decoded block.json.
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Block files.
	 * @return array<string,mixed> Dependency contract.
	 */
	private function block_dependency_contract( array $block_json, array $files ): array {
		$declared = array();
		foreach ( array( 'editorScript', 'script', 'viewScript', 'editorStyle', 'style', 'viewStyle' ) as $field ) {
			if ( ! array_key_exists( $field, $block_json ) ) {
				continue;
			}
			$declared[ $field ] = $block_json[ $field ];
		}

		$asset_files = array();
		foreach ( $files as $file ) {
			if ( str_ends_with( $file['path'], '.asset.php' ) ) {
				$asset_files[] = array(
					'path'  => $file['path'],
					'kind'  => $file['kind'],
					'bytes' => $file['bytes'],
				);
			}
		}

		return array(
			'declared'    => $declared,
			'asset_files' => $asset_files,
		);
	}

	/**
	 * Find a block-local generated file by its block.json file: reference.
	 *
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Block files.
	 * @return array{path:string,content:string,kind:string,bytes:int,source:string}|null
	 */
	private function find_block_file_by_relative_path( array $files, string $relative_path ): ?array {
		$relative_path = ltrim( str_replace( '\\', '/', $relative_path ), './' );
		foreach ( $files as $file ) {
			if ( basename( $file['path'] ) === $relative_path || str_ends_with( $file['path'], '/' . $relative_path ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Return non-entry files that SSI or another materializer may consume later.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array<int,array<string,mixed>> Files.
	 */
	private function wordpress_files_from_artifact( array $artifact ): array {
		$files = array();
		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				continue;
			}
			$manifest_file = array(
				'path'    => $file['path'],
				'kind'    => $file['kind'],
				'bytes'   => $file['bytes'],
				'mime_type' => $file['mime_type'],
				'role'    => $file['role'],
				'encoding' => $file['encoding'],
				'binary'  => $file['binary'],
				'provenance' => $file['provenance'],
			);

			if ( ! empty( $file['intent'] ) ) {
				$manifest_file['intent'] = $file['intent'];
			}
			if ( ! empty( $file['content_base64'] ) ) {
				$manifest_file['content_base64'] = $file['content_base64'];
			} else {
				$manifest_file['content'] = $file['content'];
			}

			$files[] = $manifest_file;
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
	 * Normalize file payloads from text or base64 content fields.
	 *
	 * @param array<string,mixed> $file Raw file entry.
	 * @return array{accepted:bool,content:string,content_base64:string,encoding:string,binary:bool,bytes:int,diagnostics:array<int,array<string,mixed>>}
	 */
	private function normalize_file_payload( array $file, string $path ): array {
		$diagnostics = array();
		if ( isset( $file['content_base64'] ) && is_string( $file['content_base64'] ) ) {
			$base64  = preg_replace( '/\s+/', '', $file['content_base64'] ) ?? '';
			$decoded = base64_decode( $base64, true );
			if ( false === $decoded ) {
				return array(
					'accepted'       => false,
					'content'        => '',
					'content_base64' => '',
					'encoding'       => 'base64',
					'binary'         => false,
					'bytes'          => 0,
					'diagnostics'    => array( $this->diagnostic( 'invalid_base64_content', 'warning', 'An artifact file was ignored because content_base64 is not valid base64.', array( 'path' => $path ) ) ),
				);
			}

			$is_binary = $this->looks_binary( $decoded );
			if ( ! $is_binary && isset( $file['content'] ) && is_string( $file['content'] ) && '' !== $file['content'] && $file['content'] !== $decoded ) {
				$diagnostics[] = $this->diagnostic( 'content_base64_preferred', 'info', 'Both content and content_base64 were provided; decoded content_base64 was used as the canonical payload.', array( 'path' => $path ) );
			}

			return array(
				'accepted'       => true,
				'content'        => $is_binary ? '' : $decoded,
				'content_base64' => $base64,
				'encoding'       => 'base64',
				'binary'         => $is_binary,
				'bytes'          => strlen( $decoded ),
				'diagnostics'    => $diagnostics,
			);
		}

		$content = $this->normalize_content( $file['content'] ?? $file['body'] ?? $file['text'] ?? '' );
		return array(
			'accepted'       => true,
			'content'        => $content,
			'content_base64' => '',
			'encoding'       => 'text',
			'binary'         => false,
			'bytes'          => strlen( $content ),
			'diagnostics'    => array(),
		);
	}

	/**
	 * Normalize file kind from explicit kind, path, and content.
	 */
	private function normalize_kind( string $kind, string $path, string $content, string $mime_type = '' ): string {
		$kind = sanitize_key( $kind );
		if ( in_array( $kind, array( 'html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'asset', 'blocks' ), true ) ) {
			return $kind;
		}
		if ( str_contains( $mime_type, '/' ) ) {
			if ( str_contains( $mime_type, 'html' ) ) {
				return 'html';
			}
			if ( 'text/css' === $mime_type ) {
				return 'css';
			}
			if ( in_array( $mime_type, array( 'application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript' ), true ) ) {
				return 'js';
			}
			if ( 'application/json' === $mime_type ) {
				return 'json';
			}
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'html', 'htm'       => 'html',
			'css'               => 'css',
			'js', 'mjs'          => 'js',
			'jsx'                => 'jsx',
			'tsx'                => 'tsx',
			'json'              => 'json',
			'md', 'markdown'    => 'markdown',
			'mdx'               => 'mdx',
			default             => str_contains( $content, '<!-- wp:' ) ? 'blocks' : 'asset',
		};
	}

	/**
	 * Normalize or infer a MIME type.
	 */
	private function normalize_mime_type( string $mime_type, string $path ): string {
		$mime_type = strtolower( trim( $mime_type ) );
		if ( preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime_type ) ) {
			return $mime_type;
		}

		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm'       => 'text/html',
			'css'               => 'text/css',
			'js', 'mjs'          => 'application/javascript',
			'jsx'               => 'text/jsx',
			'tsx'               => 'text/tsx',
			'json'              => 'application/json',
			'md', 'markdown'    => 'text/markdown',
			'mdx'               => 'text/mdx',
			'txt'               => 'text/plain',
			'svg'               => 'image/svg+xml',
			'png'               => 'image/png',
			'jpg', 'jpeg'       => 'image/jpeg',
			'gif'               => 'image/gif',
			'webp'              => 'image/webp',
			'avif'              => 'image/avif',
			'woff'              => 'font/woff',
			'woff2'             => 'font/woff2',
			'ttf'               => 'font/ttf',
			'otf'               => 'font/otf',
			default             => 'application/octet-stream',
		};
	}

	/**
	 * Normalize a file role without making policy decisions about generated output.
	 */
	private function normalize_role( string $role, string $kind, string $mime_type, string $path ): string {
		$role = sanitize_key( $role );
		if ( '' !== $role ) {
			return $role;
		}

		if ( 'html' === $kind ) {
			return preg_match( '#(^|/)index\.html?$#i', $path ) ? 'entry' : 'document';
		}
		if ( 'css' === $kind ) {
			return 'stylesheet';
		}
		if ( 'js' === $kind ) {
			return 'script';
		}
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 'image';
		}
		if ( str_starts_with( $mime_type, 'font/' ) ) {
			return 'font';
		}
		if ( in_array( $kind, array( 'json', 'markdown' ), true ) ) {
			return 'data';
		}

		return 'asset';
	}

	/**
	 * Normalize CSS/JS intent metadata.
	 */
	private function normalize_intent( string $intent, string $kind, string $role ): string {
		$intent = sanitize_key( $intent );
		if ( '' !== $intent ) {
			return $intent;
		}
		if ( 'css' === $kind || 'stylesheet' === $role ) {
			return 'style';
		}
		if ( 'js' === $kind || 'script' === $role ) {
			return 'behavior';
		}

		return '';
	}

	/**
	 * Detect binary payloads conservatively.
	 */
	private function looks_binary( string $content ): bool {
		return str_contains( $content, "\0" );
	}

	/**
	 * Return whether a MIME type should be treated as binary in result manifests.
	 */
	private function is_binary_mime_type( string $mime_type ): bool {
		if ( str_starts_with( $mime_type, 'text/' ) ) {
			return false;
		}

		return ! in_array( $mime_type, array( 'application/json', 'application/javascript', 'image/svg+xml' ), true );
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
			'mdx'      => 'mdx',
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
	 * @param  array<int,array{kind:string}> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_kind( array $files ): array {
		return $this->count_files_by_field( $files, 'kind' );
	}

	/**
	 * Count normalized files by a manifest field.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_field( array $files, string $field ): array {
		$counts = array();
		foreach ( $files as $file ) {
			$value = isset( $file[ $field ] ) ? (string) $file[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Split simple YAML frontmatter from a content document.
	 *
	 * @return array{frontmatter:array<string,mixed>,body:string}
	 */
	private function parse_frontmatter( string $content ): array {
		if ( ! preg_match( '/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches ) ) {
			return array(
				'frontmatter' => array(),
				'body'        => $content,
			);
		}

		$frontmatter       = array();
		$frontmatter_lines = preg_split( '/\R/', trim( $matches[1] ) );
		foreach ( false === $frontmatter_lines ? array() : $frontmatter_lines as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair ) ) {
				continue;
			}

			$value = trim( $pair[2] );
			$value = trim( $value, " \t\n\r\0\x0B\"'" );
			if ( preg_match( '/^\[(.*)\]$/', $value, $list ) ) {
				$value = array_values( array_filter( array_map( static fn ( string $item ): string => trim( $item, " \t\n\r\0\x0B\"'" ), explode( ',', $list[1] ) ), static fn ( string $item ): bool => '' !== $item ) );
			}

			$frontmatter[ sanitize_key( $pair[1] ) ] = $value;
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => substr( $content, strlen( $matches[0] ) ),
		);
	}

	/**
	 * Extract MDX imports and JSX component references while producing Markdown-compatible text.
	 *
	 * @param  array<string,mixed>                         $file     Source file.
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array{markdown_body:string,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function extract_mdx_semantics( string $body, array $file, array $artifact ): array {
		$imports     = $this->extract_mdx_imports( $body );
		$components  = array();
		$diagnostics = array();

		if ( preg_match_all( '/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$import    = $imports[ $name ] ?? null;
				$resolved  = is_array( $import ) ? $this->resolve_component_import( (string) $import['path'], (string) $file['path'], $artifact ) : '';
				$component = array(
					'name'        => $name,
					'source'      => $file['path'],
					'signal'      => 'mdx-jsx',
					'occurrences' => ( $components[ $name ]['occurrences'] ?? 0 ) + 1,
					'provenance'  => array( 'source_path' => $file['path'] ),
				);

				if ( is_array( $import ) ) {
					$component['import_path'] = $import['path'];
				}
				if ( '' !== $resolved ) {
					$component['resolved_path'] = $resolved;
				}

				$components[ $name ] = $component;

				if ( ! is_array( $import ) ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_component_unresolved',
						'warning',
						'MDX component reference has no matching import.',
						array(
							'path'      => $file['path'],
							'component' => $name,
						)
					);
				} elseif ( '' === $resolved && str_starts_with( (string) $import['path'], '.' ) ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_import_unresolved',
						'warning',
						'MDX component import could not be linked to a generated source file.',
						array(
							'path'        => $file['path'],
							'component'   => $name,
							'import_path' => $import['path'],
						)
					);
				}
			}
		}

		$markdown_body = preg_replace( '/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body ) ?? $body;
		$markdown_body = preg_replace( '/^\s*export\s+[^\r\n]+\s*$/m', '', $markdown_body ) ?? $markdown_body;
		$markdown_body = preg_replace( '/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdown_body ) ?? $markdown_body;
		$markdown_body = preg_replace( '/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdown_body ) ?? $markdown_body;

		return array(
			'markdown_body' => trim( $markdown_body ),
			'components'    => array_values( $components ),
			'diagnostics'   => $this->dedupe_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Extract default and named import aliases from simple MDX import lines.
	 *
	 * @return array<string,array{path:string}>
	 */
	private function extract_mdx_imports( string $body ): array {
		$imports = array();
		if ( ! preg_match_all( '/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER ) ) {
			return $imports;
		}

		foreach ( $matches as $match ) {
			$clause = trim( $match[1] );
			$path   = $match[2];
			if ( preg_match( '/^([A-Z][A-Za-z0-9_]*)/', $clause, $default ) ) {
				$imports[ $default[1] ] = array( 'path' => $path );
			}
			if ( preg_match( '/\{([^}]+)\}/', $clause, $named ) ) {
				foreach ( explode( ',', $named[1] ) as $name ) {
					$parts = preg_split( '/\s+as\s+/i', trim( $name ) );
					$parts = false === $parts ? array() : $parts;
					$alias = trim( (string) end( $parts ) );
					if ( preg_match( '/^[A-Z][A-Za-z0-9_]*$/', $alias ) ) {
						$imports[ $alias ] = array( 'path' => $path );
					}
				}
			}
		}

		return $imports;
	}

	/**
	 * Detect top-level component declarations in generated JSX/TSX files.
	 *
	 * @param  array<string,mixed> $file Normalized file.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_jsx_file_components( array $file ): array {
		$components = array();
		$content    = (string) ( $file['content'] ?? '' );

		if ( preg_match_all( '/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$components[ $name ] = true;
			}
		}

		if ( preg_match_all( '/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$components[ $name ] = true;
			}
		}

		return array_map(
			fn ( string $name ): array => array(
				'name'        => $name,
				'source'      => (string) ( $file['path'] ?? '' ),
				'signal'      => 'jsx-component-file',
				'occurrences' => 1,
				'provenance'  => array( 'source_path' => (string) ( $file['path'] ?? '' ) ),
			),
			array_keys( $components )
		);
	}

	/**
	 * Resolve a relative component import to a generated source file path when present.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 */
	private function resolve_component_import( string $import_path, string $source_path, array $artifact ): string {
		if ( ! str_starts_with( $import_path, '.' ) ) {
			return '';
		}

		$base = dirname( $source_path );
		$path = $this->normalize_relative_import_path( ( '.' === $base ? '' : $base . '/' ) . $import_path );
		if ( '' === $path ) {
			return '';
		}

		$candidates = array( $path );
		foreach ( array( 'js', 'jsx', 'ts', 'tsx', 'mdx' ) as $extension ) {
			$candidates[] = $path . '.' . $extension;
			$candidates[] = $path . '/index.' . $extension;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( in_array( $file['path'], $candidates, true ) ) {
				return (string) $file['path'];
			}
		}

		return '';
	}

	/**
	 * Normalize a relative import path after joining it to the importing file.
	 */
	private function normalize_relative_import_path( string $path ): string {
		$segments = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Read a scalar frontmatter value with aliases.
	 *
	 * @param array<string,mixed> $frontmatter Frontmatter map.
	 * @param array<int,string>   $keys        Keys in priority order.
	 */
	private function frontmatter_string( array $frontmatter, array $keys, string $fallback ): string {
		foreach ( $keys as $key ) {
			if ( isset( $frontmatter[ $key ] ) && is_scalar( $frontmatter[ $key ] ) && '' !== trim( (string) $frontmatter[ $key ] ) ) {
				return (string) $frontmatter[ $key ];
			}
		}

		return $fallback;
	}

	/**
	 * Extract common taxonomy hints from frontmatter.
	 *
	 * @param  array<string,mixed> $frontmatter Frontmatter map.
	 * @return array<string,mixed>
	 */
	private function frontmatter_taxonomies( array $frontmatter ): array {
		$taxonomies = array();
		foreach ( array( 'category', 'categories', 'tag', 'tags' ) as $key ) {
			if ( isset( $frontmatter[ $key ] ) ) {
				$taxonomies[ $key ] = $frontmatter[ $key ];
			}
		}

		return $taxonomies;
	}

	/**
	 * Build a stable slug from a source path.
	 */
	private function slug_from_path( string $path ): string {
		$base = preg_replace( '/\.[A-Za-z0-9]+$/', '', basename( $path ) );
		$base = '' === $base || null === $base ? 'document' : $base;
		return sanitize_key( str_replace( array( '_', '.' ), '-', $base ) );
	}

	/**
	 * Build a readable fallback title from a source path.
	 */
	private function title_from_path( string $path ): string {
		return ucwords( str_replace( '-', ' ', $this->slug_from_path( $path ) ) );
	}

	/**
	 * Build a normalized diagnostic entry.
	 *
	 * @param  array<string,mixed> $details Diagnostic details.
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
	 * @param  array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>> Diagnostics.
	 */
	private function dedupe_diagnostics( array $diagnostics ): array {
		$deduped = array();
		$seen    = array();
		foreach ( $diagnostics as $diagnostic ) {
			$details_json = wp_json_encode( $diagnostic['details'] ?? array() );
			$key          = (string) ( $diagnostic['code'] ?? '' ) . '|' . md5( false === $details_json ? '' : $details_json );
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
		return $this->file_hash_payload( $artifact['files'] );
	}

	/**
	 * Build a stable hash payload from normalized files.
	 *
	 * @param array<int,array{path:string,content:string,kind:string,bytes:int,source:string}> $files Files.
	 */
	private function file_hash_payload( array $files ): string {
		$payload = '';
		foreach ( $files as $file ) {
			$content = isset( $file['content_base64'] ) ? (string) $file['content_base64'] : (string) $file['content'];
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ( $file['mime_type'] ?? '' ) . "\0" . $content . "\0";
		}

		return $payload;
	}
}
