<?php
/**
 * Rich-text carrier weight normalization coverage.
 *
 * Run from the repository root:
 * php tests/smoke-richtext-weight-normalizer.php
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'STATIC_SITE_IMPORTER_VERSION' ) ) {
	define( 'STATIC_SITE_IMPORTER_VERSION', '1.31.8' );
}

require_once __DIR__ . '/../includes/class-static-site-importer-richtext-weight-normalizer.php';

$id = 0;
function smoke_assert_ssi_richtext( bool $condition, string $message, string $actual = '' ): string {
	global $id;
	++$id;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL {$id} {$message}\n" . $actual . "\n" );
		exit( 1 );
	}
	echo "assert-{$id}\n";
	return (string) $id;
}

/** The producer's evidence carrier: a disclosure line re-declared inside its own strong. */
$disclosure = '<p class="text-xs leading-relaxed text-slate-500"><strong><mark class="text-slate-400" style="border-color:hsl(var(--border));font-weight:bolder;color:rgb(148 163 184 / var(--tw-text-opacity, 1));background-color:transparent" role="none">Important Disclosure:</mark></strong> Housing Resolutions renders within a strong scope.</p>';
$expected   = '<p class="text-xs leading-relaxed text-slate-500"><strong><mark class="text-slate-400" style="border-color:hsl(var(--border));color:rgb(148 163 184 / var(--tw-text-opacity, 1));background-color:transparent" role="none">Important Disclosure:</mark></strong> Housing Resolutions renders within a strong scope.</p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $disclosure ) === $expected,
	'bolder carrier inside strong scope loses only the duplicated relative weight',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $disclosure )
);

$bold_tag = '<p><b>cover <span class="lead-in"><mark style="color:rebeccapurple;font-weight: bolder">bold</mark></span></b></p>';
$expected = '<p><b>cover <span class="lead-in"><mark style="color:rebeccapurple">bold</mark></span></b></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $bold_tag ) === $expected,
	'b scope with intervening markup still drops the duplicated relative weight with quote shape and spacing preserved',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $bold_tag )
);

$explicit = '<p><strong><mark style="font-weight:900;color:red">author weight</mark></strong></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $explicit ) === $explicit,
	'authored absolute weights are never rewritten',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $explicit )
);

$floating = '<p><span style="font-weight:bolder">relatively heavier text</span></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $floating ) === $floating,
	'carrier without a semantic ancestor keeps its declared relative weight',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $floating )
);

$flow_scoped = '<section><div><p><strong>lead</strong></p><strong>tail<mark style="font-weight:lighter;font-weight:bolder;color:navy">inner</mark></strong></div></section>';
$expected    = '<section><div><p><strong>lead</strong></p><strong>tail<mark style="font-weight:lighter;color:navy">inner</mark></strong></div></section>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $flow_scoped ) === $expected,
	'scope ends at its own closing tag and lighter survives',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $flow_scoped )
);

$opening = '<p><strong class="trail">unclosed carrier <mark style="font-weight:bolder;color:navy">stays</mark></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $opening ) === $opening,
	'an unclosed semantic scope is never rewritten',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $opening )
);

$quoted_markup = '<p><strong><mark style=\'font-weight:bolder;color:olive\'>single quoted</mark></strong></p>';
$expected      = '<p><strong><mark style=\'color:olive\'>single quoted</mark></strong></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $quoted_markup ) === $expected,
	'single-quoted style attributes survive with the duplicated weight removed',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $quoted_markup )
);

$twice = Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $disclosure ) );
smoke_assert_ssi_richtext(
	$twice === Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $disclosure ),
	'normalization is idempotent',
	$twice
);

$plain = '<p><strong>no carriers at all</strong></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $plain ) === $plain,
	'markup without the relative keyword passes through byte for byte',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $plain )
);

$sole = '<p><strong><mark style="font-weight:bolder">only declaration</mark></strong></p>';
$expected = '<p><strong><mark>only declaration</mark></strong></p>';
smoke_assert_ssi_richtext(
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $sole ) === $expected,
	'a carrier whose only declaration was the duplicate weight loses the empty attribute',
	Static_Site_Importer_Richtext_Weight_Normalizer::normalize_fragment( $sole )
);

$plan = array(
	'pages' => array(
		array(
			'source_path'           => 'website/home/index.html',
			'resolved_block_markup' => $disclosure,
		),
	),
	'template_parts'              => array(
		array(
			'source_path'           => 'wordpress-site-plan/shared/footer#footer',
			'resolved_block_markup' => '<footer>' . $sole . '</footer>',
		),
	),
	'templates'                   => array(),
	'menus'                       => array(
		array(
			'source_path'           => 'website/home/index.html',
			'resolved_block_markup' => $bold_tag,
		),
	),
	'runtime_declarations'        => array(),
	'runtime_entity_resolution'   => array(),
);

$normalized_plan = Static_Site_Importer_Richtext_Weight_Normalizer::normalize( $plan );
smoke_assert_ssi_richtext(
	strpos( $normalized_plan['pages'][0]['resolved_block_markup'], 'font-weight:bolder' ) === false,
	'plan normalization reweights page documents',
	$normalized_plan['pages'][0]['resolved_block_markup']
);
smoke_assert_ssi_richtext(
	strpos( $normalized_plan['template_parts'][0]['resolved_block_markup'], 'font-weight:bolder' ) === false && strpos( $normalized_plan['template_parts'][0]['resolved_block_markup'], '<mark>' ) !== false,
	'plan normalization reweights template part documents',
	$normalized_plan['template_parts'][0]['resolved_block_markup']
);
smoke_assert_ssi_richtext(
	strpos( $normalized_plan['menus'][0]['resolved_block_markup'], 'font-weight: bolder' ) === false,
	'plan normalization reweights navigation markup',
	$normalized_plan['menus'][0]['resolved_block_markup']
);

$plan['runtime_declarations'] = array(
	array(
		'kind'        => 'entity_collection',
		'type'        => 'external_metrics',
		'source_path' => 'website/home/index.html',
		'payload'     => array(
			'schema'   => 'generic/entity/v1',
			'entities' => array(
				array(
					'bindings' => array(
						array(
							'schema'              => 'generic/block-binding/v1',
							'search_block_markup' => '<!-- wp:paragraph --><p>alpha <strong><mark style="font-weight:bolder;color:navy">metric</mark></strong></p><!-- /wp:paragraph -->',
						),
					),
				),
			),
		),
	),
);
$plan['runtime_entity_resolution'] = array();
$normalized_plan                   = Static_Site_Importer_Richtext_Weight_Normalizer::normalize( $plan );
$search                            = $normalized_plan['runtime_declarations'][0]['payload']['entities'][0]['bindings'][0]['search_block_markup'];
smoke_assert_ssi_richtext(
	strpos( $search, 'font-weight:bolder' ) === false && strpos( $search, '<!-- wp:paragraph -->' ) === 0,
	'recorded generic binding anchors normalize with the same fragment transform',
	$search
);

$plan['runtime_declarations']      = array();
$plan['runtime_entity_resolution'] = array(
	array(
		'schema'       => 'blocks-engine/runtime-entity-resolution/v1',
		'entities'     => array(
			array(
				'bindings' => array(
					array(
						'search_block_markup' => '<!-- wp:group --><div>beta <b><mark style="font-weight:bolder">anchor</mark></b></div><!-- /wp:group -->',
					),
				),
				'label'    => 'metric',
			),
		),
		'reconciliation_identity' => 'e',
	),
);
$normalized_plan = Static_Site_Importer_Richtext_Weight_Normalizer::normalize( $plan );
$resolved_search = $normalized_plan['runtime_entity_resolution'][0]['entities'][0]['bindings'][0]['search_block_markup'];
smoke_assert_ssi_richtext(
	strpos( $resolved_search, 'font-weight:bolder' ) === false,
	'expanded entity resolution anchors normalize too',
	$resolved_search
);

$plan['runtime_declarations']      = array();
$plan['runtime_entity_resolution'] = array();
$schemaless                        = Static_Site_Importer_Richtext_Weight_Normalizer::normalize( $plan );
smoke_assert_ssi_richtext(
	$schemaless['pages'][0]['resolved_block_markup'] === $disclosure || strpos( $schemaless['pages'][0]['resolved_block_markup'], 'font-weight:bolder' ) === false,
	'previously normalized documents stay retired from the plan',
	$schemaless['pages'][0]['resolved_block_markup']
);

echo "smoke-richtext-weight-normalizer passed ({$id} assertions)\n";
