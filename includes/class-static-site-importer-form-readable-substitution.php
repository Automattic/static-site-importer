<?php
/**
 * Substitute provider fields into the producer's indexed, parsed block tree.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native wrappers own topology; the provider owns only field semantics. */
class Static_Site_Importer_Form_Readable_Substitution {
	private const LABEL_TEXT_FACTS = array( 'color', 'font', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'letter_spacing', 'line_height', 'text_align', 'text_decoration', 'text_transform' );

	/** How a field-box label arranges its caption and control. */
	private const FIELD_BOX_ARRANGEMENT_FACTS = array( 'display', 'flex_direction', 'gap', 'align_items', 'justify_content' );

	/** Older or partial producer trees must retain the projection path. */
	public static function supports( array $form ): bool {
		$tree = $form['readable_blocks'] ?? array();
		if ( ! is_array( $tree ) || 1 !== count( $tree ) || 'core/group' !== ( $tree[0]['blockName'] ?? '' ) || 'form' !== ( $tree[0]['attrs']['tagName'] ?? '' ) ) {
			return false;
		}
		$indices = array();
		$groups  = array();
		$valid   = true;
		$walk    = static function ( array $block ) use ( &$walk, &$indices, &$groups, &$valid, $form ): void {
			foreach ( array(
				'form_control_index'      => 'controls',
				'form_choice_group_index' => 'choice_groups',
			) as $key => $collection ) {
				if ( array_key_exists( $key, $block ) ) {
					$index = $block[ $key ];
					if ( ! is_int( $index ) || ! isset( $form[ $collection ][ $index ] ) ) {
						$valid = false;
						continue;
					}
					if ( 'controls' === $collection ) {
						$valid             = $valid && ! isset( $indices[ $index ] );
						$indices[ $index ] = true;
					} else {
						$valid            = $valid && ! isset( $groups[ $index ] );
						$groups[ $index ] = true;
					}
				}
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$walk( $child );
			}
		};
		$walk( $tree[0] );
		return $valid && count( $indices ) === count( $form['controls'] ?? array() ) && count( $groups ) === count( $form['choice_groups'] ?? array() );
	}

	/**
	 * A source label that wraps its control is that field's box, which the
	 * producer's field-box shell already states with the source classes. The
	 * provider caption keeps only the label's text, the provider field
	 * wrapper takes the shell's arrangement as if absent, and the box's
	 * paint stays on the shell alone.
	 *
	 * @param array<string,mixed> $descriptor Field presentation descriptor.
	 * @return array<string,mixed>
	 */
	public static function with_field_box_label( array $descriptor, string $scope, int $index ): array {
		$destinations = array();
		foreach ( $descriptor['destinations'] as $destination ) {
			$part = $destination['part'] ?? null;
			if ( in_array( $part, array( 'control_container', 'wrapping_label_reset' ), true ) ) {
				continue;
			}
			if ( 'label' === $part ) {
				$destination['properties'] = array_values( array_intersect( $destination['properties'], self::LABEL_TEXT_FACTS ) );
			}
			if ( 'control' === $part ) {
				// The field box stretches an auto-width control as the source label
				// did, and keeps its intrinsic width as the box's floor. The
				// provider's percentage width drops that floor, so a row of field
				// boxes would stay on one line where the source wraps.
				$destination['resets']['width'] = 'auto';
			}
			$destinations[] = $destination;
		}
		// The provider field wrapper arranges the caption and control as the
		// source label did; the shell states the rest of that box.
		$destinations[] = array(
			'part'       => 'field_box_wrapper',
			'role'       => 'label',
			// Jetpack renders the field block's class with a `-wrap` suffix.
			'selector'   => '.' . $scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $scope, 'control-' . $index ) . '-wrap',
			'properties' => self::FIELD_BOX_ARRANGEMENT_FACTS,
			'resets'     => array(
				'margin'     => '0',
				'padding'    => '0',
				'border'     => '0',
				'background' => 'transparent',
			),
			'priority'   => 'important',
			// The editor field block is `display: contents`; its control box holds the caption and control.
			'editor'     => array( 'retarget' => array( '-wrap', ' > div.jetpack-field__control' ) ),
		);
		$descriptor['destinations'] = $destinations;
		return $descriptor;
	}

	/**
	 * The provider renders its caption before every control. A source caption
	 * that follows its control is restored to that place: the field box lays
	 * its children out in a column, and the caption is ordered after the
	 * control and keeps the full row a block caption has.
	 *
	 * @param array<string,mixed> $descriptor Field presentation descriptor.
	 * @param bool                $field_box  Whether the wrapping label already arranges the field box.
	 * @return array<string,mixed>
	 */
	public static function with_caption_after_control( array $descriptor, string $scope, int $index, bool $field_box ): array {
		// Jetpack renders the field block's class with a `-wrap` suffix.
		$wrapper = '.' . $scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $scope, 'control-' . $index ) . '-wrap';
		if ( ! $field_box ) {
			$descriptor['destinations'][] = array(
				'part'       => 'caption_after_control_box',
				'role'       => 'label',
				'selector'   => $wrapper,
				'properties' => array(),
				'resets'     => array(
					'display'        => 'flex',
					'flex-direction' => 'column',
					'align-items'    => 'flex-start',
				),
				// The editor field block is `display: contents`; its control box holds the caption and control.
				'editor'     => array( 'retarget' => array( '-wrap', ' > div.jetpack-field__control' ) ),
			);
		}
		$descriptor['destinations'][] = array(
			'part'       => 'caption_after_control',
			'role'       => 'label',
			'selector'   => $wrapper . ' > label',
			'properties' => array(),
			'resets'     => array(
				'order'      => '1',
				'align-self' => 'stretch',
			),
			// The editor caption is the label block inside that control box.
			'editor'     => array( 'retarget' => array( '-wrap > label', ' > div.jetpack-field__control > .wp-block-jetpack-label' ) ),
		);
		$descriptor['has_presentation'] = true;
		return $descriptor;
	}

	/**
	 * The presentation facts of each field-box label the provider carries: its
	 * text on the caption, its arrangement on the field wrapper. The shell
	 * states the rest of that box.
	 *
	 * @param array<string,mixed> $graph   Presentation graph.
	 * @param array<int,true>     $indices Controls whose label is their field box.
	 * @return array<string,mixed>
	 */
	public static function field_box_presentation( array $graph, array $indices ): array {
		$text = array_flip( array_merge( self::LABEL_TEXT_FACTS, self::FIELD_BOX_ARRANGEMENT_FACTS ) );
		foreach ( $graph['controls'] ?? array() as $key => $control ) {
			if ( isset( $indices[ $control['index'] ?? -1 ] ) ) {
				unset( $graph['controls'][ $key ]['control_container'] );
				if ( is_array( $control['label']['styles'] ?? null ) ) {
					$graph['controls'][ $key ]['label']['styles'] = array_intersect_key( $control['label']['styles'], $text );
				}
			}
		}
		foreach ( $graph['variants'] ?? array() as $key => $variant ) {
			if ( isset( $indices[ $variant['index'] ?? -1 ] ) && in_array( $variant['role'] ?? null, array( 'label', 'control_container' ), true ) ) {
				$patch = 'label' === $variant['role'] ? array_intersect_key( $variant['style_patch'] ?? array(), $text ) : array();
				if ( array() === $patch ) {
					unset( $graph['variants'][ $key ] );
				} else {
					$graph['variants'][ $key ]['style_patch'] = $patch;
				}
			}
		}
		if ( isset( $graph['variants'] ) ) {
			$graph['variants'] = array_values( $graph['variants'] );
		}
		return $graph;
	}

	/**
	 * The source submit is one element, both the item its layout box places
	 * and the painted button; core/button splits it into a wrapper and a link.
	 * The wrapper takes the source button's place: it carries the source
	 * classes, so every source rule that places or sizes the button reaches
	 * it, with the button's own box (an inline-block by default) and none of
	 * its paint. The link fills the wrapper and keeps the paint.
	 *
	 * @param array<string,mixed> $descriptor Submit presentation descriptor.
	 * @return array<string,mixed>
	 */
	public static function with_source_box_submit_wrapper( array $descriptor ): array {
		foreach ( $descriptor['destinations'] as $index => $destination ) {
			$part = $destination['part'] ?? null;
			if ( 'submit_wrapper' === $part ) {
				$descriptor['destinations'][ $index ]['resets'] = array(
					'display'    => 'inline-block',
					// A source button is never capped by its container; Jetpack's
					// editor caps every field block at 100%.
					'max-width'  => 'none',
					'padding'    => '0',
					'border'     => '0',
					'background' => 'transparent',
					// Jetpack's editor sets the button block's line-height.
					'font'       => 'inherit',
				);
			} elseif ( 'submit_link' === $part ) {
				$descriptor['destinations'][ $index ]['resets'] += array(
					// A block <button> still shrinks to its content.
					'display' => 'block',
					'width'   => '100%',
					'margin'  => '0',
				);
			}
		}
		return $descriptor;
	}

	/** Preserve saved wrapper fragments, changing only their child slots. */
	public static function substitute( array $block, Static_Site_Importer_Form_Plan_State $state ): ?array {
		if ( isset( $block['form_choice_group_index'] ) ) {
			return self::choice_field( $block, $state );
		}
		if ( isset( $block['form_control_index'] ) ) {
			$index = $block['form_control_index'];
			if ( isset( $state->suppressed_controls[ $index ] ) || ! isset( $state->field_blocks[ $index ] ) ) {
				return null;
			}
			$field = $state->field_blocks[ $index ];
			if ( 'core/button' === $field['name'] && preg_match_all( '/(?:^|\s)ssi-source-submit--(\S+)/', $field['attrs']['className'] ?? '', $source ) ) {
				// The wrapper is the source button's box (with_source_box_submit_wrapper()).
				$field['attrs']['className'] .= ' ' . implode( ' ', $source[1] );
			}
			return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
		}
		$children = array();
		$slots    = array();
		$index    = 0;
		$legend   = self::legend_block( $block );
		foreach ( $block['innerContent'] ?? array() as $part ) {
			if ( null !== $part ) {
				$slots[] = $part;
				if ( null !== $legend ) {
					// The producer states a fieldset's legend once, as the group's
					// label; it renders first, inside the group's opening markup.
					$children[] = $legend;
					$slots[]    = null;
					$legend     = null;
				}
				continue;
			}
			$child = self::substitute( $block['innerBlocks'][ $index++ ], $state );
			if ( null !== $child ) {
				$children[] = $child;
				$slots[]    = null;
			}
		}
		// A wrapper left holding nothing held only suppressed controls: it was
		// their field box, which renders with them or not at all.
		if ( 0 < $index && array() === $children ) {
			return null;
		}
		$block['innerBlocks']  = $children;
		$block['innerContent'] = $slots;
		return $block;
	}

	/**
	 * A captured choice group as the provider's choice field.
	 *
	 * The field is the group: a fieldset framing the group names it with its
	 * legend, and the choice the source shows selected is the field's default.
	 * Each option keeps a hook for its source choice's presentation, and the
	 * option list one for the source row that laid the choices out
	 * (choice_presentation()).
	 *
	 * @param array<string,mixed> $block Readable block indexed as the choice group.
	 * @return array<string,mixed>
	 */
	private static function choice_field( array $block, Static_Site_Importer_Form_Plan_State $state ): array {
		$choice   = $state->form['choice_groups'][ $block['form_choice_group_index'] ];
		$options  = array_map(
			static fn( array $option ): array => array(
				'label' => $option['label'],
				'value' => $option['source_value'] ?? $option['label'],
			),
			$choice['choices']
		);
		$selected = $choice['observed_transition']['selected'] ?? array();
		$multiple = 'multiple' === ( $choice['group']['selection_mode'] ?? $choice['group']['mode'] ?? '' )
			|| 1 < count( array_filter( is_array( $selected ) ? $selected : array(), static fn( mixed $value ): bool => true === $value ) );
		$label    = is_string( $choice['group']['label'] ?? null ) ? trim( $choice['group']['label'] ) : '';
		if ( '' === $label && 'group' === ( $block['form_field']['kind'] ?? null ) && is_string( $block['form_field']['label'] ?? null ) ) {
			$label = trim( html_entity_decode( wp_strip_all_tags( $block['form_field']['label'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		$field                       = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
			'input',
			$multiple ? 'checkbox' : 'radio',
			array(
				'label'   => $label,
				'options' => $options,
			)
		);
		$token                       = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options );
		$field['attrs']['className'] = trim( 'is-style-button ' . $token );
		$selected_index              = $choice['observed_transition']['selected_index'] ?? null;
		if ( ! $multiple && is_int( $selected_index ) && isset( $options[ $selected_index ] ) ) {
			$field['attrs']['defaultValue'] = $options[ $selected_index ]['label'];
		}
		$controls = self::control_indices( $block );
		if ( count( $controls ) === count( $options ) ) {
			$row = self::choice_row( $state->form, $controls );
			foreach ( $field['innerBlocks'] as &$inner ) {
				if ( 'jetpack/options' !== $inner['name'] ) {
					continue;
				}
				if ( null !== $row ) {
					$inner['attrs']['className'] = Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, $row );
				}
				foreach ( $inner['innerBlocks'] as $position => &$option ) {
					$option['attrs']['className'] = Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $controls[ $position ] );
				}
				unset( $option );
			}
			unset( $inner );
			$state->choice_groups[] = array(
				'controls' => $controls,
				'row'      => $row,
				'selected' => is_array( $selected ) ? array_values( $selected ) : array(),
			);
		}
		$state->mapped_types[] = $field['name'];
		return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
	}

	/**
	 * The presentation of each substituted choice group, on the provider's options.
	 *
	 * Each source choice paints its own option, and the source row that laid the
	 * choices out lays out the option list. The source shows one selection; a
	 * choice's paint is a state when every choice the source shows unselected
	 * paints alike, and so does every selected one. Every option then takes the
	 * unselected paint, and the checked option the selected paint's difference.
	 *
	 * @param array<string,mixed> $graph Producer presentation graph.
	 * @return array{descriptors:array<int,array<string,mixed>>,controls:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,layout_nodes:array<int,array<string,mixed>>,layout_targets:array<int,array<string,mixed>>}
	 */
	public static function choice_presentation( Static_Site_Importer_Form_Plan_State $state, array $graph ): array {
		$out  = array(
			'descriptors'  => array(),
			'controls'     => array(),
			'variants'     => array(),
			'layout_nodes' => array(),
			'layout_targets' => array(),
		);
		$rows = array();
		foreach ( $graph['controls'] ?? array() as $row ) {
			if ( is_array( $row ) && is_int( $row['index'] ?? null ) && is_array( $row['control']['styles'] ?? null ) ) {
				$rows[ $row['index'] ] = $row;
			}
		}
		$nodes = array();
		foreach ( $state->form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = $node;
			}
		}
		foreach ( $state->choice_groups as $group ) {
			$row_id = $group['row'];
			if ( null !== $row_id && is_array( $nodes[ $row_id ]['layout'] ?? null ) ) {
				$node           = $nodes[ $row_id ];
				$node['parent'] = null;
				// The provider list is not a flex row, so the row states what its
				// source flex box leaves at the initial values.
				$node['layout']         += 'flex' === ( $node['layout']['display'] ?? null ) ? array(
					'direction'   => 'row',
					'wrap'        => 'nowrap',
					'align_items' => 'stretch',
				) : array();
				$out['layout_nodes'][]   = $node;
				$out['layout_targets'][] = array(
					'part'         => 'box',
					'node'         => $row_id,
					'selector'     => '.' . $state->scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, $row_id ),
					'capabilities' => Static_Site_Importer_Form_Presentation_Overlay::LAYOUT_CAPABILITIES,
				);
			}
			$painted = array_values( array_filter( $group['controls'], static fn( int $index ): bool => isset( $rows[ $index ] ) ) );
			if ( count( $painted ) !== count( $group['controls'] ) ) {
				continue;
			}
			// A source button sizes its box with its border (the browser default the
			// capture does not restate); the provider option is a label, sized by content.
			foreach ( $group['controls'] as $index ) {
				if ( 'button' === ( $state->form['controls'][ $index ]['tag'] ?? null ) ) {
					$rows[ $index ]['control']['styles'] += array( 'box_sizing' => 'border-box' );
				}
			}
			$state_paint = self::selection_paint( $group, $rows );
			foreach ( $group['controls'] as $position => $index ) {
				$hook        = '.' . $state->scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $index );
				$destination = array(
					'part'       => 'choice_option',
					'role'       => 'control',
					'selector'   => $hook,
					'properties' => array_keys( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() ),
					'resets'     => array(),
					'priority'   => 'important',
					// The editor option is the option block, which carries the same class.
					'editor'     => array( 'selector' => '{scope} .' . Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, 'control-' . $index ) ),
				);
				$row = $rows[ $index ];
				if ( null !== $state_paint ) {
					$destination['checked']           = $hook . ':has(> input:checked)';
					$destination['editor']['checked'] = true === ( $group['selected'][ $position ] ?? null );
					$row['control']                   = $state_paint['unselected'];
					if ( array() !== $state_paint['patch']['style_patch'] ) {
						$out['variants'][] = array( 'index' => $index ) + $state_paint['patch'];
					}
				}
				$out['controls'][]            = $row;
				$out['descriptors'][ $index ] = array(
					'has_presentation'   => true,
					'control_class'      => '',
					'label_class'        => '',
					'phone_destinations' => array(),
					'destinations'       => array( $destination ),
				);
			}
		}
		return $out;
	}

	/**
	 * A choice group's unselected paint, and the selected paint's difference
	 * from it, when the source shows both and each state paints alike.
	 *
	 * @param array{controls:array<int,int>,selected:array<int,?bool>} $group Choice group.
	 * @param array<int,array<string,mixed>>                           $rows  Presentation rows by control index.
	 * @return array{unselected:array<string,mixed>,patch:array<string,mixed>}|null
	 */
	private static function selection_paint( array $group, array $rows ): ?array {
		$states = array();
		foreach ( $group['controls'] as $position => $index ) {
			$selected = $group['selected'][ $position ] ?? null;
			if ( ! is_bool( $selected ) ) {
				return null;
			}
			$states[ (int) $selected ][] = $rows[ $index ]['control'];
		}
		if ( ! isset( $states[0], $states[1] ) ) {
			return null;
		}
		foreach ( $states as $paints ) {
			if ( count( array_unique( array_map( static fn( array $paint ): string => (string) wp_json_encode( $paint['styles'] ), $paints ) ) ) > 1 ) {
				return null;
			}
		}
		$unselected = $states[0][0];
		$selected   = $states[1][0];
		$patch      = array();
		foreach ( $selected['styles'] as $property => $value ) {
			if ( ( $unselected['styles'][ $property ] ?? null ) !== $value ) {
				$patch[ $property ] = $value;
			}
		}
		$changed = array_map( static fn( string $property ): string => str_replace( '_', '-', $property ), array_keys( $patch ) );
		return array(
			'unselected' => $unselected,
			'patch'      => array(
				'role'        => 'control',
				'condition'   => array(
					'kind'  => 'state',
					'state' => 'checked',
				),
				'style_patch' => $patch,
				'provenance'  => array_values(
					array_filter(
						array_map(
							static fn( array $entry ): array => array( 'properties' => array_values( array_intersect( $entry['properties'] ?? array(), $changed ) ) ) + $entry,
							$selected['provenance'] ?? array()
						),
						static fn( array $entry ): bool => array() !== $entry['properties']
					)
				),
			),
		);
	}

	/**
	 * The source controls a readable subtree holds, in source order.
	 *
	 * @param array<string,mixed> $block Readable block.
	 * @return array<int,int>
	 */
	private static function control_indices( array $block ): array {
		$indices = isset( $block['form_control_index'] ) && is_int( $block['form_control_index'] ) ? array( $block['form_control_index'] ) : array();
		foreach ( $block['innerBlocks'] ?? array() as $child ) {
			$indices = array_merge( $indices, self::control_indices( $child ) );
		}
		return $indices;
	}

	/**
	 * The layout node that lays out every choice of a group, when they share one.
	 *
	 * @param array<string,mixed> $form     Producer form.
	 * @param array<int,int>      $controls Choice control indices.
	 */
	private static function choice_row( array $form, array $controls ): ?string {
		$ids     = array_flip( array_map( static fn( int $index ): string => 'control-' . $index, $controls ) );
		$parents = array();
		foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && isset( $ids[ $node['id'] ?? '' ] ) ) {
				$parents[] = $node['parent'] ?? null;
			}
		}
		$row = array_unique( $parents );
		return count( $parents ) === count( $controls ) && 1 === count( $row ) && is_string( $row[0] ) ? $row[0] : null;
	}

	/**
	 * A fieldset group's legend, as copy. A captured choice group carries it as
	 * the provider field label instead, so only uncaptured groups render it.
	 *
	 * @param array<string,mixed> $block Readable block.
	 * @return array<string,mixed>|null
	 */
	private static function legend_block( array $block ): ?array {
		$field = $block['form_field'] ?? null;
		$text  = is_array( $field ) && 'group' === ( $field['kind'] ?? null ) && is_string( $field['label'] ?? null ) ? trim( html_entity_decode( wp_strip_all_tags( $field['label'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		if ( '' === $text ) {
			return null;
		}
		$attrs = is_array( $field['label_attributes'] ?? null ) ? array_intersect_key( $field['label_attributes'], array_flip( array( 'className', 'style' ) ) ) : array();
		return Static_Site_Importer_Form_Field_Markup::parsed_block( array(
			'name'    => 'core/paragraph',
			'attrs'   => $attrs,
			'wrapper' => 'paragraph',
			'content' => $text,
		) );
	}
}
