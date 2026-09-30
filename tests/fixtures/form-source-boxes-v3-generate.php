<?php
/**
 * Regenerate form-source-boxes-v3.json from a Blocks Engine producer that emits
 * generic/computed-layout-graph/v3 (Automattic/blocks-engine#2356).
 *
 * The fixture is neutral source HTML/CSS compiled by the producer's real
 * ArtifactCompiler; the committed JSON is exactly its runtime form entities, so
 * consumer tests run without the unreleased producer.
 *
 * STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH=/path/to/php-transformer \
 *   php tests/fixtures/form-source-boxes-v3-generate.php > tests/fixtures/form-source-boxes-v3.json
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI fixture generator outside a WordPress runtime.

$root = getenv( 'STATIC_SITE_IMPORTER_BLOCKS_ENGINE_PATH' ) ?: dirname( __DIR__, 2 ) . '/vendor/automattic/blocks-engine-php-transformer';
require_once rtrim( (string) $root, '/\\' ) . '/php-transformer.php';

$css = '.page{--copy:21px;font-family:Georgia;color:#123456;text-align:center}.wide-copy{font-size:var(--copy);line-height:1.5;margin:0 0 6px}'
	. '.intro-box{padding:3px 5px}.page .field-box{padding-bottom:24px}.submit-box{min-height:73px;padding-bottom:19px}.send{min-height:41px}'
	. '.note-box{padding:7px 0 13px}.note{font-size:11px;line-height:1.25;margin:4px 0 0}'
	. '@media (min-width:1200px){.page{--copy:27px}.wide-copy{letter-spacing:2px}.note-box{padding-bottom:17px}}';

$form = static function ( string $intro_classes ): string {
	return '<!doctype html><html><head><link rel="stylesheet" href="source.css"></head><body><main class="page"><form method="post">'
		. '<div class="intro-box"><p class="' . $intro_classes . '">A neutral introduction.</p></div>'
		. '<div class="field-box"><label for="email">Email</label><input id="email" type="email" name="email" required></div>'
		. '<textarea aria-label="Message" placeholder="Message" name="message"></textarea>'
		. '<div class="submit-box"><button class="send" type="submit">Send</button></div>'
		. '<div class="note-box"><p class="note">Please review your details.</p></div></form></main></body></html>';
};

$compile = static function ( string $html ) use ( $css ): array {
	$result = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile(
		array(
			'entrypoint' => 'index.html',
			'files'      => array(
				'index.html' => $html,
				'source.css' => $css,
			),
		)
	)->toArray();
	foreach ( $result['source_reports']['wordpress_site_plan']['runtime_declarations'] ?? array() as $declaration ) {
		if ( 'forms' === ( $declaration['type'] ?? null ) ) {
			return $declaration['payload']['entities'][0] ?? array();
		}
	}
	fwrite( STDERR, "producer emitted no runtime form entity\n" );
	exit( 1 );
};

$late    = implode( ' ', array_map( static fn( int $i ): string => 'hook-' . $i, range( 1, 20 ) ) ) . ' wide-copy';
$bounded = implode( ' ', array_map( static fn( int $i ): string => 'hook-' . $i, range( 1, 70 ) ) );
$entity  = $compile( $form( $late ) );
if ( 'generic/computed-layout-graph/v3' !== ( $entity['layout_graph']['schema'] ?? null ) ) {
	fwrite( STDERR, "producer does not emit generic/computed-layout-graph/v3\n" );
	exit( 1 );
}
echo json_encode(
	array(
		'source_css' => $css,
		'complete'   => $entity,
		'exhausted'  => $compile( $form( $bounded ) ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
