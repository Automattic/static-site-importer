<?php
/**
 * Isolated v2 plan materialization contract coverage.
 *
 * Run: php tests/smoke-wordpress-site-plan-materializer.php
 *
 * @package StaticSiteImporter
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\RuntimeDeclarations;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

define( 'OBJECT', 'OBJECT' );
$GLOBALS['ssi_plan_root']                 = sys_get_temp_dir() . '/ssi-plan-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_plan_posts']                = array();
$GLOBALS['ssi_plan_meta']                 = array();
$GLOBALS['ssi_plan_options']              = array(
	'show_on_front' => 'posts',
	'page_on_front' => 0,
	'blogname'      => 'Before',
	'use_smilies'   => true,
);
$GLOBALS['ssi_plan_fail_after']           = 0;
$GLOBALS['ssi_plan_insert_calls']         = 0;
$GLOBALS['ssi_plan_font_requests']        = array();
$GLOBALS['ssi_plan_woo_cleanup_failures'] = false;
mkdir( $GLOBALS['ssi_plan_root'], 0777, true );

class WP_Error {
	private string $code;
	private mixed $data;
	public function __construct( string $code, string $message = '', mixed $data = null ) {
		$this->code = $code;
		$this->data = $data; }
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_data(): mixed {
		return $this->data; }
}
class WP_Post {
	public int $ID;
	public string $post_name;
	public function __construct( int $id ) {
		$this->ID        = $id;
		$this->post_name = (string) ( $GLOBALS['ssi_plan_posts'][ $id ]['post_name'] ?? '' ); }
}
function apply_filters( string $hook, $value, ...$args ) {
	unset( $hook, $args );
	return $value; }
function get_page_uri( WP_Post $post ): string {
	return (string) ( $GLOBALS['ssi_plan_posts'][ $post->ID ]['post_name'] ?? '' ); }
function is_wp_error( $value ): bool {
	return $value instanceof WP_Error; }
function sanitize_key( string $value ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function get_theme_root(): string {
	return $GLOBALS['ssi_plan_root']; }
function get_theme_root_uri(): string {
	return 'https://example.test/wp-content/themes'; }
function trailingslashit( string $path ): string {
	return rtrim( $path, '/' ) . '/'; }
function wp_json_encode( $value, int $options = 0 ) {
	if ( ! empty( $GLOBALS['ssi_plan_count_aggregate_encodes'] ) && ( is_array( $value ) || is_object( $value ) ) ) {
		$GLOBALS['ssi_plan_json_array_calls'] = (int) ( $GLOBALS['ssi_plan_json_array_calls'] ?? 0 ) + 1;
	}
	return json_encode( $value, $options ); }
function wp_slash( string $value ): string {
	return addslashes( $value ); }
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0777, true ); }
function WP_Filesystem(): bool {
	$GLOBALS['wp_filesystem'] = new class {
		public function put_contents( string $path, string $content, int $mode ): bool {
			unset( $mode );
			return false !== file_put_contents( $path, $content ); }
	};
	return true; }
function wp_delete_file( string $path ): bool {
	return unlink( $path ); }
function wp_parse_url( string $url ) {
	return parse_url( $url ); }
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['ssi_plan_font_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( 'https://fonts.googleapis.com/css2?family=Inter-like:wght@400;700' === $url ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => "@font-face{font-family:'Inter-like';font-style:normal;font-weight:100 900;font-stretch:75% 125%;src:url(https://fonts.example.test/inter.woff2) format('woff2');unicode-range:U+0000-00FF}",
		);
	}
	if ( 'https://fonts.example.test/inter.woff2' === $url ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $GLOBALS['ssi_plan_binary_font'],
		);
	}
	if ( str_starts_with( $url, 'https://fonts.googleapis.com/' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => "@font-face{font-family:'Example Font';font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/example/font.woff2) format('woff2')}",
		);
	}
	if ( 'https://fonts.gstatic.com/s/example/font.woff2' === $url ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => 'font-payload',
		);
	}
	return new WP_Error( 'unexpected_request' );
}
function wp_remote_retrieve_response_code( $response ): int {
	return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ): string {
	return (string) ( $response['body'] ?? '' ); }
function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['ssi_plan_options'][ $key ] ?? $default; }
function update_option( string $key, $value ): bool {
	if ( array_key_exists( $key, $GLOBALS['ssi_plan_options'] ) && $GLOBALS['ssi_plan_options'][ $key ] === $value ) {
		return false; // Core semantics: unchanged value writes no row and returns false.
	}
	$GLOBALS['ssi_plan_options'][ $key ] = $value;
	return true;
}
function switch_theme( string $slug ): void {
	$GLOBALS['ssi_plan_options']['stylesheet'] = $slug; }
function convert_smilies( string $content, string $which = 'content' ): string {
	return ( $GLOBALS['ssi_plan_options']['use_smilies'] ?? true ) ? 'smilied-' . $which : $content; }
function sanitize_text_field( string $value ): string {
	return $value; }
function update_post_meta( int $id, string $key, string $value ): void {
	$GLOBALS['ssi_plan_meta'][ $id ][ $key ] = $value; }
function get_post_meta( int $id, string $key, bool $single = true ): string {
	return (string) ( $GLOBALS['ssi_plan_meta'][ $id ][ $key ] ?? '' ); }
function get_posts( array $args ): array {
	foreach ( $GLOBALS['ssi_plan_meta'] as $id => $meta ) {
		if ( isset( $meta[ $args['meta_key'] ] ) && ( ! isset( $args['meta_value'] ) || $meta[ $args['meta_key'] ] === $args['meta_value'] ) ) {
			$matches[] = new WP_Post( $id ); }
	}
	return $matches ?? array();
}
function get_page_by_path( string $slug, $output, string $type ) {
	foreach ( $GLOBALS['ssi_plan_posts'] as $id => $post ) {
		if ( $post['post_name'] === $slug && $post['post_type'] === $type ) {
			return new WP_Post( $id ); }
	}
	return null;
}
function wp_insert_post( array $post, bool $wp_error ) {
	++$GLOBALS['ssi_plan_insert_calls'];
	if ( $GLOBALS['ssi_plan_fail_after'] && count( $GLOBALS['ssi_plan_posts'] ) >= $GLOBALS['ssi_plan_fail_after'] ) {
		return new WP_Error( 'simulated_post_failure' ); }
	$id                               = ! empty( $post['ID'] ) ? (int) $post['ID'] : count( $GLOBALS['ssi_plan_posts'] ) + 1;
	$GLOBALS['ssi_plan_posts'][ $id ] = $post;
	return $id;
}
function wp_update_post( array $post, bool $wp_error = false ) {
	unset( $wp_error );
	$id = (int) ( $post['ID'] ?? 0 );
	if ( $id <= 0 || ! isset( $GLOBALS['ssi_plan_posts'][ $id ] ) ) {
		return new WP_Error( 'missing_post' );
	}
	$GLOBALS['ssi_plan_posts'][ $id ] = array_merge( $GLOBALS['ssi_plan_posts'][ $id ], $post );
	return $id;
}
function get_permalink( int|WP_Post $post ): string {
	$id   = $post instanceof WP_Post ? $post->ID : $post;
	$data = $GLOBALS['ssi_plan_posts'][ $id ] ?? array();
	if ( 'post' === ( $data['post_type'] ?? '' ) ) {
		return 'https://example.test/2024/03/' . (string) ( $data['post_name'] ?? '' ) . '/';
	}
	return 'https://example.test/' . (string) ( $data['post_name'] ?? '' ) . '/';
}
function get_post_field( string $field, int $id ): string {
	return stripslashes( (string) ( $GLOBALS['ssi_plan_posts'][ $id ][ $field ] ?? '' ) ); }
function sanitize_title( string $value ): string {
	return trim( (string) preg_replace( '/-+/', '-', preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ), '-' ); }
function wp_kses_post( string $value ): string {
	return $value; }
function post_type_exists( string $type ): bool {
	return 'product' === $type; }
function taxonomy_exists( string $taxonomy ): bool {
	return 'product_cat' === $taxonomy; }
function term_exists( string $term, string $taxonomy ) {
	unset( $term, $taxonomy );
	return null; }
function wp_insert_term( string $term, string $taxonomy ) {
	unset( $term, $taxonomy );
	return array( 'term_id' => 9001 ); }
function wp_set_object_terms( int $object_id, array $terms, string $taxonomy ) {
	unset( $object_id, $taxonomy );
	return $terms; }
function wp_delete_post( int $id, bool $force_delete ) {
	unset( $force_delete );
	if ( ! empty( $GLOBALS['ssi_plan_woo_cleanup_failures'] ) && 9000 <= $id ) {
		return false; }
	if ( ! isset( $GLOBALS['ssi_plan_posts'][ $id ] ) ) {
		return false; }
	$post = $GLOBALS['ssi_plan_posts'][ $id ];
	unset( $GLOBALS['ssi_plan_posts'][ $id ], $GLOBALS['ssi_plan_meta'][ $id ] );
	return $post;
}
function get_term( int $id, string $taxonomy ) {
	unset( $taxonomy );
	return 9001 === $id ? (object) array(
		'term_id' => $id,
		'count'   => 0,
	) : null; }
function wp_delete_term( int $id, string $taxonomy ) {
	unset( $id, $taxonomy );
	return empty( $GLOBALS['ssi_plan_woo_cleanup_failures'] ); }
class WC_Product_Simple {
	private array $data = array();
	public function set_name( string $value ): void {
		$this->data['post_title'] = $value; }
	public function set_slug( string $value ): void {
		$this->data['post_name'] = $value; }
	public function set_status( string $value ): void {
		$this->data['post_status'] = $value; }
	public function set_description( string $value ): void {
		$this->data['post_content'] = $value; }
	public function set_short_description( string $value ): void {
		$this->data['post_excerpt'] = $value; }
	public function set_regular_price( string $value ): void {
		unset( $value ); }
	public function set_sale_price( string $value ): void {
		unset( $value ); }
	public function set_stock_status( string $value ): void {
		unset( $value ); }
	public function set_manage_stock( bool $value ): void {
		unset( $value ); }
	public function set_stock_quantity( int $value ): void {
		unset( $value ); }
	public function save(): int {
		$id                               = 9000 + count( array_filter( $GLOBALS['ssi_plan_posts'], static fn( array $post ): bool => 'product' === ( $post['post_type'] ?? '' ) ) );
		$this->data['post_type']          = 'product';
		$GLOBALS['ssi_plan_posts'][ $id ] = $this->data;
		return $id; }
}
class WP_Post_Type {
	public string $name;
	public bool $public;
	public object $cap;
	public function __construct( string $name, bool $public = true ) {
		$this->name   = $name;
		$this->public = $public;
		$this->cap    = (object) array(
			'create_posts'  => 'page' === $name ? 'edit_pages' : 'edit_posts',
			'publish_posts' => 'page' === $name ? 'publish_pages' : 'publish_posts',
		);
	}
}
function get_post_type_object( string $post_type ): ?object {
	return in_array( $post_type, array( 'page', 'post' ), true ) ? new WP_Post_Type( $post_type ) : null;
}
function wp_parse_args( $args, array $defaults = array() ): array {
	return array_merge( $defaults, is_array( $args ) ? $args : array() );
}

$wp_root = getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ) ?: ( defined( 'ABSPATH' ) ? ABSPATH : '' );
$wp_includes = rtrim( $wp_root, '/\\' ) . '/wp-includes/';
$core_files  = array( 'class-wp-block-parser.php', 'class-wp-block-type.php', 'class-wp-block-type-registry.php', 'blocks.php' );
foreach ( $core_files as $core_file ) {
	if ( ! is_readable( $wp_includes . $core_file ) ) {
		fwrite( STDERR, "SKIP: WordPress parser/serializer files are unavailable. Set STATIC_SITE_IMPORTER_WP_ROOT.\n" );
		exit( 0 );
	}
}
foreach ( $core_files as $core_file ) {
	require_once $wp_includes . $core_file;
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-document-type-classifier.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-artifact-diagnostics-adapter.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-diagnostic-contract.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$theme_generator_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php' );
$assert( false === strpos( (string) $theme_generator_source, 'function import_compiled_website_artifact' ), 'canonical import has no legacy compiled-artifact execution path' );

$artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'      => '<html><head><link rel="stylesheet" href="/assets/site.css"></head><body><header><p>Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main></body></html>',
		'about.html'      => '<main><h1>About</h1></main>',
		'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/site.css' => 'main { background: url(assets/logo.svg); }',
	),
);
$result   = ( new ArtifactCompiler() )->compile( $artifact )->toArray();
$plan     = $result['source_reports']['wordpress_site_plan'];
$assert( 'blocks-engine/wordpress-site-plan/v2' === $plan['schema'], 'compiler emits the released v2 site plan' );
$assert( isset( $result['source_reports']['wordpress_site_plan']['reporting'] ), 'compiler exposes the plan in source reports' );
$plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-plan-identity-fixture' ),
);

// The standalone harness loads Core's parser, not its init registrations.
$register_document_blocks = static function ( array $blocks ) use ( &$register_document_blocks ): void {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$name = $block['blockName'] ?? null;
		if ( is_string( $name ) && '' !== $name && ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			WP_Block_Type_Registry::get_instance()->register( $name, array() );
		}
		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$register_document_blocks( $block['innerBlocks'] );
		}
	}
};
foreach ( $plan['pages'] as $page ) {
	$register_document_blocks( parse_blocks( (string) ( $page['canonical_block_markup'] ?? '' ) ) );
}

$plan_hash = static function ( array $candidate ): string {
	unset( $candidate['quality']['editability_report'], $candidate['quality']['editability_report_plan_hash'], $candidate['quality']['editability_report_required'] );
	$hash = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'hash' );
	return $hash->invoke( null, $candidate );
};
$with_editability_report = static function ( array $candidate ) use ( $result, $plan_hash ): array {
	$candidate['quality']['editability_report_required']  = true;
	$candidate['quality']['editability_report']           = $result['source_reports']['editability_report'];
	$candidate['quality']['editability_report_plan_hash'] = $plan_hash( $candidate );
	return $candidate;
};
$strict_editability_plan = $with_editability_report( $plan );
$strict_editability_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $strict_editability_plan, array( 'slug' => 'strict-editability-plan' ) );
$assert( 'completed' === $strict_editability_receipt['status'] && 'passed' === ( $strict_editability_receipt['editability_report']['status'] ?? '' ) && 'blocks-engine/php-transformer/editability-report/v1' === ( $strict_editability_receipt['editability_report']['report_schema'] ?? '' ), 'valid hash-bound Blocks Engine editability reports are admitted before materialization' );

$compatibility_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'compatibility-editability-plan' ) );
$assert( 'completed' === $compatibility_receipt['status'] && 'compatibility_policy_only' === ( $compatibility_receipt['editability_report']['status'] ?? '' ) && 'editability_report_compatibility_policy_only' === ( $compatibility_receipt['editability_report']['diagnostic']['reason_code'] ?? '' ), 'current producer plans retain explicit compatibility evidence' );

$missing_report_plan                                        = $plan;
$missing_report_plan['quality']['editability_report_required'] = true;
$insert_calls_before_missing                                = $GLOBALS['ssi_plan_insert_calls'];
$missing_report_receipt                                     = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $missing_report_plan, array( 'slug' => 'missing-editability-report' ) );
$assert( 'rejected' === $missing_report_receipt['status'] && 'editability_report_required' === ( $missing_report_receipt['errors'][0]['code'] ?? '' ) && $insert_calls_before_missing === $GLOBALS['ssi_plan_insert_calls'], 'required reports reject missing producer evidence before any write' );

$malformed_report_plan                                      = $strict_editability_plan;
$malformed_report_plan['quality']['editability_report']['schema'] = 'blocks-engine/php-transformer/editability-report/v0';
$malformed_report_receipt                                   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $malformed_report_plan, array( 'slug' => 'malformed-editability-report' ) );
$assert( 'rejected' === $malformed_report_receipt['status'] && 'editability_report_schema_invalid' === ( $malformed_report_receipt['errors'][0]['code'] ?? '' ), 'malformed editability reports are rejected deterministically' );

$hash_mismatch_plan                                      = $strict_editability_plan;
$hash_mismatch_plan['quality']['editability_report_plan_hash'] = str_repeat( '0', 64 );
$hash_mismatch_receipt                                   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $hash_mismatch_plan, array( 'slug' => 'mismatched-editability-report' ) );
$assert( 'rejected' === $hash_mismatch_receipt['status'] && 'editability_report_plan_hash_mismatch' === ( $hash_mismatch_receipt['errors'][0]['code'] ?? '' ), 'reports bound to a different canonical plan are rejected' );

$failed_policy_plan                                              = $strict_editability_plan;
$failed_policy_plan['quality']['status']                        = 'failed';
$failed_policy_plan['quality']['pass']                          = false;
$failed_policy_plan['quality']['editability_policy']['schema']  = 'blocks-engine/php-transformer/editability-policy/v1';
$failed_policy_plan['quality']['editability_policy']['enforcement'] = 'required';
$failed_policy_plan['quality']['editability_policy']['status']  = 'failed';
$failed_policy_plan['quality']['editability_policy']['failures'] = array( array( 'metric' => 'max_nesting_depth', 'actual' => 21, 'maximum' => 20, 'source_path' => 'about.html' ) );
$failed_policy_plan['quality']['editability_report_plan_hash']  = $plan_hash( $failed_policy_plan );
$failed_policy_receipt                                          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $failed_policy_plan, array( 'slug' => 'failed-editability-policy' ) );
$assert( 'rejected' === $failed_policy_receipt['status'] && 'editability_policy_failed' === ( $failed_policy_receipt['errors'][0]['code'] ?? '' ) && 'about.html' === ( $failed_policy_receipt['editability_report']['diagnostic']['threshold_failures'][0]['source_path'] ?? '' ), 'failed producer thresholds hard-gate materialization with bounded source diagnostics' );

// Gutenberg gaps are SSI receipt/report extensions and must never alter the
// compiler-owned plan, whose schema and hash are producer contracts.
$canonical_plan = $plan;
$project_gaps   = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'project_gutenberg_gaps' );
$gaps           = $project_gaps->invoke(
	null,
	array(
		array(
			'id'          => 'gap-plan-contract',
			'block_name'  => 'example/gap',
			'references'  => array( 'file:./view.js' ),
			'source_path' => 'index.html',
		),
	),
	'installed_activated'
);
$assert( $canonical_plan === $plan && ! isset( $plan['gutenberg_gaps'] ), 'gutenberg-gap-projection-does-not-mutate-canonical-plan' );
$gap_contract    = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'success'       => true,
		'status'        => 'completed',
		'import_report' => array(
			'blocks_engine' => array(
				'wordpress_site_plan' => $plan,
				'gutenberg_gaps'      => $gaps,
			),
		),
	)
);
$gap_diagnostics = array_values( array_filter( $gap_contract['diagnostics'] ?? array(), static fn( array $diagnostic ): bool => 'gap-plan-contract' === ( $diagnostic['id'] ?? '' ) ) );
$assert( 'installed_activated' === ( $gap_diagnostics[0]['materialization_status'] ?? '' ) && array( 'file:./view.js' ) === ( $gap_diagnostics[0]['references'] ?? array() ), 'gutenberg-gap-diagnostics-retain-materialization-status-and-references' );

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $receipt['status'], 'valid plan completes' );
$assert( 'static-site-importer/materialization-receipt/v2' === $receipt['schema'] && $plan['plan_identity'] === ( $receipt['plan_identity'] ?? null ), 'receipt binds the producer plan identity.' );
$assert( count( $plan['writes'] ) === count( $receipt['generated_files'] ), 'all canonical writes are materialized' );
$assert( file_exists( $GLOBALS['ssi_plan_root'] . '/site-plan/templates/front-page.html' ), 'templates are materialized' );
$assert( str_contains( file_get_contents( $GLOBALS['ssi_plan_root'] . '/site-plan/assets/assets/site.css' ), 'https://example.test/wp-content/themes/site-plan/assets/assets/logo.svg' ), 'root-relative stylesheet references resolve to declared theme assets' );
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'], 'plan-only materialization does not change reading settings by default' );
$assert( $receipt['plan']['pages'][0]['document_metadata']['links'][0]['resolved_url'] === 'https://example.test/wp-content/themes/site-plan/assets/assets/site.css', 'resolved metadata retains the declared stylesheet destination' );
$assert( array() === $receipt['completed']['runtime_declarations']['asset_publications'], 'plans without publication declarations retain an explicit empty receipt collection' );
$producer_page_markup = (string) ( $receipt['plan']['pages'][0]['resolved_block_markup'] ?? '' );
$page_source          = (string) ( $receipt['plan']['pages'][0]['source_path'] ?? '' );
$page_id              = (int) ( $receipt['completed']['pages'][ $page_source ] ?? 0 );
$persisted_page       = stripslashes( (string) ( $GLOBALS['ssi_plan_posts'][ $page_id ]['post_content'] ?? '' ) );
$assert( $producer_page_markup === $persisted_page, 'materializer-persists-producer-block-markup-without-html-recompilation' );
$short_write_target  = (string) ( $plan['writes'][0]['target_path'] ?? '' );
$short_write_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'                           => 'short-write-plan',
		'inject_materialization_failure' => 'theme_write_short',
	)
);
$short_write_path = $GLOBALS['ssi_plan_root'] . '/short-write-plan/' . $short_write_target;
$assert( 'partial' === $short_write_receipt['status'] && 'theme_write_failed' === ( $short_write_receipt['errors'][0]['code'] ?? '' ) && array() === ( $short_write_receipt['completed']['files'] ?? null ) && array() === ( $short_write_receipt['wordpress'] ?? null ) && ! is_file( $short_write_path ) && empty( glob( dirname( $short_write_path ) . '/.ssi-plan-*' ) ), 'short canonical writes cannot publish a truncated destination and return a rolled-back error receipt' );
$write_payload_bytes = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'write_payload_bytes' );
$referenced_write    = array(
	'payload'           => array(
		'encoding' => 'base64',
		'data'     => '',
	),
	'payload_reference' => array(
		'schema' => 'blocks-engine/payload-reference/v1',
		'id'     => 'binary-1',
		'bytes'  => 12,
		'sha256' => hash( 'sha256', 'binary-bytes' ),
	),
);
$reference_reads     = 0;

$reference_reader   = new class( $reference_reads ) {
	private $reads;
	public function __construct( int &$reads ) {
		$this->reads =& $reads; }
	public function read( array $reference ): string {
		++$this->reads;
		return 'binary-bytes'; }
};
$reference_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare(
	$plan,
	array(
		'slug'                                 => 'reference-ephemeral',
		'_static_site_importer_payload_reader' => $reference_reader,
	)
);
$assert( 'prepared' === ( $reference_prepared['status'] ?? '' ) && 0 === $reference_reads && ! isset( $reference_prepared['args']['_static_site_importer_payload_reader'] ) && isset( $reference_prepared['payload_reader'] ), 'prepared materialization retains payload readers ephemerally without dereferencing or serializing them in args' );
$reference_bytes = $write_payload_bytes->invoke( null, $referenced_write, $reference_reader );
$assert( 'binary-bytes' === $reference_bytes && 1 === $reference_reads, 'referenced binary writes resolve exactly once at their write boundary' );
$reference_write_file = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'write_file' );
$reference_root       = $GLOBALS['ssi_plan_root'] . '/reference-ephemeral';
mkdir( $reference_root, 0777, true );
file_put_contents( $reference_root . '/existing.bin', 'binary-bytes' );
$referenced_write['target_path'] = 'existing.bin';
$referenced_write['source_path'] = 'existing.bin';
$reference_reconciled            = $reference_write_file->invoke( null, $reference_root, $referenced_write, $reference_reader );
$assert( ! is_wp_error( $reference_reconciled ) && 1 === $reference_reads, 'byte-identical referenced files reconcile from their declared hash without reading workspace bytes' );
$missing_reader = $write_payload_bytes->invoke( null, $referenced_write, null );
$assert( is_wp_error( $missing_reader ) && 'static_site_importer_payload_reader_missing' === $missing_reader->get_error_code(), 'referenced writes fail deterministically when no ephemeral reader is available' );
$mismatched_reference                                = $referenced_write;
$mismatched_reference['payload_reference']['sha256'] = str_repeat( '0', 64 );
$mismatch = $write_payload_bytes->invoke( null, $mismatched_reference, $reference_reader );
$assert( is_wp_error( $mismatch ) && 'static_site_importer_payload_reference_hash_mismatch' === $mismatch->get_error_code(), 'referenced writes reject hash mismatches before filesystem mutation' );

// Blocks Engine candidates retain the canonical inline payload and add an
// opaque reference for materialization. The reference remains authoritative.
$reference_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<main><h1>Reference</h1></main>',
			'asset.bin'  => 'canonical-reference-bytes',
		),
	)
)->toArray();
$reference_plan   = $reference_result['source_reports']['wordpress_site_plan'];
$reference_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-reference-plan-identity-fixture' ),
);
foreach ( $reference_plan['writes'] as &$write ) {
	if ( 'asset.bin' === $write['source_path'] ) {
		$write['payload_reference'] = array(
			'schema' => 'blocks-engine/payload-reference/v1',
			'id'     => 'canonical-reference',
			'bytes'  => strlen( 'canonical-reference-bytes' ),
			'sha256' => hash( 'sha256', 'canonical-reference-bytes' ),
		);
	}
}
unset( $write );
$reference_target                   = 'assets/asset.bin';
$posts_before_reference_admission   = $GLOBALS['ssi_plan_posts'];
$inserts_before_reference_admission = $GLOBALS['ssi_plan_insert_calls'];
$throwing_reader                    = new class() {
	public function read( array $reference ): string {
		throw new RuntimeException( 'workspace unavailable' ); }
};
$throwing_reference_receipt         = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$reference_plan,
	array(
		'slug'                                 => 'reference-admission-throwing',
		'_static_site_importer_payload_reader' => $throwing_reader,
	)
);
$assert( 'rejected' === $throwing_reference_receipt['status'] && 'static_site_importer_payload_reference_unavailable' === ( $throwing_reference_receipt['diagnostics'][0]['reason_code'] ?? '' ) && $posts_before_reference_admission === $GLOBALS['ssi_plan_posts'] && $inserts_before_reference_admission === $GLOBALS['ssi_plan_insert_calls'] && ! file_exists( $GLOBALS['ssi_plan_root'] . '/reference-admission-throwing' ), 'throwing canonical reference readers reject before page or filesystem mutation' );
$theme_generator_materialize       = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'materialize_compiled_website_artifact' );
$theme_generator_before_admission  = $GLOBALS['ssi_plan_insert_calls'];
$theme_generator_admission_failure = $theme_generator_materialize->invoke(
	null,
	array(),
	array(
		'slug'                                 => 'theme-generator-reference-admission',
		'_static_site_importer_payload_reader' => $throwing_reader,
	),
	$reference_plan,
	array(),
	array( 'unreachable' => true ),
	array(
		'dependencies' => array(),
		'entities'     => array(),
	),
	array()
);
$assert( is_wp_error( $theme_generator_admission_failure ) && 'static_site_importer_payload_reference_unavailable' === $theme_generator_admission_failure->get_error_code() && $theme_generator_before_admission === $GLOBALS['ssi_plan_insert_calls'] && ! file_exists( $GLOBALS['ssi_plan_root'] . '/theme-generator-reference-admission' ), 'theme generator rejects unavailable references before companion, dependency, entity, page, or filesystem materialization' );
$mismatched_reader            = new class() {
	public function read( array $reference ): string {
		return 'corrupt-reference-bytes'; }
};
$mismatched_reference_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$reference_plan,
	array(
		'slug'                                 => 'reference-admission-mismatched',
		'_static_site_importer_payload_reader' => $mismatched_reader,
	)
);
$assert( 'rejected' === $mismatched_reference_receipt['status'] && 'static_site_importer_payload_reference_hash_mismatch' === ( $mismatched_reference_receipt['diagnostics'][0]['reason_code'] ?? '' ) && $posts_before_reference_admission === $GLOBALS['ssi_plan_posts'] && $inserts_before_reference_admission === $GLOBALS['ssi_plan_insert_calls'] && ! file_exists( $GLOBALS['ssi_plan_root'] . '/reference-admission-mismatched' ), 'mismatched canonical reference readers reject before page or filesystem mutation' );
$valid_reader            = new class() {
	public function read( array $reference ): string {
		return 'canonical-reference-bytes'; }
};
$valid_reference_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$reference_plan,
	array(
		'slug'                                 => 'reference-admission-valid',
		'_static_site_importer_payload_reader' => $valid_reader,
	)
);
$assert( 'completed' === $valid_reference_receipt['status'] && $inserts_before_reference_admission < $GLOBALS['ssi_plan_insert_calls'] && file_exists( $GLOBALS['ssi_plan_root'] . '/reference-admission-valid/' . $reference_target ) && 'canonical-reference-bytes' === file_get_contents( $GLOBALS['ssi_plan_root'] . '/reference-admission-valid/' . $reference_target ), 'valid canonical reference readers pass admission and materialize the declared write' );
$prepared_for_admission = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare(
	$reference_plan,
	array(
		'slug'                                 => 'reference-admission-prepared',
		'_static_site_importer_payload_reader' => $valid_reader,
	)
);
$admitted_prepared      = Static_Site_Importer_WordPress_Site_Plan_Materializer::admit_prepared( $prepared_for_admission );
$assert( 'prepared' === ( $admitted_prepared['status'] ?? '' ) && ! empty( $admitted_prepared['payload_references_admitted'] ) && ! str_contains( (string) wp_json_encode( $admitted_prepared['plan'] ), 'payload_references_admitted' ) && ! str_contains( (string) wp_json_encode( Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $admitted_prepared ) ), 'payload_references_admitted' ), 'prepared admission marker is ephemeral and excluded from canonical plans and receipts' );
$generator_admission         = strpos( (string) $theme_generator_source, '::admit_prepared( $prepared )' );
$generator_binding_preflight = strpos( (string) $theme_generator_source, 'with_resolved_runtime_binding_manifests', $generator_admission + 1 );
$generator_companion         = strpos( (string) $theme_generator_source, 'materialize_companion_dependency', $generator_admission + 1 );
$generator_dependencies      = strpos( (string) $theme_generator_source, 'materialize_prepared_dependencies', $generator_admission + 1 );
$generator_entities          = strpos( (string) $theme_generator_source, 'materialize_prepared_entities', $generator_admission + 1 );
$assert( false !== $generator_admission && $generator_admission < $generator_binding_preflight && $generator_admission < $generator_companion && $generator_admission < $generator_dependencies && $generator_admission < $generator_entities, 'theme generator admits referenced payloads before runtime binding, companion, dependency, and entity materialization work' );
$rollback_order     = array();
$block_lifecycle    = array(
	'dependencies' => array(),
	'entities'     => array(
		'woo'  => array(
			'adapter'  => array(
				'provider'          => 'woocommerce',
				'materializer'      => static fn( array $manifest ): array => array(
					'status'   => 'completed',
					'counts'   => array( 'created' => 1 ),
					'products' => array( array( 'slug' => $manifest['products'][0]['slug'] ) ),
				),
				'rollback_callback' => static function ( array $report ) use ( &$rollback_order ): array {
					$rollback_order[] = 'woo';
					return array( 'status' => 'rolled_back' ); },
			),
			'manifest' => array( 'products' => array( array( 'slug' => 'late-rollback-product' ) ) ),
		),
		'form' => array(
			'adapter'  => array(
				'provider'          => 'jetpack',
				'materializer'      => static fn( array $manifest ): array => array(
					'status' => 'completed',
					'counts' => array( 'created' => 1 ),
					'forms'  => array(
						array(
							'source_path' => $manifest['forms'][0]['source_path'],
							'selector'    => '',
						),
					),
				),
				'rollback_callback' => static function ( array $report ) use ( &$rollback_order ): array {
					$rollback_order[] = 'form';
					return array( 'status' => 'rolled_back' ); },
			),
			'manifest' => array( 'forms' => array( array( 'source_path' => 'index.html' ) ) ),
		),
	),
);
$block_late_failure = $theme_generator_materialize->invoke(
	null,
	array(),
	array(
		'slug'                           => 'block-entity-late-failure',
		'seed_entities'                  => true,
		'font_materialization'           => array(),
		'inject_materialization_failure' => 'report_persistence',
	),
	$plan,
	array(),
	null,
	$block_lifecycle,
	array()
);
$assert( is_wp_error( $block_late_failure ) && 'static_site_importer_projection_write_failed' === $block_late_failure->get_error_code() && array( 'form', 'woo' ) === $rollback_order, 'block-mode Woo and form entities compensate in reverse order when report persistence fails after materialization' );
$deferred_rollback_order = array();
$woo_snapshot_restored   = false;
$deferred_quality_plan   = $plan;
$deferred_quality_plan['quality'] = array(
	'pass'            => false,
	'metrics'         => array( 'fallback_count' => 1 ),
	'failure_reasons' => array( 'unsupported_html_fallback' ),
);
$deferred_quality_plan['diagnostics'] = array( array( 'type' => 'unsupported_html_fallback' ) );
$deferred_quality_lifecycle = array(
	'dependencies' => array(),
	'entities'     => array(
		'woo' => array(
			'adapter'  => array(
				'provider'          => 'woocommerce-like',
				'materializer'      => static fn( array $manifest ): array => array( 'status' => 'completed', 'counts' => array( 'created' => 1 ), 'products' => $manifest['products'], 'rollback' => array( 'existing_product' => array( 'id' => 77, 'name' => 'Before import' ) ) ),
				'rollback_callback' => static function ( array $report ) use ( &$deferred_rollback_order, &$woo_snapshot_restored ): array {
					$deferred_rollback_order[] = 'woo';
					$woo_snapshot_restored = array( 'id' => 77, 'name' => 'Before import' ) === ( $report['rollback']['existing_product'] ?? null );
					return array( 'status' => 'rolled_back', 'reason' => 'restored_existing_product' );
				},
			),
			'manifest' => array( 'products' => array( array( 'slug' => 'existing-product' ) ) ),
		),
		'form' => array(
			'adapter'  => array(
				'provider'          => 'jetpack-like',
				'materializer'      => static fn( array $manifest ): array => array( 'status' => 'completed', 'counts' => array( 'created' => 1 ), 'forms' => $manifest['forms'] ),
				'rollback_callback' => static function ( array $report ) use ( &$deferred_rollback_order ): array {
					$deferred_rollback_order[] = 'form';
					return array( 'status' => 'rolled_back' );
				},
			),
			'manifest' => array( 'forms' => array( array( 'source_path' => 'index.html', 'selector' => 'form.newsletter' ) ) ),
		),
	),
);
$posts_before_deferred_quality = $GLOBALS['ssi_plan_posts'];
$deferred_quality_failure      = $theme_generator_materialize->invoke(
	null,
	array(),
	array( 'slug' => 'deferred-quality-compensation', 'seed_entities' => true, 'font_materialization' => array(), 'fail_on_quality' => true, '_static_site_importer_deferred_form_quality_admission' => true ),
	$deferred_quality_plan,
	array(),
	null,
	$deferred_quality_lifecycle,
	array()
);
$deferred_quality_receipt = is_wp_error( $deferred_quality_failure ) ? $deferred_quality_failure->get_error_data() : array();
$assert( is_wp_error( $deferred_quality_failure ) && 'static_site_importer_quality_gate_failed' === $deferred_quality_failure->get_error_code() && array( 'form', 'woo' ) === $deferred_rollback_order && $woo_snapshot_restored && $posts_before_deferred_quality === $GLOBALS['ssi_plan_posts'] && 'rolled_back' === ( $deferred_quality_receipt['entity_compensation']['status'] ?? '' ) && 'final_quality_admission' === ( $deferred_quality_receipt['failure_context']['stage'] ?? '' ), 'theme generator final quality admission compensates providers in reverse order, restores pre-existing provider state, and rolls back the site plan' );

$classic_artifact   = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'      => '<html><head><link rel="stylesheet" href="assets/site.css"></head><body><header><a href="about.html">Nav</a></header><main><img src="assets/logo.svg" onerror="alert(1)"><a href="javascript:alert(1)">Unsafe</a><h1>Home</h1></main><footer>Footer</footer><script>alert(1)</script></body></html>',
		'about.html'      => '<main><h1>About</h1><img src="assets/logo.svg"></main>',
		'assets/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/site.css' => 'main{background:url(logo.svg)}',
	),
);
$classic_plan       = ( new ArtifactCompiler() )->compile( $classic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$classic_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-classic-plan-identity-fixture' ),
);
$classic_projection = Static_Site_Importer_Classic_Theme_Projection::build( $classic_artifact, $classic_plan );
$assert( ! is_wp_error( $classic_projection ), 'normalized artifact produces a render-neutral SSI classic projection without block reverse conversion' );
$woo_late_failure_lifecycle = array(
	'dependencies' => array(),
	'entities'     => array(
		'woo' => array(
			'adapter'  => array(
				'provider'                 => 'woocommerce',
				'materializer'             => array( 'Static_Site_Importer_Woo_Product_Seeder', 'seed' ),
				'rollback_callback'        => array( 'Static_Site_Importer_Woo_Product_Seeder', 'rollback' ),
				'classic_binding_callback' => array( 'Static_Site_Importer_Woo_Product_Seeder', 'binding_classic_render' ),
			),
			'manifest' => array(
				'products' => array(
					array(
						'slug'        => 'residual-woo-product',
						'name'        => 'Residual Woo Product',
						'categories'  => array( 'Residual Woo Category' ),
						'source_path' => 'index.html',
						'selector'    => 'h1',
					),
				),
			),
		),
	),
);
foreach ( array(
	'block'   => array(
		'plan' => $plan,
		'args' => array(),
	),
	'classic' => array(
		'plan' => $classic_plan,
		'args' => array(
			'theme_materialization'    => 'classic',
			'classic_theme_projection' => $classic_projection,
		),
	),
) as $strategy => $fixture ) {
	$GLOBALS['ssi_plan_woo_cleanup_failures'] = true;
	$late_failure                             = $theme_generator_materialize->invoke(
		null,
		array(),
		array_merge(
			array(
				'slug'                           => 'woo-late-' . $strategy,
				'seed_entities'                  => true,
				'font_materialization'           => array(),
				'inject_materialization_failure' => 'report_persistence',
			),
			$fixture['args']
		),
		$fixture['plan'],
		array(),
		null,
		$woo_late_failure_lifecycle,
		array()
	);
	$late_receipt                             = is_wp_error( $late_failure ) ? $late_failure->get_error_data() : array();
	$rollback                                 = $late_receipt['entity_compensation']['entities'][0] ?? array();
	$diagnostics                              = $late_receipt['diagnostics'] ?? array();
	$assert(
		is_wp_error( $late_failure )
		&& 'partial' === ( $late_receipt['status'] ?? '' )
		&& 'report_persistence' === ( $late_receipt['failure_context']['stage'] ?? '' )
		&& 'partial' === ( $late_receipt['entity_compensation']['status'] ?? '' )
		&& 'woo' === ( $rollback['entity_id'] ?? '' )
		&& 'woocommerce' === ( $rollback['adapter'] ?? '' )
		&& 'partial' === ( $rollback['status'] ?? '' )
		&& ! empty( $rollback['rollback']['product_cleanup_failures'] ?? array() )
		&& array( 9001 ) === ( $rollback['rollback']['term_cleanup_failures'] ?? array() )
		&& ! empty( $rollback['residual_state']['products'] ?? array() )
		&& array( 9001 ) === ( $rollback['residual_state']['terms'] ?? array() )
		&& array() !== array_filter( $diagnostics, static fn( array $diagnostic ): bool => 'static_site_importer_projection_write_failed' === ( $diagnostic['reason_code'] ?? '' ) && 'report_persistence' === ( $diagnostic['stage'] ?? '' ) )
		&& array() !== array_filter( $diagnostics, static fn( array $diagnostic ): bool => 'entity_compensation_partial' === ( $diagnostic['reason_code'] ?? '' ) && 'woo' === ( $diagnostic['entity_id'] ?? '' ) && 'woocommerce' === ( $diagnostic['adapter'] ?? '' ) ),
		$strategy . ' late report persistence failure returns original stage and bounded Woo product/category residual compensation evidence'
	);
	$GLOBALS['ssi_plan_woo_cleanup_failures'] = false;
	foreach ( $GLOBALS['ssi_plan_posts'] as $id => $post ) {
		if ( 'product' === ( $post['post_type'] ?? '' ) ) {
			unset( $GLOBALS['ssi_plan_posts'][ $id ] ); }
	}
}
$classic_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$classic_plan,
	array(
		'slug'                     => 'classic-site-plan',
		'name'                     => 'Classic Site',
		'theme_materialization'    => 'classic',
		'classic_theme_projection' => $classic_projection,
		'activate'                 => true,
	)
);
$classic_root    = $GLOBALS['ssi_plan_root'] . '/classic-site-plan';
$classic_pages   = json_decode( (string) file_get_contents( $classic_root . '/classic-pages.json' ), true );
$assert( 'completed' === $classic_receipt['status'] && 'source_artifact_projection' === ( $classic_receipt['theme_materialization']['status'] ?? '' ), 'classic strategy materializes through the canonical receipt path with strategy evidence' );
$assert( array() === array_diff( array( 'style.css', 'functions.php', 'header.php', 'footer.php', 'front-page.php', 'page.php', 'single.php', 'index.php', 'archive.php', 'search.php', '404.php', 'classic-pages.json', 'classic-chrome.json', 'classic-bindings.json', 'assets/assets/logo.svg', 'assets/assets/site.css' ), array_column( $classic_receipt['completed']['files'], 'target_path' ) ), 'classic receipt records the complete fixed scaffold, canonical assets, and inert data files' );
$assert( str_contains( (string) ( $classic_pages['pages']['index.html']['html'] ?? '' ), 'https://example.test/wp-content/themes/classic-site-plan/assets/assets/logo.svg' ) && ! str_contains( (string) ( $classic_pages['pages']['index.html']['html'] ?? '' ), 'onerror=' ) && ! str_contains( (string) ( $classic_pages['pages']['index.html']['html'] ?? '' ), 'javascript:' ) && ! str_contains( (string) ( $classic_pages['pages']['index.html']['html'] ?? '' ), '<script' ), 'classic page data rewrites declared asset URLs and strips executable artifact HTML' );
$assert( str_contains( (string) file_get_contents( $classic_root . '/functions.php' ), 'get_post_meta( get_queried_object_id()' ) && str_contains( (string) file_get_contents( $classic_root . '/functions.php' ), "wp_enqueue_style( 'static-site-importer-classic'" ) && 'classic-site-plan' === ( $GLOBALS['ssi_plan_options']['stylesheet'] ?? '' ), 'classic scaffold resolves data by reconciliation provenance, enqueues its stylesheet, and activates through the existing operation lifecycle' );
$hostile_projection                                   = $classic_projection;
$hostile_projection['pages']['index.html']['html']    = '<main><script>classic-hostile</script><img src="javascript:classic-hostile" onerror="classic-hostile"><iframe srcdoc="<script>classic-hostile</script>"></iframe><svg><animate attributeName="href" values="javascript:classic-hostile"></animate></svg></main>';
$hostile_projection['chrome']['header']               = '<header onclick="classic-hostile"><a href="javascript:classic-hostile">Hostile</a><svg><set attributeName="href" to="javascript:classic-hostile"></set></svg></header>';
$hostile_projection['stylesheets']['assets/site.css'] = 'body{background:url(javascript:classic-hostile);behavior:url(classic-hostile)}';
$hostile_receipt                                      = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$classic_plan,
	array(
		'slug'                     => 'classic-hostile-direct',
		'name'                     => 'Classic Hostile Direct',
		'theme_materialization'    => 'classic',
		'classic_theme_projection' => $hostile_projection,
	)
);
$hostile_root   = $GLOBALS['ssi_plan_root'] . '/classic-hostile-direct';
$hostile_output = (string) file_get_contents( $hostile_root . '/classic-pages.json' ) . (string) file_get_contents( $hostile_root . '/classic-chrome.json' ) . (string) file_get_contents( $hostile_root . '/classic-bindings.json' ) . (string) file_get_contents( $hostile_root . '/functions.php' ) . (string) file_get_contents( $hostile_root . '/style.css' );
$assert( 'completed' === $hostile_receipt['status'] && array() === array_filter( array( '<script', 'onerror', 'onclick', 'javascript:', 'srcdoc', '<iframe', '<animate', '<set', 'behavior:' ), static fn( string $needle ): bool => str_contains( strtolower( $hostile_output ), $needle ) ), 'direct classic projections sanitize script, event, javascript URL, srcdoc, and SVG mutation payloads before JSON or PHP rendering' );
$invalid_projection                         = $classic_projection;
$invalid_projection['pages']['forged.html'] = array(
	'source_path' => 'forged.html',
	'html'        => '<script>classic-hostile</script>',
);
$invalid_projection_receipt                 = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$classic_plan,
	array(
		'slug'                     => 'classic-invalid-direct',
		'theme_materialization'    => 'classic',
		'classic_theme_projection' => $invalid_projection,
	)
);
$assert( 'rejected' === $invalid_projection_receipt['status'] && 'static_site_importer_classic_projection_page_structure_invalid' === ( $invalid_projection_receipt['diagnostics'][0]['reason_code'] ?? '' ) && ! is_dir( $GLOBALS['ssi_plan_root'] . '/classic-invalid-direct' ), 'direct classic projections reject forged structural page fields before mutation' );
$unbound_provenance = $receipt['completed']['block_provenance'] ?? array();
$assert( count( $plan['pages'] ) === count( $unbound_provenance ) && count( $plan['pages'] ) === ( $receipt['completed']['block_provenance_count'] ?? 0 ), 'ordinary resolved pages receive receipt provenance without runtime bindings' );
$assert( 'blocks-engine/wordpress-site-plan-resolver' === ( $unbound_provenance[0]['stages'][0]['stage'] ?? '' ) && hash( 'sha256', $receipt['plan']['pages'][0]['resolved_block_markup'] ) === ( $unbound_provenance[0]['stages'][0]['output']['sha256'] ?? '' ), 'ordinary page provenance records the resolver output hash' );
$assert( false === ( $receipt['completed']['block_provenance_truncated'] ?? true ) && ! str_contains( (string) wp_json_encode( $unbound_provenance ), (string) $receipt['plan']['pages'][0]['resolved_block_markup'] ), 'provenance uses structural evidence without raw page markup' );

$overlay_css        = "/* Static Site Importer provider layout overlay: abcdef123456 */\n.ssi-form-123456789abc > form.jetpack-contact-form__form{display:flex;gap:1rem}\n";
$overlay            = array(
	'schema' => Static_Site_Importer_Provider_Layout_Overlay::OVERLAY_SCHEMA,
	'css'    => $overlay_css,
	'sha256' => hash( 'sha256', $overlay_css ),
	'bytes'  => strlen( $overlay_css ),
);
$collect_overlays   = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'provider_layout_overlays_from_entity_reports' );
$collected_overlays = $collect_overlays->invoke( null, array( array( 'forms' => array( array( 'provider_layout_overlay_css' => array() ), array( 'provider_layout_overlay_css' => $overlay ), array( 'provider_layout_overlay_css' => array( 'malformed' => true ) ) ) ) ) );
$assert( array( $overlay, array( 'malformed' => true ) ) === $collected_overlays, 'provider layout collection omits empty absence sentinels without hiding non-empty overlays from strict validation' );
$overlay_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'                     => 'provider-overlay-plan',
		'provider_layout_overlays' => array( $overlay, $overlay ),
	)
);
$overlay_root    = $GLOBALS['ssi_plan_root'] . '/provider-overlay-plan';
$assert( 'completed' === $overlay_receipt['status'] && 'completed' === ( $overlay_receipt['completed']['provider_layout_overlays']['status'] ?? '' ), 'provider layout receipt is applied only after stylesheet writes complete' );
$assert( str_contains( (string) file_get_contents( $overlay_root . '/style.css' ), 'provider layout overlay: abcdef123456' ) && str_contains( (string) file_get_contents( $overlay_root . '/assets/css/editor-style.css' ), 'provider layout overlay: abcdef123456' ), 'generated frontend and editor stylesheets contain the deduplicated provider overlay' );
$resumed_overlay_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'                     => 'provider-overlay-plan',
		'provider_layout_overlays' => array( $overlay ),
	)
);
$resumed_overlay_files   = $resumed_overlay_receipt['completed']['provider_layout_overlays']['files'] ?? array();
$assert( 'completed' === $resumed_overlay_receipt['status'] && 'already_satisfied' === ( $resumed_overlay_receipt['completed']['provider_layout_overlays']['status'] ?? '' ) && 2 === count( $resumed_overlay_files ) && array() === array_filter( $resumed_overlay_files, static fn( array $file ): bool => 'already_satisfied' !== ( $file['status'] ?? '' ) ), 'resumed provider overlay reconciles byte-identical stylesheet state with receipt evidence' );
$canonical_overlay_targets = array( 'style.css', 'assets/css/editor-style.css' );
$canonical_overlay_entries = static fn( array $receipt ): array => array_values( array_filter( $receipt['generated_files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) );
$initial_overlay_entries   = $canonical_overlay_entries( $overlay_receipt );
$resumed_overlay_entries   = $canonical_overlay_entries( $resumed_overlay_receipt );
$assert( 2 === count( $initial_overlay_entries ) && $initial_overlay_entries === $resumed_overlay_entries && $initial_overlay_entries === array_values( array_filter( $overlay_receipt['completed']['files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) ) && $resumed_overlay_entries === array_values( array_filter( $resumed_overlay_receipt['completed']['files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) ), 'overlay resume preserves compatible canonical stylesheet entries in completed and legacy file receipts' );
$assert( array() === array_filter( $resumed_overlay_entries, static fn( array $file ): bool => ! isset( $file['reconciliation_identity'], $file['hash'], $file['payload_hash'] ) || isset( $file['status'] ) ) && array() === array_filter( $resumed_overlay_entries, static fn( array $file ): bool => ! in_array( $file['hash'], array( hash_file( 'sha256', $overlay_root . '/style.css' ), hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ) ), true ) ), 'canonical stylesheet receipt entries preserve reconciliation compatibility with final overlay bytes' );
$overlay_hashes                = array(
	'style.css'                   => hash_file( 'sha256', $overlay_root . '/style.css' ),
	'assets/css/editor-style.css' => hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ),
);
$conflicting_overlay           = $overlay;
$conflicting_overlay['css']    = str_replace( 'gap:1rem', 'gap:2rem', $overlay['css'] );
$conflicting_overlay['sha256'] = hash( 'sha256', $conflicting_overlay['css'] );
$conflicting_overlay['bytes']  = strlen( $conflicting_overlay['css'] );
$conflicting_overlay_receipt   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'                     => 'provider-overlay-plan',
		'provider_layout_overlays' => array( $conflicting_overlay ),
	)
);
$assert( 'rejected' === $conflicting_overlay_receipt['status'] && 'provider_layout_overlay_rejected' === ( $conflicting_overlay_receipt['diagnostics'][0]['reason_code'] ?? '' ) && $overlay_hashes['style.css'] === hash_file( 'sha256', $overlay_root . '/style.css' ) && $overlay_hashes['assets/css/editor-style.css'] === hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ), 'conflicting provider overlay is rejected before either stylesheet changes' );
$forged_overlay        = $overlay;
$forged_overlay['css'] = "/* Static Site Importer provider layout overlay: abcdef123456 */\nbody{background:url(https://example.test/x)}\n";
$forged_root           = $GLOBALS['ssi_plan_root'] . '/forged-provider-overlay-plan';
$forged_receipt        = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'                     => 'forged-provider-overlay-plan',
		'provider_layout_overlays' => array( $forged_overlay ),
	)
);
$assert( 'rejected' === $forged_receipt['status'] && 'provider_layout_overlay_rejected' === ( $forged_receipt['diagnostics'][0]['reason_code'] ?? '' ) && 'not_requested' === ( $forged_receipt['completed']['provider_layout_overlays']['status'] ?? '' ) && ! file_exists( $forged_root ), 'forged provider overlay is rejected before any write claim or file mutation' );
$conflict_root = $GLOBALS['ssi_plan_root'] . '/canonical-file-conflict';
mkdir( $conflict_root, 0777, true );
file_put_contents( $conflict_root . '/theme.json', '{"conflict":true}' );
$canonical_conflict_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'canonical-file-conflict' ) );
$assert( 'rejected' === $canonical_conflict_receipt['status'] && 'file_conflict' === ( $canonical_conflict_receipt['diagnostics'][0]['reason_code'] ?? '' ) && '{"conflict":true}' === file_get_contents( $conflict_root . '/theme.json' ), 'unrelated canonical file conflicts remain rejected and unchanged' );

$explicit_styles_root   = $GLOBALS['ssi_plan_root'] . '/explicit-canonical-styles';
$explicit_styles        = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'provider_layout_stylesheet_writes' );
$explicit_styles_writes = $explicit_styles->invoke(
	null,
	array(
		'theme_dir' => $explicit_styles_root,
		'theme'     => array( 'slug' => 'explicit-canonical-styles' ),
		'resolved'  => array(
			'writes' => array(
				array(
					'target_path' => 'style.css',
					'payload'     => array(
						'encoding' => 'utf8',
						'data'     => 'body{color:black}',
					),
				),
				array(
					'target_path' => 'assets/css/editor-style.css',
					'payload'     => array(
						'encoding' => 'utf8',
						'data'     => '.editor-styles-wrapper{color:black}',
					),
				),
			),
		),
	),
	array( $overlay )
);
$assert( is_array( $explicit_styles_writes ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/style.css' ] ?? '', 'body{color:black}' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/assets/css/editor-style.css' ] ?? '', '.editor-styles-wrapper{color:black}' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/style.css' ] ?? '', 'provider layout overlay: abcdef123456' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/assets/css/editor-style.css' ] ?? '', 'provider layout overlay: abcdef123456' ), 'explicit canonical frontend and editor stylesheet payloads derive independent overlay-composed writes' );

$font_result          = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Example+Font:wght@400&amp;display=swap"><style>body{font-family:"Example Font",sans-serif}</style></head><body><main><svg xmlns="http://www.w3.org/2000/svg"><text font-family="Example Font, sans-serif">Label</text></svg></main></body></html>',
		),
	)
)->toArray();
$font_plan            = $font_result['source_reports']['wordpress_site_plan'];
$font_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-font-plan-identity-fixture' ),
);
$font_materialization = $font_result['source_reports']['materialization_plan']['theme']['font_materialization'];
$font_receipt         = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_plan,
	array(
		'slug'                 => 'font-site-plan',
		'font_materialization' => $font_materialization,
	)
);
$font_root            = $GLOBALS['ssi_plan_root'] . '/font-site-plan';
$assert( 'completed' === $font_receipt['status'], 'canonical font materialization completes' );
$assert( $font_plan['plan_identity'] === ( $font_receipt['plan_identity'] ?? null ), 'font overlay leaves the producer canonical plan identity unchanged' );
$assert( file_exists( $font_root . '/assets/css/fonts.css' ), 'declared font stylesheet is materialized' );
$assert( str_contains( (string) file_get_contents( $font_root . '/assets/css/embedded-fonts.css' ), 'data:font/woff2;base64,' ), 'self-contained font stylesheet is materialized' );
$assert( str_contains( (string) file_get_contents( $font_root . '/functions.php' ), "wp_enqueue_style( 'static-site-importer-embedded-fonts'" ), 'generated theme loads self-contained font stylesheet' );
$font_svg_files = array_values( array_filter( $font_receipt['completed']['font_materialization']['files'] ?? array(), static fn( array $file ): bool => str_ends_with( (string) ( $file['target_path'] ?? '' ), '.svg' ) ) );
$assert( ! empty( $font_svg_files ) && array() === array_filter( $font_svg_files, static fn( array $file ): bool => ! str_contains( (string) file_get_contents( $font_root . '/' . $file['target_path'] ), 'data:font/woff2;base64,' ) ), 'legacy font plans retain self-contained generated SVGs when typed consumers are unavailable' );
$assert( 2 === count( $GLOBALS['ssi_plan_font_requests'] ), 'font materialization fetches one declared stylesheet and one unique payload' );
$assert( Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text style="font-family:\'Example Font\', serif">Label</text></svg>', array( 'Example Font' ) ), 'SVG style declarations match quoted families within fallback lists' );
$assert( Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text font-family="serif, Example Font">Label</text></svg>', array( 'example font' ) ), 'SVG presentation attributes normalize case and fallback-list position' );
$assert( ! Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text font-family="Example Font Pro, sans-serif">Label</text></svg>', array( 'Example Font' ) ), 'SVG font matching compares complete family tokens instead of prefixes' );

$inter_payload                                        = "\xff" . str_repeat( "\x80", 1048575 );
$GLOBALS['ssi_plan_binary_font']                      = $inter_payload;
$typed_font_plan                                      = array(
	'schema'           => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'webfont_contract' => array(
		'schema'            => 'blocks-engine/webfont-materialization/v1',
		'imports'           => array(
			array(
				'id'     => 'webfont-import-inter',
				'state'  => 'declared',
				'source' => array(
					'url'             => 'https://fonts.googleapis.com/css2?family=Inter-like:wght@400;700',
					'format'          => 'css',
					'expected_digest' => null,
					'observed_digest' => null,
				),
			),
		),
		'faces'             => array(
			array(
				'id'             => 'webfont-face-inter-400',
				'import_id'      => 'webfont-import-inter',
				'receipt_id'     => 'webfont-receipt-inter-400',
				'state'          => 'declared',
				'family'         => 'Inter-like',
				'style'          => 'normal',
				'weight'         => array(
					'kind'  => 'static',
					'value' => 400,
				),
				'axes'           => array(
					'wght' => array(
						'kind'  => 'static',
						'value' => 400,
					),
				),
				'unicode_ranges' => array(),
			),
			array(
				'id'             => 'webfont-face-inter-700',
				'import_id'      => 'webfont-import-inter',
				'receipt_id'     => 'webfont-receipt-inter-700',
				'state'          => 'declared',
				'family'         => 'Inter-like',
				'style'          => 'normal',
				'weight'         => array(
					'kind'  => 'static',
					'value' => 700,
				),
				'axes'           => array(
					'wght' => array(
						'kind'  => 'static',
						'value' => 700,
					),
				),
				'unicode_ranges' => array(),
			),
			array(
				'id'             => 'webfont-face-inter-variable',
				'import_id'      => 'webfont-import-inter',
				'receipt_id'     => 'webfont-receipt-inter-variable',
				'state'          => 'declared',
				'family'         => 'Inter-like',
				'style'          => 'normal',
				'weight'         => array(
					'kind' => 'range',
					'min'  => 100,
					'max'  => 900,
				),
				'axes'           => array(
					'wght' => array(
						'kind' => 'range',
						'min'  => 100,
						'max'  => 900,
					),
					'wdth' => array(
						'kind' => 'range',
						'min'  => 75,
						'max'  => 125,
					),
				),
				'unicode_ranges' => array( 'U+0000-00FF' ),
			),
		),
		'receipts'          => array(
			array(
				'id'        => 'webfont-receipt-inter-400',
				'face_id'   => 'webfont-face-inter-400',
				'import_id' => 'webfont-import-inter',
				'state'     => 'pending_browser_readiness',
			),
			array(
				'id'        => 'webfont-receipt-inter-700',
				'face_id'   => 'webfont-face-inter-700',
				'import_id' => 'webfont-import-inter',
				'state'     => 'pending_browser_readiness',
			),
			array(
				'id'        => 'webfont-receipt-inter-variable',
				'face_id'   => 'webfont-face-inter-variable',
				'import_id' => 'webfont-import-inter',
				'state'     => 'pending_browser_readiness',
			),
		),
		'browser_readiness' => array(
			'state'                => 'required',
			'required_receipt_ids' => array( 'webfont-receipt-inter-400', 'webfont-receipt-inter-700', 'webfont-receipt-inter-variable' ),
		),
		'diagnostics'       => array(),
	),
);
$typed_svg_writes                                     = array_values( array_filter( $font_plan['writes'], static fn( array $write ): bool => str_ends_with( (string) $write['target_path'], '.svg' ) ) );
$typed_svg_write                                      = $typed_svg_writes[0] ?? array();
$typed_svg_hash                                       = hash( 'sha256', $typed_svg_write['payload']['data'] );
$typed_svg_face_ids                                   = array( 'webfont-face-inter-400' );
$typed_svg_source_path                                = $typed_svg_write['source_path'];
$typed_svg_write_path                                 = $typed_svg_write['target_path'];
$typed_font_plan['webfont_contract']['svg_consumers'] = array(
	array(
		'id'                         => 'svg-webfont-consumer-' . substr( hash( 'sha256', $typed_svg_source_path . "\n" . $typed_svg_write_path . "\n" . $typed_svg_hash . "\n" . implode( "\n", $typed_svg_face_ids ) ), 0, 20 ),
		'source_path'                => $typed_svg_source_path,
		'write_path'                 => $typed_svg_write_path,
		'pre_transform_payload_hash' => $typed_svg_hash,
		'face_ids'                   => $typed_svg_face_ids,
		'receipt_ids'                => array( 'webfont-receipt-inter-400' ),
		'required'                   => true,
	),
);
$typed_font_receipt                                   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_plan,
	array(
		'slug'                 => 'typed-font-site-plan',
		'font_materialization' => $typed_font_plan,
	)
);
$typed_font_root                                      = $GLOBALS['ssi_plan_root'] . '/typed-font-site-plan';
$typed_faces = $typed_font_receipt['completed']['font_materialization']['required_faces'] ?? array();
$assert(
	'completed' === $typed_font_receipt['status'] && 3 === count( $typed_faces ) && array(
		'kind' => 'range',
		'min'  => 100,
		'max'  => 900,
	) === ( $typed_faces[2]['weight'] ?? null ) && array(
		'kind' => 'range',
		'min'  => 75,
		'max'  => 125,
	) === ( $typed_faces[2]['axes']['wdth'] ?? null ) && array( 'U+0000-00FF' ) === ( $typed_faces[2]['unicode_ranges'] ?? null ),
	'nested producer contract retains static and range weights, all axes, unicode ranges, and receipt provenance'
);
$assert( $inter_payload === file_get_contents( $typed_font_root . '/' . $typed_faces[0]['assets'][0]['target_path'] ) && $inter_payload === file_get_contents( $typed_font_root . '/' . $typed_faces[1]['assets'][0]['target_path'] ), 'typed font assets are locally materialized as verified binary payloads without network-dependent test fixtures' );
$typed_asset = $typed_faces[0]['assets'][0] ?? array();
$assert( array( 'target_path', 'format', 'source_url', 'expected_sha256', 'observed_sha256' ) === array_keys( $typed_asset ) && ! isset( $typed_asset['payload'] ) && hash( 'sha256', $inter_payload ) === ( $typed_asset['observed_sha256'] ?? '' ) && is_string( wp_json_encode( $typed_font_receipt, JSON_UNESCAPED_SLASHES ) ), 'completed font receipts exclude invalid binary payloads while retaining path, format, source, and digest evidence' );
$typed_plan_writes = $typed_font_receipt['plan']['writes'] ?? array();
$assert( ! empty( $typed_plan_writes ) && array() === array_filter( $typed_plan_writes, static fn( array $write ): bool => ! isset( $write['payload']['encoding'], $write['payload']['data'] ) ), 'public receipt plan preserves the canonical v2 write payload shape and encoding' );
$projection_path = $GLOBALS['ssi_plan_root'] . '/large-invalid-binary-report.json';
$projection_payload = array( 'schema' => 'static-site-importer/import-report/v1', 'materialization_receipt' => $typed_font_receipt );
$projection_receipt = $typed_font_receipt;
$write_projection = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'write_plan_projection' );
$write_projection->invokeArgs( null, array( $projection_path, $projection_payload, &$projection_receipt ) );
$projection_json = (string) file_get_contents( $projection_path );
$projection = json_decode( $projection_json, true );
$assert( is_array( $projection ) && false === str_contains( $projection_json, $inter_payload ) && hash( 'sha256', $inter_payload ) === ( $projection['materialization_receipt']['completed']['font_materialization']['required_faces'][0]['assets'][0]['observed_sha256'] ?? '' ), 'streamed report persistence retains canonical receipt structure without invalid font bytes while retaining digest evidence' );
$json_compatibility_payload = array(
	'unicode' => json_decode( '"\\u00e9 \\u6f22 \\ud83d\\ude80"' ),
	'escaped' => "quote:\" slash:/ backslash:\\ control:\n\t\x01",
);
$json_compatibility_path = $GLOBALS['ssi_plan_root'] . '/json-compatibility.json';
$json_compatibility_receipt = array();
$write_projection->invokeArgs( null, array( $json_compatibility_path, $json_compatibility_payload, &$json_compatibility_receipt ) );
$assert( (string) wp_json_encode( $json_compatibility_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" === file_get_contents( $json_compatibility_path ), 'streamed public JSON matches historical WordPress encoding for Unicode, quotes, slashes, and control characters' );
$deferred_font_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_plan,
	array(
		'slug'                        => 'deferred-font-report-plan',
		'font_materialization'        => $typed_font_plan,
		'defer_materialization_commit' => true,
	)
);
$large_asset_base64 = base64_encode( $inter_payload );
$deferred_font_receipt['plan']['assets'][] = array(
	'source_path'    => 'assets/fonts/large-invalid.woff2',
	'target_path'    => 'assets/fonts/large-invalid.woff2',
	'content_base64' => $large_asset_base64,
);
$GLOBALS['ssi_plan_json_array_calls'] = 0;
$GLOBALS['ssi_plan_count_aggregate_encodes'] = true;
$production_result = $write_projection->getDeclaringClass()->getMethod( 'public_result_from_wordpress_site_plan_receipt' )->invoke(
	null,
	$deferred_font_receipt,
	array(
		'slug'                         => 'deferred-font-report-plan',
		'artifact_hash'                => hash( 'sha256', 'deferred-font-report-plan' ),
		'write_theme_report_artifacts' => true,
	)
);
$GLOBALS['ssi_plan_count_aggregate_encodes'] = false;
$production_report_json = (string) file_get_contents( $production_result['report_path'] ?? '' );
$production_report = json_decode( $production_report_json, true );
$assert( 0 === $GLOBALS['ssi_plan_json_array_calls'] && is_array( $production_report ) && $large_asset_base64 === ( $production_report['blocks_engine']['wordpress_site_plan']['assets'][ count( $production_report['blocks_engine']['wordpress_site_plan']['assets'] ) - 1 ]['content_base64'] ?? '' ) && isset( $production_report['materialization_receipt']['transaction']['state'] ) && hash( 'sha256', $inter_payload ) === ( $production_report['materialization_receipt']['completed']['font_materialization']['required_faces'][0]['assets'][0]['observed_sha256'] ?? '' ), 'production report assembly streams deferred transaction state and large base64 assets without aggregate JSON encoding while preserving canonical report fields and invalid-font digest evidence' );
$typed_css = (string) file_get_contents( $typed_font_root . '/assets/css/embedded-fonts.css' );
$assert( str_contains( $typed_css, 'font-weight:100 900' ) && str_contains( $typed_css, 'font-stretch:75% 125%' ) && str_contains( $typed_css, 'unicode-range:U+0000-00FF' ) && ! str_contains( $typed_css, 'fonts.example.test' ), 'producer font faces preserve all declared axes and unicode ranges while rewriting only local sources' );
$typed_readiness = (string) file_get_contents( $typed_font_root . '/assets/js/font-readiness.js' );
$assert( str_contains( $typed_readiness, 'document.fonts.load' ) && str_contains( $typed_readiness, 'SSI glyph evidence' ) && str_contains( $typed_readiness, 'status:"missing"' ), 'required typed faces install a glyph-based document.fonts readiness probe with retained missing evidence' );
$typed_svg_receipts = $typed_font_receipt['completed']['font_materialization']['svg_receipts'] ?? array();
$assert( 1 === count( $typed_svg_receipts ) && hash( 'sha256', file_get_contents( $typed_font_root . '/' . $typed_svg_write['target_path'] ) ) === ( $typed_svg_receipts[0]['output_sha256'] ?? '' ) && str_contains( (string) file_get_contents( $typed_font_root . '/' . $typed_svg_write['target_path'] ), 'data:font/woff2;base64,' ), 'final write verification accepts the declared SVG change only through its hash-bound materialization receipt' );
$invalid_typed_plan = $typed_font_plan;
$invalid_typed_plan['webfont_contract']['imports'][0]['source']['expected_digest'] = 'sha256:' . str_repeat( '0', 64 );
$invalid_typed_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_plan,
	array(
		'slug'                 => 'invalid-typed-font-site-plan',
		'font_materialization' => $invalid_typed_plan,
	)
);
$assert( 'partial' === $invalid_typed_receipt['status'] && 'static_site_importer_font_materialization_producer_stylesheet_failed' === ( $invalid_typed_receipt['errors'][0]['code'] ?? '' ), 'required producer source digest mismatch fails explicitly before theme activation' );
$assert( 'producer_stylesheet_digest_mismatch' === ( $invalid_typed_receipt['diagnostics'][1]['reason_code'] ?? '' ), 'font materialization receipts retain the producer failure reason instead of only the generic error code' );

$font_without_svg_result  = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Example+Font:wght@400&amp;display=swap"><style>body{font-family:"Example Font",sans-serif}</style></head><body><main>Text</main></body></html>',
		),
	)
)->toArray();
$font_without_svg_result['source_reports']['wordpress_site_plan']['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-font-without-svg-plan-identity-fixture' ),
);
$font_without_svg_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_without_svg_result['source_reports']['wordpress_site_plan'],
	array(
		'slug'                 => 'font-site-plan-without-svg',
		'font_materialization' => $font_without_svg_result['source_reports']['materialization_plan']['theme']['font_materialization'],
	)
);
$font_without_svg_root    = $GLOBALS['ssi_plan_root'] . '/font-site-plan-without-svg';
$assert( 'completed' === $font_without_svg_receipt['status'], 'canonical font materialization completes without SVG consumers' );
$assert( str_contains( (string) file_get_contents( $font_without_svg_root . '/assets/css/embedded-fonts.css' ), 'data:font/woff2;base64,' ), 'page fonts are self-contained without SVG consumers' );
$assert( str_contains( (string) file_get_contents( $font_without_svg_root . '/functions.php' ), "wp_enqueue_style( 'static-site-importer-embedded-fonts'" ), 'page fonts load without SVG consumers' );
$assert( 9 === count( $GLOBALS['ssi_plan_font_requests'] ), 'each successful and rejected font materialization resolves only its declared stylesheet or typed payload URLs' );

$nested_route_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			'website/api/index.html'       => '<main><h1>API</h1></main>',
			'website/lifecycle/index.html' => '<main><h1>Lifecycle</h1></main>',
			'website/index.html'           => '<main><h1>Home</h1></main>',
		),
	)
)->toArray();
$nested_routes       = array_column( $nested_route_result['source_reports']['wordpress_site_plan']['routes'], 'target_path', 'source_path' );
$assert( 3 === count( $nested_routes ) && '/' === ( $nested_routes['website/index.html'] ?? '' ) && '/api' === ( $nested_routes['website/api/index.html'] ?? '' ) && '/lifecycle' === ( $nested_routes['website/lifecycle/index.html'] ?? '' ), 'nested index documents retain distinct routes when the declared root entrypoint is ordered last' );

$product_grid_artifact     = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html' => '<main><ul class="products"><li><article class="product-card"><h3>Tour Tee</h3><p>Heavy cotton shirt.</p><div class="price">$30</div><button class="add-to-cart">Add to cart</button></article></li><li><article class="product-card"><h3>Signed CD</h3><p>Hand-signed disc.</p><div class="price">$15</div><button class="add-to-cart">Add to cart</button></article></li></ul></main>',
	),
	'runtime_declarations' => array(
		array( 'kind' => 'dependency', 'capability' => 'shop', 'source_path' => 'index.html', 'required_for' => array( 'entity_collection:products' ) ),
		array(
			'kind'        => 'entity_collection',
			'type'        => 'products',
			'source_path' => 'index.html',
			'payload'     => array(
				'schema'   => 'generic/products/v1',
				'entities' => array(
					array( 'name' => 'Tour Tee', 'slug' => 'tour-tee', 'regular_price' => '30', 'source_path' => 'index.html', 'selector' => 'ul.products li:nth-child(1)' ),
					array( 'name' => 'Signed CD', 'slug' => 'signed-cd', 'regular_price' => '15', 'source_path' => 'index.html', 'selector' => 'ul.products li:nth-child(2)' ),
				),
			),
		),
	),
);
$product_grid_plan         = ( new ArtifactCompiler() )->compile( $product_grid_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$declared_products = $product_grid_plan['runtime_declarations'] ?? array();
$assert( 2 === count( $declared_products ) && 'shop' === ( $declared_products[0]['capability'] ?? '' ) && 'products' === ( $declared_products[1]['type'] ?? '' ), 'canonical plans carry explicit typed product declarations without diagnostic mutation' );
$assert( true === in_array( 'entity_collection:products', $declared_products[0]['required_for'] ?? array(), true ), 'declared product entities retain the required capability relationship' );
$assert( array( 'tour-tee', 'signed-cd' ) === array_column( $declared_products[1]['payload']['entities'] ?? array(), 'slug' ), 'canonical declarations retain product rows unchanged' );
$declared_entities  = $declared_products[1]['payload']['entities'] ?? array();
$declared_selectors = array_column( $declared_entities, 'selector' );
$assert( 2 === count( array_unique( $declared_selectors ) ) && 2 === count( array_filter( $declared_selectors, static fn( $selector ): bool => is_string( $selector ) && '' !== $selector ) ) && 2 === count( array_filter( array_column( $declared_entities, 'source_path' ), static fn( $source ): bool => is_string( $source ) && '' !== $source ) ), 'canonical declarations retain compiler-owned exact classic source identities' );
$declared_lifecycle = ( new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'prepare_wordpress_site_plan_lifecycle' ) )->invoke( null, $product_grid_plan, array() );
$assert( 'runtime_declarations' === ( $declared_lifecycle['status'] ?? '' ) && true === ( reset( $declared_lifecycle['entities'] )['required'] ?? false ), 'declared product entities enter the generic runtime lifecycle' );

$entity_artifact                         = $artifact;
$entity_search                           = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button","text":"Add"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Add</button></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$entity_artifact['files']['index.html']  = '<main><h1>Home</h1><div class="wp-block-buttons"><button>Add</button></div></main>';
$entity_artifact['runtime_declarations'] = array(
	array(
		'kind'         => 'dependency',
		'capability'   => 'shop',
		'source_path'  => 'index.html',
		'required_for' => array( 'entity_collection:products' ),
	),
	array(
		'kind'        => 'entity_collection',
		'type'        => 'products',
		'source_path' => 'index.html',
		'payload'     => array(
			'schema'   => 'generic/products/v1',
			'entities' => array(
				array(
					'name'             => 'Aero Mug',
					'slug'             => 'aero-mug',
					'regular_price'    => '24',
					'source_selectors' => array( '.product-card' ),
					'bindings'         => array(
						array(
							'schema'                       => 'generic/block-binding/v1',
							'source_path'                  => 'index.html',
							'search_block_markup'          => $entity_search,
							'occurrence'                   => 1,
							'role'                         => 'commerce_controls',
							'superseded_runtime_selectors' => array( '.add-to-cart' ),
						),
					),
				),
			),
		),
	),
);
$entity_plan                             = ( new ArtifactCompiler() )->compile( $entity_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$prepare_lifecycle                       = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'prepare_wordpress_site_plan_lifecycle' );
$entity_lifecycle                        = $prepare_lifecycle->invoke( null, $entity_plan, array() );
$assert( 'runtime_declarations' === ( $entity_lifecycle['status'] ?? '' ), 'v2 entity declarations enter the active SSI runtime lifecycle' );
$assert( 'woocommerce_simple_product' === ( $entity_lifecycle['entities'][ $entity_plan['runtime_declarations'][1]['reconciliation_identity'] ]['adapter']['id'] ?? '' ) || 'woocommerce_simple_product' === ( reset( $entity_lifecycle['entities'] )['adapter']['id'] ?? '' ), 'product collections resolve through the configured WooCommerce adapter' );
$prepared_entity = reset( $entity_lifecycle['entities'] );
$assert( 'Aero Mug' === ( $prepared_entity['manifest']['products'][0]['name'] ?? '' ) && true === ( $prepared_entity['required'] ?? false ), 'v2 product rows validate and retain their required dependency relationship' );

// Provider declarations keep portable asset tokens, while resolver output carries
// destination-specific URLs. Binding preflight must use the latter without
// mutating the canonical declaration retained by the lifecycle.
$token_anchor                = '<!-- wp:buttons --><div><img src="{{wordpress-site-plan:asset:hero}}"></div><!-- /wp:buttons -->';
$resolved_anchor             = '<!-- wp:buttons --><div><img src="https://example.test/wp-content/themes/entity-plan/assets/hero.svg"></div><!-- /wp:buttons -->';
$token_entity_declaration_id = (string) array_key_first( $entity_lifecycle['entities'] );
$token_lifecycle             = $entity_lifecycle;
$token_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] = $token_anchor;
$resolved_declaration = $entity_plan['runtime_declarations'][1];
$resolved_declaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] = $resolved_anchor;
$resolved_binding_plan     = array(
	'pages'                => array(
		array(
			'source_path'           => 'index.html',
			'resolved_block_markup' => '<main>' . $resolved_anchor . '</main>',
		),
	),
	'runtime_declarations' => array( $resolved_declaration ),
);
$resolve_binding_manifests = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'with_resolved_runtime_binding_manifests' );
$preflight_bindings        = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'preflight_runtime_entity_binding_anchors' );
$assert( is_wp_error( $preflight_bindings->invoke( null, $resolved_binding_plan, $token_lifecycle, array() ) ), 'canonical token anchors fail against destination-specific resolved page URLs before projection' );
$resolved_lifecycle = $resolve_binding_manifests->invoke( null, $token_lifecycle, $resolved_binding_plan );
$assert( $token_anchor === ( $token_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] ?? '' ) && $resolved_anchor === ( $resolved_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] ?? '' ), 'resolved binding projection changes only lifecycle binding anchors and preserves canonical declarations' );
$assert( true === $preflight_bindings->invoke( null, $resolved_binding_plan, $resolved_lifecycle, array() ), 'resolved provider binding anchors match the exact page markup consumed by materialization' );
$assert( $token_lifecycle === $resolve_binding_manifests->invoke( null, $token_lifecycle, array( 'pages' => $resolved_binding_plan['pages'] ) ), 'plans without resolved runtime declarations retain canonical lifecycle behavior' );

$form_declaration_id                       = 'form-topology-runtime';
$topology_form                             = array(
	'selector'         => 'form.contact',
	'source_path'      => 'index.html',
	'form'             => array( 'class' => 'contact' ),
	'controls'         => array(
		array(
			'tag'   => 'input',
			'type'  => 'text',
			'name'  => 'name',
			'label' => 'Name',
		),
		array(
			'tag'   => 'textarea',
			'type'  => 'textarea',
			'name'  => 'message',
			'label' => 'Message',
		),
		array(
			'tag'   => 'button',
			'type'  => 'submit',
			'label' => 'Send',
		),
	),
	'control_topology' => array(
		'schema'    => 'generic/form-control-topology/v1',
		'max_depth' => 8,
		'max_nodes' => 128,
		'truncated' => false,
		'nodes'     => array(
			array(
				'id'        => 'wrapper-0',
				'kind'      => 'wrapper',
				'parent'    => null,
				'order'     => 0,
				'depth'     => 0,
				'tag'       => 'section',
				'class'     => 'row-2',
				'source_id' => 'contact-row',
			),
			array(
				'id'      => 'control-0',
				'kind'    => 'control',
				'parent'  => 'wrapper-0',
				'order'   => 0,
				'depth'   => 1,
				'control' => 0,
			),
			array(
				'id'      => 'control-1',
				'kind'    => 'control',
				'parent'  => null,
				'order'   => 1,
				'depth'   => 0,
				'control' => 1,
			),
			array(
				'id'      => 'control-2',
				'kind'    => 'control',
				'parent'  => null,
				'order'   => 2,
				'depth'   => 0,
				'control' => 2,
			),
		),
	),
	'bindings'         => array(
		array(
			'schema'                       => 'generic/block-binding/v1',
			'source_path'                  => 'index.html',
			'search_block_markup'          => '<!-- wp:html --><form class="contact"><h2>Contact Me</h2><label class="required-note">* Indicates required field</label><input name="name"><textarea name="message" style="height:200px"></textarea><button type="submit" class="wsite-button">Send</button></form><!-- /wp:html -->',
			'occurrence'                   => 1,
			'role'                         => 'form',
		),
	),
);
$runtime_form_plan                         = $entity_plan;
$runtime_form_plan['runtime_declarations'] = array(
	array(
		'kind'                    => 'dependency',
		'capability'              => 'form',
		'source_path'             => 'index.html',
		'required_for'            => array( 'entity_collection:forms' ),
		'reconciliation_identity' => 'form-topology-dependency',
	),
	array(
		'kind'                    => 'entity_collection',
		'type'                    => 'forms',
		'source_path'             => 'index.html',
		'reconciliation_identity' => $form_declaration_id,
		'payload'                 => array(
			'schema'   => 'generic/forms/v1',
			'entities' => array( $topology_form ),
		),
	),
);
$runtime_form_lifecycle                    = $prepare_lifecycle->invoke( null, $runtime_form_plan, array() );
$assert( ! is_wp_error( $runtime_form_lifecycle ), 'canonical form binding presentation passes runtime declaration validation' . ( is_wp_error( $runtime_form_lifecycle ) ? ': ' . $runtime_form_lifecycle->get_error_code() . ' ' . wp_json_encode( $runtime_form_lifecycle->get_error_data() ) : '' ) );
$runtime_form_manifest                     = is_wp_error( $runtime_form_lifecycle ) ? array() : ( $runtime_form_lifecycle['entities'][ $form_declaration_id ]['manifest']['forms'][0] ?? array() );
$assert( 'section' === ( $runtime_form_manifest['control_topology']['nodes'][0]['tag'] ?? '' ), 'runtime declarations retain validated form topology' );
$assert( 'Contact Me' === ( $runtime_form_manifest['form']['context_before'][0]['text'] ?? '' ) && '* Indicates required field' === ( $runtime_form_manifest['form']['context_before'][1]['text'] ?? '' ), 'canonical form bindings preserve ordered heading and required-note context before provider validation' );
$assert( '200px' === ( $runtime_form_manifest['controls'][1]['height'] ?? '' ) && 'Send' === ( $runtime_form_manifest['form']['submit_presentation']['text'] ?? '' ) && in_array( 'wsite-button', $runtime_form_manifest['form']['submit_presentation']['classes'] ?? array(), true ), 'canonical form bindings preserve textarea sizing and visible submit presentation before provider validation' );
$presentation_conflicts = array(
	str_replace( 'Contact Me', 'Write Me', $topology_form['bindings'][0]['search_block_markup'] ),
	str_replace( '</form>', '<p class="help-note">After</p></form>', $topology_form['bindings'][0]['search_block_markup'] ),
	str_replace( '<textarea', '<p class="help-note">Between</p><textarea', $topology_form['bindings'][0]['search_block_markup'] ),
	str_replace( 'class="wsite-button"', 'class="alternate-button"', $topology_form['bindings'][0]['search_block_markup'] ),
	str_replace( 'height:200px', 'height:300px', $topology_form['bindings'][0]['search_block_markup'] ),
);
foreach ( $presentation_conflicts as $conflicting_markup ) {
	$conflicting_presentation_form = $topology_form;
	$conflicting_presentation_form['bindings'][] = array_replace( $topology_form['bindings'][0], array( 'search_block_markup' => $conflicting_markup ) );
	$assert( ! isset( Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $conflicting_presentation_form )['form']['context_before'] ), 'conflicting bounded presentation across canonical form bindings fails closed' );
}
$many_textarea_controls = array();
$many_textareas_full    = '<form>';
$many_textareas_partial = '<form>';
for ( $index = 0; $index < 17; ++$index ) {
	$many_textarea_controls[] = array( 'tag' => 'textarea', 'type' => 'textarea', 'name' => 'message-' . $index );
	$many_textareas_full     .= '<textarea name="message-' . $index . '" style="height:200px"></textarea>';
	$many_textareas_partial  .= '<textarea name="message-' . $index . '"' . ( 16 === $index ? '' : ' style="height:200px"' ) . '></textarea>';
}
$many_textarea_controls[] = array( 'tag' => 'button', 'type' => 'submit' );
$many_textareas_full     .= '<button type="submit">Send</button></form>';
$many_textareas_partial  .= '<button type="submit">Send</button></form>';
$many_textarea_form       = array(
	'controls' => $many_textarea_controls,
	'bindings' => array(
		array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $many_textareas_full, 'occurrence' => 1, 'role' => 'form' ),
		array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $many_textareas_partial, 'occurrence' => 1, 'role' => 'form' ),
	),
);
$assert( ! isset( Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $many_textarea_form )['controls'][0]['height'] ), 'conflicting bounded textarea-height omission counts fail closed' );
$reordered_presentation_form             = $topology_form;
$reordered_presentation_form['controls'] = array_reverse( $reordered_presentation_form['controls'] );
$assert( ! isset( Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $reordered_presentation_form )['form']['context_before'] ), 'binding presentation fails closed when declaration control order does not match the exact anchor' );
$invalid_presentation_form                            = $topology_form;
$invalid_presentation_form['bindings'][0]['schema']   = 'generic/block-binding/invalid';
$assert( ! isset( Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $invalid_presentation_form )['form']['context_before'] ), 'non-canonical form bindings are ignored before presentation extraction' );
$scalar_bindings_form             = $topology_form;
$scalar_bindings_form['bindings'] = 'invalid';
$assert( $scalar_bindings_form === Static_Site_Importer_Report_Diagnostics::apply_form_binding_presentation( $scalar_bindings_form ), 'malformed binding collections remain unchanged for structured provider validation' );
$malformed_entity_plan = $runtime_form_plan;
$malformed_entity_plan['runtime_declarations'][1]['payload']['entities'] = array( 'invalid' );
$malformed_entity_lifecycle = $prepare_lifecycle->invoke( null, $malformed_entity_plan, array() );
$assert( is_wp_error( $malformed_entity_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $malformed_entity_lifecycle->get_error_code(), 'malformed form entities retain structured pre-provider rejection' );

$unknown_topology_form                                  = $topology_form;
$unknown_topology_form['control_topology']['untrusted'] = 'reject-me';
$unknown_topology_plan                                  = $runtime_form_plan;
$unknown_topology_plan['runtime_declarations'][1]['payload']['entities'] = array( $unknown_topology_form );
$unknown_topology_lifecycle = $prepare_lifecycle->invoke( null, $unknown_topology_plan, array() );
$assert( is_wp_error( $unknown_topology_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $unknown_topology_lifecycle->get_error_code(), 'unknown runtime topology keys are rejected before provider traversal' );

$self_referential_form = $topology_form;
$self_referential_form['control_topology']['nodes'][0]['parent'] = 'wrapper-0';
$self_referential_plan = $runtime_form_plan;
$self_referential_plan['runtime_declarations'][1]['payload']['entities'] = array( $self_referential_form );
$self_referential_lifecycle = $prepare_lifecycle->invoke( null, $self_referential_plan, array() );
$assert( is_wp_error( $self_referential_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $self_referential_lifecycle->get_error_code(), 'self-referential runtime topology is rejected before provider traversal' );

$duplicate_topology_form                                       = $topology_form;
$duplicate_topology_form['control_topology']['nodes'][1]['id'] = 'wrapper-0';
$duplicate_topology_form['control_topology']['nodes'][1]['kind'] = 'wrapper';
unset( $duplicate_topology_form['control_topology']['nodes'][1]['control'] );
$duplicate_topology_plan = $runtime_form_plan;
$duplicate_topology_plan['runtime_declarations'][1]['payload']['entities'] = array( $duplicate_topology_form );
$duplicate_topology_lifecycle = $prepare_lifecycle->invoke( null, $duplicate_topology_plan, array() );
$assert( is_wp_error( $duplicate_topology_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $duplicate_topology_lifecycle->get_error_code(), 'duplicate runtime topology identifiers are rejected before provider traversal' );

$binding_method        = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'runtime_entity_bindings' );
$entity_declaration_id = (string) array_key_first( $entity_lifecycle['entities'] );
$entity_bindings       = $binding_method->invoke(
	null,
	$entity_lifecycle,
	array(
		$entity_declaration_id => array(
			'products' => array(
				array(
					'id'     => 42,
					'slug'   => 'aero-mug',
					'status' => 'created',
				),
			),
		),
	)
);
$assert( ! is_wp_error( $entity_bindings ) && '[add_to_cart id="42"]' === trim( strip_tags( $entity_bindings[0]['replacement_block_markup'] ?? '' ) ), 'provider result resolves into a canonical runtime entity binding' );
$assert( array( '.add-to-cart' ) === ( $entity_bindings[0]['superseded_runtime_selectors'] ?? null ), 'provider binding retains its explicit runtime-selector coverage' );
$waived_bindings = $binding_method->invoke(
	null,
	$entity_lifecycle,
	array(
		$entity_declaration_id => array(
			'status'   => 'waived',
			'provider' => 'woocommerce',
		),
	)
);
$assert( array() === $waived_bindings, 'explicit provider waiver retains static fallback without requiring provider markup' );

$binding_artifact    = array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => '<main><h1>Binding</h1><p>Replace me</p></main>' ),
);
$binding_plan        = ( new ArtifactCompiler() )->compile( $binding_artifact )->toArray()['source_reports']['wordpress_site_plan'];
foreach ( $binding_plan['pages'] as $page ) {
	$register_document_blocks( parse_blocks( (string) ( $page['canonical_block_markup'] ?? '' ) ) );
}
WP_Block_Type_Registry::get_instance()->register( 'core/shortcode', array() );
$binding_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-binding-plan-identity-fixture' ),
);
$binding_search      = '<!-- wp:paragraph {"content":"Replace me"} --><p>Replace me</p><!-- /wp:paragraph -->';
$binding_replacement = '<!-- wp:shortcode -->[add_to_cart id="42"]<!-- /wp:shortcode -->';
$binding_receipt     = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'binding-plan',
		'runtime_entity_bindings' => array(
			array(
				'schema'                       => 'static-site-importer/runtime-entity-binding/v1',
				'source_path'                  => 'index.html',
				'search_block_markup'          => $binding_search,
				'replacement_block_markup'     => $binding_replacement,
				'occurrence'                   => 1,
				'role'                         => 'commerce_controls',
				'declaration_id'               => $entity_declaration_id,
				'reconciliation_identity'      => hash( 'sha256', 'binding-test' ),
				'superseded_runtime_selectors' => array( '.add-to-cart' ),
			),
		),
	)
);
$assert( str_contains( $binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ?? '', '[add_to_cart id="42"]' ) && ! isset( $binding_receipt['plan']['pages'][0]['materialized_block_markup'] ), 'runtime binding is receipt-owned without mutating the canonical resolved plan' );
$assert( hash( 'sha256', $binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ) === ( $binding_receipt['completed']['materialized_pages']['index.html']['content_hash'] ?? '' ), 'materialized page receipt owns the final provider-bound content hash' );
$binding_provenance = $binding_receipt['completed']['block_provenance'][0] ?? array();
$assert( 'static-site-importer/page-provenance/v1' === ( $binding_provenance['source']['schema'] ?? '' ) && 'index.html' === ( $binding_provenance['source']['source_path'] ?? '' ) && ! isset( $binding_provenance['source']['compiler_node_id'] ), 'receipt uses existing sanitized page provenance without fabricating compiler identities' );
$assert( 'blocks-engine/wordpress-site-plan-resolver' === ( $binding_provenance['stages'][0]['stage'] ?? '' ) && hash( 'sha256', $binding_receipt['plan']['pages'][0]['resolved_block_markup'] ) === ( $binding_provenance['stages'][0]['output']['sha256'] ?? '' ), 'receipt records the resolver output before runtime binding' );
$assert( 'static-site-importer/runtime-entity-bindings' === ( $binding_provenance['stages'][1]['stage'] ?? '' ) && ( $binding_provenance['stages'][0]['output']['sha256'] ?? '' ) === ( $binding_provenance['stages'][1]['input_sha256'] ?? '' ) && hash( 'sha256', $binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ) === ( $binding_provenance['stages'][1]['output']['sha256'] ?? '' ), 'receipt distinguishes the runtime-binding output from resolver output' );
$assert( ! str_contains( (string) wp_json_encode( $binding_provenance ), '[add_to_cart id="42"]' ), 'runtime-bound provenance does not leak provider markup' );
$binding_post_id = (int) ( $binding_receipt['completed']['pages']['index.html'] ?? 0 );
$assert( str_contains( $GLOBALS['ssi_plan_posts'][ $binding_post_id ]['post_content'] ?? '', '[add_to_cart id=\"42\"]' ), 'page write uses provider-bound markup rather than the static fallback' );
$assert( 'completed' === ( reset( $binding_receipt['completed']['runtime_declarations']['entity_bindings'] )['status'] ?? '' ), 'receipt proves canonical runtime entity binding completion' );
$assert( array( '.add-to-cart' ) === ( reset( $binding_receipt['completed']['runtime_declarations']['entity_bindings'] )['superseded_runtime_selectors'] ?? null ), 'completed receipt retains provider runtime-selector coverage' );
$reconcile_diagnostics = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'diagnostics_after_completed_entity_bindings' );
$runtime_diagnostics   = array(
	array(
		'code'        => 'preserved_runtime_island',
		'source_path' => 'index.html',
		'selector'    => '.add-to-cart',
	),
	array(
		'code'        => 'preserved_runtime_island',
		'source_path' => 'index.html',
		'selector'    => '.qty-btn',
	),
	array(
		'code'        => 'preserved_runtime_island',
		'source_path' => 'other.html',
		'selector'    => '.add-to-cart',
	),
);
$assert( array( '.qty-btn', '.add-to-cart' ) === array_column( $reconcile_diagnostics->invoke( null, $runtime_diagnostics, $binding_receipt ), 'selector' ), 'completed provider coverage removes only the matching page runtime finding and preserves same-selector findings on other pages' );
$prepared_binding_receipt = $binding_receipt;
foreach ( $prepared_binding_receipt['completed']['runtime_declarations']['entity_bindings'] as &$prepared_binding_report ) {
	$prepared_binding_report['status'] = 'prepared';
}
unset( $prepared_binding_report );
$assert( 3 === count( $reconcile_diagnostics->invoke( null, $runtime_diagnostics, $prepared_binding_receipt ) ), 'unpersisted provider bindings never suppress runtime findings' );
$invalid_coverage_binding = array(
	'schema'                       => 'static-site-importer/runtime-entity-binding/v1',
	'source_path'                  => 'index.html',
	'search_block_markup'          => $binding_search,
	'replacement_block_markup'     => $binding_replacement,
	'occurrence'                   => 1,
	'role'                         => 'commerce_controls',
	'declaration_id'               => $entity_declaration_id,
	'reconciliation_identity'      => hash( 'sha256', 'invalid-coverage-binding' ),
	'superseded_runtime_selectors' => '.add-to-cart',
);
$invalid_coverage_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'invalid-coverage-plan',
		'runtime_entity_bindings' => array( $invalid_coverage_binding ),
	)
);
$assert( 'rejected' === $invalid_coverage_receipt['status'] && 'runtime_entity_binding_invalid' === ( $invalid_coverage_receipt['errors'][0]['code'] ?? '' ), 'direct materializer callers cannot forge malformed runtime-selector coverage' );
$duplicate_binding_plan    = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array( 'index.html' => '<main><p>Same</p><p>Same</p></main>' ),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$duplicate_binding_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-duplicate-binding-plan-identity-fixture' ),
);
$duplicate_search          = '<!-- wp:paragraph {"content":"Same"} --><p>Same</p><!-- /wp:paragraph -->';
$duplicate_binding_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$duplicate_binding_plan,
	array(
		'slug'                    => 'duplicate-binding-plan',
		'runtime_entity_bindings' => array(
			array(
				'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
				'source_path'              => 'index.html',
				'search_block_markup'      => $duplicate_search,
				'replacement_block_markup' => '<!-- wp:shortcode -->[add_to_cart id="42"]<!-- /wp:shortcode -->',
				'occurrence'               => 1,
				'role'                     => 'commerce_controls',
				'declaration_id'           => 'products',
				'reconciliation_identity'  => hash( 'sha256', 'duplicate-binding-1' ),
			),
			array(
				'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
				'source_path'              => 'index.html',
				'search_block_markup'      => $duplicate_search,
				'replacement_block_markup' => '<!-- wp:shortcode -->[add_to_cart id="43"]<!-- /wp:shortcode -->',
				'occurrence'               => 2,
				'role'                     => 'commerce_controls',
				'declaration_id'           => 'products',
				'reconciliation_identity'  => hash( 'sha256', 'duplicate-binding-2' ),
			),
		),
	)
);
$duplicate_markup          = $duplicate_binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ?? '';
$assert( str_contains( $duplicate_markup, '[add_to_cart id="42"]' ) && str_contains( $duplicate_markup, '[add_to_cart id="43"]' ), 'duplicate markup anchors resolve by descending deterministic occurrence' );
$duplicate_blocks = parse_blocks( $duplicate_markup );
$assert( 'core/group' === ( $duplicate_blocks[0]['blockName'] ?? '' ) && array( 'core/shortcode', 'core/shortcode' ) === array_column( $duplicate_blocks[0]['innerBlocks'] ?? array(), 'blockName' ), 'multiple nested replacements preserve the surrounding parsed block topology' );
$malformed_fragment_binding                            = $invalid_coverage_binding;
$malformed_fragment_binding['reconciliation_identity'] = hash( 'sha256', 'malformed-fragment-binding' );
$malformed_fragment_binding['replacement_block_markup'] = '<div>Provider control without a block wrapper</div>';
$malformed_fragment_binding['superseded_runtime_selectors'] = array( '.add-to-cart' );
$inserts_before_malformed_fragment                     = $GLOBALS['ssi_plan_insert_calls'];
$malformed_fragment_receipt                            = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'malformed-fragment-binding-plan',
		'runtime_entity_bindings' => array( $malformed_fragment_binding ),
	)
);
$malformed_fragment_diagnostic = $malformed_fragment_receipt['diagnostics'][0] ?? array();
$assert( 'rejected' === $malformed_fragment_receipt['status'] && 'runtime_entity_binding_replacement_invalid' === ( $malformed_fragment_receipt['errors'][0]['code'] ?? '' ) && $inserts_before_malformed_fragment === $GLOBALS['ssi_plan_insert_calls'] && 'index.html' === ( $malformed_fragment_diagnostic['source_path'] ?? '' ) && $malformed_fragment_binding['reconciliation_identity'] === ( $malformed_fragment_diagnostic['reconciliation_identity'] ?? '' ), 'malformed replacement fragments are rejected with binding attribution before page mutation' );
$topology_breaking_binding                            = $invalid_coverage_binding;
$topology_breaking_binding['reconciliation_identity'] = hash( 'sha256', 'topology-breaking-binding' );
$topology_breaking_binding['search_block_markup']     = '<!-- wp:paragraph {"content":"Replace me"} -->';
$topology_breaking_binding['replacement_block_markup'] = '<!-- wp:shortcode /-->';
$topology_breaking_binding['superseded_runtime_selectors'] = array( '.add-to-cart' );
$inserts_before_topology_break                         = $GLOBALS['ssi_plan_insert_calls'];
$topology_breaking_receipt                             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'topology-breaking-binding-plan',
		'runtime_entity_bindings' => array( $topology_breaking_binding ),
	)
);
$topology_breaking_diagnostic = $topology_breaking_receipt['diagnostics'][0] ?? array();
$assert( 'rejected' === $topology_breaking_receipt['status'] && 'runtime_entity_bound_block_document_invalid' === ( $topology_breaking_receipt['errors'][0]['code'] ?? '' ) && $inserts_before_topology_break === $GLOBALS['ssi_plan_insert_calls'] && 'index.html' === ( $topology_breaking_diagnostic['source_path'] ?? '' ) && array( $topology_breaking_binding['reconciliation_identity'] ) === ( $topology_breaking_diagnostic['binding_reconciliation_identities'] ?? array() ), 'final complete documents are rejected when a valid fragment breaks surrounding topology' );
$provider_block_name = 'static-site-importer/runtime-provider-test';
WP_Block_Type_Registry::get_instance()->register( $provider_block_name, array() );
$provider_binding                            = $invalid_coverage_binding;
$provider_binding['reconciliation_identity'] = hash( 'sha256', 'registered-provider-binding' );
$provider_binding['replacement_block_markup'] = '<!-- wp:static-site-importer/runtime-provider-test --><div>Provider control</div><!-- /wp:static-site-importer/runtime-provider-test -->';
$provider_binding['superseded_runtime_selectors'] = array( '.add-to-cart' );
$provider_binding_receipt                    = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'registered-provider-binding-plan',
		'runtime_entity_bindings' => array( $provider_binding ),
	)
);
$provider_markup = $provider_binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ?? '';
$contains_block = static function ( array $blocks, string $block_name ) use ( &$contains_block ): bool {
	foreach ( $blocks as $block ) {
		if ( $block_name === ( $block['blockName'] ?? '' ) || ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $contains_block( $block['innerBlocks'], $block_name ) ) ) {
			return true;
		}
	}
	return false;
};
$assert( null !== WP_Block_Type_Registry::get_instance()->get_registered( $provider_block_name ), 'registered provider block is available to the materialization runtime' );
$assert( 'completed' === $provider_binding_receipt['status'], 'registered provider blocks pass editor admission without an importer allowlist' );
$assert( $contains_block( parse_blocks( $provider_markup ), $provider_block_name ), 'registered provider block survives the persisted document round-trip' );
$unknown_block_binding                            = $provider_binding;
$unknown_block_binding['reconciliation_identity'] = hash( 'sha256', 'unknown-provider-binding' );
$unknown_block_binding['replacement_block_markup'] = '<!-- wp:future-provider/control --><div>Future provider control</div><!-- /wp:future-provider/control -->';
$unknown_block_receipt                            = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'unknown-provider-binding-plan',
		'runtime_entity_bindings' => array( $unknown_block_binding ),
	)
);
$unknown_diagnostic = $unknown_block_receipt['diagnostics'][0] ?? array();
$assert( 'rejected' === $unknown_block_receipt['status'] && 'unsupported_persisted_block' === ( $unknown_block_receipt['errors'][0]['code'] ?? '' ) && 'future-provider/control' === ( $unknown_diagnostic['block_name'] ?? '' ) && 'unsupported' === ( $unknown_diagnostic['block_classification'] ?? '' ), 'undeclared unknown blocks fail editor admission with bounded diagnostics' );
WP_Block_Type_Registry::get_instance()->register( 'example/companion-control', array() );
$GLOBALS['static_site_importer_companion_block_owners']['example/companion-control'] = array( 'plugin_file' => 'ssi-example/ssi-example.php' );
$companion_binding = $provider_binding;
$companion_binding['reconciliation_identity'] = hash( 'sha256', 'declared-companion-binding' );
$companion_binding['replacement_block_markup'] = '<!-- wp:example/companion-control --><div>Companion control</div><!-- /wp:example/companion-control -->';
$companion_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $binding_plan, array( 'slug' => 'declared-companion-binding-plan', 'runtime_entity_bindings' => array( $companion_binding ) ) );
$assert( 'completed' === $companion_receipt['status'], 'declared companion blocks pass after registration' );
WP_Block_Type_Registry::get_instance()->register( 'example/restricted-parent', array( 'allowed_blocks' => array( 'core/paragraph' ) ) );
WP_Block_Type_Registry::get_instance()->register( 'example/restricted-child', array( 'parent' => array( 'example/other-parent' ) ) );
$hierarchy_binding = $provider_binding;
$hierarchy_binding['reconciliation_identity'] = hash( 'sha256', 'hierarchy-diagnostic-binding' );
$hierarchy_binding['replacement_block_markup'] = '<!-- wp:example/restricted-parent --><!-- wp:example/restricted-child --><div>Restricted child</div><!-- /wp:example/restricted-child --><!-- /wp:example/restricted-parent -->';
$hierarchy_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $binding_plan, array( 'slug' => 'hierarchy-diagnostic-binding-plan', 'runtime_entity_bindings' => array( $hierarchy_binding ) ) );
$hierarchy_reasons = array_column( $hierarchy_receipt['diagnostics'] ?? array(), 'reason_code' );
$assert( 'completed' === $hierarchy_receipt['status'] && array() === ( $hierarchy_receipt['errors'] ?? null ) && in_array( 'block_child_not_allowed', $hierarchy_reasons, true ) && in_array( 'block_parent_requirement_not_met', $hierarchy_reasons, true ), 'registered blocks retain parent and allowedBlocks editor-quality diagnostics without reporting transaction errors' );
$hierarchy_failure_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                           => 'hierarchy-diagnostic-failure-plan',
		'runtime_entity_bindings'        => array( $hierarchy_binding ),
		'inject_materialization_failure' => 'theme_write_short',
	)
);
$hierarchy_failure_reasons = array_column( $hierarchy_failure_receipt['diagnostics'] ?? array(), 'reason_code' );
$assert( 'partial' === $hierarchy_failure_receipt['status'] && 'theme_write_failed' === ( $hierarchy_failure_receipt['errors'][0]['code'] ?? '' ) && in_array( 'block_child_not_allowed', $hierarchy_failure_reasons, true ) && in_array( 'block_parent_requirement_not_met', $hierarchy_failure_reasons, true ), 'failed receipts retain nonfatal editor diagnostics while exposing the transaction-breaking cause first' );
$invalid_binding         = array(
	'schema'                   => 'static-site-importer/runtime-entity-binding/v1',
	'source_path'              => 'index.html',
	'search_block_markup'      => '<!-- wp:paragraph --><p>Missing</p><!-- /wp:paragraph -->',
	'replacement_block_markup' => $binding_replacement,
	'occurrence'               => 1,
	'role'                     => 'commerce_controls',
	'declaration_id'           => $entity_declaration_id,
	'reconciliation_identity'  => hash( 'sha256', 'invalid-binding-test' ),
);
$invalid_binding_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'invalid-binding-plan',
		'runtime_entity_bindings' => array( $invalid_binding ),
	)
);
$assert( 'rejected' === $invalid_binding_receipt['status'] && 'runtime_entity_binding_cardinality_mismatch' === ( $invalid_binding_receipt['errors'][0]['code'] ?? '' ), 'missing or ambiguous provider anchors fail before page writes' );

// A form fallback resolves only from the persisted runtime-binding receipt. The
// quality report never trusts a provider result before the replacement page write.
$form_fallback                                    = array(
	'type'            => 'unsupported_html_fallback',
	'diagnostic_code' => 'html_form_fallback',
	'source_path'     => 'index.html',
	'selector'        => 'form.newsletter',
	'form'            => array( 'class' => 'newsletter' ),
	'controls'        => array(
		array(
			'tag'  => 'input',
			'type' => 'email',
			'name' => 'email',
		),
	),
);
$form_fallback_identity                           = Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_identity( $form_fallback );
$form_fallback_hash                               = Static_Site_Importer_Report_Diagnostics::fallback_reconciliation_hash( $form_fallback );
WP_Block_Type_Registry::get_instance()->register( 'jetpack/contact-form', array() );
$form_binding                                     = array(
	'schema'                           => 'static-site-importer/runtime-entity-binding/v1',
	'source_path'                      => 'index.html',
	'search_block_markup'              => $binding_search,
	'replacement_block_markup'         => '<!-- wp:jetpack/contact-form -->newsletter<!-- /wp:jetpack/contact-form -->',
	'occurrence'                       => 1,
	'role'                             => 'form',
	'declaration_id'                   => 'forms',
	'reconciliation_identity'          => hash( 'sha256', 'form-fallback-binding' ),
	'fallback_reconciliation_identity' => $form_fallback_identity,
	'fallback_hash'                    => $form_fallback_hash,
	'materialized_block_hash'          => hash( 'sha256', '<!-- wp:jetpack/contact-form -->newsletter<!-- /wp:jetpack/contact-form -->' ),
	'provider'                         => 'jetpack',
);
$form_binding_receipt                             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'form-fallback-binding-plan',
		'runtime_entity_bindings' => array( $form_binding ),
	)
);
$form_binding_report                              = reset( $form_binding_receipt['completed']['runtime_declarations']['entity_bindings'] );
$form_quality_report                              = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$form_quality_report['quality']['fallback_count'] = 1;
$form_quality_report['diagnostics']               = array( $form_fallback );
$form_quality_report['materialization_receipt']   = $form_binding_receipt;
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $form_quality_report );
$assert( 'completed' === ( $form_binding_report['status'] ?? '' ) && ( $form_binding_report['materialized_content_hash'] ?? '' ) === hash( 'sha256', $form_binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ?? '' ), 'form quality receipt is emitted after the persisted page replacement' );
$assert( 0 === ( $form_quality_report['quality']['fallback_count'] ?? -1 ) && 1 === ( $form_quality_report['quality']['source_fallback_count'] ?? 0 ) && 'resolved_by_provider' === ( $form_quality_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ), 'persisted form receipt resolves only its identity-and-hash-bound source fallback' );
$resolved_form_quality    = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $form_quality_report, array( 'fail_on_quality' => true ) );
$resolved_form_validation = Static_Site_Importer_Report_Diagnostics::import_validation_result( $form_quality_report, $resolved_form_quality );
$assert( true === ( $resolved_form_quality['pass'] ?? false ) && false === ( $resolved_form_quality['fail_import'] ?? true ) && array() === ( $resolved_form_quality['failure_reasons'] ?? null ) && 'passed' === ( $resolved_form_validation['status'] ?? '' ), 'receipt-resolved form fallback clears derived quality gates and validation status' );
$form_admission = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'can_defer_form_quality_admission' );
$form_admission_plan = array(
	'quality' => array( 'metrics' => array( 'fallback_count' => 1 ), 'failure_reasons' => array( 'unsupported_html_fallback' ) ),
	'diagnostics' => array( $form_fallback ),
);
$form_admission_lifecycle = array(
	'entities' => array(
		'forms' => array(
			'adapter' => array( 'capability' => 'form' ),
			'declaration' => array( 'payload' => array( 'schema' => 'generic/forms/v1' ) ),
			'manifest' => array( 'forms' => array( array( 'source_path' => 'index.html', 'selector' => 'form.newsletter', 'bindings' => array( $form_binding ) ) ) ),
		),
	),
);
$assert( true === $form_admission->invoke( null, $form_admission_plan, $form_admission_lifecycle ), 'typed provider-materializable form fallback defers only until receipt reconciliation' );
$missing_binding_lifecycle = $form_admission_lifecycle;
$missing_binding_lifecycle['entities']['forms']['manifest']['forms'][0]['bindings'] = array();
$assert( false === $form_admission->invoke( null, $form_admission_plan, $missing_binding_lifecycle ), 'unbound provider form fallback remains rejected before materialization' );
$unrelated_failure_plan = $form_admission_plan;
$unrelated_failure_plan['quality']['failure_reasons'][] = 'core_html_block';
$assert( false === $form_admission->invoke( null, $unrelated_failure_plan, $form_admission_lifecycle ), 'unrelated quality failures remain rejected before materialization' );
$other_failure_report                                     = $form_quality_report;
$other_failure_report['quality']['core_html_block_count'] = 1;
$other_failure_quality                                    = Static_Site_Importer_Report_Diagnostics::finalize_quality_report( $other_failure_report, array( 'fail_on_quality' => true ) );
$other_failure_validation                                 = Static_Site_Importer_Report_Diagnostics::import_validation_result( $other_failure_report, $other_failure_quality );
$assert( 0 === ( $other_failure_quality['fallback_count'] ?? -1 ) && false === ( $other_failure_quality['pass'] ?? true ) && true === ( $other_failure_quality['fail_import'] ?? false ) && array( 'core_html_block' ) === ( $other_failure_quality['failure_reasons'] ?? null ) && 'failed' === ( $other_failure_validation['status'] ?? '' ), 'receipt reconciliation preserves unrelated quality failures and validation status' );
// Exercise the production result composition path with the partial compiler
// quality envelope that website-artifact imports supply.
$partial_quality_plan                = $plan;
$partial_quality_plan['quality']     = array(
	'metrics' => array(
		'block_count'    => 1,
		'fallback_count' => 1,
	),
);
$partial_quality_plan['diagnostics'] = array(
	array(
		'type'     => 'unsupported_html_fallback',
		'severity' => 'warning',
	),
);
$partial_quality_receipt             = $receipt;
$partial_quality_receipt['plan']     = $partial_quality_plan;
$compose_partial_quality_result      = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'public_result_from_wordpress_site_plan_receipt' );
$partial_quality_warning_handler     = set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): never {
		throw new RuntimeException( sprintf( 'PHP warning/notice [%d] %s at %s:%d', $severity, $message, $file, $line ) );
	}
);
try {
	$partial_quality_result = $compose_partial_quality_result->invoke( null, $partial_quality_receipt, array( 'fail_on_quality' => true ) );
} finally {
	restore_error_handler();
}
$partial_quality          = $partial_quality_result['quality'] ?? array();
$partial_quality_counters = array(
	'fallback_count'                        => 1,
	'content_loss_count'                    => 0,
	'empty_conversion_count'                => 0,
	'core_html_block_count'                 => 0,
	'freeform_block_count'                  => 0,
	'invalid_block_count'                   => 0,
	'invalid_block_document_count'          => 0,
	'unsafe_svg_count'                      => 0,
	'svg_materialization_failure_count'     => 0,
	'svg_sprite_reference_failure_count'    => 0,
	'commerce_dependency_failures'          => 0,
	'companion_plugin_dependency_failures'  => 0,
	'interaction_candidate_count'           => 0,
	'runtime_dependency_parity_issue_count' => 0,
	'semantic_parity_failure_count'         => 0,
	'source_fallback_count'                 => 1,
);
$assert(
	$partial_quality_counters === array_intersect_key( $partial_quality, $partial_quality_counters ) && 1 === ( $partial_quality['block_count'] ?? 0 ) && array(
		'block_count'    => 1,
		'fallback_count' => 1,
	) === ( $partial_quality['metrics'] ?? null ),
	'partial website-artifact result composition preserves supplied metrics and normalizes the complete quality counter schema'
);
$assert( false === ( $partial_quality['pass'] ?? true ) && true === ( $partial_quality['fail_import'] ?? false ) && in_array( 'unsupported_html_fallback', $partial_quality['failure_reasons'] ?? array(), true ), 'partial website-artifact reports retain unresolved compiler fallbacks as strict quality failures' );
$tampered_fragment_receipt = $form_binding_receipt;
$tampered_fragment_receipt['completed']['runtime_declarations']['entity_bindings'][ hash( 'sha256', 'form-fallback-binding' ) ]['persisted_fragment_hash'] = hash( 'sha256', 'tampered fragment' );
$tampered_fragment_report                              = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$tampered_fragment_report['quality']['fallback_count'] = 1;
$tampered_fragment_report['diagnostics']               = array( $form_fallback );
$tampered_fragment_report['materialization_receipt']   = $tampered_fragment_receipt;
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $tampered_fragment_report );
$assert( 1 === ( $tampered_fragment_report['quality']['fallback_count'] ?? 0 ) && 'unresolved' === ( $tampered_fragment_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ), 'tampered persisted fragment digest cannot resolve a fallback' );
$tampered_content_receipt = $form_binding_receipt;
$tampered_content_receipt['completed']['runtime_declarations']['entity_bindings'][ hash( 'sha256', 'form-fallback-binding' ) ]['materialized_content_hash'] = hash( 'sha256', 'tampered page' );
$tampered_content_report                              = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$tampered_content_report['quality']['fallback_count'] = 1;
$tampered_content_report['diagnostics']               = array( $form_fallback );
$tampered_content_report['materialization_receipt']   = $tampered_content_receipt;
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $tampered_content_report );
$assert( 1 === ( $tampered_content_report['quality']['fallback_count'] ?? 0 ) && 'unresolved' === ( $tampered_content_report['quality_resolutions']['resolutions'][0]['state'] ?? '' ), 'tampered persisted page digest cannot resolve a fallback' );
$deferred_form_plan                = $binding_plan;
$deferred_form_plan['quality']     = array(
	'pass'            => false,
	'metrics'         => array( 'fallback_count' => 1 ),
	'failure_reasons' => array( 'unsupported_html_fallback' ),
);
$deferred_form_plan['diagnostics'] = array( $form_fallback );
$deferred_form_receipt             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$deferred_form_plan,
	array(
		'slug'                       => 'deferred-form-quality-plan',
		'runtime_entity_bindings'    => array( $form_binding ),
		'defer_materialization_commit' => true,
	)
);
$deferred_form_receipt['completed']['runtime_declarations']['entity_bindings'][ hash( 'sha256', 'form-fallback-binding' ) ]['persisted_fragment_hash'] = hash( 'sha256', 'tampered deferred fragment' );
$provider_materializations = 0;
$provider_rollbacks        = 0;
$deferred_form_lifecycle   = array(
	'entities' => array(
		'forms' => array(
			'adapter' => array(
				'capability'        => 'form',
				'provider'          => 'lifecycle-test',
				'materializer'      => static function ( array $manifest ) use ( &$provider_materializations ): array {
					++$provider_materializations;
					return array( 'status' => 'completed', 'counts' => array( 'created' => count( $manifest['forms'] ?? array() ) ), 'forms' => $manifest['forms'] ?? array() );
				},
				'rollback_callback' => static function ( array $report ) use ( &$provider_rollbacks ): array {
					++$provider_rollbacks;
					return array( 'status' => 'rolled_back' );
				},
			),
			'manifest' => array( 'forms' => array( array( 'source_path' => 'index.html', 'selector' => 'form.newsletter' ) ) ),
		),
	),
);
$materialize_entities = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'materialize_prepared_entities' );
$deferred_entities    = $materialize_entities->invoke( null, $deferred_form_lifecycle, array( 'seed_entities' => true ) );
$final_quality_gate = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'public_result_from_wordpress_site_plan_receipt' );
$final_quality_error = $final_quality_gate->invoke(
	null,
	$deferred_form_receipt,
	array( 'fail_on_quality' => true, '_static_site_importer_deferred_form_quality_admission' => true ),
	$deferred_form_lifecycle,
	array(),
	$deferred_entities['reports']
);
$final_quality_receipt = is_wp_error( $final_quality_error ) ? $final_quality_error->get_error_data()['materialization_receipt'] ?? array() : array();
$entity_compensation = $final_quality_receipt['entity_compensation'] ?? array();
$assert( is_wp_error( $final_quality_error ) && 'static_site_importer_quality_gate_failed' === $final_quality_error->get_error_code() && 1 === $provider_materializations && 1 === $provider_rollbacks && 'partial' === ( $final_quality_receipt['status'] ?? '' ) && 'rolled_back' === ( $entity_compensation['entities'][0]['status'] ?? '' ), 'mismatched deferred form receipt rejects final admission and rolls back both provider entities and the site-plan transaction' );
$retried_quality_error = $final_quality_gate->invoke(
	null,
	$final_quality_receipt,
	array( 'fail_on_quality' => true, '_static_site_importer_deferred_form_quality_admission' => true ),
	$deferred_form_lifecycle,
	array(),
	$deferred_entities['reports']
);
$retried_quality_receipt = is_wp_error( $retried_quality_error ) ? $retried_quality_error->get_error_data()['materialization_receipt'] ?? array() : array();
$assert( is_wp_error( $retried_quality_error ) && 1 === $provider_rollbacks && $entity_compensation === ( $retried_quality_receipt['entity_compensation'] ?? array() ), 'repeated deferred finalization reuses the recorded provider compensation receipt without re-running destructive rollback callbacks' );
$cross_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$deferred_form_plan,
	array(
		'slug'                        => 'deferred-form-quality-cross-receipt',
		'runtime_entity_bindings'     => array( $form_binding ),
		'defer_materialization_commit' => true,
	)
);
$cross_receipt['completed']['runtime_declarations']['entity_bindings'][ hash( 'sha256', 'form-fallback-binding' ) ]['persisted_fragment_hash'] = hash( 'sha256', 'tampered cross receipt fragment' );
$cross_receipt['entity_compensation'] = $entity_compensation;
$cross_receipt['failure_context']     = $final_quality_receipt['failure_context'] ?? array();
$cross_quality_error = $final_quality_gate->invoke(
	null,
	$cross_receipt,
	array( 'fail_on_quality' => true, '_static_site_importer_deferred_form_quality_admission' => true ),
	$deferred_form_lifecycle,
	array(),
	$deferred_entities['reports']
);
$cross_quality_receipt = is_wp_error( $cross_quality_error ) ? $cross_quality_error->get_error_data()['materialization_receipt'] ?? array() : array();
$assert( is_wp_error( $cross_quality_error ) && 2 === $provider_rollbacks && true === ( $cross_quality_receipt['entity_compensation']['superseded_binding_mismatch'] ?? false ) && $entity_compensation['binding'] !== ( $cross_quality_receipt['entity_compensation']['binding'] ?? array() ), 'cross-receipt compensation evidence cannot suppress the second receipt rollback' );
$resumed_form_binding_receipt                             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$binding_plan,
	array(
		'slug'                    => 'form-fallback-binding-plan',
		'runtime_entity_bindings' => array( $form_binding ),
	)
);
$resumed_form_quality_report                              = Static_Site_Importer_Report_Diagnostics::new_conversion_report( 'index.html' );
$resumed_form_quality_report['quality']['fallback_count'] = 1;
$resumed_form_quality_report['diagnostics']               = array( $form_fallback );
$resumed_form_quality_report['materialization_receipt']   = $resumed_form_binding_receipt;
Static_Site_Importer_Report_Diagnostics::reconcile_provider_materialized_fallbacks( $resumed_form_quality_report );
$assert( $form_quality_report['quality_resolutions'] === $resumed_form_quality_report['quality_resolutions'], 'form quality resolution receipts remain deterministic on retry' );

$publication_svg         = '<svg xmlns="http://www.w3.org/2000/svg"><text style="font-family:Example">Example</text></svg>';
$publication_css         = '@font-face{font-family:Example;src:url(font.woff2)}';
$publication_font        = 'local-font-bytes';
$publication_token       = 'asset-' . substr( hash( 'sha256', 'assets/assets/font.woff2' ), 0, 16 );
$publication_face        = '@font-face{font-family:Example;src:url({{wordpress-site-plan:asset:' . $publication_token . '}});}';
$publication_content     = '<svg xmlns="http://www.w3.org/2000/svg"><text style="font-family:Example">Example</text><style>' . $publication_face . '</style></svg>';
$publication_input       = array(
	'css'   => array(
		array(
			'source_path'  => 'assets/fonts.css',
			'content_hash' => hash( 'sha256', $publication_css ),
			'font_faces'   => array( $publication_face ),
		),
	),
	'fonts' => array(
		array(
			'source_path'  => 'assets/font.woff2',
			'content_hash' => hash( 'sha256', base64_encode( $publication_font ) ),
		),
	),
);
$publication_declaration = array(
	'kind'                  => 'asset_publication',
	'type'                  => 'asset',
	'source_path'           => 'assets/logo.svg',
	'provenance'            => array(
		'source_path' => 'assets/logo.svg',
		'source'      => 'files',
		'hash'        => hash( 'sha256', $publication_svg ),
		'mime_type'   => 'image/svg+xml',
		'role'        => 'image',
		'bytes'       => strlen( $publication_svg ),
	),
	'destination'           => array(
		'capability' => 'asset_materialization',
		'required'   => true,
	),
	'source_role'           => 'image',
	'mime_type'             => 'image/svg+xml',
	'source_hash'           => hash( 'sha256', $publication_svg ),
	'expected_content_hash' => hash( 'sha256', $publication_content ),
	'sanitization'          => array(
		'schema'     => 'generic/svg-sanitization/v1',
		'input_hash' => hash( 'sha256', $publication_svg ),
	),
	'reference_targets'     => array(
		array(
			'target_path'                   => 'assets/assets/fonts.css',
			'write_reconciliation_identity' => hash( 'sha256', "wordpress-site-plan/write/v2\nassets/fonts.css\nassets/assets/fonts.css" ),
			'token'                         => $publication_token,
			'count'                         => 1,
			'context'                       => 'css_url',
		),
	),
	'transformation'        => array(
		'kind'                  => 'svg_font_enrichment',
		'css_source_paths'      => array( 'assets/fonts.css' ),
		'font_source_paths'     => array( 'assets/font.woff2' ),
		'input_hash'            => RuntimeDeclarations::hash( $publication_input ),
		'expected_content_hash' => hash( 'sha256', $publication_content ),
	),
);
$publication_artifact    = array(
	'entrypoint'           => 'index.html',
	'runtime_declarations' => array( $publication_declaration ),
	'files'                => array(
		'index.html'        => '<main><img src="assets/logo.svg"></main>',
		'assets/logo.svg'   => $publication_svg,
		'assets/fonts.css'  => $publication_css,
		'assets/font.woff2' => $publication_font,
	),
);
$publication_plan        = ( new ArtifactCompiler() )->compile( $publication_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$publication_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-publication-plan-identity-fixture' ),
);
$publication_receipt     = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $publication_plan, array( 'slug' => 'publication-plan' ) );
$publication_id          = $publication_plan['runtime_declarations'][0]['reconciliation_identity'];
$publication_report      = $publication_receipt['completed']['runtime_declarations']['asset_publications'][ $publication_id ] ?? array();
$publication_file        = $GLOBALS['ssi_plan_root'] . '/publication-plan/assets/assets/logo.svg';
$assert( 'completed' === $publication_receipt['status'] && 'completed' === ( $publication_report['status'] ?? '' ), 'required asset publication capability completes and is receipt-owned' );
$assert( hash_file( 'sha256', $publication_file ) === ( $publication_report['actual_content_hash'] ?? '' ) && $publication_plan['runtime_declarations'][0]['expected_content_hash'] === ( $publication_report['expected_content_hash'] ?? '' ), 'publication receipt proves canonical and resolved content integrity' );
$assert( str_contains( file_get_contents( $publication_file ), 'https://example.test/wp-content/themes/publication-plan/assets/assets/font.woff2' ), 'font-bearing SVG resolves only its declared local font URL' );

$GLOBALS['ssi_plan_options'] = array(
	'show_on_front' => 'posts',
	'page_on_front' => 0,
	'blogname'      => 'Before',
	'use_smilies'   => true,
);
$preview                     = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'      => 'site-plan',
		'overwrite' => true,
	)
);
$assert( 'completed' === $preview['status'], 'preview materialization completes' );
$assert(
	array(
		'canonical_validations'       => 1,
		'plan_resolutions'            => 1,
		'destination_preflights'      => 2,
		'immutable_projection_reused' => true,
	) === ( $preview['preparation'] ?? array() ),
	'materialization reuses one immutable projection while repeating destination preflight'
);
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'] && ! isset( $GLOBALS['ssi_plan_options']['stylesheet'] ), 'activate=false preserves runtime options' );
$activated = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'       => 'site-plan',
		'overwrite'  => true,
		'activate'   => true,
		'site_title' => 'Activated Plan',
	)
);
$assert( 'site-plan' === $GLOBALS['ssi_plan_options']['stylesheet'] && 'page' === $GLOBALS['ssi_plan_options']['show_on_front'] && 'Activated Plan' === $GLOBALS['ssi_plan_options']['blogname'], 'activate=true applies theme title and reading policy' );

// disable_smilies (issue #780): non-activating import must not touch the global option.
$GLOBALS['ssi_plan_options'] = array(
	'show_on_front' => 'posts',
	'page_on_front' => 0,
	'blogname'      => 'Before',
	'use_smilies'   => true,
);
$receipt_default             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'      => 'site-plan',
		'overwrite' => true,
	)
);
$assert( true === ( $receipt_default['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ), 'disable-smilies-defaults-requested-true' );
$assert( false === ( $receipt_default['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-not-applied-without-activate' );
$assert( true === $GLOBALS['ssi_plan_options']['use_smilies'], 'non-activating-import-preserves-use-smilies' );

// Explicit opt-out, non-activating: requested and applied both false.
$receipt_off = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'            => 'site-plan',
		'overwrite'       => true,
		'disable_smilies' => false,
	)
);
$assert( false === ( $receipt_off['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && false === ( $receipt_off['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-false-requested-and-applied-false' );

// Activating import with default policy flips the option so literal :) stays text.
$activated_smilies = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'       => 'site-plan',
		'overwrite'  => true,
		'activate'   => true,
		'site_title' => 'Activated Plan',
	)
);
$assert( false === $GLOBALS['ssi_plan_options']['use_smilies'], 'activating-import-sets-use-smilies-false' );
$assert( 'Hello :)' === convert_smilies( 'Hello :)' ), 'convert-smilies-output-unchanged-when-disabled' );
$assert( true === ( $activated_smilies['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && true === ( $activated_smilies['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'activating-import-records-requested-and-applied' );

// Explicit opt-out, activating: option untouched, policy not applied.
$GLOBALS['ssi_plan_options'] = array(
	'show_on_front' => 'posts',
	'page_on_front' => 0,
	'blogname'      => 'Before',
	'use_smilies'   => true,
);
$activated_off               = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'            => 'site-plan',
		'overwrite'       => true,
		'activate'        => true,
		'disable_smilies' => false,
		'site_title'      => 'Activated Off',
	)
);
$assert( true === $GLOBALS['ssi_plan_options']['use_smilies'], 'activate-with-disable-smilies-false-keeps-smilies' );
$assert( false === ( $activated_off['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && false === ( $activated_off['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-false-not-applied-on-activate' );

// Repeated activating import: use_smilies already false, update_option returns false (unchanged value).
$GLOBALS['ssi_plan_options']['use_smilies'] = false;
$receipt_repeat_policy                      = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'       => 'site-plan',
		'overwrite'  => true,
		'activate'   => true,
		'site_title' => 'Repeat Policy',
	)
);
$assert( 'completed' === $receipt_repeat_policy['status'], 'repeated-activating-import-completes' );
$assert( true === ( $receipt_repeat_policy['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && true === ( $receipt_repeat_policy['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'repeated-activating-import-records-requested-and-applied' );
$assert( false === $GLOBALS['ssi_plan_options']['use_smilies'], 'repeated-activating-import-keeps-use-smilies-false' );

// Every runtime mutation boundary restores the exact option snapshot on failure.
foreach ( array( 'after_activation', 'after_show_on_front', 'after_page_on_front', 'after_use_smilies', 'after_blogname' ) as $stage ) {
	$before_options              = array(
		'stylesheet'    => 'before-theme',
		'template'      => 'before-theme',
		'show_on_front' => 'posts',
		'page_on_front' => 71,
		'blogname'      => 'Before',
		'use_smilies'   => true,
	);
	$GLOBALS['ssi_plan_options'] = $before_options;
	$failed_runtime              = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
		$plan,
		array(
			'slug'                           => 'site-plan',
			'overwrite'                      => true,
			'activate'                       => true,
			'site_title'                     => 'After',
			'inject_materialization_failure' => $stage,
		)
	);
	$assert( 'partial' === $failed_runtime['status'], 'injected runtime failure returns a partial receipt: ' . $stage );
	$assert( $before_options === $GLOBALS['ssi_plan_options'], 'injected runtime failure restores every option and active theme: ' . $stage );
}

// Font overlays are journaled before each write, so a later verification failure restores bytes exactly.
$font_before = array();
foreach ( $font_plan['writes'] as $write ) {
	$path                 = $GLOBALS['ssi_plan_root'] . '/font-site-plan/' . $write['target_path'];
	$font_before[ $path ] = is_file( $path ) ? file_get_contents( $path ) : false;
}
$font_failed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_plan,
	array(
		'slug'                           => 'font-site-plan',
		'overwrite'                      => true,
		'font_materialization'           => $font_materialization,
		'inject_materialization_failure' => 'font_verification',
	)
);
$assert( 'partial' === $font_failed['status'] && in_array( 'injected_font_verification_failure', array_column( $font_failed['diagnostics'], 'reason_code' ), true ), 'font verification failure follows overlay writes' );
foreach ( $font_before as $path => $bytes ) {
	$assert( $bytes === ( is_file( $path ) ? file_get_contents( $path ) : false ), 'font verification rollback restores theme bytes exactly: ' . $path );
}

$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $repeat['status'], 'reconciliation repeat completes' );
$assert( count( $GLOBALS['ssi_plan_posts'] ) === count( $plan['pages'] ), 'reconciliation preserves source page identity' );

$before_posts      = count( $GLOBALS['ssi_plan_posts'] );
$before_files      = count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() );
$invalid           = $plan;
$invalid['schema'] = 'blocks-engine/wordpress-site-plan/v1';
$rejected          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $invalid, array( 'slug' => 'reject' ) );
$assert( 'rejected' === $rejected['status'], 'invalid plan is rejected' );
$assert( $before_posts === count( $GLOBALS['ssi_plan_posts'] ), 'invalid plan creates no posts' );
$assert( $before_files === count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() ), 'invalid plan writes no files' );

$missing_identity = $plan;
unset( $missing_identity['plan_identity'] );
$missing_identity_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $missing_identity, array( 'slug' => 'missing-plan-identity' ) );
$assert( 'rejected' === $missing_identity_receipt['status'] && 'canonical_plan_rejected' === ( $missing_identity_receipt['errors'][0]['code'] ?? '' ), 'plans require a versioned producer identity before materialization' );

$tampered_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare(
	$plan,
	array(
		'slug'      => 'tampered-prepared',
		'overwrite' => true,
	)
);
$tampered_prepared['base_resolved']['pages'][0]['resolved_block_markup'] .= '<p>tampered</p>';
$tampered_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $tampered_prepared );
$assert( 'rejected' === $tampered_result['status'] && 'prepared_projection_changed' === ( $tampered_result['diagnostics'][0]['reason_code'] ?? '' ), 'changed immutable prepared projections are rejected before mutation' );

$destination_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare(
	$plan,
	array(
		'slug'      => 'changed-prepared-destination',
		'overwrite' => true,
	)
);
symlink( sys_get_temp_dir(), $GLOBALS['ssi_plan_root'] . '/changed-prepared-destination' );
$destination_changed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $destination_prepared );
unlink( $GLOBALS['ssi_plan_root'] . '/changed-prepared-destination' );
$assert( 'rejected' === $destination_changed['status'] && 'unsafe_theme_destination' === ( $destination_changed['diagnostics'][0]['reason_code'] ?? '' ), 'mutable destination safety is rechecked immediately before writes' );

$unsafe = $GLOBALS['ssi_plan_root'] . '/unsafe';
mkdir( $unsafe, 0777, true );
symlink( sys_get_temp_dir(), $unsafe . '/assets' );
$unsafe_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$plan,
	array(
		'slug'      => 'unsafe',
		'overwrite' => true,
	)
);
$assert( 'rejected' === $unsafe_result['status'], 'unsafe destination is rejected' );
$assert( 'unsafe_destination_path' === $unsafe_result['diagnostics'][0]['reason_code'], 'unsafe destination is diagnosed' );

$external_dynamic_artifact                         = $artifact;
$external_dynamic_artifact['files']['index.html'] .= '<script src="https://cdn.example.test/runtime.js"></script>';
$external_dynamic_plan                             = ( new ArtifactCompiler() )->compile( $external_dynamic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid( $external_dynamic_plan );
$external_dynamic_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-external-dynamic-plan-identity-fixture' ),
);
$assert( 'not_proven' === $external_dynamic_plan['reference_semantics']['dynamic_client_assets']['status'], 'compiler marks external dynamic scripts as not proven' );

$dynamic_before_posts   = $GLOBALS['ssi_plan_posts'];
$dynamic_before_meta    = $GLOBALS['ssi_plan_meta'];
$dynamic_before_options = $GLOBALS['ssi_plan_options'];
$dynamic_prepared       = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $external_dynamic_plan, array( 'slug' => 'external-dynamic-plan' ) );
$assert( 'rejected' === $dynamic_prepared['status'], 'external dynamic scripts reject during preparation' );
$assert( 'WordPress site plan cannot prove dynamic client asset references.' === $dynamic_prepared['receipt']['diagnostics'][0]['reason_code'], 'preparation preserves the canonical destination rejection reason' );
$assert( $dynamic_before_posts === $GLOBALS['ssi_plan_posts'] && $dynamic_before_meta === $GLOBALS['ssi_plan_meta'] && $dynamic_before_options === $GLOBALS['ssi_plan_options'], 'preparation rejects external dynamic scripts before page or option mutation' );
$assert( ! is_dir( $GLOBALS['ssi_plan_root'] . '/external-dynamic-plan' ), 'preparation rejects external dynamic scripts before file mutation' );

$dynamic_rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $external_dynamic_plan, array( 'slug' => 'external-dynamic-plan' ) );
$assert( 'rejected' === $dynamic_rejected['status'], 'external dynamic scripts reject during materialization' );
$assert( 'WordPress site plan cannot prove dynamic client asset references.' === $dynamic_rejected['diagnostics'][0]['reason_code'], 'materialization preserves the canonical destination rejection reason' );
$assert( $dynamic_before_posts === $GLOBALS['ssi_plan_posts'] && $dynamic_before_meta === $GLOBALS['ssi_plan_meta'] && $dynamic_before_options === $GLOBALS['ssi_plan_options'], 'materialization rejects external dynamic scripts before page or option mutation' );
$assert( ! is_dir( $GLOBALS['ssi_plan_root'] . '/external-dynamic-plan' ), 'materialization rejects external dynamic scripts before file mutation' );

$dynamic_allowed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$external_dynamic_plan,
	array(
		'slug'                                 => 'allowed-external-dynamic-plan',
		'require_proven_dynamic_client_assets' => false,
	)
);
$assert( 'completed' === $dynamic_allowed['status'], 'explicit policy can preserve unproven dynamic client scripts' );

$dynamic_artifact                            = $artifact;
$dynamic_artifact['files']['index.html']    .= '<script src="assets/site.js"></script>';
$dynamic_artifact['files']['assets/site.js'] = 'window.sitePlan = true;';
$dynamic_plan                                = ( new ArtifactCompiler() )->compile( $dynamic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$dynamic_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-dynamic-plan-identity-fixture' ),
);
$dynamic_completed                           = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $dynamic_plan, array( 'slug' => 'dynamic-plan' ) );
$assert( 'completed' === $dynamic_completed['status'], 'declared static local scripts are proven and materialize' );

$many_files = array( 'index.html' => '<main><h1>Index</h1></main>' );
for ( $index = 1; $index <= 50; ++$index ) {
	$many_files[ 'page-' . $index . '.html' ] = '<main><h1>Page ' . $index . '</h1></main>';
}
$many_plan    = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => $many_files,
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$many_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-many-plan-identity-fixture' ),
);
$many_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $many_plan, array( 'slug' => 'many-pages-plan' ) );
$assert( 51 === ( $many_receipt['completed']['block_provenance_count'] ?? 0 ) && 50 === count( $many_receipt['completed']['block_provenance'] ?? array() ) && true === ( $many_receipt['completed']['block_provenance_truncated'] ?? false ), 'receipt enforces the provenance cap before downstream projection' );

$provider_receipt_diagnostics  = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'materialization_receipt' => array(
			'schema'    => 'static-site-importer/materialization-receipt/v1',
			'status'    => 'completed',
			'plan_hash' => 'provider-plan-hash',
			'completed' => array(
				'block_provenance_count' => 1,
				'block_provenance'       => array(
					array(
						'source'          => array(
							'schema'                  => 'static-site-importer/page-provenance/v1',
							'source_path'             => 'index.html',
							'reconciliation_identity' => 'page-index',
							'raw_source_html'         => '<main>provider source markup</main>',
							'unknown_source_key'      => 'provider payload',
						),
						'stages'          => array(
							array(
								'stage'         => 'blocks-engine/wordpress-site-plan-resolver',
								'output'        => array(
									'sha256'  => 'resolved-hash',
									'bytes'   => 18,
									'preview' => '<p>provider preview</p>',
								),
								'provider_html' => '<p>provider HTML</p>',
							),
							array(
								'stage'             => 'static-site-importer/runtime-entity-bindings',
								'input_sha256'      => 'resolved-hash',
								'output'            => array(
									'sha256'     => 'bound-hash',
									'bytes'      => 21,
									'count'      => 1,
									'raw_markup' => '<p>bound markup</p>',
								),
								'unknown_stage_key' => 'provider value',
							),
							array(
								'stage'  => 'provider-extra-stage',
								'output' => array( 'sha256' => 'must-not-survive' ),
							),
						),
						'unknown_row_key' => array( 'provider' => 'payload' ),
					),
				),
			),
		),
	)
);
$projected_provider_provenance = $provider_receipt_diagnostics['materialization_receipt']['block_provenance'] ?? array();
$projected_provider_json       = (string) wp_json_encode( $projected_provider_provenance );
$assert(
	array(
		array(
			'source' => array(
				'schema'                  => 'static-site-importer/page-provenance/v1',
				'source_path'             => 'index.html',
				'reconciliation_identity' => 'page-index',
			),
			'stages' => array(
				array(
					'stage'  => 'blocks-engine/wordpress-site-plan-resolver',
					'output' => array(
						'sha256' => 'resolved-hash',
						'bytes'  => 18,
					),
				),
				array(
					'stage'        => 'static-site-importer/runtime-entity-bindings',
					'input_sha256' => 'resolved-hash',
					'output'       => array(
						'sha256' => 'bound-hash',
						'bytes'  => 21,
						'count'  => 1,
					),
				),
			),
		),
	) === $projected_provider_provenance,
	'fixture diagnostics project only bounded provenance identity and stage metadata'
);
$assert( ! str_contains( $projected_provider_json, 'provider source markup' ) && ! str_contains( $projected_provider_json, 'provider HTML' ) && ! str_contains( $projected_provider_json, 'provider preview' ) && ! str_contains( $projected_provider_json, 'unknown_source_key' ) && ! str_contains( $projected_provider_json, 'unknown_row_key' ) && ! str_contains( $projected_provider_json, 'unknown_stage_key' ) && ! str_contains( $projected_provider_json, 'provider-extra-stage' ), 'fixture diagnostics reject provider markup, previews, nested payloads, and unknown provenance keys' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$nested_index_artifact     = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		'website/index.html'            => '<main><h1>Home</h1></main>',
		'website/about/index.html'      => '<main><h1>About</h1></main>',
		'website/about/team/index.html' => '<main><h1>Team</h1></main>',
	),
);
$nested_index_plan         = ( new ArtifactCompiler() )->compile( $nested_index_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$nested_index_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-nested-index-plan-identity-fixture' ),
);
$nested_index_receipt      = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $nested_index_plan, array( 'slug' => 'nested-index-plan' ) );
$nested_index_ids          = $nested_index_receipt['completed']['pages'] ?? array();
$home_id                   = (int) ( $nested_index_ids['website/index.html'] ?? 0 );
$about_id                  = (int) ( $nested_index_ids['website/about/index.html'] ?? 0 );
$team_id                   = (int) ( $nested_index_ids['website/about/team/index.html'] ?? 0 );
$assert( 'completed' === $nested_index_receipt['status'] && 3 === count( array_unique( array( $home_id, $about_id, $team_id ) ) ), 'wrapper-root nested index pages materialize as distinct WordPress posts' );
$assert( 'index' === ( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_name'] ?? null ) && 0 === ( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_parent'] ?? null ), 'wrapper entrypoint preserves its root page identity' );
$assert( 'about' === ( $GLOBALS['ssi_plan_posts'][ $about_id ]['post_name'] ?? null ) && 0 === ( $GLOBALS['ssi_plan_posts'][ $about_id ]['post_parent'] ?? null ), 'nested index page slug matches its top-level canonical route' );
$assert( 'team' === ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_name'] ?? null ) && $about_id === ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_parent'] ?? null ), 'deeper nested index page preserves canonical slug and WordPress parent identity' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$classify_artifact         = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'               => '<main><h1>Home</h1></main>',
		'blog/hello.html'          => '<html><head><meta property="article:published_time" content="2024-03-12T10:00:00Z"></head><body><main><h1>Hello</h1></main></body></html>',
		'2024/03/dated-post.html'  => '<main><h1>Dated by URL</h1></main>',
		'blog/about-the-blog.html' => '<main><h1>About the blog</h1></main>',
	),
);
$classify_plan             = ( new ArtifactCompiler() )->compile( $classify_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$classify_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-classify-plan-identity-fixture' ),
);
$classify_receipt          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $classify_plan, array( 'slug' => 'classify-plan' ) );
$classify_ids              = $classify_receipt['completed']['pages'] ?? array();
$home_id                   = (int) ( $classify_ids['index.html'] ?? 0 );
$post_id                   = (int) ( $classify_ids['blog/hello.html'] ?? 0 );
$url_dated_id              = (int) ( $classify_ids['2024/03/dated-post.html'] ?? 0 );
$blog_about_id             = (int) ( $classify_ids['blog/about-the-blog.html'] ?? 0 );
$assert( 'page' === ( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_type'] ?? null ), 'undated entrypoint stays a page by default' );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $post_id ]['post_type'] ?? null ) && '2024-03-12 10:00:00' === ( $GLOBALS['ssi_plan_posts'][ $post_id ]['post_date_gmt'] ?? null ), 'dated article meta classifies a document as a post with its publish date stored as GMT' );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $url_dated_id ]['post_type'] ?? null ), 'hierarchical YYYY/MM route classifies a document as a post' );
$assert( 'page' === ( $GLOBALS['ssi_plan_posts'][ $blog_about_id ]['post_type'] ?? null ), 'a post-like URL without date evidence stays a page' );
$assert( 0 === ( $GLOBALS['ssi_plan_posts'][ $post_id ]['post_parent'] ?? -1 ), 'a dated post does not inherit the synthetic page ancestor as post_parent' );

// Re-import the same plan without resetting the post store: reconciliation
// must reuse existing rows and keep both count and post types stable.
$classify_repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $classify_plan, array( 'slug' => 'classify-plan' ) );
$assert( 'completed' === $classify_repeat['status'] && count( $classify_ids ) === count( $classify_repeat['completed']['pages'] ?? array() ), 'classification re-import completes with the same page count' );
foreach ( array( 'index.html', 'blog/hello.html', '2024/03/dated-post.html', 'blog/about-the-blog.html' ) as $real_source ) {
	$id            = (int) ( $classify_ids[ $real_source ] ?? 0 );
	$expected_type = in_array( $real_source, array( 'blog/hello.html', '2024/03/dated-post.html' ), true ) ? 'post' : 'page';
	$repeat_id     = (int) ( $classify_repeat['completed']['pages'][ $real_source ] ?? 0 );
	// Synthetic compiler route pages are recreated on re-import; only the
	// real source documents must reuse the same post id.
	$assert( $id === $repeat_id && $expected_type === ( $GLOBALS['ssi_plan_posts'][ $id ]['post_type'] ?? null ), 'classification re-import reuses real document post ids with stable post types' );
}

// The classifier emits UTC, so a non-UTC PHP timezone must not shift the
// value written to post_date_gmt. Run the same dated meta through a non-UTC
// timezone and restore it before the next scenario.
$previous_tz = date_default_timezone_get();
date_default_timezone_set( 'America/New_York' );
$tz_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'        => '<main><h1>Home</h1></main>',
		'essays/dated.html' => '<html><head><meta property="article:published_time" content="2024-03-12T10:00:00Z"></head><body><main><h1>Dated</h1></main></body></html>',
	),
);
$tz_plan     = ( new ArtifactCompiler() )->compile( $tz_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$tz_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-timezone-plan-identity-fixture' ),
);
$tz_receipt  = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $tz_plan, array( 'slug' => 'tz-plan' ) );
$tz_id       = (int) ( ( $tz_receipt['completed']['pages'] ?? array() )['essays/dated.html'] ?? 0 );
// post_date is site-local and wp_insert_post derives it from post_date_gmt;
// the standalone stub stores the array verbatim, so only the UTC storage value
// is asserted here. The runtime smoke covers the local-time derivation.
$assert( '2024-03-12 10:00:00' === ( $GLOBALS['ssi_plan_posts'][ $tz_id ]['post_date_gmt'] ?? null ), 'non-UTC timezone does not shift the detected publish date stored as GMT' );
date_default_timezone_set( $previous_tz );

// A page first imported undated, then re-imported with a date signal: the
// reconciliation identity is stable across post types, so the existing row is
// reused and reclassified as a post rather than duplicated.
$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$reclassify_artifact       = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'      => '<main><h1>Home</h1></main>',
		'notes/post.html' => '<main><h1>Essay</h1></main>',
	),
);
$reclassify_plan           = ( new ArtifactCompiler() )->compile( $reclassify_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$reclassify_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-reclassify-plan-identity-fixture' ),
);
$reclassify_first          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $reclassify_plan, array( 'slug' => 'reclassify-plan' ) );
$first_id                  = (int) ( ( $reclassify_first['completed']['pages'] ?? array() )['notes/post.html'] ?? 0 );
$assert( 'page' === ( $GLOBALS['ssi_plan_posts'][ $first_id ]['post_type'] ?? null ), 'undated document imports as a page before reclassification' );
$reclassify_plan['pages'] = array_map(
	static function ( array $page ): array {
		if ( 'notes/post.html' === $page['source_path'] ) {
			// The plan validator requires each meta row order to match its index.
			$page['document_metadata']['meta'][] = array(
				'order'     => count( $page['document_metadata']['meta'] ),
				'placement' => 'head',
				'property'  => 'article:published_time',
				'content'   => '2024-06-01T08:00:00Z',
			);
		}
		return $page;
	},
	$reclassify_plan['pages']
);
$reclassify_second        = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $reclassify_plan, array( 'slug' => 'reclassify-plan' ) );
$second_id                = (int) ( ( $reclassify_second['completed']['pages'] ?? array() )['notes/post.html'] ?? 0 );
$assert( $first_id === $second_id && 'post' === ( $GLOBALS['ssi_plan_posts'][ $second_id ]['post_type'] ?? null ) && '2024-06-01 08:00:00' === ( $GLOBALS['ssi_plan_posts'][ $second_id ]['post_date_gmt'] ?? null ), 'page-to-post reclassification reuses the existing post and updates its type and date' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$parented_artifact         = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'            => '<main><h1>Home</h1></main>',
		'about/index.html'      => '<main><h1>About</h1></main>',
		'about/blog/index.html' => '<html><head><meta property="article:published_time" content="2024-01-05T08:00:00Z"></head><body><main><h1>Blog</h1></main></body></html>',
	),
);
$parented_plan             = ( new ArtifactCompiler() )->compile( $parented_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$parented_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-parented-plan-identity-fixture' ),
);
$parented_receipt          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $parented_plan, array( 'slug' => 'parented-plan' ) );
$parented_ids              = $parented_receipt['completed']['pages'] ?? array();
$parented_blog_id          = (int) ( $parented_ids['about/blog/index.html'] ?? 0 );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $parented_blog_id ]['post_type'] ?? null ), 'a dated article nested under a page hierarchy still classifies as a post' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$explicit_artifact         = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'     => '<main><h1>Home</h1></main>',
		'notes/ideas.md' => "---\ntitle: Ideas\ntype: post\n---\n\n# Ideas\nBody",
	),
);
$explicit_plan             = ( new ArtifactCompiler() )->compile( $explicit_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$explicit_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-explicit-plan-identity-fixture' ),
);
$explicit_receipt          = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $explicit_plan, array( 'slug' => 'explicit-plan' ) );
$explicit_ids              = $explicit_receipt['completed']['pages'] ?? array();
$ideas_id                  = (int) ( $explicit_ids['notes/ideas.md'] ?? 0 );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $ideas_id ]['post_type'] ?? null ), 'explicit markdown frontmatter post_type overrides signal-free detection' );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 1;
$partial                        = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'partial-plan' ) );
$assert( 'partial' === $partial['status'], 'runtime mutation failure returns partial receipt' );
$assert( 'simulated_post_failure' === $partial['diagnostics'][0]['reason_code'], 'partial receipt keeps mutation failure identity' );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 0;
$parent_plan                    = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			'website/index.html'       => '<main>Home</main>',
			'website/about/index.html' => '<main>About</main>',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$parent_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-parent-plan-identity-fixture' ),
);
$child_plan                     = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			'website/index.html'            => '<main>Home</main>',
			'website/about/team/index.html' => '<main>Team</main>',
		),
	)
)->toArray()['source_reports']['wordpress_site_plan'];
$register_plan_blocks = static function ( array $candidate ) use ( $register_document_blocks ): void {
	foreach ( $candidate['pages'] as $page ) {
		$register_document_blocks( parse_blocks( (string) ( $page['canonical_block_markup'] ?? '' ) ) );
	}
};
$register_plan_blocks( $parent_plan );
$register_plan_blocks( $child_plan );
$child_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-child-plan-identity-fixture' ),
);
$parent_batch                   = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$parent_plan,
	array(
		'slug'          => 'batch-parent-plan',
		'import_run_id' => 'batch-parent-run',
	)
);
file_put_contents( $GLOBALS['ssi_plan_root'] . '/batch-parent-plan/static-site-importer-manifest.json', json_encode( array( 'import_run_id' => 'batch-parent-run' ) ) );
$child_batch = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$child_plan,
	array(
		'slug'                              => 'batch-parent-plan',
		'import_run_id'                     => 'batch-parent-run',
		'preserve_existing_theme_bootstrap' => true,
		'overwrite'                         => true,
	)
);
$about_id    = (int) ( $parent_batch['completed']['pages']['website/about/index.html'] ?? 0 );
$team_id     = (int) ( $child_batch['completed']['pages']['website/about/team/index.html'] ?? 0 );
$assert( 'completed' === $child_batch['status'] && $about_id > 0 && $about_id === (int) ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_parent'] ?? 0 ), 'later batch resolves an existing parent only through matching run provenance' );
$parent_order                   = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'parent_ordered_pages' );
$GLOBALS['ssi_plan_posts'][999] = array( 'post_name' => 'external-parent' );
$GLOBALS['ssi_plan_meta'][999]['_static_site_importer_provenance'] = json_encode(
	array(
		'import_run_id' => 'batch-parent-run',
		'source_path'   => 'website/external/index.html',
	)
);
$descendant_only = $parent_order->invoke(
	null,
	array(
		array(
			'source_path'        => 'website/external/child/index.html',
			'parent_source_path' => 'website/external/index.html',
		),
	),
	'batch-parent-run'
);
$assert( is_array( $descendant_only ) && 1 === count( $descendant_only ) && 'website/external/child/index.html' === ( $descendant_only[0]['source_path'] ?? '' ), 'external provenance parent satisfies ordering without being emitted as a page' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$route_artifact            = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		'website/index.html'         => '<main><a href="contact/index.html">Contact</a><a href="post/news/index.html">News</a></main>',
		'website/contact/index.html' => '<main>Contact</main>',
		array( 'path' => 'website/post/news/index.html', 'content' => '<article><time datetime="2024-03-01">March 1</time><h1>News</h1></article>', 'metadata' => array( 'post_type' => 'post' ) ),
	),
);
$route_plan                = ( new ArtifactCompiler() )->compile( $route_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$register_plan_blocks( $route_plan );
$route_receipt             = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $route_plan, array( 'slug' => 'route-link-plan' ) );
$route_home                = current( array_filter( $GLOBALS['ssi_plan_posts'], static fn( array $post ): bool => 'index' === ( $post['post_name'] ?? '' ) ) );
$route_content             = is_array( $route_home ) ? stripslashes( (string) ( $route_home['post_content'] ?? '' ) ) : '';
$assert( 'completed' === ( $route_receipt['status'] ?? '' ) && str_contains( $route_content, 'href="https://example.test/contact/"' ) && str_contains( $route_content, 'href="https://example.test/2024/03/news/"' ), 'canonical routes resolve to actual WordPress page and dated-post permalinks after materialization' );
$rewrite_route_references = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'rewrite_route_references' );
$pin_route_content        = $rewrite_route_references->invoke( null, 'data-pin-url=\\u0022/post/news\\u0022', array( '/post/news' => 'https://example.test/2024/03/news/' ) );
$assert( 'data-pin-url=\\u0022https://example.test/2024/03/news/\\u0022' === $pin_route_content, 'escaped route-bearing data URL attributes resolve to the materialized WordPress permalink' );

$hash_plan = array(
	'schema' => 'test/plan/v1',
	'escaped' => "quote:\" slash:/ backslash:\\ control:\n\t\x01",
	'unicode' => json_decode( '"\\u00e9 \\u6f22 \\ud83d\\ude80"' ),
	'large' => array_fill( 0, 256, str_repeat( 'plan-token/', 1024 ) ),
);
$GLOBALS['ssi_plan_count_aggregate_encodes'] = true;
$legacy_hash = hash( 'sha256', (string) wp_json_encode( $hash_plan, JSON_UNESCAPED_SLASHES ) );
$GLOBALS['ssi_plan_json_array_calls'] = 0;
$plan_hash_method = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'hash' );
$streamed_hash = $plan_hash_method->invoke( null, $hash_plan );
$GLOBALS['ssi_plan_count_aggregate_encodes'] = false;
$assert( $legacy_hash === $streamed_hash && 0 === $GLOBALS['ssi_plan_json_array_calls'], 'streamed plan hashing preserves canonical JSON SHA-256 identity without materializing the full plan JSON' );

$root_media_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'website/index.html',
		'files'      => array(
			'website/index.html'        => '<main><img src="/media/example.jpg?size=large#hero" srcset="/media/example.jpg?size=small#hero 1x, https://cdn.example.test/example.jpg 2x, data:image/png;base64,AA== 3x"><div style="background-image:url(/media/example.jpg?size=large#hero)"></div></main>',
			'website/media/example.jpg' => 'fixture image',
		),
	)
)->toArray();
$root_media_plan    = $root_media_result['source_reports']['wordpress_site_plan'];
$register_plan_blocks( $root_media_plan );
$root_media_plan['plan_identity'] = array(
	'schema' => 'blocks-engine/wordpress-site-plan-identity/v1',
	'hash'   => hash( 'sha256', 'released-transformer-root-media-plan-identity-fixture' ),
);
$root_media_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $root_media_plan, array( 'slug' => 'root-media-plan' ) );
$root_media_page_id = (int) ( $root_media_receipt['completed']['pages']['website/index.html'] ?? 0 );
$root_media_content = stripslashes( (string) ( $GLOBALS['ssi_plan_posts'][ $root_media_page_id ]['post_content'] ?? '' ) );
$root_media_url     = 'https://example.test/wp-content/themes/root-media-plan/assets/website/media/example.jpg';
$assert( 'completed' === $root_media_receipt['status'] && str_contains( $root_media_content, $root_media_url . '?size=large#hero' ) && str_contains( $root_media_content, 'srcset="' . $root_media_url . '?size=small#hero 1x, https://cdn.example.test/example.jpg 2x, data:image/png;base64,AA== 3x"' ) && str_contains( $root_media_content, '"url":"' . $root_media_url . '"' ) && ! str_contains( $root_media_content, 'src="/media/example.jpg' ), 'root-relative captured media resolves through the canonical theme asset map while preserving query fragments, CSS references, and srcset external/data candidates' );

echo "WordPress site plan materializer smoke passed.\n";
