<?php
/**
 * Jetpack contact-form materialization for preserved form runtime islands.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-static-site-importer-form-fallback-contract.php';
require_once __DIR__ . '/class-static-site-importer-form-presentation-overlay.php';
require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
if ( ! class_exists( 'Static_Site_Importer_Jetpack_Forms_Runtime' ) ) {
	require_once __DIR__ . '/class-static-site-importer-jetpack-forms-runtime.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Textarea_Rows' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-textarea-rows.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Field_Markup' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-field-markup.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Presentation' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-presentation.php';
}
require_once __DIR__ . '/class-static-site-importer-form-mapping-plan.php';
require_once __DIR__ . '/class-static-site-importer-form-plan-state.php';
require_once __DIR__ . '/class-static-site-importer-form-readable-substitution.php';

/**
 * Turns preserved <form> fallback metadata into working Jetpack Form blocks.
 *
 * Mirrors Static_Site_Importer_Woo_Product_Seeder: the registry owns the manifest
 * contract; this provider seeder only consumes the normalized shape after the
 * adapter validator has succeeded and emits real form-provider block markup so a
 * detected form gains submission handling, email notifications, and spam control
 * instead of staying a dead html_form_fallback runtime island.
 */
class Static_Site_Importer_Form_Seeder {
	/** Form materialization emits provider block data only; no persistent provider entity exists to undo. */
	public static function rollback( array $report ): array {
		unset( $report );
		return array(
			'status' => 'rolled_back',
			'reason' => 'no_persistent_entity',
		); }

	/** Return the provider block emitted for one mapped form binding. */
	public static function binding_block_markup( array $entity, array $result ): string {
		unset( $entity );
		return ! empty( $result['runtime_mapped'] ) && is_string( $result['block_markup'] ?? null ) ? $result['block_markup'] : '';
	}

	/** Return adapter-owned Jetpack block data for classic server rendering. */
	public static function binding_classic_render( array $entity, array $result ): array {
		unset( $entity );
		return ! empty( $result['runtime_mapped'] ) && is_string( $result['block_markup'] ?? null ) && '' !== trim( $result['block_markup'] ) ? array(
			'kind'    => 'blocks',
			'content' => $result['block_markup'],
		) : array();
	}

	/**
	 * Provider id this seeder materializes for.
	 */
	public const PROVIDER_ID = 'jetpack';

	/**
	 * Return the Jetpack contact-form adapter definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function adapter(): array {
		return array(
			'id'                       => 'jetpack_contact_form',
			'entity_type'              => 'form',
			'entity_collection'        => 'forms',
			'rejected_rows_degrade'    => true,
			'capability'               => 'form',
			'provider'                 => 'jetpack',
			'label'                    => 'Jetpack contact form',
			'report_key'               => 'form_seeding',
			'waiver_arg'               => 'allow_missing_jetpack',
			'validator'                => array( 'Static_Site_Importer_Entity_Materializer_Registry', 'validate_forms_manifest' ),
			'materializer'             => array( self::class, 'seed' ),
			'rollback_callback'        => array( self::class, 'rollback' ),
			'rollback_contract_id'     => 'static-site-importer/jetpack-form-rollback/v1',
			'binding_callback'         => array( self::class, 'binding_block_markup' ),
			'classic_binding_callback' => array( self::class, 'binding_classic_render' ),
			'report_callback'          => array( self::class, 'new_report' ),
			'submission_evidence'      => array(
				'can_accept_callback' => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'jetpack_can_accept' ),
				'submit'              => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'submit_jetpack' ),
				'cleanup'             => array( 'Static_Site_Importer_Provider_Submission_Evidence', 'cleanup_feedback' ),
			),
			'dependencies'             => array(
				array(
					'type'                  => 'wp_org_plugin',
					'slug'                  => 'jetpack',
					'plugin_file'           => 'jetpack/jetpack.php',
					'availability_callback' => array( Static_Site_Importer_Jetpack_Forms_Runtime::class, 'jetpack_forms_available' ),
					'preparation_callback'  => array( Static_Site_Importer_Jetpack_Forms_Runtime::class, 'prepare_jetpack_forms_runtime' ),
					'provider_readiness'    => array(
						'required_block_types' => Static_Site_Importer_Jetpack_Forms_Runtime::required_block_types(),
						'required_classes'     => Static_Site_Importer_Jetpack_Forms_Runtime::required_runtime_apis(),
					),
					'missing_apis'          => array(
						'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form',
						'jetpack/contact-form',
						'jetpack/field-text',
						'jetpack/field-number',
						'jetpack/field-email',
						'jetpack/field-url',
						'jetpack/field-date',
						'jetpack/field-textarea',
						'jetpack/field-select',
						'jetpack/field-checkbox',
						'jetpack/field-radio',
						'jetpack/label',
						'jetpack/input',
						'jetpack/options',
						'jetpack/option',
					),
				),
			),
		);
	}

	/** Register the provider bootstrap needed on every WordPress request. */
	public static function register_runtime_bootstrap(): void {
		Static_Site_Importer_Jetpack_Forms_Runtime::register_runtime_bootstrap();
	}

	/**
	 * Materialize Jetpack contact forms from a validated forms manifest.
	 *
	 * @param array<string, mixed> $manifest Validated forms manifest.
	 * @return array<string, mixed>
	 */
	public static function seed( array $manifest ): array {
		$forms  = self::manifest_forms( $manifest );
		$report = self::new_report( 'not_run' );

		if ( empty( $forms ) ) {
			$report['status'] = 'skipped';
			$report['reason'] = 'empty_validated_manifest';
			return $report;
		}

		$availability                   = Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_availability_details();
		$available                      = ! empty( $availability['available'] );
		$report['provider']             = self::PROVIDER_ID;
		$report['available']            = $available;
		$report['availability_details'] = $availability;
		$report['status']               = $available ? 'completed' : 'failed';
		if ( ! $available ) {
			$report['code']   = 'static_site_importer_form_provider_unavailable';
			$report['reason'] = 'provider_unavailable';
		}

		foreach ( $forms as $form ) {
			$row               = $available ? self::seed_form( $form, true ) : self::unavailable_form_row( $form );
			$fallback_identity = $form['fallback_identity'] ?? '';
			if ( is_string( $fallback_identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fallback_identity ) ) {
				$row['fallback_identity'] = $fallback_identity;
			}
			$report['forms'][] = $row;

			$status = $row['status'] ?? 'error';
			if ( isset( $report['counts'][ $status ] ) ) {
				++$report['counts'][ $status ];
			} else {
				++$report['counts']['error'];
			}
		}

		return $report;
	}

	/**
	 * Report a form that cannot be materialized until its configured provider is active.
	 *
	 * @param array<string, mixed> $form Validated form row.
	 * @return array<string, mixed>
	 */
	private static function unavailable_form_row( array $form ): array {
		return array(
			'selector'       => isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '',
			'source_path'    => isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '',
			'provider'       => self::PROVIDER_ID,
			'block_name'     => 'jetpack/contact-form',
			'status'         => 'skipped',
			'reason'         => 'provider_unavailable',
			'runtime_mapped' => false,
		);
	}

	/**
	 * Build an initial report shape.
	 *
	 * @param string $status Report status.
	 * @return array<string, mixed>
	 */
	public static function new_report( string $status = 'skipped' ): array {
		return array(
			'status'               => $status,
			'reason'               => '',
			'provider'             => self::PROVIDER_ID,
			'available'            => Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_available(),
			'availability_details' => Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_availability_details(),
			'counts'               => array(
				'mapped'  => 0,
				'skipped' => 0,
				'error'   => 0,
			),
			'forms'                => array(),
		);
	}

	/**
	 * Extract the validator-owned forms list from a manifest.
	 *
	 * @param array<string, mixed> $manifest Validated forms manifest.
	 * @return array<int, array<string, mixed>>
	 */
	private static function manifest_forms( array $manifest ): array {
		$forms = isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : $manifest;

		return array_values(
			array_filter(
				$forms,
				static fn ( $form ): bool => is_array( $form ) && 'form' === Static_Site_Importer_Form_Fallback_Contract::route( $form )
			)
		);
	}

	/**
	 * Map one source form into Jetpack contact-form block markup.
	 *
	 * @param array<string, mixed> $form      Validated form row.
	 * @param bool                 $available Whether the Jetpack runtime is active.
	 * @return array<string, mixed>
	 */
	private static function seed_form( array $form, bool $available ): array {
		return self::plan_form( $form, $available )->to_report();
	}

	/** Prepare readable fields, presentation destinations and the support decision. */
	private static function plan_form( array $form, bool $available ): Static_Site_Importer_Form_Mapping_Plan {
		if ( ! Static_Site_Importer_Form_Readable_Substitution::supports( $form ) ) {
			return self::skipped_plan( $form, (string) ( $form['selector'] ?? '' ), (string) ( $form['source_path'] ?? '' ), 'incomplete_readable_form_tree' );
		}
		$state = self::normalized_plan_state( $form );
		self::group_controls( $state );
		foreach ( $state->controls as $control_index => $control ) {
			if ( is_array( $control ) ) {
				if ( Static_Site_Importer_Form_Presentation_Descriptor::source_label_wraps_control( $state->form, $control_index ) ) {
					// The field-box shell carries the wrapping label's classes.
					unset( $control['label_class'] );
				}
				self::map_control( $state, $control_index, $control );
			}
		}

		return self::readable_plan( $state, $available );
	}

	/** Keep native topology and compile only the existing field-presentation surfaces. */
	private static function readable_plan( Static_Site_Importer_Form_Plan_State $state, bool $available ): Static_Site_Importer_Form_Mapping_Plan {
		// The source form's own box stays a native group: Jetpack lays its form
		// body out as a wrapping flex row and gives a group child the full row,
		// so the group keeps the source form's layout around its children.
		// The source form's classes live on that group, so the provider block
		// carries only the layout scope.
		$root                    = self::form_root_as_group( Static_Site_Importer_Form_Readable_Substitution::substitute( $state->form['readable_blocks'][0], $state ) );
		$form_attrs              = Static_Site_Importer_Form_Field_Markup::contact_form_attributes( $state->form, $state->scope );
		$form_attrs['className'] = $state->scope;
		$block                   = array(
			'name'        => 'jetpack/contact-form',
			'attrs'       => $form_attrs,
			'innerBlocks' => array( $root ),
		);

		// No wrapper/layout facts enter the overlay. Its presentation destinations
		// still own label paint, input sizing and the textarea rows policy.
		$presentation_form                 = $state->form;
		$presentation_form['layout_graph'] = array( 'nodes' => array() );
		$state->presentation_descriptors   = array_intersect_key( $state->presentation_descriptors, $state->field_blocks );
		$field_box_labels                  = array();
		foreach ( $state->field_blocks as $control_index => $field_block ) {
			if ( 'core/button' === $field_block['name'] && isset( $state->presentation_descriptors[ $control_index ] ) ) {
				$state->presentation_descriptors[ $control_index ] = Static_Site_Importer_Form_Readable_Substitution::with_source_box_submit_wrapper( $state->presentation_descriptors[ $control_index ] );
				continue;
			}
			// A field's own caption arrangement applies where the provider renders
			// that caption before its control. Choice fields order their option
			// labels natively, and an option group's caption is its legend.
			if ( ! isset( $state->presentation_descriptors[ $control_index ] ) || null !== Static_Site_Importer_Form_Presentation_Descriptor::source_choice_label( $state->form, $control_index ) || in_array( $field_block['name'], array( 'jetpack/field-checkbox', 'jetpack/field-checkbox-multiple', 'jetpack/field-radio' ), true ) ) {
				continue;
			}
			if ( Static_Site_Importer_Form_Presentation_Descriptor::source_label_wraps_control( $state->form, $control_index ) ) {
				$state->presentation_descriptors[ $control_index ] = Static_Site_Importer_Form_Readable_Substitution::with_field_box_label( $state->presentation_descriptors[ $control_index ], $state->scope, $control_index );
				$field_box_labels[ $control_index ]                = true;
			}
			if ( true === ( $state->form['controls'][ $control_index ]['label_after_control'] ?? null ) ) {
				$state->presentation_descriptors[ $control_index ] = Static_Site_Importer_Form_Readable_Substitution::with_caption_after_control( $state->presentation_descriptors[ $control_index ], $state->scope, $control_index, isset( $field_box_labels[ $control_index ] ) );
			}
		}
		$presentation_form['presentation_graph']             = Static_Site_Importer_Form_Readable_Substitution::field_box_presentation( is_array( $presentation_form['presentation_graph'] ?? null ) ? $presentation_form['presentation_graph'] : array(), $field_box_labels );
		$presentation_form['presentation_graph']['controls'] = array_values( array_filter( $presentation_form['presentation_graph']['controls'] ?? array(), static fn( array $control ): bool => isset( $state->field_blocks[ $control['index'] ] ) ) );
		$maps    = self::target_maps( $state, $presentation_form );
		$overlay = Static_Site_Importer_Form_Presentation::collapse_inactive_field_errors(
			// Core's editor does not run the server-side source-class transfer hook,
			// so only the front end leaves class-owned submit colours to the live source rules.
			Static_Site_Importer_Form_Presentation_Overlay::compile_surfaces( $presentation_form['layout_graph'], $maps['target'], $maps['editor'], $presentation_form['presentation_graph'], Static_Site_Importer_Form_Presentation::without_class_carried_submit_colors( $presentation_form['presentation_graph'], $state->controls ) ),
			$state->scope
		);
		$receipt = array();
		Static_Site_Importer_Form_Mapping_Decision::append_receipt_entries( $receipt, 'operations', $overlay['operations'] );
		Static_Site_Importer_Form_Mapping_Decision::append_receipt_entries( $receipt, 'losses', array_merge( $overlay['losses'], $state->control_attribute_losses ) );
		foreach ( $state->form['control_topology']['nodes'] ?? array() as $node ) {
			if ( 'fieldset' === ( $node['tag'] ?? '' ) ) {
				Static_Site_Importer_Form_Mapping_Decision::append_receipt_entries(
					$receipt,
					'losses',
					array(
						array(
							'dimension'   => 'presentation',
							'reason_code' => 'semantic_wrapper_approximated',
						),
					)
				);
				break;
			}
		}
		$receipt['status'] = 'applied';
		$state->layout     = array( 'receipt' => $receipt );
		return Static_Site_Importer_Form_Mapping_Plan::prepared( $state->form, $state->field_blocks, $block, self::mapped_report_row( $state, $available, $maps['target'], $overlay ) );
	}

	/**
	 * The source form's group as a `div` inside the provider's form.
	 *
	 * The producer saved the group with its own markup: its frame (padding,
	 * border, radius, paint) and its layout classes. Only the element changes,
	 * since the provider renders the one `form`; everything else stays the
	 * markup the producer saved.
	 *
	 * @param array<string,mixed> $root Substituted readable root block.
	 * @return array<string,mixed>
	 */
	private static function form_root_as_group( array $root ): array {
		if ( 'form' !== ( $root['attrs']['tagName'] ?? '' ) ) {
			return $root;
		}
		unset( $root['attrs']['tagName'] );
		$slots = array_keys( array_filter( $root['innerContent'], 'is_string' ) );
		if ( array() !== $slots ) {
			$first                          = reset( $slots );
			$last                           = end( $slots );
			$root['innerContent'][ $first ] = (string) preg_replace( '/^(\s*)<form\b/i', '$1<div', $root['innerContent'][ $first ], 1 );
			$root['innerContent'][ $last ]  = (string) preg_replace( '/<\/form>(\s*)$/i', '</div>$1', $root['innerContent'][ $last ], 1 );
		}
		$root['innerHTML'] = implode( '', array_filter( $root['innerContent'], 'is_string' ) );
		return $root;
	}

	/** Normalize the producer form and release the controls the provider will map. */
	private static function normalized_plan_state( array $form ): Static_Site_Importer_Form_Plan_State {
		$state              = new Static_Site_Importer_Form_Plan_State();
		$form               = Static_Site_Importer_Form_Presentation::normalize_unconditional_layout_variants( $form );
		$controls           = isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array();
		$form               = self::project_submit_style_into_presentation_graph( $form, $controls );
		$state->textareas   = Static_Site_Importer_Form_Textarea_Rows::resolve_form( $form, $controls );
		$form               = self::project_textarea_rows_into_presentation_graph( $form, $state->textareas );
		$released           = self::release_visually_hidden_native_controls( $form, $controls );
		$state->form        = $released['form'];
		$state->controls    = $released['controls'];
		$state->selector    = isset( $state->form['selector'] ) && is_scalar( $state->form['selector'] ) ? (string) $state->form['selector'] : '';
		$state->source_path = isset( $state->form['source_path'] ) && is_scalar( $state->form['source_path'] ) ? (string) $state->form['source_path'] : '';
		return $state;
	}

	/** Resolve layout scope, presentation sources, radio groups, linked choices and submit defaults. */
	private static function group_controls( Static_Site_Importer_Form_Plan_State $state ): void {
		$form                         = $state->form;
		$state->scope                 = Static_Site_Importer_Form_Presentation::layout_scope( $form );
		$state->presentation_roles    = Static_Site_Importer_Form_Presentation_Descriptor::presentation_roles( is_array( $form['presentation_graph'] ?? null ) ? $form['presentation_graph'] : array() );
		$state->control_presentations = array_column( $form['presentation_graph']['controls'] ?? array(), null, 'index' );
		$state->radio_groups          = Static_Site_Importer_Form_Field_Markup::labelled_radio_groups( $form, $state->controls );
		$state->suppressed_controls   = $state->radio_groups['suppressed_controls'];
		$state->choice_pairs          = self::linked_choice_pairs( $state->controls, $form );
		foreach ( $state->choice_pairs as $trigger => $carrier ) {
			$state->suppressed_controls[ $carrier ] = true;
		}
		// Context is editable content, not a reason to decline the provider form.
		// Keep supported fields in Jetpack and project bounded before/after copy
		// as editable core blocks within the provider form (see context_blocks).
		$state->submit_presentation = isset( $form['form']['submit_presentation'] ) && is_array( $form['form']['submit_presentation'] ) ? $form['form']['submit_presentation'] : array();
		if ( is_string( $state->submit_presentation['text'] ?? null ) && '' !== trim( $state->submit_presentation['text'] ) ) {
			$state->submit_text = trim( $state->submit_presentation['text'] );
		}
	}

	/** Map one source control into a provider field, submit, native button or reported skip. */
	private static function map_control( Static_Site_Importer_Form_Plan_State $state, int $control_index, array $control ): void {
		$form = $state->form;
		if ( isset( $state->choice_pairs[ $control_index ] ) ) {
			$carrier         = $state->controls[ $state->choice_pairs[ $control_index ] ];
			$control         = array_merge( $carrier, array_intersect_key( $control, array_flip( array( 'label', 'label_class', 'required_text', 'required_indicator', 'description' ) ) ) );
			$control['tag']  = 'select';
			$control['type'] = 'select';
			// The source carrier was hidden; its class cannot follow the new
			// visitor-facing provider input.
			unset( $control['class'] );
		}
		$hidden_nodes = Static_Site_Importer_Form_Presentation::unconditionally_hidden_descendants( $form );
		if ( isset( $hidden_nodes[ 'control-' . $control_index ] ) ) {
			$state->suppressed_controls[ $control_index ] = true;
			$state->skipped[]                             = 'unconditionally_hidden_control';
			return;
		}
		$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
		$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
		if ( self::is_hidden_bookkeeping_control( $form, $control_index, $control, $tag, $type ) ) {
			$state->suppressed_controls[ $control_index ] = true;
			$state->skipped[]                             = 'hidden_bookkeeping';
			return;
		}
		$choice_box                                        = null !== Static_Site_Importer_Form_Presentation_Descriptor::source_choice_label( $form, $control_index );
		$state->presentation_descriptors[ $control_index ] = Static_Site_Importer_Form_Presentation_Descriptor::presentation_descriptor( $state->scope, $control_index, $type, $state->presentation_roles[ $control_index ] ?? array(), isset( $control['label_classes'] ) || isset( $control['label_marker'] ), $choice_box, Static_Site_Importer_Form_Presentation_Descriptor::source_label_wraps_control( $form, $control_index ), Static_Site_Importer_Form_Presentation_Descriptor::source_label_row_carried_margins( $form, $control_index ) );
		if ( isset( $state->suppressed_controls[ $control_index ] ) ) {
			return;
		}
		if ( isset( $state->radio_groups['groups'][ $control_index ] ) ) {
			$control = $state->radio_groups['groups'][ $control_index ];
		}

		$presentation_descriptor = $state->presentation_descriptors[ $control_index ];
		$is_auxiliary_button     = 'button' === $tag && Static_Site_Importer_Form_Field_Markup::is_provider_auxiliary_button( $state->controls, $control_index );
		if ( $is_auxiliary_button ) {
			// The provider owns this control as part of a field rather than as an action.
			return;
		}

		if ( 'submit' === $type ) {
			self::map_submit_control( $state, $control_index, $control, $presentation_descriptor );
			return;
		}
		if ( 'button' === $tag && 'button' === $type ) {
			// Mode and filter controls remain native buttons; they are not submits.
			$state->field_blocks[ $control_index ] = Static_Site_Importer_Form_Field_Markup::button_block(
				Static_Site_Importer_Form_Field_Markup::control_text( $control ),
				trim( (string) ( $control['class'] ?? '' ) . ' ' . Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $control_index ) . ' ' . $presentation_descriptor['control_class'] )
			);
			return;
		}

		if ( 'file' === $type && 'input' === $tag ) {
			$label                                 = Static_Site_Importer_Form_Field_Markup::control_text( $control );
			$state->field_blocks[ $control_index ] = array(
				'name'    => 'core/paragraph',
				'attrs'   => array( 'className' => 'ssi-unsupported-file-upload' ),
				'content' => trim( $label . ' — File upload requires Jetpack connection and a supported plan; unavailable in this import runtime.' ),
				'wrapper' => 'paragraph',
			);
			$state->skipped[]                      = 'file';
			$state->unsupported_capabilities[]     = array(
				'capability'    => 'file_upload',
				'reason_code'   => 'jetpack_upload_endpoint_requires_connected_site_and_supported_plan',
				'control_index' => $control_index,
			);
			return;
		}
		self::map_field_control( $state, $control_index, $control, $tag, $type, $presentation_descriptor, $choice_box );
	}

	/** Map the indexed source submit and its presentation. */
	private static function map_submit_control( Static_Site_Importer_Form_Plan_State $state, int $control_index, array $control, array $presentation_descriptor ): void {
		$text               = Static_Site_Importer_Form_Field_Markup::control_text( $control );
		$state->submit_text = '' !== $text ? $text : $state->submit_text;
		if ( isset( $control['presentation']['style'] ) && is_array( $control['presentation']['style'] ) ) {
			$state->submit_presentation['block_attrs']['style'] = $control['presentation']['style'];
		}
		if ( empty( $state->submit_presentation['classes'] ) && isset( $control['class'] ) && is_scalar( $control['class'] ) ) {
			$submit_classes                        = preg_split( '/\s+/', trim( (string) $control['class'] ) );
			$state->submit_presentation['classes'] = false === $submit_classes ? array() : $submit_classes;
		}
		// The compiler reports the label element a source button owns, including
		// when it declares no classes, because authored rules address it as a
		// descendant of the button.
		if ( ! isset( $state->submit_presentation['label_classes'] ) && isset( $control['label_classes'] ) && is_scalar( $control['label_classes'] ) ) {
			$label_classes                               = preg_split( '/\s+/', trim( (string) $control['label_classes'] ) );
			$state->submit_presentation['label_classes'] = false === $label_classes ? array() : array_values( array_filter( $label_classes ) );
		}
		if ( ! isset( $state->submit_presentation['label_marker'] ) && isset( $control['label_marker'] ) && is_scalar( $control['label_marker'] ) ) {
			$state->submit_presentation['label_marker'] = trim( (string) $control['label_marker'] );
		}
		// The provider emits every submit as a `<button>`; a retagged control
		// carries the marker the projected stylesheet excludes with its tag.
		$state->submit_presentation['source_tag']        = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
		$state->submit_presentation['source_tag_marker'] = isset( $control['source_tag_marker'] ) && is_string( $control['source_tag_marker'] ) ? trim( $control['source_tag_marker'] ) : '';
		foreach ( self::submit_icons( $state->form, $control_index ) as $key => $parts ) {
			if ( ! isset( $state->submit_presentation[ $key ] ) && ! empty( $parts ) ) {
				$state->submit_presentation[ $key ] = $parts;
			}
		}
		$presentation_class                    = $presentation_descriptor['control_class'];
		$state->field_blocks[ $control_index ] = Static_Site_Importer_Form_Field_Markup::submit_button_block( $state->submit_text, trim( Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $control_index ) . ' ' . $presentation_class ), $state->submit_presentation );
	}

	/** Build a provider field block and carry its source class, layout hook, help and choice tokens. */
	private static function map_field_control( Static_Site_Importer_Form_Plan_State $state, int $control_index, array $control, string $tag, string $type, array $presentation_descriptor, bool $choice_box ): void {
		$control_phone_destinations = $presentation_descriptor['phone_destinations'];
		$field_block                = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
			$tag,
			$type,
			$control,
			empty( $control_phone_destinations ) ? $presentation_descriptor['control_class'] : '',
			$choice_box && '' !== $presentation_descriptor['label_class'] ? 'ssi-source-choice-label--' . $presentation_descriptor['label_class'] : $presentation_descriptor['label_class'],
			$state->control_presentations[ $control_index ]['control']['styles'] ?? array()
		);
		if ( null === $field_block ) {
			$state->skipped[] = '' !== $type ? $type : $tag;
			return;
		}
		if ( ! empty( $control_phone_destinations ) ) {
			foreach ( $field_block['innerBlocks'] as &$inner_block ) {
				if ( 'jetpack/phone-input' === ( $inner_block['name'] ?? '' ) ) {
					$inner_block['attrs']['className'] = trim( (string) ( $inner_block['attrs']['className'] ?? '' ) . ' ' . implode( ' ', array_column( $control_phone_destinations, 'class' ) ) );
				}
			}
			unset( $inner_block );
		}
		foreach ( $field_block['losses'] ?? array() as $loss ) {
			$state->control_attribute_losses[] = $loss + array( 'control_index' => $control_index );
		}
		unset( $field_block['losses'] );

		$source_class                      = isset( $control['class'] ) && is_scalar( $control['class'] ) ? trim( (string) $control['class'] ) : '';
		$has_provider_input                = (bool) array_filter( $field_block['innerBlocks'] ?? array(), static fn ( array $block ): bool => in_array( $block['name'] ?? '', array( 'jetpack/input', 'jetpack/phone-input' ), true ) );
		$field_source_class                = $has_provider_input ? '' : $source_class;
		$layout_hook                       = Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $control_index );
		$help_classes                      = isset( $field_block['attrs']['helpText'] ) ? Static_Site_Importer_Form_Field_Markup::help_class_markers( $state->form, $control ) : '';
		$field_block['attrs']['className'] = trim( $field_source_class . ' ' . $layout_hook . ' ' . $help_classes );
		if ( isset( $state->choice_pairs[ $control_index ] ) ) {
			$choice_token = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $control['options'] );
			if ( '' === $choice_token ) {
				$state->control_attribute_losses[] = array(
					'dimension'     => 'control',
					'reason_code'   => 'unsupported_control_attribute',
					'attribute'     => 'choice_values',
					'control_index' => $control_index,
				);
			} else {
				$field_block['attrs']['className'] .= ' ' . $choice_token;
			}
		}
		// Jetpack replaces a date field's class with `jp-contact-form-date`
		// before deriving wrap classes, so the field-level layout hook never
		// reaches the shell. Keep that hook on the inner input, which Jetpack
		// still copies onto the wrap after the replacement.
		if ( 'jetpack/field-date' === ( $field_block['name'] ?? '' ) ) {
			foreach ( $field_block['innerBlocks'] as &$inner_block ) {
				if ( 'jetpack/input' === ( $inner_block['name'] ?? '' ) ) {
					$inner_block['attrs']['className'] = trim( (string) ( $inner_block['attrs']['className'] ?? '' ) . ' ' . $layout_hook );
				}
			}
			unset( $inner_block );
		}
		$state->field_blocks[ $control_index ] = $field_block;
		$state->mapped_types[]                 = $field_block['name'];
	}

	/**
	 * Derive the canonical provider target map and the editor target map.
	 *
	 * @return array{target:array<string,mixed>,editor:array<string,mixed>}
	 */
	private static function target_maps( Static_Site_Importer_Form_Plan_State $state, array $overlay_form ): array {
		// The declared part of every target and destination drives the editor
		// derivation only; the canonical map that is validated, compiled, and
		// recorded carries none.
		$part_map           = Static_Site_Importer_Form_Presentation::provider_layout_target_map( $overlay_form, $state->scope, $state->presentation_descriptors );
		$target_map         = Static_Site_Importer_Form_Presentation_Descriptor::without_parts( $part_map );
		$presentation_graph = is_array( $overlay_form['presentation_graph'] ?? null ) ? $overlay_form['presentation_graph'] : array();
		// The editor sizes a textarea from its resolved policy; a control whose
		// presentation row the overlay withdrew has no editor box to size.
		$editor_textareas = array();
		foreach ( is_array( $presentation_graph['controls'] ?? null ) ? $presentation_graph['controls'] : array() as $row ) {
			if ( is_array( $row ) && is_int( $row['index'] ?? null ) && is_array( $row['control']['styles'] ?? null ) && isset( $state->textareas[ $row['index'] ] ) ) {
				$editor_textareas[ $row['index'] ] = $state->textareas[ $row['index'] ];
			}
		}
		return array(
			'target' => $target_map,
			'editor' => Static_Site_Importer_Form_Presentation_Descriptor::editor_layout_target_map( $part_map, $state->scope, $editor_textareas ),
		);
	}

	/** The mapped report row: fields, submit, layout receipt, target map, overlay CSS and visual state. */
	private static function mapped_report_row( Static_Site_Importer_Form_Plan_State $state, bool $available, array $target_map, array $overlay ): array {
		$row = array(
			'selector'                    => $state->selector,
			'source_path'                 => $state->source_path,
			'provider'                    => self::PROVIDER_ID,
			'block_name'                  => 'jetpack/contact-form',
			'status'                      => 'mapped',
			'field_count'                 => count( $state->mapped_types ),
			'field_blocks'                => $state->mapped_types,
			'skipped_types'               => array_values( array_unique( array_filter( $state->skipped ) ) ),
			'submit_text'                 => $state->submit_text,
			'runtime_mapped'              => true,
			'runtime_carried'             => $available,
			'block_markup'                => '',
			'computed_layout_receipt'     => $state->layout['receipt'],
			'provider_layout_target_map'  => $target_map,
			'provider_layout_overlay_css' => $overlay['overlay'],
		);
		if ( ! empty( $state->unsupported_capabilities ) ) {
			$row['unsupported_capabilities'] = $state->unsupported_capabilities;
		}
		return $row;
	}

	/** Build the skipped mapping plan for a form the provider cannot represent. */
	private static function skipped_plan( array $form, string $selector, string $source_path, string $reason, array $extra = array() ): Static_Site_Importer_Form_Mapping_Plan {
		return Static_Site_Importer_Form_Mapping_Plan::skipped(
			$form,
			array_merge(
				array(
					'selector'       => $selector,
					'source_path'    => $source_path,
					'provider'       => self::PROVIDER_ID,
					'block_name'     => 'jetpack/contact-form',
					'status'         => 'skipped',
					'reason'         => $reason,
					'runtime_mapped' => false,
				),
				$extra
			)
		);
	}

	/**
	 * Split a submit control's captured inline SVGs by the producer's label side.
	 *
	 * The producer captures every drawable inline SVG descendant of a control
	 * as a bounded visual part (see the presentation graph's `visual_parts`,
	 * the same capture the empty-country visual state consumes). A submit
	 * button with an authored inline icon therefore arrives with that icon
	 * attached to its control index, while the provider submit only carries
	 * the button text — so the icon, and the authored gap beside it, vanish.
	 * Only parts that pass the portable inline-SVG admission and the same
	 * bounded sizes the producer enforced are carried, in source order.
	 *
	 * @param array<string,mixed> $form Validated form row.
	 * @param int                 $control_index Submit control index.
	 * @return array<string,array<int,string>> Validated SVGs per side, source order.
	 */
	private static function submit_icons( array $form, int $control_index ): array {
		$parts = is_array( $form['presentation_graph']['visual_parts'] ?? null ) ? $form['presentation_graph']['visual_parts'] : array();
		$owned = array();
		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) || ( $part['index'] ?? null ) !== $control_index || 'inline_svg' !== ( $part['kind'] ?? null ) ) {
				continue;
			}
			$id = is_string( $part['id'] ?? null ) ? $part['id'] : '';
			if ( '' === $id || isset( $owned[ $id ] ) || ! preg_match( '/-svg-([0-9]{1,3})$/D', $id, $ordinal ) ) {
				continue;
			}
			$markup = is_string( $part['markup'] ?? null ) ? trim( $part['markup'] ) : '';
			if ( '' === $markup || strlen( $markup ) > 12288 || ! Static_Site_Importer_Provider_Form_Runtime_V1::valid_inline_svg( $markup ) ) {
				continue;
			}
			$side = $part['label_side'] ?? null;
			if ( ! in_array( $side, array( 'before', 'after' ), true ) ) {
				continue;
			}
			$owned[ (int) $ordinal[1] ] = array(
				'side'   => $side,
				'markup' => $markup,
			);
		}
		ksort( $owned );
		$icons = array(
			'icon'       => array(),
			'icon_after' => array(),
		);
		foreach ( array_slice( array_values( $owned ), 0, 4 ) as $part ) {
			$icons[ 'before' === $part['side'] ? 'icon' : 'icon_after' ][] = $part['markup'];
		}
		return $icons;
	}

	/**
	 * Pair only a producer-linked visible choice trigger with its native select in
	 * the same field shell. The carrier is suppressed only after the trigger can
	 * stand in for the complete option list as a provider select.
	 *
	 * @return array<int,int> Trigger index to carrier index.
	 */
	private static function linked_choice_pairs( array $controls, array $form ): array {
		$parents = array();
		foreach ( $form['control_topology']['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) ) {
				$parents[ $node['control'] ] = $node['parent'] ?? null;
			}
		}
		$pairs = array();
		$used  = array();
		foreach ( $controls as $index => $trigger ) {
			if ( ! is_array( $trigger ) || 'button' !== ( $trigger['tag'] ?? null ) || 'combobox' !== ( $trigger['role'] ?? null ) || ! is_string( $trigger['choice_source_selector'] ?? null ) || '' === trim( $trigger['choice_source_selector'] ) || ! isset( $parents[ $index ] ) ) {
				continue;
			}
			$carrier_index = $index + 1;
			$carrier       = $controls[ $carrier_index ] ?? null;
			if ( ! is_array( $carrier ) || 'select' !== ( $carrier['tag'] ?? null ) || ( $carrier['selector'] ?? null ) !== $trigger['choice_source_selector'] || ( $parents[ $carrier_index ] ?? null ) !== $parents[ $index ] || isset( $used[ $carrier_index ] ) || empty( $carrier['options'] ) || ! isset( $trigger['options'] ) || $carrier['options'] !== $trigger['options'] ) {
				continue;
			}
			$pairs[ $index ]        = $carrier_index;
			$used[ $carrier_index ] = true;
		}
		return $pairs;
	}

	/**
	 * Drop source visually-hidden presentation before provider destinations are built.
	 *
	 * A source hides its native input (absolute or fixed, a 1px-or-smaller box, and
	 * either a collapsing clip or a -1px margin) because a sibling paints the visible
	 * box. The provider emits its own native control and does not reproduce that
	 * sibling, so those declarations would hide the only remaining control. Detection
	 * reads declared styles only; a measured box is not evidence. Label roles stay.
	 *
	 * @param array<string,mixed> $form     Provider form manifest row.
	 * @param array<int,mixed>    $controls Normalized controls list.
	 * @return array{form:array<string,mixed>,controls:array<int,mixed>}
	 */
	private static function release_visually_hidden_native_controls( array $form, array $controls ): array {
		$hidden      = array();
		$base_styles = array();
		foreach ( $form['presentation_graph']['controls'] ?? array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['index'] ?? null ) || ! is_array( $row['control']['styles'] ?? null ) ) {
				continue;
			}
			$base_styles[ $row['index'] ] = $row['control']['styles'];
			if ( self::is_visually_hidden_control_declaration( $row['control']['styles'] ) ) {
				$hidden[ $row['index'] ] = true;
			}
		}
		foreach ( $form['presentation_graph']['variants'] ?? array() as $variant ) {
			if ( ! is_array( $variant ) || 'control' !== ( $variant['role'] ?? null ) || ! is_int( $variant['index'] ?? null ) || ! is_array( $variant['style_patch'] ?? null ) ) {
				continue;
			}
			$styles = array_replace( $base_styles[ $variant['index'] ] ?? array(), $variant['style_patch'] );
			if ( self::is_visually_hidden_control_declaration( $styles ) ) {
				$hidden[ $variant['index'] ] = true;
			}
		}
		if ( empty( $hidden ) ) {
			return array(
				'form'     => $form,
				'controls' => $controls,
			);
		}
		foreach ( array_keys( $hidden ) as $index ) {
			if ( isset( $controls[ $index ] ) && is_array( $controls[ $index ] ) ) {
				unset( $controls[ $index ]['class'] );
			}
			if ( isset( $form['controls'][ $index ] ) && is_array( $form['controls'][ $index ] ) ) {
				unset( $form['controls'][ $index ]['class'] );
			}
		}
		if ( is_array( $form['presentation_graph']['controls'] ?? null ) ) {
			$kept_controls = array();
			foreach ( $form['presentation_graph']['controls'] as $row ) {
				if ( ! is_array( $row ) || ! isset( $hidden[ $row['index'] ?? null ] ) ) {
					$kept_controls[] = $row;
					continue;
				}
				unset( $row['control'] );
				if ( isset( $row['label'] ) || isset( $row['required_marker'] ) ) {
					$kept_controls[] = $row;
				}
			}
			$form['presentation_graph']['controls'] = $kept_controls;
		}
		if ( is_array( $form['presentation_graph']['variants'] ?? null ) ) {
			$form['presentation_graph']['variants'] = array_values(
				array_filter(
					$form['presentation_graph']['variants'],
					static fn ( $variant ): bool => ! is_array( $variant ) || 'control' !== ( $variant['role'] ?? null ) || ! isset( $hidden[ $variant['index'] ?? null ] )
				)
			);
		}
		if ( is_array( $form['layout_graph']['nodes'] ?? null ) ) {
			$form['layout_graph']['nodes'] = array_values(
				array_filter(
					$form['layout_graph']['nodes'],
					static function ( $node ) use ( $hidden ): bool {
						if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || 1 !== preg_match( '/^control-([0-9]+)$/D', $node['id'], $match ) ) {
							return true;
						}
						return ! isset( $hidden[ (int) $match[1] ] );
					}
				)
			);
		}
		if ( is_array( $form['layout_graph']['variants'] ?? null ) ) {
			$form['layout_graph']['variants'] = array_values(
				array_filter(
					$form['layout_graph']['variants'],
					static function ( $variant ) use ( $hidden ): bool {
						if ( ! is_array( $variant ) || ! is_string( $variant['node'] ?? null ) || 1 !== preg_match( '/^control-([0-9]+)$/D', $variant['node'], $match ) ) {
							return true;
						}
						return ! isset( $hidden[ (int) $match[1] ] );
					}
				)
			);
		}
		return array(
			'form'     => $form,
			'controls' => $controls,
		);
	}

	/**
	 * Whether declared styles hide a native control that a sibling box was painting.
	 *
	 * @param array<string,mixed> $styles Control presentation declarations.
	 */
	private static function is_visually_hidden_control_declaration( array $styles ): bool {
		$position = strtolower( trim( (string) ( $styles['position'] ?? '' ) ) );
		if ( ! in_array( $position, array( 'absolute', 'fixed' ), true ) || ! self::is_collapsed_declared_box( $styles['width'] ?? null ) || ! self::is_collapsed_declared_box( $styles['height'] ?? null ) ) {
			return false;
		}
		return self::declaration_has_collapsing_clip( $styles ) || self::declaration_has_negative_pixel_margin( $styles );
	}

	/** @param mixed $value Declared box length. */
	private static function is_collapsed_declared_box( mixed $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[0-9]+(?:\.[0-9]+)?)(?:px)?$/D', strtolower( trim( $value ) ) ) ) {
			return false;
		}
		return (float) $value <= 1.0;
	}

	/** @param array<string,mixed> $styles */
	private static function declaration_has_negative_pixel_margin( array $styles ): bool {
		foreach ( array( 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block', 'margin_block_start', 'margin_block_end', 'margin_inline', 'margin_inline_start', 'margin_inline_end' ) as $key ) {
			if ( ! is_string( $styles[ $key ] ?? null ) ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', strtolower( trim( $styles[ $key ] ) ) );
			if ( is_array( $tokens ) && array() !== $tokens && array() === array_diff( $tokens, array( '-1px' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $styles */
	private static function declaration_has_collapsing_clip( array $styles ): bool {
		$clip = strtolower( trim( (string) ( $styles['clip'] ?? '' ) ) );
		if ( '' !== $clip && 1 === preg_match( '/^rect\(\s*(?:0|0px|1px)(?:\s*,\s*|\s+)(?:0|0px|1px)(?:\s*,\s*|\s+)(?:0|0px|1px)(?:\s*,\s*|\s+)(?:0|0px|1px)\s*\)$/D', $clip ) ) {
			return true;
		}
		$clip_path = strtolower( trim( (string) ( $styles['clip_path'] ?? $styles['clip-path'] ?? '' ) ) );
		return '' !== $clip_path && 1 === preg_match( '/^inset\(\s*(?:50%|100%)(?:\s+(?:50%|100%)){0,3}\s*\)$/D', $clip_path );
	}

	/**
	 * Provider forms omit source-only bookkeeping controls that cannot receive input.
	 *
	 * Some site builders use a visually hidden text field instead of type="hidden".
	 * Treat it as bookkeeping only when its private name and absent user-facing affordances
	 * prove it is not an authored field the provider must reproduce.
	 */
	private static function is_hidden_bookkeeping_control( array $form, int $control_index, array $control, string $tag, string $type ): bool {
		if ( 'input' !== $tag || ! in_array( $type, array( '', 'text', 'hidden' ), true ) || '' !== trim( (string) ( $control['label'] ?? '' ) ) || '' !== trim( (string) ( $control['placeholder'] ?? '' ) ) ) {
			return false;
		}
		// Permanently collapsed, out-of-flow inputs with no visitor-facing
		// affordance are source bookkeeping even when their name is not private.
		// Conditional/revealed controls retain the existing provider mapping.
		if ( empty( $control['required'] ) && empty( $control['value'] ) ) {
			foreach ( $form['presentation_graph']['controls'] ?? array() as $row ) {
				$part = $row['control'] ?? array();
				if ( ( $row['index'] ?? null ) !== $control_index || 'absolute' !== ( $part['styles']['position'] ?? null ) || ! in_array( $part['styles']['transform'] ?? null, array( 'scale(0)', 'scale(0,0)', 'scale(0, 0)' ), true ) ) {
					continue;
				}
				$proven = array();
				foreach ( $part['provenance'] ?? array() as $fact ) {
					if ( null === ( $fact['condition'] ?? null ) && ! empty( $fact['source_sha256'] ) ) {
						$proven = array_merge( $proven, $fact['properties'] ?? array() );
					}
				}
				$conditional = array_filter( $form['presentation_graph']['variants'] ?? array(), static function ( array $variant ) use ( $control_index, $part ): bool {
					if ( ( $variant['index'] ?? null ) !== $control_index || 'control' !== ( $variant['role'] ?? null ) ) {
						return false;
					}
					$styles = array_replace( $part['styles'], $variant['style_patch'] ?? array() );
					return 'absolute' !== ( $styles['position'] ?? null ) || ! in_array( $styles['transform'] ?? null, array( 'scale(0)', 'scale(0,0)', 'scale(0, 0)' ), true );
				} );
				if ( empty( $conditional ) && empty( array_diff( array( 'position', 'transform' ), $proven ) ) ) {
					return true;
				}
			}
		}
		if ( ! preg_match( '/^_[a-z0-9_-]+$/iD', (string) ( $control['name'] ?? '' ) ) ) {
			return false;
		}
		if ( 'hidden' === $type ) {
			return true;
		}
		$presentation = $form['presentation_graph']['controls'][ $control_index ]['control'] ?? null;
		if ( is_array( $presentation ) && 'none' === ( $presentation['styles']['display'] ?? null ) && ! empty( $presentation['provenance'] ) ) {
			return true;
		}

		// Some captured builders use a text input for private bookkeeping and hide
		// it inline. The computed layout graph is the canonical evidence when no
		// separate presentation graph was emitted.
		foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ( $node['id'] ?? null ) !== 'control-' . $control_index || 'none' !== ( $node['layout']['display'] ?? null ) ) {
				continue;
			}
			foreach ( $node['provenance'] ?? array() as $fact ) {
				if ( is_array( $fact ) && null === ( $fact['condition'] ?? null ) && 'inline-style' === ( $fact['source_path'] ?? null ) && in_array( 'display', $fact['properties'] ?? array(), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Route a submit control's captured presentation style onto the same
	 * computed-presentation graph every other control already resolves through.
	 *
	 * A producer can capture a submit button's presentation as a bounded
	 * per-control style (see `flatten_submit_style()`) without running it
	 * through the full source-CSS-cascade `presentation_graph` compiler. Left
	 * alone, that capture only flagged the button with the
	 * `ssi-provider-submit-presentation` marker class; nothing ever resolved
	 * it into paint. Merging it into `presentation_graph.controls` lets the
	 * existing `Form_Presentation_Overlay` pipeline - already proven for labels,
	 * inputs, and this exact `.wp-block-button__link` destination - emit the
	 * real, specificity-safe CSS instead of relying on carried source class
	 * names a WordPress stylesheet does not define.
	 *
	 * @param array<string,mixed> $form Provider form manifest row.
	 * @param array<int,mixed>    $controls Normalized controls list.
	 * @return array<string,mixed>
	 */
	private static function project_submit_style_into_presentation_graph( array $form, array $controls ): array {
		foreach ( $controls as $control_index => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
			if ( 'submit' !== $type ) {
				continue;
			}
			$style = isset( $control['presentation']['style'] ) && is_array( $control['presentation']['style'] ) ? $control['presentation']['style'] : array();
			$flat  = self::flatten_submit_style( $style );
			if ( empty( $flat ) ) {
				continue;
			}
			$form = self::with_control_styles(
				$form,
				$control_index,
				static function ( array $existing_styles ) use ( $flat ): array {
					// The source-cascade graph is authoritative for every property it
					// resolved. The per-control capture fills only what the graph omits;
					// a box family the graph resolved (any padding or margin side) stays
					// whole so a captured longhand cannot split it.
					$family   = static fn ( string $key ): ?string => preg_match( '/^(padding|margin)(?:_|$)/', $key, $match ) ? $match[1] : null;
					$resolved = array_filter( array_map( $family, array_keys( $existing_styles ) ) );
					$fill     = array_filter( $flat, static fn ( string $key ): bool => ! array_key_exists( $key, $existing_styles ) && ! in_array( $family( $key ), $resolved, true ), ARRAY_FILTER_USE_KEY );
					return array_merge( $existing_styles, $fill );
				}
			);
		}
		return $form;
	}

	/**
	 * Project each textarea's resolved display and provider height release
	 * into the presentation graph.
	 *
	 * `Static_Site_Importer_Form_Textarea_Rows` owns both decisions; this only
	 * writes the resolved styles onto the control's presentation row, adding
	 * the row when the control had none.
	 *
	 * @param array<string,mixed>                                $form      Provider form manifest row.
	 * @param array<int,Static_Site_Importer_Form_Textarea_Rows> $textareas Resolved textarea policies keyed by control index.
	 * @return array<string,mixed>
	 */
	private static function project_textarea_rows_into_presentation_graph( array $form, array $textareas ): array {
		foreach ( $textareas as $control_index => $textarea ) {
			if ( null === $textarea->display && ! $textarea->release_height ) {
				continue;
			}
			$form = self::with_control_styles( $form, $control_index, array( $textarea, 'presentation_styles' ) );
		}
		return $form;
	}

	/**
	 * Replace one control's presentation-graph styles with `$styles( $existing )`,
	 * adding the control row when the graph has none.
	 *
	 * @param array<string,mixed> $form          Provider form manifest row.
	 * @param int|string          $control_index Control index the row is keyed by.
	 * @param callable            $styles        Maps the row's existing styles (empty when absent) to its new styles.
	 * @return array<string,mixed>
	 */
	private static function with_control_styles( array $form, int|string $control_index, callable $styles ): array {
		$controls_graph = isset( $form['presentation_graph']['controls'] ) && is_array( $form['presentation_graph']['controls'] ) ? $form['presentation_graph']['controls'] : array();
		foreach ( $controls_graph as $graph_index => $row ) {
			if ( is_array( $row ) && ( $row['index'] ?? null ) === $control_index ) {
				$existing = isset( $row['control']['styles'] ) && is_array( $row['control']['styles'] ) ? $row['control']['styles'] : array();
				$controls_graph[ $graph_index ]['control']['styles'] = $styles( $existing );
				$form['presentation_graph']['controls']              = $controls_graph;
				return $form;
			}
		}
		$controls_graph[]                       = array(
			'index'   => $control_index,
			'control' => array( 'styles' => $styles( array() ) ),
		);
		$form['presentation_graph']['controls'] = $controls_graph;
		return $form;
	}

	/**
	 * Translate a captured submit presentation style into the flat property
	 * vocabulary `Form_Presentation_Overlay::presentation_property_map()`
	 * resolves into scoped CSS.
	 *
	 * Accepts a WP block-style-object shape (`color.background`,
	 * `typography.fontWeight`, `spacing.padding.top`, ...), the same shape a
	 * source submit button's resolved presentation was previously baked into
	 * saved block attributes as, before that produced saved markup that
	 * disagreed with core/button's own save() output. It also passes through
	 * already-flat computed-presentation keys unchanged, so a producer that
	 * captures this presentation directly in the overlay's own vocabulary
	 * (background_color, font_size, width, ...) does not need translation.
	 *
	 * @param array<string,mixed> $style
	 * @return array<string,string>
	 */
	private static function flatten_submit_style( array $style ): array {
		$flat         = array();
		$property_map = Static_Site_Importer_Form_Presentation_Overlay::presentation_property_map();
		foreach ( $style as $key => $value ) {
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$snake = str_replace( '-', '_', $key );
			if ( isset( $property_map[ $snake ] ) ) {
				$flat[ $snake ] = trim( (string) $value );
			}
		}
		$color = isset( $style['color'] ) && is_array( $style['color'] ) ? $style['color'] : array();
		if ( isset( $color['background'] ) && is_scalar( $color['background'] ) && '' !== trim( (string) $color['background'] ) ) {
			$flat['background_color'] = trim( (string) $color['background'] );
		}
		if ( isset( $color['text'] ) && is_scalar( $color['text'] ) && '' !== trim( (string) $color['text'] ) ) {
			$flat['color'] = trim( (string) $color['text'] );
		}
		$typography     = isset( $style['typography'] ) && is_array( $style['typography'] ) ? $style['typography'] : array();
		$typography_map = array(
			'fontFamily'     => 'font_family',
			'fontSize'       => 'font_size',
			'fontStyle'      => 'font_style',
			'fontWeight'     => 'font_weight',
			'letterSpacing'  => 'letter_spacing',
			'lineHeight'     => 'line_height',
			'textDecoration' => 'text_decoration',
			'textTransform'  => 'text_transform',
		);
		foreach ( $typography_map as $attribute => $property ) {
			if ( isset( $typography[ $attribute ] ) && is_scalar( $typography[ $attribute ] ) && '' !== trim( (string) $typography[ $attribute ] ) ) {
				$flat[ $property ] = trim( (string) $typography[ $attribute ] );
			}
		}
		$border     = isset( $style['border'] ) && is_array( $style['border'] ) ? $style['border'] : array();
		$border_map = array(
			'radius' => 'border_radius',
			'color'  => 'border_color',
			'style'  => 'border_style',
			'width'  => 'border_width',
		);
		foreach ( $border_map as $attribute => $property ) {
			if ( isset( $border[ $attribute ] ) && is_scalar( $border[ $attribute ] ) && '' !== trim( (string) $border[ $attribute ] ) ) {
				$flat[ $property ] = trim( (string) $border[ $attribute ] );
			}
		}
		$spacing = isset( $style['spacing'] ) && is_array( $style['spacing'] ) ? $style['spacing'] : array();
		foreach ( array( 'margin', 'padding' ) as $box ) {
			if ( isset( $spacing[ $box ] ) && is_scalar( $spacing[ $box ] ) && '' !== trim( (string) $spacing[ $box ] ) ) {
				$flat[ $box ] = trim( (string) $spacing[ $box ] );
				continue;
			}
			$values = isset( $spacing[ $box ] ) && is_array( $spacing[ $box ] ) ? $spacing[ $box ] : array();
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				if ( isset( $values[ $side ] ) && is_scalar( $values[ $side ] ) && '' !== trim( (string) $values[ $side ] ) ) {
					$flat[ $box . '_' . $side ] = trim( (string) $values[ $side ] );
				}
			}
		}
		return $flat;
	}
}
