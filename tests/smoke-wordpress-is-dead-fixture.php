<?php
/**
 * Smoke test: the WordPress-is-dead static site fixture imports with portable fidelity checks.
 *
 * Run inside a WordPress site with BFB active:
 * wp eval-file tests/smoke-wordpress-is-dead-fixture.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );

if ( ! defined( 'STATIC_SITE_IMPORTER_PATH' ) && is_readable( $plugin_root . '/static-site-importer.php' ) ) {
	require_once $plugin_root . '/static-site-importer.php';
}
if ( ! class_exists( 'Static_Site_Importer_Document', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-document.php';
}
if ( ! class_exists( 'Static_Site_Importer_Theme_Generator', false ) ) {
	require_once $plugin_root . '/includes/class-static-site-importer-theme-generator.php';
}

$assertions        = 0;
$failures          = array();
$expected_failures = array();

$assert = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$expect_failure = static function ( bool $condition, string $label, string $detail = '' ) use ( &$assertions, &$expected_failures ): void {
	$assertions++;
	if ( ! $condition ) {
		$expected_failures[] = 'EXPECTED-FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$read = static function ( string $path ): string {
	$contents = file_get_contents( $path );
	return false === $contents ? '' : $contents;
};

$selector_count = static function ( string $content, string $selector ): int {
	$classes = array_filter( explode( '.', ltrim( $selector, '.' ) ) );
	preg_match_all( '/class="([^"]*)"|"className":"([^"]*)"/', $content, $matches, PREG_SET_ORDER );

	$count = 0;
	foreach ( $matches as $match ) {
		$attribute = $match[1] ?: $match[2];
		$tokens    = preg_split( '/\s+/', trim( $attribute ) ) ?: array();
		if ( empty( array_diff( $classes, $tokens ) ) ) {
			$count++;
		}
	}

	return $count;
};

$contains_selector = static fn ( string $content, string $selector ): bool => $selector_count( $content, $selector ) > 0;

$pattern_blocks = static function ( string $pattern_file ): string {
	$parts = explode( '?>', $pattern_file, 2 );
	return trim( 2 === count( $parts ) ? $parts[1] : $pattern_file );
};

$fixture_dir = $plugin_root . '/tests/fixtures/wordpress-is-dead';
$fixture     = $fixture_dir . '/index.html';
$files       = array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html', 'styles.css' );

foreach ( $files as $file ) {
	$assert( is_readable( $fixture_dir . '/' . $file ), 'fixture-file-exists-' . $file );
}

$assert( ! str_contains( $read( __FILE__ ), 'Developer' . '/wordpress-is-dead' ), 'smoke-has-no-local-fixture-fallback' );

$result = Static_Site_Importer_Theme_Generator::import_theme(
	$fixture,
	array(
		'name'        => 'WordPress Is Dead Fixture',
		'slug'        => 'wordpress-is-dead-fixture',
		'overwrite'   => true,
		'activate'    => false,
		'keep_source' => true,
	)
);

$assert( ! is_wp_error( $result ), 'import-succeeds', is_wp_error( $result ) ? $result->get_error_message() : '' );

if ( ! is_wp_error( $result ) ) {
	$theme_dir  = $result['theme_dir'];
	$front_page = $read( $theme_dir . '/templates/front-page.html' );
	$page       = $read( $theme_dir . '/templates/page.html' );
	$header     = $read( $theme_dir . '/parts/header.html' );
	$footer     = $read( $theme_dir . '/parts/footer.html' );
	$style      = $read( $theme_dir . '/style.css' );
	$functions  = $read( $theme_dir . '/functions.php' );
	$theme_json = json_decode( $read( $theme_dir . '/theme.json' ), true );
	$report     = json_decode( $read( $result['report_path'] ?? '' ), true );
	$palette    = array();
	foreach ( $theme_json['settings']['color']['palette'] ?? array() as $color ) {
		if ( isset( $color['slug'] ) ) {
			$palette[ $color['slug'] ] = $color;
		}
	}
	$home_tmpl  = $read( $theme_dir . '/templates/page-home.html' );
	$proof_tmpl = $read( $theme_dir . '/templates/page-proof.html' );
	$home_pat   = $read( $theme_dir . '/patterns/page-home.php' );
	$proof_pat  = $read( $theme_dir . '/patterns/page-proof.php' );
	$header_nav = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );

	$assert( str_contains( $front_page, 'wp:post-content' ), 'front-page-renders-page-post-content' );
	$assert( ! str_contains( $front_page, 'wp:pattern' ), 'front-page-does-not-embed-page-pattern' );
	$assert( is_array( $report ), 'import-report-is-valid-json' );
	$assert( isset( $report['quality']['fallback_count'] ), 'import-report-includes-fallback-count' );
	$assert( isset( $report['conversion_fragments']['main:index.html'] ), 'import-report-groups-fragments-by-source' );
	$assert( 'requires_external_render_check' === ( $report['visual_fidelity']['status'] ?? '' ), 'import-report-declares-visual-fidelity-render-check' );
	$assert( 'benchmark_harness' === ( $report['visual_fidelity']['gate_owner'] ?? '' ), 'import-report-delegates-visual-gate-to-benchmark-harness' );
	$visual_targets = $report['visual_fidelity']['comparison_targets'] ?? array();
	$assert( is_array( $visual_targets ) && count( $visual_targets ) >= 5, 'import-report-includes-visual-comparison-targets' );
	$home_visual_target = array_values(
		array_filter(
			is_array( $visual_targets ) ? $visual_targets : array(),
			static fn ( $target ): bool => is_array( $target ) && 'index.html' === ( $target['source_filename'] ?? '' )
		)
	)[0] ?? array();
	$assert( str_ends_with( (string) ( $home_visual_target['source_file'] ?? '' ), '/index.html' ), 'visual-target-records-source-file' );
	$assert( is_string( $home_visual_target['wordpress_url'] ?? null ) && '' !== $home_visual_target['wordpress_url'], 'visual-target-records-wordpress-url' );
	$assert( 'templates/page-home.html' === ( $home_visual_target['generated_template'] ?? '' ), 'visual-target-records-generated-template' );
	$assert( 'patterns/page-home.php' === ( $home_visual_target['generated_pattern'] ?? '' ), 'visual-target-records-generated-pattern' );
	$assert( ( $home_visual_target['source_probe_counts']['hero_candidates'] ?? 0 ) > 0, 'visual-target-counts-source-hero-probes' );
	$assert( ( $home_visual_target['source_probe_counts']['button_candidates'] ?? 0 ) > 0, 'visual-target-counts-source-button-probes' );
	$assert( ( $home_visual_target['generated_probe_counts']['core_button_blocks'] ?? 0 ) > 0, 'visual-target-counts-generated-core-button-blocks' );
	$assert( isset( $home_visual_target['comparison_hooks']['render_surfaces']['source_static'] ), 'visual-target-defines-source-render-surface' );
	$assert( isset( $home_visual_target['comparison_hooks']['render_surfaces']['wordpress_frontend'] ), 'visual-target-defines-frontend-render-surface' );
	$assert( isset( $home_visual_target['comparison_hooks']['render_surfaces']['site_editor_canvas'] ), 'visual-target-defines-site-editor-render-surface' );
	$assert( isset( $home_visual_target['comparison_hooks']['layout_probes']['nav_chrome'] ), 'visual-target-defines-nav-chrome-layout-probe' );
	$assert( isset( $home_visual_target['comparison_hooks']['layout_probes']['code_visual'] ), 'visual-target-defines-code-visual-layout-probe' );
	$assert( isset( $home_visual_target['comparison_hooks']['layout_probes']['problem_grid'] ), 'visual-target-defines-problem-grid-layout-probe' );
	$assert( in_array( 'frontend_editor_visibility_parity', $home_visual_target['comparison_hooks']['layout_probes']['code_visual']['assertions'] ?? array(), true ), 'code-visual-probe-checks-frontend-editor-visibility' );
	$assert( in_array( 'frontend_editor_column_parity', $home_visual_target['comparison_hooks']['layout_probes']['problem_grid']['assertions'] ?? array(), true ), 'problem-grid-probe-checks-frontend-editor-columns' );
	$assert( in_array( 'style.css', $home_visual_target['comparison_hooks']['generated_files'] ?? array(), true ), 'visual-target-points-harness-at-generated-css' );
	$assert( 'requires_external_render_check' === ( $report['semantic_fidelity']['status'] ?? '' ), 'import-report-declares-semantic-fidelity-render-check' );
	$assert( 'benchmark_harness' === ( $report['semantic_fidelity']['gate_owner'] ?? '' ), 'import-report-delegates-semantic-gate-to-benchmark-harness' );
	$semantic_targets = $report['semantic_fidelity']['comparison_targets'] ?? array();
	$assert( is_array( $semantic_targets ) && count( $semantic_targets ) >= 5, 'import-report-includes-semantic-comparison-targets' );
	$home_semantic_target = array_values(
		array_filter(
			is_array( $semantic_targets ) ? $semantic_targets : array(),
			static fn ( $target ): bool => is_array( $target ) && 'index.html' === ( $target['source_filename'] ?? '' )
		)
	)[0] ?? array();
	$assert( str_ends_with( (string) ( $home_semantic_target['source_file'] ?? '' ), '/index.html' ), 'semantic-target-records-source-file' );
	$assert( is_string( $home_semantic_target['wordpress_url'] ?? null ) && '' !== $home_semantic_target['wordpress_url'], 'semantic-target-records-wordpress-url' );
	$assert( 'templates/page-home.html' === ( $home_semantic_target['generated_template'] ?? '' ), 'semantic-target-records-generated-template' );
	$assert( 'patterns/page-home.php' === ( $home_semantic_target['generated_pattern'] ?? '' ), 'semantic-target-records-generated-pattern' );
	$assert( in_array( 'parts/header.html', $home_semantic_target['generated_theme_parts'] ?? array(), true ), 'semantic-target-records-generated-header-part' );
	$assert( in_array( 'parts/footer.html', $home_semantic_target['generated_theme_parts'] ?? array(), true ), 'semantic-target-records-generated-footer-part' );
	foreach ( array( 'header', 'nav', 'main', 'footer' ) as $region ) {
		$assert( in_array( $region, $home_semantic_target['regions'] ?? array(), true ), 'semantic-target-records-region-' . $region );
	}
	foreach ( array( '[class*=brand]', '[class*=logo]', '[class*=wordmark]', '[class*=nav]', '[class*=cta]', '[class*=card]', '[class*=status]' ) as $selector ) {
		$assert( in_array( $selector, $home_semantic_target['semantic_selectors'] ?? array(), true ), 'semantic-target-records-selector-' . $selector );
	}
	$assert( isset( $result['quality']['pass'] ), 'import-result-includes-quality-summary' );
	$assert( str_contains( $page, 'wp:post-content' ), 'page-template-renders-imported-page-content' );
	$assert( str_contains( $home_tmpl, 'wp:post-content' ), 'home-page-template-renders-page-post-content' );
	$assert( str_contains( $proof_tmpl, 'wp:post-content' ), 'proof-page-template-renders-page-post-content' );
	$assert( ! str_contains( $home_tmpl, 'wp:pattern' ), 'home-page-template-does-not-embed-page-pattern' );
	$assert( ! str_contains( $proof_tmpl, 'wp:pattern' ), 'proof-page-template-does-not-embed-page-pattern' );
	$assert( str_contains( $home_pat, 'Slug: wordpress-is-dead-fixture/page-home' ), 'home-pattern-has-theme-slug' );
	$assert( str_contains( $proof_pat, 'Slug: wordpress-is-dead-fixture/page-proof' ), 'proof-pattern-has-theme-slug' );
	$assert( ! str_contains( $front_page, 'layout":{"type":"constrained"' ), 'front-page-template-is-neutral' );
	$assert( ! str_contains( $page, 'layout":{"type":"constrained"' ), 'page-template-is-neutral' );
	$assert( str_contains( $header, 'WordPress' ) && str_contains( $header, 'Is' ) && str_contains( $header, 'Dead' ), 'header-preserves-site-brand' );
	$assert( ! preg_match( '/href=("|\')[^"\']+\.html(?:[#?][^"\']*)?\1/i', $header ), 'header-has-no-stale-html-links' );
	$assert( ! $contains_selector( $header, '.active' ), 'shared-header-has-no-static-active-nav-class' );
	$assert( str_contains( $header, '<!-- wp:navigation ' ), 'header-uses-native-navigation-block' );
	$assert( str_contains( $header, '"ref":' ), 'header-navigation-references-persistent-entity' );
	$assert( ! str_contains( $header, '<!-- wp:navigation-link ' ), 'header-template-part-does-not-inline-navigation-links' );
	$assert( $header_nav instanceof WP_Post, 'header-navigation-post-exists' );
	if ( $header_nav instanceof WP_Post ) {
		$assert( str_contains( $header, '"ref":' . $header_nav->ID ), 'header-navigation-ref-points-to-post' );
		$assert( 1 === substr_count( $header_nav->post_content, '"label":"Manifesto"' ), 'header-navigation-post-does-not-duplicate-label' );
		$assert( str_contains( $header_nav->post_content, '<!-- wp:navigation-link ' ), 'header-navigation-post-stores-navigation-link-blocks' );
	}
	$assert( str_contains( $footer, 'Prompt Liberation Front' ), 'footer-preserves-footer-copy' );
	$assert( str_contains( $footer, '<!-- wp:list {"className":"links"}' ), 'footer-uses-visible-list-block' );
	$assert( ! str_contains( $footer, '<!-- wp:navigation ' ), 'footer-does-not-use-responsive-navigation-block' );
	$assert( ! str_contains( $footer, '<!-- wp:navigation-link ' ), 'footer-does-not-inline-navigation-link-blocks' );
	$assert( ! preg_match( '/href=("|\')[^"\']+\.html(?:[#?][^"\']*)?\1/i', $footer ), 'footer-has-no-stale-html-links' );

	$second_result = Static_Site_Importer_Theme_Generator::import_theme(
		$fixture,
		array(
			'name'        => 'WordPress Is Dead Fixture',
			'slug'        => 'wordpress-is-dead-fixture',
			'overwrite'   => true,
			'activate'    => false,
			'keep_source' => true,
		)
	);
	$assert( ! is_wp_error( $second_result ), 'second-import-succeeds', is_wp_error( $second_result ) ? $second_result->get_error_message() : '' );
	$header_nav_after = get_page_by_path( 'wordpress-is-dead-fixture-header-navigation', OBJECT, 'wp_navigation' );
	if ( $header_nav instanceof WP_Post && $header_nav_after instanceof WP_Post ) {
		$assert( $header_nav->ID === $header_nav_after->ID, 'second-import-reuses-header-navigation-post' );
	}
	$assert( str_contains( $style, '--accent' ) && str_contains( $style, '.compare' ) && str_contains( $style, '.manifesto-list' ), 'style-preserves-source-css' );
	$assert( str_contains( $style, '.wp-block-button.btn > .wp-block-button__link:where(.wp-element-button)' ), 'style-resets-source-button-classes-on-core-button-links' );
	$assert( str_contains( $style, '.wp-block-button.btn > .wp-block-button__link' ), 'style-bridges-source-button-classes-to-core-button-links' );
	$assert( str_contains( $style, '.wp-block-button.btn.primary > .wp-block-button__link' ), 'style-bridges-source-primary-button-to-core-button-link' );
	$assert( str_contains( $style, '.wp-block-button.btn.ghost > .wp-block-button__link' ), 'style-bridges-source-ghost-button-to-core-button-link' );
	$assert( str_contains( $style, '.cta-row .wp-block-button.btn > .wp-block-button__link:focus-visible' ), 'style-bridges-contextual-anchor-button-pseudo-selector' );
	$assert( str_contains( $style, '.wp-block-button.btn.ghost > .wp-block-button__link:active' ), 'style-bridges-tagged-button-pseudo-selector' );
	$assert( str_contains( $style, '@media (max-width: 720px)' ) && str_contains( $style, '.cta-row .wp-block-button.btn.primary > .wp-block-button__link:hover' ), 'style-bridges-media-scoped-button-selector' );
	$assert( ! str_contains( $style, '.wp-block-button.container > .wp-block-button__link' ), 'style-does-not-bridge-non-button-container-class' );
	$assert( ! str_contains( $style, '.wp-block-button.note > .wp-block-button__link' ), 'style-does-not-bridge-non-button-note-class' );
	$assert( str_contains( $functions, 'wp_enqueue_style' ), 'theme-enqueues-stylesheet' );
	$assert( is_array( $theme_json ), 'theme-json-is-valid-json' );
	$assert( isset( $palette['bg'] ) && '#0a0a0a' === $palette['bg']['color'], 'theme-json-exposes-bg-palette-token' );
	$assert( isset( $palette['fg'] ) && '#f4f4f0' === $palette['fg']['color'], 'theme-json-exposes-fg-palette-token' );
	$assert( isset( $palette['muted'] ) && '#8a8a82' === $palette['muted']['color'], 'theme-json-exposes-muted-palette-token' );
	$assert( isset( $palette['accent'] ) && '#ff3b1f' === $palette['accent']['color'], 'theme-json-exposes-accent-palette-token' );
	$assert( ! isset( $palette['max'] ), 'theme-json-ignores-non-color-custom-properties' );
	$assert( 'var(--wp--preset--color--bg)' === ( $theme_json['styles']['color']['background'] ?? '' ), 'theme-json-sets-background-default-from-bg' );
	$assert( 'var(--wp--preset--color--fg)' === ( $theme_json['styles']['color']['text'] ?? '' ), 'theme-json-sets-text-default-from-fg' );
	$assert( ! str_contains( $front_page, '<!-- wp:html /-->' ), 'front-page-has-no-empty-html-fallbacks' );
	$assert( isset( $result['pages']['index.html'], $result['pages']['manifesto.html'], $result['pages']['comparison.html'], $result['pages']['eulogy.html'], $result['pages']['proof.html'] ), 'imports-five-html-pages' );

	$pages = array();
	foreach ( array( 'index.html', 'manifesto.html', 'comparison.html', 'eulogy.html', 'proof.html' ) as $filename ) {
		$post = isset( $result['pages'][ $filename ] ) ? get_post( $result['pages'][ $filename ] ) : null;
		$assert( $post instanceof WP_Post, 'page-post-exists-' . $filename );
		if ( $post instanceof WP_Post ) {
			$slug         = 'index.html' === $filename ? 'home' : preg_replace( '/\.html?$/i', '', $filename );
			$pattern_body = $pattern_blocks( $read( $theme_dir . '/patterns/page-' . $slug . '.php' ) );
			$assert( ! str_contains( $post->post_content, 'Imported page layout lives in this page' ), 'page-post-content-is-not-shell-' . $filename );
			$assert( $contains_selector( $post->post_content, '.hero' ), 'page-post-content-preserves-layout-' . $filename );
			$assert( trim( $pattern_body ) === trim( $post->post_content ), 'page-pattern-snapshot-matches-post-content-' . $filename );
			$pages[ $filename ] = array(
				'stored'   => $post->post_content,
				'rendered' => do_blocks( $post->post_content ),
			);
		}
	}

	$assert( isset( $pages['proof.html'] ) && ! str_contains( $pages['proof.html']['stored'], 'href="index.html"' ), 'page-content-rewrites-internal-links' );

	$selector_expectations = array(
		'index.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.pull' ),
		'manifesto.html'  => array( '.hero', '.block', '.container', '.lede', '.manifesto-list', '.pull' ),
		'comparison.html' => array( '.hero', '.block', '.container', '.lede', '.compare', '.col-wp', '.col-claude' ),
		'eulogy.html'     => array( '.hero', '.block', '.container', '.lede', '.eulogy-frame', '.dates', '.pull' ),
		'proof.html'      => array( '.hero', '.block', '.block.alt', '.container', '.lede', '.prompt', '.pull' ),
	);

	foreach ( $selector_expectations as $filename => $selectors ) {
		foreach ( array_unique( $selectors ) as $selector ) {
			$stored   = $pages[ $filename ]['stored'] ?? '';
			$rendered = $pages[ $filename ]['rendered'] ?? '';
			$assert( $contains_selector( $stored, $selector ), 'stored-preserves-selector-' . $filename . '-' . $selector );
			if ( 'proof.html' === $filename && '.prompt' === $selector ) {
				$expect_failure( $contains_selector( $rendered, $selector ), 'rendered-preserves-selector-' . $filename . '-' . $selector, 'blocked by h2bc/BFB preformatted wrapper-class fidelity gap' );
				continue;
			}

			$assert( $contains_selector( $rendered, $selector ), 'rendered-preserves-selector-' . $filename . '-' . $selector );
		}
	}

	$comparison = $pages['comparison.html']['stored'] ?? '';
	$eulogy     = $pages['eulogy.html']['stored'] ?? '';
	$manifesto  = $pages['manifesto.html']['stored'] ?? '';

	$assert( 1 === $selector_count( $comparison, '.compare' ), 'comparison-compare-wrapper-not-duplicated', 'count=' . $selector_count( $comparison, '.compare' ) );
	$assert( 1 === $selector_count( $comparison, '.col-wp' ), 'comparison-col-wp-not-duplicated', 'count=' . $selector_count( $comparison, '.col-wp' ) );
	$assert( 1 === $selector_count( $comparison, '.col-claude' ), 'comparison-col-claude-not-duplicated', 'count=' . $selector_count( $comparison, '.col-claude' ) );
	$assert( 1 === substr_count( $comparison, 'WordPress <span class="tag">' ), 'comparison-wordpress-heading-not-duplicated', 'count=' . substr_count( $comparison, 'WordPress <span class="tag">' ) );

	$assert( 1 === $selector_count( $eulogy, '.eulogy-frame' ), 'eulogy-frame-not-duplicated', 'count=' . $selector_count( $eulogy, '.eulogy-frame' ) );
	$assert( 1 === $selector_count( $eulogy, '.dates' ), 'eulogy-dates-not-duplicated', 'count=' . $selector_count( $eulogy, '.dates' ) );
	$assert( 1 === substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ), 'eulogy-key-paragraph-not-duplicated', 'count=' . substr_count( $eulogy, 'It is rare that a piece of software earns the right to be eulogized.' ) );

	$expect_failure( 1 === $selector_count( $manifesto, '.manifesto-list' ), 'manifesto-list-not-duplicated', 'count=' . $selector_count( $manifesto, '.manifesto-list' ) );
	$assert( 1 === substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ), 'manifesto-key-heading-not-duplicated', 'count=' . substr_count( $manifesto, 'The CMS was a workaround for not being able to write HTML.' ) );
}

$theme_part_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-theme-parts.html';
$wrote_fixture      = file_put_contents(
	$theme_part_fixture,
	'<!doctype html><html><head><title>Theme Parts</title></head><body>' .
	'<nav class="nav"><div class="nav-logo"><span class="mark">*</span>Studio Code</div><a href="#get-started" class="nav-pill"><span>Get Early Access</span></a></nav>' .
	'<main><section><h1>Theme part smoke</h1><p>Body copy.</p></section></main>' .
	'<footer class="footer"><div class="footer-brand">Studio Code by Automattic</div><div class="footer-copy">Copyright 2026 Automattic Inc.</div></footer>' .
	'</body></html>'
);
$assert( false !== $wrote_fixture, 'theme-part-fixture-written' );

if ( false !== $wrote_fixture ) {
	$theme_part_result = Static_Site_Importer_Theme_Generator::import_theme(
		$theme_part_fixture,
		array(
			'name'      => 'Theme Part Fixture',
			'slug'      => 'theme-part-fixture',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $theme_part_result ), 'theme-part-import-succeeds', is_wp_error( $theme_part_result ) ? $theme_part_result->get_error_message() : '' );
	if ( ! is_wp_error( $theme_part_result ) ) {
		$theme_part_header = $read( $theme_part_result['theme_dir'] . '/parts/header.html' );
		$theme_part_footer = $read( $theme_part_result['theme_dir'] . '/parts/footer.html' );
		$assert( str_contains( $theme_part_header, 'Studio Code' ), 'nav-only-header-preserves-brand-text' );
		$assert( str_contains( $theme_part_header, 'Get Early Access' ), 'nav-only-header-preserves-cta-text' );
		$assert( ! str_starts_with( trim( $theme_part_header ), '<!-- wp:html -->' ), 'nav-only-header-does-not-become-whole-html-island' );
		$assert( str_contains( $theme_part_footer, 'Studio Code by Automattic' ), 'simple-footer-preserves-brand-text' );
		$assert( str_contains( $theme_part_footer, 'Copyright 2026 Automattic Inc.' ), 'simple-footer-preserves-copy-text' );
		$assert( ! str_contains( $theme_part_footer, '<!-- wp:group {"className":"footer-brand"} /-->' ), 'simple-footer-does-not-emit-empty-brand-group' );
	}
}

$rsm_nav_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-rsm-nav-wrapper.html';
$wrote_rsm_nav   = file_put_contents(
	$rsm_nav_fixture,
	'<!doctype html><html><head><title>RSM Nav Wrapper</title><style>' .
	'nav { position: fixed; top: 0; left: 0; right: 0; display: flex; justify-content: space-between; }' .
	'nav .nav-logo { font-weight: 800; }' .
	'@media (max-width: 700px) { nav { position: sticky; } }' .
	'</style></head><body>' .
	'<nav><div class="nav-logo"><span>RSM</span> / Static Site Importer</div><ul class="nav-links"><li><a href="#context">Context</a></li><li><a href="#impact">Studio Impact</a></li></ul></nav>' .
	'<main><section id="context"><h1>RSM import</h1><p>Body copy.</p></section><section id="impact"><h2>Impact</h2><p>More copy.</p></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_rsm_nav, 'rsm-nav-fixture-written' );

if ( false !== $wrote_rsm_nav ) {
	$rsm_nav_result = Static_Site_Importer_Theme_Generator::import_theme(
		$rsm_nav_fixture,
		array(
			'name'      => 'RSM Nav Wrapper',
			'slug'      => 'rsm-nav-wrapper',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $rsm_nav_result ), 'rsm-nav-import-succeeds', is_wp_error( $rsm_nav_result ) ? $rsm_nav_result->get_error_message() : '' );
	if ( ! is_wp_error( $rsm_nav_result ) ) {
		$rsm_nav_header = $read( $rsm_nav_result['theme_dir'] . '/parts/header.html' );
		$rsm_nav_style  = $read( $rsm_nav_result['theme_dir'] . '/style.css' );
		$assert( str_contains( $rsm_nav_header, 'static-site-importer-source-nav' ), 'rsm-nav-wrapper-gets-source-nav-class' );
		$assert( str_contains( $rsm_nav_header, '<!-- wp:list {"className":"nav-links"} -->' ), 'rsm-nav-header-preserves-list-block-owner' );
		$assert( str_contains( $rsm_nav_header, '<ul class="wp-block-list nav-links">' ), 'rsm-nav-header-preserves-list-class-on-ul' );
		$assert( ! str_contains( $rsm_nav_header, '"className":"nav-links"} /-->' ), 'rsm-nav-header-does-not-move-list-class-to-navigation' );
		$assert( str_contains( $rsm_nav_style, '.static-site-importer-source-nav { position: fixed; top: 0; left: 0; right: 0; display: flex; justify-content: space-between; }' ), 'rsm-nav-bridge-preserves-bare-nav-rule' );
		$assert( str_contains( $rsm_nav_style, '.static-site-importer-source-nav .nav-logo { font-weight: 800; }' ), 'rsm-nav-bridge-preserves-descendant-nav-rule' );
		$assert( str_contains( $rsm_nav_style, '@media (max-width: 700px) { .static-site-importer-source-nav { position: sticky; } }' ), 'rsm-nav-bridge-preserves-media-nav-rule' );
		$assert( str_contains( $rsm_nav_style, 'body.admin-bar .static-site-importer-source-nav { top: 32px; }' ), 'rsm-nav-admin-bar-offset-targets-source-nav-wrapper' );
		$assert( str_contains( $rsm_nav_style, '@media screen and (max-width: 782px) { body.admin-bar .static-site-importer-source-nav { top: 46px; } }' ), 'rsm-nav-admin-bar-mobile-offset-targets-source-nav-wrapper' );
		$assert( ! str_contains( $rsm_nav_style, 'body.admin-bar nav { top:' ), 'rsm-nav-admin-bar-offset-avoids-original-nav-selector' );
	}
}

$footer_chrome_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-footer-chrome.html';
$wrote_footer_chrome   = file_put_contents(
	$footer_chrome_fixture,
	'<!doctype html><html><head><title>Footer Chrome</title><style>' .
	'footer { padding: 40px 48px; border-top: 1px solid var(--rule); display: flex; align-items: center; justify-content: space-between; background: var(--paper); }' .
	'footer .footer-logo { font-family: var(--mono); font-size: 13px; color: #888; display: flex; align-items: center; gap: 8px; }' .
	'footer .footer-meta { font-family: var(--mono); font-size: 12px; color: #bbb; }' .
	'@media (max-width: 900px) { footer { flex-direction: column; gap: 12px; text-align: center; } }' .
	'</style></head><body>' .
	'<main><section><h1>Footer chrome fixture</h1><p>Body copy.</p></section></main>' .
	'<footer><div class="footer-logo"><span style="width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;"></span>Studio Code &mdash; by Automattic</div><div class="footer-meta">WordPress Studio &copy; 2025</div></footer>' .
	'</body></html>'
);
$assert( false !== $wrote_footer_chrome, 'footer-chrome-fixture-written' );

if ( false !== $wrote_footer_chrome ) {
	$footer_chrome_result = Static_Site_Importer_Theme_Generator::import_theme(
		$footer_chrome_fixture,
		array(
			'name'      => 'Footer Chrome',
			'slug'      => 'footer-chrome',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $footer_chrome_result ), 'footer-chrome-import-succeeds', is_wp_error( $footer_chrome_result ) ? $footer_chrome_result->get_error_message() : '' );
	if ( ! is_wp_error( $footer_chrome_result ) ) {
		$footer_chrome_footer = $read( $footer_chrome_result['theme_dir'] . '/parts/footer.html' );
		$footer_chrome_style  = $read( $footer_chrome_result['theme_dir'] . '/style.css' );
		$footer_chrome_report = json_decode( $read( $footer_chrome_result['report_path'] ?? '' ), true );
		$footer_document      = array();
		foreach ( $footer_chrome_report['generated_theme']['block_documents'] ?? array() as $document ) {
			if ( is_array( $document ) && 'parts/footer.html' === ( $document['path'] ?? '' ) ) {
				$footer_document = $document;
				break;
			}
		}

		$assert( ! str_contains( $footer_chrome_footer, '<!-- wp:html' ), 'footer-chrome-has-no-core-html-blocks', $footer_chrome_footer );
		$assert( ! str_contains( $footer_chrome_footer, 'core/html' ), 'footer-chrome-has-no-raw-core-html-name', $footer_chrome_footer );
		$assert( str_contains( $footer_chrome_footer, '<!-- wp:paragraph {"className":"footer-logo"}' ), 'footer-logo-uses-native-paragraph' );
		$assert( str_contains( $footer_chrome_footer, 'width:6px;height:6px;border-radius:50%;background:var(--accent);display:inline-block;' ), 'footer-logo-decorative-span-style-survives' );
		$assert( str_contains( $footer_chrome_footer, 'Studio Code — by Automattic' ), 'footer-logo-text-survives' );
		$assert( str_contains( $footer_chrome_footer, '<!-- wp:paragraph {"className":"footer-meta"}' ), 'footer-meta-uses-native-paragraph' );
		$assert( ! str_contains( $footer_chrome_footer, '<!-- wp:freeform' ), 'footer-chrome-has-no-freeform-blocks', $footer_chrome_footer );
		$assert( str_contains( $footer_chrome_style, 'display: flex; align-items: center; justify-content: space-between' ), 'footer-flex-alignment-css-survives' );
		$assert( str_contains( $footer_chrome_style, 'footer .footer-logo' ) && str_contains( $footer_chrome_style, 'gap: 8px' ), 'footer-logo-spacing-css-survives' );
		$assert( str_contains( $footer_chrome_style, 'flex-direction: column; gap: 12px; text-align: center' ), 'footer-responsive-spacing-css-survives' );
		$assert( 0 === ( $footer_document['core_html_block_count'] ?? -1 ), 'footer-chrome-report-has-zero-footer-core-html-blocks' );
		$assert( 0 === ( $footer_document['freeform_block_count'] ?? -1 ), 'footer-chrome-report-has-zero-footer-freeform-blocks' );
	}
}

$footer_link_columns_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-footer-link-columns.html';
$wrote_footer_link_columns   = file_put_contents(
	$footer_link_columns_fixture,
	'<!doctype html><html><head><title>Footer Link Columns</title><style>' .
	'footer { background: #1a1a16; }' .
	'.footer-col-title { color: var(--amber); }' .
	'.footer-links { list-style: none; display: flex; flex-direction: column; gap: 0.6rem; }' .
	'.footer-links a { color: rgba(240,228,194,0.5); }' .
	'</style></head><body>' .
	'<main><section><h1>Footer link columns</h1><p>Body copy.</p></section></main>' .
	'<footer><div class="footer-grid"><div class="footer-col"><p class="footer-col-title">Conference</p><ul class="footer-links"><li><a href="#schedule">Schedule</a></li><li><a href="#speakers">Speakers</a></li></ul></div><div class="footer-col"><p class="footer-col-title">Visit</p><ul class="footer-links"><li><a href="#venue">Venue</a></li><li><a href="#tickets">Tickets</a></li></ul></div></div></footer>' .
	'</body></html>'
);
$assert( false !== $wrote_footer_link_columns, 'footer-link-columns-fixture-written' );

if ( false !== $wrote_footer_link_columns ) {
	$footer_link_columns_result = Static_Site_Importer_Theme_Generator::import_theme(
		$footer_link_columns_fixture,
		array(
			'name'      => 'Footer Link Columns',
			'slug'      => 'footer-link-columns',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $footer_link_columns_result ), 'footer-link-columns-import-succeeds', is_wp_error( $footer_link_columns_result ) ? $footer_link_columns_result->get_error_message() : '' );
	if ( ! is_wp_error( $footer_link_columns_result ) ) {
		$footer_link_columns_footer     = $read( $footer_link_columns_result['theme_dir'] . '/parts/footer.html' );
		$footer_link_columns_rendered   = do_blocks( $footer_link_columns_footer );
		$footer_link_columns_list_count = substr_count( $footer_link_columns_footer, '<!-- wp:list {"className":"footer-links"}' );

		$assert( 2 === $footer_link_columns_list_count, 'footer-link-columns-converts-both-lists-to-visible-list-blocks', 'count=' . $footer_link_columns_list_count );
		$assert( ! str_contains( $footer_link_columns_footer, '<!-- wp:navigation ' ), 'footer-link-columns-do-not-use-navigation-blocks' );
		$assert( str_contains( $footer_link_columns_footer, '"className":"footer-links"' ), 'footer-link-columns-preserve-footer-links-class' );
		$assert( str_contains( $footer_link_columns_footer, 'Conference' ) && str_contains( $footer_link_columns_footer, 'Visit' ), 'footer-link-columns-preserve-column-titles' );
		$assert( str_contains( $footer_link_columns_footer, 'Schedule' ) && str_contains( $footer_link_columns_footer, 'Tickets' ), 'footer-link-columns-store-visible-links-inline' );
		$assert( ! str_contains( $footer_link_columns_rendered, 'wp-block-navigation__responsive-container-open' ), 'footer-link-columns-render-without-overlay-open-button', $footer_link_columns_rendered );
	}
}

$leading_nav_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-leading-nav-header.html';
$wrote_leading_nav   = file_put_contents(
	$leading_nav_fixture,
	'<!doctype html><html><head><title>Leading Nav Header</title></head><body>' .
	'<nav><div class="nav-logo">Studio Code</div><div class="nav-badge">Early Access</div><a href="#get-started" class="nav-cta">Get Started</a></nav>' .
	'<header class="hero"><h1>Launch with Studio</h1><p>Hero copy.</p></header>' .
	'<main><section id="get-started"><h2>Get started</h2><p>Body copy.</p></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_leading_nav, 'leading-nav-fixture-written' );

if ( false !== $wrote_leading_nav ) {
	$leading_nav_result = Static_Site_Importer_Theme_Generator::import_theme(
		$leading_nav_fixture,
		array(
			'name'      => 'Leading Nav Header',
			'slug'      => 'leading-nav-header',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $leading_nav_result ), 'leading-nav-import-succeeds', is_wp_error( $leading_nav_result ) ? $leading_nav_result->get_error_message() : '' );
	if ( ! is_wp_error( $leading_nav_result ) ) {
		$leading_nav_source_slug = sanitize_title( preg_replace( '/\.html?$/i', '', basename( $leading_nav_fixture ) ) );
		$leading_nav_header      = $read( $leading_nav_result['theme_dir'] . '/parts/header.html' );
		$leading_nav_pattern     = $pattern_blocks( $read( $leading_nav_result['theme_dir'] . '/patterns/page-' . $leading_nav_source_slug . '.php' ) );
		$assert( str_contains( $leading_nav_header, 'Studio Code' ), 'leading-nav-header-preserves-logo' );
		$assert( str_contains( $leading_nav_header, 'Early Access' ), 'leading-nav-header-preserves-badge' );
		$assert( str_contains( $leading_nav_header, 'Get Started' ), 'leading-nav-header-preserves-cta' );
		$assert( ! str_contains( $leading_nav_header, 'Launch with Studio' ), 'leading-nav-header-excludes-hero' );
		$assert( str_contains( $leading_nav_pattern, 'Launch with Studio' ), 'leading-nav-pattern-preserves-hero-heading' );
		$assert( str_contains( $leading_nav_pattern, 'Hero copy.' ), 'leading-nav-pattern-preserves-hero-copy' );
	}
}

$branded_nav_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-branded-nav-header.html';
$wrote_branded_nav   = file_put_contents(
	$branded_nav_fixture,
	'<!doctype html><html><head><title>Branded Nav Header</title></head><body>' .
	'<nav class="nav-shell">' .
	'<a href="#" class="nav-brand"><div class="nav-logo">SC</div><span class="nav-name">Studio Code</span><span class="nav-badge">New</span></a>' .
	'<ul class="nav-links"><li><a href="#benefits">Benefits</a></li><li><a href="#workflow">How it works</a></li><li><a href="#use-cases">Use cases</a></li><li><a href="#cta" class="nav-cta">Get started</a></li></ul>' .
	'</nav>' .
	'<main><section id="benefits"><h1>Benefits</h1><p>Body copy.</p></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_branded_nav, 'branded-nav-fixture-written' );

if ( false !== $wrote_branded_nav ) {
	$branded_nav_result = Static_Site_Importer_Theme_Generator::import_theme(
		$branded_nav_fixture,
		array(
			'name'      => 'Branded Nav Header',
			'slug'      => 'branded-nav-header',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $branded_nav_result ), 'branded-nav-import-succeeds', is_wp_error( $branded_nav_result ) ? $branded_nav_result->get_error_message() : '' );
	if ( ! is_wp_error( $branded_nav_result ) ) {
		$branded_nav_header = $read( $branded_nav_result['theme_dir'] . '/parts/header.html' );
		$assert( str_contains( $branded_nav_header, 'nav-brand' ), 'branded-nav-header-preserves-brand-anchor' );
		$assert( str_contains( $branded_nav_header, 'nav-logo' ), 'branded-nav-header-preserves-logo-markup' );
		$assert( str_contains( $branded_nav_header, 'Studio Code' ), 'branded-nav-header-preserves-brand-text' );
		$assert( str_contains( $branded_nav_header, '<!-- wp:list {"className":"nav-links"} -->' ), 'branded-nav-header-preserves-list-block-owner' );
		$assert( str_contains( $branded_nav_header, '<ul class="wp-block-list nav-links">' ), 'branded-nav-header-preserves-list-class-on-ul' );
		$assert( ! str_contains( $branded_nav_header, '"className":"nav-links"} /-->' ), 'branded-nav-header-does-not-move-list-class-to-navigation' );
		$assert( str_contains( $branded_nav_header, '<a href="#benefits">Benefits</a>' ), 'branded-nav-menu-includes-benefits' );
		$assert( str_contains( $branded_nav_header, '<a href="#cta" class="nav-cta">Get started</a>' ), 'branded-nav-menu-includes-cta' );
	}
}

$footer_brand_anchor_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-footer-brand-anchor.html';
$wrote_footer_brand_anchor   = file_put_contents(
	$footer_brand_anchor_fixture,
	'<!doctype html><html><head><title>Footer Brand Anchor</title></head><body>' .
	'<main><section><h1>Footer brand anchor</h1><p>Body copy.</p></section></main>' .
	'<footer>' .
	'<a href="#" class="footer-brand"><div class="footer-logo-mark"><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 4h4v4H2zM8 4h4v1.5H8zM8 7h4v1.5H8zM2 9.5h10v1H2z" fill="white"/></svg></div><span class="footer-wordmark">Relay Atlas</span></a>' .
	'<ul class="footer-links"><li><a href="#features">Features</a></li><li><a href="#pricing">Pricing</a></li></ul>' .
	'<span class="footer-copy">&copy; 2025 Relay Atlas, Inc.</span>' .
	'</footer>' .
	'</body></html>'
);
$assert( false !== $wrote_footer_brand_anchor, 'footer-brand-anchor-fixture-written' );

if ( false !== $wrote_footer_brand_anchor ) {
	$footer_brand_anchor_result = Static_Site_Importer_Theme_Generator::import_theme(
		$footer_brand_anchor_fixture,
		array(
			'name'      => 'Footer Brand Anchor',
			'slug'      => 'footer-brand-anchor',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $footer_brand_anchor_result ), 'footer-brand-anchor-import-succeeds', is_wp_error( $footer_brand_anchor_result ) ? $footer_brand_anchor_result->get_error_message() : '' );
	if ( ! is_wp_error( $footer_brand_anchor_result ) ) {
		$footer_brand_anchor_footer = $read( $footer_brand_anchor_result['theme_dir'] . '/parts/footer.html' );
		$footer_brand_anchor_report = json_decode( $read( $footer_brand_anchor_result['report_path'] ?? '' ), true );
		$assert( ! str_contains( $footer_brand_anchor_footer, '<!-- wp:html' ), 'footer-brand-anchor-has-no-core-html-blocks', $footer_brand_anchor_footer );
		$assert( str_contains( $footer_brand_anchor_footer, '<a href="#" class="footer-brand">' ), 'footer-brand-anchor-keeps-one-brand-anchor', $footer_brand_anchor_footer );
		$assert( str_contains( $footer_brand_anchor_footer, '<span class="footer-logo-mark"><img' ), 'footer-brand-anchor-keeps-logo-class-inside-anchor', $footer_brand_anchor_footer );
		$assert( str_contains( $footer_brand_anchor_footer, '<span class="footer-wordmark">Relay Atlas</span>' ), 'footer-brand-anchor-keeps-wordmark-inside-anchor', $footer_brand_anchor_footer );
		$assert( ! str_contains( $footer_brand_anchor_footer, '<div class="wp-block-group footer-brand">' ), 'footer-brand-anchor-class-stays-on-anchor' );
		$assert( ! str_contains( $footer_brand_anchor_footer, '<a href="#">Relay Atlas</a>' ), 'footer-brand-anchor-does-not-split-wordmark-link' );
		$assert( ! preg_match( '/<p[^>]*>\s*<a[^>]*>.*<div/is', $footer_brand_anchor_footer ), 'footer-brand-anchor-avoids-invalid-paragraph-anchor-content', $footer_brand_anchor_footer );
		$assert( str_contains( $footer_brand_anchor_footer, '<!-- wp:list {"className":"footer-links"}' ), 'footer-brand-anchor-footer-uses-visible-list-block' );
		$assert( ! str_contains( $footer_brand_anchor_footer, '<!-- wp:navigation ' ), 'footer-brand-anchor-footer-does-not-use-navigation-block' );
		$assert( str_contains( $footer_brand_anchor_footer, '2025 Relay Atlas, Inc.' ), 'footer-brand-anchor-keeps-copy' );
		$assert( str_contains( $footer_brand_anchor_footer, 'Features' ) && str_contains( $footer_brand_anchor_footer, 'Pricing' ), 'footer-brand-anchor-list-includes-links' );
		$assert( 0 === ( $footer_brand_anchor_report['quality']['core_html_block_count'] ?? -1 ), 'footer-brand-anchor-report-has-zero-core-html-blocks' );
		$assert( 0 === ( $footer_brand_anchor_report['quality']['invalid_block_count'] ?? -1 ), 'footer-brand-anchor-report-has-zero-invalid-blocks' );
	}
}

$pure_nav_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-pure-nav-header.html';
$wrote_pure_nav   = file_put_contents(
	$pure_nav_fixture,
	'<!doctype html><html><head><title>Pure Nav Header</title></head><body>' .
	'<nav class="top-nav"><a href="#intro">Intro</a><a href="#pricing"><span>Pricing</span></a></nav>' .
	'<main><section id="intro"><h1>Intro</h1><p>Body copy.</p></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_pure_nav, 'pure-nav-fixture-written' );

if ( false !== $wrote_pure_nav ) {
	$pure_nav_result = Static_Site_Importer_Theme_Generator::import_theme(
		$pure_nav_fixture,
		array(
			'name'      => 'Pure Nav Header',
			'slug'      => 'pure-nav-header',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $pure_nav_result ), 'pure-nav-import-succeeds', is_wp_error( $pure_nav_result ) ? $pure_nav_result->get_error_message() : '' );
	if ( ! is_wp_error( $pure_nav_result ) ) {
		$pure_nav_header = $read( $pure_nav_result['theme_dir'] . '/parts/header.html' );
		$pure_nav_post   = get_page_by_path( 'pure-nav-header-header-navigation', OBJECT, 'wp_navigation' );
		$assert( str_contains( $pure_nav_header, '<!-- wp:navigation ' ), 'pure-nav-header-uses-navigation-block' );
		$assert( str_contains( $pure_nav_header, '"className":"top-nav"' ), 'pure-nav-header-preserves-class-on-navigation-block' );
		$assert( ! str_contains( $pure_nav_header, '"tagName":"nav"' ), 'pure-nav-header-does-not-wrap-navigation-in-nav-group' );
		$assert( $pure_nav_post instanceof WP_Post, 'pure-nav-post-exists' );
		if ( $pure_nav_post instanceof WP_Post ) {
			$assert( str_contains( $pure_nav_post->post_content, '"label":"Intro"' ), 'pure-nav-menu-includes-intro' );
			$assert( str_contains( $pure_nav_post->post_content, '"label":"Pricing"' ), 'pure-nav-menu-includes-pricing' );
		}
	}
}

$legacy_visual_parity_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-visual-parity-probes.html';
if ( file_exists( $legacy_visual_parity_fixture ) ) {
	wp_delete_file( $legacy_visual_parity_fixture );
}
$visual_parity_dir     = trailingslashit( get_temp_dir() ) . 'static-site-importer-visual-parity-probes-' . wp_generate_uuid4();
$visual_parity_fixture = trailingslashit( $visual_parity_dir ) . 'index.html';
wp_mkdir_p( $visual_parity_dir );
$wrote_visual_parity   = file_put_contents(
	$visual_parity_fixture,
	'<!doctype html><html><head><title>Visual Parity Probes</title><style>' .
	'nav { display: flex; justify-content: space-between; }' .
	'.code-window { display: block; min-height: 120px; }' .
	'.problem-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }' .
	'</style></head><body>' .
	'<nav class="nav-shell"><a href="#hero">Visual Parity</a><a href="#problems">Problems</a></nav>' .
	'<main><section id="hero" class="hero"><div class="code-window"><pre class="code-body">wp static-site-importer import-theme ./site</pre></div></section>' .
	'<section id="problems"><div class="problem-grid"><article class="problem-card"><h2>Static is winning</h2><p>Fast HTML wins mindshare.</p></article><article class="problem-card"><h2>WordPress can answer</h2><p>Editable blocks preserve the work.</p></article></div></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_visual_parity, 'visual-parity-fixture-written' );

if ( false !== $wrote_visual_parity ) {
	$visual_parity_result = Static_Site_Importer_Theme_Generator::import_theme(
		$visual_parity_fixture,
		array(
			'name'      => 'Visual Parity Probes',
			'slug'      => 'visual-parity-probes',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $visual_parity_result ), 'visual-parity-import-succeeds', is_wp_error( $visual_parity_result ) ? $visual_parity_result->get_error_message() : '' );
	if ( ! is_wp_error( $visual_parity_result ) ) {
		$visual_parity_report = json_decode( $read( $visual_parity_result['report_path'] ?? '' ), true );
		$visual_target        = $visual_parity_report['visual_fidelity']['comparison_targets'][0] ?? array();
		$layout_probes        = $visual_target['comparison_hooks']['layout_probes'] ?? array();

		$assert( ( $visual_target['source_probe_counts']['code_visual_candidates'] ?? 0 ) > 0, 'visual-parity-counts-source-code-visual-probes' );
		$assert( ( $visual_target['source_probe_counts']['grid_candidates'] ?? 0 ) > 0, 'visual-parity-counts-source-grid-probes' );
		$assert( ( $visual_target['generated_probe_counts']['code_visual_candidates'] ?? 0 ) > 0, 'visual-parity-counts-generated-code-visual-probes' );
		$assert( ( $visual_target['generated_probe_counts']['grid_candidates'] ?? 0 ) > 0, 'visual-parity-counts-generated-grid-probes' );
		$assert( 2 === ( $layout_probes['problem_grid']['min_columns'] ?? 0 ), 'visual-parity-problem-grid-requires-two-desktop-columns' );
		$assert( in_array( 'children_same_row_desktop', $layout_probes['problem_grid']['assertions'] ?? array(), true ), 'visual-parity-problem-grid-checks-same-row-desktop' );
		$assert( in_array( '.code-window', $visual_target['comparison_hooks']['code_visuals'] ?? array(), true ), 'visual-parity-exposes-code-window-selector' );
		$assert( in_array( '.problem-grid', $visual_target['comparison_hooks']['problem_grids'] ?? array(), true ), 'visual-parity-exposes-problem-grid-selector' );
	}
}

$nested_header_fixture = trailingslashit( get_temp_dir() ) . 'static-site-importer-nested-section-header.html';
$wrote_nested_header   = file_put_contents(
	$nested_header_fixture,
	'<!doctype html><html><head><title>Nested Section Header</title></head><body>' .
	'<main><section class="proof"><div class="proof-inner"><header class="proof-header">' .
	'<p class="section-label">Why It Actually Works</p>' .
	'<h2 class="proof-heading reveal">Six reasons the assumptions are wrong.</h2>' .
	'</header><p class="proof-copy">The section body stays with the section.</p></div></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_nested_header, 'nested-header-fixture-written' );

if ( false !== $wrote_nested_header ) {
	$nested_header_result = Static_Site_Importer_Theme_Generator::import_theme(
		$nested_header_fixture,
		array(
			'name'      => 'Nested Section Header',
			'slug'      => 'nested-section-header',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $nested_header_result ), 'nested-header-import-succeeds', is_wp_error( $nested_header_result ) ? $nested_header_result->get_error_message() : '' );
	if ( ! is_wp_error( $nested_header_result ) ) {
		$nested_header  = $read( $nested_header_result['theme_dir'] . '/parts/header.html' );
		$nested_pattern = $pattern_blocks( $read( $nested_header_result['theme_dir'] . '/patterns/page-static-site-importer-nested-section-header.php' ) );
		$assert( ! str_contains( $nested_header, 'proof-header' ), 'nested-section-header-not-extracted-to-global-header' );
		$assert( ! str_contains( $nested_header, 'Six reasons the assumptions are wrong.' ), 'nested-section-heading-not-extracted-to-global-header' );
		$assert( str_contains( $nested_pattern, 'proof-header' ), 'nested-section-header-class-stays-in-page-content' );
		$assert( str_contains( $nested_pattern, 'Why It Actually Works' ), 'nested-section-label-stays-in-page-content' );
		$assert( str_contains( $nested_pattern, 'Six reasons the assumptions are wrong.' ), 'nested-section-heading-stays-in-page-content' );
	}
}

$nested_footer_dir     = trailingslashit( get_temp_dir() ) . 'static-site-importer-nested-footer-' . wp_generate_uuid4();
$nested_footer_fixture = trailingslashit( $nested_footer_dir ) . 'index.html';
wp_mkdir_p( $nested_footer_dir );
$wrote_nested_footer   = file_put_contents(
	$nested_footer_fixture,
	'<!doctype html><html><head><title>Nested Footer Bar</title></head><body>' .
	'<nav class="top-nav"><a href="/">Home</a></nav>' .
	'<main><section class="cta"><h1>Ship the site</h1><p>CTA body.</p><div class="footer-bar"><span>Fine print stays with the CTA.</span></div></section></main>' .
	'</body></html>'
);
$assert( false !== $wrote_nested_footer, 'nested-footer-fixture-written' );

if ( false !== $wrote_nested_footer ) {
	$nested_footer_result = Static_Site_Importer_Theme_Generator::import_theme(
		$nested_footer_fixture,
		array(
			'name'      => 'Nested Footer Bar',
			'slug'      => 'nested-footer-bar',
			'overwrite' => true,
			'activate'  => false,
		)
	);
	$assert( ! is_wp_error( $nested_footer_result ), 'nested-footer-import-succeeds', is_wp_error( $nested_footer_result ) ? $nested_footer_result->get_error_message() : '' );
	if ( ! is_wp_error( $nested_footer_result ) ) {
		$nested_footer_theme_dir = $nested_footer_result['theme_dir'];
		$nested_footer_pattern   = $pattern_blocks( $read( $nested_footer_theme_dir . '/patterns/page-home.php' ) );
		$nested_footer_page      = $read( $nested_footer_theme_dir . '/templates/page.html' );
		$nested_footer_template  = $read( $nested_footer_theme_dir . '/templates/front-page.html' );
		$nested_footer_report    = json_decode( $read( $nested_footer_result['report_path'] ?? '' ), true );

		$assert( ! file_exists( $nested_footer_theme_dir . '/parts/footer.html' ), 'nested-footer-does-not-emit-empty-footer-part' );
		$assert( ! str_contains( $nested_footer_page, '"slug":"footer"' ), 'nested-footer-page-template-does-not-reference-footer-part' );
		$assert( ! str_contains( $nested_footer_template, '"slug":"footer"' ), 'nested-footer-specific-template-does-not-reference-footer-part' );
		$assert( str_contains( $nested_footer_pattern, 'footer-bar' ), 'nested-footer-bar-remains-in-page-content' );
		$assert( str_contains( $nested_footer_pattern, 'Fine print stays with the CTA.' ), 'nested-footer-copy-remains-in-page-content' );
		$assert( ! in_array( 'parts/footer.html', $nested_footer_report['visual_fidelity']['comparison_targets'][0]['comparison_hooks']['generated_files'] ?? array(), true ), 'nested-footer-report-omits-footer-part-file' );
	}
}

$quality_dir     = trailingslashit( get_temp_dir() ) . 'static-site-importer-quality-' . wp_generate_uuid4();
$quality_fixture = trailingslashit( $quality_dir ) . 'index.html';
wp_mkdir_p( $quality_dir );
$wrote_quality = file_put_contents(
	$quality_fixture,
	'<!doctype html><html><head><title>Quality</title></head><body><main><section><h1>Quality smoke</h1><iframe src="https://example.com/widget"></iframe></section></main></body></html>'
);
$assert( false !== $wrote_quality, 'quality-fixture-written' );

if ( false !== $wrote_quality ) {
	$quality_result = Static_Site_Importer_Theme_Generator::import_theme(
		$quality_fixture,
		array(
			'name'            => 'Quality Fixture',
			'slug'            => 'quality-fixture',
			'overwrite'       => true,
			'activate'        => false,
			'max_fallbacks'   => 0,
		)
	);
	$assert( ! is_wp_error( $quality_result ), 'quality-import-writes-theme-for-inspection', is_wp_error( $quality_result ) ? $quality_result->get_error_message() : '' );
	if ( ! is_wp_error( $quality_result ) ) {
		$quality_report = json_decode( $read( $quality_result['report_path'] ), true );
		$assert( 1 === ( $quality_result['quality']['fallback_count'] ?? 0 ), 'quality-result-counts-fallbacks' );
		$assert( false === ( $quality_result['quality']['pass'] ?? true ), 'quality-result-fails-when-fallbacks-exist' );
		$assert( true === ( $quality_result['quality']['fail_import'] ?? false ), 'quality-gate-fails-when-max-fallbacks-exceeded' );
		$fallback_diagnostics = is_array( $quality_report ) ? array_values(
			array_filter(
				$quality_report['diagnostics'] ?? array(),
				static fn ( $diagnostic ): bool => is_array( $diagnostic ) && 'unsupported_html_fallback' === ( $diagnostic['type'] ?? '' )
			)
		) : array();
		$assert( ! empty( $fallback_diagnostics ), 'quality-report-records-fallback-diagnostic' );
		$fallback_diagnostic = $fallback_diagnostics[0] ?? array();
		$assert( str_starts_with( (string) ( $fallback_diagnostic['id'] ?? '' ), 'diag-' ), 'quality-fallback-diagnostic-has-id' );
		$assert( 'warning' === ( $fallback_diagnostic['severity'] ?? '' ), 'quality-fallback-diagnostic-has-severity' );
		$assert( 'unsupported_element' === ( $fallback_diagnostic['category'] ?? '' ), 'quality-fallback-diagnostic-has-category' );
		$assert( 'replace_unsupported_html' === ( $fallback_diagnostic['suggested_repair_class'] ?? '' ), 'quality-fallback-diagnostic-has-repair-class' );
		$assert( 'index.html' === ( $fallback_diagnostic['source_path'] ?? '' ), 'quality-fallback-diagnostic-has-source-path' );
		$fallback_refs = $quality_report['quality']['diagnostic_refs']['fallback_count'] ?? array();
		$assert( is_array( $fallback_refs ) && in_array( $fallback_diagnostic['id'] ?? '', $fallback_refs, true ), 'quality-fallback-count-links-to-diagnostic' );
	}
}

if ( $expected_failures ) {
	fwrite( STDERR, implode( "\n", $expected_failures ) . "\n" );
}

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo 'OK: wordpress-is-dead fixture smoke passed (' . $assertions . ' assertions';
if ( $expected_failures ) {
	echo ', ' . count( $expected_failures ) . ' expected upstream fidelity failures';
}
echo ")\n";
