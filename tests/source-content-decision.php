<?php
/**
 * Consumer date inference may upgrade defaulted pages only with complete dates.
 *
 * Run: php tests/source-content-decision.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-static-site-importer-document-type-classifier.php';

/**
 * Standalone replacement for the WordPress key sanitizer.
 *
 * @param string $key Raw key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Standalone test needs a deterministic timezone.

$cases = array(
	'bare year'     => array( '2025', 'page', null ),
	'year-month'    => array( '2025-03', 'page', null ),
	'relative now'  => array( 'now', 'page', null ),
	'relative day'  => array( 'tomorrow', 'page', null ),
	'time only'     => array( '20:25', 'page', null ),
	'full ISO date' => array( '2025-03-01', 'post', '2025-03-01 00:00:00' ),
	'RFC 2822 date' => array( 'Sat, 01 Mar 2025 10:00:00 GMT', 'post', '2025-03-01 10:00:00' ),
);
foreach ( $cases as $name => list( $value, $expected_type, $date ) ) {
	$row      = array(
		'content_decision'  => array(
			'schema'    => 'blocks-engine/content-decision/v1',
			'state'     => 'defaulted',
			'post_type' => 'page',
			'evidence'  => array(),
		),
		'document_metadata' => array(
			'meta' => array(
				array(
					'name'    => 'DC.date',
					'content' => $value,
				),
			),
		),
	);
	$expected = array(
		'post_type' => $expected_type,
		'date'      => $date,
		'signal'    => 'post' === $expected_type ? 'dated_meta' : 'page_default',
	);
	$actual   = Static_Site_Importer_Document_Type_Classifier::classify( $row );
	if ( $expected !== $actual ) {
		throw new RuntimeException( $name . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- CLI test failure message.
	}
}
echo "Source content decision date inference passed.\n";
