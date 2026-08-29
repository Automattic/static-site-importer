<?php
/**
 * Unified WP-CLI import host: continuation, receipts, malformed input, fresh runtimes.
 *
 * Run from the repository root:
 * php tests/smoke-cli-import-continuation.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message = '', private $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $options = 0 ) {
		return json_encode( $value, $options );
	}
}

require dirname( __DIR__ ) . '/includes/cli.php';

$assertions = 0;
$failures   = array();
$assert     = static function ( bool $condition, string $label ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$missing = static_site_importer_cli_import_input( array(), array() );
$assert( is_wp_error( $missing ) && 'static_site_importer_cli_request_invalid' === $missing->get_error_code(), 'malformed-missing-request' );

$conflict = static_site_importer_cli_import_input(
	array(),
	array(
		'request' => '/tmp/request.json',
		'url'     => 'https://example.com/',
	)
);
$assert( is_wp_error( $conflict ) && 'static_site_importer_cli_request_conflict' === $conflict->get_error_code(), 'malformed-request-url-conflict' );

$bad_file = static_site_importer_cli_read_request_json( '/tmp/ssi-missing-import-request.json' );
$assert( is_wp_error( $bad_file ), 'malformed-missing-file' );

$list_path = tempnam( sys_get_temp_dir(), 'ssi-import-list-' );
file_put_contents( $list_path, "[1]\n" );
$list = static_site_importer_cli_read_request_json( $list_path );
unlink( $list_path );
$assert( is_wp_error( $list ) && 'static_site_importer_cli_request_invalid' === $list->get_error_code(), 'malformed-json-list' );

$invalid_json_path = tempnam( sys_get_temp_dir(), 'ssi-import-bad-' );
file_put_contents( $invalid_json_path, '{not-json' );
$invalid_json = static_site_importer_cli_read_request_json( $invalid_json_path );
unlink( $invalid_json_path );
$assert( is_wp_error( $invalid_json ), 'malformed-invalid-json' );

$request_path = tempnam( sys_get_temp_dir(), 'ssi-import-req-' );
file_put_contents(
	$request_path,
	wp_json_encode(
		array(
			'operation' => 'nope',
			'source'    => array(
				'type' => 'html',
				'html' => '<h1>Hi</h1>',
			),
		)
	)
);
$bad_operation = static_site_importer_cli_import_input( array(), array( 'request' => $request_path ) );
unlink( $request_path );
$assert( is_wp_error( $bad_operation ) && 'static_site_importer_invalid_import_operation' === $bad_operation->get_error_code(), 'malformed-operation' );

$html_path = tempnam( sys_get_temp_dir(), 'ssi-import-html-' );
file_put_contents(
	$html_path,
	wp_json_encode(
		array(
			'operation' => 'apply',
			'source'    => array(
				'type'  => 'files',
				'files' => array(
					array(
						'path'    => 'website/index.html',
						'content' => '<h1>Keep out of continuation</h1>',
					),
				),
			),
			'slug'      => 'northwind',
		)
	)
);
$files_input = static_site_importer_cli_import_input( array(), array( 'request' => $html_path ) );
unlink( $html_path );
$assert( is_array( $files_input ) && 'files' === ( $files_input['source']['type'] ?? '' ), 'request-files-source' );

$continued_files = static_site_importer_cli_apply_import_id( $files_input, 'abc' );
$assert( array( 'type' => 'files', 'import_id' => 'abc' ) === ( $continued_files['source'] ?? null ), 'files-continuation-strips-source-bytes' );
$assert( 'northwind' === ( $continued_files['slug'] ?? '' ), 'continuation-preserves-import-options' );

$url_input       = static_site_importer_cli_import_input( array(), array( 'url' => 'https://example.com/', 'slug' => 'example' ) );
$continued_url   = static_site_importer_cli_apply_import_id( $url_input, 'url-1' );
$assert(
	array(
		'type'      => 'url',
		'import_id' => 'url-1',
		'url'       => 'https://example.com/',
	) === ( $continued_url['source'] ?? null ),
	'url-continuation-keeps-url'
);

$requests = array();
$queue    = array(
	array(
		'success'      => true,
		'continuation' => true,
		'import_id'    => 'opaque-1',
	),
	array(
		'success'      => true,
		'continuation' => true,
		'import_id'    => 'opaque-1',
	),
	array(
		'success'      => true,
		'continuation' => false,
		'result'       => array( 'theme_slug' => 'northwind' ),
	),
);
$receipt  = static_site_importer_cli_run_import_host(
	$files_input,
	static function ( array $request ) use ( &$requests, &$queue ): array {
		$requests[] = $request;
		return array_shift( $queue );
	}
);
$assert( 3 === count( $requests ), 'multi-step-runs-three-invocations' );
$assert( isset( $requests[0]['source']['files'] ), 'first-step-keeps-source-bytes' );
$assert( array( 'type' => 'files', 'import_id' => 'opaque-1' ) === ( $requests[1]['source'] ?? null ), 'second-step-is-opaque-continuation' );
$assert( array( 'type' => 'files', 'import_id' => 'opaque-1' ) === ( $requests[2]['source'] ?? null ), 'third-step-is-opaque-continuation' );
$assert( 'static-site-importer/import-cli-receipt/v1' === ( $receipt['schema'] ?? '' ), 'terminal-receipt-schema' );
$assert( 'completed' === ( $receipt['status'] ?? '' ) && 3 === ( $receipt['steps'] ?? 0 ), 'terminal-success-status' );
$assert( true === ( $receipt['response']['success'] ?? false ) && empty( $receipt['response']['continuation'] ), 'terminal-success-has-no-continuation' );

$failed = static_site_importer_cli_run_import_host(
	array( 'source' => array( 'type' => 'html', 'html' => '<h1>x</h1>' ) ),
	static fn (): array => array(
		'success' => false,
		'error'   => array(
			'code'    => 'materialization_failed',
			'message' => 'nope',
		),
	)
);
$assert( 'failed' === ( $failed['status'] ?? '' ) && 1 === ( $failed['steps'] ?? 0 ), 'terminal-failure-status' );
$assert( 'materialization_failed' === ( $failed['response']['error']['code'] ?? '' ), 'terminal-failure-error' );

$missing_id = static_site_importer_cli_run_import_host(
	array( 'source' => array( 'type' => 'zip' ) ),
	static fn (): array => array(
		'success'      => true,
		'continuation' => true,
	)
);
$assert( 'failed' === ( $missing_id['status'] ?? '' ) && 'static_site_importer_cli_import_id_missing' === ( $missing_id['response']['error']['code'] ?? '' ), 'continuation-without-import-id-fails' );

$bounded = static_site_importer_cli_run_import_host(
	array(
		'source' => array(
			'type' => 'url',
			'url'  => 'https://example.com/',
		),
	),
	static fn (): array => array(
		'success'      => true,
		'continuation' => true,
		'import_id'    => 'opaque-url-id',
	),
	2
);
$assert( 'failed' === ( $bounded['status'] ?? '' ) && 2 === ( $bounded['steps'] ?? 0 ), 'bound-exceeded-steps' );
$assert( 'static_site_importer_cli_continuation_bound_exceeded' === ( $bounded['response']['error']['code'] ?? '' ), 'bound-exceeded-code' );

$leaked = static_site_importer_cli_import_receipt(
	array(
		'success'      => true,
		'continuation' => true,
		'import_id'    => 'leaked',
	),
	1
);
$assert( 'failed' === ( $leaked['status'] ?? '' ) && 'static_site_importer_cli_nonterminal_receipt' === ( $leaked['response']['error']['code'] ?? '' ), 'receipt-rejects-continuation-as-success' );

$spec = static_site_importer_cli_import_fresh_runtime_spec( '/tmp/ssi-step.json' );
$assert( true === ( $spec['options']['launch'] ?? null ), 'fresh-runtime-launches-new-process' );
$assert( false === ( $spec['options']['exit_error'] ?? null ), 'fresh-runtime-does-not-halt-on-child-error' );
$assert( 'all' === ( $spec['options']['return'] ?? null ), 'fresh-runtime-captures-full-process' );
$assert( ! array_key_exists( 'parse', $spec['options'] ), 'fresh-runtime-decodes-stdout-locally' );
$assert( str_contains( $spec['command'], 'static-site-importer import' ) && str_contains( $spec['command'], '--single-step' ), 'fresh-runtime-invokes-single-step-command' );
$assert( str_contains( $spec['command'], escapeshellarg( '/tmp/ssi-step.json' ) ), 'fresh-runtime-passes-request-file' );
$assert( ! str_contains( $spec['command'], 'content_base64' ) && ! str_contains( $spec['command'], 'website/index.html' ), 'fresh-runtime-command-has-no-source-payload' );

$decoded = static_site_importer_cli_decode_import_step( "Deprecated: noise\n{\"success\":true,\"continuation\":false}\n" );
$assert( true === ( $decoded['success'] ?? false ) && empty( $decoded['continuation'] ), 'fresh-process-decoding-selects-final-json-line' );
$assert( null === static_site_importer_cli_decode_import_step( 'not json' ), 'fresh-process-output-without-json-is-rejected' );

class WP_CLI {
	public static array $lines = array();
	public static ?int $halt   = null;
	public static array $commands = array();
	public static function line( string $text ): void {
		self::$lines[] = $text;
	}
	public static function halt( int $code ): void {
		self::$halt = $code;
		throw new RuntimeException( 'halt:' . $code );
	}
	public static function error( string $message ): void {
		throw new RuntimeException( $message );
	}
	public static function runcommand( string $command, array $options ) {
		self::$commands[] = array(
			'command' => $command,
			'options' => $options,
		);
		return (object) array(
			'stdout'      => "notice\n" . wp_json_encode(
				array(
					'success' => false,
					'error'   => array(
						'code'    => 'materialization_failed',
						'message' => 'child failed',
					),
				)
			),
			'stderr'      => '',
			'return_code' => 1,
		);
	}
}

try {
	static_site_importer_cli_emit_import_receipt( $failed );
} catch ( RuntimeException $error ) {
	$assert( 'halt:1' === $error->getMessage(), 'terminal-failure-exits-nonzero' );
}
$emitted = json_decode( (string) ( WP_CLI::$lines[0] ?? '' ), true );
$assert( is_array( $emitted ) && 'failed' === ( $emitted['status'] ?? '' ), 'terminal-failure-prints-json-receipt' );
$assert( ! str_contains( (string) ( WP_CLI::$lines[0] ?? '' ), 'Success:' ), 'terminal-failure-does-not-print-success' );

WP_CLI::$lines = array();
WP_CLI::$halt  = null;
static_site_importer_cli_emit_import_step(
	array(
		'success'      => true,
		'continuation' => true,
		'import_id'    => 'opaque-1',
	)
);
$step = json_decode( (string) ( WP_CLI::$lines[0] ?? '' ), true );
$assert( null === WP_CLI::$halt, 'step-continuation-does-not-halt' );
$assert( true === ( $step['continuation'] ?? false ) && 'static-site-importer/import-cli-receipt/v1' !== ( $step['schema'] ?? '' ), 'step-emits-ability-envelope-not-terminal-receipt' );

$fresh_fail = static_site_importer_cli_import_run_fresh_runtime( array( 'source' => array( 'type' => 'html' ) ) );
$assert( 'materialization_failed' === ( $fresh_fail['error']['code'] ?? '' ), 'fresh-runtime-decodes-child-json' );
$assert( 1 === count( WP_CLI::$commands ), 'fresh-runtime-runcommand-once' );
$assert( true === ( WP_CLI::$commands[0]['options']['launch'] ?? null ), 'fresh-runtime-runcommand-launch' );
$assert( str_contains( (string) ( WP_CLI::$commands[0]['command'] ?? '' ), '--single-step' ), 'fresh-runtime-runcommand-single-step' );

if ( $failures ) {
	fwrite( STDERR, "Unified CLI import smoke failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "Unified CLI import continuation smoke passed ({$assertions} assertions).\n";
