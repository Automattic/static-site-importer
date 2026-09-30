<?php
/**
 * Emit the column-flex grid-span form the rendered layout regression measures.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture: stubs Jetpack and WordPress symbols across namespaces outside a WordPress runtime.

namespace {
	require_once __DIR__ . '/form-seeder-cli-stubs.php';
	$transformer_root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
	$transformer      = rtrim( (string) $transformer_root, '/\\' ) . '/php-transformer.php';
	if ( ! is_readable( $transformer ) ) {
		fwrite( STDERR, "blocks engine transformer is unavailable\n" );
		exit( 1 );
	}
	require_once $transformer;
	$source_css = '.stack{display:flex;flex-direction:column;gap:24px;width:100%;--context-family:Georgia;--context-line-height:1.75}.stack label{margin-bottom:8px}.send-button{min-height:56px}.stack .intro-note,.stack .disclaimer-note{margin-bottom:24px;padding:8px 0 16px}form.stack .intro-note,form.stack .disclaimer-note{font-family:var(--context-family);line-height:var(--context-line-height)}.stack .responsive-intro,.stack .responsive-disclaimer{font-size:16px}@media(min-width:1536px){.send-button{min-height:68px}.stack .responsive-intro,.stack .responsive-disclaimer{font-size:24px;padding-top:12px}}';
	$html = '<style>' . $source_css . '</style><div class="type-shell"><form class="stack">'
		. '<h2 class="intro-note first second third fourth fifth sixth seventh eighth responsive-intro ninth tenth">Contact us</h2>'
		. '<div style="display:grid;width:100%;grid-template-columns:repeat(12, 1fr);column-gap:24px">'
		. '<div style="grid-column:1 / span 6"><label>First name</label><input type="text" name="first"></div>'
		. '<p class="interleaved-note">Information between fields.</p>'
		. '<div style="grid-column:7 / span 6"><label>Last name</label><input type="text" name="last"></div>'
		. '<div style="grid-column:1 / span 12"><label>Message</label><textarea name="message"></textarea></div>'
		. '<div style="grid-column:1 / span 3;min-height:64px"><button class="send-button" type="submit" style="margin-left:auto">Send</button></div>'
		. '</div><p class="disclaimer-note first second third fourth fifth sixth seventh eighth responsive-disclaimer ninth tenth">We will reply soon.</p></form></div>';
	$compiled  = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array( 'entrypoint' => 'contact.html', 'files' => array( 'contact.html' => $html ) ) )->toArray();
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $compiled['fallbacks'][0] ?? array() ) ) );
	$row       = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ?? array() ) )['forms'][0] ?? array();
	$style_writes = Static_Site_Importer_Stylesheet_Materializer::stylesheet_writes( '/tmp/ssi-form-context-matrix', 'Form Context Matrix', $source_css, array(), array(), array( $row['provider_layout_overlay_css'] ?? array() ) );
	$markup = (string) ( $row['block_markup'] ?? '' );
	$comment_json = static function ( string $markup, string $name ): array {
		$found = array();
		$offset = 0;
		$needle = '<!-- wp:' . $name . ' ';
		while ( false !== ( $start = strpos( $markup, $needle, $offset ) ) ) {
			$json_start = $start + strlen( $needle );
			if ( '{' !== ( $markup[ $json_start ] ?? '' ) ) {
				$offset = $json_start;
				continue;
			}
			$depth = 0;
			$end   = strlen( $markup );
			for ( $index = $json_start; $index < $end; ++$index ) {
				$depth += '{' === $markup[ $index ] ? 1 : ( '}' === $markup[ $index ] ? -1 : 0 );
				if ( 0 === $depth ) {
					$decoded = json_decode( substr( $markup, $json_start, $index - $json_start + 1 ), true );
					if ( is_array( $decoded ) ) {
						$found[] = $decoded;
					}
					$offset = $index + 1;
					continue 2;
				}
			}
			break;
		}
		return $found;
	};
	$fields = array();
	$labels = $comment_json( $markup, 'jetpack/label' );
	foreach ( array_merge( $comment_json( $markup, 'jetpack/field-text' ), $comment_json( $markup, 'jetpack/field-textarea' ) ) as $index => $attrs ) {
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) );
		$classes = false === $classes ? array() : array_map( static fn ( string $class_name ): string => $class_name . '-wrap', array_filter( $classes ) );
		$fields[] = array(
			'label'        => (string) ( $labels[ $index ]['label'] ?? '' ),
			'labelClasses' => (string) ( $labels[ $index ]['className'] ?? '' ),
			'classes'      => implode( ' ', $classes ),
		);
	}
	$submit = array( 'classes' => '', 'text' => 'Send' );
	$buttons = array_merge( $comment_json( $markup, 'button' ), $comment_json( $markup, 'core/button' ) );
	if ( isset( $buttons[0] ) ) {
		$submit['classes'] = (string) ( $buttons[0]['className'] ?? '' );
	}
	$submit_class = $submit['classes'];
	$runtime_html = Static_Site_Importer_Form_Seeder::project_provider_submit_presentation(
		'<div class="wp-block-button ' . htmlspecialchars( $submit_class, ENT_QUOTES, 'UTF-8' ) . '"><button type="submit" class="wp-block-button__link">Send</button></div>',
		array( 'attrs' => array( 'className' => $submit_class ) )
	);
	$source_form = is_array( $compiled['fallbacks'][0]['form'] ?? null ) ? $compiled['fallbacks'][0]['form'] : array();
	$source_layout_graph = is_array( $compiled['fallbacks'][0]['layout_graph'] ?? null ) ? $compiled['fallbacks'][0]['layout_graph'] : array();
	$submit_parent_layout = current( array_filter( $source_layout_graph['nodes'] ?? array(), static fn( $node ): bool => is_array( $node ) && 'wrapper-4' === ( $node['id'] ?? null ) ) ) ?: array();
	$normalized_context = Static_Site_Importer_Form_Fallback_Contract::presentation_from_metadata( $compiled['fallbacks'][0] ?? array() );
	$context_html = '';
	if ( preg_match_all( '/<!-- wp:core\/(heading|paragraph)(?:\s+\{.*?\})? -->.*?<!-- \/wp:core\/\1 -->/s', $markup, $context_fragments ) ) {
		$context_html = implode( '', array_filter( $context_fragments[0], static fn( string $fragment ): bool => str_contains( $fragment, 'ssi-context-' ) ) );
	}
	$form_class = '';
	if ( preg_match( '/<!-- wp:jetpack\/contact-form (\{.*?\}) -->/', $markup, $form ) ) {
		$attrs      = json_decode( $form[1], true );
		$form_class = (string) ( $attrs['className'] ?? '' );
	}
	echo (string) wp_json_encode(
		array(
			'status'    => (string) ( $row['status'] ?? '' ),
			'css'       => (string) ( $row['provider_layout_overlay_css']['css'] ?? '' ),
			'sourceCss' => $source_css,
			'materializedStyleCss' => (string) ( $style_writes['/tmp/ssi-form-context-matrix/style.css'] ?? '' ),
			'materializedEditorCss' => (string) ( $style_writes['/tmp/ssi-form-context-matrix/assets/css/editor-style.css'] ?? '' ),
			'contextCss' => (string) ( $row['provider_layout_overlay_css']['context_css'] ?? '' ),
			'editorContextCss' => (string) ( $row['provider_layout_overlay_css']['editor_context_css'] ?? '' ),
			'editorCss' => (string) ( $row['provider_layout_overlay_css']['editor_css'] ?? '' ),
			'className' => $form_class,
			'fields'    => $fields,
			'submit'    => $submit,
			'runtimeHtml' => $runtime_html,
			'producerContext' => array_intersect_key( $source_form, array_flip( array( 'context_before', 'context_after', 'unrepresented_context' ) ) ),
			'producerLayoutGraph' => $source_layout_graph,
			'submitParentSourceMinHeight' => '64px',
			'submitParentProducerLayout' => $submit_parent_layout,
			'producerLayoutGraph' => $source_layout_graph,
			'normalizedContext' => array_intersect_key( $normalized_context, array_flip( array( 'context_before', 'context_after', 'unrepresented_context_count' ) ) ),
			'contextHtml' => $context_html,
		)
	);
}
