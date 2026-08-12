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
$GLOBALS['ssi_plan_root']       = sys_get_temp_dir() . '/ssi-plan-' . bin2hex( random_bytes( 4 ) );
$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_options']    = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before', 'use_smilies' => true );
$GLOBALS['ssi_plan_fail_after'] = 0;
$GLOBALS['ssi_plan_font_requests'] = array();
mkdir( $GLOBALS['ssi_plan_root'], 0777, true );

class WP_Error {
	private string $code;
	private mixed $data;
	public function __construct( string $code, string $message = '', mixed $data = null ) { $this->code = $code; $this->data = $data; }
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
class WP_Post {
	public int $ID;
	public function __construct( int $id ) { $this->ID = $id; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function get_theme_root(): string { return $GLOBALS['ssi_plan_root']; }
function get_theme_root_uri(): string { return 'https://example.test/wp-content/themes'; }
function trailingslashit( string $path ): string { return rtrim( $path, '/' ) . '/'; }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function wp_slash( string $value ): string { return addslashes( $value ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_parse_url( string $url ) { return parse_url( $url ); }
function wp_safe_remote_get( string $url, array $args ) {
	$GLOBALS['ssi_plan_font_requests'][] = array( 'url' => $url, 'args' => $args );
	if ( 'https://fonts.googleapis.com/css2?family=Inter-like:wght@400;700' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "@font-face{font-family:'Inter-like';font-style:normal;font-weight:100 900;font-stretch:75% 125%;src:url(https://fonts.example.test/inter.woff2) format('woff2');unicode-range:U+0000-00FF}" );
	}
	if ( 'https://fonts.example.test/inter.woff2' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'inter-variable-glyph-payload' );
	}
	if ( str_starts_with( $url, 'https://fonts.googleapis.com/' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => "@font-face{font-family:'Example Font';font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/example/font.woff2) format('woff2')}" );
	}
	if ( 'https://fonts.gstatic.com/s/example/font.woff2' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => 'font-payload' );
	}
	return new WP_Error( 'unexpected_request' );
}
function wp_remote_retrieve_response_code( $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( $response ): string { return (string) ( $response['body'] ?? '' ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['ssi_plan_options'][ $key ] ?? $default; }
function update_option( string $key, $value ): bool {
	if ( array_key_exists( $key, $GLOBALS['ssi_plan_options'] ) && $GLOBALS['ssi_plan_options'][ $key ] === $value ) {
		return false; // Core semantics: unchanged value writes no row and returns false.
	}
	$GLOBALS['ssi_plan_options'][ $key ] = $value;
	return true;
}
function switch_theme( string $slug ): void { $GLOBALS['ssi_plan_options']['stylesheet'] = $slug; }
function convert_smilies( string $content, string $which = 'content' ): string { return ( $GLOBALS['ssi_plan_options']['use_smilies'] ?? true ) ? 'smilied-' . $which : $content; }
function sanitize_text_field( string $value ): string { return $value; }
function update_post_meta( int $id, string $key, string $value ): void { $GLOBALS['ssi_plan_meta'][ $id ][ $key ] = $value; }
function get_post_meta( int $id, string $key, bool $single = true ): string { return (string) ( $GLOBALS['ssi_plan_meta'][ $id ][ $key ] ?? '' ); }
function get_posts( array $args ): array {
	foreach ( $GLOBALS['ssi_plan_meta'] as $id => $meta ) {
		if ( isset( $meta[ $args['meta_key'] ] ) && ( ! isset( $args['meta_value'] ) || $meta[ $args['meta_key'] ] === $args['meta_value'] ) ) { $matches[] = new WP_Post( $id ); }
	}
	return $matches ?? array();
}
function get_page_by_path( string $slug, $output, string $type ) {
	foreach ( $GLOBALS['ssi_plan_posts'] as $id => $post ) {
		if ( $post['post_name'] === $slug && $post['post_type'] === $type ) { return new WP_Post( $id ); }
	}
	return null;
}
function wp_insert_post( array $post, bool $wp_error ) {
	if ( $GLOBALS['ssi_plan_fail_after'] && count( $GLOBALS['ssi_plan_posts'] ) >= $GLOBALS['ssi_plan_fail_after'] ) { return new WP_Error( 'simulated_post_failure' ); }
	$id = ! empty( $post['ID'] ) ? (int) $post['ID'] : count( $GLOBALS['ssi_plan_posts'] ) + 1;
	$GLOBALS['ssi_plan_posts'][ $id ] = $post;
	return $id;
}
class WP_Post_Type {
	public string $name;
	public bool $public;
	public object $cap;
	public function __construct( string $name, bool $public = true ) {
		$this->name = $name;
		$this->public = $public;
		$this->cap = (object) array(
			'create_posts'  => 'page' === $name ? 'edit_pages' : 'edit_posts',
			'publish_posts' => 'page' === $name ? 'publish_pages' : 'publish_posts',
		);
	}
}
function get_post_type_object( string $post_type ): ?object {
	return in_array( $post_type, array( 'page', 'post' ), true ) ? new WP_Post_Type( $post_type ) : null;
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-font-materializer.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-document-type-classifier.php';
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
		'index.html'       => '<html><head><link rel="stylesheet" href="/assets/site.css"></head><body><header><p>Header</p></header><main><img src="assets/logo.svg"><h1>Home</h1></main></body></html>',
		'about.html'       => '<main><h1>About</h1></main>',
		'assets/logo.svg'  => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/site.css'  => 'main { background: url(assets/logo.svg); }',
	),
);
$result = ( new ArtifactCompiler() )->compile( $artifact )->toArray();
$plan   = $result['source_reports']['wordpress_site_plan'];
$assert( 'blocks-engine/wordpress-site-plan/v2' === $plan['schema'], 'compiler emits the released v2 site plan' );
$assert( isset( $result['source_reports']['wordpress_site_plan']['reporting'] ), 'compiler exposes the plan in source reports' );

// Gutenberg gaps are SSI receipt/report extensions and must never alter the
// compiler-owned plan, whose schema and hash are producer contracts.
$canonical_plan = $plan;
$project_gaps   = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'project_gutenberg_gaps' );
$gaps           = $project_gaps->invoke( null, array( array( 'id' => 'gap-plan-contract', 'block_name' => 'example/gap', 'references' => array( 'file:./view.js' ), 'source_path' => 'index.html' ) ), 'installed_activated' );
$assert( $canonical_plan === $plan && ! isset( $plan['gutenberg_gaps'] ), 'gutenberg-gap-projection-does-not-mutate-canonical-plan' );
$gap_contract = Static_Site_Importer_Diagnostic_Contract::build( array( 'success' => true, 'status' => 'completed', 'import_report' => array( 'blocks_engine' => array( 'wordpress_site_plan' => $plan, 'gutenberg_gaps' => $gaps ) ) ) );
$gap_diagnostics = array_values( array_filter( $gap_contract['diagnostics'] ?? array(), static fn( array $diagnostic ): bool => 'gap-plan-contract' === ( $diagnostic['id'] ?? '' ) ) );
$assert( 'installed_activated' === ( $gap_diagnostics[0]['materialization_status'] ?? '' ) && array( 'file:./view.js' ) === ( $gap_diagnostics[0]['references'] ?? array() ), 'gutenberg-gap-diagnostics-retain-materialization-status-and-references' );

$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $receipt['status'], 'valid plan completes' );
$assert( 'static-site-importer/materialization-receipt/v1' === $receipt['schema'], 'receipt schema is stable' );
$assert( count( $plan['writes'] ) === count( $receipt['generated_files'] ), 'all canonical writes are materialized' );
$assert( file_exists( $GLOBALS['ssi_plan_root'] . '/site-plan/templates/front-page.html' ), 'templates are materialized' );
$assert( str_contains( file_get_contents( $GLOBALS['ssi_plan_root'] . '/site-plan/assets/assets/site.css' ), 'https://example.test/wp-content/themes/site-plan/assets/assets/logo.svg' ), 'root-relative stylesheet references resolve to declared theme assets' );
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'], 'plan-only materialization does not change reading settings by default' );
$assert( $receipt['plan']['pages'][0]['document_metadata']['links'][0]['resolved_url'] === 'https://example.test/wp-content/themes/site-plan/assets/assets/site.css', 'resolved metadata retains the declared stylesheet destination' );
$assert( array() === $receipt['completed']['runtime_declarations']['asset_publications'], 'plans without publication declarations retain an explicit empty receipt collection' );
$unbound_provenance = $receipt['completed']['block_provenance'] ?? array();
$assert( count( $plan['pages'] ) === count( $unbound_provenance ) && count( $plan['pages'] ) === ( $receipt['completed']['block_provenance_count'] ?? 0 ), 'ordinary resolved pages receive receipt provenance without runtime bindings' );
$assert( 'blocks-engine/wordpress-site-plan-resolver' === ( $unbound_provenance[0]['stages'][0]['stage'] ?? '' ) && hash( 'sha256', $receipt['plan']['pages'][0]['resolved_block_markup'] ) === ( $unbound_provenance[0]['stages'][0]['output']['sha256'] ?? '' ), 'ordinary page provenance records the resolver output hash' );
$assert( false === ( $receipt['completed']['block_provenance_truncated'] ?? true ) && ! str_contains( (string) wp_json_encode( $unbound_provenance ), (string) $receipt['plan']['pages'][0]['resolved_block_markup'] ), 'provenance uses structural evidence without raw page markup' );

$overlay_css = "/* Static Site Importer provider layout overlay: abcdef123456 */\n.ssi-form-123456789abc > form.jetpack-contact-form__form{display:flex;gap:1rem}\n";
$overlay = array( 'schema' => Static_Site_Importer_Provider_Layout_Overlay::OVERLAY_SCHEMA, 'css' => $overlay_css, 'sha256' => hash( 'sha256', $overlay_css ), 'bytes' => strlen( $overlay_css ) );
$overlay_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'provider-overlay-plan', 'provider_layout_overlays' => array( $overlay, $overlay ) ) );
$overlay_root = $GLOBALS['ssi_plan_root'] . '/provider-overlay-plan';
$assert( 'completed' === $overlay_receipt['status'] && 'completed' === ( $overlay_receipt['completed']['provider_layout_overlays']['status'] ?? '' ), 'provider layout receipt is applied only after stylesheet writes complete' );
$assert( str_contains( (string) file_get_contents( $overlay_root . '/style.css' ), 'provider layout overlay: abcdef123456' ) && str_contains( (string) file_get_contents( $overlay_root . '/assets/css/editor-style.css' ), 'provider layout overlay: abcdef123456' ), 'generated frontend and editor stylesheets contain the deduplicated provider overlay' );
$resumed_overlay_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'provider-overlay-plan', 'provider_layout_overlays' => array( $overlay ) ) );
$resumed_overlay_files = $resumed_overlay_receipt['completed']['provider_layout_overlays']['files'] ?? array();
$assert( 'completed' === $resumed_overlay_receipt['status'] && 'already_satisfied' === ( $resumed_overlay_receipt['completed']['provider_layout_overlays']['status'] ?? '' ) && 2 === count( $resumed_overlay_files ) && array() === array_filter( $resumed_overlay_files, static fn( array $file ): bool => 'already_satisfied' !== ( $file['status'] ?? '' ) ), 'resumed provider overlay reconciles byte-identical stylesheet state with receipt evidence' );
$canonical_overlay_targets = array( 'style.css', 'assets/css/editor-style.css' );
$canonical_overlay_entries = static fn( array $receipt ): array => array_values( array_filter( $receipt['generated_files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) );
$initial_overlay_entries = $canonical_overlay_entries( $overlay_receipt );
$resumed_overlay_entries = $canonical_overlay_entries( $resumed_overlay_receipt );
$assert( 2 === count( $initial_overlay_entries ) && $initial_overlay_entries === $resumed_overlay_entries && $initial_overlay_entries === array_values( array_filter( $overlay_receipt['completed']['files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) ) && $resumed_overlay_entries === array_values( array_filter( $resumed_overlay_receipt['completed']['files'] ?? array(), static fn( array $file ): bool => in_array( $file['target_path'] ?? '', $canonical_overlay_targets, true ) ) ), 'overlay resume preserves compatible canonical stylesheet entries in completed and legacy file receipts' );
$assert( array() === array_filter( $resumed_overlay_entries, static fn( array $file ): bool => ! isset( $file['reconciliation_identity'], $file['hash'], $file['payload_hash'] ) || isset( $file['status'] ) ) && array() === array_filter( $resumed_overlay_entries, static fn( array $file ): bool => ! in_array( $file['hash'], array( hash_file( 'sha256', $overlay_root . '/style.css' ), hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ) ), true ) ), 'canonical stylesheet receipt entries preserve reconciliation compatibility with final overlay bytes' );
$overlay_hashes = array(
	'style.css' => hash_file( 'sha256', $overlay_root . '/style.css' ),
	'assets/css/editor-style.css' => hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ),
);
$conflicting_overlay = $overlay;
$conflicting_overlay['css'] = str_replace( 'gap:1rem', 'gap:2rem', $overlay['css'] );
$conflicting_overlay['sha256'] = hash( 'sha256', $conflicting_overlay['css'] );
$conflicting_overlay['bytes'] = strlen( $conflicting_overlay['css'] );
$conflicting_overlay_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'provider-overlay-plan', 'provider_layout_overlays' => array( $conflicting_overlay ) ) );
$assert( 'rejected' === $conflicting_overlay_receipt['status'] && 'provider_layout_overlay_rejected' === ( $conflicting_overlay_receipt['diagnostics'][0]['reason_code'] ?? '' ) && $overlay_hashes['style.css'] === hash_file( 'sha256', $overlay_root . '/style.css' ) && $overlay_hashes['assets/css/editor-style.css'] === hash_file( 'sha256', $overlay_root . '/assets/css/editor-style.css' ), 'conflicting provider overlay is rejected before either stylesheet changes' );
$forged_overlay = $overlay;
$forged_overlay['css'] = "/* Static Site Importer provider layout overlay: abcdef123456 */\nbody{background:url(https://example.test/x)}\n";
$forged_root = $GLOBALS['ssi_plan_root'] . '/forged-provider-overlay-plan';
$forged_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'forged-provider-overlay-plan', 'provider_layout_overlays' => array( $forged_overlay ) ) );
$assert( 'rejected' === $forged_receipt['status'] && 'provider_layout_overlay_rejected' === ( $forged_receipt['diagnostics'][0]['reason_code'] ?? '' ) && 'not_requested' === ( $forged_receipt['completed']['provider_layout_overlays']['status'] ?? '' ) && ! file_exists( $forged_root ), 'forged provider overlay is rejected before any write claim or file mutation' );
$conflict_root = $GLOBALS['ssi_plan_root'] . '/canonical-file-conflict';
mkdir( $conflict_root, 0777, true );
file_put_contents( $conflict_root . '/theme.json', '{"conflict":true}' );
$canonical_conflict_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'canonical-file-conflict' ) );
$assert( 'rejected' === $canonical_conflict_receipt['status'] && 'file_conflict' === ( $canonical_conflict_receipt['diagnostics'][0]['reason_code'] ?? '' ) && '{"conflict":true}' === file_get_contents( $conflict_root . '/theme.json' ), 'unrelated canonical file conflicts remain rejected and unchanged' );

$explicit_styles_root = $GLOBALS['ssi_plan_root'] . '/explicit-canonical-styles';
$explicit_styles = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'provider_layout_stylesheet_writes' );
$explicit_styles_writes = $explicit_styles->invoke( null, array( 'theme_dir' => $explicit_styles_root, 'theme' => array( 'slug' => 'explicit-canonical-styles' ), 'resolved' => array( 'writes' => array( array( 'target_path' => 'style.css', 'payload' => array( 'encoding' => 'utf8', 'data' => 'body{color:black}' ) ), array( 'target_path' => 'assets/css/editor-style.css', 'payload' => array( 'encoding' => 'utf8', 'data' => '.editor-styles-wrapper{color:black}' ) ) ) ) ), array( $overlay ) );
$assert( is_array( $explicit_styles_writes ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/style.css' ] ?? '', 'body{color:black}' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/assets/css/editor-style.css' ] ?? '', '.editor-styles-wrapper{color:black}' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/style.css' ] ?? '', 'provider layout overlay: abcdef123456' ) && str_contains( $explicit_styles_writes[ $explicit_styles_root . '/assets/css/editor-style.css' ] ?? '', 'provider layout overlay: abcdef123456' ), 'explicit canonical frontend and editor stylesheet payloads derive independent overlay-composed writes' );

$font_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Example+Font:wght@400&amp;display=swap"><style>body{font-family:"Example Font",sans-serif}</style></head><body><main><svg xmlns="http://www.w3.org/2000/svg"><text font-family="Example Font, sans-serif">Label</text></svg></main></body></html>',
		),
	)
)->toArray();
$font_plan = $font_result['source_reports']['wordpress_site_plan'];
$font_materialization = $font_result['source_reports']['materialization_plan']['theme']['font_materialization'];
$font_plan_hash = hash( 'sha256', (string) wp_json_encode( $font_plan, JSON_UNESCAPED_SLASHES ) );
$font_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $font_plan, array( 'slug' => 'font-site-plan', 'font_materialization' => $font_materialization ) );
$font_root = $GLOBALS['ssi_plan_root'] . '/font-site-plan';
$assert( 'completed' === $font_receipt['status'], 'canonical font materialization completes' );
$assert( $font_plan_hash === $font_receipt['plan_hash'], 'font overlay leaves the canonical source plan unchanged' );
$assert( file_exists( $font_root . '/assets/css/fonts.css' ), 'declared font stylesheet is materialized' );
$assert( str_contains( (string) file_get_contents( $font_root . '/assets/css/embedded-fonts.css' ), 'data:font/woff2;base64,' ), 'self-contained font stylesheet is materialized' );
$assert( str_contains( (string) file_get_contents( $font_root . '/functions.php' ), "wp_enqueue_style( 'static-site-importer-embedded-fonts'" ), 'generated theme loads self-contained font stylesheet' );
$font_svg_files = array_values( array_filter( $font_receipt['completed']['font_materialization']['files'] ?? array(), static fn( array $file ): bool => str_ends_with( (string) ( $file['target_path'] ?? '' ), '.svg' ) ) );
$assert( ! empty( $font_svg_files ) && array() === array_filter( $font_svg_files, static fn( array $file ): bool => ! str_contains( (string) file_get_contents( $font_root . '/' . $file['target_path'] ), 'data:font/woff2;base64,' ) ), 'legacy font plans retain self-contained generated SVGs when typed consumers are unavailable' );
$assert( 2 === count( $GLOBALS['ssi_plan_font_requests'] ), 'font materialization fetches one declared stylesheet and one unique payload' );
$assert( Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text style="font-family:\'Example Font\', serif">Label</text></svg>', array( 'Example Font' ) ), 'SVG style declarations match quoted families within fallback lists' );
$assert( Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text font-family="serif, Example Font">Label</text></svg>', array( 'example font' ) ), 'SVG presentation attributes normalize case and fallback-list position' );
$assert( ! Static_Site_Importer_Font_Materializer::svg_uses_font_family( '<svg><text font-family="Example Font Pro, sans-serif">Label</text></svg>', array( 'Example Font' ) ), 'SVG font matching compares complete family tokens instead of prefixes' );

$inter_payload = 'inter-variable-glyph-payload';
$typed_font_plan = array(
	'schema' => 'blocks-engine/php-transformer/font-materialization-plan/v1',
	'webfont_contract' => array(
		'schema' => 'blocks-engine/webfont-materialization/v1',
		'imports' => array( array( 'id' => 'webfont-import-inter', 'state' => 'declared', 'source' => array( 'url' => 'https://fonts.googleapis.com/css2?family=Inter-like:wght@400;700', 'format' => 'css', 'expected_digest' => null, 'observed_digest' => null ) ) ),
		'faces' => array(
			array( 'id' => 'webfont-face-inter-400', 'import_id' => 'webfont-import-inter', 'receipt_id' => 'webfont-receipt-inter-400', 'state' => 'declared', 'family' => 'Inter-like', 'style' => 'normal', 'weight' => array( 'kind' => 'static', 'value' => 400 ), 'axes' => array( 'wght' => array( 'kind' => 'static', 'value' => 400 ) ), 'unicode_ranges' => array() ),
			array( 'id' => 'webfont-face-inter-700', 'import_id' => 'webfont-import-inter', 'receipt_id' => 'webfont-receipt-inter-700', 'state' => 'declared', 'family' => 'Inter-like', 'style' => 'normal', 'weight' => array( 'kind' => 'static', 'value' => 700 ), 'axes' => array( 'wght' => array( 'kind' => 'static', 'value' => 700 ) ), 'unicode_ranges' => array() ),
			array( 'id' => 'webfont-face-inter-variable', 'import_id' => 'webfont-import-inter', 'receipt_id' => 'webfont-receipt-inter-variable', 'state' => 'declared', 'family' => 'Inter-like', 'style' => 'normal', 'weight' => array( 'kind' => 'range', 'min' => 100, 'max' => 900 ), 'axes' => array( 'wght' => array( 'kind' => 'range', 'min' => 100, 'max' => 900 ), 'wdth' => array( 'kind' => 'range', 'min' => 75, 'max' => 125 ) ), 'unicode_ranges' => array( 'U+0000-00FF' ) ),
		),
		'receipts' => array( array( 'id' => 'webfont-receipt-inter-400', 'face_id' => 'webfont-face-inter-400', 'import_id' => 'webfont-import-inter', 'state' => 'pending_browser_readiness' ), array( 'id' => 'webfont-receipt-inter-700', 'face_id' => 'webfont-face-inter-700', 'import_id' => 'webfont-import-inter', 'state' => 'pending_browser_readiness' ), array( 'id' => 'webfont-receipt-inter-variable', 'face_id' => 'webfont-face-inter-variable', 'import_id' => 'webfont-import-inter', 'state' => 'pending_browser_readiness' ) ),
		'browser_readiness' => array( 'state' => 'required', 'required_receipt_ids' => array( 'webfont-receipt-inter-400', 'webfont-receipt-inter-700', 'webfont-receipt-inter-variable' ) ),
		'diagnostics' => array(),
	),
);
$typed_svg_writes = array_values( array_filter( $font_plan['writes'], static fn( array $write ): bool => str_ends_with( (string) $write['target_path'], '.svg' ) ) );
$typed_svg_write = $typed_svg_writes[0] ?? array();
$typed_svg_hash = hash( 'sha256', $typed_svg_write['payload']['data'] );
$typed_svg_face_ids = array( 'webfont-face-inter-400' );
$typed_svg_source_path = $typed_svg_write['source_path'];
$typed_svg_write_path = $typed_svg_write['target_path'];
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
$typed_font_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $font_plan, array( 'slug' => 'typed-font-site-plan', 'font_materialization' => $typed_font_plan ) );
$typed_font_root = $GLOBALS['ssi_plan_root'] . '/typed-font-site-plan';
$typed_faces = $typed_font_receipt['completed']['font_materialization']['required_faces'] ?? array();
$assert( 'completed' === $typed_font_receipt['status'] && 3 === count( $typed_faces ) && array( 'kind' => 'range', 'min' => 100, 'max' => 900 ) === ( $typed_faces[2]['weight'] ?? null ) && array( 'kind' => 'range', 'min' => 75, 'max' => 125 ) === ( $typed_faces[2]['axes']['wdth'] ?? null ) && array( 'U+0000-00FF' ) === ( $typed_faces[2]['unicode_ranges'] ?? null ), 'nested producer contract retains static and range weights, all axes, unicode ranges, and receipt provenance' );
$assert( $inter_payload === file_get_contents( $typed_font_root . '/' . $typed_faces[0]['assets'][0]['target_path'] ) && $inter_payload === file_get_contents( $typed_font_root . '/' . $typed_faces[1]['assets'][0]['target_path'] ), 'typed font assets are locally materialized as verified binary payloads without network-dependent test fixtures' );
$typed_css = (string) file_get_contents( $typed_font_root . '/assets/css/embedded-fonts.css' );
$assert( str_contains( $typed_css, 'font-weight:100 900' ) && str_contains( $typed_css, 'font-stretch:75% 125%' ) && str_contains( $typed_css, 'unicode-range:U+0000-00FF' ) && ! str_contains( $typed_css, 'fonts.example.test' ), 'producer font faces preserve all declared axes and unicode ranges while rewriting only local sources' );
$typed_readiness = (string) file_get_contents( $typed_font_root . '/assets/js/font-readiness.js' );
$assert( str_contains( $typed_readiness, 'document.fonts.load' ) && str_contains( $typed_readiness, 'SSI glyph evidence') && str_contains( $typed_readiness, 'status:"missing"' ), 'required typed faces install a glyph-based document.fonts readiness probe with retained missing evidence' );
$typed_svg_receipts = $typed_font_receipt['completed']['font_materialization']['svg_receipts'] ?? array();
$assert( 1 === count( $typed_svg_receipts ) && hash( 'sha256', file_get_contents( $typed_font_root . '/' . $typed_svg_write['target_path'] ) ) === ( $typed_svg_receipts[0]['output_sha256'] ?? '' ) && str_contains( (string) file_get_contents( $typed_font_root . '/' . $typed_svg_write['target_path'] ), 'data:font/woff2;base64,' ), 'final write verification accepts the declared SVG change only through its hash-bound materialization receipt' );
$invalid_typed_plan = $typed_font_plan;
$invalid_typed_plan['webfont_contract']['imports'][0]['source']['expected_digest'] = 'sha256:' . str_repeat( '0', 64 );
$invalid_typed_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $font_plan, array( 'slug' => 'invalid-typed-font-site-plan', 'font_materialization' => $invalid_typed_plan ) );
$assert( 'partial' === $invalid_typed_receipt['status'] && 'static_site_importer_font_materialization_producer_stylesheet_failed' === ( $invalid_typed_receipt['errors'][0]['code'] ?? '' ), 'required producer source digest mismatch fails explicitly before theme activation' );
$assert( 'producer_stylesheet_digest_mismatch' === ( $invalid_typed_receipt['diagnostics'][1]['reason_code'] ?? '' ), 'font materialization receipts retain the producer failure reason instead of only the generic error code' );

$font_without_svg_result = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Example+Font:wght@400&amp;display=swap"><style>body{font-family:"Example Font",sans-serif}</style></head><body><main>Text</main></body></html>',
		),
	)
)->toArray();
$font_without_svg_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize(
	$font_without_svg_result['source_reports']['wordpress_site_plan'],
	array(
		'slug'                 => 'font-site-plan-without-svg',
		'font_materialization' => $font_without_svg_result['source_reports']['materialization_plan']['theme']['font_materialization'],
	)
);
$font_without_svg_root = $GLOBALS['ssi_plan_root'] . '/font-site-plan-without-svg';
$assert( 'completed' === $font_without_svg_receipt['status'], 'canonical font materialization completes without SVG consumers' );
$assert( str_contains( (string) file_get_contents( $font_without_svg_root . '/assets/css/embedded-fonts.css' ), 'data:font/woff2;base64,' ), 'page fonts are self-contained without SVG consumers' );
$assert( str_contains( (string) file_get_contents( $font_without_svg_root . '/functions.php' ), "wp_enqueue_style( 'static-site-importer-embedded-fonts'" ), 'page fonts load without SVG consumers' );
$assert( 7 === count( $GLOBALS['ssi_plan_font_requests'] ), 'each successful and rejected font materialization resolves only its declared stylesheet or typed payload URLs' );

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
$nested_routes = array_column( $nested_route_result['source_reports']['wordpress_site_plan']['routes'], 'target_path', 'source_path' );
$assert( 3 === count( $nested_routes ) && '/' === ( $nested_routes['website/index.html'] ?? '' ) && '/api' === ( $nested_routes['website/api/index.html'] ?? '' ) && '/lifecycle' === ( $nested_routes['website/lifecycle/index.html'] ?? '' ), 'nested index documents retain distinct routes when the declared root entrypoint is ordered last' );

$product_grid_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html' => '<main><ul class="products"><li><article class="product-card"><h3>Tour Tee</h3><p>Heavy cotton shirt.</p><div class="price">$30</div><button class="add-to-cart">Add to cart</button></article></li><li><article class="product-card"><h3>Signed CD</h3><p>Hand-signed disc.</p><div class="price">$15</div><button class="add-to-cart">Add to cart</button></article></li></ul></main>',
	),
);
$product_grid_plan = ( new ArtifactCompiler() )->compile( $product_grid_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$bridge_product_grid = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'bridge_product_grid_findings_to_runtime_declarations' );
$bridged_product_grid_plan = $bridge_product_grid->invoke( null, $product_grid_plan );
$bridged_declarations = $bridged_product_grid_plan['runtime_declarations'] ?? array();
$assert( 2 === count( $bridged_declarations ) && 'shop' === ( $bridged_declarations[0]['capability'] ?? '' ) && 'products' === ( $bridged_declarations[1]['type'] ?? '' ), 'active Blocks Engine product-grid findings bridge into explicit v2 commerce declarations' );
$assert( true === in_array( 'entity_collection:products', $bridged_declarations[0]['required_for'] ?? array(), true ), 'bridged product entities retain the required commerce dependency relationship' );
$assert( array( 'tour-tee', 'signed-cd' ) === array_column( $bridged_declarations[1]['payload']['entities'] ?? array(), 'slug' ), 'bridge preserves product-grid evidence as normalized Woo entity rows' );
$bridged_lifecycle = ( new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'prepare_wordpress_site_plan_lifecycle' ) )->invoke( null, $bridged_product_grid_plan, array() );
$assert( 'runtime_declarations' === ( $bridged_lifecycle['status'] ?? '' ) && true === ( reset( $bridged_lifecycle['entities'] )['required'] ?? false ), 'bridged product entities enter the required canonical seeding lifecycle' );

$entity_artifact = $artifact;
$entity_search = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"tagName":"button","text":"Add"} --><div class="wp-block-button"><button type="button" class="wp-block-button__link wp-element-button">Add</button></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$entity_artifact['files']['index.html'] = '<main><h1>Home</h1><div class="wp-block-buttons"><button>Add</button></div></main>';
$entity_artifact['runtime_declarations'] = array(
	array( 'kind' => 'dependency', 'capability' => 'shop', 'source_path' => 'index.html', 'required_for' => array( 'entity_collection:products' ) ),
	array( 'kind' => 'entity_collection', 'type' => 'products', 'source_path' => 'index.html', 'payload' => array( 'schema' => 'generic/products/v1', 'entities' => array( array( 'name' => 'Aero Mug', 'slug' => 'aero-mug', 'regular_price' => '24', 'source_selectors' => array( '.product-card' ), 'bindings' => array( array( 'schema' => 'generic/block-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $entity_search, 'occurrence' => 1, 'role' => 'commerce_controls', 'superseded_runtime_selectors' => array( '.add-to-cart' ) ) ) ) ) ) ),
);
$entity_plan = ( new ArtifactCompiler() )->compile( $entity_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$prepare_lifecycle = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'prepare_wordpress_site_plan_lifecycle' );
$entity_lifecycle = $prepare_lifecycle->invoke( null, $entity_plan, array() );
$assert( 'runtime_declarations' === ( $entity_lifecycle['status'] ?? '' ), 'v2 entity declarations enter the active SSI runtime lifecycle' );
$assert( 'woocommerce_simple_product' === ( $entity_lifecycle['entities'][ $entity_plan['runtime_declarations'][1]['reconciliation_identity'] ]['adapter']['id'] ?? '' ) || 'woocommerce_simple_product' === ( reset( $entity_lifecycle['entities'] )['adapter']['id'] ?? '' ), 'product collections resolve through the configured WooCommerce adapter' );
$prepared_entity = reset( $entity_lifecycle['entities'] );
$assert( 'Aero Mug' === ( $prepared_entity['manifest']['products'][0]['name'] ?? '' ) && true === ( $prepared_entity['required'] ?? false ), 'v2 product rows validate and retain their required dependency relationship' );

// Provider declarations keep portable asset tokens, while resolver output carries
// destination-specific URLs. Binding preflight must use the latter without
// mutating the canonical declaration retained by the lifecycle.
$token_anchor = '<!-- wp:buttons --><div><img src="{{wordpress-site-plan:asset:hero}}"></div><!-- /wp:buttons -->';
$resolved_anchor = '<!-- wp:buttons --><div><img src="https://example.test/wp-content/themes/entity-plan/assets/hero.svg"></div><!-- /wp:buttons -->';
$token_entity_declaration_id = (string) array_key_first( $entity_lifecycle['entities'] );
$token_lifecycle = $entity_lifecycle;
$token_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] = $token_anchor;
$resolved_declaration = $entity_plan['runtime_declarations'][1];
$resolved_declaration['payload']['entities'][0]['bindings'][0]['search_block_markup'] = $resolved_anchor;
$resolved_binding_plan = array(
	'pages' => array( array( 'source_path' => 'index.html', 'resolved_block_markup' => '<main>' . $resolved_anchor . '</main>' ) ),
	'runtime_declarations' => array( $resolved_declaration ),
);
$resolve_binding_manifests = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'with_resolved_runtime_binding_manifests' );
$preflight_bindings = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'preflight_runtime_entity_binding_anchors' );
$assert( is_wp_error( $preflight_bindings->invoke( null, $resolved_binding_plan, $token_lifecycle, array() ) ), 'canonical token anchors fail against destination-specific resolved page URLs before projection' );
$resolved_lifecycle = $resolve_binding_manifests->invoke( null, $token_lifecycle, $resolved_binding_plan );
$assert( $token_anchor === ( $token_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] ?? '' ) && $resolved_anchor === ( $resolved_lifecycle['entities'][ $token_entity_declaration_id ]['manifest']['products'][0]['bindings'][0]['search_block_markup'] ?? '' ), 'resolved binding projection changes only lifecycle binding anchors and preserves canonical declarations' );
$assert( true === $preflight_bindings->invoke( null, $resolved_binding_plan, $resolved_lifecycle, array() ), 'resolved provider binding anchors match the exact page markup consumed by materialization' );
$assert( $token_lifecycle === $resolve_binding_manifests->invoke( null, $token_lifecycle, array( 'pages' => $resolved_binding_plan['pages'] ) ), 'plans without resolved runtime declarations retain canonical lifecycle behavior' );

$form_declaration_id = 'form-topology-runtime';
$topology_form = array(
	'selector' => 'form.contact', 'source_path' => 'index.html', 'form' => array( 'class' => 'contact' ),
	'controls' => array( array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'label' => 'Name' ), array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ) ),
	'control_topology' => array(
		'schema' => 'generic/form-control-topology/v1', 'max_depth' => 8, 'max_nodes' => 128, 'truncated' => false,
		'nodes' => array(
			array( 'id' => 'wrapper-0', 'kind' => 'wrapper', 'parent' => null, 'order' => 0, 'depth' => 0, 'tag' => 'section', 'class' => 'row-2', 'source_id' => 'contact-row' ),
			array( 'id' => 'control-0', 'kind' => 'control', 'parent' => 'wrapper-0', 'order' => 0, 'depth' => 1, 'control' => 0 ),
			array( 'id' => 'control-1', 'kind' => 'control', 'parent' => null, 'order' => 1, 'depth' => 0, 'control' => 1 ),
		),
	),
);
$runtime_form_plan = $entity_plan;
$runtime_form_plan['runtime_declarations'] = array(
	array( 'kind' => 'dependency', 'capability' => 'form', 'source_path' => 'index.html', 'required_for' => array( 'entity_collection:forms' ), 'reconciliation_identity' => 'form-topology-dependency' ),
	array( 'kind' => 'entity_collection', 'type' => 'forms', 'source_path' => 'index.html', 'reconciliation_identity' => $form_declaration_id, 'payload' => array( 'schema' => 'generic/forms/v1', 'entities' => array( $topology_form ) ) ),
);
$runtime_form_lifecycle = $prepare_lifecycle->invoke( null, $runtime_form_plan, array() );
$runtime_form_manifest = $runtime_form_lifecycle['entities'][ $form_declaration_id ]['manifest']['forms'][0] ?? array();
$assert( 'section' === ( $runtime_form_manifest['control_topology']['nodes'][0]['tag'] ?? '' ), 'runtime declarations retain validated form topology' );

$unknown_topology_form = $topology_form;
$unknown_topology_form['control_topology']['untrusted'] = 'reject-me';
$unknown_topology_plan = $runtime_form_plan;
$unknown_topology_plan['runtime_declarations'][1]['payload']['entities'] = array( $unknown_topology_form );
$unknown_topology_lifecycle = $prepare_lifecycle->invoke( null, $unknown_topology_plan, array() );
$assert( is_wp_error( $unknown_topology_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $unknown_topology_lifecycle->get_error_code(), 'unknown runtime topology keys are rejected before provider traversal' );

$self_referential_form = $topology_form;
$self_referential_form['control_topology']['nodes'][0]['parent'] = 'wrapper-0';
$self_referential_plan = $runtime_form_plan;
$self_referential_plan['runtime_declarations'][1]['payload']['entities'] = array( $self_referential_form );
$self_referential_lifecycle = $prepare_lifecycle->invoke( null, $self_referential_plan, array() );
$assert( is_wp_error( $self_referential_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $self_referential_lifecycle->get_error_code(), 'self-referential runtime topology is rejected before provider traversal' );

$duplicate_topology_form = $topology_form;
$duplicate_topology_form['control_topology']['nodes'][1]['id'] = 'wrapper-0';
$duplicate_topology_form['control_topology']['nodes'][1]['kind'] = 'wrapper';
unset( $duplicate_topology_form['control_topology']['nodes'][1]['control'] );
$duplicate_topology_plan = $runtime_form_plan;
$duplicate_topology_plan['runtime_declarations'][1]['payload']['entities'] = array( $duplicate_topology_form );
$duplicate_topology_lifecycle = $prepare_lifecycle->invoke( null, $duplicate_topology_plan, array() );
$assert( is_wp_error( $duplicate_topology_lifecycle ) && 'static_site_importer_runtime_entity_invalid' === $duplicate_topology_lifecycle->get_error_code(), 'duplicate runtime topology identifiers are rejected before provider traversal' );

$binding_method = new ReflectionMethod( Static_Site_Importer_Theme_Generator::class, 'runtime_entity_bindings' );
$entity_declaration_id = (string) array_key_first( $entity_lifecycle['entities'] );
$entity_bindings = $binding_method->invoke( null, $entity_lifecycle, array( $entity_declaration_id => array( 'products' => array( array( 'id' => 42, 'slug' => 'aero-mug', 'status' => 'created' ) ) ) ) );
$assert( ! is_wp_error( $entity_bindings ) && '[add_to_cart id="42"]' === trim( strip_tags( $entity_bindings[0]['replacement_block_markup'] ?? '' ) ), 'provider result resolves into a canonical runtime entity binding' );
$assert( array( '.add-to-cart' ) === ( $entity_bindings[0]['superseded_runtime_selectors'] ?? null ), 'provider binding retains its explicit runtime-selector coverage' );
$waived_bindings = $binding_method->invoke( null, $entity_lifecycle, array( $entity_declaration_id => array( 'status' => 'waived', 'provider' => 'woocommerce' ) ) );
$assert( array() === $waived_bindings, 'explicit provider waiver retains static fallback without requiring provider markup' );

$binding_artifact = array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<main><h1>Binding</h1><p>Replace me</p></main>' ) );
$binding_plan = ( new ArtifactCompiler() )->compile( $binding_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$binding_search = '<!-- wp:paragraph {"content":"Replace me"} --><p>Replace me</p><!-- /wp:paragraph -->';
$binding_replacement = '<!-- wp:shortcode -->[add_to_cart id="42"]<!-- /wp:shortcode -->';
$binding_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $binding_plan, array( 'slug' => 'binding-plan', 'runtime_entity_bindings' => array( array( 'schema' => 'static-site-importer/runtime-entity-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $binding_search, 'replacement_block_markup' => $binding_replacement, 'occurrence' => 1, 'role' => 'commerce_controls', 'declaration_id' => $entity_declaration_id, 'reconciliation_identity' => hash( 'sha256', 'binding-test' ), 'superseded_runtime_selectors' => array( '.add-to-cart' ) ) ) ) );
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
$runtime_diagnostics = array( array( 'code' => 'preserved_runtime_island', 'source_path' => 'index.html', 'selector' => '.add-to-cart' ), array( 'code' => 'preserved_runtime_island', 'source_path' => 'index.html', 'selector' => '.qty-btn' ), array( 'code' => 'preserved_runtime_island', 'source_path' => 'other.html', 'selector' => '.add-to-cart' ) );
$assert( array( '.qty-btn', '.add-to-cart' ) === array_column( $reconcile_diagnostics->invoke( null, $runtime_diagnostics, $binding_receipt ), 'selector' ), 'completed provider coverage removes only the matching page runtime finding and preserves same-selector findings on other pages' );
$prepared_binding_receipt = $binding_receipt;
foreach ( $prepared_binding_receipt['completed']['runtime_declarations']['entity_bindings'] as &$prepared_binding_report ) $prepared_binding_report['status'] = 'prepared';
unset( $prepared_binding_report );
$assert( 3 === count( $reconcile_diagnostics->invoke( null, $runtime_diagnostics, $prepared_binding_receipt ) ), 'unpersisted provider bindings never suppress runtime findings' );
$invalid_coverage_binding = array( 'schema' => 'static-site-importer/runtime-entity-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $binding_search, 'replacement_block_markup' => $binding_replacement, 'occurrence' => 1, 'role' => 'commerce_controls', 'declaration_id' => $entity_declaration_id, 'reconciliation_identity' => hash( 'sha256', 'invalid-coverage-binding' ), 'superseded_runtime_selectors' => '.add-to-cart' );
$invalid_coverage_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $binding_plan, array( 'slug' => 'invalid-coverage-plan', 'runtime_entity_bindings' => array( $invalid_coverage_binding ) ) );
$assert( 'rejected' === $invalid_coverage_receipt['status'] && 'runtime_entity_binding_invalid' === ( $invalid_coverage_receipt['errors'][0]['code'] ?? '' ), 'direct materializer callers cannot forge malformed runtime-selector coverage' );
$duplicate_binding_plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => '<main><p>Same</p><p>Same</p></main>' ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$duplicate_search = '<!-- wp:paragraph {"content":"Same"} --><p>Same</p><!-- /wp:paragraph -->';
$duplicate_binding_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $duplicate_binding_plan, array( 'slug' => 'duplicate-binding-plan', 'runtime_entity_bindings' => array( array( 'schema' => 'static-site-importer/runtime-entity-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $duplicate_search, 'replacement_block_markup' => '<!-- wp:shortcode -->[add_to_cart id="42"]<!-- /wp:shortcode -->', 'occurrence' => 1, 'role' => 'commerce_controls', 'declaration_id' => 'products', 'reconciliation_identity' => hash( 'sha256', 'duplicate-binding-1' ) ), array( 'schema' => 'static-site-importer/runtime-entity-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => $duplicate_search, 'replacement_block_markup' => '<!-- wp:shortcode -->[add_to_cart id="43"]<!-- /wp:shortcode -->', 'occurrence' => 2, 'role' => 'commerce_controls', 'declaration_id' => 'products', 'reconciliation_identity' => hash( 'sha256', 'duplicate-binding-2' ) ) ) ) );
$duplicate_markup = $duplicate_binding_receipt['completed']['materialized_pages']['index.html']['block_markup'] ?? '';
$assert( str_contains( $duplicate_markup, '[add_to_cart id="42"]' ) && str_contains( $duplicate_markup, '[add_to_cart id="43"]' ), 'duplicate markup anchors resolve by descending deterministic occurrence' );
$invalid_binding = array( 'schema' => 'static-site-importer/runtime-entity-binding/v1', 'source_path' => 'index.html', 'search_block_markup' => '<!-- wp:paragraph --><p>Missing</p><!-- /wp:paragraph -->', 'replacement_block_markup' => $binding_replacement, 'occurrence' => 1, 'role' => 'commerce_controls', 'declaration_id' => $entity_declaration_id, 'reconciliation_identity' => hash( 'sha256', 'invalid-binding-test' ) );
$invalid_binding_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $binding_plan, array( 'slug' => 'invalid-binding-plan', 'runtime_entity_bindings' => array( $invalid_binding ) ) );
$assert( 'rejected' === $invalid_binding_receipt['status'] && 'runtime_entity_binding_cardinality_mismatch' === ( $invalid_binding_receipt['errors'][0]['code'] ?? '' ), 'missing or ambiguous provider anchors fail before page writes' );

$publication_svg = '<svg xmlns="http://www.w3.org/2000/svg"><text style="font-family:Example">Example</text></svg>';
$publication_css = '@font-face{font-family:Example;src:url(font.woff2)}';
$publication_font = 'local-font-bytes';
$publication_token = 'asset-' . substr( hash( 'sha256', 'assets/assets/font.woff2' ), 0, 16 );
$publication_face = '@font-face{font-family:Example;src:url({{wordpress-site-plan:asset:' . $publication_token . '}});}';
$publication_content = '<svg xmlns="http://www.w3.org/2000/svg"><text style="font-family:Example">Example</text><style>' . $publication_face . '</style></svg>';
$publication_input = array( 'css' => array( array( 'source_path' => 'assets/fonts.css', 'content_hash' => hash( 'sha256', $publication_css ), 'font_faces' => array( $publication_face ) ) ), 'fonts' => array( array( 'source_path' => 'assets/font.woff2', 'content_hash' => hash( 'sha256', base64_encode( $publication_font ) ) ) ) );
$publication_declaration = array(
	'kind' => 'asset_publication', 'type' => 'asset', 'source_path' => 'assets/logo.svg',
	'provenance' => array( 'source_path' => 'assets/logo.svg', 'source' => 'files', 'hash' => hash( 'sha256', $publication_svg ), 'mime_type' => 'image/svg+xml', 'role' => 'image', 'bytes' => strlen( $publication_svg ) ),
	'destination' => array( 'capability' => 'asset_materialization', 'required' => true ), 'source_role' => 'image', 'mime_type' => 'image/svg+xml',
	'source_hash' => hash( 'sha256', $publication_svg ), 'expected_content_hash' => hash( 'sha256', $publication_content ),
	'sanitization' => array( 'schema' => 'generic/svg-sanitization/v1', 'input_hash' => hash( 'sha256', $publication_svg ) ),
	'reference_targets' => array( array( 'target_path' => 'assets/assets/fonts.css', 'write_reconciliation_identity' => hash( 'sha256', "wordpress-site-plan/write/v2\nassets/fonts.css\nassets/assets/fonts.css" ), 'token' => $publication_token, 'count' => 1, 'context' => 'css_url' ) ),
	'transformation' => array( 'kind' => 'svg_font_enrichment', 'css_source_paths' => array( 'assets/fonts.css' ), 'font_source_paths' => array( 'assets/font.woff2' ), 'input_hash' => RuntimeDeclarations::hash( $publication_input ), 'expected_content_hash' => hash( 'sha256', $publication_content ) ),
);
$publication_artifact = array( 'entrypoint' => 'index.html', 'runtime_declarations' => array( $publication_declaration ), 'files' => array( 'index.html' => '<main><img src="assets/logo.svg"></main>', 'assets/logo.svg' => $publication_svg, 'assets/fonts.css' => $publication_css, 'assets/font.woff2' => $publication_font ) );
$publication_plan = ( new ArtifactCompiler() )->compile( $publication_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$publication_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $publication_plan, array( 'slug' => 'publication-plan' ) );
$publication_id = $publication_plan['runtime_declarations'][0]['reconciliation_identity'];
$publication_report = $publication_receipt['completed']['runtime_declarations']['asset_publications'][ $publication_id ] ?? array();
$publication_file = $GLOBALS['ssi_plan_root'] . '/publication-plan/assets/assets/logo.svg';
$assert( 'completed' === $publication_receipt['status'] && 'completed' === ( $publication_report['status'] ?? '' ), 'required asset publication capability completes and is receipt-owned' );
$assert( hash_file( 'sha256', $publication_file ) === ( $publication_report['actual_content_hash'] ?? '' ) && $publication_plan['runtime_declarations'][0]['expected_content_hash'] === ( $publication_report['expected_content_hash'] ?? '' ), 'publication receipt proves canonical and resolved content integrity' );
$assert( str_contains( file_get_contents( $publication_file ), 'https://example.test/wp-content/themes/publication-plan/assets/assets/font.woff2' ), 'font-bearing SVG resolves only its declared local font URL' );

$GLOBALS['ssi_plan_options'] = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before', 'use_smilies' => true );
$preview = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true ) );
$assert( 'completed' === $preview['status'], 'preview materialization completes' );
$assert( array( 'canonical_validations' => 1, 'plan_resolutions' => 1, 'destination_preflights' => 2, 'immutable_projection_reused' => true ) === ( $preview['preparation'] ?? array() ), 'materialization reuses one immutable projection while repeating destination preflight' );
$assert( 'posts' === $GLOBALS['ssi_plan_options']['show_on_front'] && ! isset( $GLOBALS['ssi_plan_options']['stylesheet'] ), 'activate=false preserves runtime options' );
$activated = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'activate' => true, 'site_title' => 'Activated Plan' ) );
$assert( 'site-plan' === $GLOBALS['ssi_plan_options']['stylesheet'] && 'page' === $GLOBALS['ssi_plan_options']['show_on_front'] && 'Activated Plan' === $GLOBALS['ssi_plan_options']['blogname'], 'activate=true applies theme title and reading policy' );

// disable_smilies (issue #780): non-activating import must not touch the global option.
$GLOBALS['ssi_plan_options'] = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before', 'use_smilies' => true );
$receipt_default = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true ) );
$assert( true === ( $receipt_default['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ), 'disable-smilies-defaults-requested-true' );
$assert( false === ( $receipt_default['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-not-applied-without-activate' );
$assert( true === $GLOBALS['ssi_plan_options']['use_smilies'], 'non-activating-import-preserves-use-smilies' );

// Explicit opt-out, non-activating: requested and applied both false.
$receipt_off = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'disable_smilies' => false ) );
$assert( false === ( $receipt_off['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && false === ( $receipt_off['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-false-requested-and-applied-false' );

// Activating import with default policy flips the option so literal :) stays text.
$activated_smilies = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'activate' => true, 'site_title' => 'Activated Plan' ) );
$assert( false === $GLOBALS['ssi_plan_options']['use_smilies'], 'activating-import-sets-use-smilies-false' );
$assert( 'Hello :)' === convert_smilies( 'Hello :)' ), 'convert-smilies-output-unchanged-when-disabled' );
$assert( true === ( $activated_smilies['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && true === ( $activated_smilies['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'activating-import-records-requested-and-applied' );

// Explicit opt-out, activating: option untouched, policy not applied.
$GLOBALS['ssi_plan_options'] = array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'blogname' => 'Before', 'use_smilies' => true );
$activated_off = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'activate' => true, 'disable_smilies' => false, 'site_title' => 'Activated Off' ) );
$assert( true === $GLOBALS['ssi_plan_options']['use_smilies'], 'activate-with-disable-smilies-false-keeps-smilies' );
$assert( false === ( $activated_off['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && false === ( $activated_off['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'disable-smilies-false-not-applied-on-activate' );

// Repeated activating import: use_smilies already false, update_option returns false (unchanged value).
$GLOBALS['ssi_plan_options']['use_smilies'] = false;
$receipt_repeat_policy = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan', 'overwrite' => true, 'activate' => true, 'site_title' => 'Repeat Policy' ) );
$assert( 'completed' === $receipt_repeat_policy['status'], 'repeated-activating-import-completes' );
$assert( true === ( $receipt_repeat_policy['completed']['runtime_policy']['disable_smilies']['requested'] ?? null ) && true === ( $receipt_repeat_policy['completed']['runtime_policy']['disable_smilies']['applied'] ?? null ), 'repeated-activating-import-records-requested-and-applied' );
$assert( false === $GLOBALS['ssi_plan_options']['use_smilies'], 'repeated-activating-import-keeps-use-smilies-false' );

$repeat = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'site-plan' ) );
$assert( 'completed' === $repeat['status'], 'reconciliation repeat completes' );
$assert( count( $GLOBALS['ssi_plan_posts'] ) === count( $plan['pages'] ), 'reconciliation preserves source page identity' );

$before_posts = count( $GLOBALS['ssi_plan_posts'] );
$before_files = count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() );
$invalid = $plan;
$invalid['schema'] = 'blocks-engine/wordpress-site-plan/v1';
$rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $invalid, array( 'slug' => 'reject' ) );
$assert( 'rejected' === $rejected['status'], 'invalid plan is rejected' );
$assert( $before_posts === count( $GLOBALS['ssi_plan_posts'] ), 'invalid plan creates no posts' );
$assert( $before_files === count( glob( $GLOBALS['ssi_plan_root'] . '/reject/**/*' ) ?: array() ), 'invalid plan writes no files' );

$tampered_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $plan, array( 'slug' => 'tampered-prepared', 'overwrite' => true ) );
$tampered_prepared['base_resolved']['pages'][0]['resolved_block_markup'] .= '<p>tampered</p>';
$tampered_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $tampered_prepared );
$assert( 'rejected' === $tampered_result['status'] && 'prepared_projection_changed' === ( $tampered_result['diagnostics'][0]['reason_code'] ?? '' ), 'changed immutable prepared projections are rejected before mutation' );

$destination_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $plan, array( 'slug' => 'changed-prepared-destination', 'overwrite' => true ) );
symlink( sys_get_temp_dir(), $GLOBALS['ssi_plan_root'] . '/changed-prepared-destination' );
$destination_changed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize_prepared( $destination_prepared );
unlink( $GLOBALS['ssi_plan_root'] . '/changed-prepared-destination' );
$assert( 'rejected' === $destination_changed['status'] && 'unsafe_theme_destination' === ( $destination_changed['diagnostics'][0]['reason_code'] ?? '' ), 'mutable destination safety is rechecked immediately before writes' );

$unsafe = $GLOBALS['ssi_plan_root'] . '/unsafe';
mkdir( $unsafe, 0777, true );
symlink( sys_get_temp_dir(), $unsafe . '/assets' );
$unsafe_result = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'unsafe', 'overwrite' => true ) );
$assert( 'rejected' === $unsafe_result['status'], 'unsafe destination is rejected' );
$assert( 'unsafe_destination_path' === $unsafe_result['diagnostics'][0]['reason_code'], 'unsafe destination is diagnosed' );

$external_dynamic_artifact = $artifact;
$external_dynamic_artifact['files']['index.html'] .= '<script src="https://cdn.example.test/runtime.js"></script>';
$external_dynamic_plan = ( new ArtifactCompiler() )->compile( $external_dynamic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
WordPressSitePlan::assertValid( $external_dynamic_plan );
$assert( 'not_proven' === $external_dynamic_plan['reference_semantics']['dynamic_client_assets']['status'], 'compiler marks external dynamic scripts as not proven' );

$dynamic_before_posts   = $GLOBALS['ssi_plan_posts'];
$dynamic_before_meta    = $GLOBALS['ssi_plan_meta'];
$dynamic_before_options = $GLOBALS['ssi_plan_options'];
$dynamic_prepared = Static_Site_Importer_WordPress_Site_Plan_Materializer::prepare( $external_dynamic_plan, array( 'slug' => 'external-dynamic-plan' ) );
$assert( 'rejected' === $dynamic_prepared['status'], 'external dynamic scripts reject during preparation' );
$assert( 'WordPress site plan cannot prove dynamic client asset references.' === $dynamic_prepared['receipt']['diagnostics'][0]['reason_code'], 'preparation preserves the canonical destination rejection reason' );
$assert( $dynamic_before_posts === $GLOBALS['ssi_plan_posts'] && $dynamic_before_meta === $GLOBALS['ssi_plan_meta'] && $dynamic_before_options === $GLOBALS['ssi_plan_options'], 'preparation rejects external dynamic scripts before page or option mutation' );
$assert( ! is_dir( $GLOBALS['ssi_plan_root'] . '/external-dynamic-plan' ), 'preparation rejects external dynamic scripts before file mutation' );

$dynamic_rejected = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $external_dynamic_plan, array( 'slug' => 'external-dynamic-plan' ) );
$assert( 'rejected' === $dynamic_rejected['status'], 'external dynamic scripts reject during materialization' );
$assert( 'WordPress site plan cannot prove dynamic client asset references.' === $dynamic_rejected['diagnostics'][0]['reason_code'], 'materialization preserves the canonical destination rejection reason' );
$assert( $dynamic_before_posts === $GLOBALS['ssi_plan_posts'] && $dynamic_before_meta === $GLOBALS['ssi_plan_meta'] && $dynamic_before_options === $GLOBALS['ssi_plan_options'], 'materialization rejects external dynamic scripts before page or option mutation' );
$assert( ! is_dir( $GLOBALS['ssi_plan_root'] . '/external-dynamic-plan' ), 'materialization rejects external dynamic scripts before file mutation' );

$dynamic_allowed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $external_dynamic_plan, array( 'slug' => 'allowed-external-dynamic-plan', 'require_proven_dynamic_client_assets' => false ) );
$assert( 'completed' === $dynamic_allowed['status'], 'explicit policy can preserve unproven dynamic client scripts' );

$dynamic_artifact = $artifact;
$dynamic_artifact['files']['index.html'] .= '<script src="assets/site.js"></script>';
$dynamic_artifact['files']['assets/site.js'] = 'window.sitePlan = true;';
$dynamic_plan = ( new ArtifactCompiler() )->compile( $dynamic_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$dynamic_completed = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $dynamic_plan, array( 'slug' => 'dynamic-plan' ) );
$assert( 'completed' === $dynamic_completed['status'], 'declared static local scripts are proven and materialize' );

$many_files = array( 'index.html' => '<main><h1>Index</h1></main>' );
for ( $index = 1; $index <= 50; ++$index ) {
	$many_files[ 'page-' . $index . '.html' ] = '<main><h1>Page ' . $index . '</h1></main>';
}
$many_plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => $many_files ) )->toArray()['source_reports']['wordpress_site_plan'];
$many_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $many_plan, array( 'slug' => 'many-pages-plan' ) );
$assert( 51 === ( $many_receipt['completed']['block_provenance_count'] ?? 0 ) && 50 === count( $many_receipt['completed']['block_provenance'] ?? array() ) && true === ( $many_receipt['completed']['block_provenance_truncated'] ?? false ), 'receipt enforces the provenance cap before downstream projection' );

$provider_receipt_diagnostics = Static_Site_Importer_Diagnostic_Contract::build(
	array(
		'materialization_receipt' => array(
			'schema'    => 'static-site-importer/materialization-receipt/v1',
			'status'    => 'completed',
			'plan_hash' => 'provider-plan-hash',
			'completed' => array(
				'block_provenance_count' => 1,
				'block_provenance'       => array(
					array(
						'source'   => array( 'schema' => 'static-site-importer/page-provenance/v1', 'source_path' => 'index.html', 'reconciliation_identity' => 'page-index', 'raw_source_html' => '<main>provider source markup</main>', 'unknown_source_key' => 'provider payload' ),
						'stages'   => array(
							array( 'stage' => 'blocks-engine/wordpress-site-plan-resolver', 'output' => array( 'sha256' => 'resolved-hash', 'bytes' => 18, 'preview' => '<p>provider preview</p>' ), 'provider_html' => '<p>provider HTML</p>' ),
							array( 'stage' => 'static-site-importer/runtime-entity-bindings', 'input_sha256' => 'resolved-hash', 'output' => array( 'sha256' => 'bound-hash', 'bytes' => 21, 'count' => 1, 'raw_markup' => '<p>bound markup</p>' ), 'unknown_stage_key' => 'provider value' ),
							array( 'stage' => 'provider-extra-stage', 'output' => array( 'sha256' => 'must-not-survive' ) ),
						),
						'unknown_row_key' => array( 'provider' => 'payload' ),
					),
				),
			),
		),
	)
);
$projected_provider_provenance = $provider_receipt_diagnostics['materialization_receipt']['block_provenance'] ?? array();
$projected_provider_json        = (string) wp_json_encode( $projected_provider_provenance );
$assert( array( array( 'source' => array( 'schema' => 'static-site-importer/page-provenance/v1', 'source_path' => 'index.html', 'reconciliation_identity' => 'page-index' ), 'stages' => array( array( 'stage' => 'blocks-engine/wordpress-site-plan-resolver', 'output' => array( 'sha256' => 'resolved-hash', 'bytes' => 18 ) ), array( 'stage' => 'static-site-importer/runtime-entity-bindings', 'input_sha256' => 'resolved-hash', 'output' => array( 'sha256' => 'bound-hash', 'bytes' => 21, 'count' => 1 ) ) ) ) ) === $projected_provider_provenance, 'fixture diagnostics project only bounded provenance identity and stage metadata' );
$assert( ! str_contains( $projected_provider_json, 'provider source markup' ) && ! str_contains( $projected_provider_json, 'provider HTML' ) && ! str_contains( $projected_provider_json, 'provider preview' ) && ! str_contains( $projected_provider_json, 'unknown_source_key' ) && ! str_contains( $projected_provider_json, 'unknown_row_key' ) && ! str_contains( $projected_provider_json, 'unknown_stage_key' ) && ! str_contains( $projected_provider_json, 'provider-extra-stage' ), 'fixture diagnostics reject provider markup, previews, nested payloads, and unknown provenance keys' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$nested_index_artifact = array(
	'entrypoint' => 'website/index.html',
	'files'      => array(
		'website/index.html'            => '<main><h1>Home</h1></main>',
		'website/about/index.html'      => '<main><h1>About</h1></main>',
		'website/about/team/index.html' => '<main><h1>Team</h1></main>',
	),
);
$nested_index_plan    = ( new ArtifactCompiler() )->compile( $nested_index_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$nested_index_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $nested_index_plan, array( 'slug' => 'nested-index-plan' ) );
$nested_index_ids     = $nested_index_receipt['completed']['pages'] ?? array();
$home_id              = (int) ( $nested_index_ids['website/index.html'] ?? 0 );
$about_id             = (int) ( $nested_index_ids['website/about/index.html'] ?? 0 );
$team_id              = (int) ( $nested_index_ids['website/about/team/index.html'] ?? 0 );
$assert( 'completed' === $nested_index_receipt['status'] && 3 === count( array_unique( array( $home_id, $about_id, $team_id ) ) ), 'wrapper-root nested index pages materialize as distinct WordPress posts' );
$assert( 'index' === ( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_name'] ?? null ) && 0 === ( $GLOBALS['ssi_plan_posts'][ $home_id ]['post_parent'] ?? null ), 'wrapper entrypoint preserves its root page identity' );
$assert( 'about' === ( $GLOBALS['ssi_plan_posts'][ $about_id ]['post_name'] ?? null ) && 0 === ( $GLOBALS['ssi_plan_posts'][ $about_id ]['post_parent'] ?? null ), 'nested index page slug matches its top-level canonical route' );
$assert( 'team' === ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_name'] ?? null ) && $about_id === ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_parent'] ?? null ), 'deeper nested index page preserves canonical slug and WordPress parent identity' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$classify_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'                => '<main><h1>Home</h1></main>',
		'blog/hello.html'           => '<html><head><meta property="article:published_time" content="2024-03-12T10:00:00Z"></head><body><main><h1>Hello</h1></main></body></html>',
		'2024/03/dated-post.html'   => '<main><h1>Dated by URL</h1></main>',
		'blog/about-the-blog.html'  => '<main><h1>About the blog</h1></main>',
	),
);
$classify_plan    = ( new ArtifactCompiler() )->compile( $classify_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$classify_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $classify_plan, array( 'slug' => 'classify-plan' ) );
$classify_ids     = $classify_receipt['completed']['pages'] ?? array();
$home_id          = (int) ( $classify_ids['index.html'] ?? 0 );
$post_id          = (int) ( $classify_ids['blog/hello.html'] ?? 0 );
$url_dated_id     = (int) ( $classify_ids['2024/03/dated-post.html'] ?? 0 );
$blog_about_id    = (int) ( $classify_ids['blog/about-the-blog.html'] ?? 0 );
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
	$id = (int) ( $classify_ids[ $real_source ] ?? 0 );
	$expected_type = in_array( $real_source, array( 'blog/hello.html', '2024/03/dated-post.html' ), true ) ? 'post' : 'page';
	$repeat_id = (int) ( $classify_repeat['completed']['pages'][ $real_source ] ?? 0 );
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
$tz_plan    = ( new ArtifactCompiler() )->compile( $tz_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$tz_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $tz_plan, array( 'slug' => 'tz-plan' ) );
$tz_id      = (int) ( ( $tz_receipt['completed']['pages'] ?? array() )['essays/dated.html'] ?? 0 );
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
$reclassify_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html' => '<main><h1>Home</h1></main>',
		'notes/post.html' => '<main><h1>Essay</h1></main>',
	),
);
$reclassify_plan = ( new ArtifactCompiler() )->compile( $reclassify_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$reclassify_first = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $reclassify_plan, array( 'slug' => 'reclassify-plan' ) );
$first_id = (int) ( ( $reclassify_first['completed']['pages'] ?? array() )['notes/post.html'] ?? 0 );
$assert( 'page' === ( $GLOBALS['ssi_plan_posts'][ $first_id ]['post_type'] ?? null ), 'undated document imports as a page before reclassification' );
$reclassify_plan['pages'] = array_map(
	static function ( array $page ): array {
		if ( 'notes/post.html' === $page['source_path'] ) {
			// The plan validator requires each meta row order to match its index.
			$page['document_metadata']['meta'][] = array( 'order' => count( $page['document_metadata']['meta'] ), 'placement' => 'head', 'property' => 'article:published_time', 'content' => '2024-06-01T08:00:00Z' );
		}
		return $page;
	},
	$reclassify_plan['pages']
);
$reclassify_second = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $reclassify_plan, array( 'slug' => 'reclassify-plan' ) );
$second_id = (int) ( ( $reclassify_second['completed']['pages'] ?? array() )['notes/post.html'] ?? 0 );
$assert( $first_id === $second_id && 'post' === ( $GLOBALS['ssi_plan_posts'][ $second_id ]['post_type'] ?? null ) && '2024-06-01 08:00:00' === ( $GLOBALS['ssi_plan_posts'][ $second_id ]['post_date_gmt'] ?? null ), 'page-to-post reclassification reuses the existing post and updates its type and date' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$parented_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'            => '<main><h1>Home</h1></main>',
		'about/index.html'      => '<main><h1>About</h1></main>',
		'about/blog/index.html' => '<html><head><meta property="article:published_time" content="2024-01-05T08:00:00Z"></head><body><main><h1>Blog</h1></main></body></html>',
	),
);
$parented_plan    = ( new ArtifactCompiler() )->compile( $parented_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$parented_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $parented_plan, array( 'slug' => 'parented-plan' ) );
$parented_ids     = $parented_receipt['completed']['pages'] ?? array();
$parented_blog_id = (int) ( $parented_ids['about/blog/index.html'] ?? 0 );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $parented_blog_id ]['post_type'] ?? null ), 'a dated article nested under a page hierarchy still classifies as a post' );

$GLOBALS['ssi_plan_posts'] = array();
$GLOBALS['ssi_plan_meta']  = array();
$explicit_artifact = array(
	'entrypoint' => 'index.html',
	'files'      => array(
		'index.html'       => '<main><h1>Home</h1></main>',
		'notes/ideas.md'   => "---\ntitle: Ideas\ntype: post\n---\n\n# Ideas\nBody",
	),
);
$explicit_plan    = ( new ArtifactCompiler() )->compile( $explicit_artifact )->toArray()['source_reports']['wordpress_site_plan'];
$explicit_receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $explicit_plan, array( 'slug' => 'explicit-plan' ) );
$explicit_ids     = $explicit_receipt['completed']['pages'] ?? array();
$ideas_id         = (int) ( $explicit_ids['notes/ideas.md'] ?? 0 );
$assert( 'post' === ( $GLOBALS['ssi_plan_posts'][ $ideas_id ]['post_type'] ?? null ), 'explicit markdown frontmatter post_type overrides signal-free detection' );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 1;
$partial = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $plan, array( 'slug' => 'partial-plan' ) );
$assert( 'partial' === $partial['status'], 'runtime mutation failure returns partial receipt' );
$assert( 'simulated_post_failure' === $partial['diagnostics'][0]['reason_code'], 'partial receipt keeps mutation failure identity' );

$GLOBALS['ssi_plan_posts']      = array();
$GLOBALS['ssi_plan_meta']       = array();
$GLOBALS['ssi_plan_fail_after'] = 0;
$parent_plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'website/index.html', 'files' => array( 'website/index.html' => '<main>Home</main>', 'website/about/index.html' => '<main>About</main>' ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$child_plan = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'website/index.html', 'files' => array( 'website/index.html' => '<main>Home</main>', 'website/about/team/index.html' => '<main>Team</main>' ) ) )->toArray()['source_reports']['wordpress_site_plan'];
$parent_batch = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $parent_plan, array( 'slug' => 'batch-parent-plan', 'import_run_id' => 'batch-parent-run' ) );
file_put_contents( $GLOBALS['ssi_plan_root'] . '/batch-parent-plan/static-site-importer-manifest.json', json_encode( array( 'import_run_id' => 'batch-parent-run' ) ) );
$child_batch = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $child_plan, array( 'slug' => 'batch-parent-plan', 'import_run_id' => 'batch-parent-run', 'preserve_existing_theme_bootstrap' => true, 'overwrite' => true ) );
$about_id = (int) ( $parent_batch['completed']['pages']['website/about/index.html'] ?? 0 );
$team_id = (int) ( $child_batch['completed']['pages']['website/about/team/index.html'] ?? 0 );
$assert( 'completed' === $child_batch['status'] && $about_id > 0 && $about_id === (int) ( $GLOBALS['ssi_plan_posts'][ $team_id ]['post_parent'] ?? 0 ), 'later batch resolves an existing parent only through matching run provenance' );
$parent_order = new ReflectionMethod( Static_Site_Importer_WordPress_Site_Plan_Materializer::class, 'parent_ordered_pages' );
$GLOBALS['ssi_plan_posts'][999] = array( 'post_name' => 'external-parent' );
$GLOBALS['ssi_plan_meta'][999]['_static_site_importer_provenance'] = json_encode( array( 'import_run_id' => 'batch-parent-run', 'source_path' => 'website/external/index.html' ) );
$descendant_only = $parent_order->invoke( null, array( array( 'source_path' => 'website/external/child/index.html', 'parent_source_path' => 'website/external/index.html' ) ), 'batch-parent-run' );
$assert( is_array( $descendant_only ) && 1 === count( $descendant_only ) && 'website/external/child/index.html' === ( $descendant_only[0]['source_path'] ?? '' ), 'external provenance parent satisfies ordering without being emitted as a page' );

echo "WordPress site plan materializer smoke passed.\n";
