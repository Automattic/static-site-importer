<?php
/**
 * Regression coverage for issue #2581: a required runtime dependency's
 * install or activation can create native content at one of the imported
 * source's own routes (a commerce dependency seeds its own /checkout/ page
 * when it activates). Preflight must adopt exactly those run-created pages
 * instead of rejecting the import with post_conflict, while a page that
 * already existed before the run still rejects the import.
 *
 * The prepare and resume phases run through the real generator, dependency
 * manager, checkpoint, and plan preparation code. Only the host boundary is
 * doubled: a store-backed fake runtime plus a test-only dependency adapter
 * whose plugin "activation" seeds a /checkout/ page. The resume phase runs
 * in a fresh child process so the checkpoint's same-runtime guard applies
 * for real, and the run-owned page record has to cross the runtime boundary
 * inside the checkpoint workspace.
 *
 * Run: php tests/smoke-dependency-created-route-adoption.php
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

// ---------------------------------------------------------------------------
// Store-backed fake runtime: pages and options persist in a JSON file so a
// fresh child process observes exactly the state the prepare phase left.
// ---------------------------------------------------------------------------

$GLOBALS['ssi_store_path'] = '';
$GLOBALS['ssi_store']      = array( 'next_id' => 10, 'pages' => array(), 'options' => array(), 'dependency_active' => false );

/** @param array<string,mixed> $store */
function ssi_store_replace( array $store ): void {
	$GLOBALS['ssi_store'] = $store;
	ssi_store_save();
}

function ssi_store_save(): void {
	if ( '' !== $GLOBALS['ssi_store_path'] ) {
		file_put_contents( $GLOBALS['ssi_store_path'], (string) json_encode( $GLOBALS['ssi_store'] ) );
	}
}

function ssi_store_reset(): void {
	ssi_store_replace( array( 'next_id' => 10, 'pages' => array(), 'options' => array(), 'dependency_active' => false ) );
}

function ssi_store_page( int $id ): ?array {
	foreach ( $GLOBALS['ssi_store']['pages'] as $page ) {
		if ( (int) $page['ID'] === $id ) {
			return $page;
		}
	}
	return null;
}

function ssi_store_checkout_page_id(): int {
	foreach ( $GLOBALS['ssi_store']['pages'] as $page ) {
		if ( 'checkout' === (string) $page['post_name'] && 'page' === (string) $page['post_type'] ) {
			return (int) $page['ID'];
		}
	}
	return 0;
}

function ssi_store_insert( string $path, string $title, string $content = '' ): int {
	$id                              = (int) $GLOBALS['ssi_store']['next_id'];
	$GLOBALS['ssi_store']['next_id'] = $id + 1;
	$GLOBALS['ssi_store']['pages'][] = array(
		'ID'           => $id,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => trim( $path, '/' ),
		'post_title'   => $title,
		'post_content' => $content,
		'post_parent'  => 0,
		'meta'         => array(),
	);
	ssi_store_save();
	return $id;
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }

class WP_Post {
	public int $ID = 0;
	public string $post_name = '';
	public string $post_type = 'page';
	public string $post_status = 'publish';
	public string $post_title = '';
	public string $post_content = '';
	public int $post_parent = 0;
	public string $post_date = '';
	public string $post_modified = '';
	public int $post_author = 1;

	public function __construct( array $row ) {
		$this->ID            = (int) $row['ID'];
		$this->post_name     = (string) ( $row['post_name'] ?? '' );
		$this->post_type     = (string) ( $row['post_type'] ?? 'page' );
		$this->post_status   = (string) ( $row['post_status'] ?? 'publish' );
		$this->post_title    = (string) ( $row['post_title'] ?? '' );
		$this->post_content  = (string) ( $row['post_content'] ?? '' );
		$this->post_parent   = (int) ( $row['post_parent'] ?? 0 );
		$this->post_date     = (string) ( $row['post_date'] ?? '' );
		$this->post_modified = (string) ( $row['post_modified'] ?? '' );
	}
}

/**
 * @return array<int,int|WP_Post>
 */
function get_posts( array $args ): array {
	$matches = array();
	foreach ( $GLOBALS['ssi_store']['pages'] as $row ) {
		$type = (string) ( $args['post_type'] ?? 'any' );
		if ( 'any' !== $type && ! in_array( $row['post_type'], (array) $type, true ) ) {
			continue;
		}
		$status = (string) ( $args['post_status'] ?? 'publish' );
		if ( 'any' !== $status && ! in_array( $row['post_status'], (array) $status, true ) ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) ) {
			$value = $row['meta'][ (string) $args['meta_key'] ] ?? null;
			if ( array_key_exists( 'meta_value', $args ) && $value !== $args['meta_value'] ) {
				continue;
			}
			if ( ! array_key_exists( 'meta_value', $args ) && null === $value ) {
				continue;
			}
		}
		$matches[] = 'ids' === ( $args['fields'] ?? '' ) ? (int) $row['ID'] : new WP_Post( $row );
	}
	$number = (int) ( $args['numberposts'] ?? 5 );
	return $number > 0 ? array_slice( $matches, 0, $number ) : $matches;
}

function get_post( int $id, $output = OBJECT ): ?WP_Post {
	unset( $output );
	$row = ssi_store_page( $id );
	return null === $row ? null : new WP_Post( $row );
}

function get_page_by_path( string $path, $output = OBJECT, string|array $post_type = 'page' ): ?WP_Post {
	unset( $output );
	foreach ( $GLOBALS['ssi_store']['pages'] as $row ) {
		if ( $row['post_name'] === trim( $path, '/' ) && in_array( $row['post_type'], (array) $post_type, true ) ) {
			return new WP_Post( $row );
		}
	}
	return null;
}

function get_post_meta( int $id, string $key, bool $single = true ): mixed {
	unset( $single );
	$row = ssi_store_page( $id );
	return $row ? ( $row['meta'][ $key ] ?? '' ) : '';
}

function update_post_meta( int $id, string $key, mixed $value ): bool {
	$row = ssi_store_page( $id );
	if ( null === $row ) {
		return false;
	}
	$row['meta'][ $key ] = $value;
	return true;
}

function metadata_exists( string $meta_type, int $id, string $key ): bool {
	unset( $meta_type );
	$row = ssi_store_page( $id );
	return null !== $row && array_key_exists( $key, $row['meta'] );
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['ssi_store']['options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value ): bool {
	$GLOBALS['ssi_store']['options'][ $key ] = $value;
	ssi_store_save();
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['ssi_store']['options'][ $key ] );
	return true;
}

function get_theme_root(): string {
	return (string) $GLOBALS['ssi_theme_root'];
}

function get_theme_root_uri(): string {
	return 'https://fixture.example/themes';
}

function get_current_blog_id(): int { return 17; }
function get_current_user_id(): int { return 23; }
function get_page_uri( WP_Post|int $post ): string {
	$row = is_int( $post ) ? ssi_store_page( $post ) : ssi_store_page( $post->ID );
	return $row ? (string) $row['post_name'] : '';
}
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
function wp_json_encode( mixed $value, int $options = 0 ): string|false { return json_encode( $value, $options ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function esc_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_generate_uuid4(): string { return sprintf( '%08x-%04x-%04x-%04x-%012x', mt_rand(), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand() ); }
function wp_parse_args( $args, array $defaults = array() ): array { return array_merge( $defaults, (array) $args ); }
function current_time( string $type ): string { unset( $type ); return gmdate( 'Y-m-d H:i:s' ); }
function do_action( string $hook, mixed ...$args ): void { unset( $hook, $args ); }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void { unset( $hook, $callback, $priority, $accepted_args ); }
function remove_filter( string $hook, callable $callback, int $priority = 10 ): void { unset( $hook, $callback, $priority ); }

/**
 * The test-only dependency adapter joins the registry through the same
 * filter real providers use; every other hook passes its value through.
 */
function apply_filters( string $hook, mixed $value, mixed ...$rest ): mixed {
	unset( $rest );
	if ( 'static_site_importer_entity_materializers' === $hook && is_array( $value ) ) {
		$value['ssi_checkout_fixture'] = ssi_test_dependency_adapter();
		return $value;
	}
	return $value;
}

// ---------------------------------------------------------------------------
// Test-only dependency adapter. Its "plugin activation" seeds a native page
// at the source's own /checkout/ route, modeling the WooCommerce behavior
// behind issue #2581 (shop/cart/checkout/my-account created on activation).
// ---------------------------------------------------------------------------

function ssi_test_dependency_adapter(): array {
	return array(
		'id'                    => 'ssi_checkout_fixture',
		'capability'            => 'checkout_fixture_dependency',
		'rollback_contract_id'  => 'checkout-fixture/dependency',
		'materialization_stage' => 'before_pages',
		'dependencies'          => array(
			array(
				'type'                  => 'wp_org_plugin',
				'slug'                  => 'checkout-fixture',
				'plugin_file'           => 'checkout-fixture/checkout-fixture.php',
				'availability_callback' => static fn (): bool => (bool) ( $GLOBALS['ssi_store']['dependency_active'] ?? false ),
			),
		),
		'intent_callback'       => static function ( array $plan, array $args ): bool {
			unset( $args );
			foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
				if ( 1 === preg_match( '~(^|/)checkout/index\.html$~', (string) ( $page['source_path'] ?? '' ) ) ) {
					return true;
				}
			}
			return false;
		},
	);
}

/**
 * The real dependency manager reaches the plugin materializer by class name
 * only, so this stand-in models install+activation without WordPress plugin
 * mechanics (covered for real by the wordpress-runtime regression).
 */
class Static_Site_Importer_Plugin_Materializer {
	/** @return array<string,mixed> */
	public static function ensure_wp_org_plugin( string $slug, string $plugin_file, ?callable $availability_check = null, ?callable $preparation_callback = null ): array {
		if ( null !== $availability_check && $availability_check() ) {
			return array( 'slug' => $slug, 'plugin_file' => $plugin_file, 'status' => 'already_available', 'installed' => true, 'active' => true, 'error' => null );
		}
		$created                                   = ssi_store_insert( '/checkout/', 'Checkout', 'Checkout fixture page.' );
		$GLOBALS['ssi_store']['dependency_active'] = true;
		ssi_store_save();
		return array( 'slug' => $slug, 'plugin_file' => $plugin_file, 'status' => 'activated', 'installed' => true, 'active' => true, 'created_post_id' => $created, 'error' => null );
	}
}

// ---------------------------------------------------------------------------
// Seam stand-in: real preparation logic behind the materializer boundary.
// The resume child records the adoption evidence and stops before any
// materialization happens.
// ---------------------------------------------------------------------------

$GLOBALS['ssi_resume_evidence'] = null;

class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public const RECEIPT_SCHEMA = 'static-site-importer/materialization-receipt/v2';

	/** @return array<string,mixed> */
	public static function prepare_for_materialization( array $plan, array $args ): array {
		$prepared = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $args );
		if ( 'resume' === ( $args['runtime_lifecycle_phase'] ?? '' ) ) {
			$GLOBALS['ssi_resume_evidence'] = array(
				'captured_dependency_created_post_ids' => array_key_exists( 'dependency_created_post_ids', $args ) ? array_map( 'intval', (array) $args['dependency_created_post_ids'] ) : null,
				'existing_matches'                     => is_array( $prepared['existing_matches'] ?? null ) ? $prepared['existing_matches'] : array(),
				'source_ids'                           => is_array( $prepared['source_ids'] ?? null ) ? $prepared['source_ids'] : array(),
				'status'                               => (string) ( $prepared['status'] ?? '' ),
				'failure_reason'                       => (string) ( $prepared['errors'][0]['code'] ?? $prepared['failure_reason'] ?? ( is_array( $prepared['receipt'] ?? null ) ? ( $prepared['receipt']['errors'][0]['code'] ?? '' ) : '' ) ),
			);
			if ( 'prepared' === ( $prepared['status'] ?? '' ) ) {
				return array(
					'status'  => 'failed',
					'receipt' => array(
						'failure_reason' => 'ssi_test_stop',
						'errors'         => array( array( 'code' => 'ssi_test_stop', 'message' => 'stop before materialization' ) ),
					),
				);
			}
		}
		return $prepared;
	}

	/** @return array<string,mixed> */
	public static function prepare( array $plan, array $args = array() ): array {
		return Static_Site_Importer_Site_Plan_Preparation::prepare( $plan, $args );
	}

	public static function materialize_runtime_dependencies( array $lifecycle, array $args ) {
		return Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle, $args );
	}

	public static function commit_receipt( array &$receipt ): void {
		unset( $receipt );
	}

	/** @return array<string,mixed> */
	public static function rollback_receipt( array $receipt, string $reason ): array {
		$receipt['failure_reason'] = $reason;
		return $receipt;
	}

	public static function safe_external_report_destination( mixed $path ): bool {
		unset( $path );
		return true;
	}

	public static function prepared_resolved_projection_hash( array $projection ): string {
		return hash( 'sha256', (string) json_encode( $projection ) );
	}

	/** @return array<string,mixed> */
	public static function admit_prepared( array $prepared ): array {
		return Static_Site_Importer_Site_Plan_Preparation::admit_prepared( $prepared );
	}

	/** @return array<string,mixed> */
	public static function materialize_prepared( array $prepared ): array {
		throw new RuntimeException( 'materialize_prepared must not run in this harness' );
	}

	public static function materialize_prepared_lifecycle( array $prepared, array $lifecycle, mixed $companion_payload, array $gutenberg_gaps, array $theme_materialization ): array {
		unset( $prepared, $lifecycle, $companion_payload, $gutenberg_gaps, $theme_materialization );
		throw new RuntimeException( 'materialize_prepared_lifecycle must not run in this harness' );
	}

	public static function journal_receipt_file( array &$receipt, string $path ): void {
		unset( $receipt, $path );
	}

	public static function journal_receipt_post( array &$receipt, int $id ): void {
		unset( $receipt, $id );
	}
}

// ---------------------------------------------------------------------------
// Load the real production code under test.
// ---------------------------------------------------------------------------

$includes = ABSPATH . 'includes/';
require_once ABSPATH . 'vendor/autoload.php';
require_once $includes . 'class-static-site-importer-theme-generator.php';
require_once $includes . 'class-static-site-importer-entity-materializer-registry.php';
require_once $includes . 'class-static-site-importer-dependency-manager.php';
require_once $includes . 'class-static-site-importer-site-plan-preparation.php';
require_once $includes . 'class-static-site-importer-site-plan-persistence.php';
require_once $includes . 'class-static-site-importer-import-destination.php';
require_once $includes . 'class-static-site-importer-default-content.php';
require_once $includes . 'class-static-site-importer-site-plan-receipt.php';
require_once $includes . 'class-static-site-importer-document-type-classifier.php';
require_once $includes . 'class-static-site-importer-protected-page-policy.php';
require_once $includes . 'class-static-site-importer-quality-budget-admission.php';
require_once $includes . 'class-static-site-importer-viewport-metadata.php';
require_once $includes . 'class-static-site-importer-stylesheet-materializer.php';
require_once $includes . 'class-static-site-importer-internal-link-runtime.php';
require_once $includes . 'class-static-site-importer-font-materializer.php';

// The issue's reduced fixture: a site whose own /checkout/ page collides
// with the page the dependency creates when it activates.
function ssi_test_artifact(): array {
	return array(
		'schema' => 'blocks-engine/php-transformer/site-artifact/v1',
		'files'  => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><title>Fixture Site</title></head><body><main><h1>Welcome</h1><p>Hello from the fixture site.</p></main></body></html>',
			),
			array(
				'path'    => 'checkout/index.html',
				'content' => '<!doctype html><html><head><title>Checkout</title></head><body><main><h1>Checkout</h1><p>Complete your order.</p></main></body></html>',
			),
		),
	);
}

// ---------------------------------------------------------------------------
// Fresh-runtime resume child: the checkpoint must carry the dependency's
// created-page record across the process boundary, and preflight must adopt
// the dependency-created page.
// ---------------------------------------------------------------------------

if ( '--resume-child' === ( $argv[1] ?? '' ) ) {
	$handle = (string) $argv[2];
	$root   = (string) $argv[3];
	$GLOBALS['ssi_theme_root'] = $root . '/themes';
	ssi_store_replace( (array) json_decode( (string) file_get_contents( (string) $argv[4] ), true ) );
	$result = Static_Site_Importer_Theme_Generator::import_website_artifact(
		ssi_test_artifact(),
		array(
			'slug'                      => 'fixture-site',
			'name'                      => 'Fixture Site',
			'remove_default_content'    => true,
			'materialize_dependencies'  => true,
			'source_metadata'           => array( 'source' => 'fixture' ),
			'_static_site_importer_lifecycle_checkpoint_root' => $root,
			'runtime_lifecycle_phase'   => 'resume',
			'runtime_lifecycle_request_id'    => 'prepare-request',
			'runtime_lifecycle_invocation_id' => 'resume-request',
			'runtime_lifecycle_checkpoint'    => $handle,
		)
	);
	if ( null !== $GLOBALS['ssi_resume_evidence'] ) {
		file_put_contents( (string) $argv[5], (string) json_encode( $GLOBALS['ssi_resume_evidence'] ) );
	}
	if ( is_wp_error( $result ) && 'ssi_test_stop' !== $result->get_error_code() ) {
		fwrite( STDERR, 'resume failed: ' . $result->get_error_code() . ' ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Parent: prepare, resume across a fresh runtime, then the preflight
// admission and rejection cases.
// ---------------------------------------------------------------------------

$failures = array();
$assert   = static function ( bool $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $label;
	}
};

$root              = sys_get_temp_dir() . '/ssi-dep-route-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $root );
$GLOBALS['ssi_theme_root'] = $root . '/themes';
wp_mkdir_p( (string) $GLOBALS['ssi_theme_root'] );
$GLOBALS['ssi_store_path'] = $root . '/store.json';
ssi_store_reset();

// 1. Prepare phase: compilation, adapter intent detection, and the real
// dependency materialization create the page; the checkpoint must retain
// the run-owned page record for the resumed runtime.
$prepared = Static_Site_Importer_Theme_Generator::import_website_artifact(
	ssi_test_artifact(),
	array(
		'slug'                      => 'fixture-site',
		'name'                      => 'Fixture Site',
		'remove_default_content'    => true,
		'materialize_dependencies'  => true,
		'source_metadata'           => array( 'source' => 'fixture' ),
		'_static_site_importer_lifecycle_checkpoint_root' => $root,
		'runtime_lifecycle_phase'   => 'prepare',
		'runtime_lifecycle_invocation_id' => 'prepare-request',
	)
);
$assert( ! is_wp_error( $prepared ), 'prepare-phase-succeeds: ' . ( is_wp_error( $prepared ) ? $prepared->get_error_code() . ' ' . $prepared->get_error_message() : wp_json_encode( array( 'status' => $prepared['status'] ?? null, 'reason' => $prepared['failure_reason'] ?? null ) ) ) );
if ( ! is_wp_error( $prepared ) ) {
	$assert( 'dependencies_prepared' === ( $prepared['status'] ?? '' ), 'prepare-phase-returns-dependencies-prepared' );
	$handle = (string) ( $prepared['runtime_lifecycle_checkpoint'] ?? '' );
	$assert( 1 === preg_match( '/^[a-f0-9]{32}$/', $handle ), 'prepare-phase-returns-a-checkpoint-handle' );
	$created = ssi_store_checkout_page_id();
	$assert( $created > 0, 'dependency-activation-seeds-the-checkout-page' );

		// Intent-detected dependencies report under their declaration identity.
	$reports = $prepared['dependencies'] ?? array();
	foreach ( $reports as $declaration_reports ) {
		if ( is_array( $declaration_reports ) && isset( $declaration_reports['checkout-fixture'] ) ) {
			$report = $declaration_reports['checkout-fixture'];
			break;
		}
	}
	$assert( is_array( $report ) && array( $created ) === array_map( 'intval', (array) ( $report['dependency_created_post_ids'] ?? array() ) ), 'materialization-report-names-the-created-page-only' );

	$record_path = $root . '/.ssi-artifact-run-lifecycle-' . $handle . '/dependency-created-posts.json';
	$assert( is_file( $record_path ), 'checkpoint-workspace-retains-the-created-page-record' );
	$record = is_file( $record_path ) ? json_decode( (string) file_get_contents( $record_path ), true ) : null;
	$assert( is_array( $record ) && 'static-site-importer/dependency-created-posts/v1' === ( $record['schema'] ?? '' ) && array( $created ) === array_map( 'intval', (array) ( $record['post_ids'] ?? array() ) ), 'checkpoint-workspace-retains-the-created-page-record' );

	// 2. Resume in a fresh runtime: the record must arrive in the prepared
	// args and preflight must adopt the dependency-created page.
	$evidence_path = $root . '/resume-evidence.json';
	$command       = PHP_BINARY . ' ' . escapeshellarg( __FILE__ ) . ' --resume-child ' . escapeshellarg( $handle ) . ' ' . escapeshellarg( $root ) . ' ' . escapeshellarg( $GLOBALS['ssi_store_path'] ) . ' ' . escapeshellarg( $evidence_path );
	$process       = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$output        = is_resource( $process ) ? stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ) : '';
	if ( is_resource( $process ) ) {
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$child_status = proc_close( $process );
	} else {
		$child_status = 1;
	}
	$assert( 0 === $child_status, 'resume-child-succeeds: ' . $output );
	$evidence = json_decode( (string) file_get_contents( $evidence_path ), true );
	$assert( is_array( $evidence ), 'resume-child-writes-evidence' );
	if ( is_array( $evidence ) ) {
		$assert( 'prepared' === ( $evidence['status'] ?? '' ) && '' === ( $evidence['failure_reason'] ?? '' ), 'resume-preflight-prepares-without-error: ' . wp_json_encode( array( 'status' => $evidence['status'] ?? null, 'failure_reason' => $evidence['failure_reason'] ?? null ) ) );
		$assert( array( $created ) === ( $evidence['captured_dependency_created_post_ids'] ?? array() ), 'checkpoint-transports-the-created-page-record: ' . wp_json_encode( $evidence['captured_dependency_created_post_ids'] ?? null ) );
		$rows = array_values( array_filter(
			is_array( $evidence['existing_matches']['pages'] ?? null ) ? $evidence['existing_matches']['pages'] : array(),
			static fn ( $row ): bool => is_array( $row ) && 'checkout/index.html' === ( $row['source_path'] ?? '' )
		) );
		$assert( 1 === count( $rows ) && $created === ( $rows[0]['post_id'] ?? 0 ) && 'dependency_created_match' === ( $rows[0]['reason'] ?? '' ), 'preflight-adopts-the-dependency-created-checkout-page: ' . wp_json_encode( $rows ) );
		$assert( $created === ( $evidence['source_ids']['checkout/index.html'] ?? 0 ), 'adopted-page-id-is-bound-to-the-source-path' );
	}
}

// 3. A pre-existing page (created before the run) at the colliding route
// still rejects the import: only dependency-created pages are adopted.
$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( ssi_test_artifact(), array( 'slug' => 'fixture-site', 'name' => 'Fixture Site' ) );
$assert( ! is_wp_error( $compiled ), 'fixture-compiles: ' . ( is_wp_error( $compiled ) ? (string) $compiled->get_error_code() : '' ) );
$plan = is_wp_error( $compiled ) ? array() : (array) ( $compiled['plan'] ?? array() );
$assert( isset( $plan['pages'] ) && count( $plan['pages'] ) >= 2, 'compiled-plan-has-the-two-fixture-pages' );

$direct_args = static function ( array $extra = array() ): array {
	return array_merge(
		array(
			'slug'                   => 'fixture-site',
			'name'                   => 'Fixture Site',
			'remove_default_content' => true,
			'overwrite'              => false,
		),
		$extra
	);
};

ssi_store_reset();
$preexisting = ssi_store_insert( '/checkout/', 'Existing Checkout', 'Created before the run.' );
$rejected    = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $direct_args() );
$rejected_reason = $rejected['receipt']['errors'][0]['code'] ?? '';
$assert( 'rejected' === ( $rejected['status'] ?? '' ) && 'post_conflict' === $rejected_reason, 'pre-existing-colliding-page-still-rejects: ' . wp_json_encode( array( 'status' => $rejected['status'] ?? null, 'reason' => $rejected_reason ) ) );

ssi_store_reset();
ssi_store_insert( '/checkout/', 'Existing Checkout', 'Created before the run.' );
$stale_record = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $direct_args( array( 'dependency_created_post_ids' => array( 10 === $preexisting ? 11 : 10 ) ) ) );
$stale_reason = $stale_record['receipt']['errors'][0]['code'] ?? '';
$assert( 'rejected' === ( $stale_record['status'] ?? '' ) && 'post_conflict' === $stale_reason, 'record-naming-a-different-page-still-rejects: ' . wp_json_encode( array( 'status' => $stale_record['status'] ?? null, 'reason' => $stale_reason ) ) );

ssi_store_reset();
ssi_store_insert( '/checkout/', 'Existing Checkout', 'Created before the run.' );
$overwrite = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $direct_args( array( 'overwrite' => true ) ) );
$assert( 'prepared' === ( $overwrite['status'] ?? '' ), 'overwrite-still-prepares-across-the-colliding-route' );
$overwrite_rows = array_values( array_filter(
	is_array( $overwrite['existing_matches']['pages'] ?? null ) ? $overwrite['existing_matches']['pages'] : array(),
	static fn ( $row ): bool => is_array( $row ) && 'checkout/index.html' === ( $row['source_path'] ?? '' )
) );
$assert( 1 === count( $overwrite_rows ) && 'canonical_route_match' === ( $overwrite_rows[0]['reason'] ?? '' ), 'overwrite-keeps-the-canonical-route-reason: ' . wp_json_encode( $overwrite_rows ) );

ssi_store_reset();
$dependency_page = ssi_store_insert( '/checkout/', 'Dependency Checkout', 'Created by the dependency during prepare.' );
$adopted         = Static_Site_Importer_Site_Plan_Preparation::prepare_for_materialization( $plan, $direct_args( array( 'dependency_created_post_ids' => array( $dependency_page ) ) ) );
$assert( 'prepared' === ( $adopted['status'] ?? '' ), 'recorded-dependency-page-prepares-without-overwrite: ' . wp_json_encode( array( 'status' => $adopted['status'] ?? null, 'reason' => $adopted['failure_reason'] ?? null ) ) );
$adopted_rows = array_values( array_filter(
	is_array( $adopted['existing_matches']['pages'] ?? null ) ? $adopted['existing_matches']['pages'] : array(),
	static fn ( $row ): bool => is_array( $row ) && 'checkout/index.html' === ( $row['source_path'] ?? '' )
) );
$assert( 1 === count( $adopted_rows ) && $dependency_page === ( $adopted_rows[0]['post_id'] ?? 0 ) && 'dependency_created_match' === ( $adopted_rows[0]['reason'] ?? '' ), 'direct-preflight-adopts-with-the-dependency-created-reason: ' . wp_json_encode( $adopted_rows ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, "FAIL: \n - " . implode( "\n - ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dependency-created route adoption smoke test passed.\n";
