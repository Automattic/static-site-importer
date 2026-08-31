<?php
/** Run: php tests/smoke-ip-classifier.php */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', dirname( __DIR__ ) . '/' ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-ip-classifier.php';

$failures = array();
$check    = static function ( bool $passed, string $label ) use ( &$failures ): void {
	if ( ! $passed ) { $failures[] = $label; }
};

/*
 * IPv4-in-IPv6 encodings. PHP's own range flags accept every one of these as public
 * on 8.2.x and on 8.3 before 8.3.16, which is the SSRF bypass this class exists to close.
 */
$mapped_private = array(
	'::ffff:127.0.0.1',
	'::ffff:7f00:1',
	'0:0:0:0:0:ffff:127.0.0.1',
	'::ffff:10.0.0.1',
	'::ffff:192.168.1.1',
	'::ffff:172.16.0.1',
	'::ffff:169.254.169.254',
	'::ffff:100.64.0.1',
	'::127.0.0.1',
	'::ffff:0:127.0.0.1',
	'64:ff9b::127.0.0.1',
	'2002:7f00:1::',
	'2001:0:53aa:64c:c:1234:5678:9abc',
);
foreach ( $mapped_private as $ip ) {
	$check( ! Static_Site_Importer_IP_Classifier::is_public( $ip ), 'ipv4-in-ipv6 must not classify as public: ' . $ip );
}

// Literal private, loopback, link-local, and reserved addresses.
$reserved = array(
	'127.0.0.1',
	'127.1.2.3',
	'10.0.0.1',
	'172.16.0.1',
	'172.31.255.255',
	'192.168.1.1',
	'169.254.169.254',
	'100.64.0.1',
	'100.127.255.255',
	'198.18.0.1',
	'192.0.0.1',
	'192.0.2.1',
	'192.88.99.1',
	'198.51.100.1',
	'203.0.113.1',
	'0.0.0.0',
	'224.0.0.1',
	'240.0.0.1',
	'255.255.255.255',
	'::',
	'::1',
	'fd00::1',
	'fc00::1',
	'fe80::1',
	'ff02::1',
	'2001:db8::1',
	'2001:20::1',
	'100::1',
);
foreach ( $reserved as $ip ) {
	$check( ! Static_Site_Importer_IP_Classifier::is_public( $ip ), 'reserved address must not classify as public: ' . $ip );
}

// Genuinely routable addresses must still be reachable, or URL import is broken.
$public = array(
	'8.8.8.8',
	'1.1.1.1',
	'93.184.216.34',
	'172.15.255.255',
	'172.32.0.1',
	'100.63.255.255',
	'100.128.0.1',
	'198.20.0.1',
	'2606:4700:4700::1111',
	'2a00:1450:4001:81b::200e',
);
foreach ( $public as $ip ) {
	$check( Static_Site_Importer_IP_Classifier::is_public( $ip ), 'routable address must classify as public: ' . $ip );
}

// Normalization collapses IPv4-mapped form so classification and connection agree.
$check( '8.8.8.8' === Static_Site_Importer_IP_Classifier::normalize( '::ffff:8.8.8.8' ), 'mapped public address must normalize to its IPv4 form' );
$check( '8.8.8.8' === Static_Site_Importer_IP_Classifier::normalize( '::ffff:808:808' ), 'hex mapped form must normalize to its IPv4 form' );
$check( Static_Site_Importer_IP_Classifier::is_public( '::ffff:8.8.8.8' ), 'mapped routable address stays reachable after unmapping' );
$check( '127.0.0.1' === Static_Site_Importer_IP_Classifier::normalize( '::ffff:127.0.0.1' ), 'mapped loopback must normalize to its IPv4 form' );
$check( '8.8.8.8' === Static_Site_Importer_IP_Classifier::normalize( '8.8.8.8' ), 'IPv4 normalization is stable' );
$check( '2606:4700:4700::1111' === Static_Site_Importer_IP_Classifier::normalize( '2606:4700:4700:0:0:0:0:1111' ), 'IPv6 normalization compresses to canonical form' );

// Non-addresses fail closed.
foreach ( array( '', '   ', 'example.com', '2130706433', '0177.0.0.1', '127.0.0.1:80', 'not-an-ip' ) as $value ) {
	$check( null === Static_Site_Importer_IP_Classifier::normalize( $value ), 'non-address must not normalize: ' . $value );
	$check( ! Static_Site_Importer_IP_Classifier::is_public( $value ), 'non-address must not classify as public: ' . $value );
}

if ( $failures ) {
	throw new RuntimeException( "IP classifier failures:\n - " . implode( "\n - ", $failures ) );
}

fwrite( STDOUT, "OK: IP classifier denies IPv4-in-IPv6 and shared address space independently of the PHP runtime\n" );
