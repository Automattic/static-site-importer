<?php
/**
 * Scorecard block traversal stays linear on large sites (#3287): a synthetic
 * tree measures identically to its small equivalent, scaled, within a bound
 * the former array_merge-per-block accumulation cannot meet.
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/../includes/class-static-site-importer-block-theme-scorecard.php';

function scorecard_linear_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

/** Build routes of nested groups whose blocks carry engine, opaque and themed class tokens. */
function scorecard_linear_routes( int $routes, int $sections ): array {
	$result = array();
	for ( $r = 0; $r < $routes; $r++ ) {
		$blocks = array();
		for ( $s = 0; $s < $sections; $s++ ) {
			$blocks[] = array(
				'blockName'   => 'core/group',
				'attrs'       => array( 'className' => 'be-section-1a2b3c4d theme-card  extra' ),
				'innerHTML'   => '',
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/paragraph',
						'attrs'       => array( 'className' => 'theme-text deadbeef12 plain' ),
						'innerHTML'   => '<p><a href="/">Link</a></p>',
						'innerBlocks' => array(),
					),
					array(
						'blockName'   => null,
						'attrs'       => array( 'className' => 'ignored' ),
						'innerHTML'   => "\n",
						'innerBlocks' => array(),
					),
				),
			);
		}
		$result[ $r ] = $blocks;
	}
	return $result;
}

$class_naming = new ReflectionMethod( Static_Site_Importer_Block_Theme_Scorecard::class, 'class_naming' );
$theme_json   = new ReflectionMethod( Static_Site_Importer_Block_Theme_Scorecard::class, 'theme_json' );
$class_naming->setAccessible( true );
$theme_json->setAccessible( true );

$small = $class_naming->invoke( null, 'theme', array(), array(), scorecard_linear_routes( 1, 1 ) )['measured'];
scorecard_linear_assert( 6 === $small['class_tokens'], 'small class token count' );
scorecard_linear_assert( 1 === $small['engine_class_tokens'], 'small engine token count' );
scorecard_linear_assert( 2 === $small['opaque_class_tokens'], 'small opaque token count' );

$routes = 12;
$per    = 2000;
$large  = scorecard_linear_routes( $routes, $per );
$scale  = $routes * $per;
$start  = microtime( true );
$naming = $class_naming->invoke( null, 'theme', array(), array(), $large )['measured'];
$json   = $theme_json->invoke( null, array(), array(), array(), $large );
$took   = microtime( true ) - $start;

foreach ( array( 'class_tokens', 'engine_class_tokens', 'opaque_class_tokens', 'theme_namespaced_tokens' ) as $key ) {
	scorecard_linear_assert( $small[ $key ] * $scale === $naming[ $key ], "large $key scales with the small equivalent" );
}
scorecard_linear_assert( 1 === $naming['distinct_engine_classes'], 'large distinct engine classes' );
scorecard_linear_assert( array( 'be-section' => $scale ) === $naming['engine_class_families'], 'large engine class families' );
scorecard_linear_assert( 'fail' === $json['status'] && array( 'link' ) === $json['measured']['unstyled_used'], 'large theme_json element usage' );
scorecard_linear_assert( $took < 5.0, sprintf( 'scorecard over %d blocks took %.2fs; traversal must stay linear', $scale * 2, $took ) );

echo sprintf( "PASS: scorecard measured %d blocks in %.2fs\n", $scale * 2, $took );
