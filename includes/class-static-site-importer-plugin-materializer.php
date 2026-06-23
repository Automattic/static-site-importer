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
