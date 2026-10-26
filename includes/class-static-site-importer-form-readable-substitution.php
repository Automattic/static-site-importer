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
			$destinations[] = $destination;
		}
		// The provider field wrapper arranges the caption and control as the
		// source label did; the shell states the rest of that box.
		$destinations[]             = array(
			'part'       => 'field_box_wrapper',
			'role'       => 'label',
			// Jetpack renders the field block's class with a `-wrap` suffix.
			'selector'   => '.' . $scope . ' .' . Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-' . $index ) . '-wrap',
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
		$wrapper = '.' . $scope . ' .' . Static_Site_Importer_Form_Layout_Projection::layout_node_class( $scope, 'control-' . $index ) . '-wrap';
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
		$descriptor['destinations'][]   = array(
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
			$choice                      = $state->form['choice_groups'][ $block['form_choice_group_index'] ];
			$options                     = array_map(
				static fn( array $option ): array => array(
					'label' => $option['label'],
					'value' => $option['source_value'] ?? $option['label'],
				),
				$choice['choices']
			);
			$multiple                    = 'multiple' === ( $choice['group']['selection_mode'] ?? $choice['group']['mode'] ?? '' )
				|| 1 < count( array_filter( $choice['observed_transition']['selected'] ?? array(), static fn( mixed $selected ): bool => true === $selected ) );
			$field                       = Static_Site_Importer_Form_Field_Markup::field_block_from_control(
				'input',
				$multiple ? 'checkbox' : 'radio',
				array(
					'label'   => $choice['group']['label'] ?? '',
					'options' => $options,
				)
			);
			$token                       = Static_Site_Importer_Provider_Form_Runtime_V1::choice_token( $options );
			$field['attrs']['className'] = trim( 'is-style-button ' . $token );
			$state->mapped_types[]       = $field['name'];
			return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
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
		foreach ( $block['innerContent'] ?? array() as $part ) {
			if ( null !== $part ) {
				$slots[] = $part;
				continue;
			}
			$child = self::substitute( $block['innerBlocks'][ $index++ ], $state );
			if ( null !== $child ) {
				$children[] = $child;
				$slots[]    = null;
			}
		}
		$block['innerBlocks']  = $children;
		$block['innerContent'] = $slots;
		return $block;
	}
}
