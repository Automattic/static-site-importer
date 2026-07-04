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
	$intro       = isset( $attributes['intro'] ) && '' !== trim( (string) $attributes['intro'] ) ? (string) $attributes['intro'] : __( 'Upload a static site, ZIP, folder, Figma file, or paste HTML. Static Site Importer will compile it into a block theme.', 'static-site-importer' );
	$provider    = isset( $attributes['provider'] ) ? sanitize_key( (string) $attributes['provider'] ) : '';
	$default_url = isset( $attributes['defaultUrl'] ) ? esc_url_raw( (string) $attributes['defaultUrl'] ) : '';
	$apply       = ! empty( $attributes['applyToCurrentSite'] );
	$playground  = ! empty( $attributes['openInPlayground'] );
	$button_text = $apply ? __( 'Import to this site', 'static-site-importer' ) : __( 'Generate WordPress Website', 'static-site-importer' );

	/**
	 * Filters the importer block wrapper CSS classes.
	 *
	 * Theming seam: a host theme or plugin can append its own class to the
	 * importer wrapper (for example to scope `--ssi-importer-*` custom-property
	 * overrides) without forking the block. Return the full space-separated
	 * class string; `ssi-importer` is always present so the token defaults and
	 * base styles apply.
	 *
	 * @param string              $classes    Space-separated wrapper class list.
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	$wrapper_classes = (string) apply_filters( 'static_site_importer_block_wrapper_classes', 'ssi-importer', $attributes );
	if ( '' === trim( $wrapper_classes ) || false === strpos( ' ' . $wrapper_classes . ' ', ' ssi-importer ' ) ) {
		$wrapper_classes = trim( 'ssi-importer ' . $wrapper_classes );
	}

	/**
	 * Filters extra HTML attributes rendered on the importer block wrapper.
	 *
	 * Theming seam: a host can attach additional data-/aria-/style attributes to
	 * the wrapper (for example to project its own design tokens onto the block's
	 * `--ssi-importer-*` custom properties via an inline `style`) without forking
	 * the block. Provide an attribute-name => value map; both are escaped before
	 * output. Core importer hooks (`data-static-site-importer*`) always render and
	 * cannot be overridden here.
	 *
	 * @param array<string,string> $attrs      Extra wrapper attributes (name => value).
	 * @param array<string,mixed>  $attributes Block attributes.
	 */
	/** @var mixed $wrapper_attrs */
	$wrapper_attrs = apply_filters( 'static_site_importer_block_wrapper_attributes', array(), $attributes );
	$extra_attr    = '';
	if ( is_array( $wrapper_attrs ) ) {
		$reserved = array( 'class', 'data-static-site-importer' );
		foreach ( $wrapper_attrs as $attr_name => $attr_value ) {
			$attr_name = strtolower( preg_replace( '/[^a-z0-9_:-]/i', '', (string) $attr_name ) );
			if ( '' === $attr_name || in_array( $attr_name, $reserved, true ) || 0 === strpos( $attr_name, 'data-static-site-importer' ) ) {
				continue;
			}
			$extra_attr .= ' ' . $attr_name . '="' . esc_attr( (string) $attr_value ) . '"';
		}
	}

	ob_start();
	?>
	<div class="<?php echo esc_attr( $wrapper_classes ); ?>"<?php echo $extra_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Name sanitized, value escaped with esc_attr() above. ?> data-static-site-importer data-static-site-importer-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/imports' ) ); ?>" data-static-site-importer-figma-rest-url="<?php echo esc_url( rest_url( 'static-site-importer/v1/import-figma-file' ) ); ?>" data-static-site-importer-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-static-site-importer-provider="<?php echo esc_attr( $provider ); ?>" data-static-site-importer-apply-to-current-site="<?php echo $apply ? '1' : '0'; ?>" data-static-site-importer-open-in-playground="<?php echo $playground ? '1' : '0'; ?>">
		<section class="ssi-importer__panel" aria-labelledby="ssi-importer-title">
			<p class="ssi-importer__eyebrow"><?php esc_html_e( 'Static Site Importer', 'static-site-importer' ); ?></p>
			<h1 id="ssi-importer-title" class="ssi-importer__title"><?php echo esc_html( $title ); ?></h1>
			<p class="ssi-importer__copy"><?php echo esc_html( $intro ); ?></p>

			<form class="ssi-importer__form" data-static-site-importer-form data-static-site-importer-default-url="<?php echo esc_attr( $default_url ); ?>">
				<fieldset class="ssi-importer__field ssi-importer__dropzone" data-static-site-importer-dropzone>
					<legend class="ssi-importer__label"><?php esc_html_e( 'Drop website source', 'static-site-importer' ); ?></legend>
					<p class="ssi-importer__upload-copy"><?php esc_html_e( 'Drag a folder, ZIP, or static site files here.', 'static-site-importer' ); ?></p>
				</fieldset>

				<fieldset class="ssi-importer__field ssi-importer__upload-controls">
					<legend class="ssi-importer__label"><?php esc_html_e( 'Choose website source', 'static-site-importer' ); ?></legend>
					<div class="ssi-importer__upload-row" role="group" aria-label="<?php echo esc_attr( __( 'Upload source type', 'static-site-importer' ) ); ?>">
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-files><?php esc_html_e( 'File(s)', 'static-site-importer' ); ?></button>
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-folder><?php esc_html_e( 'Folder', 'static-site-importer' ); ?></button>
						<button type="button" class="ssi-importer__upload-button" data-static-site-importer-upload-figma><?php esc_html_e( 'Figma', 'static-site-importer' ); ?></button>
						<input type="file" name="ssi_static_upload[]" accept=".zip,application/zip,.html,.htm,text/html,text/css,text/javascript,application/javascript,application/json,application/xml,text/xml,image/*,font/*" multiple hidden data-static-site-importer-source-files>
						<input type="file" name="ssi_static_directory[]" multiple webkitdirectory hidden data-static-site-importer-source-directory>
						<input type="file" name="ssi_figma_file" accept=".fig" hidden data-static-site-importer-source-figma-file>
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
			<p hidden data-static-site-importer-progress></p>
			<textarea rows="10" readonly hidden data-static-site-importer-report></textarea>
		</section>
	</div>
	<?php

	return (string) ob_get_clean();
}
