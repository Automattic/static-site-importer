<?php
/**
 * Validation-result counts and the quality report keys they mirror.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One shared map for producing and cross-checking validation counts.
 */
final class Static_Site_Importer_Quality_Count_Keys {
	/**
	 * Validation-result count name => quality report key, in published order.
	 *
	 * Producers and every consumer that cross-checks validation counts against
	 * quality counts share this one map.
	 */
	public const MAP = array(
		'diagnostics'                        => 'diagnostic_count',
		'fallback_blocks'                    => 'fallback_count',
		'unsupported_fallbacks'              => 'unsupported_fallback_count',
		'accepted_preserved_runtime_islands' => 'accepted_preserved_runtime_island_count',
		'content_loss'                       => 'content_loss_count',
		'empty_conversions'                  => 'empty_conversion_count',
		'core_html_blocks'                   => 'core_html_block_count',
		'freeform_blocks'                    => 'freeform_block_count',
		'invalid_blocks'                     => 'invalid_block_count',
		'invalid_block_documents'            => 'invalid_block_document_count',
		'images_missing_source'              => 'image_missing_source_count',
		'unsafe_svgs'                        => 'unsafe_svg_count',
		'svg_materialization_failures'       => 'svg_materialization_failure_count',
		'svg_sprite_reference_failures'      => 'svg_sprite_reference_failure_count',
		'commerce_dependency_failures'       => 'commerce_dependency_failures',
		'interaction_candidates'             => 'interaction_candidate_count',
		'runtime_dependency_parity'          => 'runtime_dependency_parity_issue_count',
		'semantic_parity_failures'           => 'semantic_parity_failure_count',
		'unsafe_layout_constraints'          => 'unsafe_layout_constraint_count',
	);
}
