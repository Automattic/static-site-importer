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

	/**
	 * The box and flex-item facts of a control box's source frames that the
	 * control they framed states on itself once the frames are gone. The
	 * frames' own arrangement facts are how they laid their one child out, not
	 * a box the provider control renders, and the frames' paint and inherited
	 * custom properties follow their classes (with_control_box_classes()).
	 */
	private const CONTROL_BOX_ITEM_FACTS = array(
		'width'               => true,
		'min_width'           => true,
		'max_width'           => true,
		'height'              => true,
		'min_height'          => true,
		'max_height'          => true,
		'box_sizing'          => true,
		'flex'                => true,
		'flex_grow'           => true,
		'flex_shrink'         => true,
		'flex_basis'          => true,
		'align_self'          => true,
		'justify_self'        => true,
		'order'               => true,
		'grid_column'         => true,
		'grid_row'            => true,
		'grid_area'           => true,
		'margin'              => true,
		'margin_top'          => true,
		'margin_right'        => true,
		'margin_bottom'       => true,
		'margin_left'         => true,
		'margin_block_start'  => true,
		'margin_block_end'    => true,
		'margin_inline_start' => true,
		'margin_inline_end'   => true,
	);

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
	 * A source button's offset from its place. The wrapper takes that place, so
	 * the offset (and a percentage resolved against it) moves the wrapper once;
	 * on the link it would resolve against the wrapper and shift a second time.
	 */
	private const SUBMIT_OFFSET_FACTS = array( 'position', 'inset', 'top', 'right', 'bottom', 'left', 'transform', 'z_index' );

	/**
	 * The source submit is one element, both the item its layout box places
	 * and the painted button; core/button splits it into a wrapper and a link.
	 * The wrapper takes the source button's place: it carries the source
	 * carrier identities (or legacy source classes), so rules that place or size the button reach
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
				$descriptor['destinations'][ $index ]['properties'] = array_merge( $destination['properties'], array( 'grid_column', 'grid_row', 'grid_area' ), self::SUBMIT_OFFSET_FACTS );
				$descriptor['destinations'][ $index ]['resets']     = array(
					'display'     => 'inline-block',
					// A source button is never capped by its container; Jetpack's
					// editor caps every field block at 100%.
					'max-width'   => 'none',
					'padding'     => '0',
					'border'      => '0',
					'background'  => 'transparent',
					// Jetpack's editor sets the button block's line-height.
					'line-height' => 'inherit',
				);
			} elseif ( 'submit_link' === $part ) {
				$descriptor['destinations'][ $index ]['properties'] = array_values( array_diff( $destination['properties'], array( 'grid_column', 'grid_row', 'grid_area' ), self::SUBMIT_OFFSET_FACTS ) );
				// The editor repeats the wrapper classes on its RichText link.
				// Keep the button paint above the wrapper's box neutralization.
				$descriptor['destinations'][ $index ]['priority'] = 'important';
				$descriptor['destinations'][ $index ]['resets']  += array(
					// A block <button> still shrinks to its content. The button's own
					// display fact reaches the link too, blockified on compile, and
					// leaves this reset standing for every non-flex, non-grid button.
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
					'part'       => 'source_button_slot',
					'editor'     => array(),
					'role'       => 'control',
					'selector'   => $destination['selector'] . '-destination-slot',
					'properties' => array(),
					'resets'     => array( 'display' => 'contents' ),
					'priority'   => 'important',
				);
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

	/** Carry grid item facts on the provider wrapper, not its inner button. */
	public static function with_submit_item_placement( array $form ): array {
		$graph = is_array( $form['presentation_graph'] ?? null ) ? $form['presentation_graph'] : array();
		foreach ( $form['layout_graph']['variants'] ?? array() as $variant ) {
			if ( ! preg_match( '/^control-([0-9]+)$/D', $variant['node'], $match ) ) {
				continue;
			}
			$index   = (int) $match[1];
			$control = $form['controls'][ $index ] ?? array();
			$patch   = array_intersect_key( $variant['layout_patch'], array_flip( array( 'column', 'row', 'area' ) ) );
			if ( 'submit' !== ( $control['type'] ?? '' ) || empty( $control['control_carrier_classes'] ) || empty( $patch ) ) {
				continue;
			}
			$styles = array();
			foreach ( $patch as $key => $value ) {
				$styles[ 'grid_' . $key ] = $value;
			}
			$graph['variants'][] = array(
				'index'       => $index,
				'role'        => 'control',
				'condition'   => $variant['condition'],
				'style_patch' => $styles,
			);
		}
		return $graph;
	}

	/** Preserve saved wrapper fragments, changing only their child slots. */
	public static function substitute( array $block, Static_Site_Importer_Form_Plan_State $state, string $parent_name = '' ): ?array {
		if ( isset( $block['form_choice_group_index'] ) ) {
			return self::choice_field( $block, $state );
		}
		if ( isset( $block['form_control_index'] ) ) {
			$index = $block['form_control_index'];
			if ( isset( $state->suppressed_controls[ $index ] ) || ! isset( $state->field_blocks[ $index ] ) ) {
				return null;
			}
			$field = $state->field_blocks[ $index ];
			$field = self::with_source_caption( $field, $block['form_field']['label'] ?? '', $state->form['controls'][ $index ] ?? array() );
			if ( 'core/button' === $field['name'] && preg_match_all( '/(?:^|\s)ssi-source-submit--(\S+)/', $field['attrs']['className'] ?? '', $source ) ) {
				// The wrapper is the source button's box (with_source_box_submit_wrapper()).
				$field['attrs']['className'] .= ' ' . implode( ' ', $source[1] );
			}
			// The source control can occupy a group or a captured layout-shell
			// slot. Core buttons require their own immediate buttons parent.
			if ( 'core/button' === $field['name'] && 'core/buttons' !== $parent_name ) {
				$field = array(
					'name'        => 'core/buttons',
					// Keep the required editor parent without introducing a new
					// layout item: the button wrapper still owns the source box.
					'attrs'       => array( 'className' => trim( self::button_slot_class( $field ) . ' ' . implode( ' ', $field['control_carrier_classes'] ?? array() ) ) ),
					'innerBlocks' => array( $field ),
				);
			}
			return Static_Site_Importer_Form_Field_Markup::parsed_block( $field );
		}
		if ( isset( $block['form_control_box'] ) && is_int( $block['form_control_box'] ) ) {
			return self::control_box_field( $block, $state, $parent_name );
		}
		$composite = self::composite_control_box_index( $block, $state );
		if ( null !== $composite ) {
			return self::control_box_field( $block, $state, $parent_name, $composite );
		}
		if ( self::is_provider_field_label( $block, $state ) ) {
			return null;
		}
		return self::group_field( $block, $state );
	}

	/**
	 * A shell marked `form_control_box` is the exclusive wrappers between the
	 * caption level and one control (the producer's control-box shell). The
	 * substituted provider field takes the whole shell's place, inside the
	 * field box: the provider field renders the caption too, so the frame's
	 * own arrangement would take the caption and control together where the
	 * source frame held only the control. The frame renders as a real box
	 * around the provider control again: an `<input>` or `<textarea>` paints
	 * no pseudo-element and takes no padding, so the frame's classes travel
	 * as transport markers beside the box's own hook (with_control_box_classes()),
	 * and the render-time runtime builds that box from them. Where the provider
	 * renders no wrappable control markup (a dropdown's wrapper, a choice's
	 * box), the classes stay on the element that carries the control. The
	 * frame's box and flex-item facts rest on the box's own presentation
	 * destination, or on the control itself there (with_control_box_frames()).
	 *
	 * @param array<string,mixed>                  $block Readable block marked as a control box.
	 * @param Static_Site_Importer_Form_Plan_State $state Plan state.
	 * @param string                               $parent_name The block the shell stands in.
	 * @return array<string,mixed>|null
	 */
	private static function control_box_field( array $block, Static_Site_Importer_Form_Plan_State $state, string $parent_name, ?int $composite = null ): ?array {
		$index  = $composite ?? (int) $block['form_control_box'];
		$inner  = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$prefix = array();
		if ( null !== $composite ) {
			// The provider composite renders its consumed parts (the country
			// trigger and its captured panel) itself, inside its own control.
			$prefix = self::composite_prefix_classes( $inner, $composite );
			$inner  = array_values( array_filter( $inner, static fn( array $child ): bool => ( $child['form_control_index'] ?? null ) === $composite ) );
		}
		if ( 1 !== count( $inner ) || ( $inner[0]['form_control_index'] ?? null ) !== $index ) {
			// The marker promises one control the shell holds alone; anything
			// else keeps the shell, and the generic substitution walks it.
			return self::group_field( $block, $state );
		}
		$frame = self::control_box_frame( $block, $index, $state );
		$wraps = isset( $state->field_blocks[ $index ] ) && self::control_box_wraps_control( $state->field_blocks[ $index ] );
		if ( isset( $state->field_blocks[ $index ] ) ) {
			$state->field_blocks[ $index ] = self::with_control_box_classes(
				$state->field_blocks[ $index ],
				$frame['classes'],
				$wraps,
				$wraps ? Static_Site_Importer_Form_Presentation_Descriptor::control_box_destination_class( $state->scope, $index ) : '',
				$wraps ? $prefix : array()
			);
		}
		$field = self::substitute( $inner[0], $state, $parent_name );
		if ( null === $field ) {
			// The shell held only a suppressed control: it was that control's
			// box, which renders with it or not at all.
			return null;
		}
		$state->control_box_frames[ $index ] = array(
			'facts' => $frame['facts'],
			'wrap'  => $wraps,
		);
		return $field;
	}

	/**
	 * The control a shell frames together with the parts its provider field
	 * renders inside itself: a telephone input beside the country trigger the
	 * provider field consumed as its own selector.
	 *
	 * The producer marks a shell `form_control_box` only when it holds one
	 * control, so a frame holding the source's country button and the tel
	 * input arrives unmarked. Yet the provider renders both inside one
	 * control (its phone shell), so the frame is that control's box exactly
	 * as for a lone input: substituting the shell whole would put the field's
	 * caption inside the frame. A shell qualifies only when every other child
	 * holds nothing but the consumed trigger, or is the trigger's captured
	 * listbox panel, and the control is the telephone field that consumed it.
	 *
	 * @param array<string,mixed>                  $block Readable block.
	 * @param Static_Site_Importer_Form_Plan_State $state Plan state.
	 */
	private static function composite_control_box_index( array $block, Static_Site_Importer_Form_Plan_State $state ): ?int {
		$wrappers = $block['attrs']['wrappers'] ?? null;
		if ( ! str_ends_with( (string) ( $block['blockName'] ?? '' ), '/layout-shell' ) || ! is_array( $wrappers ) || array() === $wrappers ) {
			return null;
		}
		$control  = null;
		$triggers = array();
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
			if ( is_int( $child['form_control_index'] ?? null ) && ! isset( $state->suppressed_controls[ $child['form_control_index'] ] ) ) {
				if ( null !== $control ) {
					return null;
				}
				$control = $child['form_control_index'];
				continue;
			}
			if ( array() !== $triggers && self::is_captured_listbox_panel( $child ) ) {
				continue;
			}
			$indices = self::control_indices( $child );
			if ( array() === $indices || count( $indices ) !== count( array_filter( $indices, static fn( int $index ): bool => 'provider_auxiliary_button' === ( $state->field_contract[ $index ]['reason_code'] ?? null ) ) ) ) {
				return null;
			}
			$triggers = array_merge( $triggers, $indices );
		}
		if ( null === $control || array( $control - 1 ) !== $triggers ) {
			return null;
		}
		$field = $state->field_blocks[ $control ] ?? array();
		return in_array( $field['name'] ?? null, array( 'jetpack/field-telephone', 'jetpack/field-phone' ), true ) && ! empty( $field['attrs']['showCountrySelector'] ) ? $control : null;
	}

	/**
	 * The source classes of the element that held a composite box's consumed
	 * trigger inside the frame: the frame's own child the provider's prefix
	 * stands in for. Its class-owned box (the trigger's inset from the frame
	 * edge) then reaches the provider prefix as it did the source element.
	 *
	 * @param array<int,array<string,mixed>> $children The frame shell's children.
	 * @param int                            $control  The composite's control index.
	 * @return array<int,string>
	 */
	private static function composite_prefix_classes( array $children, int $control ): array {
		$classes = array();
		foreach ( $children as $child ) {
			if ( ( $child['form_control_index'] ?? null ) === $control || array() === self::control_indices( $child ) ) {
				continue;
			}
			$outer = $child['attrs']['wrappers'][0] ?? null;
			foreach ( Static_Site_Importer_Form_Presentation::class_tokens( is_array( $outer ) ? ( $outer['attributes'] ?? null ) : null ) as $class ) {
				if ( count( $classes ) < 16 && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class ) ) {
					$classes[ $class ] = true;
				}
			}
		}
		return array_keys( $classes );
	}

	/**
	 * Whether the provider field renders the one control a control box frames
	 * as a bare input or textarea, which the runtime can wrap in the frame's
	 * box. A telephone is wrapped too: a plain tel renders as a bare input,
	 * and a country-selector telephone's phone shell (trigger and input
	 * together) is wrapped whole. A dropdown renders inside its own wrapper
	 * div and a standalone choice inside its own box, so each already carries
	 * its frame on a real element.
	 *
	 * @param array<string,mixed> $field_block Provider field block.
	 */
	private static function control_box_wraps_control( array $field_block ): bool {
		foreach ( $field_block['innerBlocks'] ?? array() as $inner ) {
			if ( ! is_array( $inner ) ) {
				continue;
			}
			$name = (string) ( $inner['name'] ?? '' );
			if ( 'jetpack/input' === $name ) {
				return 'dropdown' !== strtolower( trim( (string) ( $inner['attrs']['type'] ?? '' ) ) );
			}
			if ( 'jetpack/phone-input' === $name ) {
				return true;
			}
			if ( 'jetpack/option' === $name && ! empty( $inner['attrs']['isStandalone'] ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * The classes and the box facts of a control box's source frames.
	 *
	 * The shell's wrappers carry the source classes; the class-owned rules
	 * that painted the frames (their custom properties and the paint the
	 * control inherited through them) must match the provider control once
	 * the frames are gone. The frames' own box and item facts live in the
	 * layout graph under the control's wrapper ancestors, innermost first, so
	 * the frame nearest the control owns a fact.
	 *
	 * @param array<string,mixed>                  $shell Readable block marked as a control box.
	 * @param int                                  $index The marked control index.
	 * @param Static_Site_Importer_Form_Plan_State $state Plan state.
	 * @return array{classes:array<int,string>,facts:array<string,string>}
	 */
	private static function control_box_frame( array $shell, int $index, Static_Site_Importer_Form_Plan_State $state ): array {
		$wrappers = is_array( $shell['attrs']['wrappers'] ?? null ) ? $shell['attrs']['wrappers'] : array();
		$classes  = array();
		foreach ( $wrappers as $wrapper ) {
			foreach ( Static_Site_Importer_Form_Presentation::class_tokens( is_array( $wrapper ) ? ( $wrapper['attributes'] ?? null ) : null ) as $class ) {
				if ( count( $classes ) < 16 && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D', $class ) ) {
					$classes[ $class ] = true;
				}
			}
		}
		$nodes  = array_column( is_array( $state->form['control_topology']['nodes'] ?? null ) ? $state->form['control_topology']['nodes'] : array(), null, 'id' );
		$parent = current( array_filter( $nodes, static fn( array $node ): bool => 'control' === ( $node['kind'] ?? '' ) && ( $node['control'] ?? null ) === $index ) )['parent'] ?? null;
		$layout = array_column( is_array( $state->form['layout_graph']['nodes'] ?? null ) ? $state->form['layout_graph']['nodes'] : array(), null, 'id' );
		// A marked shell without a wrapper chain still frames the control with
		// its own element: the control's direct parent.
		$facts = array();
		$depth = 0 < count( $wrappers ) ? count( $wrappers ) : 1;
		while ( 0 < $depth-- && is_string( $parent ) && isset( $nodes[ $parent ], $layout[ $parent ] ) && 'wrapper' === ( $nodes[ $parent ]['kind'] ?? '' ) ) {
			foreach ( array_intersect_key( is_array( $layout[ $parent ]['layout'] ?? null ) ? $layout[ $parent ]['layout'] : array(), self::CONTROL_BOX_ITEM_FACTS ) as $fact => $value ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$facts[ $fact ] = trim( $value );
				}
			}
			$parent = $nodes[ $parent ]['parent'] ?? null;
		}
		return array(
			'classes' => array_keys( $classes ),
			'facts'   => $facts,
		);
	}

	/**
	 * A control box's source classes join the provider control element. When
	 * the runtime renders the frame as a real box around the control, the
	 * classes travel as `ssi-source-box--` transport markers beside the box's
	 * own hook: the runtime builds the box from them, and the control keeps
	 * only its own classes (Jetpack suffixes the copies it puts on the field
	 * wrapper, so a source rule never matched the wrapper anyway). Without a
	 * wrappable control, the classes ride the element that carries the
	 * control, which the source stylesheet's rules match directly.
	 *
	 * @param array<string,mixed> $field_block Provider field block.
	 * @param array<int,string>   $classes      Source class tokens.
	 * @param bool                $wraps        Whether the runtime wraps the rendered control in the box.
	 * @param string              $box_class    The box's presentation hook, when it wraps.
	 * @param array<int,string>   $prefix       Source classes of a composite's trigger element, which travel
	 *                                          as `ssi-source-prefix--` markers to the provider's prefix.
	 * @return array<string,mixed>
	 */
	public static function with_control_box_classes( array $field_block, array $classes, bool $wraps = false, string $box_class = '', array $prefix = array() ): array {
		if ( array() === $classes && '' === $box_class ) {
			return $field_block;
		}
		foreach ( $field_block['innerBlocks'] ?? array() as $key => $inner ) {
			if ( ! is_array( $inner ) ) {
				continue;
			}
			$name       = (string) ( $inner['name'] ?? '' );
			$is_control = in_array( $name, array( 'jetpack/input', 'jetpack/phone-input' ), true )
				|| ( 'jetpack/option' === $name && ! empty( $inner['attrs']['isStandalone'] ) );
			if ( $is_control ) {
				$tokens = preg_split( '/\s+/', trim( (string) ( $inner['attrs']['className'] ?? '' ) ) );
				$tokens = false === $tokens ? array() : array_values( array_filter( $tokens ) );
				if ( $wraps ) {
					if ( '' !== $box_class ) {
						$tokens[] = $box_class;
					}
					foreach ( $classes as $class ) {
						$tokens[] = 'ssi-source-box--' . $class;
					}
					foreach ( 'jetpack/phone-input' === $name ? $prefix : array() as $class ) {
						$tokens[] = 'ssi-source-prefix--' . $class;
					}
				} else {
					$tokens = array_merge( $tokens, $classes );
				}
				// The frame's classes join the tokens the control already carries.
				$inner['attrs']['className']        = trim( implode( ' ', array_unique( $tokens ) ) );
				$field_block['innerBlocks'][ $key ] = $inner;
				continue;
			}
			if ( ! empty( $inner['innerBlocks'] ) ) {
				$field_block['innerBlocks'][ $key ] = self::with_control_box_classes( $inner, $classes, $wraps, $box_class, $prefix );
			}
		}
		return $field_block;
	}

	/**
	 * Give each wrapped control box a destination of its own: the element the
	 * runtime builds around the rendered control. The frame's box and flex-item
	 * facts state there, so the box keeps the source frame's place and size,
	 * while the control keeps its own captured facts. The editor renders no box
	 * around the control, so its copy of the destination stands in at the
	 * control itself, exactly where the facts restated when the box was flat.
	 *
	 * @param array<int,array<string,mixed>>                         $descriptors Presentation descriptors by control index.
	 * @param string                                                 $scope       Provider form scope.
	 * @param array<int,array{facts:array<string,string>,wrap:bool}> $frames      Recorded control-box frames.
	 * @return array<int,array<string,mixed>>
	 */
	public static function with_control_box_destinations( array $descriptors, string $scope, array $frames ): array {
		foreach ( $frames as $index => $frame ) {
			$facts         = $frame['facts'];
			$control_class = isset( $descriptors[ $index ]['control_class'] ) && is_string( $descriptors[ $index ]['control_class'] ) ? trim( $descriptors[ $index ]['control_class'] ) : '';
			if ( empty( $frame['wrap'] ) || ! is_array( $descriptors[ $index ]['destinations'] ?? null ) ) {
				continue;
			}
			$class                                 = Static_Site_Importer_Form_Presentation_Descriptor::control_box_destination_class( $scope, $index );
			$editor_class                          = $descriptors[ $index ]['phone_destinations'][0]['class'] ?? $control_class;
			$editor                                = array(
				'selector' => '{scope} .' . $editor_class,
				'priority' => 'important',
			);
			$descriptors[ $index ]['destinations'] = self::with_control_box_container( $descriptors[ $index ]['destinations'], $scope, $class, $editor );
			if ( array() === $facts || '' === $control_class ) {
				continue;
			}
			$descriptors[ $index ]['destinations'][]   = array(
				'part'       => 'control_box',
				'role'       => 'control_box',
				'class'      => $class,
				'selector'   => '.' . $scope . ' .' . $class,
				'properties' => array_keys( array_intersect_key( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_map(), self::CONTROL_BOX_ITEM_FACTS ) ),
				'resets'     => array(),
				'editor'     => $editor,
			);
			$descriptors[ $index ]['has_presentation'] = true;
		}
		return $descriptors;
	}

	/**
	 * The source frame's paint (its background, border, and radius) belongs to
	 * the box the runtime builds around a wrapped control, not to the control.
	 *
	 * The container destination otherwise addresses the field's layout hook,
	 * which Jetpack copies onto the rendered `<input>` or `<textarea>` itself:
	 * the control would paint the frame a second time inside the box (a second
	 * rounded edge), or, where the control's own descriptor (`border:0`) comes
	 * later at equal specificity, the frame paint would be lost from the
	 * control while the box only carried what its source classes match on
	 * their own. The box is the frame, so it alone paints it. The editor
	 * renders no box, so its copy paints the control or phone-input shell.
	 *
	 * @param array<int,array<string,mixed>> $destinations Descriptor destinations.
	 * @param string                         $scope        Provider form scope.
	 * @param string                         $box_class    The control box's destination class.
	 * @param array<string,string>           $editor       Editor control destination.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_control_box_container( array $destinations, string $scope, string $box_class, array $editor ): array {
		foreach ( $destinations as $key => $destination ) {
			if ( 'control_container' !== ( $destination['part'] ?? null ) ) {
				continue;
			}
			$destinations[ $key ]['part']     = 'control_box_container';
			$destinations[ $key ]['editor']   = $editor;
			$destinations[ $key ]['selector'] = '.' . $scope . ' .' . $box_class;
		}
		return $destinations;
	}

	/**
	 * The box of each control's control-box frame, on the box the provider
	 * renders around the control, or on the control itself where it renders
	 * none.
	 *
	 * The frame is the box the source showed: its sizing and flex-item facts
	 * own the rendered box, and the control's own percentage sizing resolves
	 * against the box again, as it did against the frame in the source. The
	 * field wrapper's auto-width floor yields where the frame states a width:
	 * the overlay keeps an explicit source declaration over its
	 * provider-default resets. A control the provider wraps in no box keeps
	 * the facts on the control itself, replacing the control's own sizing
	 * exactly as before the box existed.
	 *
	 * @param array<string,mixed>                                    $graph  Presentation graph.
	 * @param array<int,array{facts:array<string,string>,wrap:bool}> $frames Box facts by control index.
	 * @return array<string,mixed>
	 */
	public static function with_control_box_frames( array $graph, array $frames ): array {
		foreach ( $frames as $index => $frame ) {
			$facts = $frame['facts'];
			if ( array() === $facts ) {
				continue;
			}
			if ( ! empty( $frame['wrap'] ) ) {
				$row = null;
				foreach ( $graph['controls'] ?? array() as $key => $control ) {
					if ( ( $control['index'] ?? null ) === $index ) {
						$row = $key;
						break;
					}
				}
				if ( null === $row ) {
					$graph['controls'][] = array(
						'index'       => $index,
						'control_box' => array( 'styles' => $facts ),
					);
				} else {
					$graph['controls'][ $row ]['control_box'] = array( 'styles' => $facts );
				}
				continue;
			}
			foreach ( $graph['controls'] ?? array() as $key => $control ) {
				if ( ( $control['index'] ?? null ) === $index && is_array( $control['control']['styles'] ?? null ) ) {
					$graph['controls'][ $key ]['control']['styles'] = array_merge( $control['control']['styles'], $facts );
				}
			}
		}
		return $graph;
	}

	/**
	 * The preserved wrapper fragments of a shell that is no control box, with
	 * only their child slots changed.
	 *
	 * @param array<string,mixed>                  $block Readable shell block.
	 * @param Static_Site_Importer_Form_Plan_State $state Plan state.
	 * @return array<string,mixed>|null
	 */
	private static function group_field( array $block, Static_Site_Importer_Form_Plan_State $state ): ?array {
		$children = array();
		$slots    = array();
		$index    = 0;
		$consumed = self::field_descriptions( $block, $state );
		$legend   = isset( $consumed['legend'] ) ? null : self::legend_block( $block );
		// A captured listbox panel follows the trigger that opens it. A provider
		// field that consumes the trigger renders its own popup, so the captured
		// panel leaves with the trigger.
		$consumed_trigger = false;
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
			$source = $block['innerBlocks'][ $index++ ];
			if ( isset( $consumed[ $index - 1 ] ) ) {
				continue;
			}
			if ( $consumed_trigger && self::is_captured_listbox_panel( $source ) ) {
				$consumed_trigger = false;
				continue;
			}
			$child            = self::substitute( $source, $state, (string) ( $block['blockName'] ?? '' ) );
			$consumed_trigger = null === $child && self::holds_consumed_listbox_trigger( $source, $state );
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
	 * Move source descriptions into the provider field between label and input.
	 * Jetpack helpText renders after the input, so it cannot own this copy.
	 * A legend naming exactly one field is its caption, not extra group copy.
	 *
	 * @return array<int|string,true> Consumed child slots and legend flag.
	 */
	private static function field_descriptions( array $block, Static_Site_Importer_Form_Plan_State $state ): array {
		$consumed = array();
		$indices  = self::control_indices( $block );
		$legend   = $block['form_field'] ?? array();
		if ( 1 === count( $indices ) && 'group' === ( $legend['kind'] ?? null ) ) {
			$index   = $indices[0];
			$control = $state->form['controls'][ $index ] ?? array();
			$html    = $legend['label'] ?? '';
			if ( is_string( $html ) && '' !== $html && isset( $state->field_blocks[ $index ] ) && class_exists( 'DOMDocument' ) ) {
				$doc      = new DOMDocument();
				$previous = libxml_use_internal_errors( true );
				$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>' );
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
				$xpath        = new DOMXPath( $doc );
				$descriptions = array();
				$nodes        = $xpath->query( '//p[contains(concat(" ", normalize-space(@class), " "), " description ")]' );
				foreach ( false === $nodes ? array() : $nodes as $node ) {
					if ( ! $node instanceof DOMElement || null === $node->parentNode ) {
						continue;
					}
					$descriptions[] = array(
						'name'    => 'core/paragraph',
						'attrs'   => array( 'className' => $node->getAttribute( 'class' ) ),
						'wrapper' => 'paragraph',
						'content' => $node->textContent,
					);
					$node->parentNode->removeChild( $node );
				}
				$text   = trim( $doc->textContent );
				$marker = (string) ( $control['required_text'] ?? '' );
				if ( '' !== $marker && str_ends_with( $text, $marker ) ) {
					$text = trim( substr( $text, 0, -strlen( $marker ) ) );
				}
				if ( '' !== $text && trim( (string) ( $control['label'] ?? '' ) ) === $text ) {
					$consumed['legend']            = true;
					$state->field_blocks[ $index ] = self::with_source_caption( $state->field_blocks[ $index ], $html, $control, true );
					foreach ( $descriptions as $description ) {
						self::insert_description( $state->field_blocks[ $index ], $description );
					}
				}
			}
		}
		foreach ( $block['innerBlocks'] ?? array() as $slot => $child ) {
			if ( 'core/paragraph' !== ( $child['blockName'] ?? '' ) || isset( $child['form_control_index'] ) ) {
				continue;
			}
			$next    = $block['innerBlocks'][ $slot + 1 ] ?? array();
			$indices = self::control_indices( $next );
			if ( 1 !== count( $indices ) ) {
				continue;
			}
			$index = $indices[0];
			$field = $state->field_blocks[ $index ] ?? array();
			$text  = trim( html_entity_decode( wp_strip_all_tags( $child['innerHTML'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $text || ( $field['attrs']['helpText'] ?? null ) !== $text ) {
				continue;
			}
			self::insert_description( $state->field_blocks[ $index ], array(
				'name'    => 'core/paragraph',
				'attrs'   => $child['attrs'] ?? array(),
				'wrapper' => 'paragraph',
				'content' => $text,
			) );
			$consumed[ $slot ] = true;
		}
		return $consumed;
	}

	/** Carry the presentation of the caption that supplies the provider's text. */
	private static function with_source_caption( array $field, string $html, array $control, bool $legend = false ): array {
		if ( '' === $html || ! class_exists( 'DOMDocument' ) ) {
			return $field;
		}
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$marker = trim( (string) ( $control['required_text'] ?? '' ) );
		$found  = false;
		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			if ( '' !== $marker && 0 === $element->childElementCount && trim( $element->textContent ) === $marker ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				$found = '' !== $element->getAttribute( 'class' );
				break;
			}
		}
		foreach ( $field['innerBlocks'] ?? array() as $key => $child ) {
			if ( 'jetpack/label' !== ( $child['name'] ?? '' ) ) {
				continue;
			}
			if ( $legend ) {
				// The legend may frame a title element; that title, not an empty
				// control-binding label, owns the caption's presentation.
				$root  = $doc->getElementsByTagName( 'div' )->item( 0 );
				$title = $root->firstElementChild; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				if ( $title instanceof DOMElement && trim( $title->textContent ) === trim( $root->textContent ) ) {
					$child['attrs']['className'] = $title->getAttribute( 'class' );
				}
			}
			if ( $found ) {
				// Keep the marker and its arrangement wrapper together. Jetpack's
				// synthesized indicator would lose both classes and flex-row gap.
				$child['attrs']['label'] = self::caption_markup( $doc->getElementsByTagName( 'div' )->item( 0 ) );
				unset( $child['attrs']['requiredText'] );
				$child['attrs']['requiredIndicator'] = false;
				$field['attrs']['requiredIndicator'] = false;
			}
			$field['innerBlocks'][ $key ] = $child;
		}
		return $field;
	}

	/** Rebuild caption copy with only inline containers and escaped class hooks. */
	private static function caption_markup( DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			if ( $child instanceof DOMText ) {
				$html .= esc_html( $child->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			} elseif ( $child instanceof DOMElement && ! in_array( strtolower( $child->tagName ), array( 'script', 'style', 'input', 'select', 'textarea', 'button' ), true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				$class = trim( $child->getAttribute( 'class' ) );
				$html .= '<span' . ( '' === $class ? '' : ' class="' . htmlspecialchars( $class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"' ) . '>' . self::caption_markup( $child ) . '</span>';
			}
		}
		return $html;
	}

	/** Insert visible description copy immediately after the provider caption. */
	private static function insert_description( array &$field, array $description ): void {
		foreach ( $field['innerBlocks'] ?? array() as $index => $child ) {
			if ( 'jetpack/label' === ( $child['name'] ?? '' ) ) {
				array_splice( $field['innerBlocks'], $index + 1, 0, array( $description ) );
				unset( $field['attrs']['helpText'] );
				return;
			}
		}
	}

	/**
	 * Whether a readable subtree that rendered nothing held a listbox trigger
	 * a provider field consumed as part of itself (a phone field's country
	 * selector, suppressed as its provider auxiliary button).
	 *
	 * @param array<string,mixed> $block Readable block.
	 */
	private static function holds_consumed_listbox_trigger( array $block, Static_Site_Importer_Form_Plan_State $state ): bool {
		foreach ( self::control_indices( $block ) as $index ) {
			$control = $state->controls[ $index ] ?? null;
			if ( is_array( $control )
				&& 'provider_auxiliary_button' === ( $state->field_contract[ $index ]['reason_code'] ?? null )
				&& 'listbox' === strtolower( trim( (string) ( $control['aria_haspopup'] ?? $control['aria-haspopup'] ?? '' ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a readable block is a captured listbox panel: its outermost
	 * element carries the capture's listbox panel binding.
	 *
	 * @param array<string,mixed> $block Readable block.
	 */
	private static function is_captured_listbox_panel( array $block ): bool {
		$opening = $block['innerContent'][0] ?? null;
		if ( ! is_string( $opening ) || '' === $opening ) {
			return false;
		}
		$tag = new WP_HTML_Tag_Processor( $opening );
		return $tag->next_tag() && null !== $tag->get_attribute( 'data-blocks-engine-capture-listbox-panel' );
	}

	/**
	 * Whether a readable block is a source label a provider field now renders.
	 *
	 * The producer rides a control's label on the control (`form_field`), and
	 * the provider field renders it as its own label. A label laid out apart
	 * from its control (a grid's label cell beside its input cell) still reaches
	 * the tree as authored copy, so the field consumes the label that names it:
	 * by `for` against the control's id, or by the label id the producer bound
	 * to the control (an `aria-labelledby` label). A label no provider field
	 * renders stays authored content.
	 *
	 * @param array<string,mixed> $block Readable block.
	 */
	private static function is_provider_field_label( array $block, Static_Site_Importer_Form_Plan_State $state ): bool {
		if ( ! str_ends_with( (string) ( $block['blockName'] ?? '' ), '/authored-label' ) ) {
			return false;
		}
		$for = trim( (string) ( $block['attrs']['htmlFor'] ?? '' ) );
		$id  = trim( (string) ( $block['attrs']['id'] ?? '' ) );
		if ( '' === $for && '' === $id ) {
			return false;
		}
		foreach ( $state->field_blocks as $index => $field ) {
			$control = $state->form['controls'][ $index ] ?? null;
			// An option group's caption is its legend, and each option label
			// becomes an option, so only a field's own label is consumed here.
			if ( ! is_array( $control ) || isset( $state->suppressed_controls[ $index ] ) || ! str_starts_with( (string) ( $field['name'] ?? '' ), 'jetpack/field-' ) || in_array( $field['name'], array( 'jetpack/field-radio', 'jetpack/field-checkbox-multiple' ), true ) ) {
				continue;
			}
			if ( ( '' !== $for && (string) ( $control['id'] ?? '' ) === $for ) || ( '' !== $id && (string) ( $control['label_id'] ?? '' ) === $id ) ) {
				return true;
			}
		}
		return false;
	}

	/** The required buttons parent is not another source layout box. */
	private static function button_slot_class( array $field ): string {
		preg_match_all( '/\bssi-node-[a-f0-9]{12}\b/', (string) ( $field['attrs']['className'] ?? '' ), $matches );
		return implode( ' ', array_map( static fn( string $token ): string => $token . '-destination-slot', $matches[0] ) );
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
		foreach ( self::control_indices( $block ) as $control_index ) {
			$state->record_mapped( $control_index, $field['name'] );
		}
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
	 * @return array{descriptors:array<int,array<string,mixed>>,controls:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,control_containers:array<int,array<string,mixed>>}
	 */
	public static function choice_presentation( Static_Site_Importer_Form_Plan_State $state, array $graph ): array {
		$out  = array(
			'descriptors'        => array(),
			'controls'           => array(),
			'variants'           => array(),
			'control_containers' => array(),
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
			$list = self::choice_row_presentation( $state, $nodes[ $group['row'] ?? '' ]['layout'] ?? null, $group['row'] ?? '' );
			if ( null !== $list ) {
				$out['control_containers'][]                 = array(
					'index'  => $group['controls'][0],
					'styles' => $list['styles'],
				);
				$out['descriptors'][ $group['controls'][0] ] = array(
					'has_presentation'   => true,
					'control_class'      => '',
					'label_class'        => '',
					'phone_destinations' => array(),
					'destinations'       => array( $list['destination'] ),
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
				$out['controls'][]                              = $row;
				$out['descriptors'][ $index ]                   = $out['descriptors'][ $index ] ?? array(
					'has_presentation'   => true,
					'control_class'      => '',
					'label_class'        => '',
					'phone_destinations' => array(),
					'destinations'       => array(),
				);
				$out['descriptors'][ $index ]['destinations'][] = $destination;
			}
		}
		return $out;
	}

	/**
	 * The source row that laid out a choice group's choices, presented on the
	 * provider's option list. The list is no flex box of its own, so the row
	 * states the initial values its source flex box leaves implicit.
	 *
	 * @param mixed $layout The row's captured layout facts.
	 * @return array{styles:array<string,string>,destination:array<string,mixed>}|null
	 */
	private static function choice_row_presentation( Static_Site_Importer_Form_Plan_State $state, mixed $layout, string $row_id ): ?array {
		if ( ! is_array( $layout ) || 'flex' !== ( $layout['display'] ?? null ) ) {
			return null;
		}
		$layout    += array(
			'direction'   => 'row',
			'wrap'        => 'nowrap',
			'align_items' => 'stretch',
		);
		$styles     = array();
		$properties = array(
			'display'         => 'display',
			'direction'       => 'flex_direction',
			'wrap'            => 'flex_wrap',
			'gap'             => 'gap',
			'align_items'     => 'align_items',
			'justify_content' => 'justify_content',
		);
		foreach ( $properties as $fact => $property ) {
			if ( is_string( $layout[ $fact ] ?? null ) && '' !== $layout[ $fact ] ) {
				$styles[ $property ] = $layout[ $fact ];
			}
		}
		$hook = Static_Site_Importer_Form_Presentation::layout_node_class( $state->scope, $row_id );
		return array(
			'styles'      => $styles,
			'destination' => array(
				'part'       => 'choice_row',
				'role'       => 'control_container',
				'selector'   => '.' . $state->scope . ' .' . $hook,
				'properties' => array_keys( $styles ),
				'resets'     => array(),
				'priority'   => 'important',
				// The editor list is the options block, which carries the same class.
				'editor'     => array( 'selector' => '{scope} .' . $hook ),
			),
		);
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
