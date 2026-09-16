<?php
/**
 * Destination boundary for canonical site-plan materialization.
 *
 * Run: php tests/smoke-import-destination.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string {
		return $this->code; }
	public function get_error_message(): string {
		return $this->message; }
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); }
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/'; }

$GLOBALS['ssi_theme_root']       = sys_get_temp_dir() . '/ssi-destination-theme-root';
$GLOBALS['ssi_active_stylesheet'] = 'host-theme';
$GLOBALS['ssi_plugin_root']       = sys_get_temp_dir() . '/ssi-destination-plugin-root';
define( 'WP_PLUGIN_DIR', $GLOBALS['ssi_plugin_root'] );
define( 'WP_PLUGIN_URL', 'https://example.test/wp-content/plugins' );
mkdir( $GLOBALS['ssi_plugin_root'], 0777, true );

function get_theme_root(): string {
	return $GLOBALS['ssi_theme_root']; }
function get_theme_root_uri(): string {
	return 'https://example.test/wp-content/themes'; }
function get_stylesheet(): string {
	return (string) $GLOBALS['ssi_active_stylesheet']; }
function get_stylesheet_directory(): string {
	return $GLOBALS['ssi_theme_root'] . '/' . $GLOBALS['ssi_active_stylesheet']; }
function get_stylesheet_directory_uri(): string {
	return 'https://example.test/wp-content/themes/' . $GLOBALS['ssi_active_stylesheet']; }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-import-destination.php';

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( $condition ) {
		echo "ok   - {$message}\n";
		return;
	}
	++$failures;
	echo "FAIL - {$message}\n";
};

// A destination site with an already-active theme.
@mkdir( $GLOBALS['ssi_theme_root'] . '/' . $GLOBALS['ssi_active_stylesheet'], 0777, true );

// A generated-theme import owns a new directory and may publish the whole theme.
$generated = Static_Site_Importer_Import_Destination::normalize( array( 'slug' => 'imported-site' ) );
$assert( ! is_wp_error( $generated ), 'a generated-theme destination resolves from its slug' );
$assert( 'generated_theme' === $generated['mode'], 'the default destination remains the generated theme' );
$assert(
	$generated['theme_dir'] === $GLOBALS['ssi_theme_root'] . '/imported-site',
	'a generated-theme import targets its own theme directory'
);
$assert(
	$generated['theme_dir'] !== get_stylesheet_directory(),
	'a generated-theme import never targets the theme the site already runs'
);
foreach ( array( 'theme_scaffold', 'theme_bootstrap', 'theme_template', 'theme_asset' ) as $kind ) {
	$assert(
		Static_Site_Importer_Import_Destination::permits_write( $generated, $kind ),
		"a generated-theme import still publishes its own {$kind}"
	);
}
$assert(
	Static_Site_Importer_Import_Destination::permits_activation( $generated ),
	'a generated-theme import may still activate the theme it created'
);

// An existing-theme import lands in the theme the destination site already runs.
$existing = Static_Site_Importer_Import_Destination::normalize( array( 'destination' => 'existing_theme' ) );
$assert( ! is_wp_error( $existing ), 'an existing-theme destination resolves from the active theme' );
$assert( 'existing_theme' === $existing['mode'], 'the requested destination is preserved' );
$assert(
	$existing['theme_dir'] === get_stylesheet_directory(),
	'an existing-theme import targets the active theme directory'
);
$assert(
	$existing['theme_uri'] === get_stylesheet_directory_uri(),
	'an existing-theme import resolves assets against the active theme URI'
);

// The host theme's own design survives the import.
foreach ( array( 'theme_scaffold', 'theme_bootstrap', 'theme_template' ) as $kind ) {
	$assert(
		! Static_Site_Importer_Import_Destination::permits_write( $existing, $kind ),
		"an existing-theme import does not replace the host theme's {$kind}"
	);
	$diagnostic = Static_Site_Importer_Import_Destination::withheld_write_diagnostic( $existing, $kind, 'templates/index.html' );
	$assert(
		'destination_withheld_host_theme_write' === $diagnostic['reason_code'] && $kind === $diagnostic['kind'],
		"a withheld {$kind} is reported rather than silently dropped"
	);
}

// Imported presentation still reaches the page — through a theme-independent home.
$assert(
	Static_Site_Importer_Import_Destination::permits_write( $existing, 'theme_asset' ),
	'an existing-theme import still publishes the assets its pages need'
);
$assert(
	str_starts_with( (string) ( $existing['asset_dir'] ?? '' ), WP_PLUGIN_DIR )
	&& ! str_starts_with( (string) ( $existing['asset_dir'] ?? '' ), trailingslashit( get_stylesheet_directory() ) ),
	"an existing-theme import's assets publish outside the theme the host owns"
);
$assert(
	WP_PLUGIN_URL . '/ssi-host-theme/assets' === ( $existing['asset_uri'] ?? '' ),
	"an existing-theme import's assets resolve against the companion publication URI"
);
$assert(
	is_string( $generated['asset_dir'] ?? '' ) && $generated['asset_dir'] === $generated['theme_dir'],
	"a generated-theme import keeps resolving its assets against its own theme directory"
);

// The destination site keeps the theme it chose.
$assert(
	! Static_Site_Importer_Import_Destination::permits_activation( $existing ),
	'an existing-theme import never switches the active theme'
);

// Unusable destinations are refused rather than guessed.
$invalid = Static_Site_Importer_Import_Destination::normalize( array( 'destination' => 'somewhere_else' ) );
$assert(
	is_wp_error( $invalid ) && 'static_site_importer_destination_invalid' === $invalid->get_error_code(),
	'an unknown destination is rejected'
);
$missing_slug = Static_Site_Importer_Import_Destination::normalize( array() );
$assert(
	is_wp_error( $missing_slug ) && 'invalid_theme_slug' === $missing_slug->get_error_code(),
	'a generated-theme import without a slug is rejected'
);

$GLOBALS['ssi_active_stylesheet'] = 'theme-that-is-not-installed';
$unresolvable                     = Static_Site_Importer_Import_Destination::normalize( array( 'destination' => 'existing_theme' ) );
$assert(
	is_wp_error( $unresolvable ) && 'existing_theme_unavailable' === $unresolvable->get_error_code(),
	'an unresolvable active theme is refused instead of writing somewhere else'
);

echo $failures > 0 ? "\n{$failures} failing assertion(s)\n" : "\nall assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
