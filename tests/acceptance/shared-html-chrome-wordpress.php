<?php
/** Persisted-record and native-render oracle; never load in a host site. */
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Disposable oracle explicitly resets core query and style state between native frontend renders.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Oracle reads local disposable archive and theme files, never remote URLs.
if ( ! defined( 'SSI_SHARED_CHROME_DISPOSABLE_TEST' ) || true !== SSI_SHARED_CHROME_DISPOSABLE_TEST ) {
	throw new RuntimeException( 'Disposable Codebox workload required.' );
}
function chrome_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}
$prefix    = 'Automattic\\BlocksEngine\\PhpTransformer\\';
$candidate = defined( 'SSI_SHARED_CHROME_CANDIDATE' ) && SSI_SHARED_CHROME_CANDIDATE;
// Composer may prepend its own loader; install the source override after that
// registration but before loading any plugin/compiler class.
require_once '/wordpress/wp-content/plugins/static-site-importer/vendor/autoload.php';
if ( $candidate ) {
	foreach ( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) as $class ) {
		chrome_assert( ! str_starts_with( $class, $prefix ), 'Compiler already loaded before source override: ' . $class );
	}
	// Prepend a test-only owning-source loader, before SSI/compiler classes load.
	// Missing candidate classes fail closed instead of falling through to vendor.
	spl_autoload_register( static function ( string $class_name ) use ( $prefix ): void {
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$file = '/wordpress/wp-content/plugins/owning-compiler/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		chrome_assert( is_readable( $file ), 'Missing owning compiler class: ' . $class_name );
		require_once $file;
	}, true, true );
}
require_once '/wordpress/wp-content/plugins/static-site-importer/static-site-importer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/rest.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-compilation-preparation.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-wordpress-site-plan-materializer.php';
require_once '/wordpress/wp-content/plugins/static-site-importer/includes/class-static-site-importer-direct-artifact-import.php';
wp_set_current_user( 1 );
chrome_assert( '8.3.32' === PHP_VERSION && '7.1.2' === get_bloginfo( 'version' ), 'Proof requires WordPress7.1.2/PHP8.3.32' );
$reflection = array();
foreach ( array( 'ArtifactCompiler\\ArtifactCompiler', 'WordPressSitePlan\\WordPressSitePlan', 'WordPressSitePlan\\WordPressSitePlanResolver' ) as $suffix ) {
	$file = ( new ReflectionClass( $prefix . $suffix ) )->getFileName();
	chrome_assert( str_starts_with( $file, $candidate ? '/wordpress/wp-content/plugins/owning-compiler/src/' : '/wordpress/wp-content/plugins/static-site-importer/vendor/' ), 'Wrong compiler ownership: ' . $file );
	$reflection[ $suffix ] = $file;
}
echo wp_json_encode( array(
	'compiler_sources' => $reflection,
	'php'              => PHP_VERSION,
	'wordpress'        => get_bloginfo( 'version' ),
) ) . "\n";

function chrome_fixture( string $root ): array {
	$files = array();
	foreach ( array(
		'index.html'          => 'Home',
		'services/index.html' => 'Services',
	) as $path => $title ) {
		$asset   = 'Home' === $title ? 'assets/site.css' : '../assets/site.css';
		$files[] = array(
			'path'    => $root . $path,
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Literal source HTML tests materialization into native enqueued theme styles.
			'content' => '<!doctype html><html><head><title>' . $title . ' Chrome</title><meta name="description" content="' . $title . ' source description"><link rel="stylesheet" href="' . $asset . '"></head><body><!--#include virtual="/parts/header-global.html" --><main><h1>' . $title . ' body oracle</h1><p>Native paragraph.</p></main><!--#include virtual="/parts/footer-global.html" --></body></html>',
		);
	}
	$files[] = array(
		'path'    => $root . 'parts/header-global.html',
		'content' => '<header><p>Shared header oracle</p><p><a href="/services/">Services link oracle</a></p></header>',
	);
	$files[] = array(
		'path'    => $root . 'parts/footer-global.html',
		'content' => '<footer><p>Shared footer oracle</p><p><a href="/">Home link oracle</a></p></footer>',
	);
	$files[] = array(
		'path'    => $root . 'assets/site.css',
		'content' => 'body { color: #123456; }',
	);
	return array(
		'schema'     => 'blocks-engine/php-transformer/site-artifact/v1',
		'entrypoint' => $root . 'index.html',
		'files'      => $files,
	);
}
function chrome_ingress( array $artifact, string $kind ): array {
	if ( 'artifact' === $kind ) {
		chrome_assert( true === Static_Site_Importer_Content_Policy::validate_artifact( $artifact ), 'Canonical artifact policy' );
		return $artifact;
	}
	$source = array( 'entrypoint' => $artifact['entrypoint'] );
	if ( 'files' === $kind ) {
		$source['files'] = $artifact['files'];
	} else {
		$temp = tempnam( sys_get_temp_dir(), 'chrome-' );
		$zip  = new ZipArchive();
		chrome_assert( true === $zip->open( $temp, ZipArchive::OVERWRITE ), 'Create disposable ZIP' );
		foreach ( $artifact['files'] as $file ) {
			$zip->addFromString( $file['path'], $file['content'] );
		}
		$zip->close();
		$source['archive'] = array(
			'name'           => 'chrome.zip',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ZIP ingress uses portable binary archive transport.
			'content_base64' => base64_encode( file_get_contents( $temp ) ),
		);
		wp_delete_file( $temp );
	}
	$result = static_site_importer_source_runtime( $source );
	chrome_assert( ! is_wp_error( $result ), 'Ingress failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
	chrome_assert( count( $result['artifact']['files'] ) === 5, 'Ingress must retain one tree: two pages, two parts, one asset' );
	$expected_paths = array_map( static fn( $path ) => static_site_importer_rest_artifact_path( $path ), array_column( $artifact['files'], 'path' ) );
	chrome_assert( array_column( $result['artifact']['files'], 'path' ) === $expected_paths, 'Ingress path normalization must preserve the complete tree' );
	foreach ( $result['artifact']['files'] as $index => $file ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the artifact's binary content transport to compare source bytes.
		$bytes = $file['content'] ?? base64_decode( $file['content_base64'], true );
		chrome_assert( hash( 'sha256', $bytes ) === hash( 'sha256', $artifact['files'][ $index ]['content'] ), 'Ingress must preserve source bytes without expanding includes' );
	}
	return $result['artifact'];
}
function chrome_pages(): array {
	return get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	) );
}
function chrome_blocks( string $markup ): array {
	$names = array();
	$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
		foreach ( $blocks as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['blockName'];
				chrome_assert( str_starts_with( $block['blockName'], 'core/' ) && 'core/html' !== $block['blockName'], 'Focused fixture must have zero fallback blocks: ' . $block['blockName'] );
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( $markup ) );
	return $names;
}
function chrome_render( int $id ): string {
	global $wp_query, $post;
	$wp_query = new WP_Query( array( 'page_id' => $id ) );
	$post     = $wp_query->post;
	setup_postdata( $post );
	// Re-evaluate core's normal theme support after the disposable test's
	// in-request theme switch, as a fresh frontend bootstrap would do.
	wp_enable_block_templates();
	wp_set_template_globals();
	// Ask core's actual route template selection to populate the block canvas.
	$path = is_front_page() ? get_front_page_template() : get_page_template();
	if ( ! $path ) {
		$path = get_index_template();
	}
	chrome_assert( ! empty( $GLOBALS['_wp_current_template_content'] ), 'Core must select a native block template' );
	chrome_blocks( $GLOBALS['_wp_current_template_content'] );
	$GLOBALS['static_site_importer_head_metadata_emitted'] = false;
	// wp_head emits each queued style once per request. Each oracle render
	// models a separate request while keeping the disposable persisted data.
	$GLOBALS['wp_styles'] = new WP_Styles();
	require_once get_stylesheet_directory() . '/functions.php';
	ob_start();
	wp_head();
	$head = ob_get_clean();
	return '<html><head>' . $head . '</head><body>' . do_blocks( $GLOBALS['_wp_current_template_content'] ) . '</body></html>';
}
function chrome_dom( string $html ): DOMXPath {
	$dom             = new DOMDocument();
	$previous_errors = libxml_use_internal_errors( true );
	$loaded          = $dom->loadHTML( $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous_errors );
	chrome_assert( $loaded, 'Rendered HTML must parse for DOM assertions' );
	return new DOMXPath( $dom );
}
function chrome_link_matches( string $href, int $id, string $route ): bool {
	$url = wp_parse_url( html_entity_decode( $href ) );
	if ( false === $url || ( isset( $url['host'] ) && wp_parse_url( home_url(), PHP_URL_HOST ) !== $url['host'] ) ) {
		return false;
	}
	parse_str( $url['query'] ?? '', $query );
	if ( isset( $query['page_id'] ) ) {
		return $id === (int) $query['page_id'];
	}
	return trailingslashit( $url['path'] ?? '/' ) === $route;
}
function chrome_route_ids( array $ids ): void {
	$GLOBALS['chrome_route_ids'] = array();
	foreach ( $ids as $id ) {
		$GLOBALS['chrome_route_ids'][ str_contains( get_the_title( $id ), 'Services' ) ? 'Services' : 'Home' ] = $id;
	}
	chrome_assert( count( $GLOBALS['chrome_route_ids'] ) === 2, 'Persisted source titles must identify both real routes' );
}
function chrome_check_render( int $id, string $title, string $header ): void {
	$html  = chrome_render( $id );
	$xpath = chrome_dom( $html );
	chrome_assert( 1 === $xpath->query( '//header' )->length && 1 === $xpath->query( '//footer' )->length, 'Render needs exactly one header/footer' );
	chrome_assert( str_contains( $xpath->query( '//header' )->item( 0 )->textContent, $header ), 'Rendered shared header must reflect native persistence' );
	chrome_assert( str_contains( $xpath->query( '//footer' )->item( 0 )->textContent, 'Shared footer oracle' ), 'Rendered shared footer' );
	chrome_assert( 1 === $xpath->query( '//header/following::main[.//h1[contains(., "' . $title . ' body oracle")]]/following::footer' )->length, 'Chrome must surround the page main in DOM order' );
	chrome_assert( 1 === $xpath->query( '//head/meta[@name="description" and @content="' . $title . ' source description"]' )->length, 'Route source description must survive in actual wp_head output' );
	chrome_assert( str_contains( wp_get_document_title(), $title . ' Chrome' ), 'Source document title must survive native title rendering' );
	$stylesheet = false;
	foreach ( $xpath->query( '//head/link[@rel="stylesheet"]' ) as $link ) {
		$href = html_entity_decode( $link->getAttribute( 'href' ) );
		if ( str_starts_with( $href, get_stylesheet_directory_uri() . '/' ) ) {
			$relative = explode( '?', substr( $href, strlen( get_stylesheet_directory_uri() ) + 1 ) )[0];
			chrome_assert( is_file( get_stylesheet_directory() . '/' . $relative ), 'Rendered theme stylesheet URL must address a real asset: ' . $href . ' -> ' . get_stylesheet_directory() . '/' . $relative );
			$stylesheet = true;
		}
	}
	chrome_assert( $stylesheet, 'Native head must load materialized theme stylesheets' );
	$links = $xpath->query( '//header//a[contains(., "Services link oracle")]' );
	chrome_assert( 1 === $links->length, 'Shared navigation link must render' );
	$href = html_entity_decode( $links->item( 0 )->getAttribute( 'href' ) );
	chrome_assert( chrome_link_matches( $href, $GLOBALS['chrome_route_ids']['Services'], '/services/' ), 'Navigation must address the real services route' );
	$home = $xpath->query( '//footer//a[contains(., "Home link oracle")]' );
	chrome_assert( 1 === $home->length && chrome_link_matches( $home->item( 0 )->getAttribute( 'href' ), $GLOBALS['chrome_route_ids']['Home'], '/' ), 'Shared footer must address the real home route' );
	chrome_assert( ! str_contains( $html, '#include' ), 'No unresolved directives in rendered output' );
}

$evidence = array();
foreach ( defined( 'SSI_SHARED_CHROME_ROOT' ) ? array( SSI_SHARED_CHROME_ROOT ) : array( '', 'website/' ) as $root ) {
	foreach ( defined( 'SSI_SHARED_CHROME_INGRESS' ) ? array( SSI_SHARED_CHROME_INGRESS ) : array( 'artifact', 'files', 'zip' ) as $kind ) {
		$artifact = chrome_ingress( chrome_fixture( $root ), $kind );
		if ( ! $candidate ) {
			$before   = chrome_pages();
			$observed = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, array( 'slug' => 'released-chrome-probe' ) );
			chrome_assert( chrome_pages() === $before, 'Adapter/baseline probes must not write pages' );
			$evidence[] = array(
				'root'                   => $root,
				'ingress'                => $kind,
				'adapter'                => 'passed',
				'released_compile_error' => is_wp_error( $observed ) ? $observed->get_error_code() : null,
				'released_page_count'    => is_wp_error( $observed ) ? null : count( $observed['plan']['pages'] ),
				'released_part_count'    => is_wp_error( $observed ) ? null : count( $observed['plan']['template_parts'] ),
				'compact_acceptance'     => 'not_run',
			);
			continue;
		}
		$slug     = 'chrome-' . ( '' === $root ? 'bare' : 'prefixed' ) . '-' . $kind;
		$args     = array(
			'slug'                   => $slug,
			'activate'               => true,
			'remove_default_content' => false,
		);
		$before   = chrome_pages();
		$compiled = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $artifact, $args );
		chrome_assert( ! is_wp_error( $compiled ), 'Canonical compile failed: ' . ( is_wp_error( $compiled ) ? $compiled->get_error_message() : '' ) );
		chrome_assert( count( $compiled['plan']['pages'] ) === 2 && count( $compiled['plan']['template_parts'] ) === 2, 'Canonical plan must contain exactly two real pages and two shared parts: ' . wp_json_encode( array(
			'pages' => array_column( $compiled['plan']['pages'], 'source_path' ),
			'parts' => array_map( static fn( $part ) => array_intersect_key( $part, array_flip( array( 'slug', 'area', 'placement', 'source_path' ) ) ), $compiled['plan']['template_parts'] ),
		) ) );
		$receipt = Static_Site_Importer_WordPress_Site_Plan_Materializer::materialize( $compiled['plan'], $compiled['args'] );
		chrome_assert( 'completed' === ( $receipt['status'] ?? '' ), 'Materialization: ' . wp_json_encode( $receipt['errors'] ?? array() ) );
		$ids = array_values( array_diff( chrome_pages(), $before ) );
		chrome_assert( 2 === count( $ids ), 'Only two real page records may be written' );
		chrome_route_ids( $ids );
		$part_files = glob( get_stylesheet_directory() . '/parts/*.html' );
		chrome_assert( 2 === count( $part_files ), 'Shared part files must be written once each' );
		$part_writes = array_values( array_filter( array_column( $receipt['completed']['files'] ?? array(), 'target_path' ), static fn( $path ) => str_starts_with( $path, 'parts/' ) && str_ends_with( $path, '.html' ) ) );
		chrome_assert( 2 === count( $part_writes ) && 2 === count( array_unique( $part_writes ) ), 'Receipt must contain exactly one canonical write per part' );
		$parts       = array();
		$part_hashes = array();
		$header      = null;
		foreach ( $part_files as $file ) {
			$part_hashes[ $file ] = hash_file( 'sha256', $file );
			$part                 = get_block_template( get_stylesheet() . '//' . basename( $file, '.html' ), 'wp_template_part' );
			chrome_assert( null !== $part, 'Core must discover the written part' );
			chrome_blocks( $part->content );
			$parts[] = $part->slug;
			if ( str_contains( do_blocks( $part->content ), 'Shared header oracle' ) ) {
				$header = $part;
			}
		}
		chrome_assert( null !== $header, 'Native shared header part required' );
		$snapshots = array();
		foreach ( $ids as $id ) {
			$snapshots[ $id ] = get_post_field( 'post_content', $id );
			$title            = str_contains( get_the_title( $id ), 'Services' ) ? 'Services' : 'Home';
			chrome_check_render( $id, $title, 'Shared header oracle' );
			chrome_blocks( $snapshots[ $id ] );
			// Global chrome belongs to the selected native template; nested
			// chrome can belong to page content. Inspect the effective composition.
			$composition = $GLOBALS['_wp_current_template_content'] . $snapshots[ $id ];
			$names       = chrome_blocks( $composition );
			chrome_assert( in_array( 'core/template-part', $names, true ), 'Selected template/page composition must reference native parts' );
			$refs = array();
			$walk = static function ( array $blocks ) use ( &$walk, &$refs ): void {
				foreach ( $blocks as $block ) {
					if ( 'core/template-part' === $block['blockName'] ) {
						$refs[] = $block['attrs']['slug'];
					}
					$walk( $block['innerBlocks'] );
				}
			};
			$walk( parse_blocks( $composition ) );
			sort( $refs );
			$expected = $parts;
			sort( $expected );
			chrome_assert( $refs === $expected, 'Both pages must reference exactly the same two part slugs' );
		}
		// Native Site Editor persistence path: core REST controller creates a DB
		// customization from the theme-file part, not an SSI reimport or file edit.
		$request = new WP_REST_Request( 'POST', '/wp/v2/template-parts/' . get_stylesheet() . '//' . $header->slug );
		$request->set_param( 'content', str_replace( 'Shared header oracle', 'Edited shared header oracle', $header->content ) );
		$response = rest_do_request( $request );
		chrome_assert( $response->get_status() === 200 && ( $response->get_data()['wp_id'] ?? 0 ) > 0, 'Native template-part REST persistence: ' . wp_json_encode( $response->get_data() ) );
		$customization = get_post( $response->get_data()['wp_id'] );
		chrome_assert( 'wp_template_part' === $customization->post_type && $header->slug === $customization->post_name, 'Native edit must persist the shared part, not a page' );
		foreach ( $part_hashes as $file => $hash ) {
			chrome_assert( hash_file( 'sha256', $file ) === $hash, 'Native edit must leave canonical theme part files unchanged' );
		}
		foreach ( $ids as $id ) {
			chrome_assert( get_post_field( 'post_content', $id ) === $snapshots[ $id ], 'Part editing must not change page post_content' );
			chrome_check_render( $id, str_contains( get_the_title( $id ), 'Services' ) ? 'Services' : 'Home', 'Edited shared header oracle' );
		}
		$asset_found = false;
		foreach ( $receipt['completed']['files'] ?? array() as $write ) {
			if ( str_ends_with( $write['target_path'], '/site.css' ) ) {
				$css        = file_get_contents( get_stylesheet_directory() . '/' . $write['target_path'] );
				$theme_json = json_decode( file_get_contents( get_stylesheet_directory() . '/theme.json' ), true );
				// Root styles are deliberately projected into native theme.json.
				chrome_assert( str_contains( $css, '#123456' ) || '#123456' === ( $theme_json['styles']['color']['text'] ?? null ), 'Source color must survive in CSS or its native theme projection' );
				$asset_found = true;
			}
		}
		chrome_assert( $asset_found, 'Source stylesheet must be materialized' );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		// Exercise the existing durable reference-backed pipeline, not a new adapter.
		$staged_before = chrome_pages();
		$staged_args   = array_merge( $args, array( 'slug' => $slug . '-staged' ) );
		$staged        = Static_Site_Importer_Direct_Artifact_Import::start( $artifact, $staged_args, 'files', 'apply', array() );
		$i             = 0;
		while ( $i < 30 && ! is_wp_error( $staged ) && ! empty( $staged['continuation'] ) ) {
			$staged = Static_Site_Importer_Direct_Artifact_Import::resume( $staged['import_id'], $staged_args, 'files', 'apply', array() );
			++$i;
		}
		chrome_assert( ! is_wp_error( $staged ), 'Staged import failed: ' . ( is_wp_error( $staged ) ? $staged->get_error_message() : '' ) );
		chrome_assert( 'completed' === ( $staged['artifact_run']['state'] ?? '' ), 'Staged import must reach completion: ' . wp_json_encode( $staged ) );
		$staged_ids = array_values( array_diff( chrome_pages(), $staged_before ) );
		chrome_assert( 2 === count( $staged_ids ) && 2 === ( $staged['artifact_run']['progress']['page_count'] ?? null ), 'Staged pipeline must compile and write only two pages' );
		chrome_route_ids( $staged_ids );
		foreach ( $staged_ids as $id ) {
			chrome_blocks( get_post_field( 'post_content', $id ) );
			chrome_check_render( $id, str_contains( get_the_title( $id ), 'Services' ) ? 'Services' : 'Home', 'Shared header oracle' );
		}
		foreach ( array( 'missing', 'cycle' ) as $bad ) {
			$invalid = $artifact;
			if ( 'missing' === $bad ) {
				$invalid['files'] = array_values( array_filter( $invalid['files'], static fn( $file ) => ! str_ends_with( $file['path'], 'header-global.html' ) ) );
			} else {
				$invalid['files'][2]['content'] = '<!--#include virtual="/parts/header-global.html" -->';
			}
			$bad_before  = chrome_pages();
			$page_writes = 0;
			$observe     = static function ( int $id, WP_Post $post ) use ( &$page_writes ): void {
				if ( 'page' === $post->post_type ) {
					++$page_writes;
				}
			};
			add_action( 'wp_after_insert_post', $observe, 10, 2 );
			$result = Static_Site_Importer_Compilation_Preparation::compile_website_artifact( $invalid, array( 'slug' => $slug . '-' . $bad ) );
			chrome_assert( is_wp_error( $result ), 'Invalid ' . $bad . ' reference must reject compilation' );
			$bad_staged = Static_Site_Importer_Direct_Artifact_Import::start( $invalid, array( 'slug' => $slug . '-' . $bad . '-staged' ), 'files', 'apply', array() );
			chrome_assert( is_wp_error( $bad_staged ) || 'failed' === ( $bad_staged['artifact_run']['state'] ?? '' ), 'Staged invalid reference must reject' );
			chrome_assert( chrome_pages() === $bad_before, 'Invalid references must stop before page writes' );
			remove_action( 'wp_after_insert_post', $observe, 10 );
			chrome_assert( 0 === $page_writes, 'Invalid references must cause zero page insert/update events' );
		}
		$evidence[] = array(
			'root'            => $root,
			'ingress'         => $kind,
			'page_ids'        => $ids,
			'parts'           => $parts,
			'native_edit'     => 'passed',
			'staged'          => 'passed',
			'invalid_refs'    => 'passed',
			'fallback_blocks' => 0,
		);
		foreach ( $staged_ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
}
if ( $candidate ) {
	foreach ( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) as $class ) {
		if ( str_starts_with( $class, $prefix ) ) {
			$reflection = new ReflectionClass( $class );
			// PHP names caller-owned anonymous PayloadReader implementations
			// after the candidate interface; they are not vendor compiler classes.
			if ( $reflection->isAnonymous() ) {
				continue;
			}
			chrome_assert( str_starts_with( $reflection->getFileName(), '/wordpress/wp-content/plugins/owning-compiler/src/' ), 'Loaded mixed vendor/candidate compiler: ' . $class );
		}
	}
}
echo wp_json_encode( array(
	'status'             => $candidate ? 'shared-chrome-acceptance-passed' : 'baseline-adapter-passed',
	'compact_acceptance' => $candidate ? 'passed' : 'not_run',
	'cases'              => $evidence,
) ) . "\n";
