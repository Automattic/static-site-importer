<?php
/** Canonical multilingual declarations route through the ordinary dependency lifecycle. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
class WP_Error {
	public function __construct( public string $code, public string $message = '', public mixed $data = null ) {}
}
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function get_option( string $key, mixed $default = false ): mixed {
	return $default; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return $value; }
require dirname( __DIR__ ) . '/includes/class-static-site-importer-dependency-manager.php';
require dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';

$artifact        = array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => '<main><h1>Hello</h1></main>' ),
);
$compiler        = new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler();
$plain           = $compiler->compile( $artifact )->toArray()['source_reports']['wordpress_site_plan'];
$assert          = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
};
$plain_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $plain, array() );
$assert( array() === $plain_lifecycle['dependencies'], 'Ordinary source documents do not install a multilingual plugin.' );
$artifact['metadata']['runtime_declarations'] = array(
	array(
		'kind'        => 'dependency',
		'capability'  => 'multilingual',
		'source_path' => 'index.html',
	),
);
$declared                                     = $compiler->compile( $artifact )->toArray()['source_reports']['wordpress_site_plan'];
$lifecycle                                    = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $declared, array() );
$plan = Static_Site_Importer_Dependency_Manager::dependency_plan( $lifecycle, str_repeat( 'a', 64 ) );
$assert( 1 === count( $plan['entries'] ) && 'translatepress-multilingual' === $plan['entries'][0]['slug'], 'Published compiler declarations resolve to the native TranslatePress package.' );
$assert( 'translatepress-multilingual/index.php' === $plan['entries'][0]['plugin_entrypoint'], 'The native plugin entrypoint is retained in dependency proof.' );
$failure = Static_Site_Importer_Dependency_Manager::materialize_lifecycle_dependencies( $lifecycle, array( 'materialize_dependencies' => false ) );
$assert( is_wp_error( $failure ) && 'static_site_importer_required_runtime_dependency_missing' === $failure->code, 'An unavailable declared multilingual dependency fails visibly instead of silently publishing an incomplete site.' );
$invalid = Static_Site_Importer_TranslatePress_Materializer::validate(
	array(
		'multilingual' => array(
			array(
				'id'               => 'site',
				'default_language' => 'en_US',
				'languages'        => array( 'en_US', 'en_US' ),
			),
		),
	)
);
$assert( ! empty( $invalid['errors'] ), 'Duplicate language declarations cannot invent a working selector.' );
echo "Canonical multilingual dependency contract passed.\n";
