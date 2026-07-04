<?php
/**
 * Generated-theme block-document analysis and report serialization.
 *
 * Analyzes generated block-theme documents (templates/, parts/, patterns/) for
 * server-visible block-quality issues and records actionable diagnostics into the
 * import conversion report. Extracted from Static_Site_Importer_Theme_Generator as a
 * behavior-preserving decomposition slice; the generator delegates to this class and
 * passes its conversion report by reference.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyzes generated block documents and routes block-quality diagnostics into the report.
 */
class Static_Site_Importer_Block_Document_Reporter {

	/**
	 * Analyze generated block documents and record diagnostics into the conversion report.
	 *
	 * @param array<string,string> $writes    Generated theme writes keyed by absolute path.
	 * @param string               $theme_dir Generated theme directory.
	 * @param array<string,mixed>  $report    Conversion report (mutated by reference).
	 * @return void
	 */
	public static function analyze_generated_theme_block_documents( array $writes, string $theme_dir, array &$report ): void {
		foreach ( $writes as $path => $content ) {
			$relative_path = ltrim( str_replace( trailingslashit( $theme_dir ), '', $path ), '/' );
			if ( ! self::is_generated_block_document_path( $relative_path ) ) {
				continue;
			}

			$block_markup                                   = self::generated_block_document_markup( $relative_path, $content );
			$analysis                                       = self::analyze_generated_block_document( $relative_path, $block_markup, $report );
			$report['generated_theme']['block_documents'][] = $analysis;
		}
	}

	/**
	 * Determine whether a generated file should contain block markup.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @return bool
	 */
	private static function is_generated_block_document_path( string $relative_path ): bool {
		return str_starts_with( $relative_path, 'templates/' ) || str_starts_with( $relative_path, 'parts/' ) || str_starts_with( $relative_path, 'patterns/' );
	}

	/**
	 * Extract block markup from a generated block document.
	 *
	 * @param string $relative_path Theme-relative path.
	 * @param string $content       Generated file content.
	 * @return string
	 */
	private static function generated_block_document_markup( string $relative_path, string $content ): string {
		if ( str_starts_with( $relative_path, 'patterns/' ) ) {
			$parts = explode( '?>', $content, 2 );
			return trim( 2 === count( $parts ) ? $parts[1] : $content );
		}

		return trim( $content );
	}

	/**
	 * Analyze one generated block document for server-visible quality issues.
	 *
	 * Public so the theme generator can reuse it for materialized post_content analysis.
	 *
	 * @param string              $relative_path Theme-relative path.
	 * @param string              $block_markup  Block markup.
	 * @param array<string,mixed> $report        Conversion report (mutated by reference).
	 * @return array<string,mixed>
	 */
	public static function analyze_generated_block_document( string $relative_path, string $block_markup, array &$report ): array {
		$validation_method = function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ? 'wordpress_parse_blocks_serialize_blocks' : 'unavailable';
		if ( 'unavailable' === $validation_method ) {
			return array(
				'path'                   => $relative_path,
				'block_count'            => 0,
				'core_html_block_count'  => 0,
				'freeform_block_count'   => 0,
				'invalid_block_count'    => 0,
				'serialization_mismatch' => false,
				'validation_method'      => $validation_method,
				'validation_available'   => false,
			);
		}

		$blocks          = parse_blocks( $block_markup );
		$block_count     = 0;
		$core_html_count = 0;
		$freeform_count  = 0;
		$invalid_count   = 0;
		$invalid_blocks  = array();

		/** @var array<int, array<string, mixed>> $analyzed_blocks */
		$analyzed_blocks = $blocks;
		self::analyze_generated_block_list( $analyzed_blocks, $block_count, $core_html_count, $freeform_count, $invalid_count, $invalid_blocks, $report, $relative_path );

		$serialized             = serialize_blocks( $blocks );
		$serialization_mismatch = self::block_documents_differ_for_report( $block_markup, $serialized );
		$first_differing_token  = array();
		if ( $serialization_mismatch ) {
			++$invalid_count;
			$first_differing_token = self::first_differing_block_document_token( $block_markup, $serialized );
		}

		$report['quality']['core_html_block_count'] += $core_html_count;
		$report['quality']['freeform_block_count']  += $freeform_count;
		$report['quality']['invalid_block_count']   += $invalid_count;
		if ( $invalid_count > 0 ) {
			++$report['quality']['invalid_block_document_count'];
			$first_invalid_block = $invalid_blocks[0] ?? self::first_parsed_block_summary( $analyzed_blocks );
			$validation_message  = $serialization_mismatch ? 'Serialized block document differs from generated block markup.' : 'Generated block document contains parser-exposed invalid block markup.';
			$diagnostic          = array(
				'type'                      => 'invalid_block_document',
				'source'                    => $relative_path,
				'block_count'               => $block_count,
				'core_html_block_count'     => $core_html_count,
				'freeform_block_count'      => $freeform_count,
				'invalid_block_count'       => $invalid_count,
				'serialization_mismatch'    => $serialization_mismatch,
				'validation_message'        => $validation_message,
				'parser_validation_message' => $validation_message,
				'original_excerpt'          => Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( $block_markup ),
				'serialized_excerpt'        => Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( $serialized ),
			);
			if ( ! empty( $first_invalid_block ) ) {
				$diagnostic['block_name']     = $first_invalid_block['block_name'];
				$diagnostic['block_path']     = $first_invalid_block['block_path'];
				$diagnostic['attribute_path'] = $first_invalid_block['attribute_path'];
				$diagnostic['invalid_blocks'] = $invalid_blocks;
			}
			if ( ! empty( $first_differing_token ) ) {
				$diagnostic['first_differing_token'] = $first_differing_token;
			}

			$report['diagnostics'][] = $diagnostic;
		}

		return array(
			'path'                   => $relative_path,
			'block_count'            => $block_count,
			'core_html_block_count'  => $core_html_count,
			'freeform_block_count'   => $freeform_count,
			'invalid_block_count'    => $invalid_count,
			'serialization_mismatch' => $serialization_mismatch,
			'validation_method'      => $validation_method,
			'validation_available'   => true,
		);
	}

	/**
	 * Walk parsed blocks for generated-theme quality metrics.
	 *
	 * @param array<int,array<string,mixed>> $blocks          Parsed blocks.
	 * @param int                            $block_count     Total named block count.
	 * @param int                            $core_html_count HTML block count.
	 * @param int                            $freeform_count  Freeform block count.
	 * @param int                            $invalid_count   Invalid block count.
	 * @param array<int,mixed>               $invalid_blocks  Collected invalid block summaries.
	 * @param array<string,mixed>            $report          Conversion report (mutated by reference).
	 * @param string                         $source          Theme-relative source document path.
	 * @param array<int,int>                 $path            Parsed block path.
	 * @return void
	 */
	private static function analyze_generated_block_list( array $blocks, int &$block_count, int &$core_html_count, int &$freeform_count, int &$invalid_count, array &$invalid_blocks, array &$report, string $source = '', array $path = array() ): void {
		foreach ( $blocks as $index => $block ) {
			$block_path = array_merge( $path, array( $index ) );
			$name       = isset( $block['blockName'] ) ? $block['blockName'] : null;
			if ( is_string( $name ) && '' !== $name ) {
				++$block_count;
				if ( 'core/html' === $name ) {
					++$core_html_count;
					self::record_generated_core_html_block( $source, $block_path, $block, $report );
				}
				if ( 'core/freeform' === $name ) {
					++$freeform_count;
					self::record_generated_freeform_block( $source, $block_path, $block, false, $report );
				}
			} elseif ( '' !== trim( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '' ) ) {
				++$freeform_count;
				++$invalid_count;
				$invalid_blocks[] = array(
					'block_name'     => 'unparsed_html',
					'block_path'     => implode( '.', $block_path ),
					'attribute_path' => 'innerHTML',
					'reason'         => 'parser_exposed_unparsed_html',
					'html_excerpt'   => Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( (string) $block['innerHTML'] ),
				);
				self::record_generated_freeform_block( $source, $block_path, $block, true, $report );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::analyze_generated_block_list( $block['innerBlocks'], $block_count, $core_html_count, $freeform_count, $invalid_count, $invalid_blocks, $report, $source, $block_path );
			}
		}
	}

	/**
	 * Return the first named parsed block as context for document-level mismatches.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param array<int,int>                 $path   Current block path.
	 * @return array<string,string>
	 */
	private static function first_parsed_block_summary( array $blocks, array $path = array() ): array {
		foreach ( $blocks as $index => $block ) {
			$block_path = array_merge( $path, array( $index ) );
			$name       = isset( $block['blockName'] ) && is_string( $block['blockName'] ) && '' !== $block['blockName'] ? $block['blockName'] : '';
			if ( '' !== $name ) {
				return array(
					'block_name'     => $name,
					'block_path'     => implode( '.', $block_path ),
					'attribute_path' => 'document',
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$summary = self::first_parsed_block_summary( $block['innerBlocks'], $block_path );
				if ( ! empty( $summary ) ) {
					return $summary;
				}
			}
		}

		return array();
	}

	/**
	 * Record an actionable generated core/html block diagnostic.
	 *
	 * @param string              $source Theme-relative source document path.
	 * @param array<int,int>      $path   Parsed block path.
	 * @param array<string,mixed> $block  Parsed block.
	 * @param array<string,mixed> $report Conversion report (mutated by reference).
	 * @return void
	 */
	private static function record_generated_core_html_block( string $source, array $path, array $block, array &$report ): void {
		$html = '';
		if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
			$html = $block['attrs']['content'];
		} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		$report['diagnostics'][] = Static_Site_Importer_Report_Diagnostics::fallback_diagnostic_entry(
			'core_html_block',
			$source,
			$html,
			array(
				'reason' => 'generated_document_contains_core_html',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
	}

	/**
	 * Record an actionable generated freeform block diagnostic.
	 *
	 * @param string              $source     Theme-relative source document path.
	 * @param array<int,int>      $path       Parsed block path.
	 * @param array<string,mixed> $block      Parsed block.
	 * @param bool                $malformed  Whether the block parser exposed raw HTML without a block name.
	 * @param array<string,mixed> $report     Conversion report (mutated by reference).
	 * @return void
	 */
	private static function record_generated_freeform_block( string $source, array $path, array $block, bool $malformed, array &$report ): void {
		$html = '';
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		$emitted = '';
		if ( ! $malformed && function_exists( 'serialize_blocks' ) ) {
			// @phpstan-ignore-next-line argument.type -- Parsed block shape comes from WordPress parse_blocks().
			$emitted = serialize_blocks( array( $block ) );
		}
		if ( '' === trim( $emitted ) ) {
			$emitted = $html;
		}

		$entry                          = Static_Site_Importer_Report_Diagnostics::fallback_diagnostic_entry(
			'freeform_block',
			$source,
			$html,
			array(
				'reason' => $malformed ? 'generated_document_contains_malformed_freeform_html' : 'generated_document_contains_core_freeform',
				'stage'  => 'generated_theme_block_analysis',
				'path'   => implode( '.', $path ),
			),
			$block
		);
		$entry['emitted_block_preview'] = Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( $emitted );
		$entry['malformed']             = $malformed;

		$report['diagnostics'][]                        = $entry;
		$report['generated_theme']['freeform_blocks'][] = $entry;
	}

	/**
	 * Normalize generated markup enough to avoid formatting-only report noise.
	 *
	 * @param string $markup Block document markup.
	 * @return string
	 */
	private static function normalize_block_document_for_report( string $markup ): string {
		$markup = str_replace( array( "\r\n", "\r" ), "\n", trim( $markup ) );
		$markup = preg_replace( '/>\s+</', '><', $markup );
		$markup = preg_replace( '/\s+/', ' ', is_string( $markup ) ? $markup : '' );

		return is_string( $markup ) ? trim( $markup ) : '';
	}

	/**
	 * Determine whether two block documents differ semantically for report purposes.
	 *
	 * @param string $original   Original generated markup.
	 * @param string $serialized WordPress-serialized markup.
	 * @return bool
	 */
	private static function block_documents_differ_for_report( string $original, string $serialized ): bool {
		if ( self::normalize_block_document_for_report( $original ) === self::normalize_block_document_for_report( $serialized ) ) {
			return false;
		}

		$original_blocks   = self::canonical_parsed_block_document_for_report( $original );
		$serialized_blocks = self::canonical_parsed_block_document_for_report( $serialized );
		if ( array() !== $original_blocks || array() !== $serialized_blocks ) {
			return $original_blocks !== $serialized_blocks;
		}

		return true;
	}

	/**
	 * Parse a block document into a canonical shape for semantic report comparison.
	 *
	 * @param string $markup Block document markup.
	 * @return array<int,mixed>
	 */
	private static function canonical_parsed_block_document_for_report( string $markup ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		$blocks = parse_blocks( $markup );
		return self::canonicalize_parsed_block_values_for_report( $blocks );
	}

	/**
	 * Normalize parsed block values so harmless serializer escaping does not fail reports.
	 *
	 * @param mixed $value Parsed block value.
	 * @return mixed
	 */
	private static function canonicalize_parsed_block_values_for_report( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			$decoded = json_decode( '"' . str_replace( '"', '\\"', $value ) . '"' );
			return is_string( $decoded ) ? $decoded : $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		foreach ( $value as $key => $item ) {
			$normalized[ $key ] = self::canonicalize_parsed_block_values_for_report( $item );
		}

		return $normalized;
	}

	/**
	 * Report the first token that differs between generated and serialized block documents.
	 *
	 * @param string $original   Original generated markup.
	 * @param string $serialized WordPress-serialized markup.
	 * @return array<string,mixed>
	 */
	private static function first_differing_block_document_token( string $original, string $serialized ): array {
		$original_tokens   = preg_split( '/(<!--\s*\/?wp:[^>]+-->|<[^>]+>|\s+)/', self::normalize_block_document_for_report( $original ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$serialized_tokens = preg_split( '/(<!--\s*\/?wp:[^>]+-->|<[^>]+>|\s+)/', self::normalize_block_document_for_report( $serialized ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$original_tokens   = false !== $original_tokens ? $original_tokens : array();
		$serialized_tokens = false !== $serialized_tokens ? $serialized_tokens : array();
		$limit             = max( count( $original_tokens ), count( $serialized_tokens ) );

		for ( $index = 0; $index < $limit; ++$index ) {
			$original_token   = $original_tokens[ $index ] ?? null;
			$serialized_token = $serialized_tokens[ $index ] ?? null;
			if ( $original_token !== $serialized_token ) {
				return array(
					'index'      => $index,
					'original'   => null === $original_token ? null : Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( (string) $original_token ),
					'serialized' => null === $serialized_token ? null : Static_Site_Importer_Report_Diagnostics::diagnostic_excerpt( (string) $serialized_token ),
				);
			}
		}

		return array();
	}
}
