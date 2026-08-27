<?php
/**
 * Runtime-independent public-address classifier for outbound URL fetches.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify IP addresses as public-internet routable.
 *
 * PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` predicate is not a
 * stable security boundary: its IPv6 range table changed in 8.3.16 and 8.4.3, so
 * IPv4-mapped literals such as `::ffff:127.0.0.1` classify as public on every PHP
 * 8.2 release this plugin supports. It also reports RFC 6598 shared address space
 * (`100.64.0.0/10`) as public on every release. This class owns the classification
 * instead: addresses are compared as packed bytes against explicit CIDR blocks, and
 * IPv4-in-IPv6 encodings are unmapped so the address that is classified is the same
 * address the transport connects to.
 */
class Static_Site_Importer_IP_Classifier {

	/**
	 * IPv4 blocks that are not public-internet routable, keyed by CIDR.
	 *
	 * Values name the reservation so a block and its justification cannot drift
	 * apart. Sourced from the RFC 6890 special-purpose registry, plus RFC 6598
	 * shared address space, which PHP's own predicate reports as public.
	 *
	 * @var array<string,string>
	 */
	private const IPV4_BLOCKED = array(
		'0.0.0.0/8'       => 'This network (RFC 1122)',
		'10.0.0.0/8'      => 'Private use (RFC 1918)',
		'100.64.0.0/10'   => 'Shared address space, CGNAT (RFC 6598)',
		'127.0.0.0/8'     => 'Loopback (RFC 1122)',
		'169.254.0.0/16'  => 'Link local, includes cloud instance metadata (RFC 3927)',
		'172.16.0.0/12'   => 'Private use (RFC 1918)',
		'192.0.0.0/24'    => 'IETF protocol assignments (RFC 6890)',
		'192.0.2.0/24'    => 'TEST-NET-1 (RFC 5737)',
		'192.88.99.0/24'  => 'Deprecated 6to4 relay anycast (RFC 7526)',
		'192.168.0.0/16'  => 'Private use (RFC 1918)',
		'198.18.0.0/15'   => 'Benchmarking (RFC 2544)',
		'198.51.100.0/24' => 'TEST-NET-2 (RFC 5737)',
		'203.0.113.0/24'  => 'TEST-NET-3 (RFC 5737)',
		'224.0.0.0/4'     => 'Multicast (RFC 5771)',
		'240.0.0.0/4'     => 'Reserved, includes limited broadcast (RFC 1112)',
	);

	/**
	 * IPv6 blocks that are not public-internet routable, keyed by CIDR.
	 *
	 * Blocks that embed or tunnel an IPv4 destination are denied outright rather
	 * than unwrapped, because the embedded address can encode any IPv4 target.
	 *
	 * @var array<string,string>
	 */
	private const IPV6_BLOCKED = array(
		'::/96'           => 'Unspecified, loopback, deprecated IPv4-compatible (RFC 4291)',
		'::ffff:0:0:0/96' => 'Deprecated SIIT IPv4-translated (RFC 2765)',
		'64:ff9b::/96'    => 'NAT64 well-known prefix (RFC 6052)',
		'64:ff9b:1::/48'  => 'NAT64 local-use prefix (RFC 8215)',
		'100::/64'        => 'Discard-only (RFC 6666)',
		'2001::/32'       => 'Teredo tunnelling (RFC 4380)',
		'2001:10::/28'    => 'Deprecated ORCHID (RFC 4843)',
		'2001:20::/28'    => 'ORCHIDv2 (RFC 7343)',
		'2001:db8::/32'   => 'Documentation (RFC 3849)',
		'2002::/16'       => '6to4 tunnelling (RFC 3056)',
		'fc00::/7'        => 'Unique local (RFC 4193)',
		'fe80::/10'       => 'Link local unicast (RFC 4291)',
		'ff00::/8'        => 'Multicast (RFC 4291)',
	);

	/**
	 * IPv4-mapped IPv6 prefix (`::ffff:0:0/96`) in packed form.
	 */
	private const IPV4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

	/**
	 * Reduce an address to the canonical form the transport should connect to.
	 *
	 * IPv4-mapped IPv6 addresses collapse to their embedded IPv4 address so that
	 * classification and connection cannot disagree. Addresses this runtime cannot
	 * pack are rejected, which keeps callers fail closed on builds without IPv6
	 * support rather than letting an unclassifiable literal reach the transport.
	 *
	 * @param string $ip IP address.
	 * @return string|null Canonical address, or null when it cannot be classified.
	 */
	public static function normalize( string $ip ): ?string {
		$packed = self::pack( $ip );
		if ( null === $packed ) {
			return null;
		}

		$canonical = @inet_ntop( $packed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Packed input is already length validated; a failed conversion is converted to a fail-closed null below.

		return is_string( $canonical ) && '' !== $canonical ? $canonical : null;
	}

	/**
	 * Determine whether an address is public-internet routable.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public( string $ip ): bool {
		$packed = self::pack( $ip );
		if ( null === $packed ) {
			return false;
		}

		$blocks = 4 === strlen( $packed ) ? self::IPV4_BLOCKED : self::IPV6_BLOCKED;
		foreach ( array_keys( $blocks ) as $block ) {
			if ( self::in_block( $packed, $block ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Pack an address into bytes, unmapping IPv4-in-IPv6 encodings.
	 *
	 * @param string $ip IP address.
	 * @return string|null Packed address, or null when the input is not a usable IP.
	 */
	private static function pack( string $ip ): ?string {
		$ip = trim( $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Runtimes built without IPv6 support warn and return false; the fail-closed null below handles that outcome.
		if ( ! is_string( $packed ) ) {
			return null;
		}

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, self::IPV4_MAPPED_PREFIX ) ) {
			return substr( $packed, 12 );
		}

		return in_array( strlen( $packed ), array( 4, 16 ), true ) ? $packed : null;
	}

	/**
	 * Determine whether a packed address falls inside a CIDR block.
	 *
	 * @param string $packed Packed address.
	 * @param string $block  CIDR block using the same address family.
	 * @return bool
	 */
	private static function in_block( string $packed, string $block ): bool {
		list( $base, $prefix ) = explode( '/', $block, 2 );

		$base_packed = inet_pton( $base );
		if ( ! is_string( $base_packed ) || strlen( $base_packed ) !== strlen( $packed ) ) {
			return false;
		}

		$bits           = (int) $prefix;
		$whole_bytes    = intdiv( $bits, 8 );
		$remaining_bits = $bits % 8;

		if ( $whole_bytes > 0 && substr( $packed, 0, $whole_bytes ) !== substr( $base_packed, 0, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $remaining_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $remaining_bits ) ) & 0xFF;

		return ( ord( $packed[ $whole_bytes ] ) & $mask ) === ( ord( $base_packed[ $whole_bytes ] ) & $mask );
	}
}
