<?php
/**
 * Source-local publish dates (#2342): a plan row's publication_local_time sets
 * post_date only when it names the same instant as the detected UTC date.
 *
 * Run: php tests/source-local-post-date.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-document-type-classifier.php';

date_default_timezone_set( 'Pacific/Honolulu' ); // The process timezone must not matter.
$utc   = '2025-04-22 22:04:36';
$cases = array(
	'source offset'      => array( '2025-04-23T00:04:36+02:00', $utc, '2025-04-23 00:04:36' ),
	'negative offset'    => array( '2025-04-22T17:04:36-05:00', $utc, '2025-04-22 17:04:36' ),
	'different instant'  => array( '2025-04-24T00:04:36+02:00', $utc, null ),
	'missing'            => array( null, $utc, null ),
	'malformed'          => array( '2025-04-23 00:04:36', $utc, null ),
	'invalid calendar'   => array( '2025-02-30T00:04:36+02:00', '2025-03-01 22:04:36', null ),
);
foreach ( $cases as $name => list( $local, $detected, $expected ) ) {
	$page   = null === $local ? array() : array( 'publication_local_time' => $local );
	$actual = Static_Site_Importer_Document_Type_Classifier::local_date( $page, $detected );
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL {$name}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}
echo "Source-local post dates passed.\n";
