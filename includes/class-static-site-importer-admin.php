<?php
/**
 * Admin UI.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the source-site import admin entry point.
 */
class Static_Site_Importer_Admin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_import_page' ) );
		add_action( 'admin_head-themes.php', array( __CLASS__, 'render_themes_screen_button' ) );
		add_action( 'admin_post_static_site_importer_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Register the import page without adding a persistent Appearance submenu item.
	 *
	 * @return void
	 */
	public static function register_import_page(): void {
		add_submenu_page(
			null, // @phpstan-ignore argument.type
			'Import Static Site',
			'Import Static Site',
			'switch_themes',
			'static-site-importer',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add an Import Static Site button beside Add Theme on Appearance -> Themes.
	 *
	 * @return void
	 */
	public static function render_themes_screen_button(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return;
		}

		$url   = admin_url( 'admin.php?page=static-site-importer' );
		$label = __( 'Import Static Site', 'static-site-importer' );
		?>
		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				var addThemeButton = document.querySelector( '.wrap .page-title-action[href*="theme-install.php"]' );
				var heading = document.querySelector( '.wrap .wp-heading-inline' );
				var button = document.createElement( 'a' );

				button.href = <?php echo wp_json_encode( $url ); ?>;
				button.className = 'page-title-action static-site-importer-import-html-action';
				button.textContent = <?php echo wp_json_encode( $label ); ?>;

				if ( addThemeButton && addThemeButton.parentNode ) {
					addThemeButton.parentNode.insertBefore( button, addThemeButton.nextSibling );
					return;
				}

				if ( heading && heading.parentNode ) {
					heading.parentNode.insertBefore( button, heading.nextSibling );
				}
			} );
		</script>
		<?php
	}

	/**
	 * Render upload form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to import static sites.', 'static-site-importer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect-result read for admin notice
		$result = isset( $_GET['static_site_imported'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_imported'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect-result read for admin notice
		$error = isset( $_GET['static_site_error'] ) ? sanitize_text_field( wp_unslash( $_GET['static_site_error'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import Static Site', 'static-site-importer' ); ?></h1>

			<?php if ( '' !== $result ) : ?>
				<div class="notice notice-success"><p>
					<?php echo esc_html( sprintf( 'Imported block theme: %s', $result ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>"><?php echo esc_html__( 'View themes', 'static-site-importer' ); ?></a>
					<?php if ( current_user_can( 'edit_theme_options' ) ) : ?>
						| <a href="<?php echo esc_url( admin_url( 'site-editor.php' ) ); ?>"><?php echo esc_html__( 'Open Site Editor', 'static-site-importer' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<p><?php echo esc_html__( 'Paste HTML, fetch a public URL, upload a single HTML file, or upload a ZIP for a source-site export. ZIP imports use index.html as the shell/chrome entry and can include .md or .markdown content documents. MDX is reported as unsupported; build MDX to static HTML before importing.', 'static-site-importer' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'static_site_importer_import' ); ?>
				<input type="hidden" name="action" value="static_site_importer_import" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="static-site-pasted-html"><?php echo esc_html__( 'Paste HTML', 'static-site-importer' ); ?></label></th>
						<td>
							<textarea id="static-site-pasted-html" name="static_site_pasted_html" class="large-text code" rows="14" placeholder="<!doctype html>"></textarea>
							<p class="description"><?php echo esc_html__( 'Use this for one-page HTML copied from an AI builder or template source. Leave empty to fetch a URL, import an HTML file, or import a ZIP source site instead.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="static-site-url"><?php echo esc_html__( 'Import from URL', 'static-site-importer' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="static-site-url" name="static_site_url" placeholder="https://example.com/" />
							<p class="description"><?php echo esc_html__( 'Fetch one public http/https HTML URL server-side and store it as index.html. Pasted HTML takes precedence when provided; credentials and cookies are never sent.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="static-site-html"><?php echo esc_html__( 'Single HTML file', 'static-site-importer' ); ?></label></th>
						<td>
							<input type="file" id="static-site-html" name="static_site_html" accept=".html,.htm" />
							<p class="description"><?php echo esc_html__( 'Use this for a standalone .html or .htm file. Pasted HTML takes precedence when both are provided.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="static-site-zip"><?php echo esc_html__( 'Source-site ZIP', 'static-site-importer' ); ?></label></th>
						<td>
							<input type="file" id="static-site-zip" name="static_site_zip" accept=".zip" />
							<p class="description"><?php echo esc_html__( 'Use this for a site folder packaged as a ZIP with an index.html entry, sibling HTML pages, and optional nested .md/.markdown content documents. .mdx files are skipped with import-report diagnostics. Pasted HTML and single HTML uploads take precedence when provided.', 'static-site-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="theme-name"><?php echo esc_html__( 'Theme name', 'static-site-importer' ); ?></label></th>
						<td><input type="text" class="regular-text" id="theme-name" name="theme_name" placeholder="WordPress Is Dead" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="theme-slug"><?php echo esc_html__( 'Theme slug', 'static-site-importer' ); ?></label></th>
						<td><input type="text" class="regular-text" id="theme-slug" name="theme_slug" placeholder="wordpress-is-dead" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Options', 'static-site-importer' ); ?></th>
						<td><label><input type="checkbox" name="activate" value="1" checked /> <?php echo esc_html__( 'Activate imported theme', 'static-site-importer' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button( __( 'Import Static Site', 'static-site-importer' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle upload and import.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'switch_themes' ) ) {
			wp_die( esc_html__( 'You are not allowed to import static sites.', 'static-site-importer' ) );
		}

		check_admin_referer( 'static_site_importer_import' );

		$pasted_html = isset( $_POST['static_site_pasted_html'] ) ? trim( (string) wp_unslash( $_POST['static_site_pasted_html'] ) ) : '';
		$entry       = '' !== $pasted_html ? array(
			'html_path' => self::write_pasted_html( $pasted_html ),
			'metadata'  => array(),
		) : self::prepare_entry_file();
		if ( is_wp_error( $entry ) ) {
			self::redirect_error( $entry->get_error_message() );
		}

		$result = Static_Site_Importer_Theme_Generator::import_theme(
			$entry['html_path'],
			array(
				'name'            => isset( $_POST['theme_name'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_name'] ) ) : '',
				'slug'            => isset( $_POST['theme_slug'] ) ? sanitize_title( wp_unslash( $_POST['theme_slug'] ) ) : '',
				'activate'        => ! empty( $_POST['activate'] ),
				'overwrite'       => true,
				'source_metadata' => $entry['metadata'],
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}

		if ( ! empty( $result['quality']['fail_import'] ) ) {
			$failure_reasons = isset( $result['quality']['failure_reasons'] ) && is_array( $result['quality']['failure_reasons'] ) ? $result['quality']['failure_reasons'] : array();
			if ( in_array( 'woocommerce_missing', $failure_reasons, true ) ) {
				self::redirect_error( 'WooCommerce is required for this import. The source declared products but WooCommerce is not active. Install and activate WooCommerce, then retry the import.' );
			}
			self::redirect_error( 'Conversion quality gate failed. Theme files were written for inspection; review the import report and retry.' );
		}

		wp_safe_redirect( add_query_arg( 'static_site_imported', rawurlencode( $result['theme_name'] ), admin_url( 'admin.php?page=static-site-importer' ) ) );
		exit;
	}

	/**
	 * Write pasted HTML to a generated import work directory.
	 *
	 * @param string $html Raw pasted HTML.
	 * @return string HTML entry path.
	 */
	private static function write_pasted_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			self::redirect_error( 'Paste HTML content, enter a public URL, upload a single HTML file, or upload a ZIP containing index.html.' );
		}

		$work_dir  = self::create_work_dir();
		$html_path = trailingslashit( $work_dir ) . 'index.html';
		$result    = file_put_contents( $html_path, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a generated upload work file for the existing importer.

		if ( false === $result ) {
			self::redirect_error( 'Failed to write pasted HTML to the import work directory.' );
		}

		return $html_path;
	}

	/**
	 * Prepare an HTML entry file from URL, upload, or source-site ZIP intake.
	 *
	 * @return array{html_path:string,metadata:array<string,mixed>}|WP_Error
	 */
	private static function prepare_entry_file() {
		$work_dir = self::create_work_dir();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Import nonce verified in handle_import().
		$url = isset( $_POST['static_site_url'] ) ? trim( (string) wp_unslash( $_POST['static_site_url'] ) ) : '';
		if ( '' !== $url ) {
			return self::prepare_url_file( $url, $work_dir );
		}

		if ( self::has_uploaded_file( 'static_site_html' ) ) {
			return array(
				'html_path' => self::prepare_uploaded_html_file( $work_dir ),
				'metadata'  => array(),
			);
		}

		if ( self::has_uploaded_file( 'static_site_zip' ) ) {
			return array(
				'html_path' => self::prepare_uploaded_zip_file( $work_dir ),
				'metadata'  => array(),
			);
		}

		self::redirect_error( 'Paste HTML content, enter a public URL, upload a single HTML file, or upload a ZIP containing index.html.' );
	}

	/**
	 * Fetch a URL into an importer work directory.
	 *
	 * @param string $url      Source URL.
	 * @param string $work_dir Importer work directory.
	 * @return array{html_path:string,metadata:array<string,mixed>}|WP_Error
	 */
	private static function prepare_url_file( string $url, string $work_dir ) {
		return Static_Site_Importer_URL_Fetcher::fetch_to_work_dir( $url, $work_dir );
	}

	/**
	 * Store a direct HTML upload in an importer work directory.
	 *
	 * @param string $work_dir Importer work directory.
	 * @return string HTML entry path.
	 */
	private static function prepare_uploaded_html_file( string $work_dir ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$file = $_FILES['static_site_html'];
		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'html', 'htm' ), true ) ) {
			self::redirect_error( 'Upload an .html or .htm file.' );
		}

		if ( empty( $file['size'] ) || empty( $file['tmp_name'] ) || ! is_readable( (string) $file['tmp_name'] ) ) {
			self::redirect_error( 'The uploaded HTML file is empty or unreadable.' );
		}

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'html' => 'text/html',
					'htm'  => 'text/html',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			self::redirect_error( (string) $upload['error'] );
		}

		$target = trailingslashit( $work_dir ) . 'index.html';
		if ( ! copy( $upload['file'], $target ) ) {
			self::redirect_error( 'Could not store the uploaded HTML file.' );
		}

		return $target;
	}

	/**
	 * Extract an uploaded source-site ZIP and return its index.html path.
	 *
	 * @param string $work_dir Importer work directory.
	 * @return string HTML entry path.
	 */
	private static function prepare_uploaded_zip_file( string $work_dir ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$zip_file = $_FILES['static_site_zip'];
		$upload   = wp_handle_upload(
			$zip_file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'zip' => 'application/zip',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			self::redirect_error( (string) $upload['error'] );
		}

		$archive_error = self::validate_zip_archive( $upload['file'] );
		if ( is_wp_error( $archive_error ) ) {
			self::redirect_error( $archive_error->get_error_message() );
		}

		$result = unzip_file( $upload['file'], $work_dir );
		if ( is_wp_error( $result ) ) {
			self::redirect_error( $result->get_error_message() );
		}

		$html_path = self::find_index_html( $work_dir );
		if ( is_wp_error( $html_path ) ) {
			self::redirect_error( $html_path->get_error_message() );
		}

		if ( ! $html_path ) {
			self::redirect_error( 'The uploaded ZIP needs an index.html entry point. Add index.html at the archive root, or include exactly one nested index.html in the exported site folder.' );
		}

		return $html_path;
	}

	/**
	 * Create an importer work directory.
	 *
	 * @return string Directory path.
	 */
	private static function create_work_dir(): string {
		$work_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'static-site-importer/' . wp_generate_uuid4();
		wp_mkdir_p( $work_dir );

		return $work_dir;
	}

	/**
	 * Determine whether a named upload field has a file.
	 *
	 * @param string $field Upload field name.
	 * @return bool
	 */
	private static function has_uploaded_file( string $field ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		if ( ! isset( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		$error = $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload nonce verified in handle_import().
		return UPLOAD_ERR_NO_FILE !== $error && ! empty( $_FILES[ $field ]['tmp_name'] );
	}

	/**
	 * Validate archive member names before extraction when ZipArchive is available.
	 *
	 * WordPress' unzip_file() owns the actual extraction, but the importer can reject
	 * archive shapes that are outside the static-site contract before anything lands
	 * in the uploads work directory.
	 *
	 * @param string $zip_path Uploaded ZIP path.
	 * @return WP_Error|null
	 */
	private static function validate_zip_archive( string $zip_path ): ?WP_Error {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'static_site_importer_invalid_zip', 'The uploaded ZIP could not be opened. Try exporting the site again and upload a valid ZIP archive.' );
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( '' === $name || self::is_unsafe_archive_path( $name ) ) {
				$zip->close();
				return new WP_Error( 'static_site_importer_unsafe_zip_path', 'The uploaded ZIP contains an unsafe file path. Re-export the static site without absolute paths or ../ segments.' );
			}

			if ( self::is_server_side_file( $name ) ) {
				$zip->close();
				return new WP_Error( 'static_site_importer_server_side_file', 'The uploaded ZIP contains server-side code. Static Site Importer only accepts static HTML, Markdown content, CSS, JavaScript, images, fonts, and related assets.' );
			}
		}

		$zip->close();

		return null;
	}

	/**
	 * Find index.html in an extracted ZIP.
	 *
	 * Root-level index.html wins. If no root-level index exists, exactly one nested
	 * index.html is accepted. Multiple nested index files are ambiguous because the
	 * importer only imports sibling HTML files beside the selected entry point.
	 *
	 * @param string $dir Directory.
	 * @return string|WP_Error|null
	 */
	private static function find_index_html( string $dir ): string|WP_Error|null {
		$root = realpath( $dir );
		if ( false === $root ) {
			return new WP_Error( 'static_site_importer_missing_work_dir', 'The upload work directory could not be read. Please try the import again.' );
		}

		$root_candidates = array();
		$candidates      = array();
		$iterator        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && 'index.html' === strtolower( $file->getFilename() ) ) {
				$path = $file->getPathname();
				if ( ! self::path_is_under( $path, $root ) ) {
					return new WP_Error( 'static_site_importer_unsafe_index_path', 'The selected index.html resolved outside the upload work directory. Re-export the static site and try again.' );
				}

				if ( realpath( $file->getPath() ) === $root ) {
					$root_candidates[] = $path;
					continue;
				}

				$candidates[] = $path;
			}
		}

		sort( $root_candidates, SORT_STRING );
		sort( $candidates, SORT_STRING );

		if ( $root_candidates ) {
			return $root_candidates[0];
		}

		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}

		if ( count( $candidates ) > 1 ) {
			return new WP_Error( 'static_site_importer_ambiguous_index', 'The uploaded ZIP contains multiple nested index.html files and no root index.html. Add an index.html at the ZIP root, or upload a ZIP with a single exported site folder.' );
		}

		return null;
	}

	/**
	 * Determine whether an archive member name can escape the extraction root.
	 *
	 * @param string $path Archive member path.
	 * @return bool
	 */
	private static function is_unsafe_archive_path( string $path ): bool {
		$normalized = str_replace( '\\', '/', $path );

		return str_starts_with( $normalized, '/' )
			|| preg_match( '/^[A-Za-z]:\//', $normalized )
			|| str_contains( $normalized, "\0" )
			|| in_array( '..', explode( '/', $normalized ), true );
	}

	/**
	 * Determine whether an archive member is server-side executable code.
	 *
	 * @param string $path Archive member path.
	 * @return bool
	 */
	private static function is_server_side_file( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp' ), true );
	}

	/**
	 * Determine whether a path resolves inside a base directory.
	 *
	 * @param string $path Path to test.
	 * @param string $base Base directory.
	 * @return bool
	 */
	private static function path_is_under( string $path, string $base ): bool {
		$real_path = realpath( $path );
		$real_base = realpath( $base );

		if ( false === $real_path || false === $real_base ) {
			return false;
		}

		return 0 === strpos( trailingslashit( $real_path ), trailingslashit( $real_base ) );
	}

	/**
	 * Redirect to admin page with an error.
	 *
	 * @param string $message Error message.
	 * @return never
	 */
	private static function redirect_error( string $message ) {
		wp_safe_redirect( add_query_arg( 'static_site_error', rawurlencode( $message ), admin_url( 'admin.php?page=static-site-importer' ) ) );
		exit;
	}
}
