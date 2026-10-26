<?php
/**
 * Form presentation descriptors for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the provider presentation of source form controls: control parts
 * and roles, phone and country surfaces, source labels and choices, and their
 * editor surface realisations.
 */
final class Static_Site_Importer_Form_Presentation_Descriptor {
	/**
	 * Editor `::placeholder` resets: Jetpack's editor placeholder styles yield to
	 * the browser default under the authored control chrome.
	 */
	private const EDITOR_PLACEHOLDER_RESETS = array(
		'color'   => 'revert',
		'opacity' => 'revert',
	);

	private static function presentation_node_class( string $scope, int $index, string $role ): string {
		return Static_Site_Importer_Form_Presentation::layout_node_class( $scope, 'presentation-' . $index . '-' . $role );
	}

	private static function presentation_destination_class( string $scope, int $index, string $role ): string {
		return self::presentation_node_class( $scope, $index, 'control-' . $role ) . '-destination-' . $role;
	}

	/** @return array<int,array<string,bool>> */
	public static function presentation_roles( array $graph ): array {
		$roles = array();
		foreach ( $graph['controls'] ?? array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['index'] ?? null ) ) {
				continue;
			}
			foreach ( array( 'control', 'label', 'required_marker' ) as $role ) {
				if ( isset( $row[ $role ] ) ) {
					$roles[ $row['index'] ][ $role ] = true;
				}
			}
			if ( is_array( $row['control'] ?? null ) && Static_Site_Importer_Form_Presentation::has_sibling_combinator_margin( $row['control']['provenance'] ?? null ) ) {
				$roles[ $row['index'] ]['sibling_stacked_margin'] = true;
			}
		}
		foreach ( $graph['control_containers'] ?? array() as $container ) {
			if ( is_array( $container ) && is_int( $container['index'] ?? null ) ) {
				$roles[ $container['index'] ]['control_container'] = true;
			}
		}
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_int( $variant['index'] ?? null ) && in_array( $variant['role'] ?? null, array( 'control', 'label', 'required_marker', 'control_container' ), true ) ) {
				$roles[ $variant['index'] ][ $variant['role'] ] = true;
			}
		}
		ksort( $roles, SORT_NUMERIC );
		return $roles;
	}

	/**
	 * Jetpack owns this composite's rendered roles. Captured input presentation
	 * belongs to its value input; the additional provider shell is structural.
	 * Prefix chrome between a reconstructed source flex parent and the trigger
	 * stretches and centres so the trigger keeps the source inner offset.
	 *
	 * Each part declares its own editor realisation (see
	 * `editor_surface_table()`). The editor renders the composite as one
	 * control node, the primary box, so the structural shell, carrier, and
	 * prefix are front-end-only: their `height:100%` would otherwise collapse
	 * that box.
	 */
	private static function phone_presentation_destinations( string $scope, int $index ): array {
		return array(
			array(
				'part'       => 'phone_shell',
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'shell' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'shell' ),
				'properties' => array(),
				'resets'     => array(
					'padding'     => '0',
					'border'      => '0',
					'background'  => 'transparent',
					'text-indent' => '0',
					'gap'         => '0',
				),
				'priority'   => 'important',
				'editor'     => array( 'drop' => true ),
			),
			array(
				'part'       => 'phone_primary',
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'primary' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'primary' ),
				'properties' => array_keys( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() ),
				'resets'     => array(
					'font-family' => 'revert',
					'line-height' => 'revert',
				),
				'priority'   => 'important',
				'editor'     => array(),
			),
			array(
				'part'       => 'phone_carrier',
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'carrier' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'carrier' ),
				'properties' => array(),
				'resets'     => array(
					'flex'      => '1 1 0',
					'min-width' => '0',
				),
				'priority'   => 'important',
				'editor'     => array( 'drop' => true ),
			),
			array(
				'part'       => 'phone_prefix',
				'role'       => 'control',
				'class'      => self::presentation_destination_class( $scope, $index, 'prefix' ),
				'selector'   => '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'prefix' ),
				'properties' => array(),
				'resets'     => array(
					'display'     => 'flex',
					'align-items' => 'center',
					'height'      => '100%',
				),
				'priority'   => 'important',
				'editor'     => array( 'drop' => true ),
			),
		);
	}

	/** The declared part of a non-phone control's own (inner) destination. */
	private static function control_part( string $type ): string {
		return match ( $type ) {
			'submit'   => 'submit_link',
			'select'   => 'select_control',
			'checkbox' => 'checkbox_control',
			default    => 'control',
		};
	}

	/**
	 * @param array<int,string> $row_carried_label_margins Label margin properties the rebuilt source row already supplies.
	 */
	public static function presentation_descriptor( string $scope, int $index, string $type, array $roles, bool $has_caption = false, bool $choice_box = false, bool $wrapping_label = false, array $row_carried_label_margins = array() ): array {
		$control_class      = isset( $roles['control'] ) ? self::presentation_node_class( $scope, $index, 'control' ) : '';
		$label_class        = isset( $roles['label'] ) || isset( $roles['required_marker'] ) ? self::presentation_node_class( $scope, $index, 'label' ) : '';
		$phone_destinations = array();
		$destinations       = array();

		if ( '' !== $control_class ) {
			if ( in_array( $type, array( 'phone', 'tel' ), true ) ) {
				$phone_destinations = self::phone_presentation_destinations( $scope, $index );
				$destinations       = $phone_destinations;
			} else {
				$properties   = array_keys( 'submit' === $type ? Static_Site_Importer_Form_Presentation_Overlay::positioned_control_presentation_property_keys() : Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() );
				$inner_suffix = 'submit' === $type ? ' > .wp-block-button__link' : ( 'select' === $type ? ' select' : '' );
				if ( '' !== $inner_suffix ) {
					// The wrapper is the item of the source container, so the
					// control's own placement in that container belongs on it.
					$wrapper_properties = array( 'display', 'width', 'min_width', 'align_self', 'justify_self', 'order', 'flex', 'flex_basis', 'flex_grow' );
					if ( 'submit' === $type ) {
						// Gutenberg's `is-layout-flex > * { margin:0 }` zeros a submit
						// wrapper's authored class margin. Carry vertical spacing onto
						// that wrapper through the overlay so it outranks the layout
						// reset, and keep it off the inner link (which is also zeroed).
						$wrapper_properties = array_merge(
							$wrapper_properties,
							Static_Site_Importer_Form_Presentation::MARGIN_FACTS
						);
					}
					$wrapper = array(
						'part'       => 'select' === $type ? 'select_wrapper' : 'submit_wrapper',
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class,
						// A nested native control's wrapper shrink-wraps unless source
						// display, width, and min-width reach it. The authored box belongs
						// on the inner control, the same way submit facts reach the link.
						'properties' => $wrapper_properties,
					);
					if ( 'select' === $type ) {
						// Jetpack parks input className on the select wrapper and paints
						// that wrapper as a second box. Neutralize it so only the inner
						// control carries the authored padding, border, and background.
						// The editor's single dropdown toggle is both boxes, so these
						// resets are front-end-only there.
						$wrapper['resets']   = array(
							'padding'    => '0',
							'border'     => '0',
							'background' => 'transparent',
						);
						$wrapper['priority'] = 'important';
						$wrapper['editor']   = array(
							'unset_resets' => array_keys( $wrapper['resets'] ),
							'placeholder'  => self::EDITOR_PLACEHOLDER_RESETS,
						);
					}
					$destinations[] = $wrapper;
					$properties     = array_values( array_diff( $properties, $wrapper_properties ) );
				}
				$destination = array(
					'part'       => self::control_part( $type ),
					'role'       => 'control',
					'selector'   => '.' . $scope . ( 'checkbox' === $type ? ' input.' : ' .' ) . $control_class . $inner_suffix,
					'properties' => $properties,
					// Every native control, the submit `<button>` included, takes its
					// font family, line-height, padding and border from the browser
					// control default unless the source authors them (a reset's
					// `font: inherit`), which the destination carries after this
					// reset. Reverting releases the provider and theme control
					// typography and box to that default.
					'resets'     => array_merge(
						array(
							'font-family' => 'revert',
							'line-height' => 'revert',
							'padding'     => 'revert',
							'border'      => 'revert',
						),
						// Core lets a button link break inside a word, below the
						// width a source `<button>` keeps for its caption.
						'submit' === $type ? array(
							'min-height' => '0',
							'word-break' => 'normal',
						) : array(),
						'checkbox' === $type ? array(
							'min-width' => '0',
							'padding'   => '0',
							// Jetpack zeroes the checkbox's default margin.
							'margin'    => 'revert',
						) : array(),
						// Jetpack renders the native control with `appearance: none`,
						// which removes the platform chevron the authored select had.
						// Reverting to `auto` restores it alongside the authored
						// padding and border this destination already carries.
						'select' === $type ? array( 'appearance' => 'auto' ) : array()
					),
				);
				if ( 'select' === $type ) {
					// Jetpack forces `border:0!important` on the nested `<select>`.
					$destination['priority'] = 'important';
					// The editor's dropdown chrome is its toggle, the select wrapper
					// itself, which keeps its own chevron: the nested-select suffix
					// and the `appearance` reset are front-end-only. A `<select>`
					// keeps a normal line height whatever the author states
					// (`font: inherit` included); the toggle is not a select, so it
					// is told so after the source facts.
					$destination['editor'] = array(
						'retarget'     => array( $inner_suffix, '' ),
						'unset_resets' => array( 'appearance' ),
						'placeholder'  => self::EDITOR_PLACEHOLDER_RESETS,
						'forced'       => array( 'line-height' => 'normal' ),
					);
				}
				$destinations[] = $destination;
				if ( 'submit' === $type && $has_caption ) {
					// The caption span keeps its source classes, so a source rule
					// that paints the label (Wix's `.mu5PoX .OR4Nv8{color:rgb(var(--txt))}`)
					// still matches it, but the wrapper that defined its variables is
					// gone. Restate the captured text colour with the typography so the
					// label shows the button's own colour, not a fallback.
					$caption        = array( 'color', 'font', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'line_height', 'letter_spacing', 'text_transform' );
					$destinations[] = array(
						'part'       => 'submit_caption',
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class . ' > .wp-block-button__link > span',
						'properties' => $caption,
					);
				}
				if ( 'submit' === $type ) {
					// A submit control sits as a bare direct child of its source
					// container, unlike every other field, which is wrapped in its own
					// box. A sibling-stacking utility (Tailwind's `space-y-*`) that
					// matches direct children therefore captures a real vertical margin
					// fact against the button itself. The provider's own field gap
					// already reproduces that inter-sibling spacing structurally (the
					// layout graph's own `margin-block-start` reset on this same node
					// already neutralizes the layout-level half of that gap); carrying
					// the captured vertical margin onto the rendered link as well would
					// double it a second time inside the button's own wrapper. The
					// margin properties stay listed above so a captured fact is still
					// represented (not a receipt loss); this unconditional, later,
					// `!important` reset is what actually wins the cascade. It resets
					// the block axis only: an authored inline margin (a newsletter
					// row's `margin-left: 10px` between the email field and its
					// submit) is the row's own spacing, which nothing else carries.
					$destinations[] = array(
						'part'       => 'submit_link_margin_reset',
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class . ' > .wp-block-button__link',
						'properties' => array(),
						'resets'     => array(
							'margin-block' => '0',
						),
						'priority'   => 'important',
					);
					// Jetpack floors the submit's `.wp-block-button` wrapper and its link
					// at `min-height: var(--jetpack--contact-form--input-height)`, which
					// its view script sets to the runtime input height. The source button
					// had no such floor, and its captured box height belongs to the
					// link, so the wrapper floor could outgrow it. Releasing the variable here
					// rather than `min-height` itself leaves any authored minimum on the
					// wrapper or the link in charge.
					$destinations[] = array(
						'part'       => 'submit_input_height_reset',
						'role'       => 'control',
						'selector'   => '.' . $scope . ' .' . $control_class,
						'properties' => array(),
						'resets'     => array(
							'--jetpack--contact-form--input-height' => 'auto',
						),
					);
				}
			}
		}
		if ( isset( $roles['control_container'] ) ) {
			$destinations[] = array(
				'part'       => 'control_container',
				'role'       => 'control_container',
				// The source chrome owns the provider field wrapper, not its nested input.
				// The layout hook is placed on that wrapper for every Jetpack field type.
				'selector'   => '.' . $scope . ' .' . Static_Site_Importer_Form_Presentation::layout_node_class( $scope, 'control-' . $index ),
				'properties' => array( 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius' ),
			);
		}
		if ( isset( $roles['label'] ) ) {
			$text_properties = array( 'color', 'font', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'letter_spacing', 'line_height', 'text_align', 'text_decoration', 'text_transform' );
			$destinations[]  = array(
				'part'       => $choice_box ? 'choice_label' : 'label',
				'role'       => 'label',
				'selector'   => '.' . $scope . ' .' . $label_class . ( $choice_box ? ' > label' : '' ),
				'properties' => $choice_box ? $text_properties : array_keys( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() ),
				'resets'     => array(
					'margin'      => '0',
					'font-weight' => 'inherit',
				),
			);
			if ( $wrapping_label && ! $choice_box ) {
				// A source label that wraps its control is the whole field box, and
				// the rebuilt field row already spaces that box. Its block margin
				// on the provider caption would space the caption from its own
				// control a second time.
				$destinations[] = array(
					'part'       => 'wrapping_label_reset',
					'role'       => 'label',
					'selector'   => '.' . $scope . ' .' . $label_class,
					'properties' => array(),
					'resets'     => array( 'margin-block' => '0' ),
					'priority'   => 'important',
				);
			}
			if ( array() !== $row_carried_label_margins && ! $choice_box ) {
				// The producer resolved the source row's gap onto the label as a
				// margin. The front-end runtime rebuilds that row with its source
				// classes, so the stylesheet's gap already spaces the label there.
				// The reset names the rebuilt row: the editor, which loads this
				// overlay too, has no such row and keeps the margin.
				$destinations[] = array(
					'part'       => 'row_gap_label_reset',
					'role'       => 'label',
					'selector'   => '.' . $scope . ' .ssi-field-row > .' . $label_class,
					'properties' => array(),
					'resets'     => array_fill_keys( $row_carried_label_margins, '0' ),
					'priority'   => 'important',
				);
			}
			if ( $choice_box ) {
				$destinations[] = array(
					'part'       => 'choice_label_box',
					'role'       => 'label',
					'selector'   => '.' . $scope . ' .' . $label_class,
					'properties' => array_values( array_diff( array_keys( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() ), $text_properties ) ),
				);
			}
		}
		if ( isset( $roles['required_marker'] ) ) {
			$destinations[] = array(
				'part'       => 'required_marker',
				'role'       => 'required_marker',
				'selector'   => '.' . $scope . ' .' . $label_class . ' > .grunion-label-required',
				'properties' => array_keys( Static_Site_Importer_Form_Presentation_Overlay::presentation_property_keys() ),
				'resets'     => array( 'font-size' => 'inherit' ),
				'priority'   => 'important',
			);
		}

		if ( isset( $roles['sibling_stacked_margin'] ) && '' !== $control_class && 'submit' !== $type ) {
			// The captured sibling-stacking margin stays represented above. The
			// provider wraps each field and spaces the wrappers with the form gap,
			// so the same margin on the control itself would add a second gap
			// inside every wrapper. Inline margins are left to the source.
			$phone            = in_array( $type, array( 'phone', 'tel' ), true );
			$stacked_selector = $phone
				? '.' . $scope . ' .' . self::presentation_destination_class( $scope, $index, 'primary' )
				: '.' . $scope . ' .' . $control_class . ( 'select' === $type ? ' select' : '' );
			$stacked          = array(
				'part'       => $phone ? 'phone_sibling_stacked' : ( 'select' === $type ? 'select_sibling_stacked' : 'sibling_stacked' ),
				'role'       => 'control',
				'selector'   => $stacked_selector,
				'properties' => array(),
				'resets'     => array( 'margin-block' => '0' ),
				'priority'   => 'important',
			);
			if ( $phone ) {
				// The editor's phone control node is the primary box itself.
				$stacked['editor'] = array();
			} elseif ( 'select' === $type ) {
				// The editor's dropdown chrome is its toggle, not a nested select.
				$stacked['editor'] = array(
					'retarget'    => array( ' select', '' ),
					'placeholder' => self::EDITOR_PLACEHOLDER_RESETS,
				);
			}
			$destinations[] = $stacked;
		}

		return array(
			'has_presentation'   => ! empty( $roles ),
			'control_class'      => $control_class,
			'label_class'        => $label_class,
			'phone_destinations' => $phone_destinations,
			'destinations'       => $destinations,
		);
	}

	/**
	 * Whether the control's source label is taken out of flow and moved
	 * off-screen (`position:absolute; left:-9999px`), a common way to keep a
	 * label for screen readers while hiding it from view.
	 *
	 * @param array<string,mixed> $form Provider form manifest row.
	 */
	public static function source_label_moved_off_screen( array $form, int $index ): bool {
		foreach ( $form['presentation_graph']['controls'] ?? array() as $row ) {
			if ( ! is_array( $row ) || ( $row['index'] ?? null ) !== $index || ! is_array( $row['label']['styles'] ?? null ) ) {
				continue;
			}
			$styles = $row['label']['styles'];
			if ( ! in_array( strtolower( trim( (string) ( $styles['position'] ?? '' ) ) ), array( 'absolute', 'fixed' ), true ) ) {
				return false;
			}
			foreach ( Static_Site_Importer_Form_Presentation::INSET_SIDES as $side ) {
				if ( 1 === preg_match( '/^-([0-9]+(?:\.[0-9]+)?)px$/D', strtolower( trim( (string) ( $styles[ $side ] ?? '' ) ) ), $match ) && (float) $match[1] >= 999.0 ) {
					return true;
				}
			}
			return false;
		}
		return false;
	}

	/** Whether the control's source label is an ancestor that wraps it, rather than a `for` sibling. */
	public static function source_label_wraps_control( array $form, int $index ): bool {
		$nodes  = array_column( $form['control_topology']['nodes'] ?? array(), null, 'id' );
		$parent = current( array_filter( $nodes, static fn( array $node ): bool => 'control' === ( $node['kind'] ?? '' ) && ( $node['control'] ?? null ) === $index ) )['parent'] ?? null;
		while ( is_string( $parent ) && isset( $nodes[ $parent ] ) ) {
			if ( 'label' === ( $nodes[ $parent ]['tag'] ?? '' ) ) {
				return true;
			}
			$parent = $nodes[ $parent ]['parent'] ?? null;
		}
		return false;
	}

	/**
	 * Label margin properties whose every source rule selects the control's
	 * parent wrapper by classes it carries: a row gap the producer resolved
	 * onto the label. A `for` sibling label shares that parent with its control.
	 *
	 * @return array<int,string> CSS margin properties.
	 */
	public static function source_label_row_carried_margins( array $form, int $index ): array {
		$nodes   = array_column( $form['control_topology']['nodes'] ?? array(), null, 'id' );
		$control = current( array_filter( $nodes, static fn( array $node ): bool => 'control' === ( $node['kind'] ?? '' ) && ( $node['control'] ?? null ) === $index ) );
		$parent  = is_array( $control ) ? ( $nodes[ $control['parent'] ?? '' ] ?? null ) : null;
		if ( ! is_array( $parent ) || 'wrapper' !== ( $parent['kind'] ?? null ) || 'label' === ( $parent['tag'] ?? '' ) ) {
			return array();
		}
		$tokens = Static_Site_Importer_Form_Presentation::class_tokens( $parent );
		$label  = array_column( $form['presentation_graph']['controls'] ?? array(), null, 'index' )[ $index ]['label'] ?? null;
		if ( array() === $tokens || ! is_array( $label ) ) {
			return array();
		}
		$margins = array_intersect_key( Static_Site_Importer_Form_Presentation_Overlay::layout_property_map(), array_flip( Static_Site_Importer_Form_Presentation::MARGIN_SIDE_FACTS ) );
		$facts   = Static_Site_Importer_Form_Presentation::class_carried_box_facts( is_array( $label['styles'] ?? null ) ? $label['styles'] : array(), is_array( $label['provenance'] ?? null ) ? $label['provenance'] : array(), $tokens, $margins );
		return array_values( array_map( static fn( string $fact ): string => $margins[ $fact ], $facts ) );
	}

	/** A source implicit choice label owns the complete field, not just its input. */
	public static function source_choice_label( array $form, int $index ): ?array {
		if ( 'checkbox' !== ( $form['controls'][ $index ]['type'] ?? '' ) || ! empty( $form['controls'][ $index ]['options'] ) ) {
			return null;
		}
		$nodes   = array_column( $form['control_topology']['nodes'] ?? array(), null, 'id' );
		$control = current( array_filter( $nodes, static fn( array $node ): bool => 'control' === ( $node['kind'] ?? '' ) && ( $node['control'] ?? null ) === $index ) );
		$box     = is_array( $control ) ? self::source_custom_choice_box( $form, $control, $nodes ) : null;
		$label   = null;
		$parent  = $control['parent'] ?? null;
		while ( is_string( $parent ) && isset( $nodes[ $parent ] ) ) {
			if ( 'label' === ( $nodes[ $parent ]['tag'] ?? '' ) ) {
				$label ??= $nodes[ $parent ];
			} elseif ( 'div' !== ( $nodes[ $parent ]['tag'] ?? '' ) && ( $box['id'] ?? null ) !== $parent ) {
				return null;
			}
			$parent = $nodes[ $parent ]['parent'] ?? null;
		}
		return $label ?? $box;
	}

	/**
	 * The box of a custom-drawn checkbox: `.box input { display:none }` hides the
	 * native control and the box's sibling label draws its replacement. The
	 * provider's choice wrap holds the same input-then-label pair, so carrying the
	 * box class onto it lets the source's own rules hide and redraw the provider
	 * checkbox. Every display fact must be that unconditional box-keyed rule.
	 *
	 * @param array<string,mixed>               $control Control topology node.
	 * @param array<string,array<string,mixed>> $nodes   Topology nodes by id.
	 * @return array<string,mixed>|null The box's topology node.
	 */
	private static function source_custom_choice_box( array $form, array $control, array $nodes ): ?array {
		$parent = is_string( $control['parent'] ?? null ) ? $control['parent'] : '';
		$box    = $nodes[ $parent ] ?? null;
		if ( ! is_array( $box ) || 'wrapper' !== ( $box['kind'] ?? null ) || ! in_array( $box['tag'] ?? 'div', array( 'div', 'span' ), true ) || '' === trim( (string) ( $form['controls'][ $control['control'] ?? -1 ]['label'] ?? '' ) ) ) {
			return null;
		}
		$box_classes = Static_Site_Importer_Form_Presentation::class_tokens( $box );
		if ( empty( $box_classes ) || 1 !== count( array_filter( $nodes, static fn( array $node ): bool => ( $node['parent'] ?? null ) === $parent ) ) ) {
			return null;
		}
		$layout_node = current( array_filter( $form['layout_graph']['nodes'] ?? array(), static fn( $node ): bool => is_array( $node ) && ( $control['id'] ?? null ) === ( $node['id'] ?? null ) ) );
		if ( ! is_array( $layout_node ) || 'none' !== ( $layout_node['layout']['display'] ?? null ) ) {
			return null;
		}
		$display_facts = 0;
		foreach ( $layout_node['provenance'] ?? array() as $fact ) {
			if ( ! is_array( $fact ) || ! in_array( 'display', $fact['properties'] ?? array(), true ) ) {
				continue;
			}
			++$display_facts;
			$selector = is_string( $fact['selector'] ?? null ) ? trim( $fact['selector'] ) : '';
			if ( null !== ( $fact['condition'] ?? null ) || ! preg_match( '/^([a-z][a-z0-9-]*)?((?:\.[a-zA-Z_][a-zA-Z0-9_-]*)+)\s*(?:>\s*|\s)input(?:\[type=(["\']?)checkbox\3\])?$/D', $selector, $match ) ) {
				return null;
			}
			if ( ( '' !== $match[1] && ( $box['tag'] ?? 'div' ) !== $match[1] ) || array() !== array_diff( explode( '.', substr( $match[2], 1 ) ), $box_classes ) ) {
				return null;
			}
		}
		return 0 < $display_facts ? $box : null;
	}

	/**
	 * Where each declared part of the front-end target map lives in Jetpack's
	 * block-editor DOM.
	 *
	 * Every layout target and presentation destination the front-end builder
	 * emits carries a `part`. A part's editor realisation is declared exactly
	 * once: either by the destination itself, in its `editor` key, where the
	 * part is declared (the phone composite in `phone_presentation_destinations()`
	 * and the dropdown in `presentation_descriptor()`), or by this table. A
	 * realisation is any of:
	 *
	 * - `drop`: the editor has no such node;
	 * - `selector`: an editor selector replacing the front-end one, with
	 *   `{scope}` standing for the scope selector;
	 * - `retarget`: replace the declared front-end selector suffix `[0]` with
	 *   the editor suffix `[1]` (Jetpack's editor puts a field block's className
	 *   on the block element itself rather than the front-end `-wrap` copy, and
	 *   a dropdown's chrome on its toggle rather than a nested `<select>`);
	 * - `unset_resets` / `add_resets`: front-end resets the editor DOM does not
	 *   need, and editor-only resets;
	 * - `split`: a companion destination at the front-end selector carrying the
	 *   listed layout properties (the label box), with its own resets;
	 * - `placeholder`: a companion `::placeholder` reset destination;
	 * - `forced`: declarations the native control keeps whatever the author
	 *   states, restated on the editor stand-in in a rule after the source facts;
	 * - `textarea_rows`: the part can address a textarea control, so it gets a
	 *   companion realising that control's resolved
	 *   `Static_Site_Importer_Form_Textarea_Rows::editor_resets()`, when there
	 *   are any and the destination carries properties. Only the native control
	 *   destination addresses a textarea's box (its sibling-stacking reset
	 *   carries no properties).
	 *
	 * An emitted part with neither declaration, or with both, fails the editor
	 * derivation.
	 *
	 * @return array<string,array{drop?:bool,selector?:string,replace?:array{0:string,1:string},retarget?:array{0:string,1:string},unset_resets?:array<int,string>,add_resets?:array<string,string>,split?:array{prefixes:array<int,string>,properties:array<int,string>,resets:array<string,string>},placeholder?:array<string,string>,forced?:array<string,string>,textarea_rows?:bool}>
	 */
	public static function editor_surface_table(): array {
		$placeholder = self::EDITOR_PLACEHOLDER_RESETS;
		$label_box   = array(
			'split'      => array(
				'prefixes'   => array( 'margin', 'padding' ),
				'properties' => array( 'width', 'max_width', 'min_width', 'box_sizing' ),
				'resets'     => array( 'display' => 'block' ),
			),
			'retarget'   => array( '', ' > label' ),
			'add_resets' => array(
				'display' => 'block',
				'margin'  => '0',
				'padding' => '0',
			),
		);
		// The editor's submit link is RichText, not a `<button>`, so `revert`
		// cannot reach the browser button default there: the editor states
		// that default (font, padding, border) instead.
		$button_font = array(
			'unset_resets' => array( 'font-family', 'line-height', 'padding', 'border' ),
			'add_resets'   => array(
				'font'    => '-webkit-small-control',
				'padding' => '1px 6px',
				'border'  => '2px outset buttonborder',
			),
		);
		return array(
			// Layout targets.
			'form'                      => array( 'selector' => '{scope}' ),
			'native_form'               => array( 'selector' => '{scope}.ssi-native-form-topology > div.jetpack-contact-form' ),
			'form_box'                  => array(),
			'native_form_box'           => array( 'selector' => '{scope}.ssi-native-form-topology' ),
			'field_list'                => array(),
			// The field block element is the editor's field row: the flex or grid
			// item of the form, rendered for every field type (a checkbox field
			// has no `.jetpack-field__control`). The facts that arrange a field's
			// own label and control reach that control through the editor surface's
			// declared `field_control` split.
			'field'                     => array( 'retarget' => array( '-wrap', '' ) ),
			'wrap'                      => array( 'retarget' => array( '-wrap', '' ) ),
			'box'                       => array(),
			'submit_link_box'           => array(),
			// Presentation destinations: the phone country trigger.
			'country_trigger'           => array(),
			// Native controls.
			'control'                   => array(
				'placeholder'   => $placeholder,
				'textarea_rows' => true,
			),
			// The editor renders the single checkbox and its label inside the
			// option block, the field block's child, instead of a choice wrap.
			'checkbox_layout'           => array( 'retarget' => array( '-wrap .contact-form__checkbox-wrap', ' > .wp-block-jetpack-option' ) ),
			'sibling_stacked'           => array( 'placeholder' => $placeholder ),
			'control_container'         => array(),
			// The editor option block carries the control hook on its wrapper,
			// around the native checkbox. Jetpack's editor nudges that checkbox
			// down (`translateY(0.15em)`), which its front end does only for
			// list styles, so the editor releases it.
			'checkbox_control'          => array(
				'replace'    => array( ' input.', ' .' ),
				'retarget'   => array( '', ' > input.jetpack-field-option__checkbox' ),
				'add_resets' => array( 'transform' => 'none' ),
			),
			// The submit button.
			'submit_wrapper'            => array( 'placeholder' => $placeholder ),
			'submit_link'               => $button_font,
			'submit_link_margin_reset'  => array(),
			'submit_caption'            => array(),
			'submit_input_height_reset' => array( 'placeholder' => $placeholder ),
			// Labels.
			'label'                     => $label_box,
			'wrapping_label_reset'      => $label_box,
			'row_gap_label_reset'       => array( 'drop' => true ),
			// The editor's standalone option block is the choice box: it carries
			// the label class only as a choice-label marker, around the native
			// checkbox and the label RichText.
			'choice_label'              => array(
				'replace'  => array( ' .', ' .ssi-source-choice-label--' ),
				'retarget' => array( ' > label', ' > .jetpack-field-option__label-wrapper > .rich-text' ),
			),
			'choice_label_box'          => array( 'replace' => array( ' .', ' .ssi-source-choice-label--' ) ),
			'required_marker'           => array(),
		);
	}

	/**
	 * The editor realisation declared for one target or destination's part:
	 * the entry's own `editor` declaration, or else the editor table row.
	 *
	 * @param array<string,mixed> $entry A layout target or presentation destination.
	 * @return array{drop?:bool,selector?:string,checked?:bool,replace?:array{0:string,1:string},retarget?:array{0:string,1:string},unset_resets?:array<int,string>,add_resets?:array<string,string>,split?:array{prefixes:array<int,string>,properties:array<int,string>,resets:array<string,string>},placeholder?:array<string,string>,forced?:array<string,string>,textarea_rows?:bool}
	 * @throws LogicException When the part is missing or declares no realisation, or two.
	 */
	public static function editor_surface_part( array $entry ): array {
		$part     = $entry['part'] ?? null;
		$table    = self::editor_surface_table();
		$declared = $entry['editor'] ?? null;
		if ( ! is_string( $part ) || ( ! is_array( $declared ) && ! isset( $table[ $part ] ) ) ) {
			// ssi-error-class: programmer
			throw new LogicException( 'A provider form overlay part has no editor surface realisation.' );
		}
		if ( is_array( $declared ) && isset( $table[ $part ] ) ) {
			// ssi-error-class: programmer
			throw new LogicException( 'A provider form overlay part declares its editor surface realisation twice.' );
		}
		return is_array( $declared ) ? $declared : $table[ $part ];
	}

	/**
	 * Apply a declared editor selector, or swap a declared front-end suffix.
	 *
	 * @param array{selector?:string,replace?:array{0:string,1:string},retarget?:array{0:string,1:string}} $surface Editor realisation.
	 * @throws LogicException When the front-end selector lacks the declared suffix.
	 */
	private static function editor_selector( string $selector, array $surface, string $scope ): string {
		if ( isset( $surface['selector'] ) ) {
			return str_replace( '{scope}', $scope, $surface['selector'] );
		}
		if ( isset( $surface['replace'] ) ) {
			list( $search, $replacement ) = $surface['replace'];
			$at                           = strpos( $selector, $search );
			if ( false === $at ) {
				// ssi-error-class: programmer
				throw new LogicException( 'A provider form overlay selector does not contain its declared editor replacement.' );
			}
			$selector = substr_replace( $selector, $replacement, $at, strlen( $search ) );
		}
		if ( ! isset( $surface['retarget'] ) ) {
			return $selector;
		}
		list( $from, $to ) = $surface['retarget'];
		if ( '' !== $from && ! str_ends_with( $selector, $from ) ) {
			// ssi-error-class: programmer
			throw new LogicException( 'A provider form overlay selector does not end with its declared part suffix.' );
		}
		return substr( $selector, 0, strlen( $selector ) - strlen( $from ) ) . $to;
	}

	/**
	 * The map without its derivation metadata (each entry's `part` and declared
	 * `editor` realisation): the canonical map that
	 * `Form_Presentation_Overlay::validate_map()` and `compile()` accept.
	 *
	 * @param array<string,mixed> $map Front-end or editor target map.
	 * @return array<string,mixed>
	 */
	public static function without_parts( array $map ): array {
		foreach ( is_array( $map['targets'] ?? null ) ? $map['targets'] : array() as $key => $target ) {
			unset( $map['targets'][ $key ]['part'] );
		}
		foreach ( is_array( $map['presentation_targets'] ?? null ) ? $map['presentation_targets'] : array() as $key => $target ) {
			foreach ( is_array( $target['destinations'] ?? null ) ? $target['destinations'] : array() as $destination_key => $destination ) {
				unset( $map['presentation_targets'][ $key ]['destinations'][ $destination_key ]['part'], $map['presentation_targets'][ $key ]['destinations'][ $destination_key ]['editor'] );
			}
		}
		return $map;
	}

	/**
	 * Same source facts, explicit destinations for Jetpack's editable DOM.
	 *
	 * In the editor the contact-form block wrapper carries the source form's
	 * classes, so it is the source form; Jetpack's inner-blocks container has
	 * no source counterpart and the overlay renders it box-less. Each target
	 * and destination is realised by its declared part through
	 * `editor_surface_part()`; the result carries no part ids or realisations.
	 *
	 * @param array<string,mixed>                                $map       Front-end target map with declared parts.
	 * @param string                                             $scope     Provider form scope class.
	 * @param array<int,Static_Site_Importer_Form_Textarea_Rows> $textareas Resolved textarea row policies keyed by control index.
	 * @return array<string,mixed>
	 */
	public static function editor_layout_target_map( array $map, string $scope, array $textareas = array() ): array {
		$scope_selector = '.' . $scope;
		foreach ( $map['targets'] as &$target ) {
			$target['selector'] = self::editor_selector( $target['selector'], self::editor_surface_part( $target ), $scope_selector );
			unset( $target['part'] );
		}
		unset( $target );
		foreach ( $map['presentation_targets'] as &$target ) {
			$editor = array();
			$extra  = array();
			foreach ( $target['destinations'] as $destination ) {
				$surface = self::editor_surface_part( $destination );
				unset( $destination['part'], $destination['editor'] );
				if ( ! empty( $surface['drop'] ) ) {
					continue;
				}
				foreach ( $surface['unset_resets'] ?? array() as $reset ) {
					unset( $destination['resets'][ $reset ] );
				}
				if ( empty( $destination['resets'] ) ) {
					unset( $destination['resets'] );
					if ( empty( $destination['properties'] ) ) {
						continue;
					}
				}
				if ( isset( $surface['split'] ) ) {
					$split                     = $surface['split'];
					$box_properties            = array_values( array_filter( $destination['properties'], static fn( string $key ): bool => in_array( $key, $split['properties'], true ) || array() !== array_filter( $split['prefixes'], static fn( string $prefix ): bool => str_starts_with( $key, $prefix ) ) ) );
					$extra[]                   = array(
						'role'       => $destination['role'],
						'selector'   => $destination['selector'],
						'properties' => $box_properties,
						'resets'     => $split['resets'],
					);
					$destination['properties'] = array_values( array_diff( $destination['properties'], $box_properties ) );
				}
				$destination['selector'] = self::editor_selector( $destination['selector'], $surface, $scope_selector );
				// The editor has no checked option: a source-selected choice is painted as the field's default.
				unset( $destination['checked'] );
				if ( true === ( $surface['checked'] ?? false ) ) {
					$destination['checked'] = $destination['selector'];
				}
				foreach ( $surface['add_resets'] ?? array() as $reset => $value ) {
					$destination['resets'][ $reset ] = $value;
				}
				if ( isset( $surface['placeholder'] ) ) {
					$extra[] = array(
						'role'       => $destination['role'],
						'selector'   => $destination['selector'] . '::placeholder',
						'properties' => array(),
						'resets'     => $surface['placeholder'],
					);
				}
				if ( isset( $surface['forced'] ) ) {
					$extra[] = array_filter(
						array(
							'role'       => $destination['role'],
							'selector'   => $destination['selector'],
							'properties' => array(),
							'resets'     => $surface['forced'],
							'priority'   => $destination['priority'] ?? null,
						),
						static fn( $value ): bool => null !== $value
					);
				}
				$textarea_resets = ! empty( $surface['textarea_rows'] ) && isset( $textareas[ $target['index'] ] ) ? $textareas[ $target['index'] ]->editor_resets() : array();
				if ( array() !== $textarea_resets && ! empty( $destination['properties'] ) ) {
					$extra[] = array(
						'role'       => $destination['role'],
						'selector'   => $destination['selector'],
						'properties' => array(),
						'resets'     => $textarea_resets,
					);
				}
				$editor[] = $destination;
			}
			// Destinations sharing an editor box (a select and its wrapper) declare
			// the same companion once.
			$target['destinations'] = array_merge( $editor, array_values( array_unique( $extra, SORT_REGULAR ) ) );
		}
		unset( $target );
		return $map;
	}
}
