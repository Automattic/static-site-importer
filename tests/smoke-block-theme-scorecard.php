<?php
/** Real on-disk theme scorecard regression; WordPress's actual block parser. */
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $hook, $value, ...$args ) { return $value; }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
require_once __DIR__ . '/../includes/class-static-site-importer-block-theme-scorecard.php';
require_once __DIR__ . '/../includes/class-static-site-importer-import-report.php';
require_once __DIR__ . '/../includes/class-static-site-importer-diagnostic-projection.php';
foreach ( array( 'report-diagnostics', 'diagnostic-loss-classes', 'visual-parity-oracle' ) as $ssi_class ) { require_once __DIR__ . '/../includes/class-static-site-importer-' . $ssi_class . '.php'; }
function scorecard_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$root = sys_get_temp_dir() . '/ssi-scorecard-' . bin2hex( random_bytes( 6 ) );
$header = '<!-- wp:group --><header><!-- wp:navigation {"ref":7} /--></header><!-- /wp:group -->';
$footer = '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph -->';
$template = '<!-- wp:template-part {"slug":"header","area":"header"} /--><!-- wp:post-content /--><!-- wp:template-part {"slug":"footer","area":"footer"} /-->';
$context = array( 'source_css_bytes' => 1000, 'distinct_menus' => 1, 'design_system' => array( 'metrics' => array( 'preset_share' => 0.8 ) ) );
$routes = array( array( 'route' => '/', 'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ) );
try {
	foreach ( array( 'good', 'bad' ) as $name ) {
		$dir = $root . '/' . $name; mkdir( $dir . '/templates', 0777, true ); mkdir( $dir . '/parts' );
		file_put_contents( $dir . '/templates/index.html', $template );
		file_put_contents( $dir . '/parts/header.html', $header ); file_put_contents( $dir . '/parts/footer.html', $footer );
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Good\nDescription: Test\nRequires at least: 6.0\nTested up to: 6.8\nRequires PHP: 8.0\nLicense: GPL-2.0-or-later\n*/\n" );
		file_put_contents( $dir . '/functions.php', '<?php' ); file_put_contents( $dir . '/screenshot.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' ) );
		file_put_contents( $dir . '/theme.json', json_encode( array( 'version' => 3, 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'black', 'color' => '#000' ) ) ), 'typography' => array( 'fontFamilies' => array( array( 'slug' => 'sans', 'fontFamily' => 'sans-serif' ) ), 'fontSizes' => array( array( 'slug' => 'small', 'size' => '1rem' ) ) ), 'spacing' => array( 'spacingSizes' => array( array( 'slug' => 'small', 'size' => '1rem' ) ) ) ), 'styles' => array( 'elements' => array( 'link' => array( 'color' => array( 'text' => '#000' ) ), 'button' => array( 'border' => array( 'radius' => '2px' ) ), 'heading' => array( 'typography' => array( 'fontWeight' => '700' ) ) ), 'blocks' => array( 'core/paragraph' => array( 'spacing' => array( 'margin' => array( 'bottom' => '1rem' ) ) ) ) ) ) ) );
	}
	foreach ( array(
		'footer-only referenced' => array( false, true, 'pass', array(), array() ),
		'footer-only unreferenced' => array( false, false, 'fail', array( 'index' ), array( 'footer' ) ),
		'header and footer missing header reference' => array( true, true, 'fail', array( 'index' ), array( 'header' ) ),
	) as $name => list( $has_header, $references_footer, $status, $missing_shell, $unreferenced ) ) {
		$dir = $root . '/' . $name; mkdir( $dir . '/templates', 0777, true ); mkdir( $dir . '/parts' );
		file_put_contents( $dir . '/parts/footer.html', $footer );
		if ( $has_header ) { file_put_contents( $dir . '/parts/header.html', $header ); }
		file_put_contents( $dir . '/templates/index.html', '<!-- wp:post-content /-->' . ( $references_footer ? '<!-- wp:template-part {"slug":"footer","area":"footer"} /-->' : '' ) );
		$shell = Static_Site_Importer_Block_Theme_Scorecard::measure( $dir, $routes, $context )['checks']['shared_shell'];
		scorecard_assert( $status === $shell['status'], $name . ': shared shell status' );
		scorecard_assert( $missing_shell === $shell['measured']['templates_missing_shell'], $name . ': missing shell templates' );
		scorecard_assert( $unreferenced === $shell['measured']['unreferenced_parts'], $name . ': unreferenced parts' );
		scorecard_assert( array() === $shell['measured']['duplicated_parts_by_route'], $name . ': no duplicated parts' );
	}
	$good = Static_Site_Importer_Block_Theme_Scorecard::measure( $root . '/good', $routes, $context );
	foreach ( $good['checks'] as $name => $check ) { scorecard_assert( 'pass' === $check['status'], 'Good theme: ' . $name ); }
	scorecard_assert( 1 === $good['checks']['navigation']['measured']['navigation_blocks_with_ref'], 'Navigation in part measured once' );
	scorecard_assert( 1 === $good['checks']['content_quality']['measured']['totals']['block_count'], 'Block count' );
	scorecard_assert( 1 === $good['checks']['shared_shell']['measured']['header_variants'], 'Header variants' );
	scorecard_assert( 0.8 === $good['checks']['design_tokens']['measured']['design_system_metrics']['preset_share'], 'Engine metrics reused' );
	scorecard_assert( filesize( $root . '/good/style.css' ) === $good['checks']['proportionate_assets']['measured']['theme_css_bytes'], 'CSS bytes' );
	$multi_route = Static_Site_Importer_Block_Theme_Scorecard::measure( $root . '/good', array_merge( $routes, array( array( 'route' => '/nested', 'post_content' => '<!-- wp:group --><div><!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ) ) ), $context );
	scorecard_assert( 2 === $multi_route['checks']['content_quality']['measured']['totals']['max_nesting_depth'], 'Total nesting depth is the maximum across routes, not the sum' );
	// Element styles are expected for the elements the content renders, and
	// per-level heading styles style headings. Block styles are not required.
	$levels = $root . '/levels'; mkdir( $levels . '/templates', 0777, true ); mkdir( $levels . '/parts' );
	foreach ( array( 'templates/index.html', 'parts/header.html', 'parts/footer.html' ) as $file ) { copy( $root . '/good/' . $file, $levels . '/' . $file ); }
	file_put_contents( $levels . '/theme.json', json_encode( array( 'version' => 3, 'styles' => array( 'elements' => array( 'h2' => array( 'typography' => array( 'fontWeight' => '400' ) ) ) ) ) ) );
	$heading = '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->';
	$link    = '<!-- wp:paragraph --><p>Read <a href="/more">more</a></p><!-- /wp:paragraph -->';
	$button  = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/go">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
	foreach ( array( array( $heading, array() ), array( $heading . $link . $button, array( 'link', 'button' ) ) ) as list( $content, $unstyled ) ) {
		$theme_json = Static_Site_Importer_Block_Theme_Scorecard::measure( $levels, array( array( 'route' => '/', 'post_content' => $content ) ), $context )['checks']['theme_json'];
		scorecard_assert( $unstyled === $theme_json['measured']['unstyled_used'] && ( array() === $unstyled ? 'pass' : 'fail' ) === $theme_json['status'] && 0 === $theme_json['measured']['block_style_count'], 'theme_json expects styles for rendered elements: ' . json_encode( $unstyled ) );
	}
	file_put_contents( $root . '/bad/parts/footer-unused.html', $footer );
	$repeated = '<!-- wp:group --><div><!-- wp:paragraph --><p>Sidebar</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	for ( $i = 1; $i <= 3; ++$i ) { file_put_contents( $root . '/bad/templates/page-' . $i . '.html', $template . $repeated . '<!-- wp:heading {"anchor":"id-' . $i . '"} --><h2>Page ' . $i . '</h2><!-- /wp:heading -->' ); }
	$routes[0]['post_content'] .= $header;
	$bad = Static_Site_Importer_Block_Theme_Scorecard::measure( $root . '/bad', $routes, $context );
	scorecard_assert( 'fail' === $bad['checks']['shared_shell']['status'], 'Bad shell fails' );
	scorecard_assert( array( 'footer-unused' ) === $bad['checks']['shared_shell']['measured']['unreferenced_parts'], 'Unused part' );
	scorecard_assert( array( 'header' ) === $bad['checks']['shared_shell']['measured']['duplicated_parts_by_route']['/'], 'Duplicated header' );
	$economy = $bad['checks']['template_economy'];
	scorecard_assert( 'fail' === $economy['status'] && 3 === $economy['measured']['custom_template_count'] && 1 === $economy['measured']['distinct_custom_template_structures'] && 2 === $economy['measured']['distinct_template_structures'], 'Template structure ignores text and IDs' );
	scorecard_assert( 2 * strlen( $repeated ) === $economy['measured']['repeated_block_markup_bytes'], 'Repeated bytes exclude nested double counting' );
	$report = Static_Site_Importer_Import_Report::from_array( array( 'materialization_receipt' => array( 'theme' => array( 'dir' => $root . '/good' ), 'scorecard_context' => $context ) ) );
	Static_Site_Importer_Diagnostic_Projection::refresh_projections( $report, array(), false );
	scorecard_assert( 'pass' === $report['block_theme_scorecard']['checks']['shared_shell']['status'], 'Projection measures theme' );
	scorecard_assert( str_contains( $report['owner_handoff_evidence']['report_card'], '| template_economy | pass |' ), 'Report card table' );
	scorecard_assert( false === $report['block_theme_scorecard']['gating'] && empty( $report['quality']['fail_import'] ), 'Signals do not change gate' );
	scorecard_assert( 0 === $report['finding_packets']['count'] && array() === $report['compact_summary']['block_theme_scorecard']['failing_checks'], 'A passing theme reports no scorecard findings' );
	$report = Static_Site_Importer_Import_Report::from_array( array( 'materialization_receipt' => array( 'theme' => array( 'dir' => $root . '/bad' ), 'scorecard_context' => $context ) ) );
	Static_Site_Importer_Diagnostic_Projection::refresh_projections( $report, array(), false );
	$failing = $report['compact_summary']['block_theme_scorecard']['failing_checks'];
	$packets = array_column( $report['finding_packets']['packets'], null, 'id' );
	scorecard_assert( array( 'shared_shell', 'template_economy' ) === $failing && 'reported' === $report['finding_packets']['status'] && count( $failing ) === count( $packets ), 'Each failing check is summarized and becomes one finding' );
	$economy_packet = $packets['finding-block-theme-scorecard-template_economy'];
	scorecard_assert( 'blocks-engine/php-transformer' === $economy_packet['owner'] && 'warning' === $economy_packet['severity'] && 'acceptable_conversion' === $economy_packet['acceptability'], 'Finding is a routed, acceptable-conversion warning' );
	scorecard_assert( $bad['checks']['template_economy']['measured'] === $economy_packet['observed']['measured'] && array( 'custom_templates_max' => 1, 'repeated_block_markup_bytes_max' => 0 ) === $economy_packet['expected']['pass_threshold'], 'Finding carries measured value and threshold' );
	scorecard_assert( empty( $report['quality']['fail_import'] ) && 0 === $report['compact_summary']['diagnostic_count'], 'Scorecard findings stay out of the gate and diagnostic counts' );
	$report = Static_Site_Importer_Import_Report::from_array( array() );
	Static_Site_Importer_Diagnostic_Projection::refresh_projections( $report, array(), false );
	scorecard_assert( isset( $report['block_theme_scorecard'] ), 'Every report has scorecard, even before materialization' );
	echo "Block theme scorecard smoke passed\n";
} finally {
	if ( is_dir( $root ) ) { $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); } rmdir( $root ); }
}
