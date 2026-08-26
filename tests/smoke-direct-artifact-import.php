<?php
/** Run: php tests/smoke-direct-artifact-import.php */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'STATIC_SITE_IMPORTER_PATH', dirname( __DIR__ ) . '/' );

$test_root = sys_get_temp_dir() . '/ssi-direct-artifact-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_direct_filters'] = array();
$GLOBALS['ssi_direct_actions'] = array();
$GLOBALS['ssi_direct_mutations'] = 0;
$GLOBALS['ssi_direct_last_args'] = array();
$GLOBALS['ssi_direct_compiled_results'] = array();
$GLOBALS['ssi_direct_checkpoint_reads'] = array();

class WP_Error {
	public function __construct( private string $code, private string $message = '', private $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function wp_upload_dir(): array { return array( 'basedir' => $GLOBALS['test_root'] ); }
function get_current_blog_id(): int { return 17; }
function get_current_user_id(): int { return 827; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000827'; }
function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ssi_direct_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['ssi_direct_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}
function static_site_importer_source_runtime( array $source ): array {
	$files = array();
	foreach ( $source['files'] ?? array() as $file ) {
		$path = (string) ( $file['path'] ?? '' );
		$file['mime_type'] = str_ends_with( $path, '.html' ) ? 'text/html' : ( str_ends_with( $path, '.css' ) ? 'text/css' : 'application/octet-stream' );
		$files[] = $file;
	}
	return array(
		'artifact' => array(
			'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
			'entrypoint' => (string) ( $source['entrypoint'] ?? 'website/index.html' ),
			'files'      => $files,
		),
		'provider' => 'direct-artifact-smoke',
		'source_metadata' => array( 'fixture' => 'direct-artifact-multi-page' ),
	);
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-run.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-client-script-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-website-artifact-import-input.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-direct-artifact-import.php';

class Static_Site_Importer_Theme_Generator {
	public static function compile_website_artifact( array $artifact, array $args = array() ) {
		$compiled = $args['compiled_artifact_result'] ?? array();
		$plan = is_array( $compiled['source_reports']['wordpress_site_plan'] ?? null ) ? $compiled['source_reports']['wordpress_site_plan'] : array();
		if ( empty( $plan ) ) {
			return new WP_Error( 'missing_precompiled_plan', 'The smoke materializer requires the real staged compiler result.' );
		}
		return array(
			'artifact'              => $artifact,
			'args'                  => $args,
			'compiled'              => $compiled,
			'plan'                  => $plan,
			'gutenberg_gaps'        => $compiled['source_reports']['gutenberg_gaps'] ?? array(),
			'companion_payload'     => null,
			'materialization_plan'  => $compiled['source_reports']['materialization_plan'] ?? array(),
			'theme_materialization' => array( 'strategy' => 'block' ),
		);
	}
	public static function import_website_artifact( array $artifact, array $args = array() ): array {
		if ( true !== ( $args['_static_site_importer_precompiled_source'] ?? null ) || ! is_array( $args['compiled_artifact_result'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $args['import_run_id'] ?? '' ) ) ) {
			throw new RuntimeException( 'Materialization must receive the frozen precompiled result and stable run id.' );
		}
		++$GLOBALS['ssi_direct_mutations'];
		$GLOBALS['ssi_direct_last_args'] = $args;
		$GLOBALS['ssi_direct_compiled_results'][] = $args['compiled_artifact_result'];
		$plan = $args['compiled_artifact_result']['source_reports']['wordpress_site_plan'] ?? array();
		return array(
			'theme_slug'            => 'direct-artifact-fixture',
			'theme_name'            => 'Direct Artifact Fixture',
			'quality'               => $plan['quality'] ?? array(),
			'import_report_summary' => array( 'status' => 'completed' ),
			'materialization_receipt' => array(
				'status'     => 'completed',
				'page_count' => count( $plan['pages'] ?? array() ),
			),
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-canonical-import-service.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$validate_receipt = new ReflectionMethod( Static_Site_Importer_Direct_Artifact_Import::class, 'validate_receipt' );
$shared_receipt_contract = array( 'digest' => 'shared-digest', 'shared_reduction_digest' => 'shared-reduction-digest' );
$page_receipt_contract = array( 'page_id' => 'website/index.html', 'digest' => 'page-digest', 'compiler_options' => array(), 'output_schema' => 'blocks-engine/php-transformer/result/v1' );
$compact_receipt = array(
	'receipt_schema'          => 'blocks-engine/php-transformer/compiled-page-receipt/v3',
	'page_id'                 => 'website/index.html',
	'shared_digest'           => 'shared-digest',
	'shared_reduction_digest' => 'shared-reduction-digest',
	'compiler_options'        => array(),
	'output_schema'           => 'blocks-engine/php-transformer/result/v1',
	'digest'                  => 'compact-receipt-digest',
	'compiled_documents'      => array(),
	'owned_document_paths'    => array(),
	'terminal_reduction'      => array_fill_keys( array( 'normalization', 'source_documents', 'owned_transformable_paths', 'stylesheet_occurrence_files', 'component_facts', 'block_types' ), array() ),
);
$assert( true === $validate_receipt->invoke( null, $compact_receipt, $page_receipt_contract, $shared_receipt_contract ), 'compact v3 receipts must validate without duplicated shared files' );
$legacy_receipt = $compact_receipt;
$legacy_receipt['receipt_schema'] = 'blocks-engine/php-transformer/compiled-page-receipt/v2';
$assert( is_wp_error( $validate_receipt->invoke( null, $legacy_receipt, $page_receipt_contract, $shared_receipt_contract ) ), 'v2 receipts must retain their files reduction contract' );
$legacy_receipt['terminal_reduction']['files'] = array();
$assert( true === $validate_receipt->invoke( null, $legacy_receipt, $page_receipt_contract, $shared_receipt_contract ), 'complete v2 receipts must remain compatible' );
$hash_json = new ReflectionMethod( Static_Site_Importer_Direct_Artifact_Import::class, 'hash_json' );
$ordered = array( 'z' => array( 'b' => 2, 'a' => 1 ), 'a' => 'https://example.com/a/b' );
$canonical = array( 'a' => 'https://example.com/a/b', 'z' => array( 'a' => 1, 'b' => 2 ) );
$assert( hash( 'sha256', (string) wp_json_encode( $ordered ) ) === $hash_json->invoke( null, $ordered, false ), 'streamed source identity must preserve the exact ordered JSON hash' );
$assert( hash( 'sha256', (string) wp_json_encode( $canonical ) ) === $hash_json->invoke( null, $ordered, true ), 'streamed checkpoint identity must preserve the exact recursively canonical JSON hash' );
$assert( wp_mkdir_p( $test_root ), 'the fixture workspace root must be created' );
$primitive_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $test_root, 'direct-checkpoint-primitives' );
$assert( ! is_wp_error( $primitive_workspace->publish_json_once( 'ordered.json', $ordered ) ) && wp_json_encode( $ordered, JSON_PRETTY_PRINT ) === $primitive_workspace->read_raw( 'ordered.json' ), 'streamed immutable JSON must preserve exact pretty-printed checkpoint bytes' );
$large_chunk = str_repeat( 'x', 1024 * 1024 );
$large_artifact = array();
for ( $index = 0; $index < 96; ++$index ) {
	$large_artifact[ 'file-' . $index ] = array( 'content' => $large_chunk );
}
memory_reset_peak_usage();
$memory_before = memory_get_usage( true );
$large_identity = $hash_json->invoke( null, $large_artifact, false );
$large_checkpoint = $primitive_workspace->publish_json_once( 'large.json', $large_artifact );
$identity_peak_delta = memory_get_peak_usage( true ) - $memory_before;
$assert( preg_match( '/^[a-f0-9]{64}$/', $large_identity ) && ! is_wp_error( $large_checkpoint ) && $identity_peak_delta < 16 * 1024 * 1024, 'streamed identity and checkpoint publication must not allocate the logical 96 MB artifact as one JSON string' );
unset( $large_artifact, $large_chunk );
$assert( ! is_wp_error( $primitive_workspace->publish_raw_once( 'immutable.json', '{"value":1}' ) ), 'immutable checkpoint publication must succeed once' );
$assert( ! is_wp_error( $primitive_workspace->publish_raw_once( 'immutable.json', '{"value":1}' ) ), 'identical immutable checkpoint replay must be accepted' );
$immutable_conflict = $primitive_workspace->publish_raw_once( 'immutable.json', '{"value":2}' );
$assert( is_wp_error( $immutable_conflict ) && 'static_site_importer_artifact_workspace_conflict' === $immutable_conflict->get_error_code(), 'different immutable checkpoint bytes must fail closed' );
$fixture = dirname( __DIR__ ) . '/tests/fixtures/direct-artifact-multi-page';
$files = array();
foreach ( array( 'index.html', 'about.html', 'contact.html', 'styles.css' ) as $name ) {
	$files[] = array(
		'path'    => 'website/' . $name,
		'content' => (string) file_get_contents( $fixture . '/' . $name ),
	);
}
$input = static fn ( string $operation = 'apply' ): array => array(
	'operation' => $operation,
	'slug'      => 'direct-artifact-fixture',
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'website/index.html',
		'files'      => $files,
	),
);
$resume = static fn ( string $id, string $operation = 'apply' ): array => array(
	'operation' => $operation,
	'slug'      => 'direct-artifact-fixture',
	'source'    => array(
		'type'      => 'files',
		'import_id' => $id,
	),
);
$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_run_policy'][] = static fn ( array $policy ): array => array_merge(
	$policy,
	array(
		'compile_batch_pages' => 1,
	)
);
$GLOBALS['ssi_direct_actions']['static_site_importer_direct_artifact_checkpoint_read'][] = static function ( string $kind ): void {
	$GLOBALS['ssi_direct_checkpoint_reads'][ $kind ] = (int) ( $GLOBALS['ssi_direct_checkpoint_reads'][ $kind ] ?? 0 ) + 1;
};
$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_run_policy'][] = static fn ( array $policy ): array => array_merge( $policy, array( 'freeze_continuation_bytes' => 1 ) );
$frozen = Static_Site_Importer_Canonical_Import_Service::import( $input( 'plan' ) );
$assert( ! empty( $frozen['success'] ) && ! empty( $frozen['continuation'] ) && 'artifact_frozen' === ( $frozen['continuation_reason'] ?? '' ) && 0 === ( $frozen['artifact_run']['progress']['prepared_count'] ?? -1 ), 'large direct artifacts must yield after durable freezing so source request memory can unwind before compilation' );
array_pop( $GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_run_policy'] );
$frozen_resumed = Static_Site_Importer_Canonical_Import_Service::import( $resume( (string) $frozen['import_id'], 'plan' ) );
$assert( ! empty( $frozen_resumed['success'] ) && ! empty( $frozen_resumed['continuation'] ) && 3 === ( $frozen_resumed['artifact_run']['progress']['prepared_count'] ?? 0 ) && 1 === ( $frozen_resumed['artifact_run']['progress']['receipt_count'] ?? 0 ), 'source-free resume must hydrate the immutable artifact once, prepare every page in one pass, and compile only its bounded receipt batch' );

$first = Static_Site_Importer_Canonical_Import_Service::import( $input() );
$assert( ! empty( $first['success'] ) && ! empty( $first['continuation'] ) && 'pages_remaining' === ( $first['continuation_reason'] ?? '' ) && 3 === ( $first['artifact_run']['progress']['prepared_count'] ?? 0 ) && 1 === ( $first['artifact_run']['progress']['receipt_count'] ?? -1 ) && 'continuing' === ( $first['import_report_summary']['status'] ?? '' ), 'the first invocation must durably prepare all page plans in one partition, compile one bounded receipt batch, and explicitly continue' );
$import_id = (string) $first['import_id'];
$mismatch = $resume( $import_id );
$mismatch['slug'] = 'different-frozen-contract';
$mismatch = Static_Site_Importer_Canonical_Import_Service::import( $mismatch );
$assert( 'static_site_importer_direct_artifact_run_mismatch' === ( $mismatch['error']['code'] ?? '' ) && 0 === $GLOBALS['ssi_direct_mutations'], 'resume must reject normalized argument mismatches before compile or mutation' );
$locked_workspace = new Static_Site_Importer_Artifact_Run_Workspace( $test_root . '/static-site-importer/direct-artifact-imports', 'direct-' . $import_id );
$execution_lock = $locked_workspace->acquire_lock( 'execution.lock' );
$assert( is_resource( $execution_lock ), 'the fixture must own the serialized execution lock' );
$busy = Static_Site_Importer_Canonical_Import_Service::import( $resume( $import_id ) );
$assert( ! empty( $busy['continuation'] ) && 'run_in_progress' === ( $busy['continuation_reason'] ?? '' ) && 0 === $GLOBALS['ssi_direct_mutations'], 'a concurrent resume must yield without mutating run state or WordPress' );
$locked_workspace->release_lock( $execution_lock );
$GLOBALS['ssi_direct_checkpoint_reads'] = array();
$second = Static_Site_Importer_Canonical_Import_Service::import( $resume( $import_id ) );
$assert( ! empty( $second['continuation'] ) && 3 === ( $second['artifact_run']['progress']['prepared_count'] ?? 0 ) && 2 === ( $second['artifact_run']['progress']['receipt_count'] ?? -1 ), 'resume must reuse every durable page plan and compile only the next receipt batch' );
$assert( 0 === ( $GLOBALS['ssi_direct_checkpoint_reads']['artifact'] ?? 0 ) && 1 === ( $GLOBALS['ssi_direct_checkpoint_reads']['shared'] ?? 0 ) && 1 === ( $GLOBALS['ssi_direct_checkpoint_reads']['page_plan'] ?? 0 ), 'a compile-only resume must skip the source artifact and decode each consumed checkpoint once' );
$third = Static_Site_Importer_Canonical_Import_Service::import( $resume( $import_id ) );
$terminal = $third;
$work = $terminal['artifact_run']['work'] ?? array();
$terminal_work = $terminal['artifact_run']['terminal_result_work'] ?? array();
$assert( ! empty( $terminal['success'] ) && empty( $terminal['continuation'] ) && 1 === $GLOBALS['ssi_direct_mutations'], 'resumed apply must perform exactly one importer mutation' );
$assert( array( 1, 1, 1 ) === ( $work['page_compile_counts'] ?? null ) && 3 === count( $terminal['artifact_run']['receipt_identities'] ?? array() ) && 3 === ( $work['pages_compiled'] ?? 0 ), 'durable counters and receipt evidence must prove every page compiled exactly once' );
$assert( 1 === ( $work['page_prepare_passes'] ?? 0 ) && 3 === ( $work['page_plans_prepared'] ?? 0 ), 'durable counters must prove every page plan was prepared by one whole-artifact partition' );
$assert( 1 === ( $work['content_policy_applications'] ?? 0 ) && 1 === ( $work['client_script_policy_applications'] ?? 0 ), 'content and client script policy must each run once before the artifact is frozen' );
$assert( 1 === ( $work['materialization_claims'] ?? 0 ) && 1 === ( $work['materializations'] ?? 0 ) && true === ( $GLOBALS['ssi_direct_last_args']['_static_site_importer_precompiled_source'] ?? false ), 'apply must claim once and use the precompiled source handoff' );
$assert( 0 === ( $terminal_work['html_document_transform_count'] ?? -1 ) && 0 === ( $terminal_work['normalization_count'] ?? -1 ), 'terminal composition must perform zero HTML transforms and normalization' );
$assert( ! str_contains( (string) json_encode( $terminal['artifact_run'] ), $test_root ) && ! str_contains( (string) json_encode( $terminal['artifact_run'] ), 'website/index.html' ), 'public run evidence must remain bounded and path-free' );
$composed_plan = $GLOBALS['ssi_direct_compiled_results'][0]['source_reports']['wordpress_site_plan'] ?? array();
$form_declarations = array_values( array_filter( $composed_plan['runtime_declarations'] ?? array(), static fn( $declaration ): bool => is_array( $declaration ) && 'entity_collection' === ( $declaration['kind'] ?? '' ) && 'forms' === ( $declaration['type'] ?? '' ) ) );
$form_dependencies = array_values( array_filter( $composed_plan['runtime_declarations'] ?? array(), static fn( $declaration ): bool => is_array( $declaration ) && 'dependency' === ( $declaration['kind'] ?? '' ) && 'form' === ( $declaration['capability'] ?? '' ) ) );
$assert( 2 === ( $composed_plan['quality']['metrics']['fallback_count'] ?? -1 ) && 2 === count( $form_declarations[0]['payload']['entities'] ?? array() ), 'real terminal composition must retain both route-level provider-materializable form entities' );
$assert( array() === array_filter( $form_declarations[0]['payload']['entities'] ?? array(), static fn( $entity ): bool => ! is_array( $entity ) || empty( $entity['bindings'] ) ), 'real terminal composition must retain an exact provider binding for each form entity' );
$assert( in_array( 'entity_collection:forms', $form_dependencies[0]['required_for'] ?? array(), true ), 'compact page receipts must compose the form provider admission relationship' );

$replay = Static_Site_Importer_Canonical_Import_Service::import( $resume( $import_id ) );
$assert( $terminal === $replay && 1 === $GLOBALS['ssi_direct_mutations'], 'terminal replay must return the durable response without compile or mutation' );

$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_run_policy'] = array( static fn ( array $policy ): array => array_merge( $policy, array( 'compile_batch_pages' => 20 ) ) );
$clean = Static_Site_Importer_Canonical_Import_Service::import( $input() );
$canonical = static function ( array $response ): array {
	unset( $response['import_id'], $response['artifact_run'] );
	return $response;
};
$assert( empty( $clean['continuation'] ) && 2 === $GLOBALS['ssi_direct_mutations'] && $canonical( $terminal ) === $canonical( $clean ), 'clean and resumed canonical apply outputs must match outside run observations' );
$canonical_compiled = static function ( array $result ) use ( &$canonical_compiled ): array {
	unset( $result['metrics'], $result['work'] );
	foreach ( $result as $key => &$value ) {
		if ( is_array( $value ) ) {
			$value = $canonical_compiled( $value );
		} elseif ( is_string( $key ) && str_ends_with( $key, '_duration_ms' ) ) {
			unset( $result[ $key ] );
		}
	}
	unset( $value );
	return $result;
};
$assert( $canonical_compiled( $GLOBALS['ssi_direct_compiled_results'][0] ) === $canonical_compiled( $GLOBALS['ssi_direct_compiled_results'][1] ), 'clean and resumed composition must produce identical canonical plans, companion payloads, pages, writes, diagnostics, and reconciliation identities' );

$fail_materialization = true;
$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_checkpoint_publish'][] = static function ( $allowed, string $kind ) use ( &$fail_materialization ) {
	if ( $fail_materialization && 'materialization' === $kind ) {
		$fail_materialization = false;
		return new WP_Error( 'injected_materialization_publication_failure', 'Injected post-mutation checkpoint failure.' );
	}
	return $allowed;
};
$interrupted = Static_Site_Importer_Canonical_Import_Service::import( $input() );
$interrupted_data = $interrupted['error']['data'] ?? array();
$assert( 'injected_materialization_publication_failure' === ( $interrupted['error']['code'] ?? '' ) && 3 === $GLOBALS['ssi_direct_mutations'], 'post-mutation checkpoint failure must return structured interruption evidence' );
$interrupted_id = (string) ( $interrupted_data['import_id'] ?? '' );
$ambiguous = Static_Site_Importer_Canonical_Import_Service::import( $resume( $interrupted_id ) );
$assert( 'static_site_importer_direct_artifact_materialization_ambiguous' === ( $ambiguous['error']['code'] ?? '' ) && 3 === $GLOBALS['ssi_direct_mutations'], 'resume across the mutation receipt boundary must fail closed instead of repeating WordPress mutation' );

$fail_run_after_receipt = true;
$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_checkpoint_publish'][] = static function ( $allowed, string $kind, string $relative, array $evidence ) use ( &$fail_run_after_receipt ) {
	if ( $fail_run_after_receipt && 'run' === $kind && 1 === ( $evidence['progress']['receipt_count'] ?? 0 ) ) {
		$fail_run_after_receipt = false;
		return new WP_Error( 'injected_run_publication_failure', 'Injected run checkpoint failure after immutable receipt publication.' );
	}
	return $allowed;
};
$run_failed = Static_Site_Importer_Canonical_Import_Service::import( $input( 'plan' ) );
$run_failure_data = $run_failed['error']['data'] ?? array();
$assert( 'injected_run_publication_failure' === ( $run_failed['error']['code'] ?? '' ) && preg_match( '/^[a-f0-9]{64}$/', (string) ( $run_failure_data['import_id'] ?? '' ) ), 'run checkpoint failures after immutable work publication must retain a structured resumable import id' );
$run_recovered = Static_Site_Importer_Canonical_Import_Service::import( $resume( (string) $run_failure_data['import_id'], 'plan' ) );
$assert( ! empty( $run_recovered['success'] ) && array( 1, 1, 1 ) === ( $run_recovered['artifact_run']['work']['page_compile_counts'] ?? null ), 'resume must adopt the immutable receipt and never recompile completed page work' );
$source_artifact = static_site_importer_source_runtime( $input( 'plan' )['source'] )['artifact'];
$assert( hash( 'sha256', (string) wp_json_encode( $source_artifact ) ) === ( $run_recovered['source']['identity'] ?? '' ), 'staged planning must preserve the canonical normalized source identity from before script policy transforms' );

$fail_receipt = true;
$GLOBALS['ssi_direct_filters']['static_site_importer_direct_artifact_checkpoint_publish'][] = static function ( $allowed, string $kind ) use ( &$fail_receipt ) {
	if ( $fail_receipt && 'receipt' === $kind ) {
		$fail_receipt = false;
		return new WP_Error( 'injected_receipt_publication_failure', 'Injected receipt checkpoint failure.' );
	}
	return $allowed;
};
$failed = Static_Site_Importer_Canonical_Import_Service::import( $input( 'plan' ) );
$failure_data = $failed['error']['data'] ?? array();
$assert( 'injected_receipt_publication_failure' === ( $failed['error']['code'] ?? '' ) && 0 === ( $failure_data['artifact_run']['progress']['receipt_count'] ?? -1 ) && 0 === ( $failure_data['artifact_run']['work']['compositions'] ?? -1 ), 'receipt publication failure must fail closed before receipt progress or composition' );
$failed_id = (string) ( $failure_data['import_id'] ?? '' );
$recovered = Static_Site_Importer_Canonical_Import_Service::import( $resume( $failed_id, 'plan' ) );
$assert( ! empty( $recovered['success'] ) && empty( $recovered['continuation'] ), 'a structured checkpoint publication failure must remain resumable' );

$throw_compose = true;
$GLOBALS['ssi_direct_actions']['static_site_importer_direct_artifact_before_phase'][] = static function ( string $phase ) use ( &$throw_compose ): void {
	if ( $throw_compose && 'compose' === $phase ) {
		$throw_compose = false;
		throw new RuntimeException( 'Injected compose exception.' );
	}
};
$thrown = Static_Site_Importer_Canonical_Import_Service::import( $input( 'plan' ) );
$thrown_data = $thrown['error']['data'] ?? array();
$last_failure = $thrown_data['artifact_run']['failures'][0] ?? array();
$assert( 'static_site_importer_direct_artifact_phase_failed' === ( $thrown['error']['code'] ?? '' ) && 'compose' === ( $last_failure['phase'] ?? '' ) && 'RuntimeException' === ( $last_failure['exception_class'] ?? '' ) && ! empty( $last_failure['artifact_identity'] ), 'thrown exceptions must persist structured phase, class, and artifact evidence' );
$thrown_id = (string) ( $thrown_data['import_id'] ?? '' );
$recovered_throw = Static_Site_Importer_Canonical_Import_Service::import( $resume( $thrown_id, 'plan' ) );
$assert( ! empty( $recovered_throw['success'] ) && empty( $recovered_throw['continuation'] ), 'a thrown phase failure must resume from durable receipts without recompiling pages' );

Static_Site_Importer_Artifact_Run_Workspace::purge_expired_in( $test_root );
$primitive_workspace->purge();
echo "Direct artifact import smoke passed.\n";
