<?php
/**
 * Product-facing diagnostic loss classes.
 *
 * @package StaticSiteImporter
 */

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionFindingContract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies existing diagnostics into stable product readiness buckets.
 *
 * Two kinds of row arrive here:
 *
 *  1. Producer findings emitted by blocks-engine's php-transformer. These conform
 *     to {@see ConversionFindingContract} (schema
 *     `blocks-engine/php-transformer/conversion-finding/v1`) and already carry an
 *     authoritative `reason_code` / `pattern_family` / `repair_bucket` triplet.
 *     They are classified by mapping the contract's `repair_bucket` remediation
 *     lane onto a product bucket — never by inspecting strings.
 *  2. Importer-side rows raised by this plugin (materialization failures, gating,
 *     document routing). These are not producer findings, carry no contract
 *     identifier, and are classified by the legacy heuristic below.
 *
 * Keeping those two paths distinct is the point: previously every row went
 * through the heuristic, so an upstream vocabulary change silently rerouted
 * findings into the wrong product bucket instead of failing.
 */
class Static_Site_Importer_Diagnostic_Loss_Classes {

	public const NATIVE_CONVERSION            = 'native_conversion';
	public const EDITABLE_APPROXIMATION       = 'editable_approximation';
	public const PRESERVED_RUNTIME_ISLAND     = 'preserved_runtime_island';
	public const UNSUPPORTED_LOSS             = 'unsupported_loss';
	public const IMPORTER_MATERIALIZATION_BUG = 'importer_materialization_bug';

	/**
	 * Classification provenance markers, returned by {@see classify_with_provenance()}.
	 */
	public const SOURCE_EXPLICIT  = 'explicit';
	public const SOURCE_CONTRACT  = 'contract';
	public const SOURCE_HEURISTIC = 'heuristic';

	/**
	 * Upstream contract `repair_bucket` remediation lane => product-facing bucket.
	 *
	 * This is the entire cross-repo coupling, stated once and explicitly. Every
	 * lane {@see ConversionFindingContract::classify()} can emit appears here; an
	 * upstream rename or addition surfaces as an unmapped lane rather than as a
	 * silent reclassification.
	 *
	 * @var array<string,string>
	 */
	private const REPAIR_BUCKET_CLASSES = array(
		// Nothing to repair — the conversion landed natively, or the finding is
		// informational rather than a loss.
		'no_repair_needed'                            => self::NATIVE_CONVERSION,
		'drop_empty_html_block'                       => self::NATIVE_CONVERSION,
		'informational_var_density'                   => self::NATIVE_CONVERSION,
		'runtime_behavior_superseded_by_native_block' => self::NATIVE_CONVERSION,

		// Source behavior deliberately preserved as a bounded runtime island.
		'preserve_runtime_island'                     => self::PRESERVED_RUNTIME_ISLAND,
		'runtime_canvas_target_preservation'          => self::PRESERVED_RUNTIME_ISLAND,
		'preserve_static_metadata'                    => self::PRESERVED_RUNTIME_ISLAND,

		// The importer failed to materialize something it is responsible for.
		'block_serialization_validity_repair'         => self::IMPORTER_MATERIALIZATION_BUG,
		'runtime_dom_target_preservation'             => self::IMPORTER_MATERIALIZATION_BUG,
		'runtime_script_materialization'              => self::IMPORTER_MATERIALIZATION_BUG,
		'materialize_static_asset'                    => self::IMPORTER_MATERIALIZATION_BUG,
		'materialize_commerce_products'               => self::IMPORTER_MATERIALIZATION_BUG,
		'materialize_commerce_runtime'                => self::IMPORTER_MATERIALIZATION_BUG,
		'materialize_form_provider'                   => self::IMPORTER_MATERIALIZATION_BUG,
		'richtext_invalid_content_risk'               => self::IMPORTER_MATERIALIZATION_BUG,

		// No native mapping exists yet, or source content did not survive.
		'add_generic_pattern_recognizer'              => self::UNSUPPORTED_LOSS,
		'svg_content_lost'                            => self::UNSUPPORTED_LOSS,

		// Converted, editable, but not a faithful native equivalent.
		'semantic_structure_parity_restoration'       => self::EDITABLE_APPROXIMATION,
		'typography_parity_restoration'               => self::EDITABLE_APPROXIMATION,
		'runtime_interactive_behavior_restoration'    => self::EDITABLE_APPROXIMATION,
		'restore_interactive_behavior'                => self::EDITABLE_APPROXIMATION,
		'review_generic_mapping'                      => self::EDITABLE_APPROXIMATION,
		'native_block_recognition'                    => self::EDITABLE_APPROXIMATION,
		'preserve_responsive_image_markup'            => self::EDITABLE_APPROXIMATION,
		'layout_direction_misrecognition'             => self::EDITABLE_APPROXIMATION,
		'cover_gate_rejection'                        => self::EDITABLE_APPROXIMATION,
	);

	/**
	 * Contract repair buckets seen at runtime with no entry in the map above.
	 *
	 * Upstream is free to add remediation lanes. When it does, the finding still
	 * classifies (via the heuristic) but the lane is recorded here so drift is
	 * observable instead of silent. `tests/smoke-diagnostic-loss-classes.php`
	 * fails when the fixture corpus produces any unmapped lane.
	 *
	 * @var array<string,int>
	 */
	private static $unmapped_repair_buckets = array();

	/**
	 * Contract repair buckets encountered that this plugin does not map.
	 *
	 * @return array<string,int> Bucket name => occurrence count.
	 */
	public static function unmapped_repair_buckets(): array {
		return self::$unmapped_repair_buckets;
	}

	/**
	 * Reset recorded drift. Intended for test isolation.
	 */
	public static function reset_unmapped_repair_buckets(): void {
		self::$unmapped_repair_buckets = array();
	}

	/**
	 * Return the stable product-facing loss class for an existing diagnostic row.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	public static function classify( array $diagnostic ): string {
		return self::classify_with_provenance( $diagnostic )['class'];
	}

	/**
	 * Classify a diagnostic and report which path produced the answer.
	 *
	 * Provenance is what makes the remaining heuristic auditable: a producer
	 * finding that reports `heuristic` is a finding the contract failed to cover.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return array{class:string,source:string,repair_bucket:string}
	 */
	public static function classify_with_provenance( array $diagnostic ): array {
		$explicit = self::scalar( $diagnostic, array( 'loss_class', 'diagnostic_class' ) );
		if ( in_array( $explicit, self::classes(), true ) ) {
			return array(
				'class'         => $explicit,
				'source'        => self::SOURCE_EXPLICIT,
				'repair_bucket' => '',
			);
		}

		$contract_bucket = self::contract_repair_bucket( $diagnostic );
		if ( '' !== $contract_bucket && isset( self::REPAIR_BUCKET_CLASSES[ $contract_bucket ] ) ) {
			return array(
				'class'         => self::REPAIR_BUCKET_CLASSES[ $contract_bucket ],
				'source'        => self::SOURCE_CONTRACT,
				'repair_bucket' => $contract_bucket,
			);
		}

		if ( '' !== $contract_bucket ) {
			self::$unmapped_repair_buckets[ $contract_bucket ] = ( self::$unmapped_repair_buckets[ $contract_bucket ] ?? 0 ) + 1;
		}

		return array(
			'class'         => self::classify_by_heuristic( $diagnostic ),
			'source'        => self::SOURCE_HEURISTIC,
			'repair_bucket' => $contract_bucket,
		);
	}

	/**
	 * Resolve the upstream contract's remediation lane for a diagnostic row.
	 *
	 * Returns '' when the transformer contract is unavailable (the vendored
	 * package is an optional autoload in this plugin) or when the row is not a
	 * producer finding — i.e. it carries no `code` / `diagnostic_code` identifier.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	private static function contract_repair_bucket( array $diagnostic ): string {
		if ( ! class_exists( ConversionFindingContract::class ) ) {
			return '';
		}

		if ( ! ConversionFindingContract::isFinding( $diagnostic ) ) {
			return '';
		}

		$classification = ConversionFindingContract::classify( $diagnostic );

		return $classification['repair_bucket'] ?? '';
	}

	/**
	 * Legacy string-matching classification.
	 *
	 * Retained for importer-side diagnostics, which are raised by this plugin and
	 * never pass through the transformer's finding contract. Producer findings
	 * should not reach this method; when they do it means the contract map above
	 * is missing a lane.
	 *
	 * @param array<string,mixed> $diagnostic Diagnostic row.
	 * @return string
	 */
	private static function classify_by_heuristic( array $diagnostic ): string {
		$type       = sanitize_key( self::scalar( $diagnostic, array( 'type', 'kind', 'code' ) ) );
		$category   = sanitize_key( self::scalar( $diagnostic, array( 'category' ) ) );
		$repair     = sanitize_key( self::scalar( $diagnostic, array( 'suggested_repair_class', 'repair_bucket', 'group_key' ) ) );
		$reason     = sanitize_key( self::scalar( $diagnostic, array( 'reason_code', 'reason', 'error_code' ) ) );
		$stage      = sanitize_key( self::scalar( $diagnostic, array( 'stage' ) ) );
		$block_name = self::scalar( $diagnostic, array( 'block_name', 'observed_block_name' ) );
		$element    = self::scalar( $diagnostic, array( 'element', 'tag_name', 'tag' ) );
		$selector   = self::scalar( $diagnostic, array( 'selector', 'target_selector', 'runtime_target_selector' ) );
		$haystack   = strtolower( implode( ' ', array( $type, $category, $repair, $reason, $stage, $block_name, $element, $selector ) ) );

		if (
			self::contains_any(
				$haystack,
				array( 'runtime_dependency_vendor_telemetry_script', 'interaction_candidate', 'runtime_island', 'preserved_runtime' )
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
					'preservedasaboundedruntimeisland',
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
