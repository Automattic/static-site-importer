<?php
/**
 * Real WordPress acceptance: existing-theme CSS needs no importer runtime.
 * Run on a disposable WordPress 7+ installation via wp eval-file.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	throw new RuntimeException( 'A disposable WordPress runtime is required.' );
}
require_once dirname( __DIR__ ) . '/static-site-importer.php';
wp_set_current_user( 1 );
do_action( 'wp_abilities_api_categories_init' );
do_action( 'wp_abilities_api_init' );
echo 'WordPress ' . get_bloginfo( 'version' ) . "\n";
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS ' . $message . "\n";
};
$assert( version_compare( get_bloginfo( 'version' ), '7.0', '>=' ), 'WordPress 7.0+' );
switch_theme( 'twentytwentyfive' );
$assert( 'twentytwentyfive' === get_stylesheet(), 'Twenty Twenty-Five active' );
$seed = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Edited owner page', 'post_content' => '<!-- wp:paragraph --><p>Owner edits stay here.</p><!-- /wp:paragraph -->' ) );
$seed_before = get_post( $seed )->to_array();
$additional = wp_update_custom_css_post( '/* owner Additional CSS */ body { --owner-color: purple; }' );
$additional_before = get_post( $additional->ID )->post_content;
$user = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
$original = get_post( $user['ID'] )->post_content;
$raw = json_decode( $original, true );
$owner_css = "/* owner Global Styles */\n.owner { color: purple; }\n";
$raw['styles']['css'] = $owner_css;
$raw['styles']['spacing']['blockGap'] = '19px';
wp_update_post( array( 'ID' => $user['ID'], 'post_content' => wp_slash( wp_json_encode( $raw ) ) ) );
WP_Theme_JSON_Resolver::clean_cached_data();
$snapshot = static function (): array {
	$hashes = array();
	foreach ( array( 'themes', 'plugins', 'mu-plugins' ) as $surface ) {
		$dir = WP_CONTENT_DIR . '/' . $surface;
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) {
				$hashes[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
	}
	ksort( $hashes );
	return $hashes;
};
$before = $snapshot();
$slug = 'existing-css-' . strtolower( wp_generate_password( 8, false, false ) );
$html = static fn( string $title ): string => '<!doctype html><html><head><title>' . $title . '</title><link rel="stylesheet" href="style.css"></head><body><main><h1>' . $title . '</h1><p>Imported content.</p></main></body></html>';
$input = array(
	'operation' => 'apply',
	'destination' => 'existing_theme',
	'slug' => $slug,
	'source' => array(
		'type' => 'files',
		'entrypoint' => 'index.html',
		'files' => array(
			array( 'path' => 'index.html', 'content' => $html( $slug ) ),
			array( 'path' => $slug . '.html', 'content' => $html( 'Second ' . $slug ) ),
			array( 'path' => 'style.css', 'content' => 'body{color:#123456}h1{color:#234567}@media(min-width:700px){h1{color:#345678}}@supports(display:grid){header{display:grid}}@container(min-width:300px){footer{color:red}}main{background-image:url(pixel.png)}' ),
			array( 'path' => 'pixel.png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6ZQAAAABJRU5ErkJggg==' ),
		),
	),
);
$run = static function ( array $request ) use ( $assert ): array {
	$ability = wp_get_ability( 'static-site-importer/import' );
	for ( $i = 0; $i < 40; ++$i ) {
		$code = 'require_once ' . var_export( dirname( __DIR__ ) . '/static-site-importer.php', true ) . '; wp_set_current_user(1); do_action("wp_abilities_api_categories_init"); do_action("wp_abilities_api_init"); echo wp_json_encode(wp_get_ability("static-site-importer/import")->execute(json_decode(' . var_export( wp_json_encode( $request ), true ) . ',true)));';
		$result = json_decode( WP_CLI::runcommand( 'eval ' . escapeshellarg( $code ), array( 'return' => 'stdout', 'launch' => true ) ), true );
		$assert( ! is_wp_error( $result ) && ! empty( $result['success'] ), 'public ability succeeds: ' . ( is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result['error'] ?? array() ) ) );
		if ( empty( $result['continuation'] ) ) {
			return $result;
		}
		$request['source'] = array( 'type' => 'files', 'import_id' => $result['import_id'] );
		if ( isset( $result['result']['runtime_lifecycle_checkpoint'] ) ) {
			$request['runtime_lifecycle_phase'] = 'resume';
			$request['runtime_lifecycle_checkpoint'] = $result['result']['runtime_lifecycle_checkpoint'];
			$request['runtime_lifecycle_request_id'] = $result['result']['fresh_runtime']['request_id'] ?? '';
		}
	}
	throw new RuntimeException( 'Import continuation did not terminate.' );
};
try {
	$plan_input = $input;
	$plan_input['operation'] = 'plan';
	$plan = $run( $plan_input );
	$assert( 'existing_theme' === $plan['destination'], 'public plan preserves destination' );
	$assert( str_contains( wp_json_encode( $plan ), 'existing_theme_styles_per_theme' ), 'public plan reports theme-switch limitation' );
	$result = $run( $input );
	clean_post_cache( $user['ID'] );
	WP_Theme_JSON_Resolver::clean_cached_data();
	$css = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['styles']['css'];
	$assert( str_starts_with( $css, $owner_css ), 'owner Global Styles CSS preserved byte-for-byte' );
	$assert( str_contains( $css, '@media(min-width:700px)' ) && str_contains( $css, '@supports' ) && str_contains( $css, '@container' ), 'conditional CSS survives core storage' );
	$assert( ! preg_match( '/(?:^|[{}])\s*(?:body|h1)\s*\{/', $css ), 'no unscoped body or h1 rule' );
	$assert( str_contains( $css, wp_upload_dir()['baseurl'] ), 'CSS image URL points to a Media Library upload' );
	$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_static_site_importer_provenance' ) );
	$imported = array();
	foreach ( $posts as $post ) {
		if ( ! str_contains( $post->post_content, 'ssi-page-' ) || ! str_contains( $post->post_title, $slug ) ) {
			continue;
		}
		$imported[] = $post->ID;
		$blocks = parse_blocks( $post->post_content );
		$assert( 'core/group' === $blocks[0]['blockName'] && str_starts_with( $blocks[0]['attrs']['className'], 'ssi-page-' ), 'page has one stable classed root group' );
	}
	$assert( 2 === count( $imported ), 'two imported pages' );
	$after_files = $snapshot();
	$assert( $before === $after_files, 'themes/plugins/mu-plugins hash before equals after: ' . wp_json_encode( array_keys( array_diff_assoc( $after_files, $before ) ) ) );
	$assert( 'twentytwentyfive' === get_stylesheet() && $seed_before === get_post( $seed )->to_array(), 'theme and edited owner page unchanged' );
	$assert( $additional_before === get_post( $additional->ID )->post_content, 'owner Additional CSS unchanged' );
	$input['overwrite'] = true;
	$run( $input );
	clean_post_cache( $user['ID'] );
	WP_Theme_JSON_Resolver::clean_cached_data();
	$again = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data()['styles']['css'];
	$assert( $css === $again, 're-import replaces its section without duplication' );
	// Core CSS emission is independent of any importer callbacks or registration.
	// A new CLI process with --skip-plugins=static-site-importer verifies this.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		$rendered = WP_CLI::runcommand( '--skip-plugins=static-site-importer eval ' . escapeshellarg( 'wp_head(); echo do_blocks(get_post(' . $imported[0] . ')->post_content);' ), array( 'return' => 'stdout', 'launch' => true ) );
		$assert( str_contains( $rendered, '@media(min-width:700px)' ) && str_contains( $rendered, 'ssi-page-' ), 'deactivated importer: frontend core emits scoped conditional CSS and page content' );
	}
	preg_match( '~/\* static-site-importer:([a-f0-9]+):start \*/~', $css, $match );
	$removed = Static_Site_Importer_Existing_Theme_Styles::remove( $match[1] );
	$assert( ! is_wp_error( $removed ), 'import section removal succeeds' );
	foreach ( $imported as $id ) {
		clean_post_cache( $id );
		$assert( null === get_post( $id ), 'removal deletes only import-owned pages' );
	}
	$after = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
	$assert( $owner_css === $after['styles']['css'] && '19px' === $after['styles']['spacing']['blockGap'], 'removal preserves only owner CSS and unrelated Global Styles settings' );
	$assert( $before === $snapshot(), 're-import and removal write no protected files' );
} finally {
	foreach ( $imported ?? array() as $id ) {
		wp_delete_post( $id, true );
	}
	wp_delete_post( $seed, true );
	wp_update_post( array( 'ID' => $user['ID'], 'post_content' => wp_slash( $original ) ) );
	WP_Theme_JSON_Resolver::clean_cached_data();
}
