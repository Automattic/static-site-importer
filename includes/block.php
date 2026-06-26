<?php
/**
 * Importer block registration and render callback.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Static Site Importer block.
 *
 * @return void
 */
function static_site_importer_register_block(): void {
	register_block_type(
		STATIC_SITE_IMPORTER_PATH . 'blocks/importer',
		array(
			'render_callback' => 'static_site_importer_render_block',
		)
	);
}

/**
 * Render the importer block UI.
 *
 * @param array<string,mixed> $attributes Block attributes.
 * @return string
 */
function static_site_importer_render_block( array $attributes = array() ): string {
	$title       = isset( $attributes['title'] ) && '' !== trim( (string) $attributes['title'] ) ? (string) $attributes['title'] : __( 'Bring a site into WordPress.', 'static-site-importer' );
	$intro       = isset( $attributes['intro'] ) && '' !== trim( (string) $attributes['intro'] ) ? (string) $attributes['intro'] : __( 'Upload a static site, ZIP, or Figma export, or paste HTML. Static Site Importer will compile it into a block theme.', 'static-site-importer' );
	$provider    = isset( $attributes['provider'] ) ? sanitize_key( (string) $attributes['provider'] ) : '';
	$default_url = isset( $attributes['defaultUrl'] ) ? esc_url_raw( (string) $attributes['defaultUrl'] ) : '';
	$apply       = ! empty( $attributes['applyToCurrentSite'] );
	$playground  = ! empty( $attributes['openInPlayground'] );
	$button_text = $apply ? __( 'Import to this site', 'static-site-importer' ) : __( 'Generate WordPress Website', 'static-site-importer' );

	ob_start();
	?>
	<div class="ssi-importer" data-static-site-importer data-static-site-importer-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/imports' ) ); ?>" data-static-site-importer-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-static-site-importer-provider="<?php echo esc_attr( $provider ); ?>" data-static-site-importer-apply-to-current-site="<?php echo $apply ? '1' : '0'; ?>" data-static-site-importer-open-in-playground="<?php echo $playground ? '1' : '0'; ?>">
		<section class="ssi-importer__panel" aria-labelledby="ssi-importer-title">
			<p class="ssi-importer__eyebrow"><?php esc_html_e( 'Static Site Importer', 'static-site-importer' ); ?></p>
			<h1 id="ssi-importer-title" class="ssi-importer__title"><?php echo esc_html( $title ); ?></h1>
			<p class="ssi-importer__copy"><?php echo esc_html( $intro ); ?></p>

			<form class="ssi-importer__form" data-static-site-importer-form data-static-site-importer-default-url="<?php echo esc_attr( $default_url ); ?>">
				<fieldset class="ssi-importer__field ssi-importer__dropzone" data-static-site-importer-dropzone>
					<legend class="ssi-importer__label"><?php esc_html_e( 'Drop website source', 'static-site-importer' ); ?></legend>
					<p class="ssi-importer__upload-copy"><?php esc_html_e( 'Drag a folder, ZIP, Figma export, or static site files here.', 'static-site-importer' ); ?></p>
				</fieldset>

				<fieldset class="ssi-importer__field ssi-importer__upload-controls">
					<legend class="ssi-importer__label"><?php esc_html_e( 'Choose website source', 'static-site-importer' ); ?></legend>
					<div class="ssi-importer__upload-row">
						<select class="ssi-importer__source-select" aria-label="<?php echo esc_attr( __( 'Source type', 'static-site-importer' ) ); ?>" data-static-site-importer-source-type>
							<option value="files"><?php esc_html_e( 'Files, ZIP, or FIG', 'static-site-importer' ); ?></option>
							<option value="folder"><?php esc_html_e( 'Folder', 'static-site-importer' ); ?></option>
						</select>
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-trigger><?php esc_html_e( 'Upload source', 'static-site-importer' ); ?></button>
						<input type="file" name="ssi_static_upload[]" accept=".fig,.zip,application/zip,.html,.htm,text/html,text/css,text/javascript,application/javascript,application/json,application/xml,text/xml,image/*,font/*" multiple hidden data-static-site-importer-source-files>
						<input type="file" name="ssi_static_directory[]" multiple webkitdirectory hidden data-static-site-importer-source-directory>
					</div>
				</fieldset>

				<details class="ssi-importer__field">
					<summary class="ssi-importer__label"><?php esc_html_e( 'Paste HTML', 'static-site-importer' ); ?></summary>
					<textarea name="ssi_html" rows="6" data-static-site-importer-source-html></textarea>
				</details>

				<button type="button" class="ssi-importer__submit" data-static-site-importer-submit><?php echo esc_html( $button_text ); ?></button>
			</form>
		</section>

		<section class="ssi-importer__report" aria-live="polite" hidden data-static-site-importer-status>
			<p hidden data-static-site-importer-preview-link-wrap><a href="#" target="_blank" rel="noopener noreferrer" data-static-site-importer-preview-link><?php esc_html_e( 'Open WordPress preview', 'static-site-importer' ); ?></a></p>
			<textarea rows="10" readonly hidden data-static-site-importer-report></textarea>
		</section>
	</div>
	<?php

	return (string) ob_get_clean();
}
