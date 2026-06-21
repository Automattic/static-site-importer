<?php
/**
 * Product handoff contract constants.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the machine-readable envelopes shared across product handoffs.
 */
class Static_Site_Importer_Product_Handoff_Contract {

	public const VERSION = 1;
	public const CONTRACT_SCHEMA = 'static-site-importer/product-handoff-contract/v1';
	public const INPUT_ARTIFACT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';
	public const BLOCKS_ENGINE_RESULT_SCHEMA = 'blocks-engine/php-transformer/result/v1';
	public const MATERIALIZATION_PLAN_SCHEMA = 'blocks-engine/php-transformer/materialization-plan/v1';
	public const SSI_IMPORT_REPORT_SCHEMA = 'static-site-importer/import-report/v1';
	public const SSI_IMPORT_VALIDATION_RESULT_SCHEMA = 'blocks-engine/import-validation-result/v1';
	public const SSI_FINDING_PACKETS_SCHEMA = 'blocks-engine/finding-packets/v1';
	public const SSI_ARTIFACT_DIAGNOSTICS_SCHEMA = 'static-site-importer/artifact-diagnostics/v1';
	public const CODEBOX_VALIDATION_ARTIFACT_ENVELOPE_SCHEMA = 'wp-codebox/validation-artifact-envelope/v1';

	/**
	 * Return the canonical schema identifiers by handoff stage.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema_map(): array {
		return array(
			'schema'                            => self::CONTRACT_SCHEMA,
			'version'                           => self::VERSION,
			'input_artifact'                    => self::INPUT_ARTIFACT_SCHEMA,
			'blocks_engine_result'              => self::BLOCKS_ENGINE_RESULT_SCHEMA,
			'blocks_engine_materialization_plan' => self::MATERIALIZATION_PLAN_SCHEMA,
			'ssi_import_report'                 => self::SSI_IMPORT_REPORT_SCHEMA,
			'ssi_import_validation_result'      => self::SSI_IMPORT_VALIDATION_RESULT_SCHEMA,
			'ssi_finding_packets'               => self::SSI_FINDING_PACKETS_SCHEMA,
			'ssi_artifact_diagnostics'          => self::SSI_ARTIFACT_DIAGNOSTICS_SCHEMA,
			'codebox_validation_artifact_envelope' => self::CODEBOX_VALIDATION_ARTIFACT_ENVELOPE_SCHEMA,
		);
	}
}
