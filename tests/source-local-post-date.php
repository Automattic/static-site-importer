<?php
/**
 * Source-local publish dates (#2342): a plan row's publication_local_time sets
 * post_date only when it names the same instant as the detected UTC date.
 *
 * Run: php tests/source-local-post-date.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-document-type-classifier.php';

$utc   = '2025-04-22 22:04:36';
$cases = array(
	'source offset'     => array( '2025-04-23T00:04:36+02:00', $utc, '2025-04-23 00:04:36' ),
	'negative offset'   => array( '2025-04-22T17:04:36-05:00', $utc, '2025-04-22 17:04:36' ),
	'different instant' => array( '2025-04-24T00:04:36+02:00', $utc, null ),
	'missing'           => array( null, $utc, null ),
	'malformed'         => array( '2025-04-23 00:04:36', $utc, null ),
	'calendar overflow' => array( '2025-02-30T00:04:36+02:00', '2025-03-01 22:04:36', null ),
);
foreach ( $cases as $name => list( $local, $detected, $expected ) ) {
	$row    = null === $local ? array() : array( 'publication_local_time' => $local );
	$actual = Static_Site_Importer_Document_Type_Classifier::local_date( $row, $detected );
	if ( $expected !== $actual ) {
		throw new RuntimeException( $name . ': expected ' . static_site_importer_test_json( $expected ) . ', got ' . static_site_importer_test_json( $actual ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test failure message.
	}
}
echo "Source-local post dates passed.\n";

/**
 * JSON-encode a test value for the failure message.
 *
 * @param mixed $value Value.
 */
function static_site_importer_test_json( $value ): string {
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs without WordPress.
}
