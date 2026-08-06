<?php
/** Current-site materialization capability matrix regression coverage. */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
$GLOBALS['ssi_caps']             = array();
$GLOBALS['ssi_file_mod_allowed'] = true;

class WP_Error {
	public function __construct( private string $code, private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_data(): mixed { return $this->data; }
}
class SSI_Test_Post_Type {
	public object $cap;
	public function __construct( string $type ) {
		$this->cap = (object) array(
			'create_posts'  => 'page' === $type ? 'edit_pages' : 'edit_posts',
			'publish_posts' => 'page' === $type ? 'publish_pages' : 'publish_posts',
		);
	}
}
function current_user_can( string $capability, mixed ...$args ): bool { return ! empty( $GLOBALS['ssi_caps'][ $capability ] ); }
function get_post_type_object( string $type ): ?object { return in_array( $type, array( 'page', 'post' ), true ) ? new SSI_Test_Post_Type( $type ) : null; }
function wp_is_file_mod_allowed( string $context ): bool { return $GLOBALS['ssi_file_mod_allowed']; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require dirname( __DIR__ ) . '/includes/class-static-site-importer-current-site-capabilities.php';

$assertions = 0;
$assert = static function ( bool $condition, string $label ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $label );
	}
};
$state = array(
	'resolved' => array( 'writes' => array( array( 'target_path' => 'style.css' ) ), 'operations' => array( array( 'kind' => 'site_reading' ) ) ),
	'ordered_pages' => array( array( 'post_type' => 'page' ) ),
	'args' => array( 'activate' => true, 'site_title' => 'Built Site' ),
);

// WordPress Build-style product roles can be allowed to build, but cannot gain file or plugin authority.
$GLOBALS['ssi_caps'] = array( 'edit_pages' => true, 'publish_pages' => true, 'switch_themes' => true, 'manage_options' => true, 'install_themes' => true );
$assert( true === Static_Site_Importer_Current_Site_Capabilities::check_plan( $state ), 'trusted site materializer has every plan capability' );
$assert( is_wp_error( Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true ) ), 'restricted WordPress Build role cannot install or activate plugins' );

$GLOBALS['ssi_caps']['install_plugins'] = true;
$assert( is_wp_error( Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true ) ), 'plugin installation alone cannot grant activation authority' );
$GLOBALS['ssi_caps']['activate_plugins'] = true;
$assert( true === Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true ), 'regular generated plugins require install and activation authority' );
$assert( true === Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( false ), 'must-use generated plugins require install authority without activation' );

$GLOBALS['ssi_file_mod_allowed'] = false;
$denied = Static_Site_Importer_Current_Site_Capabilities::check_plan( $state );
$assert( is_wp_error( $denied ) && 'static_site_importer_file_modification_forbidden' === $denied->get_error_code(), 'theme writes honor WordPress file-modification policy' );
$denied = Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( false );
$assert( is_wp_error( $denied ) && 'static_site_importer_file_modification_forbidden' === $denied->get_error_code(), 'plugin writes honor WordPress file-modification policy' );
$assert( true === Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true, false ), 'activating an existing plugin follows WordPress capability semantics without a file write' );

$GLOBALS['ssi_file_mod_allowed'] = true;
$GLOBALS['ssi_caps']['manage_options'] = false;
$assert( is_wp_error( Static_Site_Importer_Current_Site_Capabilities::check_plan( $state ) ), 'site setting changes require manage_options' );

echo "Current-site capability smoke passed ({$assertions} assertions).\n";
