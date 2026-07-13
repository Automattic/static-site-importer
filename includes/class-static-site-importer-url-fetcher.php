<?php
/**
 * Static URL intake.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one public HTML URL into an importer work directory.
 */
class Static_Site_Importer_URL_Fetcher {

	private const MAX_REDIRECTS         = 5;
	private const DEFAULT_TIMEOUT       = 10;
	private const DEFAULT_MAX_BYTES     = 5242880;
	private const HTML_CONTENT_TYPES    = array( 'text/html', 'application/xhtml+xml' );
	private const REDIRECT_STATUSES     = array( 301, 302, 303, 307, 308 );
	private const BODY_READ_CHUNK       = 8192;
	private const HEADER_MAX_BYTES      = 65536;
	private const CONNECT_TIMEOUT_FLOOR = 1;

	/**
	 * Fetch a public HTML URL and write it as index.html.
	 *
	 * @param string $url      Source URL.
	 * @param string $work_dir Importer work directory.
	 * @param array  $args     Fetch args.
	 * @return array{html_path:string,metadata:array<string,mixed>}|WP_Error
	 */
	public static function fetch_to_work_dir( string $url, string $work_dir, array $args = array() ) {
		$timeout   = max( self::CONNECT_TIMEOUT_FLOOR, (int) ( $args['timeout'] ?? self::DEFAULT_TIMEOUT ) );
		$max_bytes = max( 1, (int) ( $args['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) );
		$initial   = self::normalize_url( $url );
		$current   = $initial;
		$started   = gmdate( 'c' );
		$redirects = array();

		for ( $attempt = 0; $attempt <= self::MAX_REDIRECTS; $attempt++ ) {
			$validation = self::validate_url( $current );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$response = self::request_once( $validation, $timeout, $max_bytes );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) $response['status_code'];
			if ( in_array( $status, self::REDIRECT_STATUSES, true ) ) {
				$location = self::first_header( $response['headers'], 'location' );
				if ( '' === $location ) {
					return new WP_Error( 'static_site_importer_url_redirect_missing_location', 'The URL returned a redirect without a Location header.' );
				}

				if ( $attempt >= self::MAX_REDIRECTS ) {
					return new WP_Error( 'static_site_importer_url_redirect_limit', 'The URL exceeded the redirect limit.' );
				}

				$next_url = self::resolve_redirect_url( $current, $location );
				if ( is_wp_error( $next_url ) ) {
					return $next_url;
				}

				$redirects[] = array(
					'from'        => $current,
					'to'          => $next_url,
					'status_code' => $status,
				);
				$current     = $next_url;
				continue;
			}

			if ( $status < 200 || $status >= 300 ) {
				return new WP_Error( 'static_site_importer_url_http_status', sprintf( 'The URL returned HTTP status %d.', $status ) );
			}

			$content_type = self::first_header( $response['headers'], 'content-type' );
			if ( ! self::is_html_content_type( $content_type ) ) {
				return new WP_Error( 'static_site_importer_url_non_html', 'The URL did not return an HTML content type.' );
			}

			if ( '' === trim( $response['body'] ) ) {
				return new WP_Error( 'static_site_importer_url_empty_body', 'The URL returned an empty HTML response.' );
			}

			$source_diagnostic = self::html_source_diagnostic( $response['body'] );
			if ( ! empty( $source_diagnostic ) && 'error' === ( $source_diagnostic['severity'] ?? '' ) ) {
				return new WP_Error(
					'static_site_importer_url_client_rendered_app',
					'This URL appears to be a JavaScript-rendered application shell. Static Site Importer can import server-rendered HTML, but this page needs a browser-rendered capture before it can produce WordPress blocks.',
					array(
						'status'     => 422,
						'diagnostic' => $source_diagnostic,
					)
				);
			}

			wp_mkdir_p( $work_dir );
			$html_path = trailingslashit( $work_dir ) . 'index.html';
			$written   = file_put_contents( $html_path, $response['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes fetched static HTML to the importer source fixture.
			if ( false === $written ) {
				return new WP_Error( 'static_site_importer_url_write_failed', 'Failed to write fetched HTML to the import work directory.' );
			}

			return array(
				'html_path' => $html_path,
				'metadata'  => array(
					'source_type'     => 'url',
					'source_url'      => $initial,
					'final_url'       => $current,
					'status_code'     => $status,
					'content_type'    => $content_type,
					'fetch_started'   => $started,
					'fetch_completed' => gmdate( 'c' ),
					'bytes'           => strlen( $response['body'] ),
					'redirects'       => $redirects,
				),
			);
		}

		return new WP_Error( 'static_site_importer_url_redirect_limit', 'The URL exceeded the redirect limit.' );
	}

	/**
	 * Normalize operator-entered public URLs before validation.
	 *
	 * @param string $url URL or bare host/path such as example.com/about.
	 * @return string
	 */
	public static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) ) {
			return $url;
		}

		if ( preg_match( '/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?::\d+)?(?:[\/?#]|$)/', $url ) ) {
			return 'https://' . $url;
		}

		return $url;
	}

	/**
	 * Detect source HTML that is present but not statically importable.
	 *
	 * @param string $html Source HTML.
	 * @return array<string,mixed>
	 */
	public static function html_source_diagnostic( string $html ): array {
		$markup_bytes = strlen( $html );
		$script_count = preg_match_all( '#<script\b#i', $html );
		$app_shell    = preg_match( '#\bid=(?:"|\')(?:root|app|__next|gatsby-focus-wrapper|mount)(?:"|\')#i', $html );
		$text_html    = preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $html );
		$text_html    = preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', (string) $text_html );
		$text_html    = preg_replace( '#<template\b[^>]*>.*?</template>#is', ' ', (string) $text_html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for non-WordPress smoke tests; WordPress runtimes use wp_strip_all_tags().
		$stripped   = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text_html ) : strip_tags( (string) $text_html );
		$text       = html_entity_decode( trim( preg_replace( '/\s+/', ' ', $stripped ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text_chars = strlen( $text );
		$text_ratio = $markup_bytes > 0 ? $text_chars / $markup_bytes : 0;

		if ( ( $script_count >= 20 && $text_chars < 1000 && $text_ratio < 0.02 ) || ( $script_count >= 3 && $text_chars < 200 && $app_shell ) ) {
			return array(
				'type'          => 'client_rendered_app_shell',
				'severity'      => 'error',
				'message'       => 'Fetched HTML is dominated by JavaScript with little server-visible page content.',
				'script_count'  => $script_count,
				'text_chars'    => $text_chars,
				'markup_bytes'  => $markup_bytes,
				'text_ratio'    => $text_ratio,
				'repair_bucket' => 'browser_rendered_capture_required',
			);
		}

		return array();
	}

	/**
	 * Validate a URL before connecting.
	 *
	 * @param string $url URL.
	 * @return array{url:string,scheme:string,host:string,port:int,path:string,ips:array<int,string>}|WP_Error
	 */
	public static function validate_url( string $url ) {
		$url   = self::normalize_url( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return new WP_Error( 'static_site_importer_url_invalid', 'Enter a valid URL.' );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'static_site_importer_url_scheme', 'Only http and https URLs are supported.' );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'static_site_importer_url_credentials', 'URLs with embedded credentials are not supported.' );
		}

		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), "[] \t\n\r\0\x0B" ) );
		if ( '' === $host || 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return new WP_Error( 'static_site_importer_url_host', 'Localhost URLs are not supported.' );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		$ips = self::resolve_host_ips( $host );
		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error( 'static_site_importer_url_private_ip', 'The URL resolves to a private, loopback, link-local, or otherwise reserved IP address.' );
			}
		}

		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
			$path .= '?' . (string) $parts['query'];
		}

		return array(
			'url'    => $url,
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => $path,
			'ips'    => array_values( $ips ),
		);
	}

	/**
	 * Perform one HTTP request to a prevalidated target.
	 *
	 * @param array $target    Validated target.
	 * @param int   $timeout   Timeout in seconds.
	 * @param int   $max_bytes Maximum response body size.
	 * @return array{status_code:int,headers:array<string,array<int,string>>,body:string}|WP_Error
	 */
	private static function request_once( array $target, int $timeout, int $max_bytes ) {
		$last_error = '';
		foreach ( $target['ips'] as $ip ) {
			$response = self::request_ip( $target, $ip, $timeout, $max_bytes );
			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$last_error = $response->get_error_message();
		}

		return new WP_Error( 'static_site_importer_url_connect_failed', '' !== $last_error ? $last_error : 'Could not connect to the URL.' );
	}

	/**
	 * Perform one HTTP request to a resolved IP.
	 *
	 * @param array  $target    Validated target.
	 * @param string $ip        Resolved public IP.
	 * @param int    $timeout   Timeout in seconds.
	 * @param int    $max_bytes Maximum response body size.
	 * @return array{status_code:int,headers:array<string,array<int,string>>,body:string}|WP_Error
	 */
	private static function request_ip( array $target, string $ip, int $timeout, int $max_bytes ) {
		$remote = sprintf( 'tcp://%s:%d', str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip, $target['port'] );
		$errno  = 0;
		$errstr = '';
		$socket = stream_socket_client( $remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT );
		if ( false === $socket ) {
			return new WP_Error( 'static_site_importer_url_connect_failed', sprintf( 'Could not connect to %s: %s', $target['host'], $errstr ) );
		}

		stream_set_timeout( $socket, $timeout );
		if ( 'https' === $target['scheme'] ) {
			stream_context_set_option( $socket, 'ssl', 'SNI_enabled', true );
			stream_context_set_option( $socket, 'ssl', 'peer_name', $target['host'] );
			stream_context_set_option( $socket, 'ssl', 'verify_peer', true );
			stream_context_set_option( $socket, 'ssl', 'verify_peer_name', true );

			if ( ! stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a validated public HTTP socket, not a filesystem handle.
				return new WP_Error( 'static_site_importer_url_tls_failed', 'Could not establish a verified TLS connection to the URL.' );
			}
		}

		$host_header  = $target['host'];
		$default_port = 'https' === $target['scheme'] ? 443 : 80;
		if ( $target['port'] !== $default_port ) {
			$host_header .= ':' . $target['port'];
		}

		$request = 'GET ' . $target['path'] . " HTTP/1.1\r\n"
			. 'Host: ' . $host_header . "\r\n"
			. 'User-Agent: StaticSiteImporter/1.0' . "\r\n"
			. 'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1' . "\r\n"
			. "Connection: close\r\n\r\n";
		fwrite( $socket, $request ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes an HTTP request to a validated public socket.

		$raw = '';
		while ( ! feof( $socket ) ) {
			$raw .= fread( $socket, self::BODY_READ_CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads a bounded HTTP response from a validated public socket.
			if ( strlen( $raw ) > $max_bytes + self::HEADER_MAX_BYTES ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a validated public HTTP socket, not a filesystem handle.
				return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
			}
		}

		$meta = stream_get_meta_data( $socket );
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a validated public HTTP socket, not a filesystem handle.
		if ( ! empty( $meta['timed_out'] ) ) {
			return new WP_Error( 'static_site_importer_url_timeout', 'The URL request timed out.' );
		}

		$separator = strpos( $raw, "\r\n\r\n" );
		if ( false === $separator ) {
			return new WP_Error( 'static_site_importer_url_malformed_response', 'The URL returned a malformed HTTP response.' );
		}

		$header_block = substr( $raw, 0, $separator );
		$body         = substr( $raw, $separator + 4 );
		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
		}

		return self::parse_response( $header_block, $body );
	}

	/**
	 * Parse an HTTP response.
	 *
	 * @param string $header_block Raw response headers.
	 * @param string $body         Raw response body.
	 * @return array{status_code:int,headers:array<string,array<int,string>>,body:string}|WP_Error
	 */
	private static function parse_response( string $header_block, string $body ) {
		$lines = preg_split( "/\r\n|\n|\r/", $header_block );
		if ( ! is_array( $lines ) ) {
			return new WP_Error( 'static_site_importer_url_malformed_response', 'The URL returned malformed HTTP headers.' );
		}

		$status_line = (string) array_shift( $lines );
		if ( ! preg_match( '/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $status_line, $matches ) ) {
			return new WP_Error( 'static_site_importer_url_malformed_response', 'The URL returned a malformed HTTP status line.' );
		}

		$headers = array();
		foreach ( $lines as $line ) {
			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $line, 2 );
			$name                 = strtolower( trim( $name ) );
			if ( '' === $name ) {
				continue;
			}

			$headers[ $name ][] = trim( $value );
		}

		return array(
			'status_code' => (int) $matches[1],
			'headers'     => $headers,
			'body'        => self::decode_body( $headers, $body ),
		);
	}

	/**
	 * Decode simple transfer encodings.
	 *
	 * @param array<string,array<int,string>> $headers Headers.
	 * @param string                          $body    Body.
	 * @return string
	 */
	private static function decode_body( array $headers, string $body ): string {
		$encoding = strtolower( self::first_header( $headers, 'transfer-encoding' ) );
		if ( str_contains( $encoding, 'chunked' ) ) {
			$decoded = '';
			$offset  = 0;
			while ( true ) {
				$line_end = strpos( $body, "\r\n", $offset );
				if ( false === $line_end ) {
					return $body;
				}

				$size = (int) hexdec( trim( substr( $body, $offset, $line_end - $offset ) ) );
				if ( 0 === $size ) {
					return $decoded;
				}

				$offset   = $line_end + 2;
				$decoded .= substr( $body, $offset, $size );
				$offset  += $size + 2;
			}
		}

		return $body;
	}

	/**
	 * Resolve a host and return all A/AAAA records.
	 *
	 * @param string $host Host.
	 * @return array<int,string>|WP_Error
	 */
	private static function resolve_host_ips( string $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_A + DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					}
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( ! $ips ) {
			$records = gethostbynamel( $host );
			if ( is_array( $records ) ) {
				$ips = $records;
			}
		}

		$ips = array_values( array_unique( array_filter( $ips, static fn ( string $ip ): bool => (bool) filter_var( $ip, FILTER_VALIDATE_IP ) ) ) );
		if ( ! $ips ) {
			return new WP_Error( 'static_site_importer_url_dns_failed', 'The URL host could not be resolved.' );
		}

		return $ips;
	}

	/**
	 * Determine whether an IP is public internet routable.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( string $ip ): bool {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Resolve redirect locations relative to the current URL.
	 *
	 * @param string $base_url Current URL.
	 * @param string $location Redirect Location header.
	 * @return string|WP_Error
	 */
	private static function resolve_redirect_url( string $base_url, string $location ) {
		$location = trim( $location );
		if ( '' === $location ) {
			return new WP_Error( 'static_site_importer_url_redirect_missing_location', 'The URL returned an empty redirect Location header.' );
		}

		if ( preg_match( '/^[a-z][a-z0-9+.-]*:/i', $location ) ) {
			return $location;
		}

		$base = wp_parse_url( $base_url );
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return new WP_Error( 'static_site_importer_url_redirect_invalid_base', 'The redirect base URL is invalid.' );
		}

		if ( str_starts_with( $location, '//' ) ) {
			return strtolower( (string) $base['scheme'] ) . ':' . $location;
		}

		$origin = strtolower( (string) $base['scheme'] ) . '://' . (string) $base['host'];
		if ( isset( $base['port'] ) ) {
			$origin .= ':' . (int) $base['port'];
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}

		$path = isset( $base['path'] ) ? (string) $base['path'] : '/';
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );

		return $origin . ( '' !== $dir ? $dir : '/' ) . $location;
	}

	/**
	 * Read the first matching header.
	 *
	 * @param array<string,array<int,string>> $headers Headers.
	 * @param string                          $name    Header name.
	 * @return string
	 */
	private static function first_header( array $headers, string $name ): string {
		$name = strtolower( $name );
		return isset( $headers[ $name ][0] ) ? (string) $headers[ $name ][0] : '';
	}

	/**
	 * Determine whether a Content-Type is HTML.
	 *
	 * @param string $content_type Content-Type header.
	 * @return bool
	 */
	private static function is_html_content_type( string $content_type ): bool {
		$type = strtolower( trim( explode( ';', $content_type )[0] ) );
		return in_array( $type, self::HTML_CONTENT_TYPES, true ) || str_ends_with( $type, '+html' );
	}
}
