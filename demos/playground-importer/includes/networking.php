<?php
/**
 * Adapt PHP.wasm's browser networking to SSI's URL acquisition contract.
 *
 * PHP.wasm DNS returns synthetic socket addresses, not public DNS records.
 * Actual requests use Playground's browser/proxy transport through WP HTTP;
 * SSI still validates public addresses, redirects, MIME types and byte limits.
 *
 * @package StaticSiteImporterPlaygroundDemo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolve real A/AAAA records instead of PHP.wasm's synthetic socket IPs. */
function static_site_importer_playground_resolve_ips( $provided, string $host ) {
	if ( null !== $provided ) {
		return $provided;
	}
	static $cache = array();
	if ( isset( $cache[ $host ] ) ) {
		return $cache[ $host ];
	}
	$ips = array();
	foreach ( array( 1, 28 ) as $type ) {
		$response = wp_remote_get(
			'https://dns.google/resolve?name=' . rawurlencode( $host ) . '&type=' . $type,
			array( 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 65536 )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) || 0 !== ( $data['Status'] ?? null ) || ! empty( $data['TC'] ) ) {
			return new WP_Error( 'static_site_importer_playground_dns_failed', 'Public DNS resolution failed in Playground.' );
		}
		foreach ( $data['Answer'] ?? array() as $answer ) {
			if ( $type === ( $answer['type'] ?? null ) && is_string( $answer['data'] ?? null ) ) {
				$ips[] = $answer['data'];
			}
		}
	}
	// The importer classifies every returned address and rejects empty answers.
	$cache[ $host ] = array_values( array_unique( $ips ) );
	return $cache[ $host ];
}

/** Select the browser transport only for the demo's PHP.wasm runtime. */
function static_site_importer_playground_url_fetcher( $provided ) {
	return null === $provided ? 'static_site_importer_playground_fetch' : $provided;
}

/** Fetch through the existing SSI policy/redirect engine with WP HTTP transport. */
function static_site_importer_playground_fetch( string $url, array $args ) {
	$args['deadline']  = $args['deadline'] ?? microtime( true ) + 20;
	$args['transport'] = array(
		'start'  => 'static_site_importer_playground_request',
		'poll'   => static fn( $response ) => $response,
		'cancel' => static function (): void {},
	);
	return Static_Site_Importer_URL_Fetcher::fetch( $url, $args );
}

/** Perform a single bounded request; SSI owns redirect handling and validation. */
function static_site_importer_playground_request( array $target, array $options ) {
	$response = wp_remote_get(
		$target['url'],
		array(
			'timeout'             => $options['timeout'],
			'redirection'         => 0,
			'limit_response_size' => $options['max_bytes'] + 1,
			'cookies'             => array(),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$headers = array();
	foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
		$headers[ strtolower( (string) $name ) ] = (array) $value;
	}
	return array(
		'status_code' => wp_remote_retrieve_response_code( $response ),
		'headers'     => $headers,
		'body'        => wp_remote_retrieve_body( $response ),
	);
}
