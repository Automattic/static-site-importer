<?php
/**
 * Product-facing diagnostic loss classes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies existing diagnostics into stable product readiness buckets.
 */
class Static_Site_Importer_Diagnostic_Loss_Classes {

	public const NATIVE_CONVERSION            = 'native_conversion';
	public const EDITABLE_APPROXIMATION       = 'editable_approximation';
	public const PRESERVED_RUNTIME_ISLAND     = 'preserved_runtime_island';
	public const UNSUPPORTED_LOSS             = 'unsupported_loss';
	public const IMPORTER_MATERIALIZATION_BUG = 'importer_materialization_bug';

	/**
	 * Return the stable product-facing loss class for an existing diagnostic row.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	public static function classify( array $diagnostic ): string {
		$explicit = self::scalar( $diagnostic, array( 'loss_class', 'diagnostic_class' ) );
		if ( in_array( $explicit, self::classes(), true ) ) {
			return $explicit;
		}

		$type        = sanitize_key( self::scalar( $diagnostic, array( 'type', 'kind', 'code' ) ) );
		$category    = sanitize_key( self::scalar( $diagnostic, array( 'category' ) ) );
		$repair      = sanitize_key( self::scalar( $diagnostic, array( 'suggested_repair_class', 'repair_bucket', 'group_key' ) ) );
		$reason      = sanitize_key( self::scalar( $diagnostic, array( 'reason_code', 'reason', 'error_code' ) ) );
		$stage       = sanitize_key( self::scalar( $diagnostic, array( 'stage' ) ) );
		$block_name  = self::scalar( $diagnostic, array( 'block_name', 'observed_block_name' ) );
		$element     = self::scalar( $diagnostic, array( 'element', 'tag_name', 'tag' ) );
		$selector    = self::scalar( $diagnostic, array( 'selector', 'target_selector', 'runtime_target_selector' ) );
		$haystack    = strtolower( implode( ' ', array( $type, $category, $repair, $reason, $stage, $block_name, $element, $selector ) ) );

		if (
			self::contains_any(
				$haystack,
				array( 'runtime_dependency_vendor_telemetry_script', 'interaction_candidate', 'runtime_island', 'runtimeisland', 'preserved_runtime', 'preservedasaboundedruntimeisland' )
			)
			|| self::is_preserved_runtime_element( $element, $selector )
		) {
			return self::PRESERVED_RUNTIME_ISLAND;
		}

		if (
			self::contains_any(
				$haystack,
				array(
					'local_asset_not_materialized',
					'materialization_failure',
					'sprite_reference_failure',
					'invalid_block',
					'block_validation',
					'missing_dom_target',
					'runtime_dependency_target',
					'runtime_dependency_parity_issue',
					'commerce_dependency_failure',
				)
			)
		) {
			return self::IMPORTER_MATERIALIZATION_BUG;
		}

		if (
			self::contains_any(
				$haystack,
				array(
					'content_loss',
					'empty_conversion',
					'unsupported_source_document',
					'unsafe_inline_svg',
					'unsupported_element_reference',
					'dropped_image',
					'missing_asset',
				)
			)
		) {
			return self::UNSUPPORTED_LOSS;
		}

		if (
			self::contains_any(
				$haystack,
				array(
					'unsupported_html_fallback',
					'core_html',
					'freeform',
					'fallback_block',
					'presentation',
					'style_loss',
					'semantic_parity',
					'navigation_',
					'landmark_',
				)
			)
		) {
			return self::EDITABLE_APPROXIMATION;
		}

		return self::NATIVE_CONVERSION;
	}

	/**
	 * Count diagnostics by loss class, including zeroes for every stable class.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<string,int>
	 */
	public static function counts( array $diagnostics ): array {
		$counts = array_fill_keys( self::classes(), 0 );
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) ) {
				continue;
			}

			$class            = self::classify( $diagnostic );
			$counts[ $class ] = ( $counts[ $class ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Stable class names.
	 *
	 * @return array<int,string>
	 */
	public static function classes(): array {
		return array(
			self::NATIVE_CONVERSION,
			self::EDITABLE_APPROXIMATION,
			self::PRESERVED_RUNTIME_ISLAND,
			self::UNSUPPORTED_LOSS,
			self::IMPORTER_MATERIALIZATION_BUG,
		);
	}

	/**
	 * Return the first non-empty scalar value.
	 *
	 * @param array<string,mixed> $row    Source row.
	 * @param array<int,string>   $fields Candidate fields.
	 * @return string
	 */
	private static function scalar( array $row, array $fields ): string {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}

		return '';
	}

	/**
	 * Determine whether a string contains any candidate fragment.
	 *
	 * @param string            $value   Value to inspect.
	 * @param array<int,string> $needles Candidate fragments.
	 * @return bool
	 */
	private static function contains_any( string $value, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a fallback row preserves a runtime-only element.
	 *
	 * @param string $element  Element or tag field.
	 * @param string $selector Selector field.
	 * @return bool
	 */
	private static function is_preserved_runtime_element( string $element, string $selector ): bool {
		$element = strtolower( trim( $element ) );
		if ( in_array( $element, array( 'canvas', 'script' ), true ) ) {
			return true;
		}

		return 1 === preg_match( '/^(?:canvas|script)(?:$|[\s.#:[>+~])/', strtolower( trim( $selector ) ) );
	}
}
