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
	$intro       = isset( $attributes['intro'] ) && '' !== trim( (string) $attributes['intro'] ) ? (string) $attributes['intro'] : __( 'Paste a URL, upload site files, or add HTML. Static Site Importer will compile it into a block theme.', 'static-site-importer' );
	$provider    = isset( $attributes['provider'] ) ? sanitize_key( (string) $attributes['provider'] ) : '';
	$default_url = isset( $attributes['defaultUrl'] ) ? esc_url_raw( (string) $attributes['defaultUrl'] ) : '';
	$apply       = ! empty( $attributes['applyToCurrentSite'] );
	$button_text = $apply ? __( 'Import to this site', 'static-site-importer' ) : __( 'Create preview', 'static-site-importer' );

	ob_start();
	?>
	<div class="ssi-importer" data-static-site-importer data-static-site-importer-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/imports' ) ); ?>" data-static-site-importer-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-static-site-importer-provider="<?php echo esc_attr( $provider ); ?>" data-static-site-importer-apply-to-current-site="<?php echo $apply ? '1' : '0'; ?>">
		<section class="ssi-importer__panel" aria-labelledby="ssi-importer-title">
			<p class="ssi-importer__eyebrow"><?php esc_html_e( 'Static Site Importer', 'static-site-importer' ); ?></p>
			<h1 id="ssi-importer-title" class="ssi-importer__title"><?php echo esc_html( $title ); ?></h1>
			<p class="ssi-importer__copy"><?php echo esc_html( $intro ); ?></p>

			<form class="ssi-importer__form" data-static-site-importer-form>
				<label class="ssi-importer__field">
					<span class="ssi-importer__label"><?php esc_html_e( 'Website URL', 'static-site-importer' ); ?></span>
					<input type="url" name="ssi_source_url" placeholder="https://example.com" autocomplete="url" value="<?php echo esc_attr( $default_url ); ?>" data-static-site-importer-source-url>
				</label>

				<label class="ssi-importer__field">
					<span class="ssi-importer__label"><?php esc_html_e( 'Site directory', 'static-site-importer' ); ?></span>
					<input type="file" name="ssi_static_template[]" multiple webkitdirectory data-static-site-importer-source-files>
				</label>

				<label class="ssi-importer__field">
					<span class="ssi-importer__label"><?php esc_html_e( 'ZIP archive', 'static-site-importer' ); ?></span>
					<input type="file" name="ssi_static_archive" accept=".zip,application/zip" data-static-site-importer-source-archive>
				</label>

				<label class="ssi-importer__field">
					<span class="ssi-importer__label"><?php esc_html_e( 'Raw HTML', 'static-site-importer' ); ?></span>
					<textarea name="ssi_html" rows="6" data-static-site-importer-source-html></textarea>
				</label>

				<button type="button" class="ssi-importer__submit" data-static-site-importer-submit><?php echo esc_html( $button_text ); ?></button>
			</form>
		</section>

		<section class="ssi-importer__report" aria-live="polite" hidden data-static-site-importer-status>
			<p class="ssi-importer__label"><?php esc_html_e( 'Import status', 'static-site-importer' ); ?></p>
			<p data-static-site-importer-progress></p>
			<p hidden data-static-site-importer-preview-link-wrap><a href="#" target="_blank" rel="noopener noreferrer" data-static-site-importer-preview-link><?php esc_html_e( 'Open WordPress preview', 'static-site-importer' ); ?></a></p>
			<textarea rows="10" readonly hidden data-static-site-importer-report></textarea>
		</section>
	</div>
	<?php

	return (string) ob_get_clean();
}
