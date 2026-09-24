<?php
/**
 * Field-block markup emit for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Jetpack_Forms_Runtime' ) ) {
	require_once __DIR__ . '/class-static-site-importer-jetpack-forms-runtime.php';
}

if ( ! class_exists( 'Static_Site_Importer_Provider_Form_Runtime_V1' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-form-runtime.php';
}

/**
 * Emits Jetpack field and contact-form block markup.
 */
final class Static_Site_Importer_Form_Field_Markup {
	/** Identify a provider-owned field companion without consuming ordinary buttons. */
	public static function is_provider_auxiliary_button( array $controls, int $control_index ): bool {
		$button = $controls[ $control_index ] ?? array();
		$next   = $controls[ $control_index + 1 ] ?? array();
		if ( ! is_array( $button ) || 'button' !== strtolower( trim( (string) ( $button['tag'] ?? '' ) ) ) ) {
			return false;
		}
		$popup = strtolower( trim( (string) ( $button['aria-haspopup'] ?? $button['aria_haspopup'] ?? '' ) ) );
		if ( 'listbox' === $popup && is_array( $next ) && in_array( strtolower( trim( (string) ( $next['type'] ?? '' ) ) ), array( 'tel', 'phone' ), true ) ) {
			return true;
		}
		$described = preg_split( '/\s+/', trim( (string) ( $button['aria_describedby'] ?? '' ) ) );
		if ( in_array( $popup, array( 'true', 'menu', 'tree', 'grid', 'dialog' ), true ) && false !== $described && ! empty( $described ) ) {
			foreach ( $controls as $field ) {
				if ( is_array( $field ) && ! empty( $field['readonly'] ) && in_array( (string) ( $field['label_id'] ?? '' ), $described, true ) ) {
					return true;
				}
			}
		}
		if ( ! is_array( $next ) || ! in_array( strtolower( trim( (string) ( $next['type'] ?? '' ) ) ), array( 'tel', 'phone' ), true ) ) {
			return false;
		}
		// Existing mobile captures expose this relationship only through their visible
		// provider control label. Retain that shipped contract until its producer emits
		// a typed replacement; incompatible popup values and ordinary buttons stay native.
		return ( '' === $popup || in_array( $popup, array( 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog' ), true ) )
			&& str_contains( strtolower( self::control_text( $button ) ), 'phone' )
			&& str_contains( strtolower( self::control_text( $button ) ), 'country' );
	}

	/**
	 * Aggregate only an exact labelled-fieldset radio topology that Jetpack can
	 * represent as one field. The generic topology supplies membership and its
	 * canonical binding supplies the source legend; no provider semantics are inferred.
	 *
	 * @return array{groups:array<int,array<string,mixed>>,suppressed_controls:array<int,bool>,represented_semantic_nodes:array<int,string>,operations:array<int,array<string,mixed>>}
	 */
	public static function labelled_radio_groups( array $form, array $controls ): array {
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
	 * Read labelled-fieldset legends from producer topology metadata.
	 *
	 * @param array<int,mixed> $nodes
	 * @return array<string,string>
	 */
	private static function labelled_fieldset_legends( array $form, array $nodes ): array {
		unset( $form );
		$legends = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'labelled_group' !== ( $node['fieldset_semantics'] ?? null ) || ! is_string( $node['id'] ?? null ) || ! is_string( $node['legend'] ?? null ) ) {
				continue;
			}
			$legend = preg_replace( '/\s+/', ' ', trim( $node['legend'] ) );
			if ( ! is_string( $legend ) || '' === $legend || 200 < strlen( $legend ) ) {
				return array();
			}
			$legends[ $node['id'] ] = $legend;
		}
		return $legends;
	}

	/**
	 * Build a Jetpack field block definition from a source control.
	 *
	 * @param string               $tag     Source control tag.
	 * @param string               $type    Source control type.
	 * @param array<string, mixed> $control Source control metadata.
	 * @return array<string, mixed>|null
	 */
	public static function field_block_from_control( string $tag, string $type, array $control, string $control_class = '', string $label_class = '' ): ?array {
		$map = Static_Site_Importer_Jetpack_Forms_Runtime::field_block_map();

		$lookup = 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'select' : $type );
		if ( 'select-multiple' === $type ) {
			$lookup = 'select';
		}

		if ( ! isset( $map[ $lookup ] ) ) {
			return null;
		}

		$attrs = array();
		$label = isset( $control['label'] ) && is_scalar( $control['label'] ) ? trim( (string) $control['label'] ) : '';
		if ( '' === $label && in_array( $lookup, array( 'checkbox', 'radio', 'select' ), true ) ) {
			$label = self::control_text( $control );
		}
		$description = self::control_description( $control );
		if ( '' !== $description && '' !== $label && str_ends_with( $label, $description ) ) {
			$label = trim( substr( $label, 0, -strlen( $description ) ) );
		}
		if ( '' !== $label && isset( $control['required_text'] ) && is_scalar( $control['label'] ?? null ) && 1 === preg_match( '/\s$/u', (string) $control['label'] ) ) {
			$label = rtrim( $label ) . ' ';
		}
		if ( ! empty( $control['required'] ) || 'true' === strtolower( trim( (string) ( $control['aria-required'] ?? $control['aria_required'] ?? '' ) ) ) ) {
			$attrs['required'] = true;
		}
		if ( false === ( $control['required_indicator'] ?? null ) && '' === trim( (string) ( $control['required_text'] ?? '' ) ) ) {
			$attrs['requiredIndicator'] = false;
		}
		$id = isset( $control['id'] ) && is_scalar( $control['id'] ) ? trim( (string) $control['id'] ) : '';
		if ( '' !== $id ) {
			$attrs['id'] = $id;
		}
		if ( in_array( $lookup, array( 'tel', 'phone' ), true ) ) {
			$attrs['showCountrySelector'] = 'phone' === $lookup;
		}
		$placeholder = isset( $control['placeholder'] ) && is_scalar( $control['placeholder'] ) ? trim( (string) $control['placeholder'] ) : '';
		if ( 'select' === $lookup && '' === $placeholder ) {
			$placeholder = self::select_placeholder_label( $control );
		}

		if ( in_array( $lookup, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$options = self::option_labels( $control, 'select' === $lookup );
			if ( ! empty( $options ) ) {
				$attrs['options'] = $options;
			}
		}

		$losses = array();
		if ( '' !== $description ) {
			// Every jetpack/field-* block declares this attribute (see
			// projects/packages/forms/src/blocks/shared/settings/index.js), but
			// rendering it is opt-in per field type: only a field whose edit
			// passes `helpTextSupport` shows the control in the editor, and only
			// a render_*_field() that calls get_field_descriptions() emits it on
			// the frontend (class-contact-form-field.php). Grouped fields -
			// checkbox and radio, materialized here as jetpack/field-checkbox(-multiple)
			// and jetpack/field-radio - accept the attribute without ever
			// displaying it, so storing it there would ship a value the visitor
			// never sees. Report that as the same unsupported-attribute loss an
			// unrepresentable numeric step already uses instead of doing that.
			if ( self::provider_renders_help_text( $lookup ) ) {
				$attrs['helpText'] = $description;
			} else {
				$losses[] = array(
					'dimension'         => 'control',
					'reason_code'       => 'unsupported_control_attribute',
					'attribute'         => 'description',
					'control_type_hash' => hash( 'sha256', $type ),
				);
			}
		}

		$inner_blocks = array();
		if ( 'checkbox' === $lookup && empty( $attrs['options'] ) ) {
			$inner_blocks[] = array(
				'name'  => 'jetpack/option',
				'attrs' => array_filter( array(
					'label'        => $label,
					'isStandalone' => true,
					'className'    => $label_class,
				) ),
			);
		} elseif ( '' !== $label ) {
			$label_attrs = array( 'label' => $label );
			if ( isset( $attrs['requiredIndicator'] ) ) {
				$label_attrs['requiredIndicator'] = $attrs['requiredIndicator'];
			}
			if ( ! empty( $attrs['required'] ) && is_string( $control['required_text'] ?? null ) && strlen( $control['required_text'] ) <= 32 ) {
				$label_attrs['requiredText'] = wp_strip_all_tags( $control['required_text'] );
			}
			$source_label_class = isset( $control['label_class'] ) && is_scalar( $control['label_class'] ) ? trim( (string) $control['label_class'] ) : '';
			$label_class        = trim( $source_label_class . ' ' . $label_class );
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
			$input_attrs  = array(
				'style' => array( 'border' => array( 'style' => 'solid' ) ),
			);
			$source_class = isset( $control['class'] ) && is_scalar( $control['class'] ) ? trim( (string) $control['class'] ) : '';
			$input_class  = trim( $source_class . ' ' . $control_class );
			if ( '' !== $input_class ) {
				$input_attrs['className'] = $input_class;
			}
			if ( '' !== $placeholder ) {
				$input_attrs['placeholder'] = $placeholder;
			}
			if ( 'textarea' === $lookup ) {
				$input_attrs['type'] = 'textarea';
				$rows                = self::textarea_rows( $control );
				if ( null !== $rows ) {
					$input_attrs['className'] = trim( (string) ( $input_attrs['className'] ?? '' ) . ' ssi-textarea-rows-' . $rows );
				}
				$height = isset( $control['height'] ) && is_scalar( $control['height'] ) ? trim( (string) $control['height'] ) : '';
				if ( '' !== $height && preg_match( '/^[0-9]{1,4}(?:\.[0-9]+)?(?:px|em|rem|vh|vw|%)$/D', $height ) ) {
					$input_attrs['style']['dimensions']['minHeight'] = $height;
				}
			} elseif ( 'select' === $lookup ) {
				$input_attrs['type'] = 'dropdown';
			}
			if ( 'number' === $lookup ) {
				foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
					if ( ! isset( $control[ $attribute ] ) || ! is_scalar( $control[ $attribute ] ) || '' === trim( (string) $control[ $attribute ] ) ) {
						continue;
					}
					if ( Static_Site_Importer_Jetpack_Forms_Runtime::input_supports_attribute( $lookup, $attribute ) ) {
						$value                     = trim( (string) $control[ $attribute ] );
						$input_attrs[ $attribute ] = is_numeric( $value ) ? 0 + $value : $value;
						continue;
					}
					$losses[] = array(
						'dimension'         => 'control',
						'reason_code'       => 'unsupported_control_attribute',
						'attribute'         => $attribute,
						'control_type_hash' => hash( 'sha256', $type ),
					);
				}
			}
			$inner_blocks[] = array(
				'name'  => in_array( $lookup, array( 'tel', 'phone' ), true ) ? 'jetpack/phone-input' : 'jetpack/input',
				'attrs' => $input_attrs,
			);
		}
		$block_name                    = 'checkbox' === $lookup && ! empty( $attrs['options'] ) ? 'jetpack/field-checkbox-multiple' : $map[ $lookup ];
		$attrs['shareFieldAttributes'] = false;
		return array(
			'name'        => $block_name,
			'attrs'       => $attrs,
			'innerBlocks' => $inner_blocks,
			'wrapper'     => 'div',
			'losses'      => $losses,
		);
	}

	/**
	 * Read a textarea's authored row count, defaulting to the HTML unset-rows
	 * value of 2. Jetpack does not expose a rows block attribute; this value
	 * is carried as an `ssi-textarea-rows-N` class onto `jetpack/input` and
	 * projected onto the rendered control at runtime.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 */
	public static function textarea_rows( array $control ): ?int {
		$rows = isset( $control['rows'] ) && is_scalar( $control['rows'] ) ? (int) $control['rows'] : 2;
		return ( $rows >= 1 && $rows <= 50 ) ? $rows : null;
	}

	/**
	 * Read a control's own source-authored description, distinct from its label.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return string
	 */
	private static function control_description( array $control ): string {
		if ( ! isset( $control['description'] ) || ! is_scalar( $control['description'] ) ) {
			return '';
		}
		$description = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $control['description'] ) : strip_tags( (string) $control['description'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback only for runtime-free smoke tests.
		return substr( $description, 0, 240 );
	}

	/**
	 * Return whether the mapped Jetpack field block ever displays its shared
	 * `helpText` attribute.
	 *
	 * Every `jetpack/field-*` block declares the attribute so it survives a
	 * type change, but only a field whose editor `edit` component passes
	 * `helpTextSupport` shows the control (jetpack-field-controls.jsx), and
	 * only a `render_*_field()` that calls `get_field_descriptions()` emits it
	 * on the frontend (class-contact-form-field.php). Grouped fields -
	 * checkbox and radio - are the deliberate exception: they carry the
	 * attribute so switching a field's type and back does not discard the
	 * author's text, but neither their editor nor their frontend renderer
	 * ever shows it.
	 *
	 * @param string $lookup Resolved field-block lookup key.
	 * @return bool
	 */
	private static function provider_renders_help_text( string $lookup ): bool {
		return ! in_array( $lookup, array( 'checkbox', 'radio' ), true );
	}

	/**
	 * Build the Jetpack submit button block.
	 *
	 * @param string $text Submit button label.
	 * @param string $class_name Wrapper class list.
	 * @param array<string, mixed> $presentation Source submit presentation: text
	 *   label element classes and marker, captured leading inline icon parts,
	 *   and provider block attrs.
	 * @return array<string, mixed>
	 */
	public static function submit_button_block( string $text, string $class_name = '', array $presentation = array() ): array {
		$source_classes = isset( $presentation['classes'] ) && is_array( $presentation['classes'] ) ? array_filter( $presentation['classes'], 'is_string' ) : array();
		$source_markers = implode( ' ', array_map( static fn ( string $source_class ): string => 'ssi-source-submit--' . $source_class, $source_classes ) );
		$block_style    = isset( $presentation['block_attrs']['style'] ) && is_array( $presentation['block_attrs']['style'] ) ? $presentation['block_attrs']['style'] : array();
		$class_name     = trim( 'form-button-submit is-submit ' . $source_markers . ' ' . ( empty( $block_style ) ? '' : 'ssi-provider-submit-presentation' ) . ' ' . $class_name );
		$attrs          = array(
			'tagName'   => 'button',
			'type'      => 'submit',
			'lock'      => array(
				'remove' => true,
			),
			'className' => $class_name,
			'metadata'  => array( 'name' => 'Submit button' ),
		);
		// The source governs this button through its own classes and stylesheet,
		// so the block does not claim those styles as attributes. Claiming them
		// made the saved markup disagree with core's save() output, which is
		// what marked every imported form dirty in the editor.
		$block = array(
			'name'    => 'core/button',
			'attrs'   => $attrs,
			'content' => '' !== trim( $text ) ? trim( $text ) : 'Submit',
			'wrapper' => 'submit',
		);
		// The source can own the label's typography through its own inline element,
		// which sizes the rendered line box. Declare that element so the serializer
		// reproduces it; the text itself stays plain and escaped.
		if ( isset( $presentation['label_classes'] ) && is_array( $presentation['label_classes'] ) ) {
			$block['label'] = array(
				'classes' => $presentation['label_classes'],
				'marker'  => isset( $presentation['label_marker'] ) && is_scalar( $presentation['label_marker'] ) ? (string) $presentation['label_marker'] : '',
			);
		}
		// The source button can own a leading inline icon drawn inside its own
		// control box, ahead of the label; the authored gap between them already
		// travels with the button's projected classes. Only parts that pass the
		// portable inline-SVG admission are kept, so the serializer reproduces
		// exactly the bounded markup the producer captured.
		$icon_parts = isset( $presentation['icon'] ) && is_array( $presentation['icon'] ) ? $presentation['icon'] : array();
		if ( ! empty( $icon_parts ) ) {
			$icon_parts = Static_Site_Importer_Provider_Form_Runtime_V1::valid_icon_parts( $icon_parts );
			if ( ! empty( $icon_parts ) ) {
				$block['icon'] = array( 'parts' => $icon_parts );
			}
		}

		return $block;
	}

	/** Build a non-submitting source button without changing the form's submission action. */
	public static function button_block( string $text, string $class_name = '' ): array {
		return array(
			'name'    => 'core/button',
			'attrs'   => array(
				'tagName'   => 'button',
				'type'      => 'button',
				'lock'      => array( 'remove' => true ),
				'className' => $class_name,
				'metadata'  => array( 'name' => 'Button' ),
			),
			'content' => '' !== trim( $text ) ? trim( $text ) : 'Button',
			'wrapper' => 'button',
		);
	}

	/**
	 * Build inner blocks for copy the producer recorded inside the form.
	 *
	 * `context_before` / `context_after` are in-form content (a heading above the
	 * fields, a required-field note). They belong inside `jetpack/contact-form`,
	 * which accepts `core/heading` and `core/paragraph`. Copy outside the form
	 * element is never stored here; it is already a page-level sibling.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function context_blocks( array $form, string $position ): array {
		$context = isset( $form['form'][ $position ] ) && is_array( $form['form'][ $position ] ) ? $form['form'][ $position ] : array();
		$blocks  = array();
		foreach ( $context as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['text'] ?? null ) || '' === trim( $block['text'] ) ) {
				continue;
			}
			if ( 'heading' === ( $block['type'] ?? null ) ) {
				$level = min( 6, max( 1, (int) ( $block['level'] ?? 2 ) ) );
				$attrs = 2 === $level ? array() : array( 'level' => $level );
				$class = isset( $block['class'] ) && is_scalar( $block['class'] ) ? trim( (string) $block['class'] ) : '';
				if ( '' !== $class ) {
					$attrs['className'] = $class;
				}
				$blocks[] = array(
					'name'    => 'core/heading',
					'attrs'   => $attrs,
					'wrapper' => 'heading',
					'content' => $block['text'],
				);
			} elseif ( 'paragraph' === ( $block['type'] ?? null ) ) {
				$blocks[] = array(
					'name'    => 'core/paragraph',
					'wrapper' => 'paragraph',
					'content' => $block['text'],
				);
			}
		}
		return $blocks;
	}

	/** Serialize in-form context as editable core blocks. */
	public static function context_block_markup( array $form, string $position ): string {
		$markup = '';
		foreach ( self::context_blocks( $form, $position ) as $block ) {
			$markup .= self::serialize_block( $block );
		}
		return $markup;
	}

	/**
	 * Resolve the contact-form block attributes from source form metadata.
	 *
	 * @param array<string, mixed> $form Validated form row.
	 * @return array<string, mixed>
	 */
	public static function contact_form_attributes( array $form, string $scope = '', array $topology_classes = array() ): array {
		$attrs    = array();
		$metadata = isset( $form['form'] ) && is_array( $form['form'] ) ? $form['form'] : array();
		$action   = isset( $metadata['action'] ) && is_scalar( $metadata['action'] ) ? trim( (string) $metadata['action'] ) : '';
		$class    = isset( $metadata['class'] ) && is_scalar( $metadata['class'] ) ? trim( (string) $metadata['class'] ) : '';

		$attrs['className'] = implode( ' ', array_filter( array( $class, implode( ' ', array_filter( $topology_classes, 'is_string' ) ), $scope ), static fn ( string $value ): bool => '' !== trim( $value ) ) );

		if ( '' !== $action && 0 === stripos( $action, 'mailto:' ) ) {
			$recipient = trim( substr( $action, 7 ) );
			$recipient = explode( '?', $recipient, 2 )[0];
			if ( '' !== $recipient && self::is_email( $recipient ) ) {
				$attrs['to'] = $recipient;
			}
		}

		return $attrs;
	}

	/**
	 * Read a control label/text value.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return string
	 */
	public static function control_text( array $control ): string {
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
	 * @param array<string, mixed> $control            Source control metadata.
	 * @param bool                 $omit_placeholders  Whether source placeholder options stay off the list.
	 * @return array<int, string>
	 */
	private static function option_labels( array $control, bool $omit_placeholders = false ): array {
		$options = isset( $control['options'] ) && is_array( $control['options'] ) ? $control['options'] : array();
		$labels  = array();

		foreach ( $options as $option ) {
			if ( is_array( $option ) ) {
				if ( $omit_placeholders && ! empty( $option['placeholder'] ) ) {
					continue;
				}
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

	/**
	 * Read a select's source-authored placeholder option, mapped onto Jetpack's
	 * input placeholder / togglelabel rather than kept as a real option.
	 *
	 * Jetpack prepends a synthetic "Select an option" unless togglelabel or a
	 * default is set. The source's empty-value disabled option is that prompt.
	 *
	 * @param array<string, mixed> $control Source control metadata.
	 * @return string
	 */
	private static function select_placeholder_label( array $control ): string {
		$options = isset( $control['options'] ) && is_array( $control['options'] ) ? $control['options'] : array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || empty( $option['placeholder'] ) ) {
				continue;
			}
			$label = isset( $option['label'] ) && is_scalar( $option['label'] ) ? trim( (string) $option['label'] ) : '';
			if ( '' === $label && isset( $option['value'] ) && is_scalar( $option['value'] ) ) {
				$label = trim( (string) $option['value'] );
			}
			if ( '' !== $label ) {
				return substr( $label, 0, 200 );
			}
		}

		return '';
	}

	/**
	 * Serialize a generated block through WordPress's canonical block serializer.
	 *
	 * @param array<string,mixed> $block Generated block: name, attrs, innerBlocks, wrapper, content, label, icon.
	 */
	public static function serialize_block( array $block ): string {
		return serialize_block( self::parsed_block( $block ) );
	}

	/**
	 * Build a parsed block, keeping Jetpack's required saved markup in innerContent.
	 *
	 * Generated blocks travel as one array so a child is recursed without being
	 * taken apart and reassembled, and so saved-markup inputs stay named at every
	 * level instead of arriving positionally.
	 *
	 * @param array<string,mixed> $block Generated block.
	 */
	private static function parsed_block( array $block ): array {
		if ( is_array( $block['_parsed_block'] ?? null ) ) {
			return $block['_parsed_block'];
		}
		$name         = isset( $block['name'] ) ? (string) $block['name'] : '';
		$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$wrapper      = isset( $block['wrapper'] ) && is_string( $block['wrapper'] ) ? $block['wrapper'] : '';
		$content      = isset( $block['content'] ) && is_string( $block['content'] ) ? $block['content'] : '';
		$label        = isset( $block['label'] ) && is_array( $block['label'] ) ? $block['label'] : null;
		// Core groups have canonical saved markup. Keep this serializer contract owned
		// here so topology callers cannot accidentally emit comment-only groups.
		if ( 'core/group' === $name && '' === $wrapper ) {
			$wrapper = 'group';
		}
		$children = array();
		foreach ( $inner_blocks as $child ) {
			if ( is_array( $child ) && ! empty( $child['name'] ) ) {
				$children[] = self::parsed_block( $child );
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
		} elseif ( in_array( $wrapper, array( 'submit', 'button' ), true ) ) {
			$classes = trim( 'wp-block-button ' . (string) ( $attrs['className'] ?? '' ) );
			$type    = 'submit' === $wrapper ? 'submit' : 'button';
			$icon    = self::icon_markup( $block['icon'] ?? null );
			$prefix  = "\n<div class=\"" . self::escape_attribute( $classes ) . '"><button type="' . $type . '" class="wp-block-button__link wp-element-button">' . $icon . self::rich_text_markup( $content, $label ) . "</button></div>\n";
		} elseif ( 'heading' === $wrapper ) {
			$level   = min( 6, max( 1, (int) ( $attrs['level'] ?? 2 ) ) );
			$classes = 'wp-block-heading';
			if ( isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) && '' !== trim( (string) $attrs['className'] ) ) {
				$classes .= ' ' . trim( (string) $attrs['className'] );
			}
			$prefix = "\n<h" . $level . ' class="' . self::escape_attribute( $classes ) . '">' . self::rich_text_markup( $content ) . '</h' . $level . ">\n";
		} elseif ( 'paragraph' === $wrapper ) {
			$prefix = "\n<p>" . self::rich_text_markup( $content ) . "</p>\n";
		} elseif ( 'group' === $wrapper ) {
			$classes = 'wp-block-group' . ( ! empty( $attrs['className'] ) ? ' ' . $attrs['className'] : '' );
			if ( 'flex' === ( $attrs['layout']['type'] ?? '' ) ) {
				$classes .= ' is-layout-flex';
			}
			$id    = ! empty( $attrs['anchor'] ) ? ' id="' . self::escape_attribute( (string) $attrs['anchor'] ) . '"' : '';
			$tag   = ! empty( $attrs['tagName'] ) ? (string) $attrs['tagName'] : 'div';
			$style = '';
			foreach ( array( 'top', 'bottom' ) as $side ) {
				if ( isset( $attrs['style']['spacing']['margin'][ $side ] ) ) {
					$style .= 'margin-' . $side . ':' . $attrs['style']['spacing']['margin'][ $side ] . ';';
				}
			}
			$prefix = "\n<" . $tag . $id . ' class="' . self::escape_attribute( $classes ) . '"' . ( '' !== $style ? ' style="' . self::escape_attribute( rtrim( $style, ';' ) ) . '"' : '' ) . '>';
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
	 * Build the saved markup for a source submit's leading inline icon.
	 *
	 * The icon is authored control content, captured by the producer as
	 * bounded validated SVG parts. Each part is re-admitted here so only the
	 * portable subset ever reaches the saved markup; an invalid or oversized
	 * part is dropped, never repaired.
	 *
	 * @param array<string,mixed>|null $icon Captured icon parts.
	 * @return string
	 */
	private static function icon_markup( ?array $icon ): string {
		if ( null === $icon || ! isset( $icon['parts'] ) || ! is_array( $icon['parts'] ) ) {
			return '';
		}
		$parts = Static_Site_Importer_Provider_Form_Runtime_V1::valid_icon_parts( $icon['parts'] );
		return implode( '', $parts );
	}

	/**
	 * Build the saved rich-text markup for a generated block.
	 *
	 * Block text is authored content and is always escaped here, which keeps a
	 * single owner for that decision. A source can also carry its text inside its
	 * own inline element, which authored rules address as a descendant, so that
	 * element is reproduced from validated class tokens rather than by trusting a
	 * caller-supplied markup string.
	 *
	 * @param string                   $text  Plain block text.
	 * @param array<string,mixed>|null $label Source label element, when the text owns one.
	 * @return string
	 */
	private static function rich_text_markup( string $text, ?array $label = null ): string {
		$markup = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		if ( null === $label ) {
			return $markup;
		}
		$classes = array_values(
			array_filter(
				isset( $label['classes'] ) && is_array( $label['classes'] ) ? $label['classes'] : array(),
				static fn ( $class_name ): bool => is_string( $class_name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class_name )
			)
		);
		// Author rules that addressed this element are projected onto its compiler
		// marker, so the marker is reproduced with it.
		$marker     = isset( $label['marker'] ) && is_string( $label['marker'] ) && 1 === preg_match( '/^blocks-engine-richtext-[a-f0-9]{6,32}-[0-9]{1,4}$/D', $label['marker'] ) ? $label['marker'] : '';
		$attributes = ( array() === $classes ? '' : ' class="' . implode( ' ', $classes ) . '"' )
			. ( '' === $marker ? '' : ' data-blocks-engine-richtext-marker="' . $marker . '"' );

		return '<span' . $attributes . '>' . $markup . '</span>';
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
