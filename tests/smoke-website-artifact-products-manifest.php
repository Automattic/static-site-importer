<?php
/**
 * Smoke coverage for direct website artifact products.json materialization.
 *
 * This intentionally avoids WP_UnitTestCase so it can run in lightweight agent
 * shells while guarding the direct-artifact commerce handoff contract.
 */

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/includes/class-static-site-importer-theme-generator.php' );

if ( false === $source ) {
	fwrite( STDERR, "Could not read theme generator source.\n" );
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ) use ( $source ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$assert(
	str_contains( $source, 'products_manifest_from_website_artifact( $artifact )' ),
	'direct artifact import extracts products.json before BAC compilation'
);

$assert(
	str_contains( $source, "\$args['_products_manifest_report'] = \$products_manifest_report;" ),
	'direct artifact import forwards products manifest report through import args'
);

$assert(
	str_contains( $source, 'record_products_manifest_from_import_args( $args )' ),
	'direct compiled artifact import records products manifest before commerce context'
);

$assert(
	str_contains( $source, 'record_commerce_context_summary( $args )' ),
	'direct compiled artifact import builds commerce context from products manifest'
);

$assert(
	str_contains( $source, 'self::materialize_required_plugins( $args );' ),
	'direct compiled artifact import materializes plugins before product seeding'
);

$assert(
	str_contains( $source, "'products.json' !== basename( \$path )" ),
	'direct artifact products manifest detection uses products.json basename'
);

$assert(
	str_contains( $source, 'base64_decode( $content, true )' ),
	'direct artifact products manifest supports base64 artifact content'
);

fwrite( STDOUT, "website artifact products manifest smoke ok\n" );
