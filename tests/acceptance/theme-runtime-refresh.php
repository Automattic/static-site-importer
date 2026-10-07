<?php
/** Real registry refresh and rollback oracle in the disposable Docker site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run only in the disposable acceptance site.' );
}
require_once ABSPATH . 'wp-content/plugins/static-site-importer/includes/class-static-site-importer-generated-runtime-package.php';
$directory = get_theme_root() . '/ssi-runtime-refresh-' . wp_generate_uuid4();
$payload = array(
	'schema' => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug' => 'refresh-probe',
	'blocks' => array( array(
		'name' => 'probe',
		'block_json' => array( 'name' => 'ssi-refresh-probe/probe', 'title' => 'Probe', 'category' => 'design', 'attributes' => array( 'label' => array( 'type' => 'string', 'default' => 'before' ) ) ),
		'render' => '<p>Before refresh</p>',
	) ),
);
$install = static function ( array $package ) use ( $directory ): void {
	foreach ( $package['files'] as $path => $bytes ) {
		wp_mkdir_p( dirname( $directory . '/' . $path ) );
		if ( false === file_put_contents( $directory . '/' . $path, $bytes ) ) {
			throw new RuntimeException( 'Probe write failed.' );
		}
	}
};
$check = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
};
$package = Static_Site_Importer_Generated_Runtime_Package::theme( $payload, 'refresh-probe' );
$snapshot = Static_Site_Importer_Generated_Runtime_Package::registration_snapshot( $package, $directory );
try {
	$install( $package );
	$check( true === Static_Site_Importer_Generated_Runtime_Package::register( $package, $directory ), 'Initial registration failed.' );
	$registry = WP_Block_Type_Registry::get_instance();
	$before = $registry->get_registered( 'ssi-refresh-probe/probe' );
	// init may follow page-ready registration in the same request.
	call_user_func( $package['registration_callback'] );
	$check( $before === $registry->get_registered( 'ssi-refresh-probe/probe' ), 'Registration lifecycle is not idempotent.' );
	$payload['blocks'][0]['block_json']['attributes']['label']['default'] = 'after';
	$payload['blocks'][0]['render'] = '<p>After refresh</p>';
	$refreshed = Static_Site_Importer_Generated_Runtime_Package::theme( $payload, 'refresh-probe' );
	$check( $package['registration_callback'] === $refreshed['registration_callback'], 'Probe must exercise an already-loaded callback.' );
	$install( $refreshed );
	$check( true === Static_Site_Importer_Generated_Runtime_Package::register( $refreshed, $directory ), 'Refresh failed.' );
	$check( 'after' === $registry->get_registered( 'ssi-refresh-probe/probe' )->attributes['label']['default'], 'Refresh used stale block metadata.' );
	$check( '<p>After refresh</p>' === do_blocks( '<!-- wp:ssi-refresh-probe/probe /-->' ), 'Refresh used stale render bytes.' );
	$foreign = Static_Site_Importer_Generated_Runtime_Package::preflight_blocks( $package, $directory . '-foreign' );
	$check( is_wp_error( $foreign ), 'Foreign destination took over an owned block.' );
	Static_Site_Importer_Generated_Runtime_Package::restore_registration( $snapshot );
	$check( ! $registry->is_registered( 'ssi-refresh-probe/probe' ) && ! isset( $GLOBALS['static_site_importer_runtime_block_owners']['ssi-refresh-probe/probe'] ), 'Rollback left a registration or ownership claim.' );
	echo wp_json_encode( array( 'same_request_refresh' => true, 'lifecycle_idempotent' => true, 'collision_rejected' => true, 'registration_rollback' => true ) ) . "\n";
} finally {
	Static_Site_Importer_Generated_Runtime_Package::restore_registration( $snapshot );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $file ) {
		$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
	}
	rmdir( $directory );
}
