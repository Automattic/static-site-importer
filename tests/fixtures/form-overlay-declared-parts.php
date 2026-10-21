<?php
/**
 * The declared form overlay parts, enumerated from the builder.
 *
 * Shared by `tests/smoke-form-overlay-editor-parts.php` (every declared part
 * has exactly one editor realisation) and
 * `tests/fixtures/form-role-box-parity-fixture.php` (every non-dropped part has
 * a front-end/editor box pair). Both enumerate the builder's branches the same
 * way: every control type crossed with every presentation role set and label
 * shape, and every layout-target kind in both form topologies.
 *
 * Requires `form-seeder-cli-stubs.php` to be loaded first.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI test helper shared by smokes and fixtures.

/**
 * Every presentation destination the descriptor builder emits for each control
 * type, presentation role set, and label shape.
 *
 * @param string   $scope   Provider form scope class.
 * @param int|null $index   Fixed control index, or null to vary it.
 * @return array<int,array<string,mixed>>
 */
function ssi_editor_parts_descriptor_destinations( string $scope, ?int $index = null ): array {
	$role_names   = array( 'control', 'label', 'required_marker', 'control_container', 'sibling_stacked_margin' );
	$role_sets    = 1 << count( $role_names );
	$destinations = array();
	$counter      = 0;
	foreach ( array( 'text', 'email', 'textarea', 'select', 'checkbox', 'submit', 'phone', 'tel' ) as $control_type ) {
		for ( $mask = 1; $mask < $role_sets; ++$mask ) {
			$roles = array();
			foreach ( $role_names as $bit => $role_name ) {
				if ( 0 !== ( $mask & ( 1 << $bit ) ) ) {
					$roles[ $role_name ] = true;
				}
			}
			foreach ( array( false, true ) as $has_caption ) {
				foreach ( array( false, true ) as $choice_box ) {
					foreach ( array( false, true ) as $wrapping_label ) {
						// A sibling label may also carry its source row's gap as a margin.
						foreach ( $wrapping_label ? array( array() ) : array( array(), array( 'margin-block-end' ) ) as $row_carried_label_margins ) {
							$descriptor = Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor( $scope, $index ?? $counter % 128, $control_type, $roles, $has_caption, $choice_box, $wrapping_label, $row_carried_label_margins );
							// A submit standing for its source block row moves its box
							// facts between destinations; both shapes reach the editor.
							$shapes = 'submit' === $control_type ? array( $descriptor, Static_Site_Importer_Form_Layout_Projection::with_submit_row_button_box( $descriptor ) ) : array( $descriptor );
							foreach ( $shapes as $shape ) {
								foreach ( $shape['destinations'] as $destination ) {
									$destinations[] = $destination;
								}
							}
						}
						++$counter;
					}
				}
			}
		}
	}
	return $destinations;
}

/**
 * Front-end target maps covering every layout-target kind in both form
 * topologies, plus the phone country-trigger destination.
 *
 * @param string              $scope Provider form scope class.
 * @param array<string,mixed> $graph Layout graph.
 * @return array<int,array<string,mixed>>
 */
function ssi_editor_parts_target_maps( string $scope, array $graph ): array {
	$target_maps = array();
	foreach ( array( false, true ) as $native ) {
		$target_maps[] = Static_Site_Importer_Form_Layout_Projection::provider_layout_target_map(
			array(
				'layout_graph'                => $graph,
				'provider_native_topology'    => $native,
				'provider_source_box_submits' => array( 1 ),
			),
			$scope,
			array(
				0 => Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor( $scope, 0, 'phone', array( 'control' => true ) ),
				1 => Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor(
					$scope,
					1,
					'select',
					array(
						'control' => true,
						'label'   => true,
					)
				),
			),
			array(
				'wrapper-0' => Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'wrapper-0' ) . '-wrap',
				'wrapper-1' => Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'wrapper-1' ),
			),
			array( 0 => 0 ),
			'ssi-node-0123456789ab-destination-country-trigger'
		);
	}
	return $target_maps;
}

/** The layout graph the target-map enumeration uses: one node per target kind. */
function ssi_editor_parts_graph(): array {
	return array(
		'nodes' => array(
			array( 'id' => 'form' ),
			array( 'id' => 'form-box' ),
			array( 'id' => 'field-list' ),
			array( 'id' => 'field-0' ),
			array( 'id' => 'control-0' ),
			array( 'id' => 'control-1' ),
			array( 'id' => 'wrapper-0' ),
			array( 'id' => 'wrapper-1' ),
		),
	);
}

/**
 * Every emitted target and destination of the enumeration.
 *
 * @param string $scope Provider form scope class.
 * @return array<int,array<string,mixed>>
 */
function ssi_editor_parts_entries( string $scope ): array {
	$entries = ssi_editor_parts_descriptor_destinations( $scope );
	foreach ( ssi_editor_parts_target_maps( $scope, ssi_editor_parts_graph() ) as $map ) {
		foreach ( $map['targets'] as $target ) {
			$entries[] = $target;
		}
		foreach ( $map['presentation_targets'] as $target ) {
			foreach ( $target['destinations'] as $destination ) {
				$entries[] = $destination;
			}
		}
	}
	return $entries;
}

/**
 * Every declared part with its editor realisation: the entry's own `editor`
 * declaration, or else its `editor_surface_table()` row.
 *
 * @param string $scope Provider form scope class.
 * @return array<string,array<string,mixed>> Realisation keyed by part id, in emission order.
 */
function ssi_editor_parts_declared( string $scope ): array {
	$declared = array();
	foreach ( ssi_editor_parts_entries( $scope ) as $entry ) {
		if ( is_string( $entry['part'] ?? null ) && ! isset( $declared[ $entry['part'] ] ) ) {
			$declared[ $entry['part'] ] = Static_Site_Importer_Form_Presentation_Descriptor::editor_surface_part( $entry );
		}
	}
	return $declared;
}
