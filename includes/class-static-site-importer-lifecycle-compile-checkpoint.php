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
	private const CLEANUP_HOOK = 'static_site_importer_purge_lifecycle_compile_checkpoints';
	private static ?string $runtime_generation = null;

	public static function create( array $artifact, array $request_args, array $materialization, string $owner, string $root = '' ) {
		$root = self::root( $root );
		if ( wp_mkdir_p( $root ) ) {
			Static_Site_Importer_Artifact_Run_Workspace::purge_expired_in( $root );
		}
		self::schedule_cleanup();
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
			'schema'             => self::SCHEMA,
			'binding'            => $binding,
			'runtime_generation' => self::runtime_generation(),
			'payload_sha256'     => hash( 'sha256', $json ),
			'payload'            => $payload,
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
		if ( ! is_array( $record ) || self::SCHEMA !== ( $record['schema'] ?? '' ) || ! is_array( $record['binding'] ?? null ) || ! is_string( $record['runtime_generation'] ?? null ) || ! self::valid_payload( $record['payload'] ?? null ) || ! is_string( $record['payload_sha256'] ?? null ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_invalid', 'The lifecycle compile checkpoint is invalid.' );
		}
		if ( hash_equals( $record['runtime_generation'], self::runtime_generation() ) ) {
			return new WP_Error( 'static_site_importer_fresh_runtime_required', 'Provider validation must resume in a fresh WordPress runtime after dependency preparation.' );
		}
		$json = wp_json_encode( $record['payload'] );
		if ( ! is_string( $json ) || ! hash_equals( $record['payload_sha256'], hash( 'sha256', $json ) ) || $record['binding'] !== self::binding( $artifact, $request_args, $owner ) ) {
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_mismatch', 'The lifecycle compile checkpoint does not match this import request.' );
		}
		return array( 'workspace' => $workspace, 'payload' => $record['payload'] );
	}

	/** Atomically reserve a loaded checkpoint for one terminal materialization attempt. */
	public static function claim( Static_Site_Importer_Artifact_Run_Workspace $workspace ) {
		$path = $workspace->path( 'claim' );
		if ( ! is_string( $path ) || ! mkdir( $path, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- mkdir is the atomic ownership primitive for an importer-owned workspace.
			return new WP_Error( 'static_site_importer_lifecycle_checkpoint_claimed', 'The lifecycle compile checkpoint is already being consumed.' );
		}
		return true;
	}

	/** Discard a checkpoint that was never handed to a caller. */
	public static function discard( string $handle, string $root = '' ): void {
		$workspace = self::workspace( $handle, $root );
		if ( ! is_wp_error( $workspace ) ) {
			$workspace->purge();
		}
	}

	/** Return the token allocated once by this PHP runtime, never from request input. */
	public static function runtime_generation(): string {
		if ( null === self::$runtime_generation ) {
			self::$runtime_generation = bin2hex( random_bytes( 16 ) );
		}
		return self::$runtime_generation;
	}

	/** Register the bounded cleanup job when the host provides WP-Cron. */
	public static function register_cleanup(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::CLEANUP_HOOK, array( self::class, 'purge_expired' ) );
		}
		self::schedule_cleanup();
	}

	/** Remove the recurring cleanup job when the plugin is deactivated. */
	public static function unschedule_cleanup(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}
	}

	/** Purge expired checkpoints from the default importer-owned root. */
	public static function purge_expired(): void {
		$root = self::root();
		if ( wp_mkdir_p( $root ) ) {
			Static_Site_Importer_Artifact_Run_Workspace::purge_expired_in( $root );
		}
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
			'implementation'  => self::implementation_binding(),
		);
	}

	private static function implementation_binding(): array {
		$binding = array(
			'content_policy'       => self::class_binding( 'Static_Site_Importer_Content_Policy' ),
			'client_script_policy' => self::class_binding( 'Static_Site_Importer_Client_Script_Policy' ),
			'compile_pipeline'     => self::files_binding(
				array(
					'class-static-site-importer-theme-generator.php',
					'class-static-site-importer-theme-materialization-strategy.php',
					'class-static-site-importer-site-identity.php',
					'class-static-site-importer-classic-theme-projection.php',
					'class-static-site-importer-companion-plugin.php',
				)
			),
			'compiler'             => self::class_binding( 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler', true ),
		);
		return function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_lifecycle_checkpoint_implementation_binding', $binding ) : $binding;
	}

	private static function class_binding( string $class, bool $with_dependencies = false ): array {
		$file = class_exists( $class ) ? ( new ReflectionClass( $class ) )->getFileName() : false;
		$data = array(
			'class'       => $class,
			'file_sha256' => is_string( $file ) && is_readable( $file ) ? hash_file( 'sha256', $file ) : '',
		);
		if ( $with_dependencies ) {
			$data['dependencies_sha256'] = is_string( $file ) ? self::compiler_dependencies_fingerprint( $file ) : '';
		}
		return $data;
	}

	/** Hash every SSI implementation file that derives the persisted compile payload. */
	private static function files_binding( array $files ): string {
		$hashes = array();
		foreach ( $files as $file ) {
			$path            = __DIR__ . '/' . $file;
			$hashes[ $file ] = is_readable( $path ) ? hash_file( 'sha256', $path ) : '';
		}
		ksort( $hashes, SORT_STRING );
		$json = wp_json_encode( $hashes );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	/** Hash the installed compiler package plus Composer's resolved dependency metadata. */
	private static function compiler_dependencies_fingerprint( string $file ): string {
		$marker = '/vendor/';
		$offset = strpos( $file, $marker );
		if ( false === $offset ) {
			return '';
		}
		$project = substr( $file, 0, $offset );
		$parts   = explode( '/', substr( $file, $offset + strlen( $marker ) ) );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$package = $project . $marker . $parts[0] . '/' . $parts[1];
		$files   = array();
		if ( is_dir( $package ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $package, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $item ) {
				if ( ! $item->isFile() || $item->isLink() ) {
					continue;
				}
				$path = $item->getPathname();
				$files[ substr( $path, strlen( $package ) + 1 ) ] = hash_file( 'sha256', $path );
			}
		}
		foreach ( array( $project . '/composer.lock', $project . '/vendor/composer/installed.php', $project . '/vendor/composer/installed.json' ) as $metadata ) {
			if ( is_readable( $metadata ) ) {
				$files[ substr( $metadata, strlen( $project ) + 1 ) ] = hash_file( 'sha256', $metadata );
			}
		}
		ksort( $files, SORT_STRING );
		$json = wp_json_encode( $files );
		return is_string( $json ) ? hash( 'sha256', $json ) : '';
	}

	private static function schedule_cleanup(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) || wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ), 'hourly', self::CLEANUP_HOOK );
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
