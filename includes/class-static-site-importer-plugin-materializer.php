<?php
/**
 * Deterministic WordPress plugin materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Current_Site_Capabilities' ) ) {
	require_once __DIR__ . '/class-static-site-importer-current-site-capabilities.php';
}

/**
 * Installs and activates declared WordPress.org plugins before entity seeding.
 */
class Static_Site_Importer_Plugin_Materializer {

	/** Option identifying the generated companion that owns document-global runtime assets. */
	public const ACTIVE_COMPANION_OPTION = 'static_site_importer_active_companion_plugin';

	/**
	 * Ensure a WordPress.org plugin is installed, active, and exposes expected APIs.
	 *
	 * @param string        $slug               WordPress.org plugin slug.
	 * @param string        $plugin_file        Plugin basename, e.g. woocommerce/woocommerce.php.
	 * @param callable|null $availability_check Optional callback that returns true when plugin APIs are available.
	 * @param callable|null $preparation_callback Optional callback that enables the required plugin capability.
	 * @return array<string, mixed>
	 */
	public static function ensure_wp_org_plugin(
		string $slug,
		string $plugin_file,
		?callable $availability_check = null,
		?callable $preparation_callback = null
	): array {
		$report = self::new_report( $slug, $plugin_file );

		if ( self::available( $availability_check ) ) {
			$report['status']    = 'already_available';
			$report['installed'] = true;
			$report['active']    = true;
			self::record_installed_provenance( $report );
			return $report;
		}

		$report['attempted'] = true;

		$deps = self::load_admin_dependencies();
		if ( is_wp_error( $deps ) ) {
			return self::failed_report( $report, $deps );
		}

		$plugin_path   = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
		$needs_install = ! self::plugin_entrypoint_exists( $plugin_path );
		if ( ! $needs_install ) {
			$report['installed'] = true;
		} else {
			$capabilities = Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true );
			if ( is_wp_error( $capabilities ) ) {
				return self::failed_report( $report, $capabilities );
			}
			$install = self::install_wp_org_plugin( $slug );
			if ( is_wp_error( $install ) ) {
				// Upgraders can persist the entrypoint before reporting a terminal
				// failure. The filesystem is the install oracle for a safe retry.
				if ( ! self::plugin_entrypoint_exists( $plugin_path ) ) {
					return self::failed_report( $report, $install );
				}
				$report['installed'] = true;
				$report['actions'][] = 'reconciled_installed';
			} else {
				$report['installed'] = true;
				$report['actions'][] = 'installed';
			}
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
			$report['active'] = true;
			$preparation      = self::prepare_plugin_runtime( $slug, $preparation_callback );
			if ( is_wp_error( $preparation ) ) {
				return self::failed_report( $report, $preparation );
			}
		} else {
			$capabilities = Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( true, false );
			if ( is_wp_error( $capabilities ) ) {
				return self::failed_report( $report, $capabilities );
			}
			$lifecycle = self::prepare_activation_lifecycle_replay();
			try {
				$activate = activate_plugin( $plugin_file );
			} catch ( Throwable $error ) {
				self::restore_activation_lifecycle_actions( $lifecycle );
				$activate = new WP_Error( 'static_site_importer_plugin_activation_failed', sprintf( 'Plugin %s activation failed: %s', $slug, $error->getMessage() ) );
			}
			if ( is_wp_error( $activate ) ) {
				self::restore_activation_lifecycle_actions( $lifecycle );
				// activate_plugin() may update active_plugins before an activation
				// callback fails. Reconcile persistent activation before failing.
				if ( ! is_plugin_active( $plugin_file ) ) {
					return self::failed_report( $report, $activate );
				}
				$report['active']    = true;
				$report['actions'][] = 'reconciled_activated';
				$preparation = self::prepare_plugin_runtime( $slug, $preparation_callback );
				if ( is_wp_error( $preparation ) ) {
					return self::failed_report( $report, $preparation );
				}
			} else {
				$preparation = self::prepare_plugin_runtime( $slug, $preparation_callback );
				if ( is_wp_error( $preparation ) ) {
					self::restore_activation_lifecycle_actions( $lifecycle );
					return self::failed_report( $report, $preparation );
				}
				try {
					$report['lifecycle_replay'] = self::complete_activation_lifecycle_replay( $lifecycle );
				} catch ( Throwable $error ) {
					return self::failed_report(
						$report,
						new WP_Error(
							'static_site_importer_plugin_lifecycle_replay_failed',
							sprintf( 'Plugin %s activated but its WordPress lifecycle callbacks failed: %s', $slug, $error->getMessage() )
						)
					);
				}

				$report['active']    = true;
				$report['actions'][] = 'activated';
			}
		}

		$report['status'] = in_array( 'installed', $report['actions'], true )
			? 'installed_activated'
			: 'activated';
		// Activation changes the persistent plugin list, not this request's loaded
		// block registry. Resume validation proves provider APIs after a new process.
		if ( ! self::available( $availability_check ) ) {
			$report['status'] = 'activated_pending_fresh_runtime';
		}
		self::record_installed_provenance( $report );
		return $report;
	}

	/** @return true|WP_Error */
	private static function prepare_plugin_runtime( string $slug, ?callable $preparation_callback ) {
		if ( null === $preparation_callback ) {
			return true;
		}
		try {
			$result = call_user_func( $preparation_callback );
		} catch ( Throwable $error ) {
			return new WP_Error( 'static_site_importer_plugin_runtime_preparation_failed', sprintf( 'Plugin %s runtime preparation failed: %s', $slug, $error->getMessage() ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true === $result
			? true
			: new WP_Error( 'static_site_importer_plugin_runtime_preparation_failed', sprintf( 'Plugin %s could not enable its required runtime capability.', $slug ) );
	}

	/**
	 * Ensure a generated companion plugin is materialized on disk and active.
	 *
	 * Mirrors ensure_wp_org_plugin() for a payload SSI produced itself instead of
	 * a WordPress.org directory slug: it scaffolds the plugin file set, writes the
	 * files into the plugins (or mu-plugins) directory, activates it (mu-plugins
	 * are always active), and treats it as a satisfied dependency. All WordPress
	 * calls are guarded so the deterministic plan is testable without a runtime.
	 *
	 * @param array<string, mixed> $payload            Generated companion-plugin payload.
	 * @param callable|null        $availability_check Optional callback that returns true when the plugin is available.
	 * @return array<string, mixed>
	 */
	public static function ensure_generated_plugin(
		array $payload,
		?callable $availability_check = null
	): array {
		// All compiler output is untrusted until the complete canonical payload has
		// passed the content-only boundary. Schema-less PHP scaffold input is gone.
		$validation = Static_Site_Importer_Companion_Plugin::validate_payload( $payload );
		if ( is_wp_error( $validation ) ) {
			$report = self::new_generated_report( '', '' );
			return self::failed_report( $report, $validation );
		}
		$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		if ( is_wp_error( $descriptor ) ) {
			$report = self::new_generated_report( '', '' );
			return self::failed_report( $report, $descriptor );
		}

		$report                = self::new_generated_report( (string) $descriptor['slug'], (string) $descriptor['plugin_file'] );
		$report['mu_plugin']   = (bool) $descriptor['mu_plugin'];
		$report['block_names'] = $descriptor['block_names'];
		$collision             = self::generated_block_name_collision( $descriptor['block_names'], $descriptor );
		if ( is_wp_error( $collision ) ) {
			return self::failed_report( $report, $collision );
		}

		$plan = self::generated_install_plan( $descriptor );
		if ( is_wp_error( $plan ) ) {
			return self::failed_report( $report, $plan );
		}

		$report['files'] = array_keys( $plan['files'] );

		$already_available = self::available( $availability_check );

		$report['attempted'] = true;
		$capabilities        = Static_Site_Importer_Current_Site_Capabilities::check_plugin_install( (bool) $plan['activate'] );
		if ( is_wp_error( $capabilities ) ) {
			return self::failed_report( $report, $capabilities );
		}

		$written = self::write_generated_files( $plan );
		if ( is_wp_error( $written ) ) {
			return self::failed_report( $report, $written );
		}
		$report['installed'] = true;
		$report['actions'][] = $already_available ? 'refreshed' : 'installed';

		if ( false === $plan['activate'] ) {
			// mu-plugins are always active; no activation call is required.
			$plugin_file = (string) $descriptor['plugin_file'];
			$registered  = self::register_generated_blocks( $descriptor, $plan );
			if ( is_wp_error( $registered ) ) {
				return self::failed_report( $report, $registered );
			}
			$report['registration'] = $registered;
			self::replace_active_generated_companion( $plugin_file, $report );
			if ( function_exists( 'update_option' ) ) {
				update_option( self::ACTIVE_COMPANION_OPTION, $plugin_file, false );
			}
			$report['active'] = true;
			$report['status'] = 'installed_activated';
			return $report;
		}

		$plugin_file = (string) $descriptor['plugin_file'];
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
			$report['active'] = true;
		} elseif ( function_exists( 'activate_plugin' ) ) {
			$activate = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate ) ) {
				return self::failed_report( $report, $activate );
			}
			$report['active']    = true;
			$report['actions'][] = 'activated';
		} else {
			return self::failed_report(
				$report,
				new WP_Error(
					'static_site_importer_companion_activate_unavailable',
					'WordPress plugin activation API is unavailable.'
				)
			);
		}
		if ( null !== $availability_check && ! self::available( $availability_check ) ) {
			return self::failed_report(
				$report,
				new WP_Error(
					'static_site_importer_companion_plugin_unavailable',
					sprintf( 'Companion plugin %s was installed/activated but is still unavailable.', (string) $descriptor['slug'] )
				)
			);
		}
		$registered = self::register_generated_blocks( $descriptor, $plan );
		if ( is_wp_error( $registered ) ) {
			return self::failed_report( $report, $registered );
		}
		$report['registration'] = $registered;
		self::replace_active_generated_companion( $plugin_file, $report );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::ACTIVE_COMPANION_OPTION, $plugin_file, false );
		}

		$report['status'] = $already_available ? 'refreshed' : 'installed_activated';
		return $report;
	}

	/**
	 * Register a materialized companion package in the current request.
	 *
	 * Activation updates persistent state after the WordPress init lifecycle has
	 * run. A compiler-declared companion owns its registration callback, so SSI
	 * invokes that callback and verifies its declared inventory before editor
	 * validation observes the imported content.
	 *
	 * @param array<string,mixed> $descriptor Generated companion descriptor.
	 * @param array<string,mixed> $plan       Generated companion install plan.
	 * @return true|WP_Error
	 */
	private static function register_generated_blocks( array $descriptor, array $plan ) {
		$slug        = isset( $descriptor['slug'] ) && is_string( $descriptor['slug'] ) ? $descriptor['slug'] : '';
		$plugin_file = isset( $descriptor['plugin_file'] ) && is_string( $descriptor['plugin_file'] ) ? $descriptor['plugin_file'] : '';
		$base_dir    = isset( $plan['base_dir'] ) && is_string( $plan['base_dir'] ) ? $plan['base_dir'] : '';
		$callback    = isset( $descriptor['registration_callback'] ) && is_string( $descriptor['registration_callback'] ) ? $descriptor['registration_callback'] : '';
		$path        = '' === $base_dir || '' === $plugin_file ? '' : rtrim( $base_dir, '/\\' ) . '/' . $plugin_file;

		if ( '' === $slug || '' === $callback || '' === $path || ! is_readable( $path ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_registration_unavailable', 'Generated companion block registration file is unavailable.' );
		}
		if ( ! function_exists( $callback ) ) {
			include $path;
		}
		if ( ! is_callable( $callback ) ) {
			return new WP_Error( 'static_site_importer_companion_plugin_registration_unavailable', sprintf( 'Generated companion %s does not expose its block registration callback.', $slug ) );
		}

		call_user_func( $callback );
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
		$missing  = array();
		foreach ( $descriptor['block_names'] ?? array() as $block_name ) {
			if ( ! is_string( $block_name ) || '' === $block_name || ! $registry || ! $registry->is_registered( $block_name ) ) {
				$missing[] = $block_name;
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_registration_incomplete',
				'Generated companion blocks were not registered before editor use.',
				array( 'missing_block_names' => $missing )
			);
		}

		return true;
	}

	/** Preflight registered block names before generated files are written. */
	private static function generated_block_name_collision( array $block_names, array $descriptor ) {
		$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
		foreach ( $block_names as $block_name ) {
			if ( ! is_string( $block_name ) || '' === $block_name ) {
				continue;
			}
			$registered = $registry && $registry->is_registered( $block_name );
			if ( function_exists( 'apply_filters' ) ) {
				/** Filters runtime registry collision detection for generated companion blocks. */
				$registered = (bool) apply_filters( 'ssi_companion_plugin_block_name_collision', $registered, $block_name, $registry );
			}
			if ( $registered && ! self::current_companion_owns_registered_block( $block_name, $descriptor ) ) {
				return new WP_Error(
					'static_site_importer_companion_plugin_block_name_collision',
					sprintf( 'Generated companion block name %s is already registered.', $block_name ),
					array(
						'block_name'  => $block_name,
						'status'      => 'rejected',
						'reason_code' => 'runtime_block_name_collision',
					)
				);
			}
		}

		return true;
	}

	/**
	 * Whether an existing block registration belongs to this active generated companion.
	 *
	 * The generated plugin records its exact plugin file and runtime path when it
	 * registers a block. Both must match the pending descriptor; a matching block
	 * name or namespace alone never establishes ownership.
	 */
	private static function current_companion_owns_registered_block( string $block_name, array $descriptor ): bool {
		$plugin_file = isset( $descriptor['plugin_file'] ) && is_string( $descriptor['plugin_file'] ) ? $descriptor['plugin_file'] : '';
		if ( '' === $plugin_file || ! function_exists( 'get_option' ) || (string) get_option( self::ACTIVE_COMPANION_OPTION, '' ) !== $plugin_file ) {
			return false;
		}
		if ( empty( $descriptor['mu_plugin'] ) && ( ! function_exists( 'is_plugin_active' ) || ! is_plugin_active( $plugin_file ) ) ) {
			return false;
		}

		$owners = $GLOBALS['static_site_importer_companion_block_owners'] ?? array();
		$owner  = is_array( $owners ) && isset( $owners[ $block_name ] ) && is_array( $owners[ $block_name ] ) ? $owners[ $block_name ] : array();
		$base   = ! empty( $descriptor['mu_plugin'] ) ? ( defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '' ) : ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '' );
		$path   = '' === $base ? '' : rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . $plugin_file;

		return (string) ( $owner['plugin_file'] ?? '' ) === $plugin_file
			&& str_replace( '\\', '/', (string) ( $owner['plugin_path'] ?? '' ) ) === $path;
	}

	/**
	 * Deactivate the regular generated companion replaced by this import.
	 *
	 * @param string               $plugin_file New generated companion basename.
	 * @param array<string, mixed> $report      Materialization report.
	 */
	private static function replace_active_generated_companion( string $plugin_file, array &$report ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$previous = (string) get_option( self::ACTIVE_COMPANION_OPTION, '' );
		if ( '' === $previous || $plugin_file === $previous || ! function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) || is_plugin_active( $previous ) ) {
			deactivate_plugins( $previous );
			$report['actions'][] = 'replaced:' . $previous;
		}
	}

	/**
	 * Build a deterministic install plan for a scaffolded companion plugin.
	 *
	 * Resolves the destination directory and the absolute file paths without
	 * touching the filesystem, so the file set and activation intent can be
	 * asserted in isolation. WordPress directory constants are read when defined
	 * and may be overridden for tests.
	 *
	 * @param array<string, mixed> $descriptor Scaffolder descriptor.
	 * @param string|null          $base_dir   Optional destination override.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generated_install_plan( array $descriptor, ?string $base_dir = null ) {
		$files = isset( $descriptor['files'] ) && is_array( $descriptor['files'] ) ? $descriptor['files'] : array();
		if ( empty( $files ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_empty',
				'Companion-plugin scaffold produced no files to install.'
			);
		}

		$mu_plugin = ! empty( $descriptor['mu_plugin'] );

		if ( null === $base_dir ) {
			if ( $mu_plugin && defined( 'WPMU_PLUGIN_DIR' ) ) {
				$base_dir = (string) WPMU_PLUGIN_DIR;
			} elseif ( ! $mu_plugin && defined( 'WP_PLUGIN_DIR' ) ) {
				$base_dir = (string) WP_PLUGIN_DIR;
			} else {
				$base_dir = '';
			}
		}

		$absolute = array();
		if ( '' !== $base_dir ) {
			$prefix = rtrim( $base_dir, '/' ) . '/';
			foreach ( $files as $relative => $content ) {
				$absolute[ $prefix . $relative ] = $content;
			}
		}

		return array(
			'slug'           => (string) ( $descriptor['slug'] ?? '' ),
			'plugin_file'    => (string) ( $descriptor['plugin_file'] ?? '' ),
			'mu_plugin'      => $mu_plugin,
			'destination'    => $mu_plugin ? 'mu_plugin' : 'plugin',
			'base_dir'       => $base_dir,
			'files'          => $files,
			'absolute_files' => $absolute,
			// mu-plugins do not require an activation call; regular plugins do.
			'activate'       => ! $mu_plugin,
		);
	}

	/**
	 * Write a generated install plan's files to disk.
	 *
	 * @param array<string, mixed> $plan Install plan from generated_install_plan().
	 * @return true|WP_Error
	 */
	private static function write_generated_files( array $plan ) {
		$absolute = isset( $plan['absolute_files'] ) && is_array( $plan['absolute_files'] ) ? $plan['absolute_files'] : array();
		if ( empty( $absolute ) ) {
			return new WP_Error(
				'static_site_importer_companion_plugin_dir_unresolved',
				'Companion-plugin destination directory could not be resolved.'
			);
		}

		foreach ( $absolute as $path => $content ) {
			$dir = dirname( (string) $path );
			if ( ! is_dir( $dir ) ) {
				$created = function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $dir ) : false;
				if ( ! $created ) {
					return new WP_Error(
						'static_site_importer_companion_plugin_mkdir_failed',
						sprintf( 'Failed to create companion-plugin directory: %s', $dir )
					);
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the generated companion plugin into the plugins directory.
			if ( false === file_put_contents( (string) $path, (string) $content ) ) {
				return new WP_Error(
					'static_site_importer_companion_plugin_write_failed',
					sprintf( 'Failed to write companion-plugin file: %s', $path )
				);
			}
		}

		return true;
	}

	/**
	 * Build an initial generated-plugin materialization report.
	 *
	 * @param string $slug        Companion plugin slug.
	 * @param string $plugin_file Plugin basename.
	 * @return array<string, mixed>
	 */
	private static function new_generated_report( string $slug, string $plugin_file ): array {
		$report                = self::new_report( $slug, $plugin_file );
		$report['source']      = 'generated';
		$report['mu_plugin']   = false;
		$report['block_names'] = array();
		$report['files']       = array();
		return $report;
	}

	/**
	 * Build an initial materialization report.
	 *
	 * @param string $slug        WordPress.org plugin slug.
	 * @param string $plugin_file Plugin basename.
	 * @return array<string, mixed>
	 */
	private static function new_report( string $slug, string $plugin_file ): array {
		return array(
			'slug'        => $slug,
			'plugin_file' => $plugin_file,
			'source'      => 'wordpress.org',
			'status'      => 'not_run',
			'attempted'   => false,
			'installed'   => false,
			'active'      => false,
			'actions'     => array(),
			'error'       => '',
			'provenance'  => array(
				'source'  => 'wordpress.org',
				'version' => '',
				'sha256'  => '',
			),
		);
	}

	/** Record the exact activated plugin entrypoint instead of inferring a package version. */
	private static function record_installed_provenance( array &$report ): void {
		$file = trailingslashit( WP_PLUGIN_DIR ) . (string) $report['plugin_file'];
		if ( ! is_readable( $file ) ) {
			return;
		}
		$headers              = function_exists( 'get_plugin_data' ) ? get_plugin_data( $file, false, false ) : array();
		$sha256               = hash_file( 'sha256', $file );
		$report['provenance'] = array(
			'source'  => 'wordpress.org',
			'version' => (string) ( $headers['Version'] ?? '' ),
			'sha256'  => false !== $sha256 ? $sha256 : '',
		);
	}

	/** Re-read an upgrader-mutated entrypoint instead of reusing pre-install state. */
	private static function plugin_entrypoint_exists( string $path ): bool {
		clearstatcache( true, $path );
		return file_exists( $path );
	}

	/**
	 * Mark a materialization report as failed.
	 *
	 * @param array<string, mixed> $report Report being built.
	 * @param WP_Error             $error  Failure details.
	 * @return array<string, mixed>
	 */
	private static function failed_report( array $report, WP_Error $error ): array {
		$report['status'] = 'failed';
		$report['error']  = array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $error->get_error_data(),
		);
		if ( is_array( $report['error']['data'] ) && isset( $report['error']['data']['reason_code'] ) ) {
			$report['diagnostics'][] = array_merge(
				$report['error']['data'],
				array(
					'code'    => $report['error']['code'],
					'message' => $report['error']['message'],
				)
			);
		}

		return $report;
	}

	/**
	 * Reopen completed lifecycle hooks while a dependency plugin is activated.
	 *
	 * Plugins activated during an import miss the normal request's plugins_loaded,
	 * init, and wp_loaded windows. Snapshotting existing callbacks lets SSI replay
	 * only callbacks introduced by the dependency, without rerunning WordPress or
	 * other active plugins.
	 *
	 * @return array<string,array{callbacks:array<string,bool>,did_action:int}>
	 */
	private static function prepare_activation_lifecycle_replay(): array {
		global $wp_actions;

		$state = array();
		foreach ( array( 'plugins_loaded', 'init', 'wp_loaded' ) as $hook_name ) {
			$count               = function_exists( 'did_action' ) ? (int) did_action( $hook_name ) : 0;
			$state[ $hook_name ] = array(
				'callbacks'  => self::snapshot_hook_callbacks( $hook_name ),
				'did_action' => $count,
			);
			if ( $count > 0 ) {
				if ( ! is_array( $wp_actions ) ) {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resets the lifecycle action counter while replaying newly registered callbacks.
					$wp_actions = array();
				}
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resets this lifecycle action counter before callback replay.
				$wp_actions[ $hook_name ] = 0;
			}
		}

		return $state;
	}

	/** @return array<string,int> Replayed callback counts keyed by lifecycle hook. */
	private static function complete_activation_lifecycle_replay( array $state ): array {
		$replayed = array();
		try {
			foreach ( $state as $hook_name => $hook_state ) {
				if ( (int) ( $hook_state['did_action'] ?? 0 ) <= 0 ) {
					continue;
				}
				$callbacks = self::defer_new_hook_callbacks(
					(string) $hook_name,
					is_array( $hook_state['callbacks'] ?? null ) ? $hook_state['callbacks'] : array()
				);
				self::run_deferred_hook_callbacks( (string) $hook_name, $callbacks );
				$replayed[ (string) $hook_name ] = count( $callbacks );
			}
		} finally {
			self::restore_activation_lifecycle_actions( $state );
		}

		return $replayed;
	}

	/** @return array<string,bool> */
	private static function snapshot_hook_callbacks( string $hook_name ): array {
		global $wp_filter;

		$snapshot = array();
		if ( ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) {
			return $snapshot;
		}
		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( array_keys( $callbacks ) as $callback_id ) {
				$snapshot[ $priority . ':' . $callback_id ] = true;
			}
		}

		return $snapshot;
	}

	/** @return array<int,array{priority:int,callback:array<string,mixed>}> */
	private static function defer_new_hook_callbacks( string $hook_name, array $before ): array {
		global $wp_filter;

		$deferred = array();
		if ( ! isset( $wp_filter[ $hook_name ]->callbacks ) || ! is_array( $wp_filter[ $hook_name ]->callbacks ) ) {
			return $deferred;
		}
		$callbacks_by_priority = $wp_filter[ $hook_name ]->callbacks;
		foreach ( $callbacks_by_priority as $priority => $callbacks ) {
			foreach ( $callbacks as $callback_id => $callback ) {
				if ( isset( $before[ $priority . ':' . $callback_id ] ) || ! isset( $callback['function'] ) ) {
					continue;
				}
				if ( remove_action( $hook_name, $callback['function'], (int) $priority ) ) {
					$deferred[] = array(
						'priority' => (int) $priority,
						'callback' => $callback,
					);
				}
			}
		}
		usort( $deferred, static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );

		return $deferred;
	}

	private static function run_deferred_hook_callbacks( string $hook_name, array $deferred ): void {
		global $wp_current_filter;

		if ( ! is_array( $wp_current_filter ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Initializes WordPress's current-hook stack for direct callback replay.
			$wp_current_filter = array();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Pushes the replayed hook onto WordPress's current-hook stack.
		$wp_current_filter[] = $hook_name;
		try {
			foreach ( $deferred as $entry ) {
				$callback = $entry['callback'] ?? null;
				if ( ! is_array( $callback ) || ! isset( $callback['function'] ) ) {
					continue;
				}
				call_user_func( $callback['function'] );
			}
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	private static function restore_activation_lifecycle_actions( array $state ): void {
		global $wp_actions;

		if ( ! is_array( $wp_actions ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Initializes lifecycle action counters before restoring the replay state.
			$wp_actions = array();
		}
		foreach ( $state as $hook_name => $hook_state ) {
			$count = (int) ( $hook_state['did_action'] ?? 0 );
			if ( $count > 0 ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the original lifecycle action count after callback replay.
				$wp_actions[ (string) $hook_name ] = max( $count, (int) ( $wp_actions[ $hook_name ] ?? 0 ) );
			}
		}
	}

	/**
	 * Check whether expected plugin APIs are already available.
	 *
	 * @param callable|null $availability_check Optional availability callback.
	 * @return bool
	 *
	 * @phpstan-impure Plugin activation can change callback results within one request.
	 */
	private static function available( ?callable $availability_check ): bool {
		return null !== $availability_check && true === (bool) call_user_func( $availability_check );
	}

	/**
	 * Load WordPress admin plugin install/activation dependencies.
	 *
	 * @return true|WP_Error
	 */
	private static function load_admin_dependencies() {
		$files = array(
			ABSPATH . 'wp-admin/includes/plugin.php',
			ABSPATH . 'wp-admin/includes/file.php',
			ABSPATH . 'wp-admin/includes/misc.php',
			ABSPATH . 'wp-admin/includes/plugin-install.php',
			ABSPATH . 'wp-admin/includes/class-wp-upgrader.php',
		);

		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return new WP_Error(
				'static_site_importer_plugin_upgrader_unavailable',
				'WordPress plugin upgrader classes are unavailable.'
			);
		}

		return true;
	}

	/**
	 * Install a WordPress.org plugin by slug.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return true|WP_Error
	 */
	private static function install_wp_org_plugin( string $slug ) {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			try {
				$result = WP_CLI::runcommand(
					'plugin install ' . escapeshellarg( $slug ),
					array(
						'return'        => true,
						'exit_on_error' => false,
					)
				);
				if ( 0 === $result || null === $result || true === $result ) {
					return true;
				}
				return new WP_Error( 'static_site_importer_plugin_install_failed', sprintf( 'WP-CLI could not install plugin %s.', $slug ) );
			} catch ( Throwable $error ) {
				return new WP_Error( 'static_site_importer_plugin_install_failed', $error->getMessage() );
			}
		}
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections' => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$download_link = isset( $api->download_link ) ? (string) $api->download_link : '';
		if ( '' === $download_link ) {
			return new WP_Error(
				'static_site_importer_plugin_download_missing',
				sprintf( 'WordPress.org did not return a download link for %s.', $slug )
			);
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error(
				'static_site_importer_plugin_install_failed',
				sprintf( 'WordPress could not install plugin %s.', $slug )
			);
		}

		return true;
	}
}
