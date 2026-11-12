<?php
/** Real WordPress: add, reuse and remove a generated host block. @package StaticSiteImporter */
require_once dirname( __DIR__ ) . '/static-site-importer.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-existing-theme-styles.php';
wp_set_current_user( 1 );
$assert   = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS ' . $message . "\n";
};
$run      = static function ( array $request ) use ( $assert ): array {
	for ( $i = 0; $i < 40; ++$i ) {
		$code   = 'wp_set_current_user(1); do_action("wp_abilities_api_categories_init"); do_action("wp_abilities_api_init"); echo wp_json_encode(wp_get_ability("static-site-importer/import")->execute(json_decode(' . var_export( wp_json_encode( $request ), true ) . ',true)));';
		$result = json_decode( WP_CLI::runcommand( 'eval ' . escapeshellarg( $code ), array(
			'return' => 'stdout',
			'launch' => true,
		) ), true );
		$assert( ! empty( $result['success'] ), 'public import succeeds' . ( empty( $result['success'] ) ? ': ' . wp_json_encode( $result ) : '' ) );
		if ( empty( $result['continuation'] ) ) {
			return $result;
		}
		$request['source'] = array(
			'type'      => 'files',
			'import_id' => $result['import_id'],
		);
		if ( isset( $result['result']['runtime_lifecycle_checkpoint'] ) ) {
			$request['runtime_lifecycle_phase']      = 'resume';
			$request['runtime_lifecycle_checkpoint'] = $result['result']['runtime_lifecycle_checkpoint'];
			$request['runtime_lifecycle_request_id'] = $result['result']['fresh_runtime']['request_id'] ?? '';
		}
	}
	throw new RuntimeException( 'Import did not terminate.' );
};
$slug     = 'fold-' . strtolower( wp_generate_password( 8, false, false ) );
$previous = get_stylesheet();
$input    = array(
	'operation' => 'apply',
	'slug'      => $slug,
	'activate'  => true,
	'source'    => array(
		'type'       => 'files',
		'entrypoint' => 'index.html',
		'files'      => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><title>Host</title></head><body><main><p>Host page</p></main></body></html>',
			), ),
	),
);
$snapshot = static function ( string $dir ): array {
	$out = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() ) {
			$out[ $file->getPathname() ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
	ksort( $out );
	return $out;
};
try {
	$run( $input );
	wp_cache_delete( 'stylesheet', 'options' );
	switch_theme( $slug );
	$dir                           = get_stylesheet_directory() . '/ssi-runtime';
	$before                        = $snapshot( $dir );
	$input['destination']          = 'existing_theme';
	$input['activate']             = false;
	$input['slug']                 = $slug . '-added';
	$input['source']['entrypoint'] = 'added.html';
	$input['source']['files']      = array(
		array(
			'path'    => 'added.html',
			'content' => '<!doctype html><html><head><title>Added</title></head><body><main><dl><dt>Term</dt><dd>Definition</dd></dl></main></body></html>',
		), );
	$run( $input );
	$after = $snapshot( $dir );
	$assert( count( $after ) > count( $before ), 'missing description-list block added to host runtime' );
	$posts = get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'any',
		'numberposts' => -1,
		'meta_key'    => '_static_site_importer_css_section',
	) );
	$id    = Static_Site_Importer_Existing_Theme_Styles::identity( array( 'args' => $input ) );
	$found = false;
	foreach ( $posts as $post ) {
		if ( $id === get_post_meta( $post->ID, '_static_site_importer_css_section', true ) ) {
			$found = str_contains( $post->post_content, 'ssi-' . $slug . '/description-list' );
		}
	}
	$assert( $found, 'added page uses host namespace' );
	$input['overwrite'] = true;
	$run( $input );
	$assert( $after === $snapshot( $dir ), 're-import reuses host block byte-for-byte' );
	$result = Static_Site_Importer_Existing_Theme_Styles::remove( $id );
	$assert( ! is_wp_error( $result ), 'removal succeeds' );
	$assert( $before === $snapshot( $dir ), 'removal restores runtime byte-for-byte' );
} finally {
	switch_theme( $previous );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	$GLOBALS['wp_filesystem']->delete( get_theme_root() . '/' . $slug, true );
}
