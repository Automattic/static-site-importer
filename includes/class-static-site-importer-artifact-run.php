<?php
/**
 * Generic, resumable artifact run storage primitives.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Artifact_Run_Workspace {
	private string $root;
	private string $directory;
	private array $record;

	public function __construct( string $root, string $purpose, array $retention = array() ) {
		$root     = rtrim( $root, '/\\' );
		$resolved = realpath( $root );
		if ( '' === $root || is_link( $root ) || false === $resolved || ! is_dir( $resolved ) ) {
			throw new RuntimeException( 'Artifact workspace root must be an existing non-symlink directory.' );
		}

		$this->root      = $resolved;
		$token           = preg_replace( '/[^A-Za-z0-9_-]/', '-', $purpose );
		$this->directory = $this->root . '/.ssi-artifact-run-' . $token;
		if ( is_link( $this->directory ) ) {
			throw new RuntimeException( 'Artifact workspace directory cannot be a symlink.' );
		}
		if ( ! is_dir( $this->directory ) && ! mkdir( $this->directory, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates the importer-owned workspace without initializing a global filesystem transport.
			throw new RuntimeException( 'Artifact workspace could not be created.' );
		}

		$existing = $this->read_raw( 'workspace.json' );
		$record   = is_string( $existing ) ? json_decode( $existing, true ) : null;
		if ( ( is_file( $this->directory . '/workspace.json' ) || is_string( $existing ) ) && ( ! is_array( $record ) || 'static-site-importer/artifact-workspace/v1' !== ( $record['schema'] ?? '' ) || ( $record['purpose'] ?? '' ) !== $token ) ) {
			throw new RuntimeException( 'Artifact workspace ownership record is invalid.' );
		}
		$this->record = is_array( $record ) ? $record : array(
			'schema'     => 'static-site-importer/artifact-workspace/v1',
			'purpose'    => $token,
			'created_at' => gmdate( 'c' ),
			'retention'  => $retention,
		);
		if ( ! is_array( $record ) && is_wp_error( $this->publish_json( 'workspace.json', $this->record ) ) ) {
			throw new RuntimeException( 'Artifact workspace ownership record could not be published.' );
		}
	}

	public function path( string $relative ): string|WP_Error {
		if ( '' === $relative || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $relative ) ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_path_invalid', 'Workspace paths must be safe relative paths.' );
		}

		$parts   = explode( '/', $relative );
		$current = $this->directory;
		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				return new WP_Error( 'static_site_importer_artifact_workspace_symlink', 'Workspace paths cannot traverse symlinks.' );
			}
		}

		return $this->directory . '/' . $relative;
	}

	public function publish_raw( string $relative, string $bytes ) {
		$path = $this->path( $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$parent = dirname( $path );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates importer-owned nested directories without initializing a global filesystem transport.
			return new WP_Error( 'static_site_importer_artifact_workspace_unavailable', 'Workspace directory is unavailable.' );
		}
		if ( is_link( $parent ) || ! str_starts_with( (string) realpath( $parent ) . '/', $this->directory . '/' ) ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_symlink', 'Workspace writes must remain in owned directories.' );
		}

		$temp    = tempnam( $parent, '.ssi-artifact-' );
		$written = is_string( $temp ) && self::write_complete_file( $temp, $bytes );
		if ( ! is_string( $temp ) || ! $written || ! self::filesystem_operation( static fn () => rename( $temp, $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomically publishes importer-owned workspace data on the same filesystem.
			if ( is_string( $temp ) && is_file( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed importer-owned temporary file.
			}
			return new WP_Error( 'static_site_importer_artifact_workspace_write_failed', 'Unable to atomically publish workspace data.', array( 'path' => $path ) );
		}

		return $path;
	}

	/** Atomically publish immutable bytes, accepting only an identical existing value. */
	public function publish_raw_once( string $relative, string $bytes ) {
		$path = $this->path( $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$parent = dirname( $path );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates importer-owned nested directories without initializing a global filesystem transport.
			return new WP_Error( 'static_site_importer_artifact_workspace_unavailable', 'Workspace directory is unavailable.' );
		}
		if ( is_link( $parent ) || ! str_starts_with( (string) realpath( $parent ) . '/', $this->directory . '/' ) ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_symlink', 'Workspace writes must remain in owned directories.' );
		}

		$temp    = tempnam( $parent, '.ssi-artifact-' );
		$written = is_string( $temp ) && self::write_complete_file( $temp, $bytes );
		$linked  = $written && self::filesystem_operation( static fn () => link( $temp, $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_link -- Hard-link publication atomically creates an immutable checkpoint on the same filesystem.
		if ( is_string( $temp ) && is_file( $temp ) ) {
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the private publication temporary file.
		}
		if ( $linked ) {
			return $path;
		}
		if ( is_file( $path ) && hash_equals( hash( 'sha256', $bytes ), hash( 'sha256', (string) $this->read_raw( $relative ) ) ) ) {
			return $path;
		}

		return new WP_Error( 'static_site_importer_artifact_workspace_conflict', 'An immutable workspace checkpoint already exists with different content.' );
	}

	public function publish_json( string $relative, array $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION );
		return is_string( $json ) ? $this->publish_raw( $relative, $json ) : new WP_Error( 'static_site_importer_artifact_workspace_json_invalid', 'Workspace JSON could not be encoded.' );
	}

	public function publish_json_once( string $relative, array $data ) {
		$path = $this->path( $relative );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$parent = dirname( $path );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates importer-owned nested directories without initializing a global filesystem transport.
			return new WP_Error( 'static_site_importer_artifact_workspace_unavailable', 'Workspace directory is unavailable.' );
		}
		if ( is_link( $parent ) || ! str_starts_with( (string) realpath( $parent ) . '/', $this->directory . '/' ) ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_symlink', 'Workspace writes must remain in owned directories.' );
		}

		$temp    = tempnam( $parent, '.ssi-artifact-' );
		$written = is_string( $temp ) && self::write_json_file( $temp, $data );
		$linked  = $written && self::filesystem_operation( static fn () => link( $temp, $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_link -- Hard-link publication atomically creates an immutable checkpoint on the same filesystem.
		$matches = $written && is_file( $path ) && hash_equals( (string) hash_file( 'sha256', $temp ), (string) hash_file( 'sha256', $path ) );
		if ( is_string( $temp ) && is_file( $temp ) ) {
			unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the private publication temporary file.
		}
		if ( $linked || $matches ) {
			return $path;
		}
		if ( ! $written ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_json_invalid', 'Workspace JSON could not be encoded.' );
		}

		return new WP_Error( 'static_site_importer_artifact_workspace_conflict', 'An immutable workspace checkpoint already exists with different content.' );
	}

	/** Atomically claim a private workspace directory exactly once. */
	public function claim_directory( string $relative ): bool {
		$path = $this->path( $relative . '/claim' );
		$path = is_string( $path ) ? dirname( $path ) : '';
		return '' !== $path && ! is_link( $path ) && self::filesystem_operation( static fn () => mkdir( $path, 0700 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- mkdir is the atomic workspace claim primitive.
	}

	/** Acquire a non-blocking workspace execution lock. */
	public function acquire_lock( string $relative ) {
		$path = $this->path( $relative );
		if ( ! is_string( $path ) || is_link( $path ) ) {
			return new WP_Error( 'static_site_importer_artifact_workspace_path_invalid', 'The workspace lock path is invalid.' );
		}
		$provided = function_exists( 'apply_filters' ) ? apply_filters( 'static_site_importer_artifact_workspace_lock', null, 'acquire', $path, null ) : null;
		if ( null !== $provided ) {
			return is_wp_error( $provided ) ? $provided : array(
				'schema' => 'static-site-importer/artifact-workspace-lock/v1',
				'path'   => $path,
				'token'  => $provided,
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opens an importer-owned advisory lock file.
		$handle = fopen( $path, 'c' );
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Serializes one importer-owned run executor.
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native stream required by flock after a failed lock attempt.
			}
			return new WP_Error( 'static_site_importer_artifact_workspace_locked', 'The artifact run is already executing.' );
		}
		return $handle;
	}

	public function release_lock( $handle ): void {
		if ( is_array( $handle ) && 'static-site-importer/artifact-workspace-lock/v1' === ( $handle['schema'] ?? '' ) && is_string( $handle['path'] ?? null ) ) {
			if ( function_exists( 'apply_filters' ) ) {
				apply_filters( 'static_site_importer_artifact_workspace_lock', null, 'release', $handle['path'], $handle['token'] ?? null );
			}
			return;
		}
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Releases the importer-owned run lock.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native stream only after releasing its advisory lock.
		}
	}

	public function read_raw( string $relative ): ?string {
		$path = $this->path( $relative );
		if ( ! is_string( $path ) || is_link( $path ) || ! is_file( $path ) ) {
			return null;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned workspace bytes.
		return is_string( $bytes ) ? $bytes : null;
	}

	public function delete( string $relative ): bool {
		$path = $this->path( $relative );
		return is_string( $path ) && ! is_link( $path ) && is_file( $path ) && unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes a verified importer-owned workspace file.
	}

	public function directory(): string {
		return $this->directory;
	}

	public function retention(): array {
		return $this->record['retention'] ?? array();
	}

	public function is_expired(): bool {
		$expires = $this->retention()['expires_at'] ?? '';
		return is_string( $expires ) && '' !== $expires && strtotime( $expires ) <= time();
	}

	public function purge_expired(): array {
		return $this->is_expired() ? $this->purge() : array(
			'status'    => 'retained',
			'workspace' => $this->directory,
			'reason'    => 'not_expired',
			'deleted'   => array(),
		);
	}

	public function cleanup( string $outcome ): array {
		$policy = $this->retention()[ 'on_' . $outcome ] ?? 'retain';
		return 'purge_on_success' === $policy || 'purge' === $policy ? $this->purge() : array(
			'status'     => 'retained',
			'workspace'  => $this->directory,
			'expires_at' => $this->retention()['expires_at'] ?? null,
			'deleted'    => array(),
		);
	}

	public function purge(): array {
		$removed = array();
		$skipped = array();
		$failed  = array();
		if ( is_link( $this->directory ) || ! is_dir( $this->directory ) ) {
			return array(
				'status'    => 'failed',
				'workspace' => $this->directory,
				'removed'   => $removed,
				'skipped'   => array( $this->directory ),
				'failed'    => $failed,
			);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( is_link( $path ) ) {
				$skipped[] = $path;
				continue;
			}

			$ok = self::remove_path( $path, $item->isDir() );
			if ( $ok ) {
				$removed[] = $path;
			} else {
				$failed[] = $path;
			}
		}
		if ( ! empty( $failed ) || ! empty( $skipped ) || ! self::remove_path( $this->directory, true ) ) {
			$failed[] = $this->directory;
		}

		$status = empty( $failed ) && empty( $skipped ) ? 'purged' : ( empty( $removed ) ? 'failed' : 'partial' );
		return array(
			'status'    => $status,
			'workspace' => $this->directory,
			'removed'   => $removed,
			'skipped'   => $skipped,
			'failed'    => $failed,
		);
	}

	public static function purge_expired_in( string $workspace_parent ): array {
		if ( is_link( $workspace_parent ) ) {
			return array();
		}
		$resolved_parent = realpath( $workspace_parent );
		if ( false === $resolved_parent ) {
			return array();
		}

		$receipts = array();
		$paths    = glob( $resolved_parent . '/.ssi-artifact-run-*' );
		if ( false === $paths ) {
			$paths = array();
		}
		foreach ( $paths as $path ) {
			if ( is_link( $path ) || ! is_dir( $path ) ) {
				continue;
			}
			$raw     = self::read_file( $path . '/workspace.json' );
			$record  = is_string( $raw ) ? json_decode( $raw, true ) : null;
			$expires = is_array( $record ) ? ( $record['retention']['expires_at'] ?? '' ) : '';
			if ( is_string( $expires ) && '' !== $expires && strtotime( $expires ) <= time() ) {
				$workspace = new self( $resolved_parent, substr( basename( $path ), strlen( '.ssi-artifact-run-' ) ) );
				$lock      = $workspace->acquire_lock( 'execution.lock' );
				if ( is_wp_error( $lock ) ) {
					continue;
				}
				try {
					$receipts[] = $workspace->purge();
				} finally {
					$workspace->release_lock( $lock );
				}
			}
		}

		return $receipts;
	}

	private static function remove_path( string $path, bool $directory ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a verified importer-owned workspace path after capturing expected race and permission warnings.
		return (bool) self::filesystem_operation( static fn () => $directory ? rmdir( $path ) : unlink( $path ) );
	}

	private static function read_file( string $path ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads importer-owned workspace metadata while treating concurrent removal as absent.
		$bytes = self::filesystem_operation( static fn () => file_get_contents( $path ) );
		return is_string( $bytes ) ? $bytes : null;
	}

	private static function write_complete_file( string $path, string $bytes ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes and flushes a private temporary file before atomic publication.
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			return false;
		}
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes a private temporary checkpoint file.
			$written = fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the native stream used by the complete-write loop on failure.
				return false;
			}
			$offset += $written;
		}
		$flushed = fflush( $handle );
		$synced  = ! function_exists( 'fsync' ) || fsync( $handle );
		$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Preserves flush, sync, then close durability ordering on the native stream.
		return $flushed && $synced && $closed;
	}

	private static function write_json_file( string $path, array $data ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a private JSON checkpoint before atomic publication.
		$handle = fopen( $path, 'wb' );
		if ( false === $handle ) {
			return false;
		}
		$written = self::write_json_value( $handle, $data, 0 );
		$flushed = $written && fflush( $handle );
		$synced  = $flushed && ( ! function_exists( 'fsync' ) || fsync( $handle ) );
		$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Preserves flush, sync, then close durability ordering on the native stream.
		return $written && $flushed && $synced && $closed;
	}

	/** Stream the exact JSON_PRETTY_PRINT token sequence without one complete JSON allocation. */
	private static function write_json_value( $handle, $value, int $depth ): bool {
		if ( ! is_array( $value ) ) {
			$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION );
			return is_string( $encoded ) && self::write_complete_handle( $handle, $encoded );
		}
		if ( empty( $value ) ) {
			return self::write_complete_handle( $handle, '[]' );
		}
		$list = array_is_list( $value );
		if ( ! self::write_complete_handle( $handle, $list ? "[\n" : "{\n" ) ) {
			return false;
		}
		$count = count( $value );
		$index = 0;
		foreach ( $value as $key => $item ) {
			if ( ! self::write_complete_handle( $handle, str_repeat( ' ', 4 * ( $depth + 1 ) ) ) ) {
				return false;
			}
			if ( ! $list ) {
				$encoded_key = wp_json_encode( (string) $key, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION );
				if ( ! is_string( $encoded_key ) || ! self::write_complete_handle( $handle, $encoded_key . ': ' ) ) {
					return false;
				}
			}
			if ( ! self::write_json_value( $handle, $item, $depth + 1 ) || ! self::write_complete_handle( $handle, ++$index < $count ? ",\n" : "\n" ) ) {
				return false;
			}
		}
		return self::write_complete_handle( $handle, str_repeat( ' ', 4 * $depth ) . ( $list ? ']' : '}' ) );
	}

	private static function write_complete_handle( $handle, string $bytes ): bool {
		$offset = 0;
		$length = strlen( $bytes );
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streams private checkpoint bytes to an already-owned handle.
			$written = fwrite( $handle, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private static function filesystem_operation( callable $operation ): mixed {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Limits expected filesystem warnings to this one cleanup operation so callers receive structured receipts.
		set_error_handler(
			static function ( int $severity ): bool {
				if ( E_WARNING !== $severity ) {
					return false;
				}
				throw new ErrorException();
			}
		);
		try {
			return $operation();
		} catch ( ErrorException $error ) {
			return false;
		} finally {
			restore_error_handler();
		}
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Artifact-run primitives share a single private storage contract.
final class Static_Site_Importer_Artifact_Run_Manifest {
	private string $path;
	private string $identity;
	private array $contract;
	private array $data = array();

	public function __construct( string $path, string $identity, string $schema, array $contract ) {
		$this->path     = $path;
		$this->identity = $identity;
		$this->contract = $contract;
		$this->data     = array(
			'schema'      => $schema,
			'version'     => 1,
			'source'      => array( 'identity' => $identity ),
			'contract'    => $contract,
			'state'       => 'running',
			'diagnostics' => array(),
		);
	}

	public function load() {
		if ( ! is_file( $this->path ) ) {
			return array();
		}
		$raw  = file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local run checkpoint.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['source']['identity'] ) ) {
			return new WP_Error( 'static_site_importer_batch_manifest_invalid', 'The batch run manifest is invalid.' );
		}
		if ( $this->identity !== $data['source']['identity'] || ( $data['contract'] ?? null ) !== $this->contract ) {
			return new WP_Error( 'static_site_importer_batch_contract_mismatch', 'The existing batch run targets a different import contract.', array( 'run_manifest' => $this->path ) );
		}

		$this->data = $data;
		return $data;
	}

	public function save( array $data ) {
		$this->data = $data;
		$temp       = tempnam( dirname( $this->path ), '.ssi-manifest-' );
		$json       = wp_json_encode( $data, JSON_PRETTY_PRINT );
		$written    = false === $temp || ! is_string( $json ) ? false : file_put_contents( $temp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a local checkpoint temporary file before atomic publication.
		if ( false === $temp || false === $written || strlen( $json ) !== $written || ! rename( $temp, $this->path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomically publishes local run state on the same filesystem.
			if ( is_string( $temp ) && is_file( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a failed local checkpoint temporary file.
			}
			return new WP_Error( 'static_site_importer_batch_checkpoint_write_failed', 'Unable to atomically write run state.', array( 'path' => $this->path ) );
		}

		return true;
	}

	public function replay(): ?array {
		return 'completed' === ( $this->data['state'] ?? '' ) && is_array( $this->data['final_result'] ?? null ) ? $this->data['final_result'] : null;
	}

	public function path(): string {
		return $this->path;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Artifact-run primitives share a single private storage contract.
final class Static_Site_Importer_Artifact_Byte_Cache {
	private Static_Site_Importer_Artifact_Run_Workspace $workspace;
	private string $cache_namespace;
	private int $max_entries;
	private int $max_bytes;
	private ?int $entry_count  = null;
	private ?int $used_bytes   = null;
	private mixed $reject_when = null;
	private array $counts      = array(
		'hits'                     => 0,
		'misses'                   => 0,
		'bytes_read'               => 0,
		'bytes_written'            => 0,
		'corrupt_entries'          => 0,
		'bypassed'                 => 0,
		'negative_hits'            => 0,
		'negative_writes'          => 0,
		'negative_expired'         => 0,
		'network_requests_avoided' => 0,
	);
	private array $adopted     = array();

	public function __construct( Static_Site_Importer_Artifact_Run_Workspace $workspace, string $cache_namespace, int $max_entries = 25000, int $max_bytes = 4294967296 ) {
		$this->workspace       = $workspace;
		$this->cache_namespace = preg_replace( '/[^A-Za-z0-9_-]/', '-', $cache_namespace );
		$this->max_entries     = $max_entries;
		$this->max_bytes       = $max_bytes;
	}

	private function name( string $key ): string {
		return 'cache/' . $this->cache_namespace . '/' . ( preg_match( '/^[a-f0-9]{64}$/', $key ) ? $key : hash( 'sha256', $key ) ) . '.entry';
	}

	public function get( string $key ): ?array {
		$name = $this->name( $key );
		$raw  = $this->workspace->read_raw( $name );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$line  = strpos( $raw, "\n" );
		$meta  = false === $line ? null : json_decode( substr( $raw, 0, $line ), true );
		$bytes = false === $line ? false : substr( $raw, $line + 1 );
		if ( ! is_array( $meta ) || ! is_string( $bytes ) || strlen( $bytes ) !== (int) ( $meta['bytes'] ?? -1 ) || hash( 'sha256', $bytes ) !== ( $meta['sha256'] ?? '' ) || ! is_array( $meta['value'] ?? null ) ) {
			++$this->counts['corrupt_entries'];
			if ( $this->workspace->delete( $name ) ) {
				$this->removed( strlen( $raw ) );
			}
			return null;
		}
		if ( $this->rejected( $bytes, $meta['value'] ) ) {
			if ( $this->workspace->delete( $name ) ) {
				$this->removed( strlen( $raw ) );
			}
			return null;
		}

		$this->counts['bytes_read'] += strlen( $raw );
		return array(
			'bytes' => $bytes,
			'value' => $meta['value'],
		);
	}

	public function get_failure( string $key, int $now ): ?array {
		$name = $this->name( $key );
		$raw  = $this->workspace->read_raw( $name );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$meta = json_decode( $raw, true );
		if ( ! is_array( $meta ) || 'failure' !== ( $meta['type'] ?? '' ) || ! is_array( $meta['error'] ?? null ) ) {
			return null;
		}
		if ( isset( $meta['retry_after'] ) && (int) $meta['retry_after'] <= $now ) {
			++$this->counts['negative_expired'];
			if ( $this->workspace->delete( $name ) ) {
				$this->removed( strlen( $raw ) );
			}
			return null;
		}

		++$this->counts['negative_hits'];
		++$this->counts['network_requests_avoided'];
		$error         = $meta['error'];
		$error['data'] = is_array( $error['data'] ?? null ) ? $error['data'] : array();
		$error['data']['_static_site_importer_negative_cache_hit'] = true;
		return $error;
	}

	public function put_failure( string $key, array $error, ?int $retry_after = null ): void {
		$raw = wp_json_encode(
			array(
				'type'        => 'failure',
				'error'       => $error,
				'retry_after' => $retry_after,
			)
		);
		if ( ! is_string( $raw ) ) {
			return;
		}
		$name     = $this->name( $key );
		$previous = $this->admit( $name, strlen( $raw ) );
		if ( false === $previous ) {
			++$this->counts['bypassed'];
			return;
		}
		if ( ! is_wp_error( $this->workspace->publish_raw( $name, $raw ) ) ) {
			$this->stored( $previous, strlen( $raw ) );
			++$this->counts['negative_writes'];
			$this->counts['bytes_written'] += strlen( $raw );
		}
	}

	public function put( string $key, string $bytes, array $value ): void {
		if ( $this->rejected( $bytes, $value ) ) {
			return;
		}
		$meta = wp_json_encode(
			array(
				'bytes'  => strlen( $bytes ),
				'sha256' => hash( 'sha256', $bytes ),
				'value'  => $value,
			)
		);
		$raw  = is_string( $meta ) ? $meta . "\n" . $bytes : false;
		if ( ! is_string( $raw ) ) {
			++$this->counts['bypassed'];
			return;
		}
		$name     = $this->name( $key );
		$previous = $this->admit( $name, strlen( $raw ) );
		if ( false === $previous ) {
			++$this->counts['bypassed'];
			return;
		}
		if ( ! is_wp_error( $this->workspace->publish_raw( $name, $raw ) ) ) {
			$this->stored( $previous, strlen( $raw ) );
			$this->counts['bytes_written'] += strlen( $raw );
		}
	}

	public function reject_when( callable $predicate ): void {
		$this->reject_when = $predicate;
	}

	private function rejected( string $bytes, array $value ): bool {
		return is_callable( $this->reject_when ) && (bool) call_user_func( $this->reject_when, $bytes, $value );
	}

	private function admit( string $name, int $bytes ): int|false {
		$this->occupancy();
		$path     = $this->workspace->path( $name );
		$previous = is_string( $path ) && is_file( $path ) ? (int) filesize( $path ) : 0;
		$entries  = (int) $this->entry_count + ( 0 === $previous ? 1 : 0 );
		$used     = (int) $this->used_bytes - $previous + $bytes;
		return $entries > $this->max_entries || $used > $this->max_bytes ? false : $previous;
	}

	private function stored( int $previous, int $bytes ): void {
		$this->entry_count = (int) $this->entry_count + ( 0 === $previous ? 1 : 0 );
		$this->used_bytes  = (int) $this->used_bytes - $previous + $bytes;
	}

	private function removed( int $bytes ): void {
		if ( null !== $this->entry_count && null !== $this->used_bytes ) {
			$this->entry_count = max( 0, $this->entry_count - 1 );
			$this->used_bytes  = max( 0, $this->used_bytes - $bytes );
		}
	}

	private function occupancy(): void {
		if ( null !== $this->entry_count && null !== $this->used_bytes ) {
			return;
		}
		$dir   = $this->workspace->directory() . '/cache/' . $this->cache_namespace;
		$files = glob( $dir . '/*.entry' );
		if ( false === $files ) {
			$files = array();
		}
		$this->entry_count = count( $files );
		$this->used_bytes  = array_sum( array_map( 'filesize', $files ) );
	}

	public function adopt_legacy( string $directory ): void {
		if ( is_link( $directory ) || ! is_dir( $directory ) ) {
			return;
		}
		$paths = glob( rtrim( $directory, '/' ) . '/*.entry' );
		if ( false === $paths ) {
			$paths = array();
		}
		foreach ( $paths as $path ) {
			if ( is_link( $path ) ) {
				continue;
			}
			$raw   = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a verified importer-owned legacy cache entry.
			$line  = is_string( $raw ) ? strpos( $raw, "\n" ) : false;
			$meta  = false === $line ? null : json_decode( substr( (string) $raw, 0, $line ), true );
			$bytes = false === $line ? false : substr( (string) $raw, $line + 1 );
			$value = is_array( $meta ) ? ( $meta['metadata'] ?? $meta['value'] ?? null ) : null;
			if ( is_array( $meta ) && is_string( $bytes ) && strlen( $bytes ) === (int) ( $meta['bytes'] ?? -1 ) && hash( 'sha256', $bytes ) === ( $meta['sha256'] ?? '' ) && is_array( $value ) ) {
				$key = basename( $path, '.entry' );
				$this->put( $key, $bytes, $value );
				$verified = $this->get( $key );
				if ( is_array( $verified ) && $verified['bytes'] === $bytes && $verified['value'] === $value ) {
					$this->adopted[ $directory ][] = $path;
				}
			}
		}
	}

	public function cleanup_adopted(): array {
		$removed = array();
		$failed  = array();
		foreach ( $this->adopted as $directory => $paths ) {
			foreach ( $paths as $path ) {
				if ( ! is_link( $path ) && is_file( $path ) && unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a verified importer-owned legacy cache entry.
					$removed[] = $path;
				} elseif ( is_file( $path ) ) {
					$failed[] = $path;
				}
			}
			$children = glob( $directory . '/*' );
			if ( ! is_link( $directory ) && is_dir( $directory ) && ( false === $children || empty( $children ) ) ) {
				rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an empty verified importer-owned legacy cache directory.
			}
		}

		return array(
			'removed' => $removed,
			'failed'  => $failed,
		);
	}

	public function hit(): void {
		++$this->counts['hits'];
	}

	public function miss(): void {
		++$this->counts['misses'];
	}

	public function network_avoided(): void {
		++$this->counts['network_requests_avoided'];
	}

	public function evidence(): array {
		return $this->counts;
	}

	public function consume(): array {
		$delta = $this->counts;
		foreach ( $this->counts as $key => $value ) {
			$this->counts[ $key ] = 0;
		}
		return $delta;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Artifact-run primitives share a single private storage contract.
final class Static_Site_Importer_Artifact_Batch_Cursor {
	private static function id( array $units ): string {
		return 'batch-' . substr( hash( 'sha256', (string) wp_json_encode( array_values( $units ) ) ), 0, 16 );
	}

	public static function create( array $units, int $size ): array {
		$rows = array();
		foreach ( array_chunk( array_values( $units ), max( 1, $size ) ) as $index => $chunk ) {
			$rows[] = array(
				'index'           => $index,
				'batch_id'        => self::id( $chunk ),
				'units'           => $chunk,
				'state'           => 'pending',
				'completed_units' => 0,
			);
		}
		return $rows;
	}

	public static function hydrate( array $rows, string $units = 'route_indexes', string $completed = 'completed_routes' ): array {
		foreach ( $rows as $index => &$row ) {
			$values = array_values( $row[ $units ] ?? array() );
			$row    = array(
				'index'                => $index,
				'batch_id'             => $row['batch_id'] ?? self::id( $values ),
				'units'                => $values,
				'state'                => $row['state'] ?? 'pending',
				'completed_units'      => (int) ( $row[ $completed ] ?? 0 ),
				'result'               => $row['result'] ?? null,
				'split_from'           => $row['split_from'] ?? null,
				'effective_batch_size' => $row['effective_batch_size'] ?? null,
				'page_ready_deferred'  => ! empty( $row['page_ready_deferred'] ),
			);
		}
		unset( $row );
		return $rows;
	}

	public static function next( array $rows ): ?int {
		foreach ( $rows as $index => $row ) {
			if ( 'completed' !== ( $row['state'] ?? '' ) ) {
				return $index;
			}
		}
		return null;
	}

	public static function complete( array $rows, int $index ): array {
		$rows[ $index ]['state']           = 'completed';
		$rows[ $index ]['completed_units'] = count( $rows[ $index ]['units'] ?? array() );
		return $rows;
	}

	public static function fail( array $rows, int $index ): array {
		$rows[ $index ]['state'] = 'failed';
		return $rows;
	}

	public static function split( array $rows, int $index ): array {
		$row      = $rows[ $index ];
		$units    = $row['units'] ?? array();
		$middle   = (int) ceil( count( $units ) / 2 );
		$children = array(
			array( 'units' => array_slice( $units, 0, $middle ) ),
			array( 'units' => array_slice( $units, $middle ) ),
		);
		foreach ( $children as &$child ) {
			$child += array(
				'batch_id'             => self::id( $child['units'] ),
				'state'                => 'pending',
				'completed_units'      => 0,
				'split_from'           => $row['batch_id'] ?? self::id( $units ),
				'effective_batch_size' => count( $child['units'] ),
			);
		}
		unset( $child );
		array_splice( $rows, $index, 1, $children );
		foreach ( $rows as $position => &$row ) {
			$row['index'] = $position;
		}
		unset( $row );
		return $rows;
	}
}
