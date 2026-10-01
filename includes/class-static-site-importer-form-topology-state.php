<?php
/**
 * Working state shared by the stages of Jetpack form topology projection.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One projection's inputs and accumulators.
 *
 * Static_Site_Importer_Form_Layout_Projection::topology_inner_blocks() runs a
 * fixed sequence of stages; each reads and extends this state instead of
 * sharing one function scope. Values keep the shapes the stages produce.
 */
final class Static_Site_Importer_Form_Topology_State {
	// Inputs.
	public mixed $form;
	/** @var array<int,array<string,mixed>> */
	public array $field_blocks;
	public mixed $controls;
	public mixed $suppressed_controls;

	// Source topology and layout indexes.
	public mixed $nodes                    = null;
	public mixed $children                 = array();
	public mixed $topology_nodes_by_id     = array();
	public mixed $control_parents          = array();
	public mixed $topology_parents         = array();
	public mixed $layout_by_node           = array();
	public mixed $layout_nodes_by_id       = array();
	public mixed $variants_by_node         = array();
	public mixed $mapped_controls          = array();
	public mixed $percentage_width_parents = array();
	public mixed $layout_css_properties    = array();
	public mixed $provider_controls        = array();
	public mixed $auxiliary_popup_controls = array();
	public mixed $phone_popup_targets      = array();

	// Shared predicates.
	public mixed $collect_controls = null;

	// Projection results.
	public mixed $losses                     = array();
	public mixed $operations                 = array();
	public mixed $represented_layout_nodes   = array();
	public mixed $represented_topology_nodes = array();
	/** @var array<string,array<int,string>> */
	public mixed $suppressed_layout_properties = array();
	public mixed $class_carried_variants       = array();
	public mixed $submit_block_rows            = array();
	public mixed $overlay_node_targets         = array();
	public mixed $responsive_variant_targets   = array();
	public mixed $native_visibility_targets    = array();
	public mixed $wrapper_hooks                = array();
	public mixed $provider_layout_targets      = array();
	public mixed $overlay_represented_nodes    = array();
	public mixed $form_classes                 = array();

	// Grid-span row state.
	public mixed $grid_span_active          = false;
	public mixed $grid_span_gap             = null;
	public mixed $grid_span_gap_variants    = array();
	public mixed $grid_span_submit_controls = array();
	public mixed $grid_span_submit_parents  = array();
	public mixed $grid_span_container       = 'form';

	public function __construct( array $form, array $field_blocks, array $controls, array $suppressed_controls ) {
		$this->form                = $form;
		$this->field_blocks        = $field_blocks;
		$this->controls            = $controls;
		$this->suppressed_controls = $suppressed_controls;
	}

	/**
	 * Mapped controls a topology branch contains, in branch order.
	 *
	 * @param array<string,mixed> $node Topology node.
	 * @return array<int,int>
	 */
	public function mapped_branch( array $node ): array {
		return array_values( array_filter( ( $this->collect_controls )( $node ), fn ( int $index ): bool => isset( $this->field_blocks[ $index ] ) ) );
	}
}
