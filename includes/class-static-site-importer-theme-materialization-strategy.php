<?php
/** Theme presentation strategy selection. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
final class Static_Site_Importer_Theme_Materialization_Strategy {
	public const BLOCK                      = 'block';
	public const CLASSIC                    = 'classic';
	private const CLASSIC_PROJECTION_SCHEMA = 'blocks-engine/classic-theme-projection/v1';
	public static function normalize( array $args ) {
		$strategy = isset( $args['theme_materialization'] ) && is_scalar( $args['theme_materialization'] ) ? (string) $args['theme_materialization'] : self::BLOCK;
		if ( ! in_array( $strategy, array( self::BLOCK, self::CLASSIC ), true ) ) {
			return new WP_Error( 'static_site_importer_theme_materialization_invalid', 'theme_materialization must be block or classic.' ); }
		return array(
			'strategy' => $strategy,
			'evidence' => array(
				'schema'   => 'static-site-importer/theme-materialization-evidence/v1',
				'strategy' => $strategy,
				'status'   => self::BLOCK === $strategy ? 'selected' : 'pending_compiler_contract',
			),
		);
	}
	public static function classic_contract_evidence( array $compiled ): array {
		$reports    = is_array( $compiled['source_reports'] ?? null ) ? $compiled['source_reports'] : array();
		$projection = is_array( $reports['classic_theme_projection'] ?? null ) ? $reports['classic_theme_projection'] : array();
		$missing    = array();
		if ( self::CLASSIC_PROJECTION_SCHEMA !== ( $projection['schema'] ?? null ) ) {
			$missing[] = 'source_reports.classic_theme_projection schema blocks-engine/classic-theme-projection/v1'; }
		foreach ( array( 'pages', 'shared_chrome', 'stylesheets', 'asset_references', 'runtime_binding_slots' ) as $field ) {
			if ( ! isset( $projection[ $field ] ) || ! is_array( $projection[ $field ] ) ) {
				$missing[] = 'source_reports.classic_theme_projection.' . $field; }
		}
		return array(
			'schema'                => 'static-site-importer/theme-materialization-evidence/v1',
			'strategy'              => self::CLASSIC,
			'status'                => empty( $missing ) ? 'contract_present_unimplemented' : 'blocked_missing_compiler_contract',
			'compiler_report_path'  => 'source_reports.classic_theme_projection',
			'expected_schema'       => self::CLASSIC_PROJECTION_SCHEMA,
			'missing_data_contract' => $missing,
			'fixed_scaffold_schema' => 'static-site-importer/managed-classic-scaffold/v1',
		);
	}
	/** The only PHP SSI emits; content/chrome remain sanitized data files. */
	public static function fixed_classic_scaffold( string $name ): array {
		// Theme headers are comments: retain only one bounded printable line so artifact
		// metadata cannot terminate or inject header directives.
		$name = preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $name ) ?? '';
		$name = str_replace( '*/', '* /', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );
		$name = substr( $name, 0, 200 );
		if ( '' === $name ) {
			$name = 'Static Site Import'; }
		$functions = <<<'PHP'
<?php
function static_site_importer_classic_assets() {
	wp_enqueue_style( 'static-site-importer-classic', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'static_site_importer_classic_assets' );
function static_site_importer_classic_render_binding( $id, $bindings, $source, $page_hash, $surface = 'page' ) {
	$binding = $bindings[ $id ] ?? array();
	if ( ! is_array( $binding ) || ! isset( $binding['kind'], $binding['content'], $binding['source_path'], $binding['page_hash'] ) || ( 'page' === $surface && $source !== $binding['source_path'] ) || $surface !== ( $binding['surface'] ?? 'page' ) || ! hash_equals( $binding['page_hash'], $page_hash ) ) { return ''; }
	if ( 'shortcode' === $binding['kind'] ) { return do_shortcode( $binding['content'] ); }
	if ( 'blocks' === $binding['kind'] && function_exists( 'do_blocks' ) ) { return do_blocks( $binding['content'] ); }
	return '';
}
function static_site_importer_classic_chrome( $slot ) {
	if ( ! in_array( $slot, array( 'header', 'footer' ), true ) ) { return; }
	$chrome = wp_json_file_decode( get_stylesheet_directory() . '/classic-chrome.json', array( 'associative' => true ) );
	$bindings = wp_json_file_decode( get_stylesheet_directory() . '/classic-bindings.json', array( 'associative' => true ) );
	$html = is_array( $chrome ) && is_string( $chrome[ $slot ] ?? null ) ? $chrome[ $slot ] : ''; $hash = hash( 'sha256', $html );
	$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), '_static_site_importer_provenance', true ), true ); $source = is_array( $provenance ) ? (string) ( $provenance['source_path'] ?? '' ) : '';
	echo preg_replace_callback( '/<!--static-site-importer-binding:([a-f0-9]{64})-->/', static function ( $match ) use ( $bindings, $hash, $slot, $source ) { return static_site_importer_classic_render_binding( $match[1], is_array( $bindings ) ? $bindings : array(), $source, $hash, $slot ); }, $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized chrome and fixed SSI binding records.
}
function static_site_importer_classic_render_current_page() {
	$provenance = json_decode( (string) get_post_meta( get_queried_object_id(), '_static_site_importer_provenance', true ), true );
	$source = is_array( $provenance ) ? (string) ( $provenance['source_path'] ?? '' ) : '';
	$data = wp_json_file_decode( get_stylesheet_directory() . '/classic-pages.json', array( 'associative' => true ) );
	$bindings = wp_json_file_decode( get_stylesheet_directory() . '/classic-bindings.json', array( 'associative' => true ) );
	$html = (string) ( $data['pages'][ $source ]['html'] ?? '' ); $page_hash = hash( 'sha256', $html );
	$html = preg_replace_callback( '/<!--static-site-importer-binding:([a-f0-9]{64})-->/', static function ( $match ) use ( $bindings, $source, $page_hash ) { return static_site_importer_classic_render_binding( $match[1], is_array( $bindings ) ? $bindings : array(), $source, $page_hash ); }, $html );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized artifact HTML and fixed SSI binding records.
}
PHP;
		return array(
			'style.css'      => "/*\nTheme Name: " . $name . "\nText Domain: static-site-importer\n*/\n",
			'functions.php'  => $functions . "\n",
			'header.php'     => "<!doctype html>\n<html <?php language_attributes(); ?>>\n<head>\n<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n<?php wp_head(); ?>\n</head>\n<body <?php body_class(); ?>>\n<?php wp_body_open(); static_site_importer_classic_chrome( 'header' ); ?>\n",
			'footer.php'     => "<?php static_site_importer_classic_chrome( 'footer' ); wp_footer(); ?>\n</body>\n</html>\n",
			'front-page.php' => "<?php get_header(); static_site_importer_classic_render_current_page(); get_footer();\n",
			'page.php'       => "<?php get_header(); static_site_importer_classic_render_current_page(); get_footer();\n",
			'single.php'     => "<?php get_header(); static_site_importer_classic_render_current_page(); get_footer();\n",
			'index.php'      => "<?php get_header(); if ( have_posts() ) { while ( have_posts() ) { the_post(); static_site_importer_classic_render_current_page(); } } get_footer();\n",
			'archive.php'    => "<?php get_header(); if ( have_posts() ) { while ( have_posts() ) { the_post(); static_site_importer_classic_render_current_page(); } } get_footer();\n",
			'search.php'     => "<?php get_template_part( 'archive' );\n",
			'404.php'        => "<?php get_template_part( 'index' );\n",
		);
	}
}
