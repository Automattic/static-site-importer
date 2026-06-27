<?php
/**
 * Deterministic WordPress plugin materialization helpers.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and activates declared WordPress.org plugins before entity seeding.
 */
class Static_Site_Importer_Plugin_Materializer {

	/**
	 * Ensure a WordPress.org plugin is installed, active, and exposes expected APIs.
	 *
	 * @param string        $slug               WordPress.org plugin slug.
	 * @param string        $plugin_file        Plugin basename, e.g. woocommerce/woocommerce.php.
	 * @param callable|null $availability_check Optional callback that returns true when plugin APIs are available.
	 * @return array<string, mixed>
	 */
	public static function ensure_wp_org_plugin(
		string $slug,
		string $plugin_file,
		?callable $availability_check = null
	): array {
		$report = self::new_report( $slug, $plugin_file );

		if ( self::available( $availability_check ) ) {
			$report['status']    = 'already_available';
			$report['installed'] = true;
			$report['active']    = true;
			return $report;
		}

		$report['attempted'] = true;

		$deps = self::load_admin_dependencies();
		if ( is_wp_error( $deps ) ) {
			return self::failed_report( $report, $deps );
		}

		if ( file_exists( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file ) ) {
			$report['installed'] = true;
		} else {
			$install = self::install_wp_org_plugin( $slug );
			if ( is_wp_error( $install ) ) {
				return self::failed_report( $report, $install );
			}

			$report['installed'] = true;
			$report['actions'][] = 'installed';
		}

		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
			$report['active'] = true;
		} else {
			$activate = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate ) ) {
				return self::failed_report( $report, $activate );
			}

			$report['active']    = true;
			$report['actions'][] = 'activated';
		}

		if ( ! self::available( $availability_check ) ) {
			return self::failed_report(
				$report,
				new WP_Error(
					'static_site_importer_plugin_apis_missing',
					sprintf( 'Plugin %s was installed/activated but expected APIs are still unavailable.', $slug )
				)
			);
		}

		$report['status'] = in_array( 'installed', $report['actions'], true )
			? 'installed_activated'
			: 'activated';
		return $report;
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
		$descriptor = Static_Site_Importer_Companion_Plugin::scaffold( $payload );
		if ( is_wp_error( $descriptor ) ) {
			$report = self::new_generated_report( '', '' );
			return self::failed_report( $report, $descriptor );
		}

		$report                = self::new_generated_report( (string) $descriptor['slug'], (string) $descriptor['plugin_file'] );
		$report['mu_plugin']   = (bool) $descriptor['mu_plugin'];
		$report['block_names'] = $descriptor['block_names'];

		$plan = self::generated_install_plan( $descriptor );
		if ( is_wp_error( $plan ) ) {
			return self::failed_report( $report, $plan );
		}

		$report['files'] = array_keys( $plan['files'] );

		if ( self::available( $availability_check ) ) {
			$report['status']    = 'already_available';
			$report['installed'] = true;
			$report['active']    = true;
			return $report;
		}

		$report['attempted'] = true;

		$written = self::write_generated_files( $plan );
		if ( is_wp_error( $written ) ) {
			return self::failed_report( $report, $written );
		}
		$report['installed'] = true;
		$report['actions'][] = 'installed';

		if ( false === $plan['activate'] ) {
			// mu-plugins are always active; no activation call is required.
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

		$report['status'] = 'installed_activated';
		return $report;
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
		);
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
		);

		return $report;
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
