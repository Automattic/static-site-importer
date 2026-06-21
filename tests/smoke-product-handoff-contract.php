<?php
/**
 * Smoke test: product handoff contract fixture matches SSI schema constants.
 *
 * Run from the repository root:
 * php tests/smoke-product-handoff-contract.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-product-handoff-contract.php';

$fixture_path = __DIR__ . '/fixtures/product-handoff-contract/v1.json';
$fixture      = json_decode( file_get_contents( $fixture_path ), true );
$failures     = array();
$assertions   = 0;
$assert       = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$assert( is_array( $fixture ), 'fixture-decodes' );

$schemas = Static_Site_Importer_Product_Handoff_Contract::schema_map();
$assert( $schemas['schema'] === ( $fixture['schema'] ?? '' ), 'contract-schema-matches-code' );
$assert( $schemas['version'] === ( $fixture['version'] ?? 0 ), 'contract-version-matches-code' );

$stages = isset( $fixture['stages'] ) && is_array( $fixture['stages'] ) ? $fixture['stages'] : array();
$example = isset( $fixture['example'] ) && is_array( $fixture['example'] ) ? $fixture['example'] : array();

$expected_stage_schemas = array(
	'input_artifact'                       => $schemas['input_artifact'],
	'blocks_engine_result'                 => $schemas['blocks_engine_result'],
	'ssi_import_report'                    => $schemas['ssi_import_report'],
	'codebox_validation_artifact_envelope' => $schemas['codebox_validation_artifact_envelope'],
);

foreach ( $expected_stage_schemas as $stage => $schema ) {
	$assert( isset( $stages[ $stage ] ) && is_array( $stages[ $stage ] ), $stage . '-stage-present' );
	$assert( $schema === ( $stages[ $stage ]['schema'] ?? '' ), $stage . '-schema-matches-code' );
	$assert( isset( $stages[ $stage ]['required_fields'] ) && is_array( $stages[ $stage ]['required_fields'] ), $stage . '-required-fields-present' );
	$assert( isset( $example[ $stage ] ) && is_array( $example[ $stage ] ), $stage . '-example-present' );
}

$assert( $schemas['blocks_engine_materialization_plan'] === ( $stages['blocks_engine_result']['materialization_plan']['schema'] ?? '' ), 'materialization-plan-schema-matches-code' );
$assert( $schemas['blocks_engine_materialization_plan'] === ( $example['blocks_engine_result']['source_reports']['materialization_plan']['schema'] ?? '' ), 'materialization-plan-example-schema' );
$assert( ! isset( $example['blocks_engine_result']['codebox'] ), 'blocks-engine-example-keeps-codebox-out' );
$assert( $schemas['ssi_import_validation_result'] === ( $example['ssi_import_report']['import_validation_result']['schema'] ?? '' ), 'import-validation-schema' );
$assert( $schemas['ssi_finding_packets'] === ( $example['ssi_import_report']['finding_packets']['schema'] ?? '' ), 'finding-packets-schema' );

$required_path_exists = static function ( array $data, string $path ): bool {
	$current = $data;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
			return false;
		}
		$current = $current[ $segment ];
	}

	return true;
};

foreach ( $stages as $stage => $stage_contract ) {
	if ( ! is_array( $stage_contract ) || ! isset( $example[ $stage ] ) || ! is_array( $example[ $stage ] ) ) {
		continue;
	}

	foreach ( $stage_contract['required_fields'] ?? array() as $field ) {
		$assert( is_string( $field ) && $required_path_exists( $example[ $stage ], $field ), $stage . '-requires-' . (string) $field );
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: product handoff contract smoke passed (' . $assertions . " assertions)\n";
