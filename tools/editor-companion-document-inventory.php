<?php
/**
 * Inventory the persisted page and generated theme block documents in a
 * materialized runtime. Parser round trips remain diagnostic only; this is a
 * runtime registration and fallback gate before browser acceptance runs.
 */

$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
if ( $post_id <= 0 ) {
	throw new InvalidArgumentException( 'A page post ID is required.' );
}

$post = get_post( $post_id );
if ( ! $post instanceof WP_Post ) {
	throw new RuntimeException( 'Imported page was not found.' );
}

$documents = array( array( 'kind' => 'page', 'path' => 'post:' . $post_id, 'markup' => $post->post_content ) );
$theme     = wp_get_theme();
foreach ( array( 'parts/*.html', 'templates/*.html', 'patterns/*.php' ) as $pattern ) {
	foreach ( glob( $theme->get_stylesheet_directory() . '/' . $pattern ) ?: array() as $file ) {
		$markup = (string) file_get_contents( $file );
		if ( str_ends_with( $file, '.php' ) && false !== strpos( $markup, '?>' ) ) {
			$markup = substr( $markup, strpos( $markup, '?>' ) + 2 );
		}
		$documents[] = array( 'kind' => 'theme', 'path' => substr( $file, strlen( $theme->get_stylesheet_directory() ) + 1 ), 'markup' => $markup );
	}
}

$registry = WP_Block_Type_Registry::get_instance();
$inventory = array();
foreach ( $documents as $document ) {
	$blocks = parse_blocks( $document['markup'] );
	$names  = array();
	$walk   = static function ( array $items ) use ( &$walk, &$names ): void {
		foreach ( $items as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = $block['blockName'];
			}
			$walk( $block['innerBlocks'] ?? array() );
		}
	};
	$walk( $blocks );
	$unknown = array_values( array_filter( array_unique( $names ), static fn( string $name ): bool => ! $registry->is_registered( $name ) ) );
	if ( in_array( 'core/html', $names, true ) || $unknown ) {
		throw new RuntimeException( wp_json_encode( array( 'path' => $document['path'], 'core_html' => in_array( 'core/html', $names, true ), 'unregistered' => $unknown ) ) );
	}
	$inventory[] = array( 'kind' => $document['kind'], 'path' => $document['path'], 'markup' => $document['markup'], 'blocks' => $names );
}

if ( count( $inventory ) < 2 ) {
	throw new RuntimeException( 'Generated theme block documents were not found.' );
}
echo wp_json_encode( array( 'schema' => 'static-site-importer/editor-document-inventory/v1', 'documents' => $inventory ) ) . PHP_EOL;
