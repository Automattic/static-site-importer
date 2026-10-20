<?php
/**
 * Neutral application form with alternating authored wrapper styles.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone fixture with WordPress/Jetpack CLI stubs.

// The oracle compiles the exact same form with only compaction and admission
// disabled. It is never used by production or by the mapped/gate assertions.
if ( in_array( '--uncompacted', $argv, true ) ) {
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-static-site-importer-provider-layout-overlay.php' );
	$source = str_replace( 'private static function compact_rules( array $rules ): array {', 'private static function compact_rules( array $rules ): array { return $rules;', $source );
	$source = str_replace( 'self::overlay_byte_limit( $css )', 'PHP_INT_MAX', $source );
	$root = sys_get_temp_dir() . '/ssi-uncompacted-' . getmypid();
	mkdir( $root . '/includes', 0700, true );
	mkdir( $root . '/tests/fixtures', 0700, true );
	foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) as $file ) {
		copy( $file, $root . '/includes/' . basename( $file ) );
	}
	$overlay_path = $root . '/includes/class-static-site-importer-provider-layout-overlay.php';
	unlink( $overlay_path );
	file_put_contents( $overlay_path, $source );
	symlink( dirname( __DIR__, 2 ) . '/vendor', $root . '/vendor' );
	copy( __FILE__, $root . '/tests/fixtures/long-form-overlay.php' );
	copy( __DIR__ . '/form-seeder-cli-stubs.php', $root . '/tests/fixtures/form-seeder-cli-stubs.php' );
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/tests/fixtures/long-form-overlay.php' ), $status );
	foreach ( glob( $root . '/includes/*' ) as $file ) {
		unlink( $file );
	}
	unlink( $root . '/vendor' );
	unlink( $root . '/tests/fixtures/long-form-overlay.php' );
	unlink( $root . '/tests/fixtures/form-seeder-cli-stubs.php' );
	rmdir( $root . '/tests/fixtures' );
	rmdir( $root . '/tests' );
	rmdir( $root . '/includes' );
	rmdir( $root );
	exit( $status );
}
require_once __DIR__ . '/form-seeder-cli-stubs.php';
$root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
require_once $root . '/php-transformer.php';
$styles = 'margin:0;border-color:#e5e7eb;border-style:solid;border-width:1px;border-radius:6px;background-color:#fafafa;color:#234567;font-family:Georgia;font-size:16px;font-weight:400;line-height:1.5;letter-spacing:0.2px;padding:12px 16px;box-sizing:border-box;display:flex;flex-direction:column;gap:8px;min-height:90px;width:100%';
$html = '<main><form style="display:flex;flex-direction:column;gap:16px">';
$types = array( 'text', 'email', 'url', 'select', 'textarea' );
for ( $i = 0; $i < 30; ++$i ) {
	$type = $types[ $i % count( $types ) ];
	$alternate = $i % 2 ? ';background-color:#f0f4f8;font-weight:500;padding:14px 18px' : '';
	$control = 'select' === $type ? '<select id="field-' . $i . '" name="field-' . $i . '"><option>One</option><option>Two</option></select>' : ( 'textarea' === $type ? '<textarea id="field-' . $i . '" name="field-' . $i . '"></textarea>' : '<input id="field-' . $i . '" name="field-' . $i . '" type="' . $type . '">' );
	$html .= '<div style="' . $styles . $alternate . '"><label for="field-' . $i . '">Field ' . $i . '</label>' . $control . '</div>';
}
$html .= '<button type="submit">Apply</button></form></main>';
$compiled = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'application.html', 'files' => array( 'application.html' => $html ) ) )->toArray();
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
$markup = $row['block_markup'] ?? '';
$fields = array();
if ( preg_match_all( '/<!-- wp:jetpack\/field-(text|email|url|select|textarea) (\{.*?\}) -->/', $markup, $matches, PREG_SET_ORDER ) ) {
	foreach ( $matches as $match ) {
		$attrs = json_decode( $match[2], true );
		$fields[] = array( 'type' => $match[1], 'className' => $attrs['className'] ?? '' );
	}
}
$class = '';
if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $match ) ) {
	$class = json_decode( $match[1], true )['className'] ?? '';
}
$rendered = preg_replace_callback( '/<!-- wp:jetpack\/field-([a-z]+) (\{.*?\}) -->(.*?)<!-- \/wp:jetpack\/field-[a-z]+ -->/s', static function ( $match ) {
	$attrs = json_decode( $match[2], true );
	preg_match( '/<!-- wp:jetpack\/label (\{.*?\}) -->/', $match[3], $label );
	$label_attrs = json_decode( $label[1] ?? '{}', true );
	$tag = 'textarea' === $match[1] ? '<textarea></textarea>' : ( 'select' === $match[1] ? '<select><option>One</option></select>' : '<input>' );
	return '<div class="' . ( $attrs['className'] ?? '' ) . '"><label class="' . ( $label_attrs['className'] ?? '' ) . '">' . ( $label_attrs['label'] ?? '' ) . '</label>' . $tag . '</div>';
}, $markup );
$rendered = preg_replace( '/<!--.*?-->/s', '', $rendered );
echo wp_json_encode( array( 'overlayValid' => null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $row['provider_layout_overlay_css'] ?? null ), 'html' => $rendered, 'status' => $row['status'] ?? '', 'row' => $row, 'fields' => $fields, 'className' => $class, 'css' => $row['provider_layout_overlay_css']['css'] ?? '', 'editorCss' => $row['provider_layout_overlay_css']['editor_css'] ?? '' ) );
