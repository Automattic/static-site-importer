<?php
/**
 * Importer block registration and render callback.
 *
 * @package StaticSiteImporterPlaygroundDemo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Static Site Importer block.
 *
 * @return void
 */
function static_site_importer_playground_demo_register_block(): void {
	register_block_type(
		STATIC_SITE_IMPORTER_PLAYGROUND_DEMO_PATH . 'blocks/importer',
		array(
			'render_callback' => 'static_site_importer_playground_demo_render_block',
		)
	);
}

/**
 * Render the importer block UI.
 *
 * @return string
 */
function static_site_importer_playground_demo_render_block(): string {
	$figma_available = Static_Site_Importer_Figma_Import::zstd_decoder_available();
	$intro           = $figma_available
		? __( 'Capture a public site, upload a static site, ZIP, folder, or Figma file, or paste HTML. Static Site Importer will compile it into a block theme.', 'static-site-importer' )
		: __( 'Capture a public site, upload a static site, ZIP, or folder, or paste HTML. Static Site Importer will compile it into a block theme.', 'static-site-importer' );

	ob_start();
	?>
	<div class="ssi-importer" data-static-site-importer data-static-site-importer-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/imports' ) ); ?>" data-static-site-importer-figma-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/import-figma-file' ) ); ?>" data-static-site-importer-home-url="<?php echo esc_url( home_url( '/' ) ); ?>" data-static-site-importer-figma-available="<?php echo $figma_available ? '1' : '0'; ?>" data-static-site-importer-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
		<section class="ssi-importer__panel" aria-labelledby="ssi-importer-title">
			<p class="ssi-importer__eyebrow"><?php esc_html_e( 'Static Site Importer', 'static-site-importer' ); ?></p>
			<h1 id="ssi-importer-title" class="ssi-importer__title"><?php esc_html_e( 'Bring a site into WordPress.', 'static-site-importer' ); ?></h1>
			<p class="ssi-importer__copy"><?php echo esc_html( $intro ); ?></p>

			<form class="ssi-importer__form" data-static-site-importer-form>
				<label class="ssi-importer__field">
					<span class="ssi-importer__label"><?php esc_html_e( 'Capture a public site', 'static-site-importer' ); ?></span>
					<input type="url" name="ssi_source_url" placeholder="https://example.com" autocomplete="url" data-static-site-importer-source-url>
				</label>

				<fieldset class="ssi-importer__field ssi-importer__dropzone" data-static-site-importer-dropzone>
					<legend class="ssi-importer__label"><?php esc_html_e( 'Drop website source', 'static-site-importer' ); ?></legend>
					<p class="ssi-importer__upload-copy"><?php esc_html_e( 'Drag a folder, ZIP, or static site files here.', 'static-site-importer' ); ?></p>
				</fieldset>

				<fieldset class="ssi-importer__field ssi-importer__upload-controls">
					<legend class="ssi-importer__label"><?php esc_html_e( 'Choose website source', 'static-site-importer' ); ?></legend>
					<div class="ssi-importer__upload-row" role="group" aria-label="<?php echo esc_attr( __( 'Upload source type', 'static-site-importer' ) ); ?>">
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-files><?php esc_html_e( 'File(s)', 'static-site-importer' ); ?></button>
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-folder><?php esc_html_e( 'Folder', 'static-site-importer' ); ?></button>
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-figma<?php echo $figma_available ? '' : ' disabled aria-disabled="true"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attributes. ?>><?php esc_html_e( 'Figma', 'static-site-importer' ); ?></button>
						<input type="file" name="ssi_static_upload[]" accept=".zip,application/zip,.html,.htm,text/html,text/css,text/javascript,application/javascript,application/json,application/xml,text/xml,image/*,font/*" multiple hidden data-static-site-importer-source-files>
						<input type="file" name="ssi_static_directory[]" multiple webkitdirectory hidden data-static-site-importer-source-directory>
						<input type="file" name="ssi_figma_file" accept=".fig" hidden data-static-site-importer-source-figma-file<?php echo $figma_available ? '' : ' disabled'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute. ?>>
					</div>
					<?php if ( ! $figma_available ) : ?>
						<p class="ssi-importer__capability-notice" data-static-site-importer-figma-unavailable><?php esc_html_e( 'Figma import requires zstd support, which is unavailable in this runtime. Other source types remain available.', 'static-site-importer' ); ?></p>
					<?php endif; ?>
				</fieldset>

				<details class="ssi-importer__field">
					<summary class="ssi-importer__label"><?php esc_html_e( 'Paste HTML', 'static-site-importer' ); ?></summary>
					<textarea name="ssi_html" rows="6" data-static-site-importer-source-html></textarea>
				</details>

				<button type="button" class="ssi-importer__submit" data-static-site-importer-submit><?php esc_html_e( 'Generate WordPress Website', 'static-site-importer' ); ?></button>
			</form>
		</section>

		<section class="ssi-importer__report" aria-live="polite" hidden data-static-site-importer-status>
			<p hidden data-static-site-importer-progress></p>
			<textarea rows="10" readonly hidden data-static-site-importer-report></textarea>
		</section>
	</div>
	<?php

	return (string) ob_get_clean();
}
