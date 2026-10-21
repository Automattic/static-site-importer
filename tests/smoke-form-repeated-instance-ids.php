<?php
/**
 * A reused SSI-materialized Jetpack form keeps every rendered ID unique.
 *
 * Reused content (one synced pattern rendered by several `core/block`
 * references, or the same markup emitted twice) renders the same form block
 * more than once on a page. Every field, error and label `for` ID must be unique
 * per rendered copy, so each label focuses the control in its own form.
 *
 * Run from the repository root against a WordPress install with Jetpack Forms:
 * STATIC_SITE_IMPORTER_WP_ROOT=/path/to/wordpress php tests/smoke-form-repeated-instance-ids.php
 * or: wp --skip-plugins=static-site-importer eval-file tests/smoke-form-repeated-instance-ids.php
 *
 * The installed SSI plugin is skipped so this test loads the worktree classes.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	$ssi_wp_root = rtrim( (string) getenv( 'STATIC_SITE_IMPORTER_WP_ROOT' ), '/\\' );
	if ( '' === $ssi_wp_root || ! is_readable( $ssi_wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, "FAIL: This test requires a WordPress runtime. Set STATIC_SITE_IMPORTER_WP_ROOT to a WordPress install with Jetpack Forms.\n" );
		exit( 1 );
	}
	// Pre-initialized hook (WP_Hook::build_preinitialized_hooks): drop the
	// installed SSI plugin before plugins load.
	$GLOBALS['wp_filter'] = array(
		'option_active_plugins' => array(
			10 => array(
				'ssi_skip_installed_importer' => array(
					'function'      => static fn ( $plugins ): array => array_values( array_filter( (array) $plugins, static fn ( $plugin ): bool => ! str_starts_with( (string) $plugin, 'static-site-importer/' ) ) ),
					'accepted_args' => 1,
				),
			),
		),
	);
	$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	require $ssi_wp_root . '/wp-load.php';
}

if ( class_exists( 'Static_Site_Importer_Form_Seeder', false ) && dirname( __DIR__ ) . '/includes' !== dirname( (string) ( new ReflectionClass( 'Static_Site_Importer_Form_Seeder' ) )->getFileName() ) ) {
	fwrite( STDERR, "FAIL: Another Static Site Importer copy is loaded; skip it (wp --skip-plugins=static-site-importer) so the worktree classes are tested.\n" );
	exit( 1 );
}
if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'jetpack/contact-form' ) ) {
	fwrite( STDERR, "FAIL: Jetpack Forms blocks are not registered in this WordPress runtime.\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

$failures   = array();
$assertions = 0;
$assert     = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	++$assertions;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

// A neutral source form. The control IDs mirror a source page whose responsive
// copies repeat them.
$source     = array(
	'forms' => array(
		array(
			'source_path' => 'index.html',
			'selector'    => 'form.contact',
			'controls'    => array(
				array( 'tag' => 'input', 'type' => 'text', 'name' => 'name', 'id' => 'name', 'label' => 'Your Name', 'required' => true ),
				array( 'tag' => 'input', 'type' => 'email', 'name' => 'email', 'id' => 'email', 'label' => 'Email', 'required' => true ),
				array( 'tag' => 'textarea', 'name' => 'message', 'id' => 'message', 'label' => 'Message' ),
				array( 'tag' => 'button', 'type' => 'submit', 'label' => 'Send' ),
			),
		),
	),
);
$validation = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( $source );
$row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validation['forms'] ?? array() ) )['forms'][0] ?? array();
$markup     = (string) ( $row['block_markup'] ?? '' );
$assert( empty( $validation['errors'] ) && true === ( $row['runtime_mapped'] ?? false ) && str_contains( $markup, '<!-- wp:jetpack/contact-form' ), 'source-form-materializes-as-jetpack-form', wp_json_encode( array( 'validation' => $validation, 'row' => $row ) ) );

// Render through the provider runtime the generated companion registers.
Static_Site_Importer_Provider_Form_Runtime_V1::register();
// Under `wp eval-file`, Jetpack treats the request as non-frontend and renders
// a link fallback; render the frontend form as a page request would.
$ssi_constants  = 'Automattic\\Jetpack\\Constants';
$ssi_in_wp_cli = defined( 'WP_CLI' ) && WP_CLI && class_exists( $ssi_constants );
if ( $ssi_in_wp_cli ) {
	$ssi_constants::set_constant( 'WP_CLI', false );
}
$html = do_blocks( $markup . "\n\n" . $markup );
if ( $ssi_in_wp_cli ) {
	$ssi_constants::clear_single_constant( 'WP_CLI' );
}

$document = new DOMDocument();
$previous = libxml_use_internal_errors( true );
$document->loadHTML( '<?xml encoding="utf-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>' );
libxml_clear_errors();
libxml_use_internal_errors( $previous );
$xpath = new DOMXPath( $document );

$owning_form = static function ( DOMNode $node ): ?DOMNode {
	for ( $parent = $node->parentNode; null !== $parent; $parent = $parent->parentNode ) {
		if ( $parent instanceof DOMElement && 'form' === strtolower( $parent->tagName ) ) {
			return $parent;
		}
	}
	return null;
};

$forms = $xpath->query( '//form' );
$assert( 2 === $forms->length, 'both-copies-render-a-form', (string) $forms->length );

// Every ID in the rendered page is unique.
$by_id      = array();
$duplicates = array();
foreach ( $xpath->query( '//*[@id]' ) as $element ) {
	$id = $element->getAttribute( 'id' );
	if ( isset( $by_id[ $id ] ) ) {
		$duplicates[ $id ] = true;
	}
	$by_id[ $id ] = $element;
}
$assert( count( $by_id ) > 0 && array() === $duplicates, 'every-rendered-id-is-unique-across-form-copies', implode( ', ', array_keys( $duplicates ) ) );

// Each label[for] resolves to a control inside its own form copy.
$labels   = $xpath->query( '//label[@for]' );
$misbound = array();
foreach ( $labels as $label ) {
	$for     = $label->getAttribute( 'for' );
	$form    = $owning_form( $label );
	$matches = $xpath->query( '//*[@id=' . ( str_contains( $for, "'" ) ? '"' . $for . '"' : "'" . $for . "'" ) . ']' );
	if ( null === $form || 1 !== $matches->length || $owning_form( $matches->item( 0 ) ) !== $form || ! in_array( strtolower( $matches->item( 0 )->tagName ), array( 'input', 'textarea', 'select', 'button' ), true ) ) {
		$misbound[] = $for;
	}
}
$assert( 6 === $labels->length && array() === $misbound, 'every-label-focuses-a-control-in-its-own-form', $labels->length . ' labels; misbound: ' . implode( ', ', $misbound ) );

// Error descriptions referenced by a control stay inside the same form copy.
$stray = array();
foreach ( $xpath->query( '//*[@aria-describedby]' ) as $control ) {
	foreach ( preg_split( '/\s+/', trim( $control->getAttribute( 'aria-describedby' ) ) ) ?: array() as $ref ) {
		if ( '' !== $ref && ( ! isset( $by_id[ $ref ] ) || $owning_form( $by_id[ $ref ] ) !== $owning_form( $control ) ) ) {
			$stray[] = $ref;
		}
	}
}
$assert( array() === $stray, 'every-described-by-reference-stays-in-its-own-form', implode( ', ', $stray ) );

if ( ! empty( $failures ) ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS smoke-form-repeated-instance-ids.php (' . $assertions . " assertions)\n";
