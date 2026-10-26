<?php
/**
 * Working state shared by the phases of provider form mapping.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One form plan's normalized inputs and accumulators.
 *
 * Static_Site_Importer_Form_Seeder::plan_form() runs a fixed sequence of
 * phases; each reads and extends this state instead of sharing one function
 * scope. Values keep the shapes the phases produce.
 */
final class Static_Site_Importer_Form_Plan_State {
	// Normalized source form.
	/** @var array<string,mixed> */
	public array $form;
	/** @var array<int,mixed> */
	public array $controls;
	/** @var array<int,mixed> */
	public array $textareas;
	public string $selector;
	public string $source_path;

	// Control groupings and presentation sources.
	public string $scope;
	public array $presentation_roles;
	public array $control_presentations;
	public array $radio_groups;
	/** @var array<int,bool> */
	public array $suppressed_controls;
	/** @var array<int,int> Trigger control index => hidden native carrier index. */
	public array $choice_pairs;
	/** @var array<int,array{controls:array<int,int>,row:?string,selected:array<int,?bool>}> Substituted captured choice groups. */
	public array $choice_groups = array();
	public array $submit_presentation;
	public string $submit_text = 'Submit';

	// Control mapping results.
	public array $presentation_descriptors = array();
	/** @var array<int,array<string,mixed>> */
	public array $field_blocks = array();
	/** @var array<int,string> */
	public array $mapped_types             = array();
	public array $skipped                  = array();
	public array $unsupported_capabilities = array();
	public array $control_attribute_losses = array();

	// Readable presentation receipt.
	/** @var array<string,mixed> Presentation receipt consumed by the quality gate. */
	public array $layout = array();
}
