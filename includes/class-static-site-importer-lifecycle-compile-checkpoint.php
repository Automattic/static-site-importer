<?php
/** Short-lived compile checkpoints for validation lifecycle handoffs. @package StaticSiteImporter */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'Static_Site_Importer_Artifact_Run_Workspace' ) ) {
	require_once __DIR__ . '/class-static-site-importer-artifact-run.php';
}

final class Static_Site_Importer_Lifecycle_Compile_Checkpoint {
	private const SCHEMA = 'static-site-importer/lifecycle-compile-checkpoint/v1';
	private const TTL    = 21600;

	public static function create( array $artifact, array $request_args, array $materialization, string $owner, string $root = '' ) {
		$root = self::root( $root );
		if ( wp_mkdir_p( $root ) ) {
			Static_Site_Importer_Artifact_Run_Workspace::purge_expired_in( $root );
		}
		$token     = bin2hex( random_bytes( 16 ) );
		$workspace = self::workspace( $token, $root );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		$binding = self::binding( $artifact, $request_args, $owner );
		$payload = array(
			'artifact'              => $materialization['artifact'],
			'args'                  => $materialization['args'],
			'plan'                  => $materialization['plan'],
			'gutenberg_gaps'        => $materialization['gutenberg_gaps'],
			'companion_payload'     => $materialization['companion_payload'],
			'materialization_plan'  => $materialization['materialization_plan'],
			'theme_materialization' => $materialization['theme_materialization'],
		);
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_encode_failed', 'The lifecycle compile checkpoint could not be encoded.' );
		}
		$record = array(
			'schema'         => self::SCHEMA,
			'binding'        => $binding,
			'payload_sha256' => hash( 'sha256', $json ),
			'payload'        => $payload,
		);
		$stored = $workspace->publish_json( 'checkpoint.json', $record );
		if ( is_wp_error( $stored ) ) {
			$workspace->purge();
			return $stored;
		}
		return $token;
	}

	public static function load( string $handle, array $artifact, array $request_args, string $owner, string $root = '' ) {
		$workspace = self::workspace( $handle, $root );
		if ( is_wp_error( $workspace ) ) {
			return $workspace;
		}
		if ( $workspace->is_expired() ) {
			$workspace->purge();
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_expired', 'The lifecycle compile checkpoint expired and the import must be restarted.' );
		}
		$raw    = $workspace->read_raw( 'checkpoint.json' );
		$record = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $record ) || self::SCHEMA !== ( $record['schema'] ?? '' ) || ! is_array( $record['binding'] ?? null ) || ! self::valid_payload( $record['payload'] ?? null ) || ! is_string( $record['payload_sha256'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_invalid', 'The lifecycle compile checkpoint is invalid.' );
		}
		$json = wp_json_encode( $record['payload'] );
		if ( ! is_string( $json ) || ! hash_equals( $record['payload_sha256'], hash( 'sha256', $json ) ) || $record['binding'] !== self::binding( $artifact, $request_args, $owner ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_mismatch', 'The lifecycle compile checkpoint does not match this import request.' );
		}
		return array( 'workspace' => $workspace, 'payload' => $record['payload'] );
	}

	public static function current_owner(): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return 'site:' . $blog_id . ';user:' . $user_id;
	}

	public static function root( string $root = '' ): string {
		if ( '' !== $root ) {
			return $root;
		}
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		return trailingslashit( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ) ) . 'static-site-importer/lifecycle-checkpoints';
	}

	private static function workspace( string $handle, string $root ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $handle ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_invalid', 'The lifecycle compile checkpoint handle is invalid.' );
		}
		$root = self::root( $root );
		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_unavailable', 'The lifecycle compile checkpoint workspace is unavailable.' );
		}
		try {
			return new Static_Site_Importer_Artifact_Run_Workspace( $root, 'lifecycle-' . $handle, array( 'on_success' => 'purge_on_success', 'expires_at' => gmdate( 'c', time() + self::ttl() ) ) );
		} catch ( RuntimeException $error ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_unavailable', $error->getMessage() );
		}
	}

	private static function binding( array $artifact, array $args, string $owner ): array {
		unset( $args['runtime_lifecycle_phase'], $args['runtime_lifecycle_request_id'], $args['runtime_lifecycle_invocation_id'], $args['runtime_lifecycle_checkpoint'], $args['_static_site_importer_lifecycle_checkpoint_root'], $args['report'] );
		return array(
			'artifact_sha256' => hash( 'sha256', (string) wp_json_encode( $artifact ) ),
			'args'            => $args,
			'owner'           => $owner,
			'compiler'        => self::compiler_binding(),
		);
	}

	private static function compiler_binding(): array {
		$class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		$file  = class_exists( $class ) ? ( new ReflectionClass( $class ) )->getFileName() : false;
		return array( 'class' => $class, 'file_sha256' => is_string( $file ) && is_readable( $file ) ? hash_file( 'sha256', $file ) : '' );
	}

	private static function ttl(): int {
		$ttl = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_lifecycle_checkpoint_ttl', self::TTL ) : self::TTL;
		return min( 604800, max( self::TTL, (int) $ttl ) );
	}

	private static function valid_payload( $payload ): bool {
		if ( ! is_array( $payload ) || ! is_array( $payload['artifact'] ?? null ) || ! is_array( $payload['args'] ?? null ) || ! is_array( $payload['plan'] ?? null ) || ! is_array( $payload['gutenberg_gaps'] ?? null ) || ! is_array( $payload['materialization_plan'] ?? null ) || ! is_array( $payload['theme_materialization'] ?? null ) || ! is_string( $payload['plan']['schema'] ?? null ) || '' === $payload['plan']['schema'] ) {
			return false;
		}
		if ( ! array_key_exists( 'companion_payload', $payload ) || ( null !== $payload['companion_payload'] && ! is_array( $payload['companion_payload'] ) ) ) {
			return false;
		}
		return ! isset( $payload['plan']['pages'] ) || is_array( $payload['plan']['pages'] );
	}
}
