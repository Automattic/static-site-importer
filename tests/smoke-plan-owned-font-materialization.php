<?php
/** Run: php tests/smoke-plan-owned-font-materialization.php */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }

class Static_Site_Importer_Lifecycle_Compile_Checkpoint { public static function current_owner(): string { return 'test-owner'; } }
class Static_Site_Importer_Compilation_Preparation {
	public static function compile_website_artifact( array $artifact, array $args ): array {
		return array( 'artifact' => $artifact, 'args' => $args, 'compiled' => array(), 'plan' => array( 'schema' => 'test-plan/v1', 'theme' => array( 'font_materialization' => array( 'schema' => 'blocks-engine/php-transformer/font-materialization-plan/v1', 'stylesheets' => array( array( 'path' => 'assets/css/source-fonts.css', 'content' => '@font-face{font-family:PlanOwned}' ) ) ) ) ), 'gutenberg_gaps' => array(), 'companion_payload' => null, 'theme_materialization' => array() );
	}
}
class Static_Site_Importer_Entity_Materializer_Registry { public static function plan_runtime_lifecycle( array $plan, array $args ): array { return array(); } }
class Static_Site_Importer_WordPress_Site_Plan_Materializer {
	public static array $args = array();
	public static function prepare_for_materialization( array $plan, array $args ): array { self::$args = $args; return array( 'status' => 'failed', 'receipt' => array( 'errors' => array( array( 'code' => 'test-stop', 'message' => 'stop' ) ) ) ); }
}

require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-theme-generator.php';

$result = Static_Site_Importer_Theme_Generator::import_website_artifact( array( 'files' => array() ) );
if ( ! is_wp_error( $result ) || isset( Static_Site_Importer_WordPress_Site_Plan_Materializer::$args['font_materialization'] ) ) {
	throw new RuntimeException( 'Theme Generator must not copy plan-owned font materialization into mutable args' );
}

echo "Plan-owned font materialization smoke passed.\n";
