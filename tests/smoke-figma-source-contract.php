<?php
/**
 * Smoke test: Figma source handoff payloads normalize into the SSI import contract.
 *
 * Run from the repository root:
 * php tests/smoke-figma-source-contract.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

class WP_Error {
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

class Static_Site_Importer_Transformer_Adapter {
	public const WEBSITE_ARTIFACT_SCHEMA = 'blocks-engine/website-artifact/v1';
}

function blocks_engine_figma_transformer_transform_scenegraph( array $scenegraph, array $options ): array {
	$GLOBALS['static_site_importer_figma_source_contract_transform'] = array(
		'scenegraph' => $scenegraph,
		'options'    => $options,
	);

	return array(
		'files' => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<main>Figma</main>',
			),
		),
	);
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$payload = array(
	'schema'            => 'static-site-importer/import-figma/v1',
	'source'            => array(
		'type'              => 'figma',
		'fileKey'           => 'abc123',
		'nodeIds'           => array( '1:1', '2:2' ),
		'frame_id'         => '1:1',
		'transform_options' => array(
			'multi_page' => true,
			'frame_ids'  => array( '1:1', 22, '' ),
			'max_pages'  => '3',
		),
		'payload'           => array(
			'scenegraph' => array(
				'document' => array(
					'id'   => '0:0',
					'name' => 'Document',
				),
			),
		),
	),
	'transform_options' => array(
		'entry_frame_id' => '2:2',
	),
	'multi_page'        => true,
	'frame_ids'         => array( '2:2', '3:3' ),
	'max_pages'         => 2,
);

$source = Static_Site_Importer_Figma_Import::source_from_input( $payload );

$assert( isset( $source['scenegraph']['document'] ), 'source-scenegraph-from-nested-payload' );
$assert( true === ( $source['transform_options']['multi_page'] ?? null ), 'source-multi-page-normalized' );
$assert( '1:1' === ( $source['transform_options']['frame_id'] ?? '' ), 'source-frame-id-from-source' );
$assert( array( '2:2', '3:3' ) === ( $source['transform_options']['frame_ids'] ?? array() ), 'source-frame-ids-top-level-overrides' );
$assert( '2:2' === ( $source['transform_options']['entry_frame_id'] ?? '' ), 'source-entry-frame-id-preserved' );
$assert( 2 === ( $source['transform_options']['max_pages'] ?? null ), 'source-max-pages-normalized' );

$artifact = Static_Site_Importer_Figma_Import::website_artifact_from_input( $payload );
$assert( is_array( $artifact ) && ! is_wp_error( $artifact ), 'website-artifact-created' );
$assert( 'website/index.html' === ( $artifact['entrypoint'] ?? '' ), 'website-artifact-entrypoint' );

$transform = $GLOBALS['static_site_importer_figma_source_contract_transform'] ?? array();
$assert( isset( $transform['scenegraph']['document'] ), 'transform-receives-normalized-scenegraph' );
$assert( ( $source['transform_options'] ?? array() ) === ( $transform['options'] ?? array() ), 'transform-receives-normalized-options' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: figma source contract smoke passed (' . $assertions . " assertions)\n";
