<?php
/** Run: php tests/smoke-figma-workspace-lifecycle.php */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
class WP_Error { public function __construct( private string $code, private string $message = '', private mixed $data = null ) {} public function get_error_code(): string{return $this->code;} }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_json_encode( $value, int $options = 0 ) { return json_encode( $value, $options ); }
function apply_filters( string $hook, $value ) { return 'static_site_importer_figma_zstd_available' === $hook ? true : $value; }
$seen = array(); $fail = false;
function blocks_engine_figma_transformer_transform_file( string $path, array $options ) { global $seen, $fail; $seen[] = $path; if ( $fail ) { return array( 'status' => 'failed' ); } return array( 'files' => array( array( 'path' => 'website/index.html', 'content' => '<main>Figma</main>' ) ) ); }
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-figma-import.php';
$before = glob( sys_get_temp_dir() . '/.ssi-artifact-run-fig-*' ) ?: array();
$input = array( 'source' => array( 'figma_file' => array( 'name' => 'design.fig', 'content_base64' => base64_encode( 'fig' ) ) ) ); $success = Static_Site_Importer_Figma_Import::website_artifact_from_input( $input );
if ( is_wp_error( $success ) || empty( $seen ) || array_diff( glob( sys_get_temp_dir() . '/.ssi-artifact-run-fig-*' ) ?: array(), $before ) ) { throw new RuntimeException( 'base64 Figma staging must clean its owned workspace after a successful transform' ); }
$fail = true; $failed = Static_Site_Importer_Figma_Import::website_artifact_from_input( $input ); if ( ! is_wp_error( $failed ) || array_diff( glob( sys_get_temp_dir() . '/.ssi-artifact-run-fig-*' ) ?: array(), $before ) ) { throw new RuntimeException( 'base64 Figma staging must clean its owned workspace after a transform failure' ); }
$fail = false; $retained = Static_Site_Importer_Figma_Import::website_artifact_from_input( $input + array( 'retain_workspace' => true ) ); $evidence = $retained['provenance']['artifact_workspace'] ?? array(); if ( is_wp_error( $retained ) || ! is_dir( $evidence['path'] ?? '' ) || empty( $evidence['expires_at'] ) || 'retained' !== ( $evidence['cleanup']['status'] ?? '' ) ) { throw new RuntimeException( 'retained Figma staging must expose expiry and cleanup evidence' ); } $workspace = new Static_Site_Importer_Artifact_Run_Workspace( (string) realpath( sys_get_temp_dir() ), substr( basename( $evidence['path'] ), strlen( '.ssi-artifact-run-' ) ) ); if ( 'purged' !== $workspace->purge()['status'] ) { throw new RuntimeException( 'retained Figma workspace must support explicit purge' ); }
$studio = ABSPATH . '.studio-import'; wp_mkdir_p( $studio ); $staged = $studio . '/design.fig'; file_put_contents( $staged, 'fig' ); Static_Site_Importer_Figma_Import::website_artifact_from_input( array( 'source' => array( 'figma_file' => array( 'name' => 'design.fig', 'staged_path' => $staged ) ) ) ); if ( ! is_file( $staged ) ) { throw new RuntimeException( 'caller-owned Studio staged files must not be deleted' ); } unlink( $staged ); rmdir( $studio );
echo "Figma workspace lifecycle smoke passed.\n";
