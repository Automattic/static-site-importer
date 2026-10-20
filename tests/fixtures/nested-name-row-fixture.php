<?php
/** Neutral nested floated name-row regression (#2216). */
// phpcs:ignoreFile -- Standalone CLI fixture with WordPress/Jetpack stubs.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;
	$source = file_get_contents( __DIR__ . '/nested-name-row.html' );
	preg_match( '/<style>(.*?)<\/style>/s', $source, $styles );
	$source_css = $styles[1];
	$form_html  = substr( $source, strpos( $source, '<form' ) );
	$html       = '<style>' . $source_css . '</style>' . $form_html;
	$compiled   = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated  = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row        = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$markup     = (string) ( $row['block_markup'] ?? '' );
	$fields     = array();
	if ( preg_match_all( '/<!-- wp:jetpack\/field-(text|email|textarea) (\{[^\n]*?\}) -->\s*<div><!-- wp:jetpack\/label (\{[^\n]*?\}) \/?-->/', $markup, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$attrs   = json_decode( $match[2], true );
			$label   = json_decode( $match[3], true );
			$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
			$classes = false === $classes ? array() : array_map( static fn ( string $class_name ): string => $class_name . '-wrap', array_filter( $classes ) );
			$fields[] = array(
				'type'         => $match[1],
				'label'        => (string) ( $label['label'] ?? '' ),
				'labelClasses' => (string) ( $label['className'] ?? '' ),
				'classes'      => implode( ' ', $classes ),
			);
		}
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$form_attrs = json_decode( $form[1], true );
		$form_class = (string) ( $form_attrs['className'] ?? '' );
	}
	$field_html = '';
	foreach ( $fields as $field ) {
		$field_html .= Static_Site_Importer_Form_Seeder::project_provider_wrapper_classes(
			'<div class="grunion-field-' . $field['type'] . '-wrap ' . $field['classes'] . '"><label>' . $field['label'] . '</label><input></div>'
		);
	}
	echo (string) wp_json_encode(
		array(
			'row'         => $row,
			'fallback'    => $compiled['fallbacks'][0],
			'markup'      => $markup,
			'status'      => (string) ( $row['status'] ?? '' ),
			'css'         => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'sourceCss'   => $source_css,
			'sourceHtml'  => $form_html,
			'className'   => $form_class,
			'fields'      => $fields,
			'fieldHtml'   => $field_html,
			'overlayValid' => null !== Static_Site_Importer_Provider_Layout_Overlay::validate_overlay( $row['provider_layout_overlay_css'] ?? null ),
			'strategies'  => array_column( $row['computed_layout_receipt']['operations'] ?? array(), 'strategy' ),
		)
	);
}
