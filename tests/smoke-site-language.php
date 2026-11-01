<?php
/** Language ownership: WPLANG via the core pack path, route-owner records, and a runtime that never echoes a source lang. */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
}

$GLOBALS['ssi_options']          = array();
$GLOBALS['ssi_options_missing']  = array();
$GLOBALS['ssi_post_meta']        = array();
$GLOBALS['ssi_meta_exists']      = array();
$GLOBALS['ssi_available']        = array();
$GLOBALS['ssi_pack_result']      = true;
$GLOBALS['ssi_pack_calls']       = array();
$GLOBALS['ssi_file_mod_allowed'] = true;

function get_option( string $option, mixed $default_value = false ): mixed {
	if ( array_key_exists( $option, $GLOBALS['ssi_options'] ) ) {
		return $GLOBALS['ssi_options'][ $option ]; }
	if ( isset( $GLOBALS['ssi_options_missing'][ $option ] ) ) {
		return $GLOBALS['ssi_options_missing'][ $option ]; }
	return $default_value; }
function update_option( string $option, mixed $value ): bool {
	$GLOBALS['ssi_options'][ $option ] = $value;
	return true; }
function delete_option( string $option ): bool {
	unset( $GLOBALS['ssi_options'][ $option ] );
	return true; }
function get_post_meta( int $id, string $key, bool $single = true ): mixed {
	return $GLOBALS['ssi_post_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( int $id, string $key, mixed $value ): bool {
	$GLOBALS['ssi_post_meta'][ $id ][ $key ] = $value;
	return true; }
function delete_post_meta( int $id, string $key ): bool {
	unset( $GLOBALS['ssi_post_meta'][ $id ][ $key ] );
	return true; }
function metadata_exists( string $type, int $id, string $key ): bool {
	return isset( $GLOBALS['ssi_meta_exists'][ $id ][ $key ] ) || isset( $GLOBALS['ssi_post_meta'][ $id ][ $key ] ); }
function get_available_languages(): array {
	return $GLOBALS['ssi_available']; }
function wp_is_file_mod_allowed( string $context ): bool {
	return $GLOBALS['ssi_file_mod_allowed']; }
function wp_download_language_pack( string $download ): string|false {
	$GLOBALS['ssi_pack_calls'][] = $download;
	if ( ! $GLOBALS['ssi_file_mod_allowed'] || array() === $GLOBALS['ssi_pack_result'] ) {
		return false; }
	return true === $GLOBALS['ssi_pack_result'] ? $download : (string) $GLOBALS['ssi_pack_result']; }
function sanitize_text_field( string $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' ); }

final class Static_Site_Importer_Site_Plan_Persistence {
	/** @var array<int,bool> */
	public static array $journaled_options = array();
	/** @var array<int,array{int,string,bool}> */
	public static array $written_meta = array();

	public static function reset(): void {
		self::$journaled_options = array();
		self::$written_meta      = array(); }

	public static function journal_option( array &$state, string $option ): void {
		self::$journaled_options[ $option ] = true; }

	public static function write_option( string $option, mixed $value ): bool {
		return update_option( $option, $value ); }

	public static function write_post_meta( int $id, string $key, string $value ): bool {
		self::$written_meta[] = array( $id, $key, $value );
		return update_post_meta( $id, $key, $value ); }
}

require dirname( __DIR__ ) . '/includes/class-static-site-importer-site-language.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
};
$reset_runtime = static function (): void {
	$GLOBALS['ssi_options']          = array();
	$GLOBALS['ssi_post_meta']        = array();
	$GLOBALS['ssi_meta_exists']      = array();
	$GLOBALS['ssi_available']        = array();
	$GLOBALS['ssi_pack_result']      = true;
	$GLOBALS['ssi_pack_calls']       = array();
	$GLOBALS['ssi_file_mod_allowed'] = true;
	Static_Site_Importer_Site_Plan_Persistence::reset();
};
$page = static fn( string $path, array $metadata, bool $entrypoint = false ): array => array(
	'source_path'       => $path,
	'entrypoint'        => $entrypoint,
	'document_metadata' => $metadata,
);
$html_lang_page   = static fn( string $path, string $lang, bool $entrypoint = false ): array => $page( $path, array( 'root_attributes' => array( 'lang' => $lang, 'class' => 'home' ) ), $entrypoint );
$content_lang_page = static fn( string $path, string $lang, bool $entrypoint = false ): array => $page( $path, array( 'meta' => array( array( 'http_equiv' => 'content-language', 'content' => $lang ) ) ), $entrypoint );

// Extraction: <html lang> wins, content-language is the fallback, junk is rejected.
$assert( 'nl' === Static_Site_Importer_Site_Language::language_from_page( $html_lang_page( 'index.html', 'nl', true ) ), '<html lang> is the source document language.' );
$assert( 'nl' === Static_Site_Importer_Site_Language::language_from_page( $content_lang_page( 'index.html', 'nl', true ) ), 'A content-language meta is the fallback source language.' );
$assert( 'nl' === Static_Site_Importer_Site_Language::language_from_page( $page( 'index.html', array( 'root_attributes' => array( 'lang' => 'nl', 'class' => 'x' ), 'meta' => array( array( 'http_equiv' => 'content-language', 'content' => 'fr' ) ) ), true ) ), 'An explicit <html lang> wins over a content-language meta.' );
$assert( '' === Static_Site_Importer_Site_Language::language_from_page( $page( 'index.html', array(), true ) ), 'A document without a language declares none.' );
$assert( '' === Static_Site_Importer_Site_Language::language_from_page( $html_lang_page( 'index.html', 'not a lang tag!!', true ) ), 'A malformed tag is not a language.' );

// Locale resolution: known mappings, explicit regions, and unknown languages.
$locale = Static_Site_Importer_Site_Language::locale_from_language(...);
$assert( 'nl_NL' === $locale( 'nl' ) && 'fr_FR' === $locale( 'fr' ) && 'en_US' === $locale( 'en' ), 'Bare languages resolve to their WordPress locale.' );
$assert( 'ja' === $locale( 'ja' ) && 'ja' === $locale( 'ja-JP' ) && 'el' === $locale( 'el-gr' ), 'Bare WordPress locales stay bare, including with an explicit region.' );
$assert( 'pt_BR' === $locale( 'pt-BR' ) && 'zh_TW' === $locale( 'zh-TW' ) && 'de_CH' === $locale( 'de-CH' ), 'Explicit regions resolve to the region locale.' );
$assert( 'sr_RS' === $locale( 'sr-RS' ) && 'nb_NO' === $locale( 'nb' ) && 'nn_NO' === $locale( 'nn' ), 'Region and language-variant conventions resolve.' );
$assert( array( 'zh_TW', 'zh_CN' ) === Static_Site_Importer_Site_Language::locale_candidates( 'zh-TW' ), 'Candidates keep the region form before the bare fallback.' );
$assert( '' === $locale( 'xx' ) && '' === $locale( '' ) && '' === $locale( 'under_score' ), 'Unknown or malformed languages resolve no locale.' );

// Site-language decision: entrypoint extraction and owner preservation.
$decision = static fn( array $pages, array $options = array() ): array => Static_Site_Importer_Site_Language::decision(
	array(
		'ordered_pages' => $pages,
		'args'          => $options,
	)
);
$reset_runtime();
$home = $decision( array( $html_lang_page( 'index.html', 'nl', true ), $html_lang_page( 'about.html', 'en' ) ) );
$assert( 'apply' === $home['mode'] && 'nl' === $home['source_tag'] && 'html_lang' === $home['source_from'] && 'nl_NL' === $home['locale'] && '' === $home['current_locale'], 'An unset site language is applied from the entry document.' );
$explicit = $decision( array( $html_lang_page( 'index.html', 'nl', true ) ), array( 'site_language' => 'fr' ) );
$assert( 'apply' === $explicit['mode'] && 'fr' === $explicit['source_tag'] && 'fr_FR' === $explicit['locale'] && 'explicit' === $explicit['source_from'], 'An explicit site-language arg wins over the document.' );
$unsupported = $decision( array( $html_lang_page( 'index.html', 'xx', true ) ) );
$assert( 'unsupported_language' === $unsupported['mode'] && '' === $unsupported['locale'], 'A language with no WordPress locale is unsupported, never applied.' );
$fabricated = $decision( array( $html_lang_page( 'index.html', 'xx-YY', true ) ) );
$assert( 'apply' === $fabricated['mode'] && 'xx_YY' === $fabricated['locale'], 'A region-shaped tag maps structurally; the pack path decides installability.' );
$GLOBALS['ssi_options']['WPLANG'] = 'fr_FR';
$preserved = $decision( array( $html_lang_page( 'index.html', 'nl', true ) ) );
$assert( 'preserved_owner_value' === $preserved['mode'] && 'fr_FR' === $preserved['current_locale'], 'A language the site owner already set is preserved.' );

// Materialization: applied through the core language-pack path, journaled for rollback.
$reset_runtime();
$GLOBALS['ssi_available'] = array( 'nl_NL' );
$state = array(
	'ordered_pages' => array( $html_lang_page( 'index.html', 'nl', true ), $html_lang_page( 'about.html', 'en' ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11, 'about.html' => 12 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array(), 'route_language' => array() ),
);
$applied = Static_Site_Importer_Site_Language::materialize_site_language( $state );
$assert( 'applied' === $applied['status'] && 'nl_NL' === $applied['locale'] && 'nl_NL' === get_option( 'WPLANG' ), 'An installed language pack makes the source language the site language owner.' );
$assert( array() === $GLOBALS['ssi_pack_calls'], 'An already-installed pack needs no download.' );
$assert( isset( Static_Site_Importer_Site_Plan_Persistence::$journaled_options['WPLANG'] ), 'The language write is journaled for rollback.' );

$reset_runtime();
$download_state = array(
	'ordered_pages' => array( $html_lang_page( 'index.html', 'nl', true ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array() ),
);
$downloaded = Static_Site_Importer_Site_Language::materialize_site_language( $download_state );
$assert( 'applied' === $downloaded['status'] && array( 'nl_NL' ) === $GLOBALS['ssi_pack_calls'] && 'nl_NL' === get_option( 'WPLANG' ), 'An uninstalled pack goes through the core download path.' );

// Owner handoff: a pack that cannot install keeps the site default and records why. Never a failure.
$reset_runtime();
$GLOBALS['ssi_pack_result'] = false;
$handoff_state = array(
	'ordered_pages' => array( $html_lang_page( 'index.html', 'nl', true ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array() ),
);
$handoff = Static_Site_Importer_Site_Language::materialize_site_language( $handoff_state );
$assert( 'owner_handoff' === $handoff['status'] && 'language_pack_not_installed' === $handoff['reason'] && 'en_US' === $handoff['kept_locale'], 'An un-installable pack is an owner-handoff decision with a reason.' );
$assert( ! array_key_exists( 'WPLANG', $GLOBALS['ssi_options'] ), 'The site default survives a failed pack install.' );
$assert( 'site_language_pack_unavailable' === ( $handoff_state['diagnostics'][0]['reason_code'] ?? '' ) && 'owner_handoff' === ( $handoff_state['diagnostics'][0]['constraints'] ?? '' ), 'The owner-handoff decision is recorded as a diagnostic.' );
$reset_runtime();
$GLOBALS['ssi_file_mod_allowed'] = false;
$disallowed_state = array(
	'ordered_pages' => array( $html_lang_page( 'index.html', 'nl', true ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array() ),
);
$disallowed = Static_Site_Importer_Site_Language::materialize_site_language( $disallowed_state );
$assert( 'owner_handoff' === $disallowed['status'] && ! array_key_exists( 'WPLANG', $GLOBALS['ssi_options'] ), 'A runtime that forbids file changes keeps the default and hands off.' );
$reset_runtime();
$absent_state = array(
	'ordered_pages' => array( $page( 'index.html', array(), true ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array() ),
);
$absent = Static_Site_Importer_Site_Language::materialize_site_language( $absent_state );
$assert( 'absent' === $absent['status'] && ! array_key_exists( 'WPLANG', $GLOBALS['ssi_options'] ), 'A source without a language leaves the site language untouched.' );

// Route owners: languages that differ from the site language are recorded; matching ones are not.
$reset_runtime();
$GLOBALS['ssi_available'] = array( 'nl_NL' );
$route_state = array(
	'ordered_pages' => array( $html_lang_page( 'index.html', 'nl', true ), $html_lang_page( 'en/about.html', 'en' ), $html_lang_page( 'fr/contact.html', 'fr' ), $html_lang_page( 'over-ons.html', 'nl' ) ),
	'args'          => array(),
	'source_ids'    => array( 'index.html' => 11, 'en/about.html' => 12, 'fr/contact.html' => 13, 'over-ons.html' => 14 ),
	'diagnostics'   => array(),
	'rollback'      => array( 'options' => array(), 'route_language' => array() ),
);
$routes = Static_Site_Importer_Site_Language::materialize_route_languages( $route_state );
$assert( 'completed' === $routes['status'] && 'nl_NL' === $routes['site_locale'], 'Route recording resolves the effective site locale.' );
$recorded = array_column( array_filter( $routes['routes'], static fn( array $row ): bool => 'recorded' === $row['status'] ), 'language', 'source_path' );
$assert( array( 'en/about.html' => 'en', 'fr/contact.html' => 'fr' ) === $recorded, 'Route languages that differ from the site language are recorded on their owner.' );
$matching = array_filter( $routes['routes'], static fn( array $row ): bool => 'matches_site_language' === $row['status'] );
$assert( 2 === count( $matching ) && ! isset( $matching['index.html'] ), 'Routes sharing the site language are matched, not recorded.' );
$assert( 'en' === get_post_meta( 12, Static_Site_Importer_Site_Language::ROUTE_META_KEY, true ) && 'fr' === get_post_meta( 13, Static_Site_Importer_Site_Language::ROUTE_META_KEY, true ), 'The route owner carries its source language.' );
$assert( ! metadata_exists( 'post', 11, Static_Site_Importer_Site_Language::ROUTE_META_KEY ) && ! metadata_exists( 'post', 14, Static_Site_Importer_Site_Language::ROUTE_META_KEY ), 'Routes without a language difference stay unwritten.' );

// Effective locale: owner value first, then the applied decision, then the default.
$reset_runtime();
$assert( 'nl_NL' === Static_Site_Importer_Site_Language::effective_locale( array( 'mode' => 'apply', 'locale' => 'nl_NL' ) ), 'Without an owner value the applied locale is effective.' );
$assert( 'en_US' === Static_Site_Importer_Site_Language::effective_locale( array( 'mode' => 'absent', 'locale' => '' ) ), 'Without a decision the default locale is effective.' );
$GLOBALS['ssi_options']['WPLANG'] = 'fr_FR';
$assert( 'fr_FR' === Static_Site_Importer_Site_Language::effective_locale( array( 'mode' => 'preserved_owner_value', 'locale' => 'nl_NL' ) ), 'An owner value is always the effective locale.' );

// Runtime composition: the composed bootstrap strips the source-lang replay, idempotently.
// Use the real vendored document-root bootstrap: the composed runtime must be a drop-in
// whose only change is removing the source lang from the replayed roots.
$vendor_pages = array(
	array(
		'reconciliation_identity' => 'website/index.html',
		'route'                   => array( 'path' => '/' ),
		'entrypoint'              => true,
		'document_metadata'       => array( 'root_attributes' => array( 'lang' => 'nl', 'class' => 'be-r home' ), 'body_attributes' => array() ),
	),
);
$canonical = "<?php\n" . Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\DocumentRootContext::bootstrap( $vendor_pages );
function add_filter( ...$args ): void {}
function apply_filters( string $hook, mixed $value ): mixed {
	return $value; }
function is_singular(): bool {
	return false; }
function get_queried_object_id(): int {
	return 0; }
function is_front_page(): bool {
	return true; }
function get_page_uri( int $id ): string {
	return ''; }
function get_post_type( int $id ): string {
	return 'page'; }
function add_action( ...$args ): void {}
function wp_add_inline_script( ...$args ): void {}
$composed = Static_Site_Importer_Site_Language::with_runtime_ownership( $canonical );
$assert( str_contains( $composed, "unset( \$blocks_engine_document_roots[ \$static_site_importer_root_key ]['attributes']['lang'] );" ), 'The composed runtime removes the source lang from the replayed document roots.' );
$assert( str_contains( $composed, $canonical ), 'Runtime ownership appends to the canonical bootstrap.' );
$assert( Static_Site_Importer_Site_Language::with_runtime_ownership( $composed ) === $composed, 'Runtime ownership is idempotent across overlay chains.' );
$bootstrap_path = tempnam( sys_get_temp_dir(), 'ssi-language-bootstrap-' );
file_put_contents( $bootstrap_path, $composed );
require $bootstrap_path;
$assert( ! isset( $blocks_engine_document_roots['website/index.html']['attributes']['lang'] ), 'Executing the composed bootstrap leaves no source lang for the language_attributes replay.' );
$assert( isset( $blocks_engine_document_roots['website/index.html']['attributes']['class'] ), 'Every other source root attribute keeps its replay owner.' );
$plain = "<?php\nadd_action( 'after_setup_theme', static function (): void {} );\n";
$assert( Static_Site_Importer_Site_Language::with_runtime_ownership( $plain ) === $plain, 'A bootstrap without document roots is left untouched.' );

// Composition over prepared state: overlay-composed and canonical bootstraps both gain ownership.
$compose_state = static function ( array $writes, array $composed = array() ): array {
	return array(
		'theme_dir'             => '/tmp/ssi-language-theme',
		'resolved'              => array( 'writes' => $writes ),
		'composed_theme_writes' => $composed,
		'font_overlay'          => array(),
		'internal_link_overlay' => array(),
	);
};
$overlay_state = $compose_state(
	array(),
	array( '/tmp/ssi-language-theme/functions.php' => $canonical )
);
$overlay_state['font_overlay'] = array( 'writes' => array(
	array( 'target_path' => 'functions.php', 'content' => $canonical ),
) );
Static_Site_Importer_Site_Language::compose_runtime_ownership( $overlay_state );
$assert( str_contains( (string) $overlay_state['composed_theme_writes']['/tmp/ssi-language-theme/functions.php'], "['attributes']['lang']" ), 'Overlay-composed bootstraps gain the ownership section.' );
$assert( str_contains( (string) $overlay_state['font_overlay']['writes'][0]['content'], "['attributes']['lang']" ), 'The overlay write that persistence applies carries the ownership section too.' );
$canonical_state = $compose_state( array(
	array(
		'target_path' => 'functions.php',
		'payload'     => array( 'encoding' => 'utf8', 'data' => $canonical ),
		'payload_hash' => hash( 'sha256', $canonical ),
	),
) );
Static_Site_Importer_Site_Language::compose_runtime_ownership( $canonical_state );
$write = $canonical_state['resolved']['writes'][0];
$assert( str_contains( (string) $write['payload']['data'], "['attributes']['lang']" ) && hash_equals( (string) $write['payload_hash'], hash( 'sha256', (string) $write['payload']['data'] ) ), 'A canonical-only bootstrap is patched with its payload hash kept consistent.' );
$assert ( str_contains( (string) $canonical_state['composed_theme_writes']['/tmp/ssi-language-theme/functions.php'], "['attributes']['lang']" ), 'The patched canonical write is registered as composed content.' );

echo "site language ownership smoke passed\n";
