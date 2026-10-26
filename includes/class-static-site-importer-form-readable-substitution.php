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
	 * Lay a single checkbox and its label out as the source row did.
	 *
	 * Jetpack nests the pair in a choice wrap inside the field wrapper; a
	 * retained source wrapper (`.ssi-field-row`) can sit between the two, and
	 * the field wrapper holds exactly one choice wrap, so the selector descends.
	 *
	 * @param array<string,mixed>  $descriptor   Presentation descriptor.
	 * @param array<string,string> $declarations Source row arrangement.
	 * @return array<string,mixed>
	 */
	public static function with_checkbox_layout( array $descriptor, string $scope, int $index, array $declarations ): array {
		$descriptor['destinations'][]   = array(
			'part'       => 'checkbox_layout',
			'role'       => 'label',
			'selector'   => '.' . $scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $scope, 'control-' . $index ) . '-wrap .contact-form__checkbox-wrap',
			'properties' => array(),
			'resets'     => $declarations,
		);
		$descriptor['has_presentation'] = true;
		return $descriptor;
	}

	/**
	 * Give each laid-out checkbox a label row, so its layout destination
	 * compiles even when the source states no label paint.
	 *
	 * @param array<string,mixed> $graph   Presentation graph.
	 * @param array<int,mixed>    $indices Controls with a checkbox layout.
	 * @return array<string,mixed>
	 */
	public static function with_checkbox_layout_rows( array $graph, array $indices ): array {
		$graph['controls'] = $graph['controls'] ?? array();
		foreach ( array_keys( $indices ) as $index ) {
			$key = array_search( $index, array_map( static fn( $control ) => $control['index'] ?? null, $graph['controls'] ), true );
			if ( false === $key ) {
				$graph['controls'][] = array(
					'index' => $index,
					'label' => array( 'styles' => array() ),
				);
			} elseif ( ! is_array( $graph['controls'][ $key ]['label']['styles'] ?? null ) ) {
				$graph['controls'][ $key ]['label']['styles'] = array();
			}
		}
		return $graph;
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
				// The editor repeats the wrapper classes on its RichText link.
				// Keep the button paint above the wrapper's box neutralization.
				$descriptor['destinations'][ $index ]['priority'] = 'important';
				$descriptor['destinations'][ $index ]['resets']  += array(
					// A block <button> still shrinks to its content.
					'display' => 'block',
					'width'   => '100%',
					'margin'  => '0',
				);
			}
		}
		// Source classes also reach this wrapper for native item placement.
		// Their paint (including padding) must only reach the inner button,
		// even when the imported stylesheet scopes those classes more tightly.
		foreach ( $descriptor['destinations'] as $destination ) {
			if ( 'submit_wrapper' === ( $destination['part'] ?? null ) ) {
				$descriptor['destinations'][] = array(
					'part'       => 'source_submit_wrapper_paint_reset',
					'role'       => 'control',
					'selector'   => $destination['selector'],
					'properties' => array(),
					'resets'     => array(
						'padding'    => '0',
						'border'     => '0',
						'background' => 'transparent',
					),
					'priority'   => 'important',
					// Editor RichText repeats the wrapper's classes on the link.
					// Its existing normal-priority resets let link paint win there.
					'editor'     => array(
						'drop' => true,
					),
				);
				break;
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

	/**
	 * The source arrangement of each proven two-item checkbox/label flex row.
	 *
	 * @param array<string,mixed> $form Provider form manifest row.
	 * @return array<int,array<string,string>> CSS declarations by control index.
	 */
	public static function checkbox_layouts( array $form ): array {
		$graph   = $form['layout_graph'] ?? array();
		$targets = array();
		if ( 'generic/computed-layout-graph/v3' !== ( $graph['schema'] ?? null ) || ! empty( $graph['truncated'] ) ) {
			return $targets;
		}
		$nodes = array_column( $graph['nodes'] ?? array(), null, 'id' );
		foreach ( $nodes as $label ) {
			if ( 'label' !== ( $label['kind'] ?? null ) ) {
				continue;
			}
			$control = $nodes[ $label['for_control'] ?? '' ] ?? array();
			$parent  = $label['parent'] ?? '';
			$wrapper = $nodes[ $parent ] ?? array();
			$layout  = $wrapper['layout'] ?? array();
			$index   = (int) substr( $control['id'] ?? '', 8 );
			if ( 'control' !== ( $control['kind'] ?? null ) || 'checkbox' !== ( $form['controls'][ $index ]['type'] ?? null ) || ! empty( $form['controls'][ $index ]['options'] ) || ( $control['parent'] ?? null ) !== $parent || 2 !== ( $wrapper['in_flow_children'] ?? null ) || ! in_array( $layout['display'] ?? '', array( 'flex', 'inline-flex' ), true ) ) {
				continue;
			}
			$children = array_filter( $nodes, static fn( array $node ): bool => ( $node['parent'] ?? null ) === $parent );
			if ( 2 !== count( $children ) || ! is_int( $control['order'] ?? null ) || ! is_int( $label['order'] ?? null ) || $label['order'] === $control['order'] ) {
				continue;
			}
			$in_flow = true;
			foreach ( array( $control, $label ) as $item ) {
				$item_layout = $item['layout'] ?? array();
				if ( 'none' === ( $item_layout['display'] ?? '' ) || in_array( $item_layout['position'] ?? '', array( 'absolute', 'fixed' ), true ) || ! empty( $item_layout['order'] ) ) {
					$in_flow = false;
				}
			}
			$gaps = self::source_gaps( $layout );
			if ( ! $in_flow || null === $gaps || ( null === $gaps['row'] && null === $gaps['column'] ) ) {
				continue;
			}
			$direction = $layout['direction'] ?? 'row';
			if ( $label['order'] < $control['order'] ) {
				$direction = str_ends_with( $direction, '-reverse' ) ? substr( $direction, 0, -8 ) : $direction . '-reverse';
			}
			$declarations = array(
				'display'        => 'flex',
				'flex-direction' => $direction,
				'align-items'    => $layout['align_items'] ?? 'stretch',
			);
			foreach ( array( 'gap', 'row_gap', 'column_gap', 'justify_content' ) as $property ) {
				if ( isset( $layout[ $property ] ) ) {
					$declarations[ str_replace( '_', '-', $property ) ] = $layout[ $property ];
				}
			}
			$targets[ $index ] = $declarations;
		}
		return $targets;
	}

	/**
	 * Row and column gaps from a layout's gap facts, or null when they conflict.
	 *
	 * @param array<string,mixed> $layout Source layout facts.
	 * @return array{row:?string,column:?string}|null
	 */
	private static function source_gaps( array $layout ): ?array {
		$fact  = static fn ( string $key ): ?string => is_string( $layout[ $key ] ?? null ) && '' !== trim( $layout[ $key ] ) ? trim( $layout[ $key ] ) : null;
		$parts = null === $fact( 'gap' ) ? array() : \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevelWhitespace( (string) $fact( 'gap' ) );
		if ( count( $parts ) > 2 ) {
			return null;
		}
		$shorthand = array(
			'row'    => $parts[0] ?? null,
			'column' => $parts[1] ?? $parts[0] ?? null,
		);
		$gaps      = array(
			'row'    => $fact( 'row_gap' ) ?? $shorthand['row'],
			'column' => $fact( 'column_gap' ) ?? $shorthand['column'],
		);
		if ( array() !== $parts && $gaps !== $shorthand ) {
			return null;
		}
		return $gaps;
	}
}
