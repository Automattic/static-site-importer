<?php
/**
 * ZIP imports through the public CLI report distinct missing targets.
 *
 * Run: wp eval-file tests/unresolved-links-wordpress-smoke.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$slug = 'ssi-links-' . wp_generate_uuid4();
foreach ( array( false, true ) as $resolved ) {
	$directory = sys_get_temp_dir() . '/ssi-links-' . wp_generate_uuid4();
	wp_mkdir_p( $directory );
	$zip = new ZipArchive();
	$zip->open( $directory . '/site.zip', ZipArchive::CREATE );
	$header = '<header><nav><a href="/pricing">Pricing</a><a href="about.html">About</a></nav></header>';
	$body = '<main><h1>Welcome</h1><a href="/pricing">Pricing</a><a href="#contact">Contact</a><p id="contact">Contact us</p><a href="about.html#team">Team</a></main>';
	foreach ( array( 'index.html', 'about.html' ) as $page ) {
		$zip->addFromString( $page, '<!doctype html><html><head><title>Links</title></head><body>' . $header . $body . ( $resolved ? '<p id="team">Team</p>' : '' ) . '</body></html>' );
	}
	if ( $resolved ) {
		$zip->addFromString( 'pricing.html', '<!doctype html><html><head><title>Pricing</title></head><body><main><h1>Pricing</h1></main></body></html>' );
	}
	$zip->close();
	file_put_contents( $directory . '/request.json', wp_json_encode( array(
		'operation' => 'apply',
		'source' => array( 'type' => 'zip', 'ref' => 'request-bundle:site.zip' ),
		'slug' => $slug,
		'overwrite' => true,
		'write_theme_report_artifacts' => true,
	) ) );
	$command = 'static-site-importer import --request=' . escapeshellarg( $directory . '/request.json' );
	$output = WP_CLI::runcommand( $command, array( 'return' => 'all', 'exit_error' => false ) );
	$lines = explode( "\n", trim( $output->stdout ) );
	$receipt = json_decode( end( $lines ), true );
	$response = $receipt['response'] ?? array();
	$result = $response['result'] ?? array();
	$counts = $result['import_validation_result']['counts'] ?? array();
	$expected = $resolved ? 0 : 1;
	if ( 0 !== $output->return_code || $expected !== ( $counts['unresolved_internal_links'] ?? null ) || $expected !== ( $counts['unresolved_fragment_links'] ?? null ) ) {
		throw new RuntimeException( 'ZIP link counts mismatch: ' . $output->stdout . $output->stderr );
	}
	$validation = $result['import_validation_result'];
	if ( true !== $validation['quality_pass'] || false !== $validation['fail_import'] ) {
		throw new RuntimeException( 'Link findings must not gate the import.' );
	}
	$report = json_decode( (string) file_get_contents( $result['report_path'] ), true );
	foreach ( array( 'unresolved_internal_link', 'unresolved_fragment_link' ) as $type ) {
		$rows = array_values( array_filter( $report['diagnostics'] ?? array(), static fn( array $row ): bool => $type === ( $row['reason_code'] ?? '' ) ) );
		if ( $expected !== count( $rows ) || ( ! $resolved && ( empty( $rows[0]['source_path'] ) || ( 'unresolved_internal_link' === $type ? '/pricing' !== $rows[0]['route'] : '/about' !== $rows[0]['route'] || 'team' !== $rows[0]['fragment'] ) ) ) ) {
			throw new RuntimeException( 'Missing distinct-target diagnostic: ' . wp_json_encode( $report ) );
		}
	}
}
echo "ZIP unresolved links smoke passed.\n";
