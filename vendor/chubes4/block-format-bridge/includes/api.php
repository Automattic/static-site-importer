<?php
/**
 * Public API.
 *
 * These functions form the public Phase 1 surface:
 *
 *   bfb_convert( $content, $from, $to, $options ) — universal conversion
 *   bfb_to_blocks( $content, $from, $options )    — block-array conversion
 *   bfb_normalize( $content, $format )      — declared-format validation
 *   bfb_analyze_blocks( $blocks )           — block quality report
 *   bfb_conversion_report( $content, $from ) — conversion quality report
 *   bfb_convert_fragment( $html, $options )  — scoped fragment conversion
 *   bfb_capabilities()                      — conversion substrate report
 *   bfb_get_adapter( $slug )                — registry lookup
 *
 * `bfb_convert()` routes through the block pivot via the adapter registry.
 * `bfb_normalize()` validates already-declared content through the dedicated
 * normalization helpers loaded from includes/normalization.php.
 *
 * @package BlockFormatBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bfb_capabilities' ) ) {
	/**
	 * Return a machine-readable report of the active conversion substrate.
	 *
	 * @return array<string, mixed>
	 */
	function bfb_capabilities(): array {
		$formats = array(
			'blocks' => array(
				'slug'        => 'blocks',
				'label'       => 'Serialized WordPress blocks',
				'adapter'     => null,
				'to_blocks'   => true,
				'from_blocks' => true,
				'pivot'       => true,
			),
		);

		foreach ( BFB_Adapter_Registry::slugs() as $slug ) {
			$adapter = bfb_get_adapter( $slug );
			if ( ! $adapter ) {
				continue;
			}

			$formats[ $slug ] = array(
				'slug'        => $slug,
				'label'       => $slug,
				'adapter'     => get_class( $adapter ),
				'to_blocks'   => true,
				'from_blocks' => true,
				'pivot'       => false,
			);
		}

		$h2bc = bfb_h2bc_capabilities();

		$block_coverage = isset( $h2bc['inventory']['block_coverage'] ) && is_array( $h2bc['inventory']['block_coverage'] )
			? $h2bc['inventory']['block_coverage']
			: array(
				'source'             => (string) ( $h2bc['inventory']['source'] ?? 'h2bc_capability_api_missing' ),
				'requires'           => (string) ( $h2bc['inventory']['requires'] ?? 'https://github.com/chubes4/html-to-blocks-converter/issues/418' ),
				'supported_blocks'   => array(),
				'unsupported_blocks' => array(),
				'classifications'    => array(),
			);

		return array(
			'bridge'         => array(
				'version' => defined( 'BFB_VERSION' ) ? BFB_VERSION : null,
				'path'    => defined( 'BFB_PATH' ) ? BFB_PATH : null,
			),
			'formats'        => $formats,
			'conversions'    => array(
				'html_to_blocks'            => array(
					'available' => (bool) $h2bc['available'],
					'provider'  => 'html-to-blocks-converter',
				),
				'source_fragment_to_blocks' => array(
					'available' => (bool) $h2bc['available'],
					'provider'  => 'block-format-bridge',
				),
			),
			'h2bc'           => $h2bc,
			'block_coverage' => $block_coverage,
			'hooks'          => array(
				'filters' => array(
					'bfb_register_format_adapter',
					'bfb_default_format',
					'bfb_skip_insert_conversion',
					'bfb_rest_supported_post_types',
					'bfb_markdown_input',
					'bfb_markdown_output',
					'bfb_html_to_blocks_args',
					'bfb_html_to_blocks_pre_result',
					'bfb_html_to_blocks_result',
					'bfb_html_to_markdown_options',
				),
				'actions' => array(
					'bfb_loaded',
					'bfb_adapters_registered',
					'bfb_diagnostic',
					'bfb_conversion_metadata',
					'bfb_materialization_request',
					'bfb_insert_conversion_measured',
					'bfb_html_to_markdown_converter',
				),
			),
			'abilities'      => array(
				'block-format-bridge/get-capabilities',
				'block-format-bridge/convert',
				'block-format-bridge/convert-fragment',
				'block-format-bridge/normalize',
			),
		);
	}
}

if ( ! function_exists( 'bfb_convert_fragment' ) ) {
	/**
	 * Convert a standalone source fragment to editor-valid block markup.
	 *
	 * This generic contract is intentionally small: callers pass a localized
	 * HTML fragment plus optional provenance hints, and BFB returns serialized
	 * block markup with diagnostics scoped to that fragment. Full-document
	 * conversion remains on bfb_convert() / bfb_conversion_report().
	 *
	 * Supported provenance option keys: source_id, source_selector, region_id,
	 * label. The values are copied into the returned scope and forwarded through
	 * conversion context for substrate integrations that can use them.
	 *
	 * @param string               $html    Standalone HTML fragment.
	 * @param array<string, mixed> $options Per-call conversion and provenance options.
	 * @return array<string, mixed> Fragment conversion envelope.
	 */
	function bfb_convert_fragment( string $html, array $options = array() ): array {
		$scope = bfb_normalize_fragment_scope( $options );

		$conversion_options = $options;
		$context            = isset( $conversion_options['context'] ) && is_array( $conversion_options['context'] ) ? $conversion_options['context'] : array();
		$context            = array_merge(
			$context,
			array(
				'conversion_scope' => 'fragment',
				'source_fragment'  => $scope,
			)
		);

		$conversion_options['context'] = $context;

		$report     = bfb_conversion_report( $html, 'html', $conversion_options );
		$serialized = isset( $report['serialized_blocks'] ) && is_string( $report['serialized_blocks'] ) ? $report['serialized_blocks'] : '';
		$blocks     = '' !== $serialized ? parse_blocks( $serialized ) : array();
		$status     = isset( $report['status'] ) ? (string) $report['status'] : 'failed';

		$report['scope'] = $scope;
		if ( isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ) {
			$report['diagnostics'] = bfb_scope_fragment_diagnostics( $report['diagnostics'], $scope );
		}

		return array(
			'success'           => 'failed' !== $status,
			'status'            => $status,
			'from'              => 'html',
			'to'                => 'blocks',
			'scope'             => $scope,
			'content'           => $serialized,
			'serialized_blocks' => $serialized,
			'blocks'            => $blocks,
			'diagnostics'       => isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array(),
			'provenance'        => array(
				'scope'        => $scope,
				'source_bytes' => strlen( $html ),
				'source_hash'  => hash( 'sha256', $html ),
			),
			'report'            => $report,
		);
	}
}

if ( ! function_exists( 'bfb_h2bc_capabilities' ) ) {
	/**
	 * Return availability metadata for the bundled or standalone h2bc substrate.
	 *
	 * @return array<string, mixed>
	 */
	function bfb_h2bc_capabilities(): array {
		$handler = null;
		if ( function_exists( '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler' ) ) {
			$handler = '\BlockFormatBridge\Vendor\html_to_blocks_raw_handler';
		} elseif ( function_exists( 'html_to_blocks_raw_handler' ) ) {
			$handler = 'html_to_blocks_raw_handler';
		}

		$path = null;
		if ( defined( 'HTML_TO_BLOCKS_CONVERTER_PATH' ) ) {
			$path = HTML_TO_BLOCKS_CONVERTER_PATH;
		} elseif ( $handler ) {
			$reflection = new ReflectionFunction( $handler );
			$file       = $reflection->getFileName();
			$path       = is_string( $file ) ? dirname( $file ) . '/' : null;
		}

		$version = null;
		if ( $path ) {
			$library = trailingslashit( $path ) . 'library.php';
			if ( is_readable( $library ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package metadata read.
				$source = file_get_contents( $library );
				if ( is_string( $source ) && preg_match( "/html_to_blocks_library_version\s*=\s*'([^']+)'/", $source, $match ) ) {
					$version = $match[1];
				}
			}
		}

		$capability_function = bfb_h2bc_capability_function();
		$inventory           = array(
			'source'   => null !== $capability_function ? 'h2bc_capabilities' : 'h2bc_capability_api_missing',
			'requires' => null !== $capability_function ? null : 'https://github.com/chubes4/html-to-blocks-converter/issues/418',
		);

		if ( null !== $capability_function ) {
			$capability_report = $capability_function();
			if ( is_array( $capability_report ) ) {
				$inventory = bfb_normalize_h2bc_inventory( $capability_report );
				if ( isset( $inventory['version'] ) && is_string( $inventory['version'] ) && '' !== $inventory['version'] ) {
					$version = $inventory['version'];
				}
			}
		}

		return array(
			'available'      => null !== $handler,
			'version'        => $version,
			'path'           => $path,
			'raw_handler'    => $handler,
			'capability_api' => $capability_function,
			'inventory'      => $inventory,
		);
	}
}

if ( ! function_exists( 'bfb_h2bc_capability_function' ) ) {
	/**
	 * Resolve h2bc's public capability function when the active substrate exposes one.
	 *
	 * @return callable-string|null Callable function name, or null when h2bc lacks the API.
	 */
	function bfb_h2bc_capability_function(): ?string {
		$candidates = array(
			'\BlockFormatBridge\Vendor\html_to_blocks_capabilities',
			'html_to_blocks_capabilities',
		);
		$defined    = get_defined_functions();
		$functions  = array_map( 'strtolower', $defined['user'] );

		foreach ( $candidates as $candidate ) {
			if ( in_array( strtolower( ltrim( $candidate, '\\' ) ), $functions, true ) ) {
				/** @var callable-string $candidate */
				return $candidate;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'bfb_normalize_h2bc_inventory' ) ) {
	/**
	 * Normalize h2bc-owned capability data into BFB's public capability shape.
	 *
	 * @param array<string, mixed> $report h2bc capability report.
	 * @return array<string, mixed>
	 */
	function bfb_normalize_h2bc_inventory( array $report ): array {
		$block_coverage = isset( $report['block_coverage'] ) && is_array( $report['block_coverage'] ) ? $report['block_coverage'] : array();
		$transforms     = isset( $report['transforms'] ) && is_array( $report['transforms'] ) ? $report['transforms'] : array();

		$supported_blocks   = bfb_h2bc_report_list( $report, $block_coverage, 'supported_blocks' );
		$unsupported_blocks = bfb_h2bc_report_list( $report, $block_coverage, 'unsupported_blocks' );
		$classifications    = bfb_h2bc_report_array( $report, $block_coverage, 'classifications' );
		$families           = bfb_h2bc_report_list( $report, $transforms, 'families' );

		return array(
			'source'             => 'h2bc_capabilities',
			'version'            => isset( $report['version'] ) && is_scalar( $report['version'] ) ? (string) $report['version'] : null,
			'handler'            => isset( $report['handler'] ) && is_scalar( $report['handler'] ) ? (string) $report['handler'] : null,
			'transform_families' => $families,
			'block_coverage'     => array(
				'source'             => 'h2bc_capabilities',
				'supported_blocks'   => $supported_blocks,
				'unsupported_blocks' => $unsupported_blocks,
				'classifications'    => $classifications,
			),
			'raw'                => $report,
		);
	}
}

if ( ! function_exists( 'bfb_h2bc_report_list' ) ) {
	/**
	 * Return a normalized scalar list from possible report locations.
	 *
	 * @param array<string, mixed> $primary   Primary report data.
	 * @param array<string, mixed> $secondary Secondary report data.
	 * @param string               $key       Field key.
	 * @return array<int, string>
	 */
	function bfb_h2bc_report_list( array $primary, array $secondary, string $key ): array {
		$values = array();
		if ( isset( $primary[ $key ] ) && is_array( $primary[ $key ] ) ) {
			$values = $primary[ $key ];
		} elseif ( isset( $secondary[ $key ] ) && is_array( $secondary[ $key ] ) ) {
			$values = $secondary[ $key ];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $value ): string {
						return is_scalar( $value ) ? (string) $value : '';
					},
					$values
				),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}
}

if ( ! function_exists( 'bfb_h2bc_report_array' ) ) {
	/**
	 * Return an array field from possible report locations.
	 *
	 * @param array<string, mixed> $primary   Primary report data.
	 * @param array<string, mixed> $secondary Secondary report data.
	 * @param string               $key       Field key.
	 * @return array<string, mixed>
	 */
	function bfb_h2bc_report_array( array $primary, array $secondary, string $key ): array {
		if ( isset( $primary[ $key ] ) && is_array( $primary[ $key ] ) ) {
			return $primary[ $key ];
		}

		if ( isset( $secondary[ $key ] ) && is_array( $secondary[ $key ] ) ) {
			return $secondary[ $key ];
		}

		return array();
	}
}

if ( ! function_exists( 'bfb_get_adapter' ) ) {
	/**
	 * Resolve an adapter by slug.
	 *
	 * @param string $slug Adapter slug (e.g. 'html', 'markdown').
	 * @return BFB_Format_Adapter|null
	 */
	function bfb_get_adapter( string $slug ): ?BFB_Format_Adapter {
		return BFB_Adapter_Registry::get( $slug );
	}
}

if ( ! function_exists( 'bfb_to_blocks' ) ) {
	/**
	 * Convert content into parse_blocks()-compatible block arrays.
	 *
	 * This is the compiler-facing helper for callers that need the block-array
	 * pivot directly instead of serialized block markup.
	 *
	 * @param string               $content Source content.
	 * @param string               $from    Source format slug.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return array<int|string, array{blockName: string|null, attrs: array, innerBlocks: array<array>, innerHTML: string, innerContent: array}> Block array. Empty array on unsupported source.
	 */
	function bfb_to_blocks( string $content, string $from, array $options = array() ): array {
		if ( 'blocks' === $from ) {
			return parse_blocks( $content );
		}

		$from_adapter = bfb_get_adapter( $from );
		if ( ! $from_adapter ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches existing public conversion failure diagnostics.
			error_log( sprintf( '[Block Format Bridge] No adapter registered for source format "%s".', $from ) );
			return array();
		}

		return $from_adapter->to_blocks( $content, $options );
	}
}

if ( ! function_exists( 'bfb_convert' ) ) {
	/**
	 * Convert content from one format to another.
	 *
	 * Routing always passes through the block pivot:
	 *
	 *   $blocks = bfb_to_blocks( $content, $from, $options );
	 *   return    $to_adapter->from_blocks( $blocks, $options );
	 *
	 * Special cases:
	 *   - $from === $to                → returns $content unchanged
	 *   - $from === 'blocks'           → skips the to_blocks() hop and
	 *                                    treats $content as serialized
	 *                                    block markup, parsing it first
	 *   - $to === 'blocks'             → returns serialized block markup
	 *
	 * @param string               $content Source content.
	 * @param string               $from    Source format slug.
	 * @param string               $to      Target format slug.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return string Converted content. Empty string on failure.
	 */
	function bfb_convert( string $content, string $from, string $to, array $options = array() ): string {
		if ( $from === $to ) {
			return $content;
		}

		$blocks = bfb_to_blocks( $content, $from, $options );
		if ( array() === $blocks && 'blocks' !== $from ) {
			return '';
		}

		// Render the intermediate into the target format.
		if ( 'blocks' === $to ) {
			return serialize_blocks( $blocks );
		}

		$to_adapter = bfb_get_adapter( $to );
		if ( ! $to_adapter ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Matches existing public conversion failure diagnostics.
			error_log( sprintf( '[Block Format Bridge] No adapter registered for target format "%s".', $to ) );
			return '';
		}

		return $to_adapter->from_blocks( $blocks, $options );
	}
}

if ( ! function_exists( 'bfb_filter_html_to_blocks_result' ) ) {
	/**
	 * Filter block arrays returned by the HTML conversion substrate.
	 *
	 * This hook lets generic conversion substrate integrations expose a final
	 * block array without coupling BFB to any filesystem or importer behavior.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Converted block list.
	 * @param string                           $content Source HTML.
	 * @param array<string, mixed>             $options Per-call conversion options.
	 * @param array<string, mixed>             $args    Raw handler arguments.
	 * @return array<int, array<string, mixed>> Filtered block list.
	 */
	function bfb_filter_html_to_blocks_result( array $blocks, string $content, array $options, array $args ): array {
		/**
		 * Filters block arrays returned by the HTML conversion substrate.
		 *
		 * @since 0.5.0
		 *
		 * @param array<int, array<string, mixed>> $blocks  Converted block list.
		 * @param string                           $content Source HTML.
		 * @param array<string, mixed>             $options Per-call conversion options.
		 * @param array<string, mixed>             $args    Raw handler arguments.
		 */
		return (array) apply_filters( 'bfb_html_to_blocks_result', $blocks, $content, $options, $args );
	}
}

if ( ! function_exists( 'bfb_normalize_fragment_scope' ) ) {
	/**
	 * Normalize source-fragment provenance into a stable public shape.
	 *
	 * @param array<string, mixed> $options Fragment conversion options.
	 * @return array<string, string>
	 */
	function bfb_normalize_fragment_scope( array $options ): array {
		$scope = array( 'type' => 'fragment' );
		$keys  = array( 'source_id', 'source_selector', 'region_id', 'label' );

		foreach ( $keys as $key ) {
			if ( isset( $options[ $key ] ) && is_scalar( $options[ $key ] ) ) {
				$value = trim( (string) $options[ $key ] );
				if ( '' !== $value ) {
					$scope[ $key ] = $value;
				}
			}
		}

		if ( isset( $options['scope'] ) && is_array( $options['scope'] ) ) {
			foreach ( $keys as $key ) {
				if ( isset( $options['scope'][ $key ] ) && is_scalar( $options['scope'][ $key ] ) && ! isset( $scope[ $key ] ) ) {
					$value = trim( (string) $options['scope'][ $key ] );
					if ( '' !== $value ) {
						$scope[ $key ] = $value;
					}
				}
			}
		}

		return $scope;
	}
}

if ( ! function_exists( 'bfb_scope_fragment_diagnostics' ) ) {
	/**
	 * Attach source-fragment scope to every diagnostic entry.
	 *
	 * @param array<int, array<string, mixed>> $diagnostics Diagnostics.
	 * @param array<string, string>            $scope       Fragment scope.
	 * @return array<int, array<string, mixed>> Scoped diagnostics.
	 */
	function bfb_scope_fragment_diagnostics( array $diagnostics, array $scope ): array {
		foreach ( $diagnostics as $index => $diagnostic ) {
			$details          = isset( $diagnostic['details'] ) && is_array( $diagnostic['details'] ) ? $diagnostic['details'] : array();
			$details['scope'] = $scope;

			$diagnostic['scope']   = $scope;
			$diagnostic['details'] = $details;
			$diagnostics[ $index ] = $diagnostic;
		}

		return $diagnostics;
	}
}

if ( ! function_exists( 'bfb_analyze_blocks' ) ) {
	/**
	 * Analyze a parsed block tree for conversion quality signals.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed block list.
	 * @return array<string, mixed> Quality report.
	 */
	function bfb_analyze_blocks( array $blocks ): array {
		$report = array(
			'total_blocks'     => 0,
			'block_counts'     => array(),
			'core_html_blocks' => 0,
			'fallbacks'        => array(),
		);

		bfb_analyze_block_list( $blocks, $report );

		return $report;
	}
}

if ( ! function_exists( 'bfb_conversion_report' ) ) {
	/**
	 * Convert content to blocks and return quality metrics alongside the result.
	 *
	 * @param string               $content Source content.
	 * @param string               $from    Source format slug.
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return array<string, mixed> Conversion report.
	 */
	function bfb_conversion_report( string $content, string $from, array $options = array() ): array {
		$fallback_events          = array();
		$conversion_metadata      = array();
		$materialization_requests = array();
		$fallback_listener        = static function ( string $html, array $context, array $block ) use ( &$fallback_events ): void {
			$fallback_events[] = bfb_normalize_html_fallback_diagnostic( $html, $context, $block );
		};
		$metadata_listener        = static function ( array $metadata ) use ( &$conversion_metadata, &$materialization_requests ): void {
			$normalized = bfb_normalize_conversion_metadata( $metadata );
			if ( array() === $normalized ) {
				return;
			}

			$conversion_metadata[] = $normalized;
			if ( 'materialization_request' === ( $normalized['type'] ?? '' ) ) {
				$materialization_requests[] = $normalized;
			}
		};
		$request_listener         = static function ( array $request ) use ( &$conversion_metadata, &$materialization_requests ): void {
			$normalized = bfb_normalize_conversion_metadata( array_merge( array( 'type' => 'materialization_request' ), $request ) );
			if ( array() === $normalized ) {
				return;
			}

			$conversion_metadata[]      = $normalized;
			$materialization_requests[] = $normalized;
		};

		add_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10, 3 );
		add_action( 'html_to_blocks_conversion_metadata', $metadata_listener, 10, 1 );
		add_action( 'html_to_blocks_materialization_request', $request_listener, 10, 1 );
		add_action( 'bfb_conversion_metadata', $metadata_listener, 10, 1 );
		add_action( 'bfb_materialization_request', $request_listener, 10, 1 );
		try {
			$blocks = bfb_to_blocks( $content, $from, $options );
		} finally {
			remove_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10 );
			remove_action( 'html_to_blocks_conversion_metadata', $metadata_listener, 10 );
			remove_action( 'html_to_blocks_materialization_request', $request_listener, 10 );
			remove_action( 'bfb_conversion_metadata', $metadata_listener, 10 );
			remove_action( 'bfb_materialization_request', $request_listener, 10 );
		}

		$analysis                                  = bfb_analyze_blocks( $blocks );
		$analysis['from']                          = $from;
		$analysis['source_bytes']                  = strlen( $content );
		$analysis['source_text_bytes']             = bfb_text_bytes( $content );
		$analysis['fallback_events']               = $fallback_events;
		$analysis['fallback_diagnostics']          = ! empty( $fallback_events ) ? $fallback_events : $analysis['fallbacks'];
		$analysis['fallback_event_count']          = count( $fallback_events );
		$analysis['conversion_metadata']           = $conversion_metadata;
		$analysis['materialization_requests']      = $materialization_requests;
		$analysis['materialization_request_count'] = count( $materialization_requests );
		$analysis['serialized_blocks']             = serialize_blocks( $blocks );
		$analysis['converted_text_bytes']          = bfb_text_bytes( $analysis['serialized_blocks'] );
		$analysis['text_retention_ratio']          = bfb_text_retention_ratio( (int) $analysis['source_text_bytes'], (int) $analysis['converted_text_bytes'] );

		$diagnostics = bfb_build_conversion_diagnostics( $analysis );

		$analysis['status']         = $diagnostics['status'];
		$analysis['diagnostics']    = $diagnostics['diagnostics'];
		$analysis['agent_guidance'] = $diagnostics['agent_guidance'];

		return $analysis;
	}
}

if ( ! function_exists( 'bfb_build_conversion_diagnostics' ) ) {
	/**
	 * Build structured, agent-safe diagnostics from a conversion report.
	 *
	 * The status values intentionally distinguish native conversion, explicit
	 * core/html fallback, and suspected text loss so automation can react to the
	 * right evidence without being nudged toward manually authoring wp:html blocks.
	 *
	 * @param array<string, mixed> $report Conversion report data.
	 * @return array{status:string,diagnostics:array<int,array<string,mixed>>,agent_guidance:string}
	 */
	function bfb_build_conversion_diagnostics( array $report ): array {
		$status      = 'success_native';
		$diagnostics = array();
		$guidance    = 'Conversion completed with native blocks. Continue using the conversion layer for future writes.';

		$total_blocks          = isset( $report['total_blocks'] ) ? (int) $report['total_blocks'] : 0;
		$core_html_blocks      = isset( $report['core_html_blocks'] ) ? (int) $report['core_html_blocks'] : 0;
		$fallback_event_count  = isset( $report['fallback_event_count'] ) ? (int) $report['fallback_event_count'] : 0;
		$materialization_count = isset( $report['materialization_request_count'] ) ? (int) $report['materialization_request_count'] : 0;
		$source_bytes          = isset( $report['source_bytes'] ) ? (int) $report['source_bytes'] : 0;
		$source_text_bytes     = isset( $report['source_text_bytes'] ) ? (int) $report['source_text_bytes'] : 0;
		$converted_text_bytes  = isset( $report['converted_text_bytes'] ) ? (int) $report['converted_text_bytes'] : 0;
		$text_retention_ratio  = isset( $report['text_retention_ratio'] ) ? (float) $report['text_retention_ratio'] : 1.0;
		$has_fallback_evidence = $core_html_blocks > 0 || $fallback_event_count > 0;
		$suspected_text_loss   = ! $has_fallback_evidence && $source_text_bytes >= 200 && $text_retention_ratio < 0.5;

		if ( $source_bytes > 0 && 0 === $total_blocks ) {
			$status        = 'failed';
			$diagnostics[] = array(
				'code'     => 'conversion_failed',
				'severity' => 'error',
				'message'  => 'The source content did not produce any blocks.',
				'details'  => array(
					'source_bytes' => $source_bytes,
				),
			);
			$guidance      = 'Conversion failed. Inspect the source format and adapter availability before retrying; do not bypass conversion with manual wp:html unless raw HTML preservation is explicitly required.';
		} elseif ( $has_fallback_evidence ) {
			$fallback_diagnostics = isset( $report['fallback_diagnostics'] ) && is_array( $report['fallback_diagnostics'] ) ? $report['fallback_diagnostics'] : array();
			$status               = 'success_with_fallbacks';
			$diagnostics[]        = array(
				'code'     => 'core_html_fallback',
				'severity' => 'warning',
				'message'  => 'Conversion completed, but some fragments were preserved as core/html fallback blocks.',
				'details'  => array(
					'core_html_blocks'     => $core_html_blocks,
					'fallback_event_count' => $fallback_event_count,
					'fallback_diagnostics' => $fallback_diagnostics,
				),
			);
			$guidance             = 'Conversion completed with explicit fallback evidence. Review fallback_events and fallbacks to identify unsupported fragments; keep future writes routed through BFB unless the user explicitly wants raw HTML blocks.';
		} elseif ( $materialization_count > 0 ) {
			$status        = 'success_with_materialization_requests';
			$diagnostics[] = array(
				'code'     => 'materialization_requested',
				'severity' => 'info',
				'message'  => 'Conversion completed with downstream asset or reference materialization requests.',
				'details'  => array(
					'materialization_request_count' => $materialization_count,
				),
			);
			$guidance      = 'Conversion completed without fallback evidence, but downstream materialization is required. Review materialization_requests, write assets in the consuming layer, and replace placeholders before final output.';
		} elseif ( $suspected_text_loss ) {
			$status        = 'warning_only_suspicion';
			$diagnostics[] = array(
				'code'     => 'possible_text_loss',
				'severity' => 'warning',
				'message'  => 'Converted block text is much smaller than source text, but no explicit core/html fallback was reported.',
				'details'  => array(
					'source_text_bytes'    => $source_text_bytes,
					'converted_text_bytes' => $converted_text_bytes,
					'text_retention_ratio' => $text_retention_ratio,
				),
			);
			$guidance      = 'Potential warning-only content loss. Capture this report for the conversion substrate and retry with simpler source structure if needed; do not work around it by manually authoring wp:html blocks.';
		}

		return array(
			'status'         => $status,
			'diagnostics'    => $diagnostics,
			'agent_guidance' => $guidance,
		);
	}
}

if ( ! function_exists( 'bfb_normalize_conversion_metadata' ) ) {
	/**
	 * Normalize conversion metadata emitted by conversion substrates.
	 *
	 * BFB stores only structured, filesystem-agnostic data. Downstream consumers
	 * decide whether and where to write assets, then replace any placeholders.
	 *
	 * @param array<string, mixed> $metadata Raw metadata event.
	 * @return array<string, mixed> Normalized metadata, or empty array when invalid.
	 */
	function bfb_normalize_conversion_metadata( array $metadata ): array {
		$type = isset( $metadata['type'] ) ? sanitize_key( (string) $metadata['type'] ) : '';
		if ( '' === $type ) {
			return array();
		}

		$normalized  = array( 'type' => $type );
		$scalar_keys = array(
			'id',
			'kind',
			'source',
			'placeholder',
			'media_type',
			'filename',
			'alt',
			'label',
			'classification',
			'encoding',
		);

		foreach ( $scalar_keys as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				$normalized[ $key ] = (string) $metadata[ $key ];
			}
		}

		if ( isset( $metadata['payload'] ) && is_string( $metadata['payload'] ) ) {
			$normalized['payload']       = $metadata['payload'];
			$normalized['payload_bytes'] = strlen( $metadata['payload'] );
		} elseif ( isset( $metadata['payload_bytes'] ) && is_numeric( $metadata['payload_bytes'] ) ) {
			$normalized['payload_bytes'] = max( 0, (int) $metadata['payload_bytes'] );
		}

		if ( isset( $metadata['metadata'] ) && is_array( $metadata['metadata'] ) ) {
			$normalized['metadata'] = $metadata['metadata'];
		}

		if ( isset( $metadata['replacement'] ) && is_array( $metadata['replacement'] ) ) {
			$normalized['replacement'] = $metadata['replacement'];
		}

		return $normalized;
	}
}

if ( ! function_exists( 'bfb_normalize_html_fallback_diagnostic' ) ) {
	/**
	 * Normalize an unsupported HTML fallback event into a consumer-safe shape.
	 *
	 * @param string               $html    Unsupported HTML fragment.
	 * @param array<string, mixed> $context Fallback context emitted by the converter.
	 * @param array<string, mixed> $block   Generated fallback block.
	 * @return array<string, mixed> Structured fallback diagnostic.
	 */
	function bfb_normalize_html_fallback_diagnostic( string $html, array $context = array(), array $block = array() ): array {
		$signature            = bfb_extract_html_fragment_signature( $html );
		$context_tag          = isset( $context['tag_name'] ) && is_scalar( $context['tag_name'] ) ? strtoupper( (string) $context['tag_name'] ) : '';
		$tag_name             = '' !== $context_tag ? $context_tag : strtoupper( $signature['source_tag'] );
		$reason_code          = isset( $context['reason'] ) && is_scalar( $context['reason'] ) ? sanitize_key( (string) $context['reason'] ) : '';
		$generated_block_type = isset( $block['blockName'] ) && is_scalar( $block['blockName'] ) ? (string) $block['blockName'] : '';

		return array(
			'code'                 => 'unsupported_html_fallback',
			'reason_code'          => '' !== $reason_code ? $reason_code : 'unknown',
			'reason'               => '' !== $reason_code ? $reason_code : 'unknown',
			'source_tag'           => '' !== $tag_name ? strtolower( $tag_name ) : '',
			'tag_name'             => $tag_name,
			'attributes'           => $signature['attributes'],
			'classes'              => $signature['classes'],
			'occurrence'           => isset( $context['occurrence'] ) && is_numeric( $context['occurrence'] ) ? (int) $context['occurrence'] : null,
			'bytes'                => strlen( $html ),
			'preview'              => bfb_preview_html( $html ),
			'generated_block_type' => $generated_block_type,
			'block_name'           => $generated_block_type,
		);
	}
}

if ( ! function_exists( 'bfb_extract_html_fragment_signature' ) ) {
	/**
	 * Extract a compact tag/attribute signature from an HTML fragment.
	 *
	 * @param string $html HTML fragment.
	 * @return array{source_tag:string,attributes:array<string,string>,classes:array<int,string>}
	 */
	function bfb_extract_html_fragment_signature( string $html ): array {
		$signature = array(
			'source_tag' => '',
			'attributes' => array(),
			'classes'    => array(),
		);

		if ( preg_match( '/^\s*<\s*([a-z0-9:-]+)\b([^>]*)>/is', $html, $matches ) ) {
			$signature['source_tag'] = strtolower( $matches[1] );
			$signature['attributes'] = bfb_parse_html_fallback_attributes( $matches[2] );
		}

		$class_attr              = isset( $signature['attributes']['class'] ) ? $signature['attributes']['class'] : '';
		$classes                 = preg_split( '/\s+/', trim( $class_attr ) );
		$signature['classes']    = is_array( $classes ) ? array_values( array_filter( $classes ) ) : array();
		$signature['attributes'] = array_slice( $signature['attributes'], 0, 20, true );

		return $signature;
	}
}

if ( ! function_exists( 'bfb_normalize_html_fallback_attributes' ) ) {
	/**
	 * Keep useful, non-executable source attributes for fallback diagnostics.
	 *
	 * @param array<string, mixed> $attributes Raw HTML attributes.
	 * @return array<string, string> Normalized attributes.
	 */
	function bfb_normalize_html_fallback_attributes( array $attributes ): array {
		$normalized = array();

		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );
			if ( '' === $name || preg_match( '/^on/i', $name ) ) {
				continue;
			}

			if ( ! in_array( $name, array( 'id', 'class', 'src', 'href', 'name', 'type', 'role', 'title', 'aria-label' ), true ) && 0 !== strpos( $name, 'data-' ) ) {
				continue;
			}

			$normalized[ $name ] = substr( is_scalar( $value ) ? (string) $value : '', 0, 240 );
		}

		return $normalized;
	}
}

if ( ! function_exists( 'bfb_parse_html_fallback_attributes' ) ) {
	/**
	 * Parse attributes from an opening HTML tag for fallback diagnostics.
	 *
	 * @param string $attribute_html Raw attribute text from the opening tag.
	 * @return array<string, string> Normalized attributes.
	 */
	function bfb_parse_html_fallback_attributes( string $attribute_html ): array {
		$attributes = array();
		if ( ! preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $attribute_html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$name  = strtolower( $match[1] );
			$value = isset( $match[2] ) ? trim( $match[2], " \t\n\r\0\x0B\"'" ) : '';

			$attributes[ $name ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return bfb_normalize_html_fallback_attributes( $attributes );
	}
}

if ( ! function_exists( 'bfb_text_retention_ratio' ) ) {
	/**
	 * Calculate the ratio of converted text bytes to source text bytes.
	 *
	 * @param int $source_text_bytes Source text byte count.
	 * @param int $converted_text_bytes Converted text byte count.
	 * @return float Retention ratio in the range 0..1+.
	 */
	function bfb_text_retention_ratio( int $source_text_bytes, int $converted_text_bytes ): float {
		if ( $source_text_bytes <= 0 ) {
			return 1.0;
		}

		return round( $converted_text_bytes / $source_text_bytes, 4 );
	}
}

if ( ! function_exists( 'bfb_text_bytes' ) ) {
	/**
	 * Count visible text bytes in content for loss-suspicion diagnostics.
	 *
	 * @param string $content Source or serialized block content.
	 * @return int Visible text byte count.
	 */
	function bfb_text_bytes( string $content ): int {
		$text = wp_strip_all_tags( $content );
		$text = preg_replace( '/\s+/', ' ', trim( (string) $text ) );

		return strlen( is_string( $text ) ? $text : '' );
	}
}

if ( ! function_exists( 'bfb_analyze_block_list' ) ) {
	/**
	 * Walk parsed blocks and populate a quality report.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed block list.
	 * @param array<string, mixed>                    $report Report being populated.
	 * @param array<int, int|string>                  $path   Current block path.
	 * @return void
	 */
	function bfb_analyze_block_list( array $blocks, array &$report, array $path = array() ): void {
		foreach ( $blocks as $index => $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			if ( '' !== $name ) {
				++$report['total_blocks'];
				$report['block_counts'][ $name ] = isset( $report['block_counts'][ $name ] ) ? (int) $report['block_counts'][ $name ] + 1 : 1;
			}

			$block_path = array_merge( $path, array( $index ) );
			if ( 'core/html' === $name ) {
				$html = '';
				if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
					$html = $block['attrs']['content'];
				} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
					$html = $block['innerHTML'];
				}

				++$report['core_html_blocks'];
				$fallback         = bfb_normalize_html_fallback_diagnostic(
					$html,
					array(
						'reason'   => 'core_html_block',
						'tag_name' => '',
					),
					$block
				);
				$fallback['path'] = implode( '.', $block_path );

				$report['fallbacks'][] = $fallback;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				bfb_analyze_block_list( $block['innerBlocks'], $report, $block_path );
			}
		}
	}
}

if ( ! function_exists( 'bfb_preview_html' ) ) {
	/**
	 * Build a compact one-line preview for reports.
	 *
	 * @param string $html HTML fragment.
	 * @return string Preview text.
	 */
	function bfb_preview_html( string $html ): string {
		$preview = preg_replace( '/\s+/', ' ', trim( $html ) );
		return substr( is_string( $preview ) ? $preview : trim( $html ), 0, 700 );
	}
}

if ( ! function_exists( 'bfb_render_post' ) ) {
	/**
	 * Render a post's `post_content` in the requested format.
	 *
	 * Reads the raw `post_content` and routes it through `bfb_convert`
	 * with `'blocks'` as the source format (so dynamic blocks render
	 * via their server-side callbacks).
	 *
	 * Returns an empty string when the post is missing, the post type
	 * does not support content, or the requested format is unknown.
	 *
	 * @param int|WP_Post          $post    Post ID or WP_Post.
	 * @param string               $format  Target format slug (e.g. 'html', 'markdown').
	 * @param array<string, mixed> $options Per-call conversion options.
	 * @return string Rendered content. Empty string on failure.
	 */
	function bfb_render_post( $post, string $format, array $options = array() ): string {
		$post_obj = get_post( $post );
		if ( ! $post_obj ) {
			return '';
		}

		$content = (string) $post_obj->post_content;
		if ( '' === $content ) {
			return '';
		}

		// post_content is always serialised block markup (or raw HTML on
		// pre-Gutenberg installs); route through the 'blocks' source so
		// dynamic blocks resolve.
		return bfb_convert( $content, 'blocks', $format, $options );
	}
}
