<?php
/** Public caller page choices on both host kinds. @package StaticSiteImporter */
wp_set_current_user( 1 );
$assert = static function ( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	echo 'PASS ' . $message . "\n";
};
$run = static function ( array $request ) {
	for ( $i = 0; $i < 40; ++$i ) {
		$code = 'wp_set_current_user(1); do_action("wp_abilities_api_categories_init"); do_action("wp_abilities_api_init"); echo wp_json_encode(wp_get_ability("static-site-importer/import")->execute(json_decode(' . var_export( wp_json_encode( $request ), true ) . ',true)));';
		$result = json_decode( WP_CLI::runcommand( 'eval ' . escapeshellarg( $code ), array( 'return' => 'stdout', 'launch' => true ) ), true );
		if ( empty( $result['continuation'] ) || empty( $result['success'] ) ) {
			return $result;
		}
		$request['source'] = array( 'type' => 'files', 'import_id' => $result['import_id'] );
		if ( isset( $result['result']['runtime_lifecycle_checkpoint'] ) ) {
			$request['runtime_lifecycle_phase'] = 'resume';
			$request['runtime_lifecycle_checkpoint'] = $result['result']['runtime_lifecycle_checkpoint'];
			$request['runtime_lifecycle_request_id'] = $result['result']['fresh_runtime']['request_id'] ?? '';
		}
	}
	throw new RuntimeException( 'Continuation limit' );
};
$html = static fn( $title, $links = '' ) => '<!doctype html><html><head><title>' . $title . '</title></head><body><main><h1>' . $title . '</h1><p>' . $links . '</p></main></body></html>';
foreach ( array( 'twentytwentyfive', 'generated' ) as $host ) {
	if ( 'generated' === $host ) {
		$result = $run( array( 'operation' => 'apply', 'slug' => 'choices-host', 'activate' => true, 'source' => array( 'type' => 'files', 'entrypoint' => 'index.html', 'files' => array( array( 'path' => 'index.html', 'content' => $html( 'Host' ) ) ) ) ) );
		$assert( ! empty( $result['success'] ), 'generated host installed' );
	} else {
		switch_theme( $host );
	}
	echo 'HOST ' . $host . "\n";
	$about = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Owner about', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'Owner edited' ) );
	$parent = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Company', 'post_name' => 'company', 'post_status' => 'publish' ) );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $parent );
	$before = get_post( $about )->to_array();
	$settings = array_map( 'get_option', array( 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'blogname', 'blogdescription' ) );
	$menu_id = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_title' => 'Owner menu', 'post_status' => 'publish', 'post_content' => '<!-- wp:navigation-link {"label":"Owner about","url":"/about/"} /-->' ) );
	$menus = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'numberposts' => -1 ) );
	$input = array( 'operation' => 'plan', 'destination' => 'existing_theme', 'slug' => 'choices-' . $host, 'source' => array( 'type' => 'files', 'entrypoint' => 'index.html', 'files' => array(
		array( 'path' => 'index.html', 'content' => $html( 'Index' ) ),
		array( 'path' => 'about.html', 'content' => $html( 'About' ) ),
		array( 'path' => 'pricing.html', 'content' => $html( 'Pricing', '<a href="about.html">About</a> <a href="index.html">Index</a>' ) ),
	) ) );
	$plan = $run( $input );
	$rows = array_column( $plan['pages'] ?? array(), null, 'source_path' );
	$assert( 'conflict' === ( $rows['about.html']['action'] ?? '' ) && $about === ( $rows['about.html']['occupying_post']['ID'] ?? 0 ) && ! empty( $rows['about.html']['suggested_slug'] ), 'plan reports occupant and free slug' );
	$assert( '/index/' === $rows['index.html']['route'], 'index is a normal page' );
	$count = count( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1 ) ) );
	$input['operation'] = 'apply';
	$failed = $run( $input );
	$assert( empty( $failed['success'] ) && $count === count( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1 ) ) ), 'conflicting apply writes nothing' );
	$input['pages'] = array( 'pricing.html', 'about.html' );
	$input['page_routes'] = array( 'about.html' => array( 'slug' => 'about-us', 'parent' => $parent ) );
	$input['operation'] = 'plan';
	$plan = $run( $input );
	$assert( str_contains( wp_json_encode( $plan ), 'unselected_source_page_link' ), 'unselected index link diagnosed' );
	$invalid = $input;
	$invalid['page_routes']['about.html']['parent'] = 99999999;
	$invalid_plan = $run( $invalid );
	$assert( empty( $invalid_plan['success'] ) && str_contains( wp_json_encode( $invalid_plan ), 'about.html' ), 'invalid parent is a named plan error' );
	$input['operation'] = 'apply';
	$result = $run( $input );
	$assert( ! empty( $result['success'] ), 'selected apply succeeds' );
	$posts = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_static_site_importer_provenance' ) );
	$added = array();
	foreach ( $posts as $post ) {
		if ( in_array( $post->post_name, array( 'pricing', 'about-us' ), true ) ) {
			$added[ $post->post_name ] = $post;
		}
	}
	$assert( 2 === count( $added ) && 'draft' === $added['pricing']->post_status && 'draft' === $added['about-us']->post_status, 'two draft pages' );
	$assert( $parent === (int) $added['about-us']->post_parent, 'chosen parent persisted' );
	$assert( str_contains( $added['pricing']->post_content, wp_make_link_relative( get_permalink( $added['about-us'] ) ) ), 'pricing links to remapped permalink' );
	wp_update_post( array( 'ID' => $added['pricing']->ID, 'post_status' => 'pending' ) );
	$before_count = count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ) ) );
	$again = $run( $input );
	$assert( ! empty( $again['success'] ) && 'pending' === get_post_status( $added['pricing']->ID ), 'reimport preserves current status' );
	$saved = $run( array( 'operation' => 'apply', 'plan' => $plan ) );
	$assert( ! empty( $saved['success'] ), 'saved plan retains page choices' );
	$assert( $before_count === count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1 ) ) ), 'reimport creates no duplicates' );
	$assert( ! get_page_by_path( 'index' ), 'unselected index is not materialized' );
	$assert( $before === get_post( $about )->to_array(), 'owner about unchanged' );
	$assert( $settings === array_map( 'get_option', array( 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'blogname', 'blogdescription' ) ), 'site settings unchanged' );
	$assert( $menus == get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'any', 'numberposts' => -1 ) ), 'menus unchanged' );
	foreach ( array_merge( array( $about, $parent, $menu_id ), array_map( static fn( $p ) => $p->ID, $added ) ) as $id ) {
		wp_delete_post( $id, true );
	}
}
