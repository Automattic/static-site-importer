<?php
/**
 * Static URL intake.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-static-site-importer-url-fetcher-native-handle.php';

/**
 * Fetches one public HTML URL into an importer work directory.
 */
class Static_Site_Importer_URL_Fetcher {

	private const MAX_REDIRECTS              = 5;
	private const DEFAULT_TIMEOUT            = 10;
	private const DEFAULT_MAX_BYTES          = 5242880;
	private const MAX_RESPONSE_BYTES         = 10485760;
	private const HTML_CONTENT_TYPES         = array( 'text/html', 'application/xhtml+xml' );
	private const REDIRECT_STATUSES          = array( 301, 302, 303, 307, 308 );
	private const BODY_READ_CHUNK            = 8192;
	private const HEADER_MAX_BYTES           = 65536;
	private const CONNECT_TIMEOUT_FLOOR      = 1;
	private const DEFAULT_CONCURRENCY        = 4;
	private const DEFAULT_ORIGIN_CONCURRENCY = 2;

	/**
	 * Fetch a public HTML URL and write it as index.html.
	 *
	 * @param string $url      Source URL.
	 * @param string $work_dir Importer work directory.
	 * @param array  $args     Fetch args.
	 * @return array{html_path:string,metadata:array<string,mixed>}|WP_Error
	 */
	public static function fetch_to_work_dir( string $url, string $work_dir, array $args = array() ) {
		$fetch = self::fetch( $url, $args );
		if ( is_wp_error( $fetch ) ) {
			return $fetch;
		}

		$source_diagnostic = self::html_source_diagnostic( $fetch['body'] );
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
		$written   = file_put_contents( $html_path, $fetch['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes fetched static HTML to the importer source fixture.
		if ( false === $written ) {
			return new WP_Error( 'static_site_importer_url_write_failed', 'Failed to write fetched HTML to the import work directory.' );
		}

		return array(
			'html_path' => $html_path,
			'metadata'  => $fetch['metadata'],
		);
	}

	/**
	 * Fetch one public resource using the URL intake safety policy.
	 *
	 * @param string $url  Public resource URL.
	 * @param array  $args Fetch args. `content_types` optionally limits accepted MIME types.
	 * @return array{body:string,metadata:array<string,mixed>}|WP_Error
	 */
	public static function fetch( string $url, array $args = array() ) {
		if ( isset( $args['deadline'] ) ) {
			$many_args = array( 'deadline' => (float) $args['deadline'] );
			if ( isset( $args['clock'] ) && is_callable( $args['clock'] ) ) {
				$many_args['clock'] = $args['clock'];
			}
			if ( isset( $args['transport'] ) && is_array( $args['transport'] ) ) {
				$many_args['transport'] = $args['transport'];
			}
			$results = self::fetch_many(
				array(
					'fetch' => array(
						'url'  => $url,
						'args' => $args,
					),
				),
				$many_args
			);
			return $results['fetch'];
		}
		$timeout               = max( self::CONNECT_TIMEOUT_FLOOR, (int) ( $args['timeout'] ?? self::DEFAULT_TIMEOUT ) );
		$max_bytes             = min( self::MAX_RESPONSE_BYTES, max( 1, (int) ( $args['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) ) );
		$has_content_types_arg = isset( $args['content_types'] ) && is_array( $args['content_types'] );
		$content_types         = $has_content_types_arg ? array_values( array_filter( array_map( static fn ( $value ): string => strtolower( (string) $value ), $args['content_types'] ) ) ) : self::HTML_CONTENT_TYPES;
		$initial               = self::normalize_url( $url );
		$current               = $initial;
		$started               = gmdate( 'c' );
		$redirects             = array();

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
				return new WP_Error( 'static_site_importer_url_http_status', sprintf( 'The URL returned HTTP status %d.', $status ), array( 'status' => $status ) );
			}

			$content_type            = self::first_header( $response['headers'], 'content-type' );
			$normalized_content_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
			$content_type_allowed    = $has_content_types_arg ? empty( $content_types ) || in_array( $normalized_content_type, $content_types, true ) : self::is_html_content_type( $content_type );
			if ( ! $content_type_allowed ) {
				if ( ! $has_content_types_arg ) {
					return new WP_Error( 'static_site_importer_url_non_html', 'The URL did not return an HTML content type.' );
				}
				return new WP_Error( 'static_site_importer_url_unexpected_content_type', sprintf( 'The URL returned unsupported content type %s.', '' !== $content_type ? $content_type : '(missing)' ) );
			}

			// An explicit empty accepted-type list is used for optional binary/text assets.
			// HTML and explicitly requested HTML responses must still contain a document.
			if ( '' === trim( $response['body'] ) && ( ! $has_content_types_arg || ! empty( $content_types ) ) ) {
				return new WP_Error( 'static_site_importer_url_empty_body', $has_content_types_arg ? 'The URL returned an empty response.' : 'The URL returned an empty HTML response.' );
			}

			return array(
				'body'     => $response['body'],
				'metadata' => array(
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
	 * Fetch bounded public resources concurrently without changing result order.
	 *
	 * Requests are keyed by caller identity. Each value is a URL string or an
	 * array with `url` and optional per-request `args`. The optional transport is
	 * deliberately a small start/poll/cancel driver so tests can control progress
	 * without ever bypassing URL validation or response policy.
	 *
	 * @param array<string,string|array{url:string,args?:array}> $requests Requests keyed by identity.
	 * @param array $args Transport arguments: concurrency, per_origin_concurrency, deadline, clock, transport.
	 * @return array<string,array{body:string,metadata:array<string,mixed>}|WP_Error>
	 */
	public static function fetch_many( array $requests, array $args = array() ): array {
		$global_limit = max( 1, (int) ( $args['concurrency'] ?? self::DEFAULT_CONCURRENCY ) );
		$origin_limit = max( 1, (int) ( $args['per_origin_concurrency'] ?? self::DEFAULT_ORIGIN_CONCURRENCY ) );
		$clock        = isset( $args['clock'] ) && is_callable( $args['clock'] ) ? $args['clock'] : static fn(): float => microtime( true );
		$deadline     = isset( $args['deadline'] ) ? (float) $args['deadline'] : null;
		$driver       = isset( $args['transport'] ) && is_array( $args['transport'] ) ? $args['transport'] : null;
		$pending      = array();
		$active       = array();
		$origins      = array();
		$results      = array();

		foreach ( $requests as $key => $request ) {
			$identity   = (string) $key;
			$url        = is_array( $request ) ? (string) $request['url'] : (string) $request;
			$fetch_args = is_array( $request ) ? (array) ( $request['args'] ?? array() ) : array();
			$validation = self::validate_url( $url );
			if ( is_wp_error( $validation ) ) {
				$results[ $identity ] = $validation;
				continue;
			}
			$pending[] = array(
				'key'       => $identity,
				'initial'   => $validation['url'],
				'current'   => $validation['url'],
				'target'    => $validation,
				'args'      => $fetch_args,
				'started'   => gmdate( 'c' ),
				'redirects' => array(),
				'attempt'   => 0,
			);
		}

		while ( $pending || $active ) {
			if ( null !== $deadline && $clock() >= $deadline ) {
				foreach ( $active as $state ) {
					self::cancel_transport( $driver, $state['handle'], 'deadline_exhausted' );
					$results[ $state['key'] ] = new WP_Error( 'static_site_importer_url_deadline_exhausted', 'The URL request deadline was exhausted.' );
				}
				foreach ( $pending as $state ) {
					$results[ $state['key'] ] = new WP_Error( 'static_site_importer_url_deadline_exhausted', 'The URL request deadline was exhausted.' );
				}
				break;
			}

			$started = false;
			foreach ( $pending as $index => $state ) {
				$origin = self::origin_key( $state['target'] );
				if ( count( $active ) >= $global_limit || ( $origins[ $origin ] ?? 0 ) >= $origin_limit ) {
					continue;
				}
				unset( $pending[ $index ] );
				$state['handle']    = self::start_transport( $driver, $state['target'], self::request_options( $state['args'], $deadline, $clock ) );
				$active[]           = $state;
				$origins[ $origin ] = ( $origins[ $origin ] ?? 0 ) + 1;
				$started            = true;
			}
			$pending = array_values( $pending );

			foreach ( $active as $index => $state ) {
				$response = self::poll_transport( $driver, $state['handle'] );
				if ( null === $response ) {
					continue;
				}
				unset( $active[ $index ] );
				$origin = self::origin_key( $state['target'] );
				--$origins[ $origin ];
				if ( is_wp_error( $response ) ) {
					$results[ $state['key'] ] = $response;
					continue;
				}
				$outcome = self::finish_many_response( $state, $response );
				if ( isset( $outcome['pending'] ) ) {
					$pending[] = $outcome['pending'];
				} else {
					$results[ $state['key'] ] = $outcome['result'];
				}
			}
			$active = array_values( $active );
			if ( ! $started && $active ) {
				usleep( 1000 );
			}
		}

		// Preserve caller identity order rather than completion order.
		$ordered = array();
		foreach ( $requests as $key => $_request ) {
			$ordered[ (string) $key ] = $results[ (string) $key ] ?? new WP_Error( 'static_site_importer_url_cancelled', 'The URL request was cancelled.' );
		}
		/** @var array<string,array{body:string,metadata:array<string,mixed>}|WP_Error> $ordered */
		return $ordered;
	}

	/** @return array{timeout:float,max_bytes:int,has_content_types_arg:bool,content_types:array<int,string>,deadline:?float,clock:?callable,deadline_limited:bool} */
	private static function request_options( array $args, ?float $deadline = null, ?callable $clock = null ): array {
		$has_content_types_arg = isset( $args['content_types'] ) && is_array( $args['content_types'] );
		$requested_timeout     = max( self::CONNECT_TIMEOUT_FLOOR, (int) ( $args['timeout'] ?? self::DEFAULT_TIMEOUT ) );
		$timeout               = $requested_timeout;
		$deadline_limited      = false;
		if ( null !== $deadline && null !== $clock ) {
			$remaining        = $deadline - $clock();
			$deadline_limited = $remaining < $requested_timeout;
			$timeout          = max( 0.001, min( $timeout, $remaining ) );
		}
		return array(
			'timeout'               => $timeout,
			'max_bytes'             => min( self::MAX_RESPONSE_BYTES, max( 1, (int) ( $args['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) ) ),
			'has_content_types_arg' => $has_content_types_arg,
			'content_types'         => $has_content_types_arg ? array_values( array_filter( array_map( static fn( $value ): string => strtolower( (string) $value ), $args['content_types'] ) ) ) : self::HTML_CONTENT_TYPES,
			'deadline'              => $deadline,
			'clock'                 => $clock,
			'deadline_limited'      => $deadline_limited,
		);
	}

	/** @return array{result:array|WP_Error}|array{pending:array} */
	private static function finish_many_response( array $state, array $response ): array {
		$status  = (int) ( $response['status_code'] ?? 0 );
		$headers = is_array( $response['headers'] ?? null ) ? $response['headers'] : array();
		$body    = (string) ( $response['body'] ?? '' );
		$options = self::request_options( $state['args'] );
		if ( strlen( $body ) > $options['max_bytes'] ) {
			return array( 'result' => new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' ) );
		}
		if ( in_array( $status, self::REDIRECT_STATUSES, true ) ) {
			$location = self::first_header( $headers, 'location' );
			if ( '' === $location ) {
				return array( 'result' => new WP_Error( 'static_site_importer_url_redirect_missing_location', 'The URL returned a redirect without a Location header.' ) );
			}
			if ( $state['attempt'] >= self::MAX_REDIRECTS ) {
				return array( 'result' => new WP_Error( 'static_site_importer_url_redirect_limit', 'The URL exceeded the redirect limit.' ) );
			}
			$next   = self::resolve_redirect_url( $state['current'], $location );
			$target = is_wp_error( $next ) ? $next : self::validate_url( $next );
			if ( is_wp_error( $target ) ) {
				return array( 'result' => $target );
			}
			$state['redirects'][] = array(
				'from'        => $state['current'],
				'to'          => $target['url'],
				'status_code' => $status,
			);
			$state['current']     = $target['url'];
			$state['target']      = $target;
			++$state['attempt'];
			unset( $state['handle'] );
			return array( 'pending' => $state );
		}
		if ( $status < 200 || $status >= 300 ) {
			return array( 'result' => new WP_Error( 'static_site_importer_url_http_status', sprintf( 'The URL returned HTTP status %d.', $status ), array( 'status' => $status ) ) );
		}
		$content_type            = self::first_header( $headers, 'content-type' );
		$normalized_content_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		$allowed                 = $options['has_content_types_arg'] ? ( empty( $options['content_types'] ) || in_array( $normalized_content_type, $options['content_types'], true ) ) : self::is_html_content_type( $content_type );
		if ( ! $allowed ) {
			return array( 'result' => new WP_Error( $options['has_content_types_arg'] ? 'static_site_importer_url_unexpected_content_type' : 'static_site_importer_url_non_html', $options['has_content_types_arg'] ? sprintf( 'The URL returned unsupported content type %s.', '' !== $content_type ? $content_type : '(missing)' ) : 'The URL did not return an HTML content type.' ) );
		}
		if ( '' === trim( $body ) && ( ! $options['has_content_types_arg'] || ! empty( $options['content_types'] ) ) ) {
			return array( 'result' => new WP_Error( 'static_site_importer_url_empty_body', $options['has_content_types_arg'] ? 'The URL returned an empty response.' : 'The URL returned an empty HTML response.' ) );
		}
		return array(
			'result' => array(
				'body'     => $body,
				'metadata' => array(
					'source_type'     => 'url',
					'source_url'      => $state['initial'],
					'final_url'       => $state['current'],
					'status_code'     => $status,
					'content_type'    => $content_type,
					'fetch_started'   => $state['started'],
					'fetch_completed' => gmdate( 'c' ),
					'bytes'           => strlen( $body ),
					'redirects'       => $state['redirects'],
				),
			),
		);
	}

	private static function origin_key( array $target ): string {
		return $target['scheme'] . '://' . $target['host'] . ':' . $target['port'];
	}

	private static function start_transport( ?array $driver, array $target, array $options ) {
		if ( $driver && isset( $driver['start'] ) && is_callable( $driver['start'] ) ) {
			return $driver['start']( $target, $options );
		}
		return self::native_start( $target, $options );
	}

	private static function poll_transport( ?array $driver, $handle ) {
		if ( $driver && isset( $driver['poll'] ) && is_callable( $driver['poll'] ) ) {
			return $driver['poll']( $handle );
		}
		return self::native_poll( $handle );
	}

	private static function cancel_transport( ?array $driver, $handle, string $reason ): void {
		if ( $driver && isset( $driver['cancel'] ) && is_callable( $driver['cancel'] ) ) {
			$driver['cancel']( $handle, $reason );
			return;
		}
		if ( $handle instanceof Static_Site_Importer_URL_Fetcher_Native_Handle && null !== $handle->multi && null !== $handle->curl ) {
			curl_multi_remove_handle( $handle->multi, $handle->curl );
			curl_multi_close( $handle->multi );
			$handle->curl  = null;
			$handle->multi = null;
			return;
		}
		if ( is_object( $handle ) && isset( $handle->socket ) && is_resource( $handle->socket ) ) {
			fclose( $handle->socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a validated public HTTP socket.
		}
	}

	/** Start an IP-pinned native request, preferring curl_multi for bounded TLS progress. */
	private static function native_start( array $target, array $options ): Static_Site_Importer_URL_Fetcher_Native_Handle {
		if ( function_exists( 'curl_multi_init' ) ) {
			return self::native_curl_start( $target, $options );
		}
		return self::native_stream_start( $target, $options );
	}

	/** Start the no-curl nonblocking stream fallback. */
	private static function native_stream_start( array $target, array $options ): Static_Site_Importer_URL_Fetcher_Native_Handle {
		$ip               = $target['ips'][0];
		$remote           = sprintf( 'tcp://%s:%d', str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip, $target['port'] );
		$context          = self::tls_context( $target['host'] );
		$errno            = 0;
		$errstr           = '';
		$socket           = @stream_socket_client( $remote, $errno, $errstr, $options['timeout'], STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Nonblocking connection failure is returned through $errno/$errstr.
		$handle           = new Static_Site_Importer_URL_Fetcher_Native_Handle();
		$handle->socket   = $socket;
		$handle->target   = $target;
		$handle->options  = $options;
		$handle->started  = microtime( true );
		$handle->outbound = '';
		$handle->raw      = '';
		$handle->crypto   = 'https' !== $target['scheme'];
		$handle->error    = '';
		$handle->ip_index = 0;
		if ( false === $socket ) {
			$handle->error = sprintf( 'Could not connect to %s: %s', $target['host'], $errstr );
			return $handle;
		}
		stream_set_blocking( $socket, false );
		$host             = $target['host'] . ( ( 'https' === $target['scheme'] ? 443 : 80 ) === $target['port'] ? '' : ':' . $target['port'] );
		$handle->outbound = 'GET ' . $target['path'] . " HTTP/1.1\r\nHost: " . $host . "\r\nUser-Agent: StaticSiteImporter/1.0\r\nAccept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1\r\nConnection: close\r\n\r\n";
		return $handle;
	}

	/** Start a curl request pinned to one already validated IP without changing Host or SNI. */
	private static function native_curl_start( array $target, array $options ): Static_Site_Importer_URL_Fetcher_Native_Handle {
		$handle                  = new Static_Site_Importer_URL_Fetcher_Native_Handle();
		$handle->target          = $target;
		$handle->options         = $options;
		$handle->started         = microtime( true );
		$handle->ip_index        = 0;
		$handle->multi           = curl_multi_init();
		$handle->curl            = curl_init();
		$ip                      = $target['ips'][0];
		$host                    = $target['host'];
		$host_header             = $host . ( ( 'https' === $target['scheme'] ? 443 : 80 ) === $target['port'] ? '' : ':' . $target['port'] );
		$resolved_ip             = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$timeout_ms              = max( 1, (int) ceil( $options['timeout'] * 1000 ) );
		$url_host                = str_contains( $host, ':' ) ? '[' . $host . ']' : $host;
		$url                     = $target['scheme'] . '://' . $url_host . ( ( 'https' === $target['scheme'] ? 443 : 80 ) === $target['port'] ? '' : ':' . $target['port'] ) . $target['path'];

		$curl_options = array(
			CURLOPT_URL                    => $url,
			CURLOPT_HTTPGET                => true,
			CURLOPT_PROXY                  => '',
			CURLOPT_NOPROXY                => '*',
			CURLOPT_FOLLOWLOCATION         => false,
			CURLOPT_MAXREDIRS              => 0,
			CURLOPT_HTTP_TRANSFER_DECODING => false,
			CURLOPT_CONNECTTIMEOUT_MS      => $timeout_ms,
			CURLOPT_TIMEOUT_MS             => $timeout_ms,
			CURLOPT_SSL_VERIFYPEER         => true,
			CURLOPT_SSL_VERIFYHOST         => 2,
			CURLOPT_HTTPHEADER             => array( 'Host: ' . $host_header, 'User-Agent: StaticSiteImporter/1.0', 'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1', 'Connection: close' ),
			CURLOPT_HEADERFUNCTION         => static function( $curl, string $data ) use ( $handle ): int {
				if ( strlen( $handle->response_headers ) + strlen( $data ) > self::HEADER_MAX_BYTES ) {
					$handle->limit_error = 'headers';
					return 0;
				}
				$handle->response_headers .= $data;
				return strlen( $data );
			},
			CURLOPT_WRITEFUNCTION          => static function( $curl, string $data ) use ( $handle ): int {
				if ( strlen( $handle->body ) + strlen( $data ) > $handle->options['max_bytes'] ) {
					$handle->limit_error = 'body';
					return 0;
				}
				$handle->body .= $data;
				return strlen( $data );
			},
		);
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$curl_options[ CURLOPT_RESOLVE ] = array( $host . ':' . $target['port'] . ':' . $resolved_ip );
		}
		$ca_bundle    = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( is_readable( $ca_bundle ) ) {
			$curl_options[ CURLOPT_CAINFO ] = $ca_bundle;
		}
		curl_setopt_array( $handle->curl, $curl_options );
		curl_multi_add_handle( $handle->multi, $handle->curl );
		return $handle;
	}

	/** Build the verified TLS context used by IP-pinned connections. */
	private static function tls_context( string $host ) {
		return stream_context_create( array(
			'ssl' => array(
				'SNI_enabled'      => true,
				'peer_name'        => $host,
				'verify_peer'      => true,
				'verify_peer_name' => true,
				'cafile'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			),
		) );
	}

	/** Poll a nonblocking connection. Null means it remains in flight. */
	private static function native_poll( Static_Site_Importer_URL_Fetcher_Native_Handle $handle ) {
		if ( null !== $handle->multi ) {
			return self::native_curl_poll( $handle );
		}
		if ( '' !== $handle->error ) {
			if ( self::native_retry( $handle ) ) {
				return null;
			}
			return new WP_Error( 'static_site_importer_url_connect_failed', $handle->error );
		}
		if ( self::native_deadline_exhausted( $handle ) || microtime( true ) - $handle->started >= $handle->options['timeout'] ) {
			$deadline_exhausted = self::native_deadline_exhausted( $handle ) || ! empty( $handle->options['deadline_limited'] );
			if ( self::native_retry( $handle ) ) {
				return null;
			}
			return new WP_Error( $deadline_exhausted ? 'static_site_importer_url_deadline_exhausted' : 'static_site_importer_url_timeout', $deadline_exhausted ? 'The URL request deadline was exhausted.' : 'The URL request timed out.' );
		}
		if ( ! $handle->crypto ) {
			$crypto = @stream_socket_enable_crypto( $handle->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- TLS failures are returned as a reason-coded URL error.
			if ( false === $crypto ) {
				if ( self::native_retry( $handle ) ) {
					return null;
				}
				return new WP_Error( 'static_site_importer_url_tls_failed', 'Could not establish a verified TLS connection to the URL.' );
			}
			if ( 0 === $crypto ) {
				return null;
			}
			$handle->crypto = true;
		}
		if ( '' !== $handle->outbound ) {
			$written = @fwrite( $handle->socket, $handle->outbound ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes an HTTP request to a validated public socket.
			if ( false === $written ) {
				if ( self::native_retry( $handle ) ) {
					return null;
				}
				return new WP_Error( 'static_site_importer_url_connect_failed', 'Could not write the URL request.' );
			}
			$handle->outbound = (string) substr( $handle->outbound, $written );
			return null;
		}
		while ( ! feof( $handle->socket ) ) {
			$chunk = @fread( $handle->socket, self::BODY_READ_CHUNK ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Nonblocking reads return no bytes until the validated socket is ready.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$handle->raw .= $chunk;
			if ( strlen( $handle->raw ) > $handle->options['max_bytes'] + self::HEADER_MAX_BYTES ) {
				self::cancel_transport( null, $handle, 'too_large' );
				return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
			}
			$separator = strpos( $handle->raw, "\r\n\r\n" );
			if ( ( false === $separator && strlen( $handle->raw ) > self::HEADER_MAX_BYTES ) || ( false !== $separator && $separator + 4 > self::HEADER_MAX_BYTES ) ) {
				self::cancel_transport( null, $handle, 'too_large' );
				return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
			}
		}
		if ( ! feof( $handle->socket ) ) {
			return null;
		}
		self::cancel_transport( null, $handle, 'completed' );
		$separator = strpos( $handle->raw, "\r\n\r\n" );
		if ( false === $separator ) {
			return new WP_Error( 'static_site_importer_url_malformed_response', 'The URL returned a malformed HTTP response.' );
		}
		$body = substr( $handle->raw, $separator + 4 );
		if ( strlen( $body ) > $handle->options['max_bytes'] ) {
			return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
		}
		return self::parse_response( substr( $handle->raw, 0, $separator ), $body );
	}

	/** Advance curl without blocking; completion is collected from its per-request multi handle. */
	private static function native_curl_poll( Static_Site_Importer_URL_Fetcher_Native_Handle $handle ) {
		if ( self::native_deadline_exhausted( $handle ) ) {
			self::cancel_transport( null, $handle, 'deadline_exhausted' );
			return new WP_Error( 'static_site_importer_url_deadline_exhausted', 'The URL request deadline was exhausted.' );
		}
		do {
			$status = curl_multi_exec( $handle->multi, $running );
		} while ( CURLM_CALL_MULTI_PERFORM === $status );
		if ( CURLM_OK !== $status ) {
			self::cancel_transport( null, $handle, 'curl_multi_failed' );
			return new WP_Error( 'static_site_importer_url_connect_failed', 'Could not progress the URL request.' );
		}
		$info = curl_multi_info_read( $handle->multi );
		if ( false === $info ) {
			return null;
		}
		$result = (int) $info['result'];
		if ( '' !== $handle->limit_error ) {
			self::cancel_transport( null, $handle, 'too_large' );
			return new WP_Error( 'static_site_importer_url_too_large', 'The URL response exceeded the maximum allowed size.' );
		}
		if ( CURLE_OK !== $result ) {
			$error              = curl_error( $handle->curl );
			$deadline_exhausted = ! empty( $handle->options['deadline_limited'] );
			if ( self::native_retry( $handle ) ) {
				return null;
			}
			self::cancel_transport( null, $handle, 'curl_failed' );
			if ( CURLE_OPERATION_TIMEDOUT === $result ) {
				return new WP_Error( $deadline_exhausted ? 'static_site_importer_url_deadline_exhausted' : 'static_site_importer_url_timeout', $deadline_exhausted ? 'The URL request deadline was exhausted.' : 'The URL request timed out.' );
			}
			return new WP_Error( 'static_site_importer_url_connect_failed', '' !== $error ? $error : 'Could not connect to the URL.' );
		}
		self::cancel_transport( null, $handle, 'completed' );
		$headers = preg_split( "/\r\n\r\n|\n\n|\r\r/", trim( $handle->response_headers ) );
		$header  = is_array( $headers ) ? (string) end( $headers ) : '';
		return self::parse_response( $header, $handle->body );
	}

	private static function native_deadline_exhausted( Static_Site_Importer_URL_Fetcher_Native_Handle $handle ): bool {
		return null !== $handle->options['deadline'] && is_callable( $handle->options['clock'] ) && call_user_func( $handle->options['clock'] ) >= $handle->options['deadline'];
	}

	/** Retry another already validated DNS address without re-resolving the hostname. */
	private static function native_retry( Static_Site_Importer_URL_Fetcher_Native_Handle $handle ): bool {
		self::cancel_transport( null, $handle, 'retry_ip' );
		if ( self::native_deadline_exhausted( $handle ) ) {
			return false;
		}
		$next_ip = $handle->ip_index + 1;
		if ( ! isset( $handle->target['ips'][ $next_ip ] ) ) {
			return false;
		}
		$target                = $handle->target;
		$connect_target        = $target;
		$connect_target['ips'] = array_slice( $target['ips'], $next_ip );
		$options               = $handle->options;
		if ( null !== $options['deadline'] && is_callable( $options['clock'] ) ) {
			$options['timeout'] = max( 0.001, min( $options['timeout'], $options['deadline'] - call_user_func( $options['clock'] ) ) );
		}
		$next                  = self::native_start( $connect_target, $options );
		foreach ( get_object_vars( $next ) as $name => $value ) {
			$handle->$name = $value;
		}
		$handle->target   = $target;
		$handle->ip_index = $next_ip;
		return true;
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
		$markup_bytes     = strlen( $html );
		$script_count     = preg_match_all( '#<script\b#i', $html );
		$app_shell        = preg_match( '#\bid=(?:"|\')(?:root|app|__next|gatsby-focus-wrapper|mount)(?:"|\')#i', $html );
		$text_html        = preg_replace( '#<script\b[^>]*>.*?</script>#is', ' ', $html );
		$text_html        = preg_replace( '#<style\b[^>]*>.*?</style>#is', ' ', (string) $text_html );
		$text_html        = preg_replace( '#<template\b[^>]*>.*?</template>#is', ' ', (string) $text_html );
		$content_elements = preg_match_all( '#<(?:main|article|h[1-6]|p)\b#i', (string) $text_html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for non-WordPress smoke tests; WordPress runtimes use wp_strip_all_tags().
		$stripped   = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $text_html ) : strip_tags( (string) $text_html );
		$text       = html_entity_decode( trim( preg_replace( '/\s+/', ' ', $stripped ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text_chars = strlen( $text );
		$text_ratio = $markup_bytes > 0 ? $text_chars / $markup_bytes : 0;

		if ( ( $script_count >= 20 && $text_chars < 1000 && $text_ratio < 0.02 && 0 === $content_elements ) || ( $script_count >= 3 && $text_chars < 200 && $app_shell ) ) {
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
		$remote  = sprintf( 'tcp://%s:%d', str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip, $target['port'] );
		$context = self::tls_context( $target['host'] );
		$errno   = 0;
		$errstr  = '';
		$socket  = stream_socket_client( $remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context );
		if ( false === $socket ) {
			return new WP_Error( 'static_site_importer_url_connect_failed', sprintf( 'Could not connect to %s: %s', $target['host'], $errstr ) );
		}

		stream_set_timeout( $socket, $timeout );
		if ( 'https' === $target['scheme'] ) {
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
			$separator = strpos( $raw, "\r\n\r\n" );
			if ( ( false === $separator && strlen( $raw ) > self::HEADER_MAX_BYTES ) || ( false !== $separator && $separator + 4 > self::HEADER_MAX_BYTES ) ) {
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

		$provided = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_url_resolved_ips', null, $host ) : null;
		if ( is_wp_error( $provided ) ) {
			return $provided;
		}
		if ( null !== $provided && ! is_array( $provided ) ) {
			return new WP_Error( 'static_site_importer_url_dns_provider_invalid', 'The URL host resolver returned an invalid response.' );
		}

		$ips = is_array( $provided ) ? $provided : array();
		if ( null === $provided && function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported runtimes may expose dns_get_record() while warning and returning no records; the fail-closed fallback handles that outcome.
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

		if ( null === $provided && ! $ips ) {
			$records = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is converted to a reason-coded, fail-closed URL error below.
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
