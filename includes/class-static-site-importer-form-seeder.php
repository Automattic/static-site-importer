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

	/** Whether this process has explicitly completed the late Jetpack Forms init. */
	private static bool $jetpack_forms_initialized = false;

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

	/** Register the provider bootstrap needed on every WordPress request. */
	public static function register_runtime_bootstrap(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'jetpack_loaded', array( __CLASS__, 'bootstrap_jetpack_forms_runtime' ) );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'grunion_contact_form_field_html', array( __CLASS__, 'project_provider_wrapper_classes' ) );
		}
	}

	/** Project source wrapper classes onto Jetpack's existing field wrapper. */
	public static function project_provider_wrapper_classes( string $html ): string {
		$projected = preg_replace_callback(
			'/\bclass=(["\'])(.*?)\1/s',
			static function ( array $matches ): string {
				$classes    = preg_split( '/\s+/', trim( $matches[2] ) ) ?: array();
				$is_wrapper = (bool) array_filter( $classes, static fn ( string $class ): bool => 1 === preg_match( '/^grunion-field-[A-Za-z0-9_-]+-wrap$/D', $class ) );
				$output     = array();
				foreach ( $classes as $class ) {
					if ( str_starts_with( $class, 'ssi-source-wrapper--' ) ) {
						if ( $is_wrapper && str_ends_with( $class, '-wrap' ) ) {
							$source_class = substr( $class, strlen( 'ssi-source-wrapper--' ), -strlen( '-wrap' ) );
							if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $source_class ) ) {
								$output[] = $source_class;
							}
						}
						continue;
					}
					$output[] = $class;
				}
				return 'class=' . $matches[1] . implode( ' ', array_values( array_unique( $output ) ) ) . $matches[1];
			},
			$html
		);
		return is_string( $projected ) ? $projected : $html;
	}

	/**
	 * Load Forms after Jetpack's autoloader is ready and before WordPress init.
	 *
	 * Jetpack 16 skips its normal after_setup_theme module loader for disconnected
	 * sites. Its persisted contact-form module flag therefore needs this adapter
	 * bootstrap on later frontend requests as well as during import preparation.
	 */
	public static function bootstrap_jetpack_forms_runtime(): void {
		if ( ! self::runtime_static_method_exists( 'Jetpack', 'is_module_active' ) || ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( 'contact-form' ) ) ) {
			return;
		}

		$loader = 'Automattic\\Jetpack\\Forms\\Jetpack_Forms';
		if ( self::runtime_static_method_exists( $loader, 'load_contact_form' ) ) {
			self::invoke_runtime_static_method( $loader, 'load_contact_form' );
		}
	}

	/**
	 * Map a source control type to a Jetpack field block name.
	 *
	 * @return array<string,string>
	 */
	private static function field_block_map(): array {
		return array(
			'text'     => 'jetpack/field-text',
			'search'   => 'jetpack/field-text',
			'password' => 'jetpack/field-text',
			'number'   => 'jetpack/field-number',
			'email'    => 'jetpack/field-email',
			'tel'      => 'jetpack/field-telephone',
			'url'      => 'jetpack/field-url',
			'date'     => 'jetpack/field-date',
			'textarea' => 'jetpack/field-textarea',
			'select'   => 'jetpack/field-select',
			'checkbox' => 'jetpack/field-checkbox',
			'radio'    => 'jetpack/field-radio',
		);
	}

	/** @return array<int,string> Every Jetpack block type the adapter can emit. */
	public static function required_block_types(): array {
		return array_values( array_unique( array_merge( array( 'jetpack/contact-form', 'jetpack/field-checkbox-multiple', 'jetpack/input', 'jetpack/label', 'jetpack/option', 'jetpack/options', 'jetpack/phone-input' ), array_values( self::field_block_map() ) ) ) );
	}

	/** @return array<int,string> Provider APIs required by the declared adapter. */
	public static function required_runtime_apis(): array {
		return array( 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form' );
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

		$availability                   = self::jetpack_forms_availability_details();
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
			'available'            => self::jetpack_forms_available(),
			'availability_details' => self::jetpack_forms_availability_details(),
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
		$availability = self::jetpack_forms_availability_details();
		return ! empty( $availability['available'] );
	}

	/** Activate and prepare Jetpack Forms through its canonical module lifecycle. */
	public static function prepare_jetpack_forms_runtime() {
		$availability = self::jetpack_forms_availability_details();
		if ( ! empty( $availability['available'] ) ) {
			return true;
		}

		$lifecycle_apis         = array(
			'Jetpack::is_module_active'         => self::runtime_static_method_exists( 'Jetpack', 'is_module_active' ),
			'Jetpack::activate_default_modules' => self::runtime_static_method_exists( 'Jetpack', 'activate_default_modules' ),
		);
		$missing_lifecycle_apis = array_keys( array_filter( $lifecycle_apis, static fn ( bool $available ): bool => ! $available ) );
		if ( ! empty( $missing_lifecycle_apis ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_lifecycle_missing', $missing_lifecycle_apis );
		}

		if ( ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( 'contact-form' ) ) ) {
			// Jetpack uses this inverted range to activate only explicitly supplied defaults.
			self::invoke_runtime_static_method( 'Jetpack', 'activate_default_modules', array( 999, 1, array( 'contact-form' ), false, false ) );
			if ( ! self::invoke_runtime_static_method( 'Jetpack', 'is_module_active', array( 'contact-form' ) ) ) {
				return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_activation_failed', array( 'contact-form' ) );
			}
		}

		$loader = 'Automattic\\Jetpack\\Forms\\Jetpack_Forms';
		if ( ! self::runtime_static_method_exists( $loader, 'load_contact_form' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_loader_missing', array( $loader . '::load_contact_form' ) );
		}

		$initializer = 'Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form_Plugin';
		if ( ! self::runtime_static_method_exists( $initializer, 'init' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_init_missing', array( $initializer . '::init' ) );
		}

		$init_callback_loaded = function_exists( 'has_action' ) && (
			false !== has_action( 'init', '\\' . $initializer . '::init' )
			|| false !== has_action( 'init', $initializer . '::init' )
		);
		if ( ! $init_callback_loaded ) {
			self::invoke_runtime_static_method( $loader, 'load_contact_form' );
		}

		if ( ! function_exists( 'did_action' ) || ! did_action( 'init' ) ) {
			return self::jetpack_forms_runtime_error( 'static_site_importer_jetpack_forms_init_pending', array( 'init' ) );
		}

		if ( ! self::$jetpack_forms_initialized ) {
			self::invoke_runtime_static_method( $initializer, 'init' );
			self::$jetpack_forms_initialized = true;
		}

		$availability = self::jetpack_forms_availability_details();
		if ( ! empty( $availability['available'] ) ) {
			return true;
		}

		return self::jetpack_forms_runtime_error(
			'static_site_importer_jetpack_forms_blocks_missing',
			array_keys( array_filter( $availability['required_blocks'], static fn ( bool $registered ): bool => ! $registered ) ),
			$availability
		);
	}

	/** Check an extension-owned static API without binding analysis to that extension's stubs. */
	private static function runtime_static_method_exists( string $class_name, string $method ): bool {
		return class_exists( $class_name ) && method_exists( $class_name, $method );
	}

	/**
	 * Invoke an extension-owned static API after runtime_static_method_exists() succeeds.
	 *
	 * @phpstan-impure
	 */
	private static function invoke_runtime_static_method( string $class_name, string $method, array $args = array() ) {
		return ( new ReflectionMethod( $class_name, $method ) )->invokeArgs( null, $args );
	}

	/** Build a bounded provider-readiness error. */
	private static function jetpack_forms_runtime_error( string $code, array $missing, array $details = array() ): WP_Error {
		return new WP_Error(
			$code,
			'Jetpack Forms provider runtime is not ready.',
			array_filter(
				array(
					'missing' => array_slice( array_values( $missing ), 0, 20 ),
					'details' => $details,
				)
			)
		);
	}

	/**
	 * Return the specific Jetpack Forms APIs present in the current runtime.
	 *
	 * @return array<string,mixed>
	 */
	public static function jetpack_forms_availability_details(): array {
		$required_apis = array();
		foreach ( self::required_runtime_apis() as $api ) {
			$required_apis[ $api ] = class_exists( $api );
		}
		$contact_form_class = ! empty( $required_apis['Automattic\\Jetpack\\Forms\\ContactForm\\Contact_Form'] );
		$legacy_class       = class_exists( 'Grunion_Contact_Form' ) || class_exists( 'Contact_Form' );
		$registered_blocks  = array_fill_keys( self::required_block_types(), false );

		if ( class_exists( 'WP_Block_Type_Registry' ) ) {
			$registry = WP_Block_Type_Registry::get_instance();
			foreach ( array_keys( $registered_blocks ) as $block_name ) {
				$registered_blocks[ $block_name ] = $registry->is_registered( $block_name );
			}
		}
		$contact_form_block        = $registered_blocks['jetpack/contact-form'];
		$field_text_block          = $registered_blocks['jetpack/field-text'];
		$required_blocks_available = ! empty( $registered_blocks ) && ! in_array( false, $registered_blocks, true );
		$required_apis_available   = ! empty( $required_apis ) && ! in_array( false, $required_apis, true );

		return array(
			'available'          => $required_apis_available && $contact_form_block && $required_blocks_available,
			'contact_form_class' => $contact_form_class,
			'legacy_class'       => $legacy_class,
			'contact_form_block' => $contact_form_block,
			'field_text_block'   => $field_text_block,
			'required_apis'      => $required_apis,
			'required_blocks'    => $registered_blocks,
			'registered_blocks'  => $registered_blocks,
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
		$controls    = isset( $form['controls'] ) && is_array( $form['controls'] ) ? $form['controls'] : array();
		$selector    = isset( $form['selector'] ) && is_scalar( $form['selector'] ) ? (string) $form['selector'] : '';
		$source_path = isset( $form['source_path'] ) && is_scalar( $form['source_path'] ) ? (string) $form['source_path'] : '';

		$scope                         = self::layout_scope( $form );
		$field_blocks                  = array();
		$mapped_types                  = array();
		$submit_text                   = 'Submit';
		$skipped                       = array();
		$control_attribute_losses      = array();
		$has_topology                  = isset( $form['control_topology'] );
		$has_source_submit             = false;
		$textarea_height_omitted_count = (int) ( $form['form']['textarea_height_omitted_count'] ?? 0 );
		$radio_groups                  = self::labelled_radio_groups( $form, $controls );
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
			if ( isset( $radio_groups['suppressed_controls'][ $control_index ] ) ) {
				continue;
			}
			if ( isset( $radio_groups['groups'][ $control_index ] ) ) {
				$control = $radio_groups['groups'][ $control_index ];
			}

			$type = strtolower( trim( (string) ( $control['type'] ?? '' ) ) );
			$tag  = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );

			if ( 'submit' === $type || ( 'button' === $tag && 'submit' === $type ) ) {
				$text              = self::control_text( $control );
				$submit_text       = '' !== $text ? $text : $submit_text;
				$has_source_submit = true;
				if ( $has_topology ) {
					$field_blocks[ $control_index ] = self::submit_button_block( $submit_text, self::layout_node_class( $scope, 'control-' . $control_index ), $submit_presentation );
				}
				continue;
			}

			$field_block = self::field_block_from_control( $tag, $type, $control );
			if ( null === $field_block ) {
				$skipped[] = '' !== $type ? $type : $tag;
				continue;
			}
			foreach ( $field_block['losses'] ?? array() as $loss ) {
				$control_attribute_losses[] = $loss + array( 'control_index' => $control_index );
			}
			unset( $field_block['losses'] );

			$source_class       = isset( $control['class'] ) && is_scalar( $control['class'] ) ? trim( (string) $control['class'] ) : '';
			$has_provider_input = (bool) array_filter( $field_block['innerBlocks'] ?? array(), static fn ( array $block ): bool => in_array( $block['name'] ?? '', array( 'jetpack/input', 'jetpack/phone-input' ), true ) );
			$field_source_class = $has_provider_input ? '' : $source_class;
			$field_block['attrs']['className'] = trim( $field_source_class . ' ' . self::layout_node_class( $scope, 'control-' . $control_index ) );
			$field_blocks[ $control_index ]    = $field_block;
			$mapped_types[]                    = $field_block['name'];
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

		$topology = self::topology_inner_blocks( $form, $field_blocks, $controls, $radio_groups['suppressed_controls'] );
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
			$inner_blocks[] = self::submit_button_block( $submit_text, self::layout_node_class( $scope, 'control-submit' ), $submit_presentation );
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
		self::append_receipt_entries( $layout['receipt'], 'operations', array_merge( $radio_groups['operations'], $topology['operations'] ) );
		self::append_receipt_entries( $layout['receipt'], 'losses', $control_attribute_losses );
		$inner_blocks              = $layout['blocks'];
		$form_attrs                = self::contact_form_attributes( $form, $scope );
		$overlay_graph             = $provider_graph;
		$overlay_graph['nodes']    = array_values( array_filter( $overlay_graph['nodes'] ?? array(), static fn ( $node ): bool => is_array( $node ) && ( 'form' === ( $node['id'] ?? '' ) || preg_match( '/^control-[0-9]+$/D', (string) ( $node['id'] ?? '' ) ) ) ) );
		$overlay_graph['variants'] = array_merge( $overlay_graph['variants'] ?? array(), $topology['responsive_variant_targets'] );
		$overlay_node_ids          = array_fill_keys( array_map( static fn( array $node ): string => (string) $node['id'], $overlay_graph['nodes'] ), true );
		foreach ( $topology['responsive_variant_targets'] as $variant ) {
			if ( is_string( $variant['node'] ?? null ) && ! isset( $overlay_node_ids[ $variant['node'] ] ) ) {
				$overlay_graph['nodes'][]             = array(
					'id'     => $variant['node'],
					'layout' => array(),
				);
				$overlay_node_ids[ $variant['node'] ] = true;
			}
		}
		$overlay_nodes                = array_fill_keys( array_map( static fn ( array $node ): string => (string) $node['id'], $overlay_graph['nodes'] ), true );
		$overlay_graph['variants']    = array_values( array_filter( $overlay_graph['variants'], static fn ( $variant ): bool => is_array( $variant ) && isset( $overlay_nodes[ $variant['node'] ?? '' ] ) ) );
		$overlay_form                 = $form;
		$overlay_form['layout_graph'] = $overlay_graph;
		$target_map                   = self::provider_layout_target_map( $overlay_form, $scope );
		$overlay                      = Static_Site_Importer_Provider_Layout_Overlay::compile( $overlay_graph, $target_map );
		self::append_receipt_entries( $layout['receipt'], 'operations', $overlay['operations'] );
		self::append_receipt_entries( $layout['receipt'], 'losses', $overlay['losses'] );
		$layout['receipt']['status'] = 0 < $layout['receipt']['operations_total'] ? 'applied' : ( 0 < $layout['receipt']['losses_total'] ? 'deferred' : 'skipped' );
		$markup                      = self::context_block_markup( $form, 'context_before' ) . self::serialize_block( 'jetpack/contact-form', $form_attrs, $inner_blocks ) . self::context_block_markup( $form, 'context_after' );
		$row                         = array(
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
		$unaccepted_losses           = array_values(
			array_filter(
				$layout['receipt']['losses'] ?? array(),
				static fn( $loss ): bool => is_array( $loss ) && self::receipt_loss_requires_gate( $loss ) && ! self::provider_represents_receipt_loss( $loss, $form, $field_blocks ) && true !== apply_filters( 'static_site_importer_form_receipt_loss_accepted', false, $loss, $form, $row )
			)
		);
		$gate_overflow_count         = (int) ( $layout['receipt']['gate_required_loss_overflow_count'] ?? 0 );
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
	 * Report whether a source control carries content the provider is expected to represent.
	 *
	 * Hidden inputs carry the source platform's form-handler plumbing, such as endpoint
	 * identifiers and captcha tokens, rather than content an author wrote or a visitor sees.
	 * A provider form supersedes that plumbing, so leaving hidden inputs behind is the intended
	 * conversion rather than a fidelity loss.
	 *
	 * @param string $type Normalized source control type.
	 */
	private static function control_carries_authored_content( string $type ): bool {
		return 'hidden' !== $type;
	}

	/** A source label wrapper is carried by the mapped Jetpack field's label child. */
	private static function provider_represents_receipt_loss( array $loss, array $form, array $field_blocks ): bool {
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
			if ( 'fieldset' === $tag && 'plain_group' === ( $node['fieldset_semantics'] ?? '' ) && null === ( $node['parent'] ?? null ) ) {
				$nodes_by_id = array();
				foreach ( $nodes as $candidate ) {
					if ( is_array( $candidate ) && is_string( $candidate['id'] ?? null ) ) {
						$nodes_by_id[ $candidate['id'] ] = $candidate;
					}
				}
				foreach ( array_keys( $field_blocks ) as $control_index ) {
					$control_node = null;
					foreach ( $nodes as $candidate ) {
						if ( is_array( $candidate ) && 'control' === ( $candidate['kind'] ?? '' ) && ( $candidate['control'] ?? null ) === $control_index ) {
							$control_node = $candidate;
							break;
						}
					}
					$parent = $control_node['parent'] ?? null;
					while ( is_string( $parent ) && ( $node['id'] ?? '' ) !== $parent ) {
						$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
					}
					if ( ( $node['id'] ?? '' ) !== $parent ) {
						return false;
					}
				}
				return ! empty( $field_blocks );
			}
			if ( 'label' !== $tag ) {
				return false;
			}
			$controls = array_values(
				array_filter(
					$nodes,
					static fn( $candidate ): bool => is_array( $candidate ) && 'control' === ( $candidate['kind'] ?? '' ) && ( $candidate['parent'] ?? '' ) === ( $node['id'] ?? '' ) && is_int( $candidate['control'] ?? null ) && isset( $field_blocks[ $candidate['control'] ] )
				)
			);
			return 1 === count( $controls );
		}
		return false;
	}

	/**
	 * Aggregate only an exact labelled-fieldset radio topology that Jetpack can
	 * represent as one field. The generic topology supplies membership and its
	 * canonical binding supplies the source legend; no provider semantics are inferred.
	 *
	 * @return array{groups:array<int,array<string,mixed>>,suppressed_controls:array<int,bool>,represented_semantic_nodes:array<int,string>,operations:array<int,array<string,mixed>>}
	 */
	private static function labelled_radio_groups( array $form, array $controls ): array {
		$result = array(
			'groups'                     => array(),
			'suppressed_controls'        => array(),
			'represented_semantic_nodes' => array(),
			'operations'                 => array(),
		);
		$nodes  = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $nodes ) ) {
			return $result;
		}

		$children = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return $result;
			}
			$children[ $node['parent'] ?? '$root' ][] = $node;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, static fn ( array $left, array $right ): int => $left['order'] <=> $right['order'] );
		}
		unset( $siblings );

		$legends = self::labelled_fieldset_legends( $form, $nodes );
		if ( empty( $legends ) ) {
			return $result;
		}

		$all_radio_names = array();
		foreach ( $controls as $control_index => $control ) {
			if ( ! is_array( $control ) || 'input' !== strtolower( trim( (string) ( $control['tag'] ?? '' ) ) ) || 'radio' !== strtolower( trim( (string) ( $control['type'] ?? '' ) ) ) ) {
				continue;
			}
			$name = trim( (string) ( $control['name'] ?? '' ) );
			if ( '' !== $name ) {
				$all_radio_names[ $name ][] = $control_index;
			}
		}

		foreach ( $nodes as $fieldset ) {
			if ( ! is_array( $fieldset ) || 'wrapper' !== ( $fieldset['kind'] ?? null ) || 'fieldset' !== ( $fieldset['tag'] ?? null ) || 'labelled_group' !== ( $fieldset['fieldset_semantics'] ?? null ) || ! is_string( $fieldset['id'] ?? null ) || ! isset( $legends[ $fieldset['id'] ] ) ) {
				continue;
			}
			$members           = array();
			$semantic_nodes    = array( $fieldset['id'] );
			$unambiguous_shape = true;
			$labels            = $children[ $fieldset['id'] ] ?? array();
			if ( 1 === count( $labels ) && 'wrapper' === ( $labels[0]['kind'] ?? null ) && 'div' === ( $labels[0]['tag'] ?? null ) ) {
				$labels = $children[ $labels[0]['id'] ];
			}
			foreach ( $labels as $label ) {
				$label_children = $children[ $label['id'] ] ?? array();
				if ( 'wrapper' !== ( $label['kind'] ?? null ) || 'label' !== ( $label['tag'] ?? null ) || 1 !== count( $label_children ) || 'control' !== ( $label_children[0]['kind'] ?? null ) || ! is_int( $label_children[0]['control'] ?? null ) ) {
					$unambiguous_shape = false;
					break;
				}
				$members[]        = $label_children[0]['control'];
				$semantic_nodes[] = $label['id'];
			}
			if ( ! $unambiguous_shape || ! in_array( count( $members ), array( 2, 3, 4 ), true ) || count( $members ) !== count( array_unique( $members ) ) ) {
				continue;
			}
			$first    = $controls[ $members[0] ] ?? null;
			$name     = is_array( $first ) ? trim( (string) ( $first['name'] ?? '' ) ) : '';
			$options  = array();
			$required = false;
			foreach ( $members as $control_index ) {
				$control = $controls[ $control_index ] ?? null;
				if ( ! is_array( $control ) || 'input' !== strtolower( trim( (string) ( $control['tag'] ?? '' ) ) ) || 'radio' !== strtolower( trim( (string) ( $control['type'] ?? '' ) ) ) || trim( (string) ( $control['name'] ?? '' ) ) !== $name ) {
					$unambiguous_shape = false;
					break;
				}
				if ( ! isset( $control['label'] ) || ! is_scalar( $control['label'] ) || '' === trim( (string) $control['label'] ) ) {
					$unambiguous_shape = false;
					break;
				}
				$option    = self::control_text( array( 'label' => $control['label'] ) );
				$options[] = $option;
				$required  = $required || ! empty( $control['required'] ) || 'true' === strtolower( trim( (string) ( $control['aria-required'] ?? $control['aria_required'] ?? '' ) ) );
			}
			if ( ! $unambiguous_shape || '' === $name || count( $all_radio_names[ $name ] ?? array() ) !== count( $members ) ) {
				continue;
			}

			$group_control            = $first;
			$group_control['text']    = $legends[ $fieldset['id'] ];
			$group_control['label']   = $legends[ $fieldset['id'] ];
			$group_control['options'] = $options;
			if ( $required ) {
				$group_control['required'] = true;
			}
			$result['groups'][ $members[0] ] = $group_control;
			foreach ( array_slice( $members, 1 ) as $control_index ) {
				$result['suppressed_controls'][ $control_index ] = true;
			}
			$result['represented_semantic_nodes'] = array_merge( $result['represented_semantic_nodes'], $semantic_nodes );
			$result['operations'][]               = array(
				'dimension'     => 'semantic',
				'strategy'      => 'provider_radio_fieldset_equivalent',
				'target_hash'   => hash( 'sha256', $fieldset['id'] ),
				'control_count' => count( $members ),
				'required'      => $required,
			);
		}

		return $result;
	}

	/**
	 * Read only the source legend evidence already preserved in one canonical
	 * binding. A count mismatch leaves every fieldset fail-closed.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 * @return array<string,string>
	 */
	private static function labelled_fieldset_legends( array $form, array $nodes ): array {
		$fieldset_ids = array();
		foreach ( $nodes as $node ) {
			if ( 'wrapper' === ( $node['kind'] ?? null ) && 'fieldset' === ( $node['tag'] ?? null ) && 'labelled_group' === ( $node['fieldset_semantics'] ?? null ) && is_string( $node['id'] ?? null ) ) {
				$fieldset_ids[] = $node['id'];
			}
		}
		$bindings = $form['bindings'] ?? null;
		if ( empty( $fieldset_ids ) || ! is_array( $bindings ) || 1 !== count( $bindings ) || ! is_array( $bindings[0] ?? null ) || ! is_string( $bindings[0]['search_block_markup'] ?? null ) ) {
			return array();
		}
		$count   = preg_match_all( '~<fieldset\b[^>]*>\s*<legend\b[^>]*>(.*?)</legend>~is', $bindings[0]['search_block_markup'], $matches );
		$legends = array();
		if ( false === $count || count( $fieldset_ids ) !== $count ) {
			return array();
		}
		foreach ( $matches[1] as $index => $markup ) {
			$legend = preg_replace( '/\s+/', ' ', trim( html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( ! is_string( $legend ) || '' === $legend || 200 < strlen( $legend ) ) {
				return array();
			}
			$legends[ $fieldset_ids[ $index ] ] = $legend;
		}
		return $legends;
	}

	/**
	 * Flatten the validated generic tree into Jetpack's constrained direct-child
	 * grammar. Equal two- and four-column grids and complete provenance-backed
	 * percentage rows map to provider field widths; other wrapper semantics/layout
	 * remain explicit receipt losses.
	 *
	 * @param array<int,array<string,mixed>> $field_blocks
	 * @param array<int,array<string,mixed>> $controls
	 * @return array{blocks:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>,represented_layout_nodes:array<int,string>,represented_topology_nodes:array<int,string>,responsive_variant_targets:array<int,array<string,mixed>>,native_visibility_targets:array<int,string>}|null
	 */
	private static function topology_inner_blocks( array $form, array $field_blocks, array $controls, array $suppressed_controls = array() ): ?array {
		if ( ! isset( $form['control_topology'] ) ) {
			return array(
				'blocks'                     => array_values( $field_blocks ),
				'losses'                     => array(),
				'operations'                 => array(),
				'represented_layout_nodes'   => array(),
				'represented_topology_nodes' => array(),
				'responsive_variant_targets' => array(),
				'native_visibility_targets'  => array(),
			);
		}
		$nodes = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $nodes ) ) {
			return null;
		}
		$children = array( '$root' => array() );
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return null;
			}
			$parent                = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$children[ $parent ][] = $node;
		}
		foreach ( $children as &$siblings ) {
			usort( $siblings, static fn ( array $left, array $right ): int => $left['order'] <=> $right['order'] );
		}
		unset( $siblings );
		$losses                     = array();
		$operations                 = array();
		$represented_layout_nodes   = array();
		$represented_topology_nodes = array();
		$responsive_variant_targets = array();
		$native_visibility_targets  = array();
		$layout_by_node             = array();
		$layout_nodes_by_id         = array();
		$variants_by_node           = array();
		foreach ( $form['layout_graph']['nodes'] ?? array() as $layout_node ) {
			if ( is_array( $layout_node ) && is_string( $layout_node['id'] ?? null ) ) {
				$layout_by_node[ $layout_node['id'] ]     = is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
				$layout_nodes_by_id[ $layout_node['id'] ] = $layout_node;
			}
		}
		foreach ( $form['layout_graph']['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_string( $variant['node'] ?? null ) ) {
				$variants_by_node[ $variant['node'] ][] = $variant;
			}
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$direct_children = $children[ $node['id'] ] ?? array();
			if ( 1 !== count( $direct_children ) || 'control' !== ( $direct_children[0]['kind'] ?? null ) ) {
				continue;
			}
			$control_index = $direct_children[0]['control'] ?? null;
			$source_class  = trim( (string) ( $node['class'] ?? '' ) );
			if ( ! is_int( $control_index ) || '' === $source_class || ! isset( $field_blocks[ $control_index ] ) || 'core/button' === ( $field_blocks[ $control_index ]['name'] ?? '' ) ) {
				continue;
			}
			$generated_class                                      = self::layout_node_class( self::layout_scope( $form ), $node['id'] );
			$wrapper_classes                                      = preg_split( '/\s+/', $source_class ) ?: array();
			$wrapper_markers                                      = implode( ' ', array_map( static fn ( string $class ): string => 'ssi-source-wrapper--' . $class, $wrapper_classes ) );
			$field_blocks[ $control_index ]['attrs']['className'] = trim( (string) ( $field_blocks[ $control_index ]['attrs']['className'] ?? '' ) . ' ' . $wrapper_markers . ' ' . $generated_class );
			$operations[] = array(
				'dimension'   => 'topology',
				'strategy'    => 'provider_field_wrapper_class_projection',
				'target_hash' => hash( 'sha256', $node['id'] ),
			);
			$layout       = $layout_by_node[ $node['id'] ] ?? array();
			$provenance   = $layout_nodes_by_id[ $node['id'] ]['provenance'] ?? array();
			$class_tokens = preg_split( '/\s+/', $source_class );
			if ( false === $class_tokens ) {
				$class_tokens = array();
			}
			$class_owned = ! empty( $layout ) && ! empty( $provenance );
			foreach ( $provenance as $provenance_row ) {
				$selector      = is_array( $provenance_row ) && is_string( $provenance_row['selector'] ?? null ) ? $provenance_row['selector'] : '';
				$matches_class = false;
				if ( preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector ) ) {
					foreach ( $class_tokens as $class_token ) {
						if ( '' !== $class_token && preg_match( '/\.' . preg_quote( $class_token, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) {
							$matches_class = true;
							break;
						}
					}
				}
				if ( ! $matches_class ) {
					$class_owned = false;
					break;
				}
			}
			if ( $class_owned ) {
				$represented_layout_nodes[] = $node['id'];
			}
		}
		$collect_controls = static function ( array $node ) use ( &$collect_controls, $children ): array {
			if ( 'control' === ( $node['kind'] ?? null ) ) {
				return is_int( $node['control'] ?? null ) ? array( $node['control'] ) : array();
			}
			$controls = array();
			foreach ( $children[ $node['id'] ?? '' ] ?? array() as $child ) {
				$controls = array_merge( $controls, $collect_controls( $child ) );
			}
			return $controls;
		};
		$topology_by_id   = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$topology_by_id[ $node['id'] ] = $node;
			}
		}
		$has_unconditional_proven_property = static function ( array $node, string $property ): bool {
			foreach ( $node['provenance'] ?? array() as $fact ) {
				if ( is_array( $fact ) && null === ( $fact['condition'] ?? null ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && in_array( $property, $fact['properties'] ?? array(), true ) ) {
					return true;
				}
			}
			return false;
		};
		$safe_percentage_variants          = static function ( array $variants ): bool {
			foreach ( $variants as $variant ) {
				$patch = is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
				$width = $patch['width'] ?? null;
				if ( ! is_array( $variant ) || ! is_array( $variant['condition'] ?? null ) || 'media' !== ( $variant['condition']['kind'] ?? null ) || ! is_string( $variant['condition']['query'] ?? null ) || 1 !== preg_match( '/^\((?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)\)$/D', $variant['condition']['query'] ) || ! is_string( $width ) || 1 !== preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) || ( array( 'width' ) !== array_keys( $patch ) && array( 'display', 'width' ) !== array_keys( $patch ) ) || ( isset( $patch['display'] ) && 'block' !== $patch['display'] ) ) {
					return false;
				}
				$proven = false;
				foreach ( $variant['provenance'] ?? array() as $fact ) {
					if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === $variant['condition'] && is_string( $fact['source_path'] ?? null ) && is_string( $fact['source_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) && is_string( $fact['selector'] ?? null ) && ! array_diff( array_keys( $patch ), $fact['properties'] ?? array() ) ) {
						$proven = true;
						break;
					}
				}
				if ( ! $proven ) {
					return false;
				}
			}
			return true;
		};
		foreach ( $nodes as $node ) {
			$id = is_array( $node ) && 'control' === ( $node['kind'] ?? null ) && is_string( $node['id'] ?? null ) ? $node['id'] : '';
			if ( '' === $id || 'none' !== ( $layout_by_node[ $id ]['display'] ?? null ) ) {
				continue;
			}
			$control_index = $node['control'] ?? null;
			if ( ! is_int( $control_index ) || ! isset( $field_blocks[ $control_index ] ) || 'core/button' === ( $field_blocks[ $control_index ]['name'] ?? '' ) ) {
				continue;
			}
			$parent         = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$layout_node    = $layout_nodes_by_id[ $id ] ?? null;
			$source_classes = is_array( $layout_node['source']['classes'] ?? null ) ? $layout_node['source']['classes'] : array();
			$class_proven   = ! empty( $source_classes ) && is_array( $layout_node ) && $has_unconditional_proven_property( $layout_node, 'display' );
			foreach ( $layout_node['provenance'] ?? array() as $fact ) {
				if ( ! is_array( $fact ) || ! in_array( 'display', $fact['properties'] ?? array(), true ) ) {
					continue;
				}
				$selector       = is_string( $fact['selector'] ?? null ) ? $fact['selector'] : '';
				$matches_source = false;
				if ( null === ( $fact['condition'] ?? null ) && preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector ) ) {
					foreach ( $source_classes as $source_class ) {
						if ( is_string( $source_class ) && '' !== $source_class && preg_match( '/\.' . preg_quote( $source_class, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) {
							$matches_source = true;
							break;
						}
					}
				}
				if ( ! $matches_source ) {
					$class_proven = false;
					break;
				}
			}
			$replacement_proven = '' !== $parent
				&& in_array( $parent, $represented_layout_nodes, true )
				&& 1 === count( $children[ $parent ] ?? array() )
				&& 'none' !== ( $layout_by_node[ $parent ]['display'] ?? null )
				&& ! isset( $variants_by_node[ $parent ] )
				&& ! isset( $variants_by_node[ $id ] )
				&& $class_proven;
			if ( ! $replacement_proven ) {
				$losses[] = array(
					'dimension'   => 'topology',
					'reason_code' => 'provider_native_control_visibility_unrepresentable',
					'node_hash'   => hash( 'sha256', $id ),
				);
				continue;
			}
			$native_visibility_targets[] = $id;
			$operations[]                = array(
				'dimension' => 'layout',
				'strategy'  => 'provider_native_control_visibility',
				'node_hash' => hash( 'sha256', $id ),
			);
		}
		$percentage_width_parents = array();
		if ( 'generic/computed-layout-graph/v2' === ( $form['layout_graph']['schema'] ?? null ) ) {
			foreach ( $children as $parent => $siblings ) {
				if ( '$root' === $parent || count( $siblings ) < 2 || isset( $variants_by_node[ $parent ] ) ) {
					continue;
				}
				$indexes             = array();
				$widths              = array();
				$branches            = array();
				$row_variant_targets = array();
				foreach ( $siblings as $sibling ) {
					$id              = $sibling['id'];
					$layout_node     = $layout_nodes_by_id[ $id ] ?? null;
					$layout          = is_array( $layout_node ) && is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
					$width           = is_string( $layout['width'] ?? null ) ? trim( $layout['width'] ) : '';
					$width_proven    = is_array( $layout_node ) && $has_unconditional_proven_property( $layout_node, 'width' );
					$branch_controls = $collect_controls( $sibling );
					$value           = 1 === preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)%$/D', $width ) ? (float) substr( $width, 0, -1 ) : 0.0;
					$variants        = $variants_by_node[ $id ] ?? array();
					if ( ! $safe_percentage_variants( $variants ) || ! $width_proven || 0 >= $value || 100 < $value || 1 !== count( $branch_controls ) || ! isset( $field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
						$indexes = array();
						break;
					}
					$indexes[]  = $branch_controls[0];
					$widths[]   = $value;
					$branches[] = $id;
					foreach ( $variants as $variant ) {
						$variant['node']       = 'control-' . $branch_controls[0];
						$row_variant_targets[] = $variant;
					}
				}
				if ( empty( $indexes ) || 0.001 < abs( array_sum( $widths ) - 100.0 ) ) {
					continue;
				}
				$responsive_variant_targets = array_merge( $responsive_variant_targets, $row_variant_targets );
				foreach ( $indexes as $offset => $control_index ) {
					$field_blocks[ $control_index ]['attrs']['width'] = round( $widths[ $offset ], 3 );
				}
				$represented_layout_nodes            = array_merge( $represented_layout_nodes, $branches );
				$percentage_width_parents[ $parent ] = true;
				$operations[]                        = array(
					'dimension'   => 'layout',
					'strategy'    => 'provider_percentage_width_fields',
					'target_hash' => hash( 'sha256', $parent ),
					'field_count' => count( $indexes ),
					'widths_hash' => hash( 'sha256', (string) wp_json_encode( $widths ) ),
				);

				$table_chain  = array( $parent );
				$table_tags   = array( 'tr', 'tbody', 'table' );
				$cursor       = $parent;
				$table_proven = true;
				foreach ( $table_tags as $expected_tag ) {
					$current            = $topology_by_id[ $cursor ] ?? null;
					$layout             = $layout_by_node[ $cursor ] ?? array();
					$allows_table_width = 'table' === $expected_tag && array( 'width' ) === array_keys( $layout ) && '100%' === ( $layout['width'] ?? null ) && isset( $layout_nodes_by_id[ $cursor ] ) && $has_unconditional_proven_property( $layout_nodes_by_id[ $cursor ], 'width' );
					if ( ! is_array( $current ) || ( $current['tag'] ?? 'div' ) !== $expected_tag || ( $layout_nodes_by_id[ $cursor ]['source']['tag'] ?? '' ) !== $expected_tag || ( 'tr' !== $expected_tag && ! empty( $layout ) && ! $allows_table_width ) ) {
						$table_proven = false;
						break;
					}
					$cursor = $current['parent'] ?? null;
					if ( is_string( $cursor ) ) {
						$table_chain[] = $cursor;
					}
				}
				foreach ( $branches as $branch ) {
					if ( 'td' !== ( $topology_by_id[ $branch ]['tag'] ?? 'div' ) || 'td' !== ( $layout_nodes_by_id[ $branch ]['source']['tag'] ?? '' ) || array( 'width' ) !== array_keys( $layout_by_node[ $branch ] ?? array() ) ) {
						$table_proven = false;
					}
				}
				if ( $table_proven ) {
					$represented_topology_nodes = array_merge( $represented_topology_nodes, $branches, array_slice( $table_chain, 0, 3 ) );
					if ( array( 'width' ) === array_keys( $layout_by_node[ $table_chain[2] ] ?? array() ) ) {
						$represented_layout_nodes[] = $table_chain[2];
					}
				}
			}
		}
		foreach ( $nodes as $node ) {
			$id               = is_array( $node ) ? ( $node['id'] ?? null ) : null;
			$layout_node      = is_string( $id ) ? ( $layout_nodes_by_id[ $id ] ?? null ) : null;
			$omitted_controls = is_array( $node ) ? $collect_controls( $node ) : array();
			if ( 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $id ) || array( 'display' => 'none' ) !== ( $layout_by_node[ $id ] ?? array() ) || isset( $variants_by_node[ $id ] ) || ! is_array( $layout_node ) || ! $has_unconditional_proven_property( $layout_node, 'display' ) || empty( $omitted_controls ) || array_filter( $omitted_controls, static fn( int $index ): bool => self::control_carries_authored_content( strtolower( trim( (string) ( $controls[ $index ]['type'] ?? $controls[ $index ]['tag'] ?? '' ) ) ) ) ) ) {
				continue;
			}
			$represented_layout_nodes[]   = $id;
			$represented_topology_nodes[] = $id;
			$operations[]                 = array(
				'dimension'     => 'topology',
				'strategy'      => 'provider_omitted_runtime_controls',
				'target_hash'   => hash( 'sha256', $id ),
				'control_count' => count( $omitted_controls ),
			);
		}
		foreach ( $children as $parent => $siblings ) {
			if ( '$root' === $parent || isset( $percentage_width_parents[ $parent ] ) || ! isset( $layout_by_node[ $parent ] ) || isset( $variants_by_node[ $parent ] ) || ! in_array( count( $siblings ), array( 2, 4 ), true ) ) {
				continue;
			}
			$layout = $layout_by_node[ $parent ];
			if ( array_intersect( array_keys( $layout ), array( 'item_placement', 'column', 'row', 'area' ) ) ) {
				continue;
			}
			$columns    = preg_replace( '/\s+/', '', (string) ( $layout['columns'] ?? '' ) );
			$count      = count( $siblings );
			$equal_grid = 'grid' === ( $layout['display'] ?? null ) && ( 'repeat(' . $count . ',1fr)' === $columns || str_repeat( '1fr', $count ) === $columns );
			if ( ! $equal_grid ) {
				continue;
			}
			$indexes = array();
			foreach ( $siblings as $sibling ) {
				$branch_controls = $collect_controls( $sibling );
				if ( 1 !== count( $branch_controls ) || ! isset( $field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
					$indexes = array();
					break;
				}
				$indexes[] = $branch_controls[0];
			}
			if ( empty( $indexes ) ) {
				continue;
			}
			foreach ( $indexes as $control_index ) {
				$field_blocks[ $control_index ]['attrs']['width'] = 100 / $count;
			}
			$represented_layout_nodes[] = $parent;
			$operations[]               = array(
				'dimension'   => 'layout',
				'strategy'    => 'provider_equal_width_fields',
				'target_hash' => hash( 'sha256', $parent ),
				'width'       => 100 / $count,
			);
		}
		$represented = array_fill_keys( $represented_layout_nodes, true );
		foreach ( $layout_by_node as $node_id => $layout ) {
			if ( ! preg_match( '/^wrapper-[0-9]+$/D', $node_id ) || isset( $represented[ $node_id ] ) || ( empty( $layout ) && ! isset( $variants_by_node[ $node_id ] ) ) ) {
				continue;
			}
			$losses[] = array(
				'dimension'   => 'topology',
				'reason_code' => 'provider_wrapper_layout_unrepresentable',
				'node_hash'   => hash( 'sha256', $node_id ),
			);
		}
		$build = static function ( string $parent_node ) use ( &$build, $children, $field_blocks, $controls, $suppressed_controls, &$losses ): array {
			$blocks = array();
			foreach ( $children[ $parent_node ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$control_index = $node['control'] ?? -1;
					if ( isset( $field_blocks[ $control_index ] ) ) {
						$blocks[] = $field_blocks[ $control_index ];
					} elseif ( isset( $suppressed_controls[ $control_index ] ) ) {
						continue;
					} elseif ( isset( $controls[ $control_index ] ) ) {
						$type = strtolower( trim( (string) ( $controls[ $control_index ]['type'] ?? $controls[ $control_index ]['tag'] ?? '' ) ) );
						if ( ! self::control_carries_authored_content( $type ) ) {
							continue;
						}
						$losses[] = array(
							'dimension'         => 'topology',
							'reason_code'       => 'unsupported_control_unrepresentable',
							'node_hash'         => hash( 'sha256', $node['id'] ),
							'control_index'     => $control_index,
							'control_type_hash' => hash( 'sha256', $type ),
						);
					}
					continue;
				}
				$blocks = array_merge( $blocks, $build( $node['id'] ) );
			}
			return $blocks;
		};
		return array(
			'blocks'                     => $build( '$root' ),
			'losses'                     => $losses,
			'operations'                 => $operations,
			'represented_layout_nodes'   => array_values( array_unique( array_map( 'strval', $represented_layout_nodes ) ) ),
			'represented_topology_nodes' => array_values( array_unique( array_map( 'strval', $represented_topology_nodes ) ) ),
			'responsive_variant_targets' => $responsive_variant_targets,
			'native_visibility_targets'  => array_values( array_unique( array_map( 'strval', $native_visibility_targets ) ) ),
		);
	}

	/**
	 * Build a Jetpack field block definition from a source control.
	 *
	 * @param string               $tag     Source control tag.
	 * @param string               $type    Source control type.
	 * @param array<string, mixed> $control Source control metadata.
	 * @return array<string, mixed>|null
	 */
	private static function field_block_from_control( string $tag, string $type, array $control ): ?array {
		$map = self::field_block_map();

		$lookup = 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'select' : $type );
		if ( 'select-multiple' === $type ) {
			$lookup = 'select';
		}

		if ( ! isset( $map[ $lookup ] ) ) {
			return null;
		}

		$attrs = array();
		$label = self::control_text( $control );
		if ( ! empty( $control['required'] ) || 'true' === strtolower( trim( (string) ( $control['aria-required'] ?? $control['aria_required'] ?? '' ) ) ) ) {
			$attrs['required'] = true;
		}
		$id = isset( $control['id'] ) && is_scalar( $control['id'] ) ? trim( (string) $control['id'] ) : '';
		if ( '' !== $id ) {
			$attrs['id'] = $id;
		}
		if ( 'tel' === $lookup ) {
			$attrs['showCountrySelector'] = false;
		}
		$placeholder = isset( $control['placeholder'] ) && is_scalar( $control['placeholder'] ) ? trim( (string) $control['placeholder'] ) : '';

		if ( in_array( $lookup, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$options = self::option_labels( $control );
			if ( ! empty( $options ) ) {
				$attrs['options'] = $options;
			}
		}

		$inner_blocks = array();
		if ( 'checkbox' === $lookup && empty( $attrs['options'] ) ) {
			$inner_blocks[] = array(
				'name'  => 'jetpack/option',
				'attrs' => array(
					'label'        => $label,
					'isStandalone' => true,
				),
			);
		} elseif ( '' !== $label ) {
			$label_attrs  = array( 'label' => $label );
			$label_class  = isset( $control['label_class'] ) && is_scalar( $control['label_class'] ) ? trim( (string) $control['label_class'] ) : '';
			if ( '' !== $label_class ) {
				$label_attrs['className'] = $label_class;
			}
			$inner_blocks[] = array(
				'name'  => 'jetpack/label',
				'attrs' => $label_attrs,
			);
		}

		if ( in_array( $lookup, array( 'radio', 'checkbox' ), true ) && ! empty( $attrs['options'] ) ) {
			$option_blocks = array();
			foreach ( $attrs['options'] as $option ) {
				$option_blocks[] = array(
					'name'  => 'jetpack/option',
					'attrs' => array( 'label' => $option ),
				);
			}
			$inner_blocks[] = array(
				'name'        => 'jetpack/options',
				'attrs'       => array( 'type' => 'radio' === $lookup ? 'radio' : 'checkbox' ),
				'innerBlocks' => $option_blocks,
				'wrapper'     => 'ul',
			);
		} elseif ( ! in_array( $lookup, array( 'checkbox', 'radio' ), true ) ) {
			$input_attrs = array(
				'style' => array( 'border' => array( 'style' => 'solid' ) ),
			);
			$source_class = isset( $control['class'] ) && is_scalar( $control['class'] ) ? trim( (string) $control['class'] ) : '';
			if ( '' !== $source_class ) {
				$input_attrs['className'] = $source_class;
			}
			if ( '' !== $placeholder ) {
				$input_attrs['placeholder'] = $placeholder;
			}
			if ( 'textarea' === $lookup ) {
				$input_attrs['type'] = 'textarea';
				$height              = isset( $control['height'] ) && is_scalar( $control['height'] ) ? trim( (string) $control['height'] ) : '';
				if ( '' !== $height && preg_match( '/^[0-9]{1,4}(?:\.[0-9]+)?(?:px|em|rem|vh|vw|%)$/D', $height ) ) {
					$input_attrs['style']['dimensions']['minHeight'] = $height;
				}
			} elseif ( 'select' === $lookup ) {
				$input_attrs['type'] = 'dropdown';
			}
			if ( self::provider_supports_input_attribute( $lookup, 'step' ) && isset( $control['step'] ) && is_scalar( $control['step'] ) && '' !== trim( (string) $control['step'] ) ) {
				$input_attrs['step'] = trim( (string) $control['step'] );
			}
			$inner_blocks[] = array(
				'name'  => 'tel' === $lookup ? 'jetpack/phone-input' : 'jetpack/input',
				'attrs' => $input_attrs,
			);
		}

		$losses = array();
		if ( 'number' === $lookup ) {
			foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
				if ( self::provider_supports_input_attribute( $lookup, $attribute ) ) {
					continue;
				}
				if ( isset( $control[ $attribute ] ) && is_scalar( $control[ $attribute ] ) && '' !== trim( (string) $control[ $attribute ] ) ) {
					$losses[] = array(
						'dimension'         => 'control',
						'reason_code'       => 'unsupported_control_attribute',
						'attribute'         => $attribute,
						'control_type_hash' => hash( 'sha256', $type ),
					);
				}
			}
		}
		$block_name = 'checkbox' === $lookup && ! empty( $attrs['options'] ) ? 'jetpack/field-checkbox-multiple' : $map[ $lookup ];
		return array(
			'name'        => $block_name,
			'attrs'       => $attrs,
			'innerBlocks' => $inner_blocks,
			'wrapper'     => 'div',
			'losses'      => $losses,
		);
	}

	/** Return whether the selected Jetpack input block can carry a source attribute. */
	private static function provider_supports_input_attribute( string $lookup, string $attribute ): bool {
		return 'number' === $lookup && 'step' === $attribute;
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

	/**
	 * Build the Jetpack submit button block.
	 *
	 * @param string $text Submit button label.
	 * @return array<string, mixed>
	 */
	private static function submit_button_block( string $text, string $class_name = '', array $presentation = array() ): array {
		$source_classes = isset( $presentation['classes'] ) && is_array( $presentation['classes'] ) ? implode( ' ', array_filter( $presentation['classes'], 'is_string' ) ) : '';
		$class_name     = trim( 'form-button-submit is-submit ' . $source_classes . ' ' . $class_name );
		return array(
			'name'    => 'core/button',
			'attrs'   => array(
				'tagName'   => 'button',
				'type'      => 'submit',
				'lock'      => array(
					'remove' => true,
				),
				'className' => $class_name,
				'metadata'  => array( 'name' => 'Submit button' ),
			),
			'content' => '' !== trim( $text ) ? trim( $text ) : 'Submit',
			'wrapper' => 'submit',
		);
	}

	/** Serialize source context as editable core blocks beside the provider form. */
	private static function context_block_markup( array $form, string $position ): string {
		$context = isset( $form['form'][ $position ] ) && is_array( $form['form'][ $position ] ) ? $form['form'][ $position ] : array();
		$markup  = '';
		foreach ( $context as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['text'] ?? null ) || '' === trim( $block['text'] ) ) {
				continue;
			}
			if ( 'heading' === ( $block['type'] ?? null ) ) {
				$level   = min( 6, max( 1, (int) ( $block['level'] ?? 2 ) ) );
				$markup .= self::serialize_block( 'core/heading', 2 === $level ? array() : array( 'level' => $level ), array(), 'heading', $block['text'] );
			} elseif ( 'paragraph' === ( $block['type'] ?? null ) ) {
				$markup .= self::serialize_block( 'core/paragraph', array(), array(), 'paragraph', $block['text'] );
			}
		}
		return $markup;
	}

	/**
	 * Resolve the contact-form block attributes from source form metadata.
	 *
	 * @param array<string, mixed> $form Validated form row.
	 * @return array<string, mixed>
	 */
	private static function contact_form_attributes( array $form, string $scope = '' ): array {
		$attrs    = array();
		$metadata = isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array();
		$action   = isset( $metadata['action'] ) && is_scalar( $metadata['action'] ) ? trim( (string) $metadata['action'] ) : '';
		$class    = isset( $metadata['class'] ) && is_scalar( $metadata['class'] ) ? trim( (string) $metadata['class'] ) : '';

		$attrs['className'] = trim( $class . ' ' . $scope );

		if ( '' !== $action && 0 === stripos( $action, 'mailto:' ) ) {
			$recipient = trim( substr( $action, 7 ) );
			$recipient = explode( '?', $recipient, 2 )[0];
			if ( '' !== $recipient && self::is_email( $recipient ) ) {
				$attrs['to'] = $recipient;
			}
		}

		return $attrs;
	}

	/** Stable generated classes are provider hooks, never source presentation hooks. */
	private static function layout_scope( array $form ): string {
		return 'ssi-form-' . substr( hash( 'sha256', (string) ( $form['source_path'] ?? '' ) . "\n" . (string) ( $form['selector'] ?? '' ) ), 0, 12 );
	}
	private static function layout_node_class( string $scope, string $node ): string {
		return 'ssi-node-' . substr( hash( 'sha256', $scope . "\n" . $node ), 0, 12 );
	}
	private static function provider_layout_target_map( array $form, string $scope ): array {
		$selector_scope = '.' . $scope;
		$targets        = array();
		foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$id = $node['id'];
			if ( 'form' !== $id && ! preg_match( '/^control-[0-9]+$/D', $id ) ) {
				continue;
			}
			$selector = 'form' === $id ? $selector_scope . ' > form.jetpack-contact-form__form' : $selector_scope . ' .' . self::layout_node_class( $scope, $id );
			// Jetpack's contact-form root includes hidden and error nodes, so it cannot
			// promise source direct-child relationships. Generated node hooks can.
			$capabilities = 'form' === $id ? array( 'container_layout', 'responsive_layout' ) : array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' );
			$targets[]    = array(
				'node'         => $id,
				'selector'     => $selector,
				'capabilities' => $capabilities,
			);
		}
		return array(
			'schema'   => Static_Site_Importer_Provider_Layout_Overlay::MAP_SCHEMA,
			'provider' => self::PROVIDER_ID,
			'scope'    => $selector_scope,
			'targets'  => $targets,
		);
	}

	/**
	 * Read a control label/text value.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return string
	 */
	private static function control_text( array $control ): string {
		foreach ( array( 'text', 'label', 'value', 'placeholder', 'name' ) as $key ) {
			if ( isset( $control[ $key ] ) && is_scalar( $control[ $key ] ) && '' !== trim( (string) $control[ $key ] ) ) {
				$text = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $control[ $key ] ) : strip_tags( (string) $control[ $key ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for runtime-free smoke tests.
				return substr( $text, 0, 200 );
			}
		}

		return '';
	}

	/**
	 * Extract option labels from a select/radio/checkbox control.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return array<int, string>
	 */
	private static function option_labels( array $control ): array {
		$options = isset( $control['options'] ) && is_array( $control['options'] ) ? $control['options'] : array();
		$labels  = array();

		foreach ( $options as $option ) {
			if ( is_array( $option ) ) {
				$label = isset( $option['label'] ) && is_scalar( $option['label'] ) ? trim( (string) $option['label'] ) : '';
				if ( '' === $label && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
					$label = trim( (string) $option['value'] );
				}
			} else {
				$label = is_scalar( $option ) ? trim( (string) $option ) : '';
			}

			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/** Serialize a generated block through WordPress's canonical block serializer. */
	private static function serialize_block( string $name, array $attrs, array $inner_blocks = array(), string $wrapper = '', string $content = '' ): string {
		return serialize_block( self::parsed_block( $name, $attrs, $inner_blocks, $wrapper, $content ) );
	}

	/** Build a parsed block, keeping Jetpack's required saved markup in innerContent. */
	private static function parsed_block( string $name, array $attrs, array $inner_blocks = array(), string $wrapper = '', string $content = '' ): array {
		$children = array();
		foreach ( $inner_blocks as $block ) {
			if ( ! empty( $block['name'] ) ) {
				$children[] = self::parsed_block(
					(string) $block['name'],
					isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
					isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(),
					isset( $block['wrapper'] ) && is_string( $block['wrapper'] ) ? $block['wrapper'] : '',
					isset( $block['content'] ) && is_string( $block['content'] ) ? $block['content'] : ''
				);
			}
		}

		$prefix = '';
		$suffix = '';
		if ( 'jetpack/contact-form' === $name ) {
			$classes = 'wp-block-jetpack-contact-form';
			if ( isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) && '' !== trim( (string) $attrs['className'] ) ) {
				$classes .= ' ' . trim( (string) $attrs['className'] );
			}
			$prefix = "\n<div class=\"" . self::escape_attribute( $classes ) . '">';
			$suffix = "</div>\n";
		} elseif ( 'submit' === $wrapper ) {
			$classes = trim( 'wp-block-button ' . (string) ( $attrs['className'] ?? '' ) );
			$prefix  = "\n<div class=\"" . self::escape_attribute( $classes ) . '"><button type="submit" class="wp-block-button__link wp-element-button">' . htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . "</button></div>\n";
		} elseif ( 'heading' === $wrapper ) {
			$level  = min( 6, max( 1, (int) ( $attrs['level'] ?? 2 ) ) );
			$prefix = "\n<h" . $level . ' class="wp-block-heading">' . htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</h' . $level . ">\n";
		} elseif ( 'paragraph' === $wrapper ) {
			$prefix = "\n<p>" . htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . "</p>\n";
		} elseif ( 'group' === $wrapper ) {
			$classes = 'wp-block-group' . ( ! empty( $attrs['className'] ) ? ' ' . $attrs['className'] : '' );
			if ( 'flex' === ( $attrs['layout']['type'] ?? '' ) ) {
				$classes .= ' is-layout-flex';
			}
			$id     = ! empty( $attrs['anchor'] ) ? ' id="' . self::escape_attribute( (string) $attrs['anchor'] ) . '"' : '';
			$tag    = ! empty( $attrs['tagName'] ) ? (string) $attrs['tagName'] : 'div';
			$prefix = "\n<" . $tag . $id . ' class="' . self::escape_attribute( $classes ) . '">';
			$suffix = '</' . $tag . ">\n";
		} elseif ( in_array( $wrapper, array( 'div', 'ul' ), true ) ) {
			$prefix = "\n<" . $wrapper . '>';
			$suffix = '</' . $wrapper . ">\n";
		}

		$inner_content = array();
		if ( '' !== $prefix || '' !== $suffix || ! empty( $children ) ) {
			$inner_content[] = $prefix;
			foreach ( $children as $index => $_child ) {
				$inner_content[] = null;
				if ( $index < count( $children ) - 1 ) {
					$inner_content[] = "\n";
				}
			}
			$inner_content[] = $suffix;
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Escape a block wrapper attribute without requiring WordPress to be loaded.
	 *
	 * @param string $value Raw attribute value.
	 * @return string
	 */
	private static function escape_attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Validate a candidate recipient email without requiring WordPress helpers.
	 *
	 * @param string $email Candidate email.
	 * @return bool
	 */
	private static function is_email( string $email ): bool {
		if ( function_exists( 'is_email' ) ) {
			return (bool) is_email( $email );
		}

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}
