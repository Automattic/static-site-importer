<?php
/**
 * Jetpack contact-form materialization for preserved form runtime islands.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-static-site-importer-computed-layout-strategy.php';
require_once __DIR__ . '/class-static-site-importer-provider-layout-overlay.php';
require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
if ( ! class_exists( 'Static_Site_Importer_Jetpack_Forms_Runtime' ) ) {
	require_once __DIR__ . '/class-static-site-importer-jetpack-forms-runtime.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Field_Markup' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-field-markup.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Layout_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-layout-projection.php';
}

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
					'availability_callback' => array( self::class, 'jetpack_forms_available' ),
					'preparation_callback'  => array( self::class, 'prepare_jetpack_forms_runtime' ),
					'provider_readiness'    => array(
						'required_block_types' => self::required_block_types(),
						'required_classes'     => self::required_runtime_apis(),
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

	/** Move source submit presentation from Core's wrapper onto its button control. */
	public static function project_provider_submit_presentation( string $html, array $block = array() ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_submit_presentation( $html, $block );
	}

	/** Restore the source field row onto the provider field shell. */
	public static function project_provider_wrapper_classes( string $html ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_wrapper_classes( $html );
	}

	/** Carry an authored textarea row count onto Jetpack's hardcoded rows='20'. */
	public static function project_provider_textarea_rows( string $html ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_textarea_rows( $html );
	}

	/** Restore a proven source root fieldset around Jetpack's rendered field list. */
	public static function project_provider_plain_root_fieldset( string $html, array $block = array() ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_plain_root_fieldset( $html, $block );
	}

	/** Carry the provider block's layout role onto Jetpack's rendered page-grid item. */
	public static function project_provider_form_container_placement( string $html, array $block = array() ): string {
		return Static_Site_Importer_Provider_Form_Runtime_V1::project_form_container_placement( $html, $block );
	}

	/**
	 * Load Forms after Jetpack's autoloader is ready and before WordPress init.
	 *
	 * Jetpack 16 skips its normal after_setup_theme module loader for disconnected
	 * sites. Its persisted contact-form module flag therefore needs this adapter
	 * bootstrap on later frontend requests as well as during import preparation.
	 */
	public static function bootstrap_jetpack_forms_runtime(): void {
		Static_Site_Importer_Jetpack_Forms_Runtime::bootstrap_jetpack_forms_runtime();
	}

	/** @return array<int,string> Every Jetpack block type the adapter can emit. */
	public static function required_block_types(): array {
		return Static_Site_Importer_Jetpack_Forms_Runtime::required_block_types();
	}

	/** @return array<int,string> Provider APIs required by the declared adapter. */
	public static function required_runtime_apis(): array {
		return Static_Site_Importer_Jetpack_Forms_Runtime::required_runtime_apis();
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

	/** Derive companion-owned visual state without writing provider entities. */
	public static function visual_states( array $manifest ): array {
		$states = array();
		foreach ( self::manifest_forms( $manifest ) as $form ) {
			$row = self::seed_form( $form, true );
			if ( ! empty( $row['runtime_mapped'] ) && is_array( $row['form_visual_state'] ?? null ) ) {
				$states[] = $row['form_visual_state'];
			}
		}
		return array_values( array_unique( $states, SORT_REGULAR ) );
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
	 * Determine whether the Jetpack Forms runtime is available to host seeded forms.
	 *
	 * Public so the registry availability callback and the dependency gate can run
	 * before forms are materialized into a runtime that can carry submissions.
	 *
	 * @return bool
	 */
	public static function jetpack_forms_available(): bool {
		return Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_available();
	}

	/** Activate and prepare Jetpack Forms through its canonical module lifecycle. */
	public static function prepare_jetpack_forms_runtime() {
		return Static_Site_Importer_Jetpack_Forms_Runtime::prepare_jetpack_forms_runtime();
	}

	/**
	 * Return the specific Jetpack Forms APIs present in the current runtime.
	 *
	 * @return array<string,mixed>
	 */
	public static function jetpack_forms_availability_details(): array {
		return Static_Site_Importer_Jetpack_Forms_Runtime::jetpack_forms_availability_details();
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
				static fn ( $form ): bool => is_array( $form )
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
		$form        = Static_Site_Importer_Form_Layout_Projection::normalize_unconditional_layout_variants( $form );
		$controls    = isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array();
		$form        = self::project_submit_style_into_presentation_graph( $form, $controls );
		$form        = self::project_textarea_row_height_into_presentation_graph( $form, $controls );
		$selector    = isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '';
		$source_path = isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '';

		$scope                         = Static_Site_Importer_Form_Layout_Projection::layout_scope( $form );
		$presentation_roles            = Static_Site_Importer_Form_Layout_Projection::presentation_roles( is_array( $form['presentation_graph'] ?? null ) ? $form['presentation_graph'] : array() );
		$presentation_descriptors      = array();
		$field_blocks                  = array();
		$mapped_types                  = array();
		$submit_text                   = 'Submit';
		$skipped                       = array();
		$control_attribute_losses      = array();
		$has_topology                  = isset( $form['control_topology'] );
		$has_source_submit             = false;
		$textarea_height_omitted_count = (int) ( $form['form']['textarea_height_omitted_count'] ?? 0 );
		$radio_groups                  = Static_Site_Importer_Form_Field_Markup::labelled_radio_groups( $form, $controls );
		$suppressed_controls           = $radio_groups['suppressed_controls'];
		if ( ! empty( $form['form']['interleaved_context'] ) ) {
			return array(
				'selector'       => $selector,
				'source_path'    => $source_path,
				'provider'       => self::PROVIDER_ID,
				'block_name'     => 'jetpack/contact-form',
				'status'         => 'skipped',
				'reason'         => 'interleaved_context_unrepresentable',
				'runtime_mapped' => false,
			);
		}
		$submit_presentation = isset( $form['form']['submit_presentation'] ) && is_array( $form['form']['submit_presentation'] ) ? $form['form']['submit_presentation'] : array();
		if ( is_string( $submit_presentation['text'] ?? null ) && '' !== trim( $submit_presentation['text'] ) ) {
			$submit_text = trim( $submit_presentation['text'] );
		}

		foreach ( $controls as $control_index => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
			$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
			if ( self::is_hidden_bookkeeping_control( $form, $control_index, $control, $tag, $type ) ) {
				$suppressed_controls[ $control_index ] = true;
				$skipped[]                             = 'hidden_bookkeeping';
				continue;
			}
			$presentation_descriptors[ $control_index ] = Static_Site_Importer_Form_Layout_Projection::presentation_descriptor( $scope, $control_index, $type, $presentation_roles[ $control_index ] ?? array() );
			if ( isset( $suppressed_controls[ $control_index ] ) ) {
				continue;
			}
			if ( isset( $radio_groups['groups'][ $control_index ] ) ) {
				$control = $radio_groups['groups'][ $control_index ];
			}

			$presentation_descriptor = $presentation_descriptors[ $control_index ];
			$is_auxiliary_button     = 'button' === $tag && Static_Site_Importer_Form_Field_Markup::is_provider_auxiliary_button( $controls, $control_index );
			if ( $is_auxiliary_button ) {
				// The provider owns this control as part of a field rather than as an action.
				continue;
			}

			if ( 'submit' === $type || ( 'button' === $tag && 'submit' === $type ) ) {
				$text              = Static_Site_Importer_Form_Field_Markup::control_text( $control );
				$submit_text       = '' !== $text ? $text : $submit_text;
				$has_source_submit = true;
				if ( isset( $control['presentation']['style'] ) && is_array( $control['presentation']['style'] ) ) {
					$submit_presentation['block_attrs']['style'] = $control['presentation']['style'];
				}
				if ( empty( $submit_presentation['classes'] ) && isset( $control['class'] ) && is_scalar( $control['class'] ) ) {
					$submit_classes                 = preg_split( '/\s+/', trim( (string) $control['class'] ) );
					$submit_presentation['classes'] = false === $submit_classes ? array() : $submit_classes;
				}
				// The compiler reports the label element a source button owns, including
				// when it declares no classes, because authored rules address it as a
				// descendant of the button.
				if ( ! isset( $submit_presentation['label_classes'] ) && isset( $control['label_classes'] ) && is_scalar( $control['label_classes'] ) ) {
					$label_classes                        = preg_split( '/\s+/', trim( (string) $control['label_classes'] ) );
					$submit_presentation['label_classes'] = false === $label_classes ? array() : array_values( array_filter( $label_classes ) );
				}
				if ( ! isset( $submit_presentation['label_marker'] ) && isset( $control['label_marker'] ) && is_scalar( $control['label_marker'] ) ) {
					$submit_presentation['label_marker'] = trim( (string) $control['label_marker'] );
				}
				if ( $has_topology ) {
					$presentation_class             = $presentation_descriptor['control_class'];
					$field_blocks[ $control_index ] = Static_Site_Importer_Form_Field_Markup::submit_button_block( $submit_text, trim( Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-' . $control_index ) . ' ' . $presentation_class ), $submit_presentation );
				}
				continue;
			}
			if ( 'button' === $tag && 'button' === $type ) {
				// Mode and filter controls remain native buttons; they are not submits.
				$field_blocks[ $control_index ] = Static_Site_Importer_Form_Field_Markup::button_block(
					Static_Site_Importer_Form_Field_Markup::control_text( $control ),
					trim( (string) ( $control['class'] ?? '' ) . ' ' . Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-' . $control_index ) . ' ' . $presentation_descriptor['control_class'] )
				);
				continue;
			}

			$control_phone_destinations = $presentation_descriptor['phone_destinations'];
			$field_block                = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
				$tag,
				$type,
				$control,
				empty( $control_phone_destinations ) ? $presentation_descriptor['control_class'] : '',
				$presentation_descriptor['label_class']
			);
			if ( null === $field_block ) {
				$skipped[] = '' !== $type ? $type : $tag;
				continue;
			}
			// Source responsive documents can repeat IDs. Provider state is keyed by
			// field ID, so its identity must belong to this materialized form instance.
			$field_block['attrs']['id'] = $scope . '-field-' . $control_index;
			if ( ! empty( $control_phone_destinations ) ) {
				foreach ( $field_block['innerBlocks'] as &$inner_block ) {
					if ( 'jetpack/phone-input' === ( $inner_block['name'] ?? '' ) ) {
						$inner_block['attrs']['className'] = trim( (string) ( $inner_block['attrs']['className'] ?? '' ) . ' ' . implode( ' ', array_column( $control_phone_destinations, 'class' ) ) );
					}
				}
				unset( $inner_block );
			}
			foreach ( $field_block['losses'] ?? array() as $loss ) {
				$control_attribute_losses[] = $loss + array( 'control_index' => $control_index );
			}
			unset( $field_block['losses'] );

			$source_class                      = isset( $control['class'] ) && is_scalar( $control['class'] ) ? trim( (string) $control['class'] ) : '';
			$has_provider_input                = (bool) array_filter( $field_block['innerBlocks'] ?? array(), static fn ( array $block ): bool => in_array( $block['name'] ?? '', array( 'jetpack/input', 'jetpack/phone-input' ), true ) );
			$field_source_class                = $has_provider_input ? '' : $source_class;
			$layout_hook                       = Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-' . $control_index );
			$field_block['attrs']['className'] = trim( $field_source_class . ' ' . $layout_hook );
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
			$field_blocks[ $control_index ] = $field_block;
			$mapped_types[]                 = $field_block['name'];
		}

		if ( empty( $field_blocks ) ) {
			return array(
				'selector'       => $selector,
				'source_path'    => $source_path,
				'provider'       => self::PROVIDER_ID,
				'block_name'     => 'jetpack/contact-form',
				'status'         => 'skipped',
				'reason'         => 'no_mappable_form_fields',
				'runtime_mapped' => false,
				'skipped_types'  => array_values( array_unique( array_filter( $skipped ) ) ),
			);
		}

		$topology = Static_Site_Importer_Form_Layout_Projection::topology_inner_blocks( $form, $field_blocks, $controls, $suppressed_controls );
		if ( null === $topology ) {
			return array(
				'selector'       => $selector,
				'source_path'    => $source_path,
				'provider'       => self::PROVIDER_ID,
				'block_name'     => 'jetpack/contact-form',
				'status'         => 'skipped',
				'reason'         => 'unsupported_control_topology',
				'runtime_mapped' => false,
			);
		}
		$inner_blocks = $topology['blocks'];
		if ( ! $has_topology || ! $has_source_submit ) {
			$inner_blocks[] = Static_Site_Importer_Form_Field_Markup::submit_button_block( $submit_text, Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-submit' ), $submit_presentation );
		}
		$form['topology_losses']            = $topology['losses'];
		$form['represented_semantic_nodes'] = $radio_groups['represented_semantic_nodes'];
		$provider_graph                     = is_array( $form['layout_graph'] ?? null ) ? $form['layout_graph'] : array(
			'nodes'    => array(),
			'variants' => array(),
		);
		if ( ! empty( $topology['represented_layout_nodes'] ) ) {
			$represented                = array_fill_keys( $topology['represented_layout_nodes'], true );
			$provider_graph['nodes']    = array_values( array_filter( $provider_graph['nodes'] ?? array(), static fn ( $node ): bool => ! is_array( $node ) || ! isset( $represented[ $node['id'] ?? '' ] ) ) );
			$provider_graph['variants'] = array_values( array_filter( $provider_graph['variants'] ?? array(), static fn ( $variant ): bool => ! is_array( $variant ) || ! isset( $represented[ $variant['node'] ?? '' ] ) ) );
		}
		foreach ( $topology['suppressed_layout_properties'] as $node_id => $properties ) {
			foreach ( $provider_graph['nodes'] as &$provider_node ) {
				if ( is_array( $provider_node ) && ( $provider_node['id'] ?? null ) === $node_id && is_array( $provider_node['layout'] ?? null ) ) {
					$provider_node['layout'] = array_diff_key( $provider_node['layout'], array_fill_keys( $properties, true ) );
				}
			}
			unset( $provider_node );
		}
		$native_visibility_targets = array_fill_keys( $topology['native_visibility_targets'], true );
		foreach ( $provider_graph['nodes'] as &$provider_node ) {
			if ( is_array( $provider_node ) && isset( $native_visibility_targets[ $provider_node['id'] ?? '' ] ) ) {
				unset( $provider_node['layout']['display'] );
			}
		}
		unset( $provider_node );
		$layout_form                                        = $form;
		$layout_form['layout_graph']                        = $provider_graph;
		$layout_form['provider_represented_topology_nodes'] = $topology['represented_topology_nodes'];
		$layout = Static_Site_Importer_Computed_Layout_Strategy::apply( $layout_form, $inner_blocks );
		if ( 0 < $textarea_height_omitted_count ) {
			$control_attribute_losses[] = array(
				'dimension'     => 'control',
				'reason_code'   => 'textarea_height_omitted',
				'omitted_count' => $textarea_height_omitted_count,
			);
		}
		$host = Static_Site_Importer_Form_Layout_Projection::host_wrapper_projection( $form );
		self::append_receipt_entries( $layout['receipt'], 'operations', array_merge( $radio_groups['operations'], $topology['operations'], $host['operations'] ) );
		self::append_receipt_entries( $layout['receipt'], 'losses', $control_attribute_losses );
		$inner_blocks           = $layout['blocks'];
		$form_attrs             = Static_Site_Importer_Form_Field_Markup::contact_form_attributes( $form, $scope, array_merge( $topology['form_classes'], $host['classes'] ) );
		$overlay_graph          = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( Static_Site_Importer_Form_Layout_Projection::split_form_box( $provider_graph ), is_array( $form['layout_graph'] ?? null ) ? $form['layout_graph'] : array() );
		$box_targets            = $topology['provider_layout_targets'];
		$overlay_graph['nodes'] = array_values( array_filter( $overlay_graph['nodes'] ?? array(), static fn ( $node ): bool => is_array( $node ) && ( 'form' === ( $node['id'] ?? '' ) || 'form-box' === ( $node['id'] ?? '' ) || isset( $box_targets[ (string) ( $node['id'] ?? '' ) ] ) || preg_match( '/^control-[0-9]+$/D', (string) ( $node['id'] ?? '' ) ) ) ) );
		foreach ( $topology['overlay_node_targets'] as $target ) {
			$merged = false;
			foreach ( $overlay_graph['nodes'] as &$overlay_node ) {
				if ( is_array( $overlay_node ) && ( $overlay_node['id'] ?? null ) === ( $target['id'] ?? null ) ) {
					$overlay_node['layout'] = array_merge( is_array( $overlay_node['layout'] ?? null ) ? $overlay_node['layout'] : array(), $target['layout'] );
					if ( ! empty( $target['important'] ) && is_array( $target['important'] ) ) {
						$overlay_node['important'] = array_values( array_unique( array_merge( is_array( $overlay_node['important'] ?? null ) ? $overlay_node['important'] : array(), $target['important'] ) ) );
					}
					$merged = true;
					break;
				}
			}
			unset( $overlay_node );
			if ( ! $merged ) {
				$overlay_graph['nodes'][] = $target;
			}
		}
		foreach ( Static_Site_Importer_Form_Layout_Projection::direct_label_control_gap_targets( $form ) as $target ) {
			$control_index = $target['control'];
			$layout_patch  = $target['layout'];
			// Jetpack renders a label and input as siblings in its field wrapper.
			// Keep generic control layout on the input and target this relationship
			// specifically at the generated wrapper.
			$target_id = 'field-' . $control_index;
			if ( isset( $target['condition'] ) ) {
				if ( ! array_filter( $overlay_graph['nodes'], static fn( $node ): bool => is_array( $node ) && ( $node['id'] ?? null ) === $target_id ) ) {
					$overlay_graph['nodes'][] = array(
						'id'     => $target_id,
						'layout' => array(),
					);
				}
				$overlay_graph['variants'][] = array(
					'node'         => $target_id,
					'condition'    => $target['condition'],
					'layout_patch' => $layout_patch,
				);
				continue;
			}
			$merged = false;
			foreach ( $overlay_graph['nodes'] as &$overlay_node ) {
				if ( is_array( $overlay_node ) && ( $overlay_node['id'] ?? null ) === $target_id ) {
					$overlay_node['layout'] = array_merge( $overlay_node['layout'] ?? array(), $layout_patch );
					$merged                 = true;
					break;
				}
			}
			unset( $overlay_node );
			if ( ! $merged ) {
				$overlay_graph['nodes'][] = array(
					'id'     => $target_id,
					'layout' => $layout_patch,
				);
			}
			$layout['receipt']['operations'][] = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_direct_label_control_gap',
				'target_hash' => hash( 'sha256', $target_id ),
			);
		}
		foreach ( $overlay_graph['nodes'] as &$overlay_node ) {
			if ( ! is_array( $overlay_node ) || ! preg_match( '/^(?:control|wrapper)-[0-9]+$/D', (string) ( $overlay_node['id'] ?? '' ) ) || ! Static_Site_Importer_Form_Layout_Projection::fixed_width_uses_default_flex( $overlay_node ) ) {
				continue;
			}
			$overlay_node['layout']['flex'] = '0 1 auto';
		}
		unset( $overlay_node );
		$overlay_graph['variants'] = array_merge( $overlay_graph['variants'] ?? array(), $topology['responsive_variant_targets'] );
		foreach ( $overlay_graph['variants'] as &$overlay_variant ) {
			if ( ! is_array( $overlay_variant ) || ! preg_match( '/^(?:control|wrapper)-[0-9]+$/D', (string) ( $overlay_variant['node'] ?? '' ) ) || ! Static_Site_Importer_Form_Layout_Projection::fixed_width_uses_default_flex( $overlay_variant ) ) {
				continue;
			}
			$overlay_variant['layout_patch']['flex'] = '0 1 auto';
		}
		unset( $overlay_variant );
		$overlay_node_ids = array_fill_keys( array_map( static fn( array $node ): string => (string) $node['id'], $overlay_graph['nodes'] ), true );
		foreach ( $topology['responsive_variant_targets'] as $variant ) {
			if ( is_string( $variant['node'] ?? null ) && ! isset( $overlay_node_ids[ $variant['node'] ] ) ) {
				$overlay_graph['nodes'][]             = array(
					'id'     => $variant['node'],
					'layout' => array(),
				);
				$overlay_node_ids[ $variant['node'] ] = true;
			}
		}
		$overlay_nodes             = array_fill_keys( array_map( static fn ( array $node ): string => (string) $node['id'], $overlay_graph['nodes'] ), true );
		$overlay_graph['variants'] = array_values( array_filter( $overlay_graph['variants'], static fn ( $variant ): bool => is_array( $variant ) && isset( $overlay_nodes[ $variant['node'] ?? '' ] ) ) );
		$layout_intent             = Static_Site_Importer_Form_Layout_Projection::form_layout_intent( $form );
		foreach ( $layout_intent['nodes'] as $node ) {
			if ( ! isset( $overlay_nodes[ $node['id'] ] ) ) {
				$overlay_graph['nodes'][]     = $node;
				$overlay_nodes[ $node['id'] ] = true;
			}
		}
		foreach ( $layout_intent['variants'] as $variant ) {
			$node = $variant['node'];
			if ( ! isset( $overlay_nodes[ $node ] ) ) {
				$overlay_graph['nodes'][] = array(
					'id'     => $node,
					'layout' => array(),
				);
				$overlay_nodes[ $node ]   = true;
			}
			$overlay_graph['variants'][] = $variant;
		}
		// Box transposition and responsive targets reinstate the source container's
		// own layout, so the track definition is reconsidered once every box and
		// variant has been merged.
		$overlay_graph                = Static_Site_Importer_Form_Layout_Projection::without_shared_source_grid_rows( $overlay_graph, is_array( $form['layout_graph'] ?? null ) ? $form['layout_graph'] : array() );
		$overlay_form                 = $form;
		$overlay_form['layout_graph'] = $overlay_graph;
		foreach ( array_keys( $suppressed_controls ) as $control_index ) {
			unset( $overlay_form['presentation_graph']['controls'][ $control_index ] );
		}
		$visual_state           = Static_Site_Importer_Form_Layout_Projection::empty_country_visual_state( $form, $scope, $topology['phone_popup_targets'] );
		$target_map             = Static_Site_Importer_Form_Layout_Projection::provider_layout_target_map( $overlay_form, $scope, $presentation_descriptors, $box_targets, $topology['phone_popup_targets'], $visual_state['trigger_class'] ?? '' );
		$presentation_graph     = is_array( $overlay_form['presentation_graph'] ?? null ) ? $overlay_form['presentation_graph'] : array();
		$container_presentation = is_array( $form['form']['container_presentation'] ?? null ) ? $form['form']['container_presentation'] : array();
		// The captured form box's own padding/margin/etc. is bounded, source-CSS-cascade
		// evidence carried the same way every other captured control already is (see
		// Provider_Layout_Overlay's `generic/form-container-presentation/v1` destination).
		// It belongs on the rendered page, not only inside editor chrome, so the frontend
		// compile also receives it.
		$overlay        = Static_Site_Importer_Provider_Layout_Overlay::compile( $overlay_graph, $target_map, $presentation_graph, $container_presentation );
		$overlay        = Static_Site_Importer_Form_Layout_Projection::collapse_inactive_provider_errors( $overlay, $scope, $mapped_types );
		$editor_map     = Static_Site_Importer_Form_Layout_Projection::editor_layout_target_map( $target_map, $scope );
		$editor_overlay = Static_Site_Importer_Provider_Layout_Overlay::compile( $overlay_graph, $editor_map, $presentation_graph, $container_presentation, true );
		if ( isset( $editor_overlay['overlay']['editor_css'] ) ) {
			foreach ( array( 'editor_css', 'editor_sha256', 'editor_bytes' ) as $key ) {
				$overlay['overlay'][ $key ] = $editor_overlay['overlay'][ $key ];
			}
		}
		self::append_receipt_entries( $layout['receipt'], 'losses', $editor_overlay['losses'] );
		self::append_receipt_entries( $layout['receipt'], 'operations', $overlay['operations'] );
		self::append_receipt_entries( $layout['receipt'], 'operations', $layout_intent['operations'] );
		self::append_receipt_entries( $layout['receipt'], 'losses', $overlay['losses'] );
		$layout['receipt']['status'] = 0 < $layout['receipt']['operations_total'] ? 'applied' : ( 0 < $layout['receipt']['losses_total'] ? 'deferred' : 'skipped' );
		$status                      = $form['form']['trailing_status'] ?? null;
		if ( is_array( $status ) && 'status' === ( $status['role'] ?? null ) ) {
			// Output provides the native status role without a raw HTML block.
			$attrs = array(
				'tagName'      => 'output',
				'templateLock' => 'all',
				'metadata'     => array(
					'name' => 'Form status',
				),
			);
			if ( is_string( $status['id'] ?? null ) && preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $status['id'] ) ) {
				$attrs['anchor'] = $status['id'];
			}
			foreach ( array( 'top', 'bottom' ) as $side ) {
				if ( is_string( $status[ 'margin_' . $side ] ?? null ) && preg_match( '/^(?:-?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vh|vw|%)|0)$/D', $status[ 'margin_' . $side ] ) ) {
					$attrs['style']['spacing']['margin'][ $side ] = $status[ 'margin_' . $side ];
				}
			}
			$inner_blocks[] = array(
				'name'  => 'core/group',
				'attrs' => $attrs,
			);
		}
		$inner_blocks = array_merge(
			Static_Site_Importer_Form_Field_Markup::context_blocks( $form, 'context_before' ),
			$inner_blocks,
			Static_Site_Importer_Form_Field_Markup::context_blocks( $form, 'context_after' )
		);
		$markup       = Static_Site_Importer_Form_Field_Markup::serialize_block(
			array(
				'name'        => 'jetpack/contact-form',
				'attrs'       => $form_attrs,
				'innerBlocks' => $inner_blocks,
			)
		);
		$row    = array(
			'selector'                    => $selector,
			'source_path'                 => $source_path,
			'provider'                    => self::PROVIDER_ID,
			'block_name'                  => 'jetpack/contact-form',
			'status'                      => 'mapped',
			'field_count'                 => count( $mapped_types ),
			'field_blocks'                => $mapped_types,
			'skipped_types'               => array_values( array_unique( array_filter( $skipped ) ) ),
			'submit_text'                 => $submit_text,
			'runtime_mapped'              => true,
			'runtime_carried'             => $available,
			'block_markup'                => $markup,
			'computed_layout_receipt'     => $layout['receipt'],
			'provider_layout_target_map'  => $target_map,
			'provider_layout_overlay_css' => $overlay['overlay'],
		);
		if ( ! empty( $visual_state['state'] ) ) {
			$row['form_visual_state'] = $visual_state['state'];
		}
		if ( ! empty( $visual_state['diagnostics'] ) ) {
			$row['form_visual_state_diagnostics'] = $visual_state['diagnostics'];
		}
		$unaccepted_losses   = array_values(
			array_filter(
				$layout['receipt']['losses'] ?? array(),
				static fn( $loss ): bool => is_array( $loss ) && self::receipt_loss_requires_gate( $loss ) && ! self::provider_represents_receipt_loss( $loss, $form, $field_blocks, $target_map ) && true !== apply_filters( 'static_site_importer_form_receipt_loss_accepted', false, $loss, $form, $row )
			)
		);
		$gate_overflow_count = (int) ( $layout['receipt']['gate_required_loss_overflow_count'] ?? 0 );
		if ( $gate_overflow_count > 0 ) {
			$unaccepted_losses[] = array(
				'dimension'   => 'topology',
				'reason_code' => 'form_receipt_gate_loss_overflow',
				'loss_count'  => $gate_overflow_count,
				'loss_hash'   => (string) ( $layout['receipt']['gate_required_loss_overflow_hash'] ?? '' ),
			);
		}
		if ( ! empty( $unaccepted_losses ) ) {
			// The layout-fidelity gate is a decision not to represent this one form
			// with the provider, exactly like an unsupported control topology. The
			// converted source form stays in the page, so this is a per-entity
			// decline the registry degrades around, never an import failure.
			$row['provider_mapped']                = true;
			$row['runtime_mapped']                 = false;
			$row['status']                         = 'skipped';
			$row['reason']                         = 'form_receipt_loss_unaccepted';
			$row['form_receipt_unaccepted_losses'] = $unaccepted_losses;
			$row['unaccepted_receipt_loss_count']  = count( $unaccepted_losses );
		}
		return $row;
	}

	/**
	 * Provider forms omit source-only bookkeeping controls that cannot receive input.
	 *
	 * Some site builders use a visually hidden text field instead of type="hidden".
	 * Treat it as bookkeeping only when its private name and absent user-facing affordances
	 * prove it is not an authored field the provider must reproduce.
	 */
	private static function is_hidden_bookkeeping_control( array $form, int $control_index, array $control, string $tag, string $type ): bool {
		if ( 'input' !== $tag || ! in_array( $type, array( '', 'text', 'hidden' ), true ) || ! preg_match( '/^_[a-z0-9_-]+$/iD', (string) ( $control['name'] ?? '' ) ) || '' !== trim( (string) ( $control['label'] ?? '' ) ) || '' !== trim( (string) ( $control['placeholder'] ?? '' ) ) ) {
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
	 * existing `Provider_Layout_Overlay` pipeline - already proven for labels,
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
			$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
			if ( 'submit' !== $type && ! ( 'button' === $tag && 'submit' === $type ) ) {
				continue;
			}
			$style = isset( $control['presentation']['style'] ) && is_array( $control['presentation']['style'] ) ? $control['presentation']['style'] : array();
			$flat  = self::flatten_submit_style( $style );
			if ( empty( $flat ) ) {
				continue;
			}
			$controls_graph = isset( $form['presentation_graph']['controls'] ) && is_array( $form['presentation_graph']['controls'] ) ? $form['presentation_graph']['controls'] : array();
			$found          = false;
			foreach ( $controls_graph as &$row ) {
				if ( ! is_array( $row ) || ( $row['index'] ?? null ) !== $control_index ) {
					continue;
				}
				$found           = true;
				$existing_styles = isset( $row['control']['styles'] ) && is_array( $row['control']['styles'] ) ? $row['control']['styles'] : array();
				// Cascade-resolved facts fill what this capture omits (background from
				// a utility the style object did not reify). Authored style wins on
				// conflict: a preflight `padding:0` / `font-weight:inherit` reset is
				// not the button's own box.
				$row['control']['styles'] = array_merge( $existing_styles, $flat );
				if ( array_intersect_key( $flat, array_flip( array( 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block', 'padding_inline' ) ) ) && ! isset( $flat['padding'] ) ) {
					unset( $row['control']['styles']['padding'] );
				}
				break;
			}
			unset( $row );
			if ( ! $found ) {
				$controls_graph[] = array(
					'index'   => $control_index,
					'control' => array( 'styles' => $flat ),
				);
			}
			$form['presentation_graph']['controls'] = $controls_graph;
		}
		return $form;
	}

	/**
	 * Neutralize this provider's fixed textarea height so the authored `rows`
	 * attribute can size the control.
	 *
	 * A source textarea sized by its own `rows` attribute (rather than an
	 * authored CSS height) carries no CSS declaration a source-CSS-cascade
	 * compiler could capture. blocks-engine still reports this row count
	 * (`effectiveRows()`, always populated, defaulting to the browser's own
	 * unset-`rows` default of 2). Jetpack's renderer hardcodes `rows='20'`
	 * and paints `:where(.contact-form textarea){height:200px}`, so the
	 * authored count never reaches the visitor. The row count itself is
	 * carried onto `jetpack/input` as `ssi-textarea-rows-N` and rewritten
	 * onto the rendered control at runtime. This projection only overrides
	 * the provider height with `auto` so the browser sizes N line boxes
	 * using the padding, border, font-size, and line-height already carried
	 * onto the same control. A cascade-resolved height or minimum height
	 * already captured for this control is authoritative and this
	 * projection does not run.
	 *
	 * @param array<string,mixed> $form Provider form manifest row.
	 * @param array<int,mixed>    $controls Normalized controls list.
	 * @return array<string,mixed>
	 */
	private static function project_textarea_row_height_into_presentation_graph( array $form, array $controls ): array {
		foreach ( $controls as $control_index => $control ) {
			if ( ! is_array( $control ) || 'textarea' !== strtolower( trim( (string) ( $control['tag'] ?? '' ) ) ) || null === Static_Site_Importer_Form_Field_Markup::textarea_rows( $control ) ) {
				continue;
			}
			$controls_graph  = isset( $form['presentation_graph']['controls'] ) && is_array( $form['presentation_graph']['controls'] ) ? $form['presentation_graph']['controls'] : array();
			$existing_styles = array();
			$row_index       = null;
			foreach ( $controls_graph as $graph_index => $row ) {
				if ( is_array( $row ) && ( $row['index'] ?? null ) === $control_index ) {
					$existing_styles = isset( $row['control']['styles'] ) && is_array( $row['control']['styles'] ) ? $row['control']['styles'] : array();
					$row_index       = $graph_index;
					break;
				}
			}
			if ( isset( $existing_styles['height'] ) || isset( $existing_styles['min_height'] ) ) {
				continue;
			}
			if ( null !== $row_index ) {
				$controls_graph[ $row_index ]['control']['styles']['height'] = 'auto';
			} else {
				$controls_graph[] = array(
					'index'   => $control_index,
					'control' => array( 'styles' => array( 'height' => 'auto' ) ),
				);
			}
			$form['presentation_graph']['controls'] = $controls_graph;
		}
		return $form;
	}

	/**
	 * Translate a captured submit presentation style into the flat property
	 * vocabulary `Provider_Layout_Overlay::presentation_property_map()`
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
		$property_map = Static_Site_Importer_Provider_Layout_Overlay::presentation_property_map();
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

	/** A source label wrapper is carried by the mapped Jetpack field's label child. */
	private static function provider_represents_receipt_loss( array $loss, array $form, array $field_blocks, array $target_map = array() ): bool {
		if ( 'provider_wrapper_layout_unrepresentable' === ( $loss['reason_code'] ?? '' ) && is_string( $loss['node_hash'] ?? null ) ) {
			$targets = is_array( $target_map['targets'] ?? null ) ? $target_map['targets'] : array();
			foreach ( $targets as $target ) {
				if ( ! is_array( $target ) || ! is_string( $target['node'] ?? null ) || hash( 'sha256', $target['node'] ) !== $loss['node_hash'] || ! is_array( $target['capabilities'] ?? null ) ) {
					continue;
				}
				// A target with all layout capabilities is an adapter-owned replacement
				// for this exact source wrapper, including its responsive variants.
				if ( array_diff( array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' ), $target['capabilities'] ) === array() ) {
					return true;
				}
			}
		}
		if ( 'unsupported_semantic_wrapper' !== ( $loss['reason_code'] ?? '' ) || ! is_string( $loss['node_hash'] ?? null ) ) {
			return false;
		}
		$nodes = isset( $form['control_topology']['nodes'] ) && is_array( $form['control_topology']['nodes'] ) ? $form['control_topology']['nodes'] : array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? '' ) || hash( 'sha256', (string) ( $node['id'] ?? '' ) ) !== $loss['node_hash'] ) {
				continue;
			}
			$tag = (string) ( $node['tag'] ?? 'div' );
			if ( in_array( $tag, array( 'ul', 'ol', 'li' ), true ) ) {
				return true;
			}
			if ( 'fieldset' === $tag && Static_Site_Importer_Form_Layout_Projection::projectable_plain_root_fieldset( $node, $nodes, $field_blocks ) ) {
				return true;
			}
			if ( 'label' !== $tag ) {
				return false;
			}
			$nodes_by_id = array_column( $nodes, null, 'id' );
			$controls    = array_values(
				array_filter(
					$nodes,
					static function ( $candidate ) use ( $node, $nodes_by_id, $field_blocks ): bool {
						if ( ! is_array( $candidate ) || 'control' !== ( $candidate['kind'] ?? '' ) || ! is_int( $candidate['control'] ?? null ) || ! isset( $field_blocks[ $candidate['control'] ] ) ) {
							return false;
						}
						$parent = $candidate['parent'] ?? null;
						while ( is_string( $parent ) ) {
							if ( ( $node['id'] ?? null ) === $parent ) {
								return true;
							}
							$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
						}
						return false;
					}
				)
			);
			return 1 === count( $controls );
		}
		return false;
	}

	/** Append bounded receipt entries without discarding pre-existing overflow totals. */
	private static function append_receipt_entries( array &$receipt, string $key, array $entries ): void {
		$total_key             = 'operations' === $key ? 'operations_total' : 'losses_total';
		$count_key             = 'operations' === $key ? 'operation_count' : 'loss_count';
		$receipt[ $key ]       = isset( $receipt[ $key ] ) && is_array( $receipt[ $key ] ) ? $receipt[ $key ] : array();
		$receipt[ $total_key ] = isset( $receipt[ $total_key ] ) ? (int) $receipt[ $total_key ] : count( $receipt[ $key ] );
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			++$receipt[ $total_key ];
			if ( count( $receipt[ $key ] ) < 32 ) {
				$receipt[ $key ][] = $entry;
			} elseif ( 'losses' === $key && self::receipt_loss_requires_gate( $entry ) ) {
				$receipt['gate_required_loss_overflow_count'] = (int) ( $receipt['gate_required_loss_overflow_count'] ?? 0 ) + 1;
				$receipt['gate_required_loss_overflow_hash']  = hash( 'sha256', (string) ( $receipt['gate_required_loss_overflow_hash'] ?? '' ) . wp_json_encode( $entry ) );
				if ( ! array_filter( $receipt[ $key ], array( self::class, 'receipt_loss_requires_gate' ) ) ) {
					$receipt[ $key ][31] = $entry;
				}
			}
		}
		$receipt[ $count_key ] = min( 32, $receipt[ $total_key ] );
		$receipt['truncated']  = ! empty( $receipt['truncated'] ) || (int) ( $receipt['operations_total'] ?? 0 ) > 32 || (int) ( $receipt['losses_total'] ?? 0 ) > 32;
	}

	/** Keep the bounded receipt aligned with the form finding acceptance gate. */
	private static function receipt_loss_requires_gate( array $loss ): bool {
		return 'unsupported_control_unrepresentable' === ( $loss['reason_code'] ?? '' )
			|| 'unsupported_control_attribute' === ( $loss['reason_code'] ?? '' )
			|| 'textarea_height_omitted' === ( $loss['reason_code'] ?? '' )
			|| in_array( $loss['dimension'] ?? '', array( 'semantic', 'topology' ), true )
			|| in_array( $loss['reason_code'] ?? '', array( 'provider_structure_mismatch', 'direct_child_relationship_unrepresentable' ), true );
	}
}
