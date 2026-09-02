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
if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['ssi_cli_filters'] = array();
	function add_filter( string $hook, callable $callback ): void {
		$GLOBALS['ssi_cli_filters'][ $hook ][] = $callback;
	}
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['ssi_cli_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

require dirname( __DIR__ ) . '/includes/cli.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-portable-source-manifest.php';

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

$bundle_dir = sys_get_temp_dir() . '/ssi-request-bundle-' . bin2hex( random_bytes( 6 ) );
mkdir( $bundle_dir );
$bundle_request = $bundle_dir . '/request.json';
$bundle_source  = $bundle_dir . '/source';
mkdir( $bundle_source );
file_put_contents( $bundle_source . '/index.html', '<h1>Bundle</h1>' );
file_put_contents(
	$bundle_source . '/' . Static_Site_Importer_Portable_Source_Manifest::FILENAME,
	wp_json_encode(
		array(
			'schema'     => Static_Site_Importer_Portable_Source_Manifest::SCHEMA,
			'root'       => '.',
			'entrypoint' => 'index.html',
			'files'      => array(
				array(
					'path'   => 'index.html',
					'sha256' => hash( 'sha256', '<h1>Bundle</h1>' ),
				),
			),
		)
	)
);
file_put_contents(
	$bundle_request,
	wp_json_encode(
		array(
			'operation' => 'apply',
			'source'    => array(
				'type' => 'files',
				'ref'  => 'request-bundle:source',
			),
		)
	)
);
$bundle_input = static_site_importer_cli_import_input( array(), array( 'request' => $bundle_request ) );
$bundle_real_dir = realpath( $bundle_dir );
$assert( is_array( $bundle_input ) && $bundle_real_dir === ( $bundle_input['_cli_request_bundle_dir'] ?? '' ), 'request-bundle-registers-directory' );
$assert( realpath( $bundle_source ) === static_site_importer_cli_request_bundle_path( $bundle_request, 'request-bundle:source' ), 'request-bundle-resolves-source' );
$resolved_bundle = apply_filters( 'static_site_importer_resolve_source_reference', null, 'request-bundle:source', 'files' );
$bundle_files    = array_column( $resolved_bundle['source']['files'] ?? array(), null, 'path' );
$assert( isset( $bundle_files['index.html'] ), 'request-bundle-registers-opaque-resolver' );
$assert( '<h1>Bundle</h1>' === $resolved_bundle['payload_reader']->read( $bundle_files['index.html']['payload_reference'] ), 'request-bundle-reader-returns-source-bytes' );
$projected_bundle = Static_Site_Importer_Portable_Source_Manifest::project(
	array( 'files' => $resolved_bundle['source']['files'] ),
	$resolved_bundle['payload_reader']
);
$assert( ! is_wp_error( $projected_bundle ) && array( 'index.html' ) === array_column( $projected_bundle['files'] ?? array(), 'path' ), 'request-bundle-projects-portable-manifest-through-payload-reader' );
$figma_source = $bundle_dir . '/design.fig';
file_put_contents( $figma_source, 'figma bytes' );
$figma_bundle_input = static_site_importer_cli_prepare_request_bundle(
	array( 'source' => array( 'type' => 'figma', 'ref' => 'request-bundle:design.fig' ) ),
	$bundle_request
);
$resolved_figma = apply_filters( 'static_site_importer_resolve_source_reference', null, 'request-bundle:design.fig', 'figma' );
$assert( is_array( $figma_bundle_input ) && realpath( $figma_source ) === ( $resolved_figma['source']['figma_file']['staged_path'] ?? '' ), 'request-bundle-registers-figma-source' );
$bundle_step = static_site_importer_cli_write_step_request( $bundle_input );
$assert( is_string( $bundle_step ) && $bundle_real_dir === dirname( $bundle_step ), 'request-bundle-keeps-fresh-runtime-adjacent' );
if ( is_string( $bundle_step ) ) {
	unlink( $bundle_step );
}
$traversal = static_site_importer_cli_request_bundle_path( $bundle_request, 'request-bundle:../source' );
$assert( is_wp_error( $traversal ) && 'static_site_importer_cli_request_bundle_invalid' === $traversal->get_error_code(), 'request-bundle-rejects-traversal' );
$missing_source = static_site_importer_cli_request_bundle_path( $bundle_request, 'request-bundle:missing' );
$assert( is_wp_error( $missing_source ), 'request-bundle-rejects-missing-source' );
$bundle_link = $bundle_dir . '/linked';
symlink( $bundle_source, $bundle_link );
$linked_source = static_site_importer_cli_request_bundle_path( $bundle_request, 'request-bundle:linked' );
$assert( is_wp_error( $linked_source ), 'request-bundle-rejects-symlink' );
unlink( $bundle_link );
unlink( $figma_source );
unlink( $bundle_source . '/' . Static_Site_Importer_Portable_Source_Manifest::FILENAME );
unlink( $bundle_source . '/index.html' );
rmdir( $bundle_source );
unlink( $bundle_request );
rmdir( $bundle_dir );

$bounded_bundle_dir = sys_get_temp_dir() . '/ssi-bounded-request-bundle-' . bin2hex( random_bytes( 6 ) );
mkdir( $bounded_bundle_dir );
$inline_styles  = str_repeat( '<style>.bounded{display:grid}</style>', 9 );
$inline_scripts = str_repeat( '<script>document.documentElement.dataset.ready="true"</script>', 2 );
file_put_contents( $bounded_bundle_dir . '/index.html', $inline_styles . $inline_scripts . '<h1>Bounded</h1>' );
for ( $index = 0; $index < 500; ++$index ) {
	file_put_contents( $bounded_bundle_dir . '/asset-' . $index . '.css', 'a{}' );
}
$bounded_bundle = static_site_importer_cli_request_bundle_files( $bounded_bundle_dir );
$assert( is_array( $bounded_bundle ) && 501 === count( $bounded_bundle['files'] ?? array() ), 'request-bundle-retains-files-above-compiler-default' );
$assert( array( 'max_files' => 512, 'max_file_bytes' => 10485760, 'max_total_bytes' => 335544320 ) === ( $bounded_bundle['compiler_limits'] ?? null ), 'request-bundle-reserves every inline style and script expansion' );
foreach ( scandir( $bounded_bundle_dir ) as $entry ) {
	if ( '.' !== $entry && '..' !== $entry ) {
		unlink( $bounded_bundle_dir . '/' . $entry );
	}
}
rmdir( $bounded_bundle_dir );

$count_limit_dir = sys_get_temp_dir() . '/ssi-request-bundle-count-' . bin2hex( random_bytes( 6 ) );
mkdir( $count_limit_dir );
for ( $index = 0; $index <= 5000; ++$index ) {
	file_put_contents( $count_limit_dir . '/asset-' . $index . '.css', '' );
}
$count_limit = static_site_importer_cli_request_bundle_files( $count_limit_dir );
$assert( is_wp_error( $count_limit ) && 'static_site_importer_cli_request_bundle_file_limit_exceeded' === $count_limit->get_error_code(), 'request-bundle-rejects-file-count-over-hard-boundary' );
foreach ( scandir( $count_limit_dir ) as $entry ) {
	if ( '.' !== $entry && '..' !== $entry ) {
		unlink( $count_limit_dir . '/' . $entry );
	}
}
rmdir( $count_limit_dir );

$file_limit_dir = sys_get_temp_dir() . '/ssi-request-bundle-file-' . bin2hex( random_bytes( 6 ) );
mkdir( $file_limit_dir );
$file_limit_handle = fopen( $file_limit_dir . '/asset.css', 'w' );
ftruncate( $file_limit_handle, 10485761 );
fclose( $file_limit_handle );
$file_limit = static_site_importer_cli_request_bundle_files( $file_limit_dir );
$assert( is_wp_error( $file_limit ) && 'static_site_importer_cli_request_bundle_file_too_large' === $file_limit->get_error_code(), 'request-bundle-rejects-file-bytes-over-hard-boundary' );
unlink( $file_limit_dir . '/asset.css' );
rmdir( $file_limit_dir );

$total_limit_dir = sys_get_temp_dir() . '/ssi-request-bundle-total-' . bin2hex( random_bytes( 6 ) );
mkdir( $total_limit_dir );
for ( $index = 0; $index < 26; ++$index ) {
	$total_limit_handle = fopen( $total_limit_dir . '/asset-' . $index . '.css', 'w' );
	ftruncate( $total_limit_handle, 10485760 );
	fclose( $total_limit_handle );
}
$total_limit = static_site_importer_cli_request_bundle_files( $total_limit_dir );
$assert( is_wp_error( $total_limit ) && 'static_site_importer_cli_request_bundle_total_too_large' === $total_limit->get_error_code(), 'request-bundle-rejects-aggregate-bytes-over-hard-boundary' );
foreach ( scandir( $total_limit_dir ) as $entry ) {
	if ( '.' !== $entry && '..' !== $entry ) {
		unlink( $total_limit_dir . '/' . $entry );
	}
}
rmdir( $total_limit_dir );

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

$progress_events = array();
$slow_queue      = array(
	array(
		'success'             => true,
		'continuation'        => true,
		'continuation_reason' => 'run_in_progress',
		'import_id'           => 'durable-slow-run',
		'artifact_run'        => array(
			'phase'    => 'compile_pages',
			'progress' => array( 'page_count' => 34, 'receipt_count' => 12 ),
		),
	),
	array(
		'success'      => true,
		'continuation' => false,
		'result'       => array( 'theme_slug' => 'slow-import' ),
	),
);
$slow_receipt = static_site_importer_cli_run_import_host(
	$files_input,
	static function () use ( &$slow_queue, &$progress_events ): array {
		// A synchronous worker starts only after the durable-phase heartbeat is emitted.
		if ( 1 === count( $slow_queue ) && 'heartbeat' !== ( $progress_events[1]['event'] ?? '' ) ) {
			throw new RuntimeException( 'Slow worker started without a heartbeat.' );
		}
		return array_shift( $slow_queue );
	},
	0,
	static function ( array $event ) use ( &$progress_events ): void {
		$progress_events[] = $event;
	},
	'wp static-site-importer import --request=\'/tmp/request.json\' --import-id=<import-id>'
);
$assert( 'completed' === ( $slow_receipt['status'] ?? '' ) && 2 === ( $slow_receipt['steps'] ?? 0 ), 'slow-work-completes-with-one-terminal-receipt' );
$assert( 2 === count( $progress_events ), 'slow-work-emits-continuation-and-heartbeat' );
$assert( 'continuation' === ( $progress_events[0]['event'] ?? '' ) && 'compile_pages' === ( $progress_events[0]['phase'] ?? '' ), 'continuation-projects-durable-phase' );
$assert( 12 === ( $progress_events[0]['completed_units'] ?? -1 ) && 34 === ( $progress_events[0]['total_units'] ?? -1 ), 'continuation-projects-durable-unit-counts' );
$assert( 'heartbeat' === ( $progress_events[1]['event'] ?? '' ) && 'run_in_progress' === ( $progress_events[1]['continuation_reason'] ?? '' ), 'slow-work-heartbeat-precedes-next-synchronous-step' );
$assert( 'wp static-site-importer import --request=\'/tmp/request.json\' --import-id=durable-slow-run' === ( $progress_events[1]['resume_command'] ?? '' ), 'progress-includes-exact-resume-command' );

$interrupted_step = array(
	'success'             => true,
	'continuation'        => true,
	'continuation_reason' => 'deadline_exhausted',
	'import_id'           => 'durable-interrupted-run',
	'artifact_run'        => array(
		'phase'    => 'compile_pages',
		'progress' => array( 'page_count' => 2, 'receipt_count' => 1 ),
	),
);
$resume_requests = array();
$resumed = static_site_importer_cli_run_import_host(
	static_site_importer_cli_apply_import_id( $files_input, (string) $interrupted_step['import_id'] ),
	static function ( array $request ) use ( &$resume_requests ): array {
		$resume_requests[] = $request;
		return array( 'success' => true, 'continuation' => false, 'result' => array( 'theme_slug' => 'resumed-import' ) );
	}
);
$assert( 'compile_pages' === ( $interrupted_step['artifact_run']['phase'] ?? '' ) && 1 === ( $interrupted_step['artifact_run']['progress']['receipt_count'] ?? 0 ), 'interruption-keeps-durable-progress' );
$assert( array( 'type' => 'files', 'import_id' => 'durable-interrupted-run' ) === ( $resume_requests[0]['source'] ?? null ), 'interruption-resumes-using-existing-durable-import-id' );
$assert( 'completed' === ( $resumed['status'] ?? '' ) && 1 === ( $resumed['steps'] ?? 0 ), 'interrupted-run-resumes-to-terminal-receipt' );

$lifecycle_requests = array();
$lifecycle_queue    = array(
	array(
		'success'               => true,
		'continuation'          => true,
		'continuation_reason'   => 'dependencies_prepared',
		'import_id'             => 'durable-direct-run',
		'result'                => array(
			'status'                       => 'dependencies_prepared',
			'runtime_lifecycle_checkpoint' => 'checkpoint-1453',
			'fresh_runtime'                => array(
				'request_id'              => 'prepare-request-1453',
				'lifecycle_checkpoint_id' => 'checkpoint-1453',
			),
		),
	),
	array(
		'success'      => true,
		'continuation' => false,
		'result'       => array( 'theme_slug' => 'fresh-runtime' ),
	),
);
$lifecycle_receipt = static_site_importer_cli_run_import_host(
	$files_input,
	static function ( array $request ) use ( &$lifecycle_requests, &$lifecycle_queue ): array {
		$lifecycle_requests[] = $request;
		return array_shift( $lifecycle_queue );
	}
);
$assert( ! isset( $lifecycle_requests[0]['runtime_lifecycle_phase'] ), 'ordinary-request-omits-caller-lifecycle' );
$assert( 'resume' === ( $lifecycle_requests[1]['runtime_lifecycle_phase'] ?? '' ), 'dependency-continuation-enters-resume' );
$assert( 'prepare-request-1453' === ( $lifecycle_requests[1]['runtime_lifecycle_request_id'] ?? '' ) && 'checkpoint-1453' === ( $lifecycle_requests[1]['runtime_lifecycle_checkpoint'] ?? '' ), 'fresh-runtime-preserves-lifecycle-transport' );
$assert( array( 'type' => 'files', 'import_id' => 'durable-direct-run' ) === ( $lifecycle_requests[1]['source'] ?? null ), 'fresh-runtime-preserves-direct-run-identity' );
$assert( 'completed' === ( $lifecycle_receipt['status'] ?? '' ) && 2 === ( $lifecycle_receipt['steps'] ?? 0 ), 'dependency-checkpoint-resumes-terminally' );

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

$resume_command = static_site_importer_cli_import_resume_command(
	array( 'url' => 'https://example.com/', 'slug' => 'example', 'activate' => true, 'max-steps' => 1 ),
	'opaque-resume-id'
);
$assert( "wp static-site-importer import --url='https://example.com/' --slug='example' --activate --import-id=opaque-resume-id" === $resume_command, 'resume-command-preserves-import-arguments-and-omits-host-controls' );

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
static_site_importer_cli_emit_import_progress( $progress_events[0] );
static_site_importer_cli_emit_import_receipt( $slow_receipt );
$typed_progress = json_decode( (string) ( WP_CLI::$lines[0] ?? '' ), true );
$terminal_after_progress = json_decode( (string) ( WP_CLI::$lines[1] ?? '' ), true );
$assert( 'static-site-importer/import-cli-progress/v1' === ( $typed_progress['schema'] ?? '' ), 'json-consumers-receive-typed-progress-events' );
$assert( 'static-site-importer/import-cli-receipt/v1' === ( $terminal_after_progress['schema'] ?? '' ), 'json-consumers-receive-one-terminal-receipt-after-progress' );

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
