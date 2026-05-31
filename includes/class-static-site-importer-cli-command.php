<?php
/**
 * WP-CLI command.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static site importer CLI command.
 */
class Static_Site_Importer_CLI_Command {

	/**
	 * Import a static-site HTML entry as a block theme.
	 *
	 * @subcommand import-theme
	 *
	 * ## OPTIONS
	 *
	 * [<html-entry-file>]
	 * : Path to an HTML shell/chrome entry file. Optional when --url is provided. Sibling HTML files and nested .md/.markdown source documents in the selected source tree are imported as pages; .mdx files are skipped with explicit report diagnostics.
	 *
	 * [--url=<url>]
	 * : Fetch one public http/https HTML URL and import it as index.html. Redirects are validated with SSRF protections before connecting. URL intake imports a single fetched HTML document only.
	 *
	 * [--slug=<slug>]
	 * : Theme slug.
	 *
	 * [--name=<name>]
	 * : Theme name.
	 *
	 * [--activate]
	 * : Activate the generated theme.
	 *
	 * [--overwrite]
	 * : Overwrite an existing theme directory.
	 *
	 * [--keep-source]
	 * : Preserve the source static-site directory after a successful clean import for debugging or development. Sources are always preserved when import quality checks report issues.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks, invalid blocks, or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * [--allow-missing-woocommerce]
	 * : Allow commerce-bearing imports to proceed when WooCommerce is not active. Default is to fail the import. Theme files are still written either way; the waiver only suppresses product seeding without aborting.
	 *
		 * [--report=<path>]
		 * : Copy the generated import report JSON to an external archive path.
		 *
		 * [--asset-policy=<policy>]
		 * : Copy target for resolved local assets. Use media-library to import supported copied assets as WordPress attachments; defaults to theme.
		 *
		 * [--asset-materialization-policy=<policy>]
		 * : Local asset materialization policy. One of copy_to_theme, preserve, use_map. Defaults to copy_to_theme.
		 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable command output.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_theme( array $args, array $assoc_args ): void {
		$html_file       = $args[0] ?? '';
		$source_metadata = array();
		if ( isset( $assoc_args['url'] ) && '' !== trim( (string) $assoc_args['url'] ) ) {
			if ( '' !== $html_file ) {
				$this->error_or_json( 'static_site_importer_conflicting_sources', 'Use either <html-entry-file> or --url, not both.', $assoc_args );
				return;
			}

			$fetch = Static_Site_Importer_URL_Fetcher::fetch_to_work_dir(
				(string) $assoc_args['url'],
				trailingslashit( wp_upload_dir()['basedir'] ) . 'static-site-importer/' . wp_generate_uuid4()
			);
			if ( is_wp_error( $fetch ) ) {
				$this->error_or_json( (string) $fetch->get_error_code(), $fetch->get_error_message(), $assoc_args );
				return;
			}

			$html_file       = $fetch['html_path'];
			$source_metadata = $fetch['metadata'];
		}

		if ( '' === $html_file ) {
			$this->error_or_json( 'static_site_importer_missing_html_path', 'Missing <html-entry-file> argument or --url option.', $assoc_args );
			return;
		}

		$ability = wp_get_ability( 'static-site-importer/import-theme' );
		if ( ! $ability ) {
			$this->error_or_json( 'static_site_importer_ability_missing', 'Static Site Importer import ability is not registered.', $assoc_args );
			return;
		}

		$ability_args = array(
			'html_path'                 => $html_file,
			'slug'                      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
			'name'                      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
			'activate'                  => isset( $assoc_args['activate'] ),
			'overwrite'                 => isset( $assoc_args['overwrite'] ),
			'keep_source'               => isset( $assoc_args['keep-source'] ),
			'fail_on_quality'           => isset( $assoc_args['fail-on-quality'] ),
			'allow_missing_woocommerce' => isset( $assoc_args['allow-missing-woocommerce'] ),
			'report'                    => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
			'asset_policy'              => isset( $assoc_args['asset-policy'] ) ? (string) $assoc_args['asset-policy'] : '',
			'asset_materialization_policy' => isset( $assoc_args['asset-materialization-policy'] ) ? (string) $assoc_args['asset-materialization-policy'] : '',
			'source_metadata'           => $source_metadata,
		);

		if ( isset( $assoc_args['max-fallbacks'] ) ) {
			$ability_args['max_fallbacks'] = (int) $assoc_args['max-fallbacks'];
		}

		$ability_result = $ability->execute( $ability_args );

		if ( is_wp_error( $ability_result ) ) {
			$this->error_or_json( (string) $ability_result->get_error_code(), $ability_result->get_error_message(), $assoc_args );
			return;
		}

		if ( empty( $ability_result['success'] ) ) {
			$error = isset( $ability_result['error'] ) && is_array( $ability_result['error'] ) ? $ability_result['error'] : array();
			$this->error_or_json(
				isset( $error['code'] ) ? (string) $error['code'] : 'static_site_importer_failed',
				isset( $error['message'] ) ? (string) $error['message'] : 'Static site import failed.',
				$assoc_args,
				isset( $ability_result['import_report_summary'] ) && is_array( $ability_result['import_report_summary'] ) ? $ability_result['import_report_summary'] : array()
			);
			return;
		}

		$result = isset( $ability_result['result'] ) && is_array( $ability_result['result'] ) ? $ability_result['result'] : array();

		$failure_reasons       = isset( $result['quality']['failure_reasons'] ) && is_array( $result['quality']['failure_reasons'] ) ? $result['quality']['failure_reasons'] : array();
		$commerce_dep_failure  = in_array( 'woocommerce_missing', $failure_reasons, true );
		$allow_missing_woo_cli = isset( $assoc_args['allow-missing-woocommerce'] );
		$fail_import           = ! empty( $result['quality']['fail_import'] );
		$is_json               = isset( $assoc_args['format'] ) && 'json' === (string) $assoc_args['format'];

		if ( $is_json ) {
			// Always emit the structured payload so JSON parsers see quality.fail_import,
			// failure_reasons, and the commerce.dependencies block on the report. When the
			// gate trips, exit non-zero after printing so machine-readable callers (e.g.
			// wc-site-generator) cannot mistake a failed import for success.
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $fail_import ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		// Human format: report failures first so we never print "success" before erroring.
		if ( $commerce_dep_failure && $fail_import ) {
			WP_CLI::error(
				sprintf(
					"WooCommerce is required for this import. The source declared products but WooCommerce is not active.\nInstall and activate WooCommerce, or rerun with --allow-missing-woocommerce to import the theme without seeding products.\nTheme files were written for inspection at: %s\nImport report: %s",
					$result['theme_dir'],
					$result['report_path']
				)
			);
			return;
		}

		if ( $fail_import ) {
			WP_CLI::error(
				sprintf(
					"Conversion quality gate failed. Theme files were written for inspection at: %s\nImport report: %s",
					$result['theme_dir'],
					$result['report_path']
				)
			);
			return;
		}

		WP_CLI::success( sprintf( 'Imported static site as block theme "%s" (%s).', $result['theme_name'], $result['theme_slug'] ) );
		WP_CLI::line( sprintf( 'Theme directory: %s', $result['theme_dir'] ) );
		WP_CLI::line( sprintf( 'Import report: %s', $result['report_path'] ) );
		if ( ! empty( $result['external_report_path'] ) ) {
			WP_CLI::line( sprintf( 'External import report: %s', $result['external_report_path'] ) );
		}
		if ( ! empty( $result['source_cleanup_error'] ) ) {
			WP_CLI::warning( sprintf( 'Source cleanup skipped: %s', $result['source_cleanup_error'] ) );
		}
		if ( ! empty( $source_metadata['final_url'] ) ) {
			WP_CLI::line( sprintf( 'Fetched URL: %s', $source_metadata['final_url'] ) );
		}
		$source_documents = $result['source_documents'];
		$counts           = isset( $source_documents['counts_by_format'] ) && is_array( $source_documents['counts_by_format'] ) ? $source_documents['counts_by_format'] : array();
		WP_CLI::line( sprintf( 'Source documents: %d HTML, %d Markdown, %d skipped MDX, %d unresolved links.', (int) ( $counts['html'] ?? 0 ), (int) ( $counts['markdown'] ?? 0 ), (int) ( $source_documents['skipped_mdx_count'] ?? 0 ), (int) ( $source_documents['unresolved_link_count'] ?? 0 ) ) );
		WP_CLI::line( sprintf( 'Conversion quality: %s (%d unsupported HTML fallbacks, %d invalid blocks, %d content-loss aborts).', $result['quality']['pass'] ? 'pass' : 'needs review', $result['quality']['fallback_count'], $result['quality']['invalid_block_count'], $result['quality']['content_loss_count'] ) );

		if ( ! $result['quality']['pass'] ) {
			WP_CLI::warning( 'Conversion quality checks reported issues. Inspect import-report.json for source fragments and diagnostics.' );
		}

		if ( $allow_missing_woo_cli ) {
			WP_CLI::warning( 'WooCommerce dependency check waived via --allow-missing-woocommerce. Products were not seeded.' );
		}
	}

	/**
	 * Import a website artifact bundle as a block theme.
	 *
	 * @subcommand import-website-artifact
	 *
	 * ## OPTIONS
	 *
	 * <artifact-json-file>
	 * : Path to a JSON website artifact bundle accepted by Block Artifact Compiler.
	 *
	 * [--slug=<slug>]
	 * : Theme slug.
	 *
	 * [--name=<name>]
	 * : Theme name.
	 *
	 * [--activate]
	 * : Activate the generated theme.
	 *
	 * [--overwrite]
	 * : Overwrite an existing theme directory.
	 *
	 * [--keep-source]
	 * : Preserve the temporary artifact source directory after a successful clean import.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks, invalid blocks, or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * [--allow-missing-woocommerce]
	 * : Allow commerce-bearing imports to proceed when WooCommerce is not active.
	 *
		 * [--report=<path>]
		 * : Copy the generated import report JSON to an external archive path.
		 *
		 * [--asset-policy=<policy>]
		 * : Copy target for resolved local assets. Use media-library to import supported copied assets as WordPress attachments; defaults to theme.
		 *
		 * [--asset-materialization-policy=<policy>]
		 * : Local asset materialization policy. One of copy_to_theme, preserve, use_map. Defaults to copy_to_theme.
		 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable command output.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_website_artifact( array $args, array $assoc_args ): void {
		$artifact_file = $args[0] ?? '';
		if ( '' === $artifact_file || ! is_file( $artifact_file ) ) {
			$this->error_or_json( 'static_site_importer_missing_artifact_file', 'Missing readable <artifact-json-file> argument.', $assoc_args );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local artifact JSON file selected for import.
		$artifact_json = file_get_contents( $artifact_file );
		$artifact      = is_string( $artifact_json ) ? json_decode( $artifact_json, true ) : null;
		if ( ! is_array( $artifact ) ) {
			$this->error_or_json( 'static_site_importer_invalid_artifact_json', 'Artifact file must contain a JSON object.', $assoc_args );
			return;
		}

		$ability = wp_get_ability( 'static-site-importer/import-website-artifact' );
		if ( ! $ability ) {
			$this->error_or_json( 'static_site_importer_ability_missing', 'Static Site Importer website artifact ability is not registered.', $assoc_args );
			return;
		}

		$ability_args = array(
			'artifact'                  => $artifact,
			'slug'                      => isset( $assoc_args['slug'] ) ? (string) $assoc_args['slug'] : '',
			'name'                      => isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '',
			'activate'                  => isset( $assoc_args['activate'] ),
			'overwrite'                 => isset( $assoc_args['overwrite'] ),
			'keep_source'               => isset( $assoc_args['keep-source'] ),
			'fail_on_quality'           => isset( $assoc_args['fail-on-quality'] ),
			'allow_missing_woocommerce' => isset( $assoc_args['allow-missing-woocommerce'] ),
			'report'                    => isset( $assoc_args['report'] ) ? (string) $assoc_args['report'] : '',
			'asset_policy'              => isset( $assoc_args['asset-policy'] ) ? (string) $assoc_args['asset-policy'] : '',
			'asset_materialization_policy' => isset( $assoc_args['asset-materialization-policy'] ) ? (string) $assoc_args['asset-materialization-policy'] : '',
			'source_metadata'           => array( 'artifact_file' => $artifact_file ),
		);

		if ( isset( $assoc_args['max-fallbacks'] ) ) {
			$ability_args['max_fallbacks'] = (int) $assoc_args['max-fallbacks'];
		}

		$ability_result = $ability->execute( $ability_args );
		if ( is_wp_error( $ability_result ) ) {
			$this->error_or_json( (string) $ability_result->get_error_code(), $ability_result->get_error_message(), $assoc_args );
			return;
		}

		if ( empty( $ability_result['success'] ) ) {
			$error = isset( $ability_result['error'] ) && is_array( $ability_result['error'] ) ? $ability_result['error'] : array();
			$this->error_or_json(
				isset( $error['code'] ) ? (string) $error['code'] : 'static_site_importer_failed',
				isset( $error['message'] ) ? (string) $error['message'] : 'Website artifact import failed.',
				$assoc_args,
				isset( $ability_result['import_report_summary'] ) && is_array( $ability_result['import_report_summary'] ) ? $ability_result['import_report_summary'] : array()
			);
			return;
		}

		$result  = isset( $ability_result['result'] ) && is_array( $ability_result['result'] ) ? $ability_result['result'] : array();
		$is_json = isset( $assoc_args['format'] ) && 'json' === (string) $assoc_args['format'];
		if ( $is_json ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( ! empty( $result['quality']['fail_import'] ) ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		if ( ! empty( $result['quality']['fail_import'] ) ) {
			WP_CLI::error( sprintf( "Conversion quality gate failed. Theme files were written for inspection at: %s\nImport report: %s", $result['theme_dir'], $result['report_path'] ) );
			return;
		}

		WP_CLI::success( sprintf( 'Imported website artifact as block theme "%s" (%s).', $result['theme_name'], $result['theme_slug'] ) );
		WP_CLI::line( sprintf( 'Theme directory: %s', $result['theme_dir'] ) );
		WP_CLI::line( sprintf( 'Import report: %s', $result['report_path'] ) );
	}

	/**
	 * Emit a human WP-CLI error or a machine-readable JSON failure payload.
	 *
	 * @param string               $code           Error code.
	 * @param string               $message        Error message.
	 * @param array<string, mixed> $assoc_args     CLI associative args.
	 * @param array<string, mixed> $report_summary Optional report summary.
	 * @return void
	 */
	private function error_or_json( string $code, string $message, array $assoc_args, array $report_summary = array() ): void {
		if ( isset( $assoc_args['format'] ) && 'json' === (string) $assoc_args['format'] ) {
			if ( empty( $report_summary ) && function_exists( 'static_site_importer_failure_report_summary' ) ) {
				$report_summary = static_site_importer_failure_report_summary( $code, $message );
			}

			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'success'               => false,
						'error'                 => array(
							'code'    => $code,
							'message' => $message,
						),
						'import_report_summary' => $report_summary,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			WP_CLI::halt( 1 );
		}

		WP_CLI::error( $message );
	}

	/**
	 * Import one public URL as a block theme.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Public http/https URL to fetch.
	 *
	 * [--slug=<slug>]
	 * : Theme slug.
	 *
	 * [--name=<name>]
	 * : Theme name.
	 *
	 * [--activate]
	 * : Activate the generated theme.
	 *
	 * [--overwrite]
	 * : Overwrite an existing theme directory.
	 *
	 * [--keep-source]
	 * : Preserve the fetched source directory after a successful clean import.
	 *
	 * [--fail-on-quality]
	 * : Exit non-zero when conversion quality checks report fallbacks, invalid blocks, or content loss.
	 *
	 * [--max-fallbacks=<count>]
	 * : Exit non-zero when unsupported HTML fallback count exceeds this threshold.
	 *
	 * [--allow-missing-woocommerce]
	 * : Allow commerce-bearing imports to proceed when WooCommerce is not active.
	 *
	 * [--report=<path>]
	 * : Copy the generated import report JSON to an external archive path.
	 *
	 * [--format=<format>]
	 * : Output format. Use json for machine-readable command output.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function import_url( array $args, array $assoc_args ): void {
		$url = $args[0] ?? '';
		if ( '' === trim( $url ) ) {
			WP_CLI::error( 'Missing <url> argument.' );
			return;
		}

		$assoc_args['url'] = $url;
		$this->import_theme( array(), $assoc_args );
	}
}
