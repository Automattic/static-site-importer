<?php
/**
 * Owner-facing companion metadata regression coverage (issue #1972).
 *
 * Proves the generated companion plugin carries a site-specific WordPress
 * name/description and a plain-language README whose inventory matches the
 * actual materialized file set across a scaffold/materialize/re-scaffold
 * cycle, including after the importer itself is removed.
 *
 * Run from the repository root:
 * php tests/smoke-companion-inventory-readme.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'STATIC_SITE_IMPORTER_VERSION' ) ) {
	define( 'STATIC_SITE_IMPORTER_VERSION', '1.24.15' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $code, private string $message, private mixed $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value ): mixed {
		unset( $hook );
		return $value;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
		return trim( $title, '-' );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-content-policy.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-owner-handoff-evidence.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

/**
 * Parse WordPress plugin headers the way core get_file_data() does.
 *
 * @param string $content Plugin file contents.
 * @return array<string,string>
 */
$ssi_1972_headers = static function ( string $content ): array {
	$headers = array();
	foreach ( array( 'Plugin Name', 'Description', 'Version', 'Update URI' ) as $field ) {
		if ( 1 === preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ':(.*)$/m', $content, $matches ) ) {
			$headers[ $field ] = trim( $matches[1] );
		}
	}
	return $headers;
};

/**
 * Recursively delete a directory.
 */
$ssi_1972_rrmdir = static function ( string $dir ) use ( &$ssi_1972_rrmdir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		is_dir( $path ) ? $ssi_1972_rrmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
};

// Generic payload: one metadata block, one scoped island, one editor script, and
// imported form visual states so every owned functionality family is present.
$payload = array(
	'schema'       => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'    => 'Example Site',
	'site_name'    => 'Example Site',
	'blocks'       => array(
		array(
			'name'       => 'custom-hero',
			'block_json' => array(
				'name'         => 'example/custom-hero',
				'title'        => 'Custom Hero',
				'category'     => 'design',
				'editorScript' => 'file:./index.js',
				'style'        => 'file:./style.css',
				'attributes'   => array(
					'content' => array( 'type' => 'string', 'default' => '' ),
				),
			),
			'render'     => '<div class="ssi-hero">Example hero</div>',
			'assets'     => array(
				'index.js'  => 'window.SSIEditor = true;',
				'style.css' => '.ssi-hero { color: inherit; }',
			),
		),
	),
	'preserved_js' => array(
		array(
			'handle'      => 'hero-island',
			'content'     => 'document.addEventListener("DOMContentLoaded",function(){});',
			'block'       => 'example/custom-hero',
			'source_path' => '/wp-content/themes/source/hero.js',
		),
	),
	'editor_scripts' => array(
		array(
			'handle'       => 'ssi-example-site-editor',
			'src'          => 'editor/core-enhancement.js',
			'content'      => 'window.ssiExampleEditor = true;',
			'dependencies' => array( 'wp-element' ),
		),
	),
	'form_visual_states' => array(
		array(
			'schema'   => 'static-site-importer/form-visual-state/v1',
			'field_id' => 'ssi-form-123456789abc-field-0',
			'trigger_class' => 'ssi-node-123456789abc-destination-country-trigger',
			'group'    => array( 'id' => 'visual-group-1234567890abcdef', 'class' => 'ssi-fvg-123456789abc' ),
			'parts'    => array(
				array( 'id' => 'control-0-svg-0', 'class' => 'ssi-fvs-123456789abc', 'markup' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M1 1h22v22H1z"/></svg>' ),
				array( 'id' => 'control-0-svg-1', 'class' => 'ssi-fvs-abcdef123456', 'markup' => '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M1 1l7 7 7-7"/></svg>' ),
			),
			'css' => '.ssi-form-123456789abc .ssi-form-visual-state .ssi-fvs-123456789abc{width:24px!important}',
		),
	),
	'provenance'   => array(
		'schema'         => 'blocks-engine/generated-artifact-provenance/v1',
		'generator'      => 'blocks-engine@2.3.4',
		'engine_version' => 'php-transformer@0.32.3',
		'artifact_hash'  => 'a1b2c3d4e5f6a7b8a1b2c3d4e5f6a7b8',
	),
);

// ---------------------------------------------------------------------------
// 1. The scaffold emits site-specific WordPress plugin metadata.
// ---------------------------------------------------------------------------
$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
$assert( is_array( $descriptor ), 'scaffold-succeeds', is_wp_error( $descriptor ) ? $descriptor->get_error_message() : '' );

if ( is_array( $descriptor ) ) {
	$main_file   = (string) $descriptor['files'][ $descriptor['plugin_file'] ] ?? '';
	$headers     = $ssi_1972_headers( $main_file );
	$assert( 'Example Site Companion' === ( $headers['Plugin Name'] ?? '' ), 'plugin-name-is-site-specific', (string) ( $headers['Plugin Name'] ?? 'missing' ) );
	$assert( 'SSI Companion' !== ( $headers['Plugin Name'] ?? '' ), 'plugin-name-is-not-generic' );
	$description = (string) ( $headers['Description'] ?? '' );
	$assert( '' !== $description && str_contains( $description, 'Example Site' ), 'plugin-description-is-site-specific', $description );
	$assert( str_contains( $description, 'eactivat' ) || str_contains( $description, 'remov' ), 'plugin-description-explains-removal-consequence', $description );
	$assert( '1.24.15+a1b2c3d4' === ( $headers['Version'] ?? '' ), 'plugin-version-stamps-producing-build', (string) ( $headers['Version'] ?? 'missing' ) );
	$assert( '' !== ( $headers['Update URI'] ?? '' ), 'plugin-update-uri-present-with-provenance' );

	// ---------------------------------------------------------------------------
	// 2. The scaffold emits a README whose inventory matches the file set.
	// ---------------------------------------------------------------------------
	$readme = (string) ( $descriptor['files']['ssi-example-site/README.md'] ?? '' );
	$assert( '' !== $readme, 'scaffold-emits-readme' );

	if ( '' !== $readme ) {
		$assert( str_contains( $readme, '# Example Site Companion' ), 'readme-titles-the-site', substr( $readme, 0, 80 ) );
		$assert( str_contains( $readme, 'example/custom-hero' ), 'readme-inventories-owned-block' );
		$assert( str_contains( $readme, 'Custom Hero' ), 'readme-uses-block-title' );
		$assert( str_contains( $readme, 'blocks/custom-hero/block.json' ) && str_contains( $readme, 'blocks/custom-hero/index.js' ) && str_contains( $readme, 'blocks/custom-hero/style.css' ), 'readme-inventories-materialized-block-assets' );
		$assert( str_contains( $readme, 'hero-island' ), 'readme-inventories-preserved-island-handle' );
		$assert( str_contains( $readme, '/wp-content/themes/source/hero.js' ), 'readme-inventories-island-source-path' );
		$assert( str_contains( $readme, 'ssi-example-site-editor' ), 'readme-inventories-editor-script' );

		// Status vocabulary: rebuilt / preserved / snapshot / unresolved / unknown.
		$assert( str_contains( $readme, 'Rebuilt in WordPress' ), 'readme-labels-rebuilt-status' );
		$assert( str_contains( $readme, 'Preserved runtime assets' ), 'readme-labels-preserved-status' );
		$assert( str_contains( $readme, 'Captured snapshot' ), 'readme-labels-snapshot-status' );
		$assert( str_contains( $readme, 'Unresolved behavior' ), 'readme-labels-unresolved-status' );
		$assert( str_contains( $readme, 'Unknown evidence' ), 'readme-labels-unknown-status' );

		// Routes and redirects are owned behavior with spot-check actions.
		$assert( str_contains( $readme, 'redirect' ), 'readme-explains-source-route-redirects' );
		$assert( str_contains( $readme, '301' ), 'readme-explains-redirect-status-code' );
		$assert( str_contains( $readme, 'Spot-check' ) || str_contains( $readme, 'spot-check' ), 'readme-includes-spot-check-actions' );

		// Deactivation/removal consequences and dependency statement.
		$assert( str_contains( $readme, 'Deactivating' ) || str_contains( $readme, 'deactivating' ), 'readme-explains-deactivation-consequence' );
		$assert( str_contains( $readme, 'Jetpack' ), 'readme-names-form-dependency', 'form_visual_states present implies the Jetpack forms dependency must be named' );

		// Snapshot truthfulness for external metrics: static captured values, no
		// invented refresh provider, no fabricated wordpress.org fetching.
		$assert( str_contains( $readme, 'snapshot' ) && str_contains( $readme, 'do not update' ), 'readme-states-snapshot-content-does-not-update' );
		$assert( str_contains( $readme, 'no live data source or refresh mechanism is installed' ) && ! str_contains( $readme, 'api.wordpress.org' ), 'readme-claims-no-live-refresh-provider' );
		$assert( ! str_contains( $readme, 'All imported text, images and numbers are static' ) && str_contains( $readme, 'Explicit runtime blocks and scripts listed above may implement updates' ), 'readme-does-not-misclassify-rebuilt-runtime-fields-as-static' );
		$assert( ! str_contains( $readme, 'preserved source JavaScript' ) && str_contains( $readme, 'included frontend JavaScript' ), 'readme-does-not-invent-source-origin-for-generated-frontend-scripts' );
		$assert( ! str_contains( $readme, 'api.wordpress.org' ), 'readme-hardcodes-no-wordpress-org-fetching' );

		// Build version/provenance policy: explain the real producing build; never
		// reset generated artifacts to a placeholder version.
		$assert( str_contains( $readme, 'blocks-engine@2.3.4' ), 'readme-explains-producing-generator' );
		$assert( str_contains( $readme, 'php-transformer@0.32.3' ), 'readme-explains-engine-version' );
		$assert( str_contains( $readme, 'a1b2c3d4' ), 'readme-explains-artifact-hash' );
		$assert( str_contains( $readme, '1.24.15+a1b2c3d4' ), 'readme-references-real-version-header' );
		$assert( ! str_contains( $readme, 'Version: 1.0.0' ), 'readme-does-not-reset-version-to-placeholder' );

		// Deterministic: same payload scaffolds byte-identical owner metadata.
		$again  = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		$assert( is_array( $again ) && ( $again['files']['ssi-example-site/README.md'] ?? '' ) === $readme, 'readme-is-deterministic' );
		$assert( is_array( $again ) && ( $again['files'][ $again['plugin_file'] ] ?? '' ) === $main_file, 'main-plugin-file-is-deterministic' );
	}

	// ---------------------------------------------------------------------------
	// 3. Without producer provenance the placeholder default is explained, not
	// silently presented as a real build identity.
	// ---------------------------------------------------------------------------
	$unproven = $payload;
	unset( $unproven['provenance'] );
	$unproven_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $unproven );
	$assert( is_array( $unproven_descriptor ), 'unproven-payload-scaffolds' );
	if ( is_array( $unproven_descriptor ) ) {
		$unproven_headers = $ssi_1972_headers( (string) $unproven_descriptor['files'][ $unproven_descriptor['plugin_file'] ] );
		$assert( '1.0.0' === ( $unproven_headers['Version'] ?? '' ), 'unproven-payload-keeps-compatibility-default-version', (string) ( $unproven_headers['Version'] ?? 'missing' ) );
		$unproven_readme = (string) ( $unproven_descriptor['files']['ssi-example-site/README.md'] ?? '' );
		$assert( str_contains( $unproven_readme, '1.0.0' ) && str_contains( $unproven_readme, 'no producer provenance' ), 'readme-explains-missing-provenance' );
		$assert( ! isset( $unproven_headers['Update URI'] ), 'unproven-payload-emits-no-update-uri' );
	}
}

// ---------------------------------------------------------------------------
// 4. A canonical owner-handoff document projects unresolved rows; an absent
// document is reported as unknown evidence rather than invented success.
// ---------------------------------------------------------------------------
$handoff_plan = array( 'schema' => Static_Site_Importer_Owner_Handoff_Evidence::PLAN_IDENTITY_SCHEMA, 'hash' => str_repeat( 'a', 64 ) );
$handoff_document = Static_Site_Importer_Owner_Handoff_Evidence::compose(
	array(
		'plan_identity'           => $handoff_plan,
		'materialization_receipt' => array(
			'schema'        => 'static-site-importer/materialization-receipt/v2',
			'status'        => 'completed',
			'plan_identity' => $handoff_plan,
		),
		'dimensions'    => array(
			'provider_functionality' => array(
				'receipts' => array(
					array( 'status' => 'failed' ),
				),
			),
		),
	)
);
$assert( is_array( $handoff_document ) && Static_Site_Importer_Owner_Handoff_Evidence::SCHEMA === ( $handoff_document['schema'] ?? '' ), 'handoff-document-composes' );

$with_handoff = $payload;
$with_handoff['owner_handoff_evidence'] = $handoff_document;
$handoff_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $with_handoff );
$assert( is_array( $handoff_descriptor ), 'handoff-carrying-payload-scaffolds' );
if ( is_array( $handoff_descriptor ) ) {
	$handoff_readme = (string) ( $handoff_descriptor['files']['ssi-example-site/README.md'] ?? '' );
	$assert( '' !== $handoff_readme, 'handoff-readme-emitted' );
	if ( '' !== $handoff_readme ) {
		$assert( str_contains( $handoff_readme, 'provider_functionality' ), 'readme-projects-handoff-finding-dimension', $handoff_readme );
		$assert( str_contains( $handoff_readme, 'hard_failure' ), 'readme-projects-handoff-finding-status' );
		$assert( ! str_contains( $handoff_readme, 'Unknown evidence: owner-handoff' ), 'handoff-document-replaces-unknown-evidence-status' );
	}
}

$malformed_handoff = $payload;
$malformed_handoff['owner_handoff_evidence'] = array( 'schema' => 'not-the-canonical-schema', 'findings' => array( array( 'dimension' => 'fabricated' ) ) );
$malformed_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $malformed_handoff );
$assert( is_array( $malformed_descriptor ), 'malformed-handoff-still-scaffolds' );
if ( is_array( $malformed_descriptor ) ) {
	$malformed_readme = (string) ( $malformed_descriptor['files']['ssi-example-site/README.md'] ?? '' );
	$assert( ! str_contains( $malformed_readme, 'fabricated' ), 'malformed-handoff-document-is-not-projected' );
	$assert( str_contains( $malformed_readme, 'Unknown evidence' ), 'malformed-handoff-falls-back-to-unknown' );
}

// ---------------------------------------------------------------------------
// 5. Materialize → read back (export) → re-scaffold (reimport): the README
// inventory must match the files actually on disk, and the generated plugin
// must carry no runtime dependency on the importer.
// ---------------------------------------------------------------------------
if ( is_array( $descriptor ) ) {
	$base = sys_get_temp_dir() . '/ssi-1972-materialize-' . getmypid();
	$ssi_1972_rrmdir( $base );
	mkdir( $base, 0777, true );

	// The canonical install set is the descriptor file map (the same map the
	// install plan writes); write it exactly as materialization would.
	$written = array();
	foreach ( $descriptor['files'] as $relative => $content ) {
		$path = $base . '/' . $relative;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $path, (string) $content );
		$written[] = $relative;
	}

	$readme_path = $base . '/ssi-example-site/README.md';
	$assert( file_exists( $readme_path ), 'materialized-plugin-contains-readme' );
	if ( file_exists( $readme_path ) ) {
		$disk_readme = (string) file_get_contents( $readme_path );
		$assert( $disk_readme === (string) $descriptor['files']['ssi-example-site/README.md'], 'materialized-readme-matches-scaffold' );

		// Reimport: re-scaffold the same payload and confirm byte-identical files.
		$reimported = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		$assert( is_array( $reimported ), 'reimport-scaffold-succeeds' );
		if ( is_array( $reimported ) ) {
			$assert( $reimported['files'] === $descriptor['files'], 'reimport-reproduces-identical-file-set' );
		}

		// Inventory/report consistency against the real materialized tree.
		$config = json_decode( (string) file_get_contents( $base . '/ssi-example-site/companion.json' ), true );
		$assert( is_array( $config ) && array( 'custom-hero' ) === ( $config['block_directories'] ?? array( 'unexpected' ) ), 'companion-config-lists-block-directory' );
		$disk_block_json = json_decode( (string) file_get_contents( $base . '/ssi-example-site/blocks/custom-hero/block.json' ), true );
		$assert( is_array( $disk_block_json ) && 'example/custom-hero' === ( $disk_block_json['name'] ?? '' ), 'materialized-block-name-matches-readme-inventory' );
		$assert( str_contains( $disk_readme, (string) ( $disk_block_json['name'] ?? '' ) ), 'readme-names-the-materialized-block' );
		$assert( file_exists( $base . '/ssi-example-site/islands/hero-island.js' ), 'materialized-island-file-exists' );
		$assert( str_contains( $disk_readme, 'islands/hero-island.js' ), 'readme-references-materialized-island-path' );
		$assert( file_exists( $base . '/ssi-example-site/editor/core-enhancement.js' ), 'materialized-editor-script-exists' );

		// WordPress metadata as WordPress will read it from the installed tree.
		$disk_headers = $ssi_1972_headers( (string) file_get_contents( $base . '/ssi-example-site/ssi-example-site.php' ) );
		$assert( 'Example Site Companion' === ( $disk_headers['Plugin Name'] ?? '' ), 'materialized-plugin-name-is-site-specific' );
		$assert( 'SSI Companion' !== ( $disk_headers['Plugin Name'] ?? '' ), 'materialized-plugin-name-is-not-generic' );

		// After importer removal: no executable dependency on Static Site Importer.
		$foreign_references = array();
		foreach ( (array) scandir( $base . '/ssi-example-site' ) as $entry ) {
			$path = $base . '/ssi-example-site/' . $entry;
			if ( ! is_file( $path ) || ! str_ends_with( $path, '.php' ) ) {
				continue;
			}
			$source = (string) file_get_contents( $path );
			if ( preg_match( '/Static_Site_Importer_/', $source ) ) {
				$foreign_references[] = $entry;
			}
		}
		$assert( array() === $foreign_references, 'generated-plugin-has-no-importer-class-dependency', implode( ', ', $foreign_references ) );

		$ssi_1972_rrmdir( $base );
	}
}

// ---------------------------------------------------------------------------
// 6. Must-use loader metadata is site-specific too.
// ---------------------------------------------------------------------------
$mu_payload = $payload;
$mu_payload['mu_plugin'] = true;
$mu_descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $mu_payload );
$assert( is_array( $mu_descriptor ) && '' !== ( $mu_descriptor['loader_file'] ?? '' ), 'mu-payload-emits-loader' );
if ( is_array( $mu_descriptor ) && '' !== ( $mu_descriptor['loader_file'] ?? '' ) ) {
	$loader = (string) $mu_descriptor['files'][ $mu_descriptor['loader_file'] ] ?? '';
	$assert( str_contains( $loader, 'Plugin Name: Example Site Companion Loader' ), 'mu-loader-name-is-site-specific', substr( $loader, 0, 120 ) );
	$assert( ! str_contains( $loader, 'SSI Companion Loader' ), 'mu-loader-name-is-not-generic' );
}

echo 'Assertions: ' . $assertions . "\n";
if ( array() !== $failures ) {
	echo implode( "\n", $failures ) . "\n";
	exit( 1 );
}
echo "PASS\n";
