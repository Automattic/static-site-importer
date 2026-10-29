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

	// Field-contract surfaces: every control decides, and nothing decides silently.
	/**
	 * Per-control contract decision keyed by control index.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $field_contract = array();
	/**
	 * Explicit owner-handoff items naming what the provider cannot represent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $handoff_items = array();
	/**
	 * Recovered recipient, or the explicit handoff when none was recovered.
	 *
	 * @var array<string,mixed>
	 */
	public array $recipient = array();

	// Readable presentation receipt.
	/** @var array<string,mixed> Presentation receipt consumed by the quality gate. */
	public array $layout = array();

	/**
	 * Record that a control materialized as the named provider block.
	 *
	 * @param int                      $index          Source control index.
	 * @param string|array<int,string> $provider_block One block name, or the
	 *                                                 list of provider blocks a
	 *                                                 substituted group materialized as.
	 */
	public function record_mapped( int $index, string|array $provider_block ): void {
		$this->field_contract[ $index ] = array(
			'index'          => $index,
			'decision'       => 'mapped',
			'source'         => $this->source_facts( $index ),
			'provider_block' => is_string( $provider_block ) ? $provider_block : array_values( array_filter( $provider_block ) ),
		);
	}

	/**
	 * Record that a control is the form's submit action.
	 *
	 * @param int $index Source control index.
	 */
	public function record_submit( int $index ): void {
		$this->field_contract[ $index ] = array(
			'index'          => $index,
			'decision'       => 'submit',
			'source'         => $this->source_facts( $index ),
			'provider_block' => 'core/button',
		);
	}

	/**
	 * Record that a control is represented by the provider without its own field.
	 *
	 * @param int    $index       Source control index.
	 * @param string $reason_code Why the provider represents it without a field.
	 */
	public function record_suppressed( int $index, string $reason_code ): void {
		$this->field_contract[ $index ] = array(
			'index'       => $index,
			'decision'    => 'suppressed',
			'source'      => $this->source_facts( $index ),
			'reason_code' => $reason_code,
		);
	}

	/**
	 * Record the owner handoff for a control the provider cannot represent.
	 *
	 * The item names the captured control and the capability that cannot be
	 * carried, so the gap is an explicit decision for the site's owner instead
	 * of a silent downgrade to a control the provider does have.
	 *
	 * @param int    $index       Source control index.
	 * @param string $capability  The capability that cannot be carried.
	 * @param string $reason_code Why the provider cannot carry it.
	 */
	public function record_handoff( int $index, string $capability, string $reason_code = 'provider_cannot_represent_control' ): void {
		$source                         = $this->source_facts( $index );
		$this->field_contract[ $index ] = array(
			'index'       => $index,
			'decision'    => 'handoff',
			'source'      => $source,
			'reason_code' => $reason_code,
			'capability'  => $capability,
		);
		$this->handoff_items[]          = array(
			'kind'          => 'control',
			'reason_code'   => $reason_code,
			'capability'    => $capability,
			'control_index' => $index,
			'tag'           => $source['tag'],
			'type'          => $source['type'],
			'name'          => $source['name'],
			'label'         => $source['label'],
			'message'       => self::handoff_message( $source, $capability ),
		);
	}

	/**
	 * The bounded source identity every contract decision names.
	 *
	 * @param int $index Source control index.
	 * @return array{tag:string,type:string,name:string,label:string}
	 */
	private function source_facts( int $index ): array {
		$control = $this->controls[ $index ] ?? null;
		$facts   = array(
			'tag'   => '',
			'type'  => '',
			'name'  => '',
			'label' => '',
		);
		if ( ! is_array( $control ) ) {
			return $facts;
		}
		foreach ( array( 'tag', 'type', 'name' ) as $key ) {
			$value = isset( $control[ $key ] ) && is_scalar( $control[ $key ] ) ? trim( (string) $control[ $key ] ) : '';
			if ( '' !== $value ) {
				$facts[ $key ] = substr( $value, 0, 200 );
			}
		}
		$facts['label'] = Static_Site_Importer_Form_Field_Markup::control_text( $control );
		return $facts;
	}

	/**
	 * The one-line owner instruction a handoff item carries.
	 *
	 * @param array{tag:string,type:string,name:string,label:string} $source     Bounded source identity.
	 * @param string                                                 $capability The capability that cannot be carried.
	 * @return string
	 */
	private static function handoff_message( array $source, string $capability ): string {
		$named = isset( $source['name'] ) && '' !== $source['name'] ? 'name "' . $source['name'] . '"' : ( '' !== $source['label'] ? 'label "' . $source['label'] . '"' : $source['type'] . ' control' );
		$kind  = '' !== $source['type'] ? $source['type'] : $source['tag'];
		if ( str_starts_with( $capability, 'control_type:' ) ) {
			return 'The source ' . $kind . ' control (' . $named . ') has no provider field for its type (' . substr( $capability, strlen( 'control_type:' ) ) . '); add an equivalent field by hand or accept the loss.';
		}
		$captions = array(
			'file_upload'        => 'a working file upload',
			'multiple_selection' => 'choosing several options at once',
		);
		$caption  = $captions[ $capability ] ?? str_replace( '_', ' ', $capability );
		return 'The source ' . $kind . ' control (' . $named . ') needs ' . $caption . ', which the provider cannot represent; add it by hand or accept the loss.';
	}
}
