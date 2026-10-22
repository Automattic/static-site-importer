<?php
/**
 * Topology and layout projection for Jetpack form materialization.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Form_Topology_State' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-topology-state.php';
}
if ( ! class_exists( 'Static_Site_Importer_Provider_Layout_Overlay' ) ) {
	require_once __DIR__ . '/class-static-site-importer-provider-layout-overlay.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Field_Markup' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-field-markup.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Textarea_Rows' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-textarea-rows.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Native_Topology' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-native-topology.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Grid_Placement' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-grid-placement.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Presentation_Descriptor' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-presentation-descriptor.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Box_Transposition' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-box-transposition.php';
}

/**
 * Projects source form topology onto provider layout overlays.
 */
final class Static_Site_Importer_Form_Layout_Projection {
	/**
	 * The four physical inset sides.
	 *
	 * @internal
	 */
	public const INSET_SIDES = array( 'top', 'right', 'bottom', 'left' );

	/**
	 * Per-side margin facts.
	 *
	 * @internal
	 */
	public const MARGIN_SIDE_FACTS = array( 'margin_top', 'margin_bottom', 'margin_left', 'margin_right', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end' );

	/**
	 * The margin shorthand and every margin longhand fact.
	 *
	 * @internal
	 */
	public const MARGIN_FACTS = array( 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end' );

	/** The provider's field width steps, in percent. */
	private const WIDTH_STEPS = array( 25, 33, 50, 75, 100 );

	/**
	 * A source wrapper topology node id.
	 *
	 * @internal
	 */
	public const WRAPPER_ID_PATTERN = '/^wrapper-[0-9]+$/D';

	/** A class name the projection may carry into a marker. */
	private const CLASS_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,79}$/D';

	/**
	 * The topology result envelope with nothing projected.
	 *
	 * @internal
	 */
	public const EMPTY_TOPOLOGY_RESULT = array(
		'blocks'                       => array(),
		'losses'                       => array(),
		'operations'                   => array(),
		'represented_layout_nodes'     => array(),
		'represented_topology_nodes'   => array(),
		'suppressed_layout_properties' => array(),
		'overlay_node_targets'         => array(),
		'responsive_variant_targets'   => array(),
		'native_visibility_targets'    => array(),
		'form_classes'                 => array(),
		'provider_layout_targets'      => array(),
		'phone_popup_targets'          => array(),
	);

	/** @return array<int,array{control:int,layout:array<string,string>,condition?:array<string,mixed>}> */
	public static function direct_label_control_gap_targets( array $form ): array {
		$relations = $form['sibling_relations'] ?? null;
		$graph     = $form['layout_graph'] ?? null;
		if ( ! is_array( $relations ) || 'generic/form-sibling-relations/v1' !== ( $relations['schema'] ?? null ) || true === ( $relations['truncated'] ?? false ) || ! is_array( $relations['pairs'] ?? null ) || ! is_array( $graph['nodes'] ?? null ) ) {
			return array();
		}
		$form_node = current( array_filter( $graph['nodes'], static fn( $node ): bool => is_array( $node ) && 'form' === ( $node['id'] ?? null ) ) );
		$targets   = array();
		$plans     = array(
			array(
				'layout'     => $form_node['layout'] ?? array(),
				'condition'  => null,
				'provenance' => $form_node['provenance'] ?? array(),
			),
		);
		foreach ( $graph['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && 'form' === ( $variant['node'] ?? null ) ) {
				$plans[] = array(
					'layout'     => $variant['layout_patch'] ?? array(),
					'condition'  => $variant['condition'] ?? null,
					'provenance' => $variant['provenance'] ?? array(),
				);
			}
		}
		foreach ( $plans as $plan ) {
			$layout   = is_array( $plan['layout'] ) ? $plan['layout'] : array();
			$property = isset( $layout['row_gap'] ) ? 'row-gap' : ( isset( $layout['gap'] ) ? 'gap' : '' );
			$gap      = '' !== $property ? $layout[ str_replace( '-', '_', $property ) ] : null;
			if ( 'flex' !== ( $layout['display'] ?? null ) || 'column' !== ( $layout['direction'] ?? null ) || ! is_string( $gap ) || '' === trim( $gap ) ) {
				continue;
			}
			$proven = array_filter( $plan['provenance'], static fn( $fact ): bool => is_array( $fact ) && in_array( 'display', $fact['properties'], true ) && in_array( 'flex-direction', $fact['properties'], true ) && in_array( $property, $fact['properties'], true ) );
			if ( empty( $proven ) ) {
				continue;
			}
			foreach ( $relations['pairs'] as $pair ) {
				if ( is_array( $pair ) && is_int( $pair['control'] ?? null ) ) {
					$targets[] = array_filter( array(
						'control'   => $pair['control'],
						'layout'    => array(
							'display'   => 'flex',
							'direction' => 'column',
							'gap'       => $gap,
						),
						'condition' => $plan['condition'],
					), static fn( $value ): bool => null !== $value );
				}
			}
		}
		return $targets;
	}

	/**
	 * Canonicalize the CSS identity condition before provider planning.
	 *
	 * A producer may retain a stylesheet's implicit `media="all"` wrapper as a
	 * graph variant. It has no responsive behavior, so an unconflicted patch can
	 * safely become a base fact. Conflicting values remain variants and continue
	 * through the existing fail-closed receipt path.
	 */
	/**
	 * Box facts whose every provenance rule selects the element by classes it
	 * carries in its rightmost compound, so a restored element with those
	 * classes receives the same declaration from the enqueued source stylesheet.
	 *
	 * @param array<string,mixed>            $layout
	 * @param array<int,mixed>               $provenance
	 * @param array<int,string>              $class_tokens
	 * @param array<string,string>|null      $properties   Fact => CSS property; box facts by default.
	 * @param bool                          $subject_only Require a standalone class compound, not an ancestor-dependent selector.
	 * @return array<int,string> Layout fact keys.
	 * @internal
	 */
	public static function class_carried_box_facts( array $layout, array $provenance, array $class_tokens, ?array $properties = null, bool $subject_only = false ): array {
		$box     = $properties ?? Static_Site_Importer_Provider_Layout_Overlay::box_property_map();
		$carried = array();
		foreach ( array_intersect_key( $layout, $box ) as $fact => $value ) {
			$owners = 0;
			foreach ( $provenance as $row ) {
				if ( ! is_array( $row ) || ! in_array( $box[ $fact ], is_array( $row['properties'] ?? null ) ? $row['properties'] : array(), true ) ) {
					continue;
				}
				$parts     = preg_split( '/\s*[\s>+~]\s*/', trim( (string) ( $row['selector'] ?? '' ) ) );
				$rightmost = false === $parts ? '' : (string) end( $parts );
				if ( ( $subject_only && ( false === $parts || count( $parts ) !== 1 || ! str_starts_with( $rightmost, '.' ) ) ) || null !== ( $row['condition'] ?? null ) || ! preg_match( '/^(?:[a-z][a-z0-9-]*)?((?:\.[A-Za-z_][A-Za-z0-9_-]*)+)$/D', $rightmost, $compound ) || array_diff( explode( '.', ltrim( $compound[1], '.' ) ), $class_tokens ) ) {
					$owners = -1;
					break;
				}
				++$owners;
			}
			if ( $owners > 0 ) {
				$carried[] = $fact;
			}
		}
		return $carried;
	}

	/**
	 * Leave class-owned submit colours with the source stylesheet. Its rules
	 * already bind palette presets and include interaction states; restating a
	 * computed literal at overlay specificity would override both.
	 *
	 * @param array<string,mixed> $graph    Presentation graph.
	 * @param array<int,mixed>    $controls Source controls.
	 * @return array<string,mixed>
	 */
	public static function without_class_carried_submit_colors( array $graph, array $controls ): array {
		foreach ( $graph['controls'] ?? array() as $offset => $row ) {
			$control = $controls[ $row['index'] ] ?? array();
			if ( 'submit' !== ( $control['type'] ?? '' ) ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', trim( (string) ( $control['class'] ?? '' ) ) );
			$tokens = false === $tokens ? array() : $tokens;
			$part   = $row['control'] ?? array();
			$facts  = self::class_carried_box_facts(
				$part['styles'] ?? array(),
				$part['provenance'] ?? array(),
				$tokens,
				array(
					'background'       => 'background',
					'background_color' => 'background-color',
					'color'            => 'color',
				),
				true
			);
			// Never split overlapping shorthand/longhand ownership: a retained
			// overlay background would still win over the source longhand.
			$backgrounds = array_intersect( array_keys( $part['styles'] ?? array() ), array( 'background', 'background_color' ) );
			if ( array_diff( $backgrounds, $facts ) ) {
				$facts = array_diff( $facts, $backgrounds );
			}
			foreach ( $facts as $fact ) {
				unset( $graph['controls'][ $offset ]['control']['styles'][ $fact ] );
			}
		}
		return $graph;
	}

	/**
	 * A submit whose source wrappers are plain block boxes between it and the
	 * form occupies its own row there, placed within that row by the inherited
	 * text alignment. The provider renders the button's Core wrapper as a
	 * shrink-to-fit item of its flex form, so restore the row and the placement.
	 *
	 * @param array<int,array<string,mixed>>   $chain              Source wrappers, outermost first.
	 * @param array<string,array<string,mixed>> $layout_nodes_by_id Source layout graph nodes.
	 * @return array<string,string>|null Layout for the provider button wrapper.
	 */
	private static function submit_block_row( array $chain, array $layout_nodes_by_id ): ?array {
		$outer = $layout_nodes_by_id[ (string) ( $chain[0]['id'] ?? '' ) ] ?? null;
		if ( ! is_array( $outer ) || 'form' !== ( $outer['parent'] ?? null ) ) {
			return null;
		}
		$box   = Static_Site_Importer_Provider_Layout_Overlay::box_property_map() + array_flip( array( 'margin_block_start', 'margin_block_end' ) );
		$align = '';
		foreach ( $chain as $wrapper ) {
			// A wrapper the layout graph omits declared no layout facts, display
			// included, so it is the plain block box its topology tag describes.
			$node    = $layout_nodes_by_id[ (string) ( $wrapper['id'] ?? '' ) ] ?? array( 'source' => array( 'tag' => (string) ( $wrapper['tag'] ?? 'div' ) ) );
			$display = strtolower( trim( (string) ( $node['layout']['display'] ?? ( $node['presentation']['styles']['display'] ?? 'block' ) ) ) );
			if ( ! in_array( $node['source']['tag'] ?? '', array( 'div', 'p', 'section', 'fieldset', 'li' ), true ) || ! in_array( $display, array( 'block', 'flow-root' ), true ) || array() !== array_diff_key( is_array( $node['layout'] ?? null ) ? $node['layout'] : array(), $box ) ) {
				return null;
			}
			// text-align inherits, so the innermost box that resolves it places the button.
			$resolved = strtolower( trim( (string) ( $node['presentation']['styles']['text_align'] ?? '' ) ) );
			if ( '' !== $resolved ) {
				$align = $resolved;
			}
		}
		$justify = array(
			'left'   => 'flex-start',
			'start'  => 'flex-start',
			'center' => 'center',
			'right'  => 'flex-end',
			'end'    => 'flex-end',
		)[ $align ] ?? null;
		if ( null === $justify ) {
			return null;
		}
		return array(
			'width'           => '100%',
			'flex_basis'      => '100%',
			'flex_grow'       => '0',
			'flex_shrink'     => '0',
			'display'         => 'flex',
			'justify_content' => $justify,
		);
	}

	/**
	 * Once a submit's Core wrapper stands for its source block row, the wrapper
	 * is that row's box and the inner link is the source button's box. Move the
	 * button's own sizing facts from the wrapper destination to the link.
	 *
	 * @param array<string,mixed> $descriptor Presentation descriptor for the submit.
	 * @return array<string,mixed>
	 */
	public static function with_submit_row_button_box( array $descriptor ): array {
		$moved        = array_merge( array( 'display', 'width', 'min_width' ), self::MARGIN_FACTS );
		$destinations = is_array( $descriptor['destinations'] ?? null ) ? $descriptor['destinations'] : array();
		foreach ( $destinations as $index => $destination ) {
			if ( 'control' !== ( $destination['role'] ?? null ) || ( ! empty( $destination['resets'] ) && array() === ( $destination['properties'] ?? array() ) ) ) {
				continue;
			}
			if ( 'submit_link' === ( $destination['part'] ?? null ) ) {
				$destinations[ $index ]['properties'] = array_values( array_unique( array_merge( $destination['properties'] ?? array(), $moved ) ) );
			} elseif ( array() !== array_intersect( $moved, $destination['properties'] ?? array() ) ) {
				$destinations[ $index ]['properties'] = array_values( array_diff( $destination['properties'] ?? array(), $moved ) );
			}
		}
		$descriptor['destinations'] = array_values( array_filter( $destinations, static fn( array $destination ): bool => ! empty( $destination['properties'] ) || ! empty( $destination['resets'] ) ) );
		return $descriptor;
	}

	/** A box value equal to the CSS initial value of that property. */
	public static function is_initial_box_value( string $fact, string $value ): bool {
		$tokens = preg_split( '/\s+/', trim( $value ) );
		if ( false === $tokens || array() === $tokens ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			$initial = 1 === preg_match( '/^(?:0|0?\.?0+(?:px|rem|em|%|vw|vh|vmin|vmax|ch|ex))$/D', $token ) || ( 'min_height' === $fact && 'auto' === $token );
			if ( ! $initial ) {
				return false;
			}
		}
		return true;
	}

	public static function normalize_unconditional_layout_variants( array $form ): array {
		$graph = $form['layout_graph'] ?? null;
		if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) || ! is_array( $graph['variants'] ?? null ) ) {
			return $form;
		}

		$nodes = array();
		foreach ( $graph['nodes'] as $index => $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) && is_array( $node['layout'] ?? null ) ) {
				$nodes[ $node['id'] ] = $index;
			}
		}
		$variants = array();
		foreach ( $graph['variants'] as $variant ) {
			$node_id = is_array( $variant ) ? $variant['node'] ?? null : null;
			$patch   = is_array( $variant ) && is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : null;
			if ( ! is_string( $node_id ) || ! isset( $nodes[ $node_id ] ) || ! is_array( $patch ) || ! self::is_unconditional_media_variant( $variant ) ) {
				$variants[] = $variant;
				continue;
			}
			$node_index = $nodes[ $node_id ];
			$layout     = $graph['nodes'][ $node_index ]['layout'];
			if ( array_intersect_key( $layout, $patch ) && array_diff_assoc( array_intersect_key( $layout, $patch ), $patch ) ) {
				$variants[] = $variant;
				continue;
			}
			$graph['nodes'][ $node_index ]['layout'] = array_merge( $layout, $patch );
			foreach ( $variant['provenance'] ?? array() as $fact ) {
				if ( ! is_array( $fact ) ) {
					continue;
				}
				$fact['condition']                             = null;
				$graph['nodes'][ $node_index ]['provenance'][] = $fact;
			}
		}
		$graph['variants']    = $variants;
		$form['layout_graph'] = $graph;
		return $form;
	}

	private static function is_unconditional_media_variant( mixed $variant ): bool {
		return is_array( $variant ) && array(
			'kind'  => 'media',
			'query' => 'all',
		) === ( $variant['condition'] ?? null );
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
	 * @internal
	 */
	public static function control_carries_authored_content( string $type ): bool {
		return 'hidden' !== $type;
	}

	/** Accept only a non-nested root fieldset that contains every mapped provider control. */
	public static function projectable_plain_root_fieldset( array $fieldset, array $nodes, array $field_blocks ): bool {
		if ( 'wrapper' !== ( $fieldset['kind'] ?? null ) || 'fieldset' !== ( $fieldset['tag'] ?? null ) || 'plain_group' !== ( $fieldset['fieldset_semantics'] ?? null ) || null !== ( $fieldset['parent'] ?? null ) || ! is_string( $fieldset['id'] ?? null ) || empty( $field_blocks ) ) {
			return false;
		}
		$nodes_by_id     = array();
		$controls_by_key = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return false;
			}
			$nodes_by_id[ $node['id'] ] = $node;
			if ( 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) ) {
				$controls_by_key[ $node['control'] ] = $node;
			}
		}
		foreach ( $nodes as $node ) {
			if ( 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || ( $node['id'] ?? null ) === $fieldset['id'] ) {
				continue;
			}
			$parent = $node['parent'] ?? null;
			while ( is_string( $parent ) ) {
				if ( $fieldset['id'] === $parent ) {
					return false;
				}
				$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
			}
		}
		foreach ( array_keys( $field_blocks ) as $control_index ) {
			$parent = $controls_by_key[ $control_index ]['parent'] ?? null;
			while ( is_string( $parent ) && $fieldset['id'] !== $parent ) {
				$parent = $nodes_by_id[ $parent ]['parent'] ?? null;
			}
			if ( $fieldset['id'] !== $parent ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Flatten the validated generic tree into Jetpack's constrained direct-child
	 * grammar. Equal-fraction field-row grids (2/3/4 columns, any number of
	 * occupying fields) and complete provenance-backed percentage rows map to
	 * provider field widths; other wrapper semantics/layout remain explicit
	 * receipt losses.
	 *
	 * Every result carries the keys of EMPTY_TOPOLOGY_RESULT. Only the staged
	 * projection adds the optional suppressed_variant_properties,
	 * submit_block_rows, whole_field_controls and grid_span_submit_controls.
	 *
	 * @param array<int,array<string,mixed>> $field_blocks
	 * @param array<int,array<string,mixed>> $controls
	 * @return array{blocks:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>,represented_layout_nodes:array<int,string>,represented_topology_nodes:array<int,string>,suppressed_layout_properties:array<string,array<int,string>>,suppressed_variant_properties?:array<string,array<int,string>>,submit_block_rows?:array<int,int>,whole_field_controls?:array<int,int>,overlay_node_targets:array<int,array<string,mixed>>,responsive_variant_targets:array<int,array<string,mixed>>,native_visibility_targets:array<int,string>,form_classes:array<int,string>,provider_layout_targets:array<string,string>,phone_popup_targets:array<int,int>,grid_span_submit_controls?:array<int,int>}|null
	 */
	public static function topology_inner_blocks( array $form, array $field_blocks, array $controls, array $suppressed_controls = array() ): ?array {
		$s = new Static_Site_Importer_Form_Topology_State( $form, $field_blocks, $controls, $suppressed_controls );
		if ( ! isset( $s->form['control_topology'] ) ) {
			$derived_topology = self::derive_control_topology_from_layout_graph( $s->form, $s->controls );
			if ( null !== $derived_topology ) {
				$s->form['control_topology'] = $derived_topology;
			} else {
				return array_replace( self::EMPTY_TOPOLOGY_RESULT, array( 'blocks' => array_values( $s->field_blocks ) ) );
			}
		}
		$s->nodes = $s->form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $s->nodes ) ) {
			return null;
		}
		if ( ! self::index_control_topology( $s ) ) {
			return null;
		}
		foreach ( array_keys( $s->auxiliary_popup_controls ) as $control_index ) {
			$s->record( 'topology', 'provider_auxiliary_popup_control', 'control-' . $control_index );
		}
		foreach ( $s->form['layout_graph']['nodes'] ?? array() as $layout_node ) {
			if ( is_array( $layout_node ) && is_string( $layout_node['id'] ?? null ) ) {
				$s->layout_by_node[ $layout_node['id'] ]     = is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
				$s->layout_nodes_by_id[ $layout_node['id'] ] = $layout_node;
			}
		}
		foreach ( $s->form['layout_graph']['variants'] ?? array() as $variant ) {
			if ( is_array( $variant ) && is_string( $variant['node'] ?? null ) ) {
				$s->variants_by_node[ $variant['node'] ][] = $variant;
			}
		}
		$hidden_nodes = self::unconditionally_hidden_descendants( $s->form );
		foreach ( array_keys( $hidden_nodes ) as $node_id ) {
			$s->represented_layout_nodes[]   = $node_id;
			$s->represented_topology_nodes[] = $node_id;
			$s->record( 'topology', 'provider_omitted_hidden_subtree', $node_id );
		}
		foreach ( $s->children as $parent => $children ) {
			$s->children[ $parent ] = array_values( array_filter( $children, static fn( array $node ): bool => ! isset( $hidden_nodes[ $node['id'] ] ) ) );
		}
		$s->nodes          = array_values( array_filter( $s->nodes, static fn( array $node ): bool => ! isset( $hidden_nodes[ $node['id'] ] ) ) );
		$exact_native_tree = Static_Site_Importer_Form_Native_Topology::exact_native_topology( $s->nodes, $s->children, $s->field_blocks, $s->suppressed_controls, $s->layout_nodes_by_id, $s->layout_by_node, $s->variants_by_node, self::layout_scope( $s->form ) );
		if ( null !== $exact_native_tree ) {
			$exact_native_tree['operations']                 = array_merge( $s->operations, $exact_native_tree['operations'] );
			$exact_native_tree['represented_layout_nodes']   = array_merge( array_keys( $hidden_nodes ), $exact_native_tree['represented_layout_nodes'] );
			$exact_native_tree['represented_topology_nodes'] = array_merge( array_keys( $hidden_nodes ), $exact_native_tree['represented_topology_nodes'] );
			return $exact_native_tree;
		}
		$s->collect_controls = static function ( array $node, bool $include_auxiliary = true ) use ( $s ): array {
			if ( 'control' === ( $node['kind'] ?? null ) ) {
				$control_index = $node['control'] ?? null;
				if ( $include_auxiliary && is_int( $control_index ) && isset( $s->phone_popup_targets[ $control_index ] ) && ! isset( $s->suppressed_controls[ $s->phone_popup_targets[ $control_index ] ] ) ) {
					return array( $s->phone_popup_targets[ $control_index ] );
				}
				return is_int( $control_index ) && ! isset( $s->auxiliary_popup_controls[ $control_index ] ) && ! isset( $s->suppressed_controls[ $control_index ] ) ? array( $control_index ) : array();
			}
			$controls = array();
			foreach ( $s->children[ $node['id'] ?? '' ] ?? array() as $child ) {
				$controls = array_merge( $controls, ( $s->collect_controls )( $child, $include_auxiliary ) );
			}
			return array_values( array_unique( $controls ) );
		};
		self::project_wrapper_chains( $s );
		self::project_row_bands( $s );
		self::restore_semantic_wrappers( $s );
		Static_Site_Importer_Form_Grid_Placement::project_grid_placement( $s );
		self::project_compensated_percentage_halves( $s );
		self::project_gutter_compensated_rows( $s );
		self::project_sibling_rows( $s );
		self::project_grid_span_rows( $s );
		self::project_flex_cell_rows( $s );
		Static_Site_Importer_Form_Box_Transposition::transpose_form_boxes( $s );
		Static_Site_Importer_Form_Box_Transposition::finalize_overlay_targets( $s );
		$build = static function ( string $parent_node ) use ( $s, &$build ): array {
			$blocks = array();
			foreach ( $s->children[ $parent_node ] ?? array() as $node ) {
				if ( 'control' === ( $node['kind'] ?? null ) ) {
					$control_index = $node['control'] ?? -1;
					if ( isset( $s->field_blocks[ $control_index ] ) ) {
						$blocks[] = $s->field_blocks[ $control_index ];
					} elseif ( isset( $s->suppressed_controls[ $control_index ] ) ) {
						continue;
					} elseif ( isset( $s->controls[ $control_index ] ) ) {
						$type = self::control_type( $s->controls[ $control_index ] ?? null );
						if ( isset( $s->auxiliary_popup_controls[ $control_index ] ) || ! self::control_carries_authored_content( $type ) ) {
							continue;
						}
						$s->losses[] = array(
							'dimension'         => 'topology',
							'reason_code'       => 'unsupported_control_unrepresentable',
							'node_hash'         => hash( 'sha256', $node['id'] ),
							'control_index'     => $control_index,
							'control_type_hash' => hash( 'sha256', $type ),
						);
					}
					continue;
				}
				$inner_blocks = $build( $node['id'] );
				$blocks       = array_merge( $blocks, $inner_blocks );
			}
			return $blocks;
		};
		// A wrapper that also earned its own overlay target (because carrying its
		// source class name alone cannot promise a responsive variant under a
		// different, unrepresented class) must keep its graph node so that target
		// map can still address it; "represented by source class" is no longer
		// the operative claim once an overlay target exists for the same node.
		$s->represented_layout_nodes = array_values( array_diff( array_map( 'strval', $s->represented_layout_nodes ), array_keys( $s->provider_layout_targets ) ) );
		// A conditional box patch stays with the restored source element only when
		// its base fact did too (or it has none); otherwise shell and element would
		// each hold one half of the same property.
		$suppressed_variant_properties = array();
		foreach ( $s->class_carried_variants as $node_id => $facts ) {
			$base = $s->layout_nodes_by_id[ $node_id ]['layout'] ?? array();
			foreach ( array_unique( $facts ) as $fact ) {
				if ( ! isset( $base[ $fact ] ) || in_array( $fact, $s->suppressed_layout_properties[ $node_id ] ?? array(), true ) ) {
					$suppressed_variant_properties[ $node_id ][] = $fact;
				}
			}
		}
		return array(
			'blocks'                        => $build( '$root' ),
			'losses'                        => $s->losses,
			'operations'                    => $s->operations,
			'represented_layout_nodes'      => array_values( array_unique( array_map( 'strval', $s->represented_layout_nodes ) ) ),
			'represented_topology_nodes'    => array_values( array_unique( array_map( 'strval', $s->represented_topology_nodes ) ) ),
			'suppressed_layout_properties'  => $s->suppressed_layout_properties,
			'suppressed_variant_properties' => $suppressed_variant_properties,
			'submit_block_rows'             => $s->submit_block_rows,
			'whole_field_controls'          => $s->whole_field_controls,
			'overlay_node_targets'          => $s->overlay_node_targets,
			'responsive_variant_targets'    => $s->responsive_variant_targets,
			'native_visibility_targets'     => array_values( array_unique( array_map( 'strval', $s->native_visibility_targets ) ) ),
			'form_classes'                  => array_values( array_unique( $s->form_classes ) ),
			'provider_layout_targets'       => $s->provider_layout_targets,
			'phone_popup_targets'           => $s->phone_popup_targets,
			'grid_span_submit_controls'     => array_values( array_unique( $s->grid_span_submit_controls ) ),
		);
	}

	/**
	 * Index the control topology and detect provider-owned auxiliary popup controls.
	 *
	 * @return bool False when a topology node is malformed.
	 */
	private static function index_control_topology( Static_Site_Importer_Form_Topology_State $s ): bool {
		$s->children             = array( '$root' => array() );
		$s->topology_nodes_by_id = array();
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				return false;
			}
			$parent                                 = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$s->children[ $parent ][]               = $node;
			$s->topology_nodes_by_id[ $node['id'] ] = $node;
		}
		foreach ( $s->children as &$siblings ) {
			usort( $siblings, static fn ( array $left, array $right ): int => $left['order'] <=> $right['order'] );
		}
		unset( $siblings );
		$s->control_parents  = array();
		$s->topology_parents = array();
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$parent                             = isset( $node['parent'] ) && is_string( $node['parent'] ) ? $node['parent'] : '$root';
			$s->topology_parents[ $node['id'] ] = $parent;
			if ( 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) ) {
				$s->control_parents[ $node['control'] ] = $parent;
			}
		}
		$s->auxiliary_popup_controls = array();
		$s->phone_popup_targets      = array();
		$shares_phone_group          = static function ( int $popup_control, int $phone_control ) use ( $s ): bool {
			$popup_parent = $s->control_parents[ $popup_control ] ?? null;
			$phone_parent = $s->control_parents[ $phone_control ] ?? null;
			if ( ! is_string( $popup_parent ) || ! is_string( $phone_parent ) || '$root' === $phone_parent ) {
				return false;
			}
			for ( $depth = 0; $depth < 16 && '$root' !== $popup_parent; ++$depth ) {
				if ( $phone_parent === $popup_parent ) {
					return true;
				}
				$popup_parent = $s->topology_parents[ $popup_parent ] ?? '$root';
			}
			return false;
		};
		foreach ( $s->controls as $control_index => $control ) {
			if ( ! in_array( self::control_type( $control, false ), array( 'phone', 'tel' ), true ) ) {
				$type      = self::control_type( $control, false );
				$tag       = strtolower( trim( (string) ( $control['tag'] ?? '' ) ) );
				$popup     = strtolower( trim( (string) ( $control['aria_haspopup'] ?? '' ) ) );
				$described = preg_split( '/\s+/', trim( (string) ( $control['aria_describedby'] ?? '' ) ) );
				if ( 'button' !== $tag || 'button' !== $type || ! in_array( $popup, array( 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog' ), true ) || false === $described || empty( $described ) ) {
					continue;
				}
				foreach ( $s->controls as $field_index => $field ) {
					$label_id = trim( (string) ( $field['label_id'] ?? '' ) );
					if ( $field_index === $control_index || empty( $field['readonly'] ) || '' === $label_id || ! in_array( $label_id, $described, true ) || ( $s->control_parents[ $field_index ] ?? null ) !== ( $s->control_parents[ $control_index ] ?? null ) ) {
						continue;
					}
					$s->auxiliary_popup_controls[ $control_index ] = true;
					break;
				}
				continue;
			}
			$previous = $s->controls[ $control_index - 1 ] ?? null;
			if ( is_array( $previous ) && Static_Site_Importer_Form_Field_Markup::is_provider_auxiliary_button( $s->controls, $control_index - 1 ) && $shares_phone_group( $control_index - 1, $control_index ) ) {
				$s->phone_popup_targets[ $control_index - 1 ]      = $control_index;
				$s->auxiliary_popup_controls[ $control_index - 1 ] = true;
			}
		}
		return true;
	}

	/** Project each single-control source wrapper chain onto its provider field shell. */
	private static function project_wrapper_chains( Static_Site_Importer_Form_Topology_State $s ): void {
		$wrapper_chains     = array();
		$compound_ancestors = array();
		foreach ( $s->phone_popup_targets as $auxiliary => $primary ) {
			$parent = $s->control_parents[ $auxiliary ] ?? '$root';
			for ( $depth = 0; $depth < 16 && '$root' !== $parent; ++$depth ) {
				$compound_ancestors[ $parent ][ $primary ] = true;
				$parent                                    = $s->topology_parents[ $parent ] ?? '$root';
			}
		}
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			// Provider ownership does not imply DOM containment: a prefix's wrappers
			// must not be restored around the primary value input.
			$branch_controls = ( $s->collect_controls )( $node, false );
			if ( empty( $branch_controls ) ) {
				$branch_controls          = ( $s->collect_controls )( $node );
				$node['destination_role'] = 'prefix';
			}
			if ( 1 !== count( $branch_controls ) ) {
				continue;
			}
			$control_index = $branch_controls[0];
			if ( isset( $compound_ancestors[ $node['id'] ][ $control_index ] ) && ! isset( $node['destination_role'] ) ) {
				$node['destination_role'] = 'shell';
			}
			// A classless paragraph around one control (a newsletter row's
			// `<p id="subscribe-email">`, styled by id) is the same proven box as a
			// classless div: its layout must reach the provider field shell, or the
			// facts become an unrepresentable topology loss that declines the form.
			$source_class                 = trim( (string) ( $node['class'] ?? '' ) );
			$is_projectable_classless_box = '' === $source_class
				&& in_array( $node['tag'] ?? '', array( 'div', 'span', 'p' ), true )
				&& ( ! empty( $s->layout_by_node[ $node['id'] ] ?? array() ) || ! empty( $s->variants_by_node[ $node['id'] ] ?? array() ) );
			if ( ! is_int( $control_index ) || ! isset( $s->field_blocks[ $control_index ] ) || ( '' === $source_class && ! $is_projectable_classless_box ) ) {
				continue;
			}
			$wrapper_chains[ $control_index ][] = $node;
		}
		foreach ( $wrapper_chains as $control_index => $chain ) {
			usort( $chain, static fn ( array $left, array $right ): int => (int) ( $left['depth'] ?? 0 ) <=> (int) ( $right['depth'] ?? 0 ) );
			$class_names  = array( (string) ( $s->field_blocks[ $control_index ]['attrs']['className'] ?? '' ) );
			$choice_label = Static_Site_Importer_Form_Presentation_Descriptor::source_choice_label( $s->form, $control_index );
			// A button control is rendered by Core, which carries the source box as its
			// own block element and has no provider field shell to rebuild layers inside.
			if ( 'core/button' === ( $s->field_blocks[ $control_index ]['name'] ?? '' ) ) {
				$outermost                            = $chain[0];
				$button_hook                          = self::layout_node_class( self::layout_scope( $s->form ), $outermost['id'] );
				$class_names[]                        = $button_hook;
				$s->wrapper_hooks[ $outermost['id'] ] = $button_hook;
				$s->submit_block_rows[]               = $control_index;

				$s->field_blocks[ $control_index ]['attrs']['className'] = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $class_names ) ) ) );

				$s->record( 'topology', 'provider_field_wrapper_class_projection', $outermost['id'] );

				$row = self::submit_block_row( $chain, $s->layout_nodes_by_id );
				if ( null !== $row ) {
					$s->provider_layout_targets[ $outermost['id'] ] = $button_hook;
					$s->overlay_node_targets[]                      = array(
						'id'     => $outermost['id'],
						'layout' => $row,
					);

					$s->record( 'layout', 'provider_submit_block_row', $outermost['id'] );
				}
				continue;
			}
			foreach ( $chain as $offset => $node ) {
				$generated_class = self::layout_node_class( self::layout_scope( $s->form ), $node['id'] );
				if ( null !== $choice_label && (int) $node['depth'] <= (int) $choice_label['depth'] ) {
					$source_classes = preg_split( '/\s+/', trim( (string) ( $node['class'] ?? '' ) ) );
					$classes        = array_merge( false === $source_classes ? array() : $source_classes, array( $generated_class ) );
					$role           = $node['id'] === $choice_label['id'] ? 'choice-box' : 'field-ancestor-' . (int) $node['depth'];
					foreach ( $classes as $class ) {
						$class_names[] = 'ssi-source-' . $role . '--' . $class;
					}
					$s->wrapper_hooks[ $node['id'] ]           = $generated_class;
					$s->provider_layout_targets[ $node['id'] ] = $generated_class;
					$s->whole_field_controls[ $control_index ] = $control_index;
					continue;
				}
				$wrapper_classes = self::class_tokens( $node );
				// The outermost source box is the provider's own field shell, and the
				// runtime rebuilds every deeper box as its own element. Giving each box
				// its own hook keeps one source element addressable by one target instead
				// of collapsing a nested chain onto a single element.
				$is_primary_wrapper = ! isset( $node['destination_role'] );
				if ( 0 === $offset && $is_primary_wrapper ) {
					$class_names[] = $generated_class;
				} else {
					$wrapper_classes[] = $generated_class;
				}
				$layer = min( 99, max( 0, (int) ( $node['depth'] ?? 0 ) ) );
				// The suffix is an explicit runtime projection contract: these classes
				// describe wrapper layers, not provider block classes. The runtime consumes
				// them only inside a provider field shell and rebuilds the layer at the
				// native control, leaving source classes out of persisted block markup.
				// Jetpack derives its field-shell classes by adding `-wrap`. Keep the
				// transport marker unsuffixed so that derivation leaves one recognizable
				// suffix rather than making it part of the restored source class.
				// A classless source box can still have proven inline layout. Transport its
				// generated hook so the runtime can restore a concrete box for the overlay.
				if ( empty( $wrapper_classes ) ) {
					$wrapper_classes[] = $generated_class;
				}
				$wrapper_role                    = $is_primary_wrapper ? '' : $node['destination_role'] . '-';
				$class_names[]                   = implode( ' ', array_map( static fn ( string $class_name ): string => 'ssi-source-wrapper-' . $wrapper_role . $layer . '--' . $class_name, $wrapper_classes ) );
				$s->wrapper_hooks[ $node['id'] ] = 0 === $offset && $is_primary_wrapper ? $generated_class . '-wrap' : $generated_class;
				$s->record( 'topology', 'provider_field_wrapper_class_projection', $node['id'] );
				// A source box whose own stylesheet addresses it by class keeps its layout
				// through the projected classes, so it needs no generated overlay target.
				$class_tokens = self::class_tokens( $node );
				$provenance   = $s->layout_nodes_by_id[ $node['id'] ]['provenance'] ?? array();
				$class_owned  = ! empty( $s->layout_by_node[ $node['id'] ] ?? array() ) && self::class_owns_rows( $provenance, $class_tokens );
				if ( $class_owned ) {
					$s->represented_layout_nodes[] = $node['id'];
				} elseif ( 0 === $offset && $is_primary_wrapper && array() !== $class_tokens ) {
					// Box facts add up across nested elements. The runtime rebuilds this
					// primary box as its own element carrying its source classes, so a
					// source rule whose subject is those classes already paints it there;
					// repeating the box on the provider shell would double it.
					self::carry_class_owned_facts( $s, $node['id'], $provenance, $class_tokens, Static_Site_Importer_Provider_Layout_Overlay::box_property_map(), 'provider_source_box_class_carry' );
				}
			}
			$s->field_blocks[ $control_index ]['attrs']['className'] = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $class_names ) ) ) );
			self::suppress_parent_class_carried_control_facts( $s, $control_index, $chain );
		}
	}

	/**
	 * A producer may resolve a parent's spacing onto its child: the gap of a
	 * `grid gap-2` field wrapper becomes the control's `margin-block-start`, its
	 * provenance the wrapper's own class rule. The runtime rebuilds that wrapper
	 * with its source classes, so the enqueued stylesheet already spaces the
	 * control; compiling the fact onto the control as well doubles the gap.
	 *
	 * @param array<int,array<string,mixed>> $chain Source wrappers around the control.
	 */
	private static function suppress_parent_class_carried_control_facts( Static_Site_Importer_Form_Topology_State $s, int $control_index, array $chain ): void {
		$control_id = 'control-' . $control_index;
		$parent_id  = (string) ( $s->layout_nodes_by_id[ $control_id ]['parent'] ?? '' );
		$parent     = null;
		foreach ( $chain as $node ) {
			if ( '' !== $parent_id && ( $node['id'] ?? null ) === $parent_id ) {
				$parent = $node;
			}
		}
		$class_tokens = self::class_tokens( $parent );
		if ( array() === $class_tokens ) {
			return;
		}
		$properties = array_intersect_key( Static_Site_Importer_Provider_Layout_Overlay::layout_property_map(), array_flip( self::MARGIN_SIDE_FACTS ) );
		self::carry_class_owned_facts( $s, $control_id, $s->layout_nodes_by_id[ $control_id ]['provenance'] ?? array(), $class_tokens, $properties, 'provider_parent_class_carried_control_spacing' );
	}

	/**
	 * Leave a node's class-owned facts to the source stylesheet. A fact is
	 * carried when every rule setting it selects the node by classes the
	 * restored element keeps; a conditional patch only when its own rule is too.
	 * Carried facts are suppressed from the overlay and recorded once.
	 *
	 * @param array<int,mixed>     $provenance   The node's base provenance rows.
	 * @param array<int,string>    $class_tokens Classes the restored element carries.
	 * @param array<string,string> $properties   Fact => CSS property eligible for carry.
	 */
	private static function carry_class_owned_facts( Static_Site_Importer_Form_Topology_State $s, string $node_id, array $provenance, array $class_tokens, array $properties, string $strategy ): void {
		$carried = self::class_carried_box_facts( $s->layout_by_node[ $node_id ] ?? array(), $provenance, $class_tokens, $properties );
		foreach ( $s->variants_by_node[ $node_id ] ?? array() as $variant ) {
			$patch = self::layout_patch( $variant );
			foreach ( array_keys( array_intersect_key( $patch, $properties ) ) as $fact ) {
				$rows = array_map( static fn( $row ): mixed => is_array( $row ) ? array_merge( $row, array( 'condition' => null ) ) : $row, is_array( $variant['provenance'] ?? null ) ? $variant['provenance'] : array() );
				if ( array( $fact ) === self::class_carried_box_facts( array( $fact => $patch[ $fact ] ), $rows, $class_tokens, $properties ) ) {
					$s->class_carried_variants[ $node_id ][] = $fact;
				} else {
					$carried = array_values( array_diff( $carried, array( $fact ) ) );
				}
			}
		}
		if ( array() === $carried ) {
			return;
		}
		$s->suppressed_layout_properties[ $node_id ] = array_values( array_unique( array_merge( $s->suppressed_layout_properties[ $node_id ] ?? array(), $carried ) ) );
		$s->layout_by_node[ $node_id ]               = array_diff_key( $s->layout_by_node[ $node_id ] ?? array(), array_flip( $carried ) );
		$s->record( 'layout', $strategy, $node_id );
	}

	/** Size the boxes of a multi-box source row as provider field widths. */
	private static function project_row_bands( Static_Site_Importer_Form_Topology_State $s ): void {
		// A source row that holds several boxes is a band of columns. The provider
		// states that relationship with its own field width, so the boxes sit side
		// by side there instead of stacking one per row.
		foreach ( self::source_grid_row_bands( $s->layout_nodes_by_id, $s->variants_by_node ) as $band ) {
			$members = array();
			foreach ( $band as $node_id => $width ) {
				$node = $s->topology_nodes_by_id[ $node_id ] ?? null;
				if ( ! is_array( $node ) ) {
					continue 2;
				}
				$branch = $s->mapped_branch( $node );
				if ( 1 !== count( $branch ) || 'core/button' === ( $s->field_blocks[ $branch[0] ]['name'] ?? '' ) ) {
					continue 2;
				}
				$members[ $branch[0] ] = $width;
			}
			$total = array_sum( $members );
			if ( count( $members ) < 2 || $total <= 0 ) {
				continue;
			}
			$widths = self::band_field_widths( array_map( static fn ( float $width ): float => $width / $total, array_values( $members ) ) );
			if ( null === $widths ) {
				continue;
			}
			foreach ( array_keys( $members ) as $position => $control_index ) {
				$s->field_blocks[ $control_index ]['attrs']['width'] = $widths[ $position ];
				$s->record( 'layout', 'provider_row_band_field_width', (string) $control_index );
			}
		}
	}

	/** Restore source paragraphs and fieldsets around the controls they own. */
	private static function restore_semantic_wrappers( Static_Site_Importer_Form_Topology_State $s ): void {
		// Jetpack fields own their editable label/control pair. A source paragraph
		// around exactly that pair can be restored at render time without claiming
		// that an arbitrary semantic wrapper is a Gutenberg group.
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'p' !== ( $node['tag'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$branch_controls = $s->mapped_branch( $node );
			if ( 1 !== count( $branch_controls ) ) {
				continue;
			}
			$control_index = $branch_controls[0];
			$s->field_blocks[ $control_index ]['attrs']['className'] = self::with_semantic_markers( $s->field_blocks[ $control_index ]['attrs']['className'] ?? '', $node, 'p', self::class_tokens( $node ) );
			$s->represented_topology_nodes[]                         = $node['id'];
			$s->record( 'topology', 'provider_paragraph_wrapper_projection', $node['id'] );
		}
		// A nested labelled fieldset around one to four mapped text fields is the
		// source Name/phone group. Producers often omit legend text while still
		// classifying the wrapper as labelled_group. Jetpack keeps the fields.
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'labelled_group' !== ( $node['fieldset_semantics'] ?? null ) || null === ( $node['parent'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$branch_controls = ( $s->collect_controls )( $node );
			$branch_fields   = array();
			$unmapped        = false;
			foreach ( $branch_controls as $control_index ) {
				if ( isset( $s->suppressed_controls[ $control_index ] ) ) {
					continue;
				}
				if ( ! isset( $s->field_blocks[ $control_index ] ) || 'core/button' === ( $s->field_blocks[ $control_index ]['name'] ?? '' ) ) {
					$unmapped = true;
					break;
				}
				$type = self::control_type( $s->controls[ $control_index ] ?? null );
				if ( ! in_array( $type, array( '', 'text', 'email', 'tel', 'phone', 'number', 'url' ), true ) ) {
					$unmapped = true;
					break;
				}
				$branch_fields[] = $control_index;
			}
			if ( $unmapped || ! in_array( count( $branch_fields ), array( 1, 2, 3, 4 ), true ) ) {
				continue;
			}
			$s->represented_topology_nodes[] = $node['id'];
			$s->represented_layout_nodes[]   = $node['id'];
			$s->record( 'semantic', 'provider_labelled_text_fieldset_projection', $node['id'], array( 'control_count' => count( $branch_fields ) ) );
		}
		// A plain fieldset around exactly one labelled text, email, tel, or textarea
		// control is that control's own group, whether it is nested or a peer root.
		// Jetpack keeps the editable pair; the runtime restores one fieldset from
		// the depth marker. An all-controls root fieldset stays on its own projection.
		// Labelled, unlabelled, and multi-control groups stay losses.
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'plain_group' !== ( $node['fieldset_semantics'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			if ( self::projectable_plain_root_fieldset( $node, $s->nodes, $s->field_blocks ) ) {
				continue;
			}
			$branch_controls = ( $s->collect_controls )( $node );
			if ( 1 !== count( $branch_controls ) ) {
				continue;
			}
			$control_index = $branch_controls[0];
			if ( isset( $s->suppressed_controls[ $control_index ] ) || ! isset( $s->field_blocks[ $control_index ] ) || 'core/button' === ( $s->field_blocks[ $control_index ]['name'] ?? '' ) ) {
				continue;
			}
			$type  = self::control_type( $s->controls[ $control_index ] ?? null );
			$label = $s->controls[ $control_index ]['label'] ?? null;
			if ( ! in_array( $type, array( 'text', 'email', 'tel', 'textarea' ), true ) || ! is_string( $label ) || '' === trim( $label ) ) {
				continue;
			}
			$owns_label = false;
			foreach ( is_array( $s->field_blocks[ $control_index ]['innerBlocks'] ?? null ) ? $s->field_blocks[ $control_index ]['innerBlocks'] : array() as $inner ) {
				if ( is_array( $inner ) && 'jetpack/label' === ( $inner['name'] ?? null ) && trim( $label ) === trim( (string) ( $inner['attrs']['label'] ?? '' ) ) ) {
					$owns_label = true;
					break;
				}
			}
			if ( ! $owns_label ) {
				continue;
			}
			$classes = array_values( array_unique( array_filter( self::class_tokens( $node ), static fn( string $class_name ): bool => 1 === preg_match( self::CLASS_NAME_PATTERN, $class_name ) ) ) );
			$s->field_blocks[ $control_index ]['attrs']['className'] = self::with_semantic_markers( $s->field_blocks[ $control_index ]['attrs']['className'] ?? '', $node, 'fieldset', $classes );
			$s->represented_topology_nodes[]                         = $node['id'];
			$s->represented_layout_nodes[]                           = $node['id'];
			$s->record( 'topology', 'provider_plain_single_field_fieldset_projection', $node['id'] );
		}
		$s->mapped_controls = array_keys( $s->field_blocks );
		sort( $s->mapped_controls );
		// Jetpack owns the form and its handler nodes, but a plain root fieldset that
		// contains every mapped control can be restored around only its field list.
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || 'fieldset' !== ( $node['tag'] ?? null ) || 'plain_group' !== ( $node['fieldset_semantics'] ?? null ) || null !== ( $node['parent'] ?? null ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			if ( ! self::projectable_plain_root_fieldset( $node, $s->nodes, $s->field_blocks ) ) {
				continue;
			}
			$class_tokens                    = array_values( array_filter( self::class_tokens( $node ), static fn( string $class_name ): bool => 1 === preg_match( self::CLASS_NAME_PATTERN, $class_name ) ) );
			$s->form_classes                 = array_merge( $s->form_classes, array( 'ssi-source-root-fieldset' ), array_map( static fn( string $class_name ): string => 'ssi-source-root-fieldset--' . $class_name, array_slice( array_values( array_unique( $class_tokens ) ), 0, 8 ) ) );
			$s->represented_topology_nodes[] = $node['id'];
			$s->record( 'topology', 'provider_plain_root_fieldset_projection', $node['id'] );
			break;
		}
		foreach ( $s->nodes as $node ) {
			if ( ! is_array( $node ) || 'wrapper' !== ( $node['kind'] ?? null ) || '' === trim( (string) ( $node['class'] ?? '' ) ) ) {
				continue;
			}
			if ( 'fieldset' === ( $node['tag'] ?? null ) && 'plain_group' === ( $node['fieldset_semantics'] ?? null ) && null === ( $node['parent'] ?? null ) ) {
				continue;
			}
			$branch_controls = ( $s->collect_controls )( $node );
			sort( $branch_controls );
			if ( $s->mapped_controls !== $branch_controls || count( $s->children[ $node['id'] ] ?? array() ) < 2 ) {
				continue;
			}
			$source_class = trim( (string) $node['class'] );
			$class_tokens = preg_split( '/\s+/', $source_class );
			if ( false === $class_tokens ) {
				$class_tokens = array();
			}
			$facts       = array_merge( $s->layout_nodes_by_id[ $node['id'] ]['provenance'] ?? array(), ...array_map( static fn ( array $variant ): array => is_array( $variant['provenance'] ?? null ) ? $variant['provenance'] : array(), $s->variants_by_node[ $node['id'] ] ?? array() ) );
			$class_owned = self::class_owns_rows( $facts, $class_tokens );
			if ( ! $class_owned && null === self::display_from_class_tokens( $class_tokens ) ) {
				continue;
			}
			$s->form_classes                 = array_merge( $s->form_classes, $class_tokens );
			$s->represented_layout_nodes[]   = $node['id'];
			$s->represented_topology_nodes[] = $node['id'];
			$s->record( 'topology', 'provider_field_list_class_projection', $node['id'] );
			break;
		}
	}

	/**
	 * Project gutter-compensated percentage rows onto equal-width provider fields.
	 *
	 * A common legacy row lays out equal `inline-block` columns at `width: N%`
	 * with a uniform trailing `padding-right: G`, and widens the row itself to
	 * `width: calc(100% + G)` so the last column's padding overhangs. Visually
	 * that is a row of equal columns separated by gutter G, which is exactly what
	 * the provider's equal-width fields paint. The row may be authored at every
	 * width or only inside one min-width query; below that query the fields stack.
	 * An overhang that differs from the column gutter by more than one pixel is a
	 * different layout and keeps its loss. Remaining box facts on the row or its
	 * columns are reported as named box losses.
	 */
	private static function project_gutter_compensated_rows( Static_Site_Importer_Form_Topology_State $s ): void {
		$box_facts = Static_Site_Importer_Provider_Layout_Overlay::box_property_map();
		foreach ( $s->children as $parent => $siblings ) {
			if ( 1 !== preg_match( self::WRAPPER_ID_PATTERN, $parent ) || count( $siblings ) < 2 || in_array( $parent, $s->represented_layout_nodes, true ) ) {
				continue;
			}
			$parent_node = $s->layout_nodes_by_id[ $parent ] ?? null;
			if ( ! is_array( $parent_node ) ) {
				continue;
			}
			// The row scope is where the source widens the row: its base layout, or
			// exactly one min-width variant.
			$scope    = null;
			$overhang = self::gutter_overhang( $s->layout_by_node[ $parent ] ?? array() );
			$proven   = null !== $overhang && self::has_unconditional_proven_property( $parent_node, 'width' );
			if ( null === $overhang ) {
				foreach ( $s->variants_by_node[ $parent ] ?? array() as $variant ) {
					$candidate = self::gutter_overhang( self::layout_patch( $variant ) );
					if ( null === $candidate ) {
						continue;
					}
					if ( null !== $scope || ! self::min_width_query( $variant['condition'] ?? null ) ) {
						$overhang = null;
						break;
					}
					$scope    = $variant['condition'];
					$overhang = $candidate;
					$proven   = self::variant_proven( $variant, 'width' );
				}
			}
			if ( ! $proven || null === $overhang ) {
				continue;
			}
			$consumed = array( $parent => array( ( null === $scope ? 'base' : 'scope' ) => array( 'width' ) ) );
			$cells    = array();
			foreach ( $siblings as $sibling ) {
				$id       = is_string( $sibling['id'] ?? null ) ? $sibling['id'] : '';
				$box      = $s->layout_nodes_by_id[ $id ] ?? null;
				$controls = ( $s->collect_controls )( $sibling );
				if ( 'wrapper' !== ( $sibling['kind'] ?? null ) || ! is_array( $box ) || 1 !== count( $controls ) || ! isset( $s->field_blocks[ $controls[0] ] ) || 'core/button' === ( $s->field_blocks[ $controls[0] ]['name'] ?? '' ) ) {
					$cells = array();
					break;
				}
				$row   = is_array( $box['layout'] ?? null ) ? $box['layout'] : array();
				$proof = array_fill_keys( array_keys( $row ), $box );
				foreach ( $s->variants_by_node[ $id ] ?? array() as $variant ) {
					if ( null !== $scope && ( $variant['condition'] ?? null ) === $scope ) {
						$row   = array_replace( $row, self::layout_patch( $variant ) );
						$proof = array_replace( $proof, array_fill_keys( array_keys( self::layout_patch( $variant ) ), $variant ) );
					}
				}
				$proof_properties = array(
					'display'       => 'display',
					'width'         => 'width',
					'padding_right' => 'padding-right',
				);
				$width            = is_string( $row['width'] ?? null ) && 1 === preg_match( '/^([0-9]+(?:\.[0-9]+)?)%$/D', trim( $row['width'] ), $match ) ? (float) $match[1] : 0.0;
				$proven           = 0.0 < $width && 'inline-block' === ( $row['display'] ?? null ) && is_string( $row['padding_right'] ?? null );
				foreach ( $proof_properties as $fact => $property ) {
					$source = $proof[ $fact ] ?? null;
					$proven = $proven && is_array( $source ) && ( $source === $box ? self::has_unconditional_proven_property( $box, $property ) : self::variant_proven( $source, $property ) );
				}
				if ( ! $proven ) {
					$cells = array();
					break;
				}
				$cells[]         = array(
					'id'      => $id,
					'control' => $controls[0],
					'width'   => $width,
					'gutter'  => trim( (string) $row['padding_right'] ),
				);
				$consumed[ $id ] = array( ( null === $scope ? 'base' : 'scope' ) => array( 'display', 'width', 'padding_right' ) );
			}
			if ( count( $cells ) !== count( $siblings ) ) {
				continue;
			}
			$widths  = array_unique( array_map( static fn ( array $cell ): string => (string) $cell['width'], $cells ) );
			$gutters = array_unique( array_column( $cells, 'gutter' ) );
			if ( 1 !== count( $widths ) || 1 !== count( $gutters ) || 0.01 < abs( $cells[0]['width'] * count( $cells ) - 100.0 ) || ! self::gutter_matches_overhang( $gutters[0], $overhang ) ) {
				continue;
			}
			// Outside the row scope a column may only return to block flow (the
			// stacked state the provider paints below the row's query); any other
			// fact must be a box fact, reported below as a named box loss.
			$residual = array();
			foreach ( $consumed as $node_id => $taken ) {
				$facts = array_diff( array_keys( $s->layout_by_node[ $node_id ] ?? array() ), $taken['base'] ?? array() );
				foreach ( $s->variants_by_node[ $node_id ] ?? array() as $variant ) {
					$patch = self::layout_patch( $variant );
					if ( null !== $scope && ( $variant['condition'] ?? null ) === $scope ) {
						$patch = array_diff_key( $patch, array_flip( $taken['scope'] ?? array() ) );
					} elseif ( $node_id !== $parent && 'block' === ( $patch['display'] ?? null ) ) {
						unset( $patch['display'] );
					}
					$facts = array_merge( $facts, array_keys( $patch ) );
				}
				// Outside a scoped widening the row's own `width: 100%` is the
				// stacked block flow the provider paints.
				if ( $node_id === $parent && null !== $scope && '100%' === ( $s->layout_by_node[ $parent ]['width'] ?? null ) ) {
					$facts = array_diff( $facts, array( 'width' ) );
				}
				$facts = array_values( array_unique( $facts ) );
				if ( array_diff( $facts, array_keys( $box_facts ) ) ) {
					continue 2;
				}
				if ( ! empty( $facts ) ) {
					$residual[] = $node_id;
				}
			}
			$width       = self::provider_field_width( 1 / count( $cells ) );
			$stack_query = null === $scope ? null : self::inverted_min_width_media_query( (string) $scope['query'] );
			if ( null !== $scope && null === $stack_query ) {
				continue;
			}
			// Each column is an equal share of the row less its part of the
			// gutters; every column but the last keeps the gutter after it.
			$track = self::equal_fraction_track_size( count( $cells ), $gutters[0] );
			$last  = count( $cells ) - 1;
			foreach ( $cells as $offset => $cell ) {
				$layout = array(
					'width'       => $track,
					'flex_grow'   => '0',
					'flex_shrink' => '0',
					'flex_basis'  => $track,
				);
				if ( $offset < $last ) {
					$layout['margin_right'] = $gutters[0];
				}
				$s->field_blocks[ $cell['control'] ]['attrs']['width'] = $width;
				$s->represented_layout_nodes[]                         = $cell['id'];
				$s->overlay_node_targets[]                             = array(
					'id'     => 'field-' . $cell['control'],
					'layout' => $layout,
				);
				if ( is_string( $stack_query ) ) {
					$s->responsive_variant_targets[] = array(
						'node'         => 'field-' . $cell['control'],
						'condition'    => array(
							'kind'  => 'media',
							'query' => $stack_query,
						),
						'layout_patch' => array(
							'flex'         => '1 1 100%',
							'width'        => '100%',
							// The provider caps a half-width field at 50%; the
							// stacked source column spans the whole row.
							'max_width'    => '100%',
							'margin_right' => '0',
						),
					);
				}
			}
			$s->represented_layout_nodes[]          = $parent;
			$s->percentage_width_parents[ $parent ] = true;
			$s->record(
				'layout',
				'provider_gutter_compensated_row_fields',
				$parent,
				array(
					'field_count' => count( $cells ),
					'width'       => $width,
				)
			);
			foreach ( $residual as $node_id ) {
				$s->losses[] = array(
					'dimension'   => 'layout',
					'reason_code' => 'provider_wrapper_box_unrepresentable',
					'node_hash'   => hash( 'sha256', $node_id ),
				);
			}
		}
	}

	/**
	 * Project a proven percentage pair per condition onto equal provider fields.
	 * Float/clear are not exported by the producer; require a bounded width and
	 * gutter budget in the base and every supported media condition instead.
	 *
	 * @param Static_Site_Importer_Form_Topology_State $s Projection state.
	 */
	private static function project_compensated_percentage_halves( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->children as $parent => $siblings ) {
			if ( 2 !== count( $siblings ) || isset( $s->percentage_width_parents[ $parent ] ) ) {
				continue;
			}
			$row_facts = Static_Site_Importer_Form_Box_Transposition::non_block_flow_facts( $s->layout_by_node[ $parent ] ?? array() );
			if ( array_diff( $row_facts, array_keys( Static_Site_Importer_Provider_Layout_Overlay::box_property_map() ) ) || ! empty( $s->variants_by_node[ $parent ] ) ) {
				continue;
			}
			$cells      = array();
			$conditions = array();
			foreach ( $siblings as $node ) {
				$id       = $node['id'];
				$box      = $s->layout_nodes_by_id[ $id ] ?? array();
				$controls = ( $s->collect_controls )( $node );
				$variants = $s->variants_by_node[ $id ] ?? array();
				if ( 'wrapper' !== ( $node['kind'] ?? null )
					|| 1 !== count( $controls ) || ! isset( $s->field_blocks[ $controls[0] ] )
					|| 'core/button' === $s->field_blocks[ $controls[0] ]['name']
					|| ! self::has_unconditional_proven_property( $box, 'width' )
				) {
					continue 2;
				}
				$allowed = Static_Site_Importer_Provider_Layout_Overlay::box_property_map() + array_flip( array( 'width' ) );
				if ( array_diff_key( $s->layout_by_node[ $id ] ?? array(), $allowed ) ) {
					continue 2;
				}
				foreach ( $variants as $variant ) {
					$condition = $variant['condition'] ?? array();
					$patch     = self::layout_patch( $variant );
					$query     = (string) ( $condition['query'] ?? '' );
					if ( 'media' !== ( $condition['kind'] ?? null )
						|| ( ! self::is_min_width_media_query( $query ) && ! self::is_max_width_media_query( $query ) )
						|| array_diff_key( $patch, $allowed ) ) {
						continue 3;
					}
					foreach ( $patch as $fact => $value ) {
						if ( ! self::variant_proven( $variant, str_replace( '_', '-', $fact ) ) ) {
							continue 4;
						}
					}
					$conditions[ $query ] = $condition;
				}
				$cells[] = array(
					'id'      => $id,
					'control' => $controls[0],
				);
			}
			// Only disjoint queries are independently resolved here. Overlapping
			// queries require a combined cascade proof, so leave those untouched.
			$ranges = array();
			foreach ( $conditions as $query => $condition ) {
				preg_match( '/\(\s*(min|max)-width\s*:\s*([0-9]+(?:\.[0-9]+)?)px\s*\)/i', $query, $bound );
				if ( empty( $bound ) || isset( $ranges[ $bound[1] ] ) ) {
					continue 2;
				}
				$ranges[ $bound[1] ] = (float) $bound[2];
			}
			if ( isset( $ranges['min'], $ranges['max'] ) && $ranges['min'] <= $ranges['max'] ) {
				continue;
			}
			// Validate every resolved condition before mutating the projection.
			$rows = array();
			foreach ( array_merge( array( null ), array_values( $conditions ) ) as $condition ) {
				$layouts = array();
				foreach ( $cells as $cell ) {
					$id  = $cell['id'];
					$box = $s->layout_nodes_by_id[ $id ];
					// Class-carried margins still belong in the source row's budget,
					// even when wrapper planning left them on the replayed layer.
					$layout = $box['layout'];
					foreach ( array( 'margin_left', 'margin_right' ) as $fact ) {
						if ( isset( $layout[ $fact ] ) && ! self::has_unconditional_proven_property( $box, str_replace( '_', '-', $fact ) ) ) {
							continue 4;
						}
					}
					foreach ( $s->variants_by_node[ $id ] ?? array() as $variant ) {
						if ( null !== $condition && ( $variant['condition'] ?? null ) === $condition ) {
							$layout = array_replace( $layout, self::layout_patch( $variant ) );
						}
					}
					$layouts[] = $layout;
				}
				$gutter = self::percentage_pair_gutter( $layouts );
				if ( null === $gutter ) {
					continue 2;
				}
				$rows[] = array(
					'condition' => $condition,
					'gutter'    => $gutter,
					'stacked'   => '100%' === $layouts[0]['width'] && '100%' === $layouts[1]['width'],
				);
			}
			// The authored margin supplies the gutter; a provider/theme column
			// gap on top of it would force the second half onto another line.
			$s->overlay_node_targets[] = array(
				'id'     => 'form',
				'layout' => array(
					'column_gap' => '0',
					'row_gap'    => '0',
				),
			);

			foreach ( $cells as $offset => $cell ) {
				$s->field_blocks[ $cell['control'] ]['attrs']['width'] = 50;
				foreach ( $rows as $row ) {
					$track  = $row['stacked'] ? '100%' : self::equal_fraction_track_size( 2, $row['gutter'] );
					$layout = array(
						'width'               => $track,
						'max_width'           => '100%',
						'flex_basis'          => $track,
						'flex_grow'           => '0',
						'flex_shrink'         => '0',
						'margin_inline_start' => '0',
						'margin_right'        => 0 === $offset && ! $row['stacked'] ? $row['gutter'] : '0',
					);
					if ( null === $row['condition'] ) {
						$s->overlay_node_targets[] = array(
							'id'        => 'field-' . $cell['control'],
							'layout'    => $layout,
							'important' => array( 'margin_inline_start' ),
						);
					} else {
						$s->responsive_variant_targets[] = array(
							'node'         => 'field-' . $cell['control'],
							'condition'    => $row['condition'],
							'layout_patch' => $layout,
							'important'    => array( 'margin_inline_start' ),
						);
					}
				}
				foreach ( $conditions as $condition ) {
					$s->responsive_variant_targets[] = array(
						'node'         => $cell['id'],
						'condition'    => $condition,
						'layout_patch' => array(
							'float'        => 'none',
							'width'        => '100%',
							'margin_left'  => '0',
							'margin_right' => '0',
						),
						'important'    => array( 'float', 'width', 'margin_left' ),
					);
				}
				// Give the restored inner cell its own target, distinct from the
				// provider shell whose width now expresses the row relationship.
				$inner_hook = self::layout_node_class( self::layout_scope( $s->form ), $cell['id'] . '-inner' );
				$depth      = (int) $s->topology_nodes_by_id[ $cell['id'] ]['depth'];
				$s->field_blocks[ $cell['control'] ]['attrs']['className'] .= ' ssi-source-wrapper-' . $depth . '--' . $inner_hook;
				$s->wrapper_hooks[ $cell['id'] ]                            = $inner_hook;
				$s->provider_layout_targets[ $cell['id'] ]                  = $inner_hook;
				// The source cell is rebuilt inside the provider shell. Its old
				// percentage width and float margin must not size the control again.
				// Force these resets even when the source half-class selector has
				// higher specificity or an authored important mobile width.
				$s->overlay_node_targets[] = array(
					'id'        => $cell['id'],
					'layout'    => array(
						'float'        => 'none',
						'width'        => '100%',
						'margin_left'  => '0',
						'margin_right' => '0',
					),
					'important' => array( 'float', 'width', 'margin_left' ),
				);
			}
			$s->percentage_width_parents[ $parent ] = true;
			$s->record( 'layout', 'provider_compensated_percentage_halves', $parent );
		}
	}

	/**
	 * Prove a two-cell percentage budget; return its pixel gutter or decline.
	 * Percent totals below 100 are safe at any row width, provided the fixed
	 * gutter is paid for by the subtractions. Full-width pairs instead stack.
	 *
	 * @param array $layouts Resolved sibling cell layouts.
	 */
	private static function percentage_pair_gutter( array $layouts ): ?string {
		$percent = 0.0;
		$budget  = 0.0;
		$gutter  = 0.0;
		foreach ( $layouts as $offset => $layout ) {
			$width = trim( (string) ( $layout['width'] ?? '' ) );
			if ( 1 === preg_match( '/^([0-9]+(?:\.[0-9]+)?)%$/D', $width, $match ) ) {
				$percent += (float) $match[1];
			} elseif ( 1 === preg_match( '/^calc\(\s*([0-9]+(?:\.[0-9]+)?)%\s*-\s*([0-9]+(?:\.[0-9]+)?)px\s*\)$/D', $width, $match ) ) {
				$percent += (float) $match[1];
				$budget  += (float) $match[2];
			} else {
				return null;
			}
			if ( 0.0 >= (float) $match[1] || 100.0 < (float) $match[1] ) {
				return null;
			}
			foreach ( array( 'margin_left', 'margin_right' ) as $fact ) {
				$value = trim( (string) ( $layout[ $fact ] ?? '0' ) );
				if ( '0' !== $value && 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)px$/D', $value ) ) {
					return null;
				}
				if ( ( 'margin_left' === $fact && 1 === $offset ) || ( 'margin_right' === $fact && 0 === $offset ) ) {
					$gutter += (float) $value;
				} elseif ( 0.0 !== (float) $value ) {
					return null;
				}
			}
		}
		if ( '100%' === $layouts[0]['width'] && '100%' === $layouts[1]['width'] ) {
			return 0.0 === $gutter ? '0px' : null;
		}
		return 100.0 >= $percent && $gutter <= $budget ? $gutter . 'px' : null;
	}

	/** The `G` of a `width: calc(100% + G)` row overhang, or null. */
	private static function gutter_overhang( array $layout ): ?string {
		$width = is_string( $layout['width'] ?? null ) ? trim( $layout['width'] ) : '';
		return 1 === preg_match( '/^calc\(\s*100%\s*\+\s*([0-9]+(?:\.[0-9]+)?(?:px|rem|em))\s*\)$/iD', $width, $match ) ? strtolower( $match[1] ) : null;
	}

	/** Whether a column gutter and a row overhang name the same gutter (pixels within 1px). */
	private static function gutter_matches_overhang( string $gutter, string $overhang ): bool {
		if ( 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)(px|rem|em)$/iD', strtolower( $gutter ), $g ) || 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)(px|rem|em)$/D', $overhang, $o ) || $g[2] !== $o[2] ) {
			return false;
		}
		return 'px' === $g[2] ? 1.0 >= abs( (float) $g[1] - (float) $o[1] ) : 0.0001 > abs( (float) $g[1] - (float) $o[1] );
	}

	/**
	 * Project equal-fraction sibling rows onto provider field widths.
	 *
	 * Flattening resets every field's and the submit's block-start margin. When
	 * that replaces source sibling-stacking margins, the form carries the source
	 * stacking margin as its row gap and the row's column gap as its column gap,
	 * because the provider form has no block gap of its own (#2159).
	 */
	private static function project_sibling_rows( Static_Site_Importer_Form_Topology_State $s ): void {
		foreach ( $s->children as $parent => $siblings ) {
			if ( '$root' === $parent || isset( $s->percentage_width_parents[ $parent ] ) || count( $siblings ) < 2 ) {
				continue;
			}
			$layout = $s->layout_by_node[ $parent ] ?? array();
			if ( array_intersect( array_keys( $layout ), array( 'column', 'row', 'area' ) ) ) {
				continue;
			}
			$parent_variants = $s->variants_by_node[ $parent ] ?? array();
			$columns         = preg_replace( '/\s+/', '', (string) ( $layout['columns'] ?? '' ) );
			$class_tokens    = self::class_tokens( $s->topology_nodes_by_id[ $parent ] ?? null );
			$display         = $layout['display'] ?? null;
			if ( 'grid' !== $display ) {
				$from_class = self::display_from_class_tokens( $class_tokens );
				if ( 'grid' === $from_class ) {
					$display = 'grid';
				}
			}
			// Column count comes from the resolved track list, not from how many
			// field boxes occupy those tracks. A 2-column grid of eight fields is
			// four visual rows of Jetpack `width: 50`, not an 8-column row.
			$column_count        = self::equal_fraction_column_count( $columns );
			$class_token_columns = false;
			$widening_query      = null;
			if ( null === $column_count && '' === $columns && in_array( 'grid', $class_tokens, true ) ) {
				// Resolved tracks are absent (layered utility CSS often never
				// becomes a layout-graph node). A field-row grid of single-field
				// siblings is the 2-column pairing Jetpack `width: 50` represents;
				// 3- and 4-column rows still require a resolved track list.
				$column_count        = 2;
				$class_token_columns = true;
			}
			$equal_grid      = empty( $parent_variants ) && 'grid' === $display && null !== $column_count;
			$narrowing_query = null;
			// The desktop-first form of the same row: equal columns by default,
			// collapsed to one track below a proven max-width breakpoint. That is
			// the provider's equal-width row with its stack scoped to the source's
			// own query, so it maps the same way the min-width widening does.
			if ( ! $equal_grid && 'grid' === $display && null !== $column_count && 1 === count( $parent_variants ) ) {
				$variant   = $parent_variants[0];
				$condition = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
				$patch     = self::layout_patch( $variant );
				if ( array( 'columns' ) === array_keys( $patch )
					&& self::is_single_fraction_track( preg_replace( '/\s+/', '', (string) ( $patch['columns'] ?? '' ) ) )
					&& is_array( $condition ) && 'media' === ( $condition['kind'] ?? null )
					&& is_string( $condition['query'] ?? null )
					&& self::is_max_width_media_query( $condition['query'] )
					&& self::has_source_fact( $variant['provenance'] ?? array(), $condition, array( 'grid-template-columns' ) )
				) {
					$equal_grid      = true;
					$narrowing_query = $condition['query'];
				}
			}
			// A mobile-first source stacks these boxes by default and only bands
			// them into equal columns at a wider breakpoint (Tailwind's own
			// `grid-cols-1 md:grid-cols-2` or `grid sm:grid-cols-2` shape). Jetpack
			// field width is a single, non-responsive value, so accept exactly one
			// proven min-width widening of the track count and materialize that
			// widened state instead; any other variant shape (more than one variant,
			// a narrowing, a max-width query, or a patch touching more than the
			// column tracks) keeps the existing wrapper-layout decline.
			if ( ! $equal_grid && 'grid' === $display && 1 === count( $parent_variants ) ) {
				$variant       = $parent_variants[0];
				$condition     = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
				$patch         = self::layout_patch( $variant );
				$widened       = preg_replace( '/\s+/', '', (string) ( $patch['columns'] ?? '' ) );
				$widened_count = self::equal_fraction_column_count( $widened );
				if ( array( 'columns' ) === array_keys( $patch )
					&& self::min_width_query( $condition )
					&& null !== $widened_count
				) {
					if ( self::has_source_fact( $variant['provenance'] ?? array(), $condition, array( 'grid-template-columns' ) ) ) {
						$equal_grid     = true;
						$column_count   = $widened_count;
						$widening_query = $condition['query'];
					}
					if ( ! $equal_grid && in_array( 'grid', self::class_tokens( $s->topology_nodes_by_id[ $parent ] ?? null ), true ) ) {
						$equal_grid     = true;
						$column_count   = $widened_count;
						$widening_query = $condition['query'];
					}
				}
			}
			if ( ! $equal_grid || null === $column_count ) {
				continue;
			}
			$indexes = array();
			foreach ( $siblings as $sibling ) {
				$branch_controls = ( $s->collect_controls )( $sibling );
				if ( 1 !== count( $branch_controls ) || ! isset( $s->field_blocks[ $branch_controls[0] ] ) || 'core/button' === ( $s->field_blocks[ $branch_controls[0] ]['name'] ?? '' ) ) {
					$indexes = array();
					break;
				}
				$indexes[] = $branch_controls[0];
			}
			if ( empty( $indexes ) ) {
				continue;
			}
			$non_submit_indexes = array_values(
				array_filter(
					array_keys( $s->field_blocks ),
					static fn ( int $index ): bool => 'core/button' !== ( $s->field_blocks[ $index ]['name'] ?? '' )
				)
			);
			$paired_indexes     = $indexes;
			sort( $non_submit_indexes );
			sort( $paired_indexes );
			if ( $paired_indexes === $non_submit_indexes ) {
				continue;
			}
			$source_gaps = self::source_gaps( $layout );
			if ( null === $source_gaps ) {
				continue;
			}
			$width       = self::provider_field_width( 1 / $column_count );
			$gap         = $source_gaps['column'] ?? '1.5rem';
			$track       = self::equal_fraction_track_size( $column_count, $gap );
			$paired      = array_fill_keys( $indexes, true );
			$stack_query = is_string( $widening_query ) ? self::inverted_min_width_media_query( $widening_query ) : $narrowing_query;
			if ( null === $stack_query && $class_token_columns ) {
				$stack_query = self::equal_width_stack_query_from_cascade_facts( $parent, $s->variants_by_node, $s->layout_nodes_by_id );
				if ( null === $stack_query ) {
					// Layered source utilities never become graph variants, so the
					// widening query is missing. The 50% overlay is more specific than
					// Jetpack's own `@media (max-width: 480px)` wrap stack and would
					// otherwise keep the row two-up at every width.
					$stack_query = '(max-width: 480px)';
				}
			}
			foreach ( $indexes as $control_index ) {
				$s->field_blocks[ $control_index ]['attrs']['width'] = $width;
				$s->overlay_node_targets[]                           = array(
					'id'        => 'field-' . $control_index,
					'layout'    => array(
						'width'              => $track,
						'flex_grow'          => '0',
						'flex_shrink'        => '0',
						'flex_basis'         => $track,
						'margin_block_start' => '0',
					),
					// A source sibling-stacking utility (e.g. Tailwind `space-y-*`) is
					// authored against the ORIGINAL sibling relationships and is carried
					// onto the provider form unscoped. Once flattening makes these two
					// fields adjacent provider siblings, that rule can still match them
					// with a selector more specific than this reset, since the reset's
					// only leverage over an unbounded source selector is the cascade
					// origin, not specificity. Forcing the reset wins regardless of the
					// source rule's specificity, which is what a value of literal `0` on
					// this synthetic flattening seam is always for. The vertical rhythm
					// that margin carried moves to the form's row gap (below).
					'important' => array( 'margin_block_start' ),
				);
				if ( is_string( $stack_query ) ) {
					$s->responsive_variant_targets[] = array(
						'node'         => 'field-' . $control_index,
						'condition'    => array(
							'kind'  => 'media',
							'query' => $stack_query,
						),
						'layout_patch' => array(
							'flex'  => '1 1 100%',
							'width' => '100%',
						),
					);
				}
			}
			$s->represented_layout_nodes[] = $parent;
			$s->record( 'layout', 'provider_equal_width_fields', $parent, array( 'width' => $width ) );
			foreach ( $s->field_blocks as $control_index => $field_block ) {
				if ( isset( $paired[ $control_index ] ) ) {
					continue;
				}
				// A submit control is never routed through Jetpack's grunion field
				// renderer, so it never receives the `-wrap` class suffix the `field-N`
				// id resolves through; address its own generated node hook instead.
				// It still sits as a flex item beside the flattened fields in the same
				// `space-y-*`-classed container, so it needs the identical reset: the
				// container's own flex `gap` reproduces the source spacing (see the
				// row-gap rule below), and the carried sibling-margin would otherwise
				// double it.
				$s->overlay_node_targets[] = array(
					'id'        => ( 'core/button' === ( $field_block['name'] ?? '' ) ? 'control-' : 'field-' ) . $control_index,
					'layout'    => array(
						'margin_block_start' => '0',
					),
					'important' => array( 'margin_block_start' ),
				);
			}
			// The resets above only hold if the provider form's own flex gap really
			// reproduces the source stacking. Jetpack's form is a wrapping flex row
			// with no block gap of its own, and a gap the producer lifted onto the
			// form (if any) is the flattened row's column gap, not the source's
			// vertical rhythm. So whenever source sibling-stacking margins are
			// replaced, the form carries that stacking margin as its row gap, and the
			// column gap the field tracks were sized against as its column gap, so
			// consecutive field groups and the submit keep the source spacing while
			// the flattened row's two-up math stays exact.
			$rhythm = self::source_stacking_rhythm( $s, array_keys( $s->field_blocks ) );
			if ( is_string( $rhythm ) ) {
				// Context blocks are flex items too. Reset only their outer box, not
				// nested copy whose internal margins were never replaced by this gap.
				foreach ( array( 'context_before', 'context_after' ) as $position ) {
					foreach ( Static_Site_Importer_Form_Field_Markup::context_blocks( $s->form, $position ) as $index => $block ) {
						if ( 1 !== preg_match( '/\bssi-context-[a-f0-9]{12}\b/', (string) ( $block['attrs']['className'] ?? '' ), $match ) ) {
							continue;
						}
						$id                                = $position . '-' . $index;
						$s->provider_layout_targets[ $id ] = $match[0];
						$s->overlay_node_targets[]         = array(
							'id'        => $id,
							'layout'    => array( 'margin_block_start' => '0' ),
							'important' => array( 'margin_block_start' ),
						);
					}
				}
				$s->overlay_node_targets[] = array(
					'id'     => 'form',
					'layout' => array(
						'row_gap'    => $rhythm,
						'column_gap' => $gap,
					),
				);
			}
		}
	}

	/**
	 * The single source sibling-stacking margin the given controls' boxes carry.
	 *
	 * Looks at each control's topology node and its wrapper ancestors for an
	 * unconditional block-start margin authored through a sibling combinator
	 * (the stacking rule shape, never a class name). Returns null when none is
	 * found or the boxes disagree, so an ambiguous rhythm is never invented.
	 *
	 * @param array<int,int> $control_indexes
	 */
	private static function source_stacking_rhythm( Static_Site_Importer_Form_Topology_State $s, array $control_indexes ): ?string {
		$control_nodes = array();
		foreach ( $s->nodes as $node ) {
			if ( is_array( $node ) && 'control' === ( $node['kind'] ?? null ) && is_int( $node['control'] ?? null ) && is_string( $node['id'] ?? null ) ) {
				$control_nodes[ $node['control'] ] = $node['id'];
			}
		}
		$values = array();
		foreach ( $control_indexes as $control_index ) {
			$node_id = $control_nodes[ $control_index ] ?? null;
			$guard   = 0;
			while ( is_string( $node_id ) && '$root' !== $node_id && $guard++ < 32 ) {
				$layout     = $s->layout_by_node[ $node_id ] ?? array();
				$suppressed = $s->suppressed_layout_properties[ $node_id ] ?? array();
				if ( self::has_sibling_combinator_margin( $s->layout_nodes_by_id[ $node_id ]['provenance'] ?? array() ) ) {
					foreach ( array( 'margin_block_start', 'margin_top' ) as $fact ) {
						if ( in_array( $fact, $suppressed, true ) || ! is_string( $layout[ $fact ] ?? null ) ) {
							continue;
						}
						$length = self::stacking_margin_length( $layout[ $fact ] );
						if ( null === $length ) {
							return null;
						}
						if ( 1 !== preg_match( '/^0(?:\.0+)?(?:px|rem|em)?$/D', $length ) ) {
							$values[ $length ] = true;
						}
						break;
					}
				}
				$node_id = $s->topology_parents[ $node_id ] ?? null;
			}
		}
		return 1 === count( $values ) ? (string) array_key_first( $values ) : null;
	}

	/**
	 * A plain length from a resolved stacking margin.
	 *
	 * Includes Tailwind's reversible `calc(L * calc(1 - var(--tw-space-y-reverse)))`
	 * form, both unresolved and with the reverse flag already resolved to `0`
	 * (the shape the capture reports for `--tw-space-y-reverse:0`).
	 */
	private static function stacking_margin_length( string $margin ): ?string {
		$margin = trim( $margin );
		if ( 1 === preg_match( '/^calc\(\s*((?:0|[0-9]*\.?[0-9]+)(?:px|rem|em))\s*\*\s*calc\(\s*1\s*-\s*(?:var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}\)|0(?:\.0+)?)\s*\)\s*\)$/D', $margin, $match ) ) {
			return $match[1];
		}
		return self::resolved_gap_length( $margin );
	}

	/** Project repeat(N, 1fr) grid-span rows onto provider field widths. */
	private static function project_grid_span_rows( Static_Site_Importer_Form_Topology_State $s ): void {
		// A repeat(N, 1fr) row places each field with grid-column: A / span B.
		// Jetpack has no grid-column attribute; a span that is exactly 25 / 33 /
		// 50 / 75 / 100 becomes that field's width, and the row's column-gap is
		// the same track compensation an equal-fraction row already uses. A span
		// that does not land on one of those steps, a row that does not tile, or
		// any other wrapper fact stays unrepresented.
		foreach ( $s->children as $parent => $siblings ) {
			if ( 1 !== preg_match( self::WRAPPER_ID_PATTERN, $parent ) || count( $siblings ) < 2 || in_array( $parent, $s->represented_layout_nodes, true ) || isset( $s->percentage_width_parents[ $parent ] ) ) {
				continue;
			}
			$layout_node = $s->layout_nodes_by_id[ $parent ] ?? null;
			$layout      = is_array( $layout_node ) && is_array( $layout_node['layout'] ?? null ) ? $layout_node['layout'] : array();
			$gap_variant = null;
			$variants    = $s->variants_by_node[ $parent ] ?? array();
			if ( ! empty( $variants ) ) {
				if ( 1 !== count( $variants ) ) {
					continue;
				}
				$candidate = $variants[0];
				$patch     = self::layout_patch( $candidate );
				$condition = $candidate['condition'] ?? null;
				$proven    = false;
				foreach ( $candidate['provenance'] ?? array() as $fact ) {
					if ( is_array( $fact ) && ( $fact['condition'] ?? null ) === $condition && in_array( 'column-gap', $fact['properties'] ?? array(), true ) && is_string( $fact['source_path'] ?? null ) && is_string( $fact['selector'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) ( $fact['source_sha256'] ?? '' ) ) ) {
						$proven = true;
						break;
					}
				}
				if ( array( 'column_gap' ) !== array_keys( $patch ) || ! self::min_width_query( $condition ) || ! $proven || null === self::resolved_gap_length( (string) $patch['column_gap'] ) ) {
					continue;
				}
				$gap_variant = $candidate;
			}
			$columns = self::equal_track_count( is_string( $layout['columns'] ?? null ) ? $layout['columns'] : '' );
			if ( ! is_array( $layout_node ) || null === $columns || array_diff( array_keys( $layout ), array( 'display', 'columns', 'width', 'row_gap', 'column_gap', 'gap' ) ) || 'grid' !== ( $layout['display'] ?? null ) || ( isset( $layout['width'] ) && '100%' !== $layout['width'] ) || ! self::has_unconditional_proven_property( $layout_node, 'display' ) || ! self::has_unconditional_proven_property( $layout_node, 'grid-template-columns' ) || ( isset( $layout['width'] ) && ! self::has_unconditional_proven_property( $layout_node, 'width' ) ) ) {
				continue;
			}
			// Placement states no row rhythm of its own, so a row gap must match the
			// column gap the tracks are sized against.
			$source_gaps = self::source_gaps( $layout );
			if ( null === $source_gaps || ( null !== $source_gaps['row'] && $source_gaps['row'] !== $source_gaps['column'] ) ) {
				continue;
			}
			if ( ( isset( $layout['row_gap'] ) && ! self::has_unconditional_proven_property( $layout_node, 'row-gap' ) ) || ( isset( $layout['column_gap'] ) && ! self::has_unconditional_proven_property( $layout_node, 'column-gap' ) ) || ( isset( $layout['gap'] ) && ! self::has_unconditional_proven_property( $layout_node, 'gap' ) ) ) {
				continue;
			}
			$source_gap = $source_gaps['column'];
			$gap        = null === $source_gap ? ( null === $gap_variant ? '1.5rem' : '0px' ) : self::resolved_gap_length( $source_gap );
			if ( ! is_string( $gap ) ) {
				continue;
			}
			$placements        = array();
			$submit_placements = array();
			$accepted          = true;
			foreach ( $siblings as $sibling ) {
				$sibling_id = $sibling['id'];
				$branch     = '' !== $sibling_id ? $s->mapped_branch( $sibling ) : array();
				$item_node  = $s->layout_nodes_by_id[ $sibling_id ] ?? null;
				$item       = is_array( $item_node ) && is_array( $item_node['layout'] ?? null ) ? $item_node['layout'] : array();
				$is_submit  = 1 === count( $branch ) && 'core/button' === ( $s->field_blocks[ $branch[0] ]['name'] ?? '' );
				if ( '' === $sibling_id || 1 !== count( $branch ) || ! empty( $s->variants_by_node[ $sibling_id ] ) || isset( $item['column'], $item['area'] ) || ( ! isset( $item['column'] ) && ! isset( $item['area'] ) ) ) {
					$accepted = false;
					break;
				}
				if ( isset( $item['column'] ) ) {
					$parsed = self::grid_column_placement( $layout['columns'], $item['column'] );
					if ( null === $parsed || $parsed['columns'] !== $columns || ! self::has_unconditional_proven_property( $item_node, 'grid-column' ) ) {
						$accepted = false;
						break;
					}
					$start = $parsed['start'];
					$span  = $parsed['span'];
					$row   = null;
				} else {
					$parsed = self::grid_area_placement( $item['area'] );
					if ( null === $parsed || $parsed['span'] > $columns || ( null !== $parsed['start'] && $parsed['start'] + $parsed['span'] - 1 > $columns ) || ! self::has_unconditional_proven_property( $item_node, 'grid-area' ) ) {
						$accepted = false;
						break;
					}
					$start = $parsed['start'];
					$span  = $parsed['span'];
					$row   = $parsed['row'];
				}
				if ( isset( $item['row'] ) ) {
					$row_start = self::grid_line_start( $item['row'] );
					if ( null === $row_start || ( null !== $row && $row !== $row_start ) || ! self::has_unconditional_proven_property( $item_node, 'grid-row' ) ) {
						$accepted = false;
						break;
					}
					$row = $row_start;
				}
				if ( $is_submit ) {
					if ( null === self::clean_provider_field_width( $span / $columns ) ) {
						$accepted = false;
						break;
					}
					$submit_placements[] = array(
						'control' => $branch[0],
						'node'    => $sibling_id,
						'span'    => $span,
						'layout'  => $item,
					);
					continue;
				}
				$placements[] = array(
					'control' => $branch[0],
					'node'    => $sibling_id,
					'start'   => $start,
					'span'    => $span,
					'row'     => $row,
					'layout'  => $item,
				);
			}
			$tiled = $accepted ? self::tiled_grid_span_placements( $placements, $columns ) : null;
			if ( null === $tiled ) {
				continue;
			}
			$widths = array();
			foreach ( $tiled as $placement ) {
				$width = self::clean_provider_field_width( $placement['span'] / $columns );
				if ( null === $width ) {
					$widths = array();
					break;
				}
				$widths[] = $width;
			}
			if ( count( $widths ) !== count( $tiled ) ) {
				continue;
			}
			foreach ( $tiled as $offset => $placement ) {
				$share = $placement['span'] / $columns;
				$track = self::fractional_track_size( $share, $gap );
				$s->field_blocks[ $placement['control'] ]['attrs']['width'] = $widths[ $offset ];
				$s->overlay_node_targets[]                                  = array(
					'id'        => 'field-' . $placement['control'],
					'layout'    => array(
						'width'              => $track,
						'flex_grow'          => '0',
						'flex_shrink'        => '0',
						'flex_basis'         => $track,
						'margin_block_start' => '0',
					),
					'important' => array( 'margin_block_start' ),
				);
				if ( null !== $gap_variant ) {
					$variant_gap                     = self::resolved_gap_length( (string) $gap_variant['layout_patch']['column_gap'] );
					$variant_track                   = self::fractional_track_size( $share, $variant_gap );
					$s->responsive_variant_targets[] = array(
						'node'         => 'field-' . $placement['control'],
						'condition'    => $gap_variant['condition'],
						'layout_patch' => array(
							'width'      => $variant_track,
							'flex_basis' => $variant_track,
						),
					);
				}
				if ( ! array_diff( array_keys( $placement['layout'] ), array( 'column', 'row', 'area' ) ) ) {
					$s->overlay_represented_nodes[] = $placement['node'];
				}
			}
			foreach ( $submit_placements as $placement ) {
				$share                          = $placement['span'] / $columns;
				$track                          = self::fractional_track_size( $share, $gap );
				$s->overlay_node_targets[]      = array(
					'id'        => 'control-' . $placement['control'],
					'layout'    => array(
						'width'              => $track,
						'flex_grow'          => '0',
						'flex_shrink'        => '0',
						'flex_basis'         => $track,
						'margin_block_start' => '0',
					),
					'important' => array( 'margin_block_start' ),
				);
				$s->grid_span_submit_controls[] = $placement['control'];
				if ( ! array_diff( array_keys( $placement['layout'] ), array( 'column', 'row', 'area' ) ) ) {
					$s->overlay_represented_nodes[] = $placement['node'];
				}
			}
			$s->grid_span_active = true;
			if ( null !== $gap_variant ) {
				$s->grid_span_gap_variants[ (string) wp_json_encode( $gap_variant['condition'] ) ] = array(
					'condition' => $gap_variant['condition'],
					'gap'       => self::resolved_gap_length( (string) $gap_variant['layout_patch']['column_gap'] ),
				);
			}
			if ( null === $s->grid_span_gap ) {
				$s->grid_span_gap = is_string( $source_gap ) && Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( array( 'gap' => $source_gap ) ) ? $source_gap : $gap;
			}
			$s->represented_layout_nodes[] = $parent;
			$s->record( 'layout', 'provider_grid_span_fields', $parent, array( 'field_count' => count( $tiled ) ) );
		}
	}

	/** Project proven flex rows and their single-field cells onto the provider field list. */
	private static function project_flex_cell_rows( Static_Site_Importer_Form_Topology_State $s ): void {
		$projected = false;
		foreach ( $s->children as $parent => $siblings ) {
			$node   = $s->layout_nodes_by_id[ $parent ] ?? null;
			$layout = is_array( $node ) && is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			if ( ! preg_match( self::WRAPPER_ID_PATTERN, $parent ) || 'flex' !== ( $layout['display'] ?? null ) || 'row' !== ( $layout['direction'] ?? 'row' ) || count( $siblings ) < 2 || ! self::has_unconditional_proven_property( $node, 'display' ) ) {
				continue;
			}
			$parent_layout = array();
			foreach ( $layout as $property => $value ) {
				$css_property = Static_Site_Importer_Provider_Layout_Overlay::layout_property_map()[ $property ] ?? null;
				if ( is_string( $css_property ) && self::has_unconditional_proven_property( $node, $css_property ) ) {
					$parent_layout[ $property ] = $value;
				}
			}
			$cells = array();
			foreach ( $siblings as $cell ) {
				$branch = $s->mapped_branch( $cell );
				$id     = is_string( $cell['id'] ?? null ) ? $cell['id'] : '';
				$box    = $s->layout_nodes_by_id[ $id ] ?? null;
				if ( 'wrapper' !== ( $cell['kind'] ?? null ) || '' === $id || 1 !== count( $branch ) || 'core/button' === ( $s->field_blocks[ $branch[0] ]['name'] ?? '' ) || ! is_array( $box ) ) {
					$cells = array();
					break;
				}
				$cell_layout = is_array( $box['layout'] ?? null ) ? $box['layout'] : array();
				if ( ! self::layout_facts_proven( $box['provenance'] ?? array(), null, $cell_layout ) ) {
					$cells = array();
					break;
				}
				$cell_variants = $s->variants_by_node[ $id ] ?? array();
				foreach ( $cell_variants as $variant ) {
					if ( ! self::variant_patch_proven( $variant ) ) {
						$cells = array();
						break 2;
					}
				}
				$cells[] = array(
					'id'       => $id,
					'control'  => $branch[0],
					'layout'   => $cell_layout,
					'variants' => $cell_variants,
				);
			}
			if ( count( $cells ) !== count( $siblings ) ) {
				continue;
			}
			foreach ( $cells as $cell ) {
				$target_id = 'field-' . $cell['control'];
				if ( ! empty( $cell['layout'] ) ) {
					$s->overlay_node_targets[] = array(
						'id'     => $target_id,
						'layout' => $cell['layout'],
					);
				}
				foreach ( $cell['variants'] as $variant ) {
					$s->responsive_variant_targets[] = array(
						'node'         => $target_id,
						'condition'    => $variant['condition'],
						'layout_patch' => self::layout_patch( $variant ),
					);
				}
				$s->overlay_represented_nodes[] = $cell['id'];
			}
			$s->form_classes[]              = 'ssi-source-field-list';
			$field_list_layout              = $parent_layout + array( 'wrap' => 'wrap' );
			$field_list_layout['display']   = 'flex';
			$field_list_layout['direction'] = 'row';
			$s->overlay_node_targets[]      = array(
				'id'     => 'field-list',
				'layout' => $field_list_layout,
			);
			foreach ( $s->variants_by_node[ $parent ] ?? array() as $variant ) {
				if ( ! self::variant_patch_proven( $variant ) ) {
					continue;
				}
				$s->responsive_variant_targets[] = array(
					'node'         => 'field-list',
					'condition'    => $variant['condition'],
					'layout_patch' => self::layout_patch( $variant ),
				);
			}
			$s->overlay_represented_nodes[] = $parent;
			$projected                      = true;
			$s->record( 'layout', 'provider_flex_cell_row_transposition', $parent, array( 'field_count' => count( $cells ) ) );
		}
		if ( $projected ) {
			$s->suppressed_layout_properties['form'] = array_values( array_unique( array_merge( $s->suppressed_layout_properties['form'] ?? array(), array( 'display', 'direction', 'wrap' ) ) ) );
		}
	}

	/**
	 * Recover the control tree when the producer emitted layout nodes but no
	 * separate topology. The layout graph is sufficient only when every visible
	 * control and its ancestor chain survived capture; otherwise retain the
	 * existing fail-closed flat projection.
	 *
	 * @param array<string,mixed>              $form
	 * @param array<int,array<string,mixed>>   $controls
	 * @return array<string,mixed>|null
	 */
	private static function derive_control_topology_from_layout_graph( array $form, array $controls ): ?array {
		$graph = $form['layout_graph'] ?? null;
		if ( ! is_array( $graph ) || ! is_array( $graph['nodes'] ?? null ) ) {
			return null;
		}

		$source_nodes = array();
		foreach ( $graph['nodes'] as $node ) {
			// Copy-only `context-N` boxes belong to in-form context materialization,
			// not to the control topology a provider field shell is rebuilt from.
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || str_starts_with( $node['id'], 'context-' ) ) {
				continue;
			}
			$source_nodes[ $node['id'] ] = $node;
		}
		if ( empty( $source_nodes ) ) {
			return null;
		}

		$nodes       = array();
		$control_ids = array();
		foreach ( $source_nodes as $id => $node ) {
			$parent = is_string( $node['parent'] ?? null ) ? $node['parent'] : null;
			if ( null !== $parent && ! isset( $source_nodes[ $parent ] ) ) {
				continue;
			}
			$source        = is_array( $node['source'] ?? null ) ? $node['source'] : array();
			$control_match = array();
			if ( 1 === preg_match( '/^control-([0-9]+)$/D', $id, $control_match ) ) {
				$control_index                 = (int) $control_match[1];
				$control_ids[ $control_index ] = true;
				$nodes[]                       = array(
					'id'      => $id,
					'kind'    => 'control',
					'parent'  => $parent,
					'order'   => (int) ( $node['order'] ?? 0 ),
					'depth'   => (int) ( $node['depth'] ?? 0 ),
					'control' => $control_index,
				);
				continue;
			}
			$classes = is_array( $source['classes'] ?? null ) ? $source['classes'] : array();
			$nodes[] = array(
				'id'     => $id,
				'kind'   => 'wrapper',
				'parent' => $parent,
				'order'  => (int) ( $node['order'] ?? 0 ),
				'depth'  => (int) ( $node['depth'] ?? 0 ),
				'tag'    => is_string( $source['tag'] ?? null ) ? strtolower( $source['tag'] ) : 'div',
				'class'  => implode( ' ', array_filter( array_map( 'strval', $classes ) ) ),
			);
		}
		foreach ( $controls as $index => $control ) {
			$type = self::control_type( $control );
			if ( ! isset( $control_ids[ $index ] ) && 'hidden' !== $type ) {
				return null;
			}
		}
		if ( empty( $nodes ) ) {
			return null;
		}
		usort(
			$nodes,
			static function ( array $left, array $right ): int {
				$depth = (int) $left['depth'] <=> (int) $right['depth'];

				return 0 !== $depth ? $depth : ( (int) $left['order'] <=> (int) $right['order'] );
			}
		);

		return array(
			'schema'    => 'generic/form-control-topology/v1',
			'max_depth' => (int) ( $graph['limits']['depth'] ?? 16 ),
			'max_nodes' => (int) ( $graph['limits']['nodes'] ?? 128 ),
			'truncated' => ! empty( $graph['truncated'] ),
			'nodes'     => $nodes,
		);
	}

	/**
	 * Jetpack retains an empty error element in every field. It remains a flex
	 * item, so a source-owned field gap is applied to it unless it is removed
	 * while inactive. The runtime adds `has-errors` when validation needs it.
	 *
	 * The runtime also rebuilds a proven source label/control row as the field
	 * shell's own sole child (`.ssi-field-row`, see
	 * Static_Site_Importer_Provider_Form_Runtime::project_wrapper_classes()).
	 * When the shell itself resolves to `display: grid` from captured source
	 * facts (a mobile-only track layout is one such source), an item that is
	 * not explicitly placed on the grid's column axis auto-places into a
	 * single implicit track instead of spanning the shell's own tracks, so
	 * both the row and every control nested inside it collapse to that one
	 * track's width. `grid-column` has no effect on a non-grid-item element,
	 * so this is scoped to only the forms that proved a field shell's own
	 * `display: grid` (detected from the shell's already-compiled `-wrap`
	 * rule), leaving every flex- or block-shell form's compiled CSS, and the
	 * existing tests that assert its exact shape, unchanged.
	 *
	 * @param array<string,mixed> $overlay Compiled provider overlay.
	 * @param string              $scope Provider form scope.
	 * @param array<int,string>  $mapped_types Materialized field types by source index.
	 * @return array<string,mixed>
	 */
	public static function collapse_inactive_provider_errors( array $overlay, string $scope, array $mapped_types ): array {
		if ( ! isset( $overlay['overlay'] ) || ! is_array( $overlay['overlay'] ) || ! is_string( $overlay['css'] ?? null ) || ! preg_match( '/^ssi-form-[a-f0-9]{12}$/D', $scope ) ) {
			return $overlay;
		}

		if ( empty( $mapped_types ) ) {
			return $overlay;
		}
		// Below 480px Jetpack sets `flex-basis: 100%` on every button wrapper in
		// the form, stretching the submit across the row. The source button's
		// width is already carried on that wrapper (an authored `width: 100%`,
		// or none for a content-sized button), so the provider basis is
		// released by a reset weaker than a captured flex fact on the submit
		// node, which keeps an authored basis in charge.
		$field_row_span = 1 === preg_match( '/\.ssi-node-[a-f0-9]{12}-wrap\{[^}]*\bdisplay:grid\b/', $overlay['css'] )
			? '.' . $scope . ' .grunion-field-wrap > .ssi-field-row{grid-column:1 / -1}' . "\n"
			: '';
		$css            = rtrim( $overlay['css'] ) . "\n." . $scope . ' .grunion-field-wrap .contact-form__input-error:not(.has-errors){display:none}' . "\n" . '.' . $scope . ' .grunion-field-wrap .contact-form__field-hints{display:contents}' . "\n" . '.' . $scope . ' .grunion-field-wrap .contact-form__field-format{display:none}' . "\n" . '.' . $scope . ' .ssi-field-row > label{margin-block-end:0}' . "\n" . '.' . $scope . ' .grunion-field-wrap .grunion-field::placeholder{color:revert}' . "\n" . '.' . $scope . ' div.form-button-submit{flex-basis:auto}' . "\n" . $field_row_span;
		// A restored native select appearance owns its arrow too. Suppress only the
		// decoration inside that select's presentation hook, never provider-wide.
		$select_icons = array();
		if ( in_array( 'jetpack/field-select', $mapped_types, true ) && preg_match_all( '/\.' . preg_quote( $scope, '/' ) . '(?:\.' . preg_quote( $scope, '/' ) . ')? (\.ssi-node-[a-f0-9]{12}) select\{([^}]+)\}/', $overlay['css'], $select_rules, PREG_SET_ORDER ) ) {
			foreach ( $select_rules as $select_rule ) {
				if ( preg_match( '/(?:^|;)appearance:auto(?:!important)?(?:;|$)/', $select_rule[2] ) ) {
					$select_icons[ $select_rule[1] ] = '.' . $scope . ' ' . $select_rule[1] . ' .jetpack-field-dropdown__icon{display:none}' . "\n";
				}
			}
		}
		$css                         .= implode( '', $select_icons );
		$overlay['css']               = $css;
		$overlay['overlay']['css']    = $css;
		$overlay['overlay']['sha256'] = hash( 'sha256', $css );
		$overlay['overlay']['bytes']  = strlen( $css );

		return $overlay;
	}

	/**
	 * Source wrappers the binding replaces together with the form.
	 *
	 * The producer coalesces a form's exclusive ancestors and the form element
	 * into one layout-shell block, so replacing that block with the provider form
	 * also removes every ancestor box (a page-grid column, a flex row, their
	 * margins and padding). Each ancestor keeps its own element here: the shell
	 * is re-emitted around the provider block with the form element removed from
	 * its wrapper chain. Flattening ancestor classes onto the provider block
	 * instead stacks nested boxes onto one element, which the provider then
	 * renders twice (on its container and its inner block).
	 *
	 * @return array{shell:array{name:string,wrappers:array<int,array<string,mixed>>,open:string,close:string}|null,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>}
	 */
	public static function host_wrapper_projection( array $form ): array {
		$shells = array();
		foreach ( self::binding_markups( $form ) as $markup ) {
			$shells[] = self::host_shell( $markup );
		}
		$result = array(
			'shell'      => null,
			'operations' => array(),
			'losses'     => array(),
		);
		if ( array() === array_filter( $shells ) ) {
			return $result;
		}
		$first = $shells[0];
		foreach ( $shells as $shell ) {
			if ( null === $shell || $shell !== $first ) {
				// Occurrences disagree about the ancestor chain, so no single
				// replacement can restore it for every page.
				$result['losses'][] = array(
					'dimension'   => 'layout',
					'reason_code' => 'provider_host_wrapper_unrestored',
					'target_hash' => hash( 'sha256', (string) ( $form['selector'] ?? '' ) ),
				);
				return $result;
			}
		}
		$result['shell']        = $first;
		$result['operations'][] = array(
			'dimension'     => 'layout',
			'strategy'      => 'provider_host_wrapper_restoration',
			'target_hash'   => hash( 'sha256', $first['open'] ),
			'wrapper_count' => count( $first['wrappers'] ),
		);
		return $result;
	}

	/** @return array<int,string> */
	private static function binding_markups( array $form ): array {
		$markups    = array();
		$candidates = is_array( $form['bindings'] ?? null ) ? $form['bindings'] : array();
		if ( is_array( $form['binding'] ?? null ) ) {
			array_unshift( $candidates, $form['binding'] );
		}
		foreach ( $candidates as $binding ) {
			if ( is_array( $binding ) && is_string( $binding['search_block_markup'] ?? null ) && '' !== trim( $binding['search_block_markup'] ) ) {
				$markups[] = $binding['search_block_markup'];
			}
		}
		return $markups;
	}

	/**
	 * Split a layout-shell whose innermost wrapper is the form element into the
	 * ancestor wrappers' exact saved open and close markup. Null when the markup
	 * is not such a shell or has no ancestor to restore.
	 *
	 * @return array{name:string,wrappers:array<int,array<string,mixed>>,open:string,close:string}|null
	 */
	private static function host_shell( string $markup ): ?array {
		$markup = trim( $markup );
		if ( ! preg_match( '/^<!-- wp:((?:[a-z][a-z0-9-]*\/)?layout-shell) (\{.*?\}) -->/s', $markup, $header ) || ! str_ends_with( $markup, '<!-- /wp:' . $header[1] . ' -->' ) ) {
			return null;
		}
		$attrs    = json_decode( $header[2], true );
		$wrappers = is_array( $attrs ) && is_array( $attrs['wrappers'] ?? null ) && array_is_list( $attrs['wrappers'] ) ? $attrs['wrappers'] : array();
		$count    = count( $wrappers );
		if ( $count < 2 || ! is_array( $wrappers[ $count - 1 ] ) || 'form' !== strtolower( (string) ( $wrappers[ $count - 1 ]['tagName'] ?? '' ) ) ) {
			return null;
		}
		$tags = array();
		foreach ( $wrappers as $wrapper ) {
			$tag = is_array( $wrapper ) ? strtolower( (string) ( $wrapper['tagName'] ?? 'div' ) ) : '';
			if ( 1 !== preg_match( '/^[a-z][a-z0-9]*$/D', $tag ) ) {
				return null;
			}
			$tags[] = $tag;
		}
		// Saved shell content is each wrapper's opening tag in order, the inner
		// blocks, then the closing tags. Take the ancestors' tags verbatim so the
		// restored shell saves exactly as the block's own save() would.
		$body   = substr( $markup, strlen( $header[0] ), - strlen( '<!-- /wp:' . $header[1] . ' -->' ) );
		$offset = 0;
		$opens  = array();
		foreach ( $tags as $tag ) {
			if ( 1 !== preg_match( '/\G<' . $tag . '(?:\s[^<>]*)?>/', $body, $opening, 0, $offset ) ) {
				return null;
			}
			$opens[] = $opening[0];
			$offset += strlen( $opening[0] );
		}
		$closing = implode( '', array_map( static fn( string $tag ): string => '</' . $tag . '>', array_reverse( $tags ) ) );
		if ( ! str_ends_with( $body, $closing ) ) {
			return null;
		}
		$ancestors = array_slice( $tags, 0, -1 );
		return array(
			'name'     => $header[1],
			'wrappers' => array_slice( $wrappers, 0, -1 ),
			'open'     => implode( '', array_slice( $opens, 0, -1 ) ),
			'close'    => implode( '', array_map( static fn( string $tag ): string => '</' . $tag . '>', array_reverse( $ancestors ) ) ),
		);
	}

	/** Stable generated classes are provider hooks, never source presentation hooks. */
	public static function layout_scope( array $form ): string {
		$identity = $form['fallback_identity'] ?? '';
		if ( is_string( $identity ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $identity ) ) {
			return 'ssi-form-' . substr( $identity, 0, 12 );
		}
		return 'ssi-form-' . substr( hash( 'sha256', (string) ( $form['source_path'] ?? '' ) . "\n" . (string) ( $form['selector'] ?? '' ) ), 0, 12 );
	}

	public static function layout_node_class( string $scope, string $node ): string {
		return 'ssi-node-' . substr( hash( 'sha256', $scope . "\n" . $node ), 0, 12 );
	}

	/**
	 * Whether a margin fact was captured through a sibling-combinator rule.
	 *
	 * A sibling-stacking utility (Tailwind's `space-y-*`) is authored as
	 * `.x > :not([hidden]) ~ :not([hidden])`, so it lands on every flat control
	 * after the first. Only the combinator is looked at, never a class name.
	 *
	 * @param mixed $provenance
	 * @internal
	 */
	public static function has_sibling_combinator_margin( $provenance ): bool {
		foreach ( is_array( $provenance ) ? $provenance : array() as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['selector'] ?? null ) || ! is_array( $entry['properties'] ?? null ) ) {
				continue;
			}
			$has_margin = false;
			foreach ( $entry['properties'] as $property ) {
				$has_margin = $has_margin || ( is_string( $property ) && str_starts_with( $property, 'margin' ) );
			}
			if ( ! $has_margin ) {
				continue;
			}
			// Drop attribute selectors and functional arguments, where `~` and `+`
			// are not combinators (`[a~=b]`, `:nth-child(2n+1)`).
			$selector = preg_replace( '/\[[^\]]*\]/', '', $entry['selector'] );
			do {
				$selector = preg_replace( '/\([^()]*\)/', '', (string) $selector, -1, $count );
			} while ( $count > 0 );
			if ( 1 === preg_match( '/[~+]/', (string) $selector ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * State the initial flex-container values a source flex box leaves implicit.
	 *
	 * The producer records declared facts only, so a source box that declares
	 * only `display: flex` reaches the graph without its initial `row`, `nowrap`
	 * and `align-items: stretch`. Jetpack's form element is a flex layout of its
	 * own (block.json `layout.default`: wrapping, `verticalAlignment: top`, which
	 * paints `align-items:flex-start`), so a source flex box transposed onto it
	 * would keep that wrap and top alignment. A fact the source declares (in
	 * `$layout` or in `$declared`, the base facts a conditional patch builds on)
	 * is never replaced.
	 *
	 * The row and wrap are stated only when the source box arranged every item
	 * the provider element holds: Jetpack also renders in-form copy and the
	 * status output as items of that element, which the source row did not
	 * arrange, and they keep the provider's wrap.
	 *
	 * @param array<string,mixed> $layout
	 * @param array<string,mixed> $declared
	 * @param bool                $arranges_every_item Whether the source box arranged every item of the provider element.
	 * @return array<string,mixed>
	 */
	public static function with_flex_container_defaults( array $layout, array $declared = array(), bool $arranges_every_item = true ): array {
		if ( ! in_array( strtolower( trim( (string) ( $layout['display'] ?? '' ) ) ), array( 'flex', 'inline-flex' ), true ) ) {
			return $layout;
		}
		$initial = array( 'align_items' => 'stretch' );
		if ( $arranges_every_item ) {
			$initial = array(
				'direction' => 'row',
				'wrap'      => 'nowrap',
			) + $initial;
		}
		return $layout + array_diff_key( $initial, $declared );
	}

	/**
	 * State the initial flex values a source flex item leaves implicit.
	 *
	 * A child of a source flex container is a flex item with `flex: 0 1 auto`
	 * unless it declares otherwise, and a box that declares `flex-grow` or
	 * `flex-shrink` is a flex item too. Jetpack sizes its own items: a field
	 * shell is `flex: 1 1 100%`, and on narrow screens the submit wrapper is
	 * `flex: 0 1 100%`. A transposed item would then start from a full-row basis
	 * and squeeze or stretch its row siblings (a field and the submit beside it).
	 * A fact the source declares (in `$layout`, or in `$declared`, the base facts
	 * a conditional patch builds on) is never replaced.
	 *
	 * @param array<string,mixed> $layout
	 * @param array<string,mixed> $declared
	 * @param bool                $flex_item Whether the box's source parent is a flex container.
	 * @return array<string,mixed>
	 */
	public static function with_flex_item_defaults( array $layout, array $declared = array(), bool $flex_item = false ): array {
		$facts = $layout + $declared;
		if ( isset( $facts['flex'] ) || ( ! $flex_item && array() === array_intersect_key( $layout, array_flip( array( 'flex_grow', 'flex_shrink' ) ) ) ) ) {
			return $layout;
		}
		return $layout + array_diff_key(
			array(
				'flex_grow'   => '0',
				'flex_shrink' => '1',
				'flex_basis'  => 'auto',
			),
			$facts
		);
	}

	/**
	 * Move a field control's flex-item facts onto its field wrapper.
	 *
	 * Jetpack renders a text-like control inside its own field wrapper, so the
	 * wrapper, not the control, is the item of the provider's field container.
	 * A source control that is a flex item (`input { flex: 1 }` in a flex form
	 * row) has its item facts stated on the wrapper's `field-N` node; on the
	 * control they would size nothing, and the wrapper would keep Jetpack's
	 * full-row `flex: 1 1 100%`. Every other fact stays on the control.
	 *
	 * @param array<string,mixed> $graph    Provider overlay graph.
	 * @param array<int,int>      $controls Control indexes rendered inside a field wrapper.
	 * @return array<string,mixed>
	 */
	public static function with_field_wrapper_item_layout( array $graph, array $controls ): array {
		$item_facts = array_flip( array( 'flex', 'flex_grow', 'flex_shrink', 'flex_basis', 'align_self', 'order' ) );
		$wrapped    = array_fill_keys( array_map( static fn( int $index ): string => 'control-' . $index, $controls ), true );
		$moved      = array();
		foreach ( $graph['nodes'] ?? array() as $key => $node ) {
			$id   = is_array( $node ) ? (string) ( $node['id'] ?? '' ) : '';
			$item = isset( $wrapped[ $id ] ) && is_array( $node['layout'] ?? null ) ? array_intersect_key( $node['layout'], $item_facts ) : array();
			if ( empty( $item ) ) {
				continue;
			}
			$graph['nodes'][ $key ]['layout']     = array_diff_key( $node['layout'], $item );
			$moved[ 'field-' . substr( $id, 8 ) ] = $item;
		}
		foreach ( $graph['variants'] ?? array() as $key => $variant ) {
			$id    = is_array( $variant ) ? (string) ( $variant['node'] ?? '' ) : '';
			$patch = isset( $wrapped[ $id ] ) && is_array( $variant['layout_patch'] ?? null ) ? array_intersect_key( $variant['layout_patch'], $item_facts ) : array();
			if ( empty( $patch ) ) {
				continue;
			}
			$graph['variants'][ $key ]['layout_patch'] = array_diff_key( $variant['layout_patch'], $patch );
			$field_variant                             = $variant;
			$field_variant['node']                     = 'field-' . substr( $id, 8 );
			$field_variant['layout_patch']             = $patch;
			$graph['variants'][]                       = $field_variant;
			$moved                                    += array( $field_variant['node'] => array() );
		}
		foreach ( $moved as $field_id => $item ) {
			foreach ( $graph['nodes'] as $key => $node ) {
				if ( is_array( $node ) && ( $node['id'] ?? null ) === $field_id ) {
					$graph['nodes'][ $key ]['layout'] = array_merge( is_array( $node['layout'] ?? null ) ? $node['layout'] : array(), $item );
					continue 2;
				}
			}
			$graph['nodes'][] = array(
				'id'     => $field_id,
				'layout' => $item,
			);
		}
		return $graph;
	}

	/** Jetpack fields default to flex:1 1 100%; preserve a source fixed width's default flex behavior. */
	public static function fixed_width_uses_default_flex( array $node ): bool {
		$layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : ( self::layout_patch( $node ) );
		$width  = $layout['width'] ?? null;
		if ( ! is_string( $width ) || ! preg_match( '/^(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em)$/D', $width ) || array_intersect( array( 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' ), array_keys( $layout ) ) ) {
			return false;
		}
		return self::has_source_fact( $node['provenance'] ?? array(), null, array( 'width' ), false, true );
	}

	/**
	 * Resolve source flex stretch into the generated Button wrapper's layout.
	 *
	 * The graph deliberately omits controls with no authored declarations, so a
	 * submit can be present only in topology. This recognizes that narrow case
	 * without adding browser-computed geometry to the producer contract.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,variants:array<int,array<string,mixed>>,operations:array<int,array<string,mixed>>}
	 */
	public static function form_layout_intent( array $form ): array {
		$graph    = is_array( $form['layout_graph'] ?? null ) ? $form['layout_graph'] : array();
		$controls = is_array( $form['controls'] ?? null ) ? $form['controls'] : array();
		$nodes    = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = $node;
			}
		}
		$form_layout  = is_array( $nodes['form']['layout'] ?? null ) ? $nodes['form']['layout'] : array();
		$intent_nodes = array();
		$variants     = array();
		$operations   = array();
		$conditions   = array();
		foreach ( $form['control_topology']['nodes'] ?? array() as $topology_node ) {
			$index = is_array( $topology_node ) ? ( $topology_node['control'] ?? null ) : null;
			if ( ! is_int( $index ) || null !== ( $topology_node['parent'] ?? null ) || 'submit' !== strtolower( (string) ( $controls[ $index ]['type'] ?? '' ) ) ) {
				continue;
			}
			$control_id     = 'control-' . $index;
			$control_layout = is_array( $nodes[ $control_id ]['layout'] ?? null ) ? $nodes[ $control_id ]['layout'] : array();
			if ( isset( $nodes[ $control_id ] ) || ! self::submit_allows_flex_stretch( $control_layout ) ) {
				continue;
			}
			$base_stretches = self::is_stretching_column_flex( $form_layout );
			foreach ( $graph['variants'] ?? array() as $variant ) {
				if ( ! is_array( $variant ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
					continue;
				}
				if ( 'form' === ( $variant['node'] ?? null ) && array_intersect( array( 'display', 'direction', 'align_items' ), array_keys( $variant['layout_patch'] ) ) && ! self::is_stretching_column_flex( array_merge( $form_layout, $variant['layout_patch'] ) ) ) {
					$base_stretches = false;
				}
				if ( ( $variant['node'] ?? null ) === $control_id && ! self::submit_allows_flex_stretch( array_merge( $control_layout, $variant['layout_patch'] ) ) ) {
					$base_stretches = false;
				}
			}
			if ( $base_stretches ) {
				$intent_nodes[] = array(
					'id'     => $control_id,
					'layout' => array( 'align_self' => 'stretch' ),
				);
				$operations[]   = array(
					'dimension' => 'layout',
					'strategy'  => 'form_layout_intent_flex_stretch_submit',
					'node_hash' => hash( 'sha256', $control_id ),
				);
			}
			foreach ( $graph['variants'] ?? array() as $variant ) {
				if ( ! is_array( $variant ) || 'form' !== ( $variant['node'] ?? null ) || ! is_array( $variant['condition'] ?? null ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
					continue;
				}
				$condition_key = (string) wp_json_encode( $variant['condition'] );
				if ( isset( $conditions[ $condition_key ] ) ) {
					continue;
				}
				$conditions[ $condition_key ] = true;
				$parent_patch                 = array();
				foreach ( $graph['variants'] as $parent_variant ) {
					if ( is_array( $parent_variant ) && 'form' === ( $parent_variant['node'] ?? null ) && wp_json_encode( $parent_variant['condition'] ?? null ) === $condition_key && is_array( $parent_variant['layout_patch'] ?? null ) ) {
						$parent_patch = array_merge( $parent_patch, $parent_variant['layout_patch'] );
					}
				}
				$parent_layout = array_merge( $form_layout, $parent_patch );
				if ( ! self::is_stretching_column_flex( $parent_layout ) ) {
					continue;
				}
				$control_patch = array();
				foreach ( $graph['variants'] as $control_variant ) {
					if ( is_array( $control_variant ) && ( $control_variant['node'] ?? null ) === $control_id && wp_json_encode( $control_variant['condition'] ?? null ) === $condition_key && is_array( $control_variant['layout_patch'] ?? null ) ) {
						$control_patch = array_merge( $control_patch, $control_variant['layout_patch'] );
					}
				}
				if ( ! self::submit_allows_flex_stretch( array_merge( $control_layout, $control_patch ) ) ) {
					continue;
				}
				$variants[]   = array(
					'node'         => $control_id,
					'condition'    => $variant['condition'],
					'layout_patch' => array( 'align_self' => 'stretch' ),
				);
				$operations[] = array(
					'dimension'  => 'layout',
					'strategy'   => 'form_layout_intent_flex_stretch_submit',
					'node_hash'  => hash( 'sha256', $control_id ),
					'responsive' => true,
				);
			}
		}
		return array(
			'nodes'      => $intent_nodes,
			'variants'   => $variants,
			'operations' => $operations,
		);
	}

	private static function is_stretching_column_flex( array $layout ): bool {
		return 'flex' === ( $layout['display'] ?? null ) && 'column' === ( $layout['direction'] ?? null ) && in_array( $layout['align_items'] ?? 'stretch', array( 'stretch' ), true );
	}

	private static function submit_allows_flex_stretch( array $layout ): bool {
		return ! array_intersect( array( 'width', 'flex', 'flex_grow', 'flex_shrink', 'flex_basis' ), array_keys( $layout ) ) && in_array( $layout['align_self'] ?? 'auto', array( 'auto', 'stretch' ), true );
	}

	/**
	 * Drop grid placement a provider cannot honor.
	 *
	 * Siblings that declare the same source grid row are separated by offsets
	 * outside the layout graph's vocabulary. A provider that gives each control
	 * its own row addresses different controls with those row indexes, so the
	 * placement would reorder the form instead of reproducing it.
	 *
	 * Placement is judged against the complete source graph, because a partly
	 * represented sibling set can look like an ordered sequence on its own.
	 *
	 * A grid whose every child keeps its own provider element and declares an
	 * explicit placement is the exception (#2005): explicit placement does not
	 * depend on the provider's row sequence, so Wix's mesh rows (several boxes
	 * sharing one cell, offset by their own insets) are reproduced as authored.
	 *
	 * @param array<string,mixed> $graph
	 * @param array<string,mixed>|null $source_graph
	 * @param array<string,mixed>|null $placed Source node ids rendered as their own provider element.
	 * @return array<string,mixed>
	 */
	public static function without_shared_source_grid_rows( array $graph, ?array $source_graph = null, ?array $placed = null ): array {
		$shared = self::shared_source_grid_row_nodes( is_array( $source_graph ) ? $source_graph : $graph, $placed );
		if ( empty( $shared ) ) {
			return $graph;
		}
		// Track indexes describe the same rows being dropped. Keeping them would
		// give every field its own row and undo the column membership the provider
		// states for itself. The authored display mode must still reach the field
		// list: Jetpack paints `display:flex` on `.wp-block-jetpack-contact-form`
		// unless `is-layout-flex` is present, which outranks a class like `.grid`.
		// Column templates stay with the source stylesheet.
		// The source container may already be collapsed onto the provider's own form
		// element, so the track definition can sit on either node.
		$containers = array( 'form' => true );
		foreach ( ( is_array( $source_graph ) ? $source_graph : $graph )['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && isset( $shared[ (string) ( $node['id'] ?? '' ) ] ) && is_string( $node['parent'] ?? null ) ) {
				$containers[ $node['parent'] ] = true;
			}
		}
		foreach ( $graph['nodes'] ?? array() as $index => $node ) {
			if ( ! is_array( $node ) || ! is_array( $node['layout'] ?? null ) ) {
				continue;
			}
			if ( isset( $shared[ (string) ( $node['id'] ?? '' ) ] ) ) {
				$graph['nodes'][ $index ]['layout'] = array_diff_key( $node['layout'], array_flip( array( 'area', 'row' ) ) );
			}
			if ( isset( $containers[ (string) ( $node['id'] ?? '' ) ] ) && 'grid' === ( $node['layout']['display'] ?? '' ) ) {
				$graph['nodes'][ $index ]['layout'] = array_diff_key( $graph['nodes'][ $index ]['layout'], array_flip( array( 'columns', 'rows' ) ) );
			}
		}
		foreach ( $graph['variants'] ?? array() as $index => $variant ) {
			$patch        = self::layout_patch( $variant );
			$drops_tracks = (bool) array_intersect( array( 'columns', 'rows' ), array_keys( $patch ) );
			$grid_display = 'grid' === ( $patch['display'] ?? '' );
			if ( ! is_array( $variant ) || ! isset( $containers[ (string) ( $variant['node'] ?? '' ) ] ) || empty( $patch ) || ( ! $grid_display && ! $drops_tracks ) ) {
				continue;
			}
			$patch = array_diff_key( $patch, array_flip( array( 'columns', 'rows' ) ) );
			if ( empty( $patch ) ) {
				unset( $graph['variants'][ $index ] );
				continue;
			}
			$graph['variants'][ $index ]['layout_patch'] = $patch;
		}
		foreach ( $graph['variants'] ?? array() as $index => $variant ) {
			if ( ! is_array( $variant ) || ! isset( $shared[ (string) ( $variant['node'] ?? '' ) ] ) || ! is_array( $variant['layout_patch'] ?? null ) ) {
				continue;
			}
			$patch = array_diff_key( $variant['layout_patch'], array_flip( array( 'area', 'row' ) ) );
			if ( empty( $patch ) ) {
				unset( $graph['variants'][ $index ] );
				continue;
			}
			$graph['variants'][ $index ]['layout_patch'] = $patch;
		}
		if ( isset( $graph['variants'] ) && is_array( $graph['variants'] ) ) {
			$graph['variants'] = array_values( $graph['variants'] );
		}
		return $graph;
	}

	/**
	 * Groups of sibling boxes that a source grid places on one row.
	 *
	 * Only sized boxes qualify: a row is a band of columns when its members each
	 * declare how much of it they occupy. Their offsets are outside the layout
	 * graph's vocabulary, so the proportion comes from the widths themselves.
	 *
	 * @param array<string,array<string,mixed>> $layout_nodes_by_id
	 * @param array<string,array<int,array<string,mixed>>> $variants_by_node
	 * @return array<int,array<string,float>>
	 */
	private static function source_grid_row_bands( array $layout_nodes_by_id, array $variants_by_node ): array {
		$rows = array();
		foreach ( $layout_nodes_by_id as $id => $node ) {
			if ( ! preg_match( self::WRAPPER_ID_PATTERN, (string) $id ) ) {
				continue;
			}
			$layout = is_array( $node['layout'] ?? null ) ? $node['layout'] : array();
			$width  = self::declared_pixel_width( $layout );
			$row    = self::declared_grid_row( $layout );
			foreach ( $variants_by_node[ $id ] ?? array() as $variant ) {
				$patch = self::layout_patch( $variant );
				$row   = '' === $row ? self::declared_grid_row( $patch ) : $row;
				$width = 0.0 === $width ? self::declared_pixel_width( $patch ) : $width;
			}
			if ( '' === $row || 0.0 === $width ) {
				continue;
			}
			$rows[ (string) ( $node['parent'] ?? '' ) . "\n" . $row ][ (string) $id ] = $width;
		}

		return array_values( array_filter( $rows, static fn ( array $band ): bool => count( $band ) > 1 ) );
	}

	/**
	 * Whether a media condition widens at a single fixed breakpoint: the legacy
	 * `(min-width: 768px)` form and the modern CSS range syntax `(width>=768px)`
	 * Tailwind CSS v4 emits for its own `md:`/`lg:`/etc. prefixes.
	 */
	private static function is_min_width_media_query( string $query ): bool {
		return null !== self::min_width_breakpoint( $query );
	}

	/**
	 * The breakpoint a single-breakpoint widening query starts at, as its length.
	 * The one grammar both the predicate and the inverter read, so every query
	 * accepted as a widening also has a stacked range below it.
	 */
	private static function min_width_breakpoint( string $query ): ?string {
		if ( 1 !== preg_match( '/^(?:(?:only )?screen and )?\((?:min-width:\s?|width\s*>=\s*)([0-9]+(?:\.[0-9]+)?(?:px|em|rem))\)$/D', $query, $match ) ) {
			return null;
		}
		return $match[1];
	}

	/**
	 * Whether a layout condition is a media query that widens at one breakpoint.
	 *
	 * @internal
	 */
	public static function min_width_query( mixed $condition ): bool {
		return is_array( $condition ) && 'media' === ( $condition['kind'] ?? null ) && is_string( $condition['query'] ?? null ) && self::is_min_width_media_query( $condition['query'] );
	}

	private static function is_max_width_media_query( string $query ): bool {
		return 1 === preg_match( '/^(?:(?:only )?screen and )?\((?:max-width:\s?[0-9]+(?:\.[0-9]+)?(?:px|em|rem)|width\s*<=?\s*[0-9]+(?:\.[0-9]+)?(?:px|em|rem))\)$/D', $query );
	}

	/** One full-width track: `1fr`, `minmax(0,1fr)`, or their one-count repeat. */
	private static function is_single_fraction_track( string $columns ): bool {
		return 1 === self::equal_track_count( $columns );
	}

	/**
	 * Invert a proven min-width widening into the max-range the overlay applies
	 * below that breakpoint. `(min-width: 768px)` and `(width>=40rem)` both
	 * include the bound, so the stacked state is the strict less-than range.
	 */
	private static function inverted_min_width_media_query( string $query ): ?string {
		$breakpoint = self::min_width_breakpoint( $query );
		return null === $breakpoint ? null : '(width<' . $breakpoint . ')';
	}

	/**
	 * Recover a stacking query from a captured cascade fact when the equal-width
	 * row came from the class-token fallback rather than a graph variant.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $variants_by_node
	 * @param array<string,array<string,mixed>>            $layout_nodes_by_id
	 */
	private static function equal_width_stack_query_from_cascade_facts( string $parent_id, array $variants_by_node, array $layout_nodes_by_id ): ?string {
		foreach ( $variants_by_node[ $parent_id ] ?? array() as $variant ) {
			$condition = is_array( $variant['condition'] ?? null ) ? $variant['condition'] : null;
			if ( ! self::min_width_query( $condition ) ) {
				continue;
			}
			$inverted = self::inverted_min_width_media_query( $condition['query'] );
			if ( is_string( $inverted ) ) {
				return $inverted;
			}
		}
		foreach ( ( $layout_nodes_by_id[ $parent_id ]['provenance'] ?? array() ) as $fact ) {
			$condition = is_array( $fact ) && is_array( $fact['condition'] ?? null ) ? $fact['condition'] : null;
			if ( ! self::min_width_query( $condition ) || ! in_array( 'grid-template-columns', $fact['properties'] ?? array(), true ) ) {
				continue;
			}
			$inverted = self::inverted_min_width_media_query( $condition['query'] );
			if ( is_string( $inverted ) ) {
				return $inverted;
			}
		}
		return null;
	}

	/** @param array<int,string> $tokens */
	public static function display_from_class_tokens( array $tokens ): ?string {
		$map     = array(
			'grid'        => 'grid',
			'flex'        => 'flex',
			'inline-flex' => 'inline-flex',
			'block'       => 'block',
			'contents'    => 'contents',
			'hidden'      => 'none',
		);
		$display = null;
		foreach ( $tokens as $token ) {
			if ( isset( $map[ $token ] ) ) {
				$display = $map[ $token ];
			}
		}
		return $display;
	}

	/**
	 * Whether source provenance proves every listed CSS property.
	 *
	 * A fact proves a property when it names a safe source path, a SHA-256
	 * source digest and the property, under the given condition. Selector
	 * evidence is required unless the caller proves facts by source alone.
	 *
	 * @param mixed             $facts            Provenance rows.
	 * @param array|null        $condition        Condition the fact must carry.
	 * @param array<int,string> $properties       CSS properties that must all be proven.
	 * @param bool              $require_selector Whether the fact must name its selector.
	 * @param bool              $any_condition    Whether a fact under any condition proves.
	 * @internal
	 */
	public static function has_source_fact( mixed $facts, ?array $condition, array $properties, bool $require_selector = true, bool $any_condition = false ): bool {
		foreach ( is_array( $facts ) ? $facts : array() as $fact ) {
			if ( ! is_array( $fact ) || ( ! $any_condition && ( $fact['condition'] ?? null ) !== $condition ) || ! is_string( $fact['source_path'] ?? null ) || ! is_string( $fact['source_sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fact['source_sha256'] ) || ( $require_selector && ! is_string( $fact['selector'] ?? null ) ) ) {
				continue;
			}
			if ( ! array_diff( $properties, $fact['properties'] ?? array() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find non-rendering field subtrees using proven ancestor display facts.
	 *
	 * The layout graph may omit unstyled descendants, so use control topology
	 * for ancestry too. Any conditional display patch keeps existing behavior.
	 * Bookkeeping-only branches retain their existing runtime-control receipt.
	 *
	 * @param array<string,mixed> $form Validated source form.
	 * @return array<string,bool> Hidden subtree node IDs.
	 * @internal
	 */
	public static function unconditionally_hidden_descendants( array $form ): array {
		$nodes    = array_column( $form['layout_graph']['nodes'] ?? array(), null, 'id' );
		$hidden   = array();
		$ancestry = $nodes;
		foreach ( $form['control_topology']['nodes'] ?? array() as $node ) {
			$ancestry[ $node['id'] ] = $node;
		}
		foreach ( $nodes as $id => $node ) {
			if ( 'control' === ( $node['kind'] ?? null ) || 'none' !== ( $node['layout']['display'] ?? null ) || ! self::has_unconditional_proven_property( $node, 'display' ) ) {
				continue;
			}
			foreach ( $form['layout_graph']['variants'] ?? array() as $variant ) {
				if ( ( $variant['node'] ?? null ) === $id && isset( $variant['layout_patch']['display'] ) ) {
					continue 2;
				}
			}
			$subtree = array();
			foreach ( $ancestry as $descendant_id => $descendant ) {
				$cursor = $descendant_id;
				$seen   = array();
				while ( isset( $ancestry[ $cursor ] ) && ! isset( $seen[ $cursor ] ) ) {
					if ( $cursor === $id ) {
						$subtree[ $descendant_id ] = true;
						break;
					}
					$seen[ $cursor ] = true;
					$cursor          = $ancestry[ $cursor ]['parent'] ?? '';
				}
			}
			foreach ( $form['controls'] ?? array() as $index => $control ) {
				if ( isset( $subtree[ 'control-' . $index ] ) && self::control_carries_authored_content( self::control_type( $control ) ) ) {
					$hidden += $subtree;
					break;
				}
			}
		}
		return $hidden;
	}

	/**
	 * Whether unconditional source provenance proves a property.
	 *
	 * @internal
	 */
	public static function has_unconditional_proven_property( array $node, string $property ): bool {
		return self::has_source_fact( $node['provenance'] ?? array(), null, array( $property ) );
	}

	/** Whether a variant's own conditional rule proves one of its properties. */
	private static function variant_proven( array $variant, string $property ): bool {
		return self::has_source_fact( $variant['provenance'] ?? array(), $variant['condition'] ?? null, array( $property ) );
	}

	/**
	 * Whether source provenance under one condition proves every layout fact.
	 *
	 * @param array<string,mixed> $layout
	 * @internal
	 */
	public static function layout_facts_proven( mixed $provenance, mixed $condition, array $layout ): bool {
		$property_map = Static_Site_Importer_Provider_Layout_Overlay::layout_property_map();
		foreach ( array_keys( $layout ) as $fact ) {
			if ( ! isset( $property_map[ $fact ] ) || ! self::has_source_fact( $provenance, $condition, array( $property_map[ $fact ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Whether a variant carries a non-empty, safe patch its own rule proves. */
	private static function variant_patch_proven( array $variant ): bool {
		$patch = self::layout_patch( $variant );
		return ! empty( $patch ) && Static_Site_Importer_Provider_Layout_Overlay::layout_values_are_safe( $patch ) && self::layout_facts_proven( $variant['provenance'] ?? array(), $variant['condition'] ?? null, $patch );
	}

	/**
	 * @return array<string,mixed> A layout variant's patch, or an empty patch.
	 *
	 * @internal
	 */
	public static function layout_patch( mixed $variant ): array {
		return is_array( $variant ) && is_array( $variant['layout_patch'] ?? null ) ? $variant['layout_patch'] : array();
	}

	/**
	 * @return array<int,string> A topology node's non-empty source class tokens.
	 *
	 * @internal
	 */
	public static function class_tokens( mixed $node ): array {
		$tokens = preg_split( '/\s+/', trim( (string) ( is_array( $node ) ? ( $node['class'] ?? '' ) : '' ) ) );
		return false === $tokens ? array() : array_values( array_filter( $tokens ) );
	}

	/**
	 * A control's lowercased type, falling back to its tag when asked.
	 *
	 * @internal
	 */
	public static function control_type( mixed $control, bool $tag_fallback = true ): string {
		return strtolower( trim( (string) ( $control['type'] ?? ( $tag_fallback ? $control['tag'] ?? '' : '' ) ) ) );
	}

	/**
	 * A class name with one more class appended.
	 *
	 * @internal
	 */
	public static function append_class( mixed $class_name, string $appended ): string {
		return trim( (string) $class_name . ' ' . $appended );
	}

	/**
	 * A field class name carrying a source semantic wrapper's markers.
	 *
	 * @param array<int,string> $classes
	 */
	private static function with_semantic_markers( mixed $class_name, array $node, string $tag, array $classes ): string {
		$depth   = min( 99, max( 0, (int) $node['depth'] ) );
		$markers = array( 'ssi-source-semantic-wrapper-' . $depth . '--' . $tag );
		foreach ( $classes as $class ) {
			$markers[] = 'ssi-source-semantic-wrapper-' . $depth . '--' . $tag . '--' . $class;
		}
		return trim( implode( ' ', array_filter( array_merge( array( (string) $class_name ), $markers ) ) ) );
	}

	/**
	 * A selector made of an optional type and one or more classes.
	 *
	 * @internal
	 */
	public static function is_class_only_selector( string $selector ): bool {
		return 1 === preg_match( '/^(?:[a-z][a-z0-9-]*)?(?:\.[a-zA-Z][a-zA-Z0-9_-]*)+$/D', $selector );
	}

	/**
	 * A class-only selector naming at least one of an element's classes, so the
	 * element keeps the rule wherever it keeps those classes.
	 *
	 * @param array<int,mixed> $class_tokens
	 * @internal
	 */
	public static function class_only_selector_names( string $selector, array $class_tokens ): bool {
		if ( ! self::is_class_only_selector( $selector ) ) {
			return false;
		}
		foreach ( $class_tokens as $class_token ) {
			if ( is_string( $class_token ) && '' !== $class_token && preg_match( '/\.' . preg_quote( $class_token, '/' ) . '(?![a-zA-Z0-9_-])/', $selector ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether every provenance row is a class-only selector naming one of the
	 * element's classes. No rows prove nothing.
	 *
	 * @param array<int,mixed> $rows
	 * @param array<int,mixed> $class_tokens
	 */
	private static function class_owns_rows( array $rows, array $class_tokens ): bool {
		foreach ( $rows as $row ) {
			if ( ! self::class_only_selector_names( is_array( $row ) && is_string( $row['selector'] ?? null ) ? $row['selector'] : '', $class_tokens ) ) {
				return false;
			}
		}
		return array() !== $rows;
	}

	/**
	 * Start line and span of a grid-column value on equal fractional tracks.
	 *
	 * @internal
	 */
	public static function grid_column_placement( mixed $columns, mixed $column ): ?array {
		$columns = preg_replace( '/\s+/', '', is_string( $columns ) ? $columns : '' );
		$column  = preg_replace( '/\s+/', '', is_string( $column ) ? $column : '' );
		$count   = self::equal_track_count( is_string( $columns ) ? $columns : '' );
		if ( null === $count || ! is_string( $column ) ) {
			return null;
		}
		if ( '1/-1' === $column ) {
			return array(
				'columns' => $count,
				'start'   => 1,
				'span'    => $count,
			);
		}
		if ( 1 !== preg_match( '/^(?:([1-9][0-9]*)\/)?span([1-9][0-9]*)$/D', $column, $span ) ) {
			return null;
		}
		$start = '' === $span[1] ? null : (int) $span[1];
		$size  = (int) $span[2];
		if ( $size > $count || ( null !== $start && ( $start < 1 || $start + $size - 1 > $count ) ) ) {
			return null;
		}
		return array(
			'columns' => $count,
			'start'   => $start,
			'span'    => $size,
		);
	}

	/** Row start, column start, and column span of a four-part grid-area value. */
	private static function grid_area_placement( mixed $area ): ?array {
		$area = is_string( $area ) ? trim( $area ) : '';
		if ( 1 !== preg_match( '/^([0-9]+|auto)\s*\/\s*([0-9]+|auto)\s*\/\s*span\s+[0-9]+\s*\/\s*span\s+([1-9][0-9]*)$/D', $area, $match ) ) {
			return null;
		}
		return array(
			'row'   => 'auto' === $match[1] ? null : (int) $match[1],
			'start' => 'auto' === $match[2] ? null : (int) $match[2],
			'span'  => (int) $match[3],
		);
	}

	/** First line of a grid-row or grid-column value, when it is a line number. */
	private static function grid_line_start( mixed $value ): ?int {
		$value = preg_replace( '/\s+/', '', is_string( $value ) ? $value : '' );
		if ( ! is_string( $value ) || 1 !== preg_match( '/^([1-9][0-9]*)(?:\/(?:span[1-9][0-9]*|-1|[1-9][0-9]*))?$/D', $value, $match ) ) {
			return null;
		}
		return (int) $match[1];
	}

	/**
	 * Count of equal fractional tracks, however the author spelled them.
	 *
	 * Every column parser reads tracks through this one grammar, so the same grid
	 * is recognised by every pass: `repeat(N, T)` or an explicit list of N `T`,
	 * where `T` is `1fr` or `minmax(0, 1fr)`. Null for any other track list.
	 */
	private static function equal_track_count( string $columns ): ?int {
		$columns = preg_replace( '/\s+/', '', $columns );
		$track   = '(?:1fr|minmax\(0(?:px)?,1fr\))';
		if ( ! is_string( $columns ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^repeat\(([1-9][0-9]*),' . $track . '\)$/D', $columns, $match ) ) {
			return (int) $match[1];
		}
		return 1 === preg_match( '/^(?:' . $track . ')+$/D', $columns ) ? (int) preg_match_all( '/' . $track . '/', $columns ) : null;
	}

	/**
	 * Resolved length of a gap, including a custom property's own length fallback.
	 */
	private static function resolved_gap_length( string $gap ): ?string {
		$gap = trim( $gap );
		if ( 1 === preg_match( '/^calc\(\s*([0-9]+(?:\.[0-9]+)?)\s*\*\s*([0-9]+(?:\.[0-9]+)?)(px|rem|em)\s*\)$/D', $gap, $product ) ) {
			return rtrim( rtrim( number_format( (float) $product[1] * (float) $product[2], 4, '.', '' ), '0' ), '.' ) . $product[3];
		}
		if ( 1 === preg_match( '/^(?:0|[0-9]+(?:\.[0-9]+)?)(?:px|rem|em)$/D', $gap ) ) {
			return $gap;
		}
		if ( 1 === preg_match( '/^var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79},\s*((?:0|[0-9]+(?:\.[0-9]+)?)(?:px|rem|em))\)$/D', $gap, $match ) ) {
			return $match[1];
		}
		return null;
	}

	/**
	 * A box's row and column gaps, read from the `gap` shorthand or its longhands.
	 *
	 * `gap` states the row gap and then the column gap, which defaults to the
	 * row gap. Blocks Engine resolves a shorthand that meets a longhand into the
	 * longhands that hold, so a shorthand beside a disagreeing longhand is a box
	 * whose cascade order is unknown (a custom-property shorthand, say). That box
	 * is null, as is a shorthand of more than two values.
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

	/** Jetpack field width, or null when the share is not one of those steps. */
	private static function clean_provider_field_width( float $share ): ?int {
		foreach ( self::WIDTH_STEPS as $step ) {
			if ( abs( $share - ( $step / 100 ) ) <= 0.005 ) {
				return $step;
			}
		}
		return null;
	}

	/** Place span items into rows that fill the track list in source order. Null when they do not tile. */
	private static function tiled_grid_span_placements( array $placements, int $columns ): ?array {
		if ( $columns < 2 || count( $placements ) < 2 ) {
			return null;
		}
		$rows_declared = array_filter( $placements, static fn ( array $placement ): bool => null !== $placement['row'] );
		if ( 0 !== count( $rows_declared ) && count( $rows_declared ) !== count( $placements ) ) {
			return null;
		}
		if ( count( $rows_declared ) === count( $placements ) ) {
			$by_row     = array();
			$normalized = $placements;
			foreach ( $placements as $index => $placement ) {
				$by_row[ $placement['row'] ][] = $index;
			}
			foreach ( $by_row as $row => $indexes ) {
				$cursor = 1;
				foreach ( $indexes as $index ) {
					$span  = $normalized[ $index ]['span'];
					$start = $normalized[ $index ]['start'] ?? $cursor;
					if ( $start !== $cursor || $span < 1 || $cursor + $span - 1 > $columns ) {
						return null;
					}
					$normalized[ $index ]['start'] = $start;
					$normalized[ $index ]['row']   = (int) $row;
					$cursor                       += $span;
				}
				if ( $columns + 1 !== $cursor ) {
					return null;
				}
			}
		} else {
			$cursor     = 1;
			$row        = 1;
			$normalized = array();
			foreach ( $placements as $placement ) {
				$span  = $placement['span'];
				$start = $placement['start'] ?? $cursor;
				if ( $start !== $cursor || $cursor + $span - 1 > $columns ) {
					return null;
				}
				$placement['start'] = $start;
				$placement['row']   = $row;
				$normalized[]       = $placement;
				$cursor            += $span;
				if ( $columns + 1 === $cursor ) {
					$cursor = 1;
					++$row;
				}
			}
			if ( 1 !== $cursor ) {
				return null;
			}
		}
		$visual = $normalized;
		usort(
			$visual,
			static function ( array $left, array $right ): int {
				$row_order = $left['row'] <=> $right['row'];

				return 0 !== $row_order ? $row_order : $left['start'] <=> $right['start'];
			}
		);
		if ( array_column( $visual, 'control' ) !== array_column( $normalized, 'control' ) ) {
			return null;
		}
		return $normalized;
	}

	/** Width of one span after the source column-gap is subtracted from that share. */
	private static function fractional_track_size( float $share, string $gap ): string {
		$share_css = rtrim( rtrim( number_format( $share * 100, 3, '.', '' ), '0' ), '.' );
		if ( 1 !== preg_match( '/^([0-9]+(?:\.[0-9]+)?)(px|rem|em)$/D', trim( $gap ), $match ) ) {
			$match = array( '1.5rem', '1.5', 'rem' );
		}
		$portion = (float) $match[1] * ( 1 - $share );
		if ( $portion < 0.0000001 ) {
			return $share_css . '%';
		}
		$gap_css = rtrim( rtrim( number_format( $portion, 4, '.', '' ), '0' ), '.' ) . $match[2];
		return 'calc(' . $share_css . '% - ' . $gap_css . ')';
	}

	/**
	 * Equal-fraction track count Jetpack can express as a field `width` of
	 * 50 / 33 / 25. Null when the value is not that shape.
	 */
	private static function equal_fraction_column_count( string $columns ): ?int {
		$count = self::equal_track_count( $columns );
		return in_array( $count, array( 2, 3, 4 ), true ) ? $count : null;
	}

	/**
	 * Whether a resolved `grid-template-columns` value is exactly $count equal
	 * fractional tracks. Accepts the plain `1fr` form and the `minmax(0,1fr)`
	 * form utility frameworks such as Tailwind emit for their grid-cols-N classes.
	 *
	 * @internal
	 */
	public static function is_equal_fraction_columns( string $columns, int $count ): bool {
		return self::equal_track_count( $columns ) === $count;
	}

	/** Jetpack subtracts a whole gap from each field; the source share is gap * (count-1)/count. */
	private static function equal_fraction_track_size( int $count, string $gap ): string {
		return self::fractional_track_size( 1 / $count, $gap );
	}

	/** @param array<string,mixed> $layout */
	private static function declared_pixel_width( array $layout ): float {
		return 1 === preg_match( '/^([0-9]+(?:\.[0-9]+)?)px$/D', trim( (string) ( $layout['width'] ?? '' ) ), $match ) ? (float) $match[1] : 0.0;
	}

	/**
	 * Provider field widths for one row band, snapped together.
	 *
	 * Snapping each share on its own can overfill the row: a 1:2 band snaps to
	 * 33 + 75, and at 108% the provider wraps the fields onto separate rows. The
	 * band is snapped as a whole instead: the closest step combination that still
	 * fits in one row, preferring the one that fills more of it on a tie. Null
	 * when no combination fits (more boxes than the narrowest step allows).
	 *
	 * @param list<float> $shares Each box's share of the band, summing to 1.
	 * @return list<int>|null
	 */
	private static function band_field_widths( array $shares ): ?array {
		$steps  = self::WIDTH_STEPS;
		$radix  = count( $steps );
		$count  = count( $shares );
		$combos = $radix ** $count;
		if ( $count * min( $steps ) > 100 ) {
			return null;
		}
		$best       = null;
		$best_error = INF;
		$best_sum   = 0;
		for ( $code = 0; $code < $combos; $code++ ) {
			$widths = array();
			$error  = 0.0;
			$digits = $code;
			foreach ( $shares as $share ) {
				$step     = $steps[ $digits % $radix ];
				$digits   = intdiv( $digits, $radix );
				$widths[] = $step;
				$error   += abs( $share - ( $step / 100 ) );
			}
			$sum = array_sum( $widths );
			if ( $sum <= 100 && ( $error < $best_error - 0.0000001 || ( abs( $error - $best_error ) <= 0.0000001 && $sum > $best_sum ) ) ) {
				$best       = $widths;
				$best_error = $error;
				$best_sum   = $sum;
			}
		}
		return $best;
	}

	/** The provider states column membership in fixed steps, so snap to the nearest. */
	private static function provider_field_width( float $share ): int {
		$closest = 100;
		foreach ( self::WIDTH_STEPS as $step ) {
			if ( abs( $share - ( $step / 100 ) ) < abs( $share - ( $closest / 100 ) ) ) {
				$closest = $step;
			}
		}

		return $closest;
	}

	/**
	 * Source boxes whose grid row placement cannot be transposed to a provider.
	 *
	 * @param array<string,mixed> $graph
	 * @return array<string,bool>
	 */
	private static function shared_source_grid_row_nodes( array $graph, ?array $placed = null ): array {
		$parents    = array();
		$rows       = array();
		$placements = array();
		$conditions = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$parents[ $node['id'] ] = is_string( $node['parent'] ?? null ) ? $node['parent'] : '';
			$row                    = self::declared_grid_row( is_array( $node['layout'] ?? null ) ? $node['layout'] : array() );
			if ( '' !== $row ) {
				$rows[ $node['id'] ][ $row ]                   = true;
				$placements[ $node['id'] ]['null']             = self::grid_placement_is_explicit( $node['layout'] );
				$conditions[ $parents[ $node['id'] ] ]['null'] = true;
			}
		}
		foreach ( $graph['variants'] ?? array() as $variant ) {
			$id = is_array( $variant ) && is_string( $variant['node'] ?? null ) ? $variant['node'] : '';
			if ( '' === $id || ! isset( $parents[ $id ] ) ) {
				continue;
			}
			$row = self::declared_grid_row( self::layout_patch( $variant ) );
			if ( '' !== $row ) {
				$condition                                   = (string) wp_json_encode( $variant['condition'] ?? null );
				$rows[ $id ][ $row ]                         = true;
				$placements[ $id ][ $condition ]             = self::grid_placement_is_explicit( self::layout_patch( $variant ) );
				$conditions[ $parents[ $id ] ][ $condition ] = true;
			}
		}
		$by_parent = array();
		foreach ( $rows as $id => $declared ) {
			$parent                        = $parents[ $id ] ?? '';
			$by_parent[ $parent ]['boxes'] = ( $by_parent[ $parent ]['boxes'] ?? 0 ) + 1;
			foreach ( array_keys( $declared ) as $row ) {
				$by_parent[ $parent ]['rows'][ $row ] = true;
			}
		}
		$scrambled = array();
		foreach ( $by_parent as $parent => $summary ) {
			$distinct = count( $summary['rows'] );
			// One row for every box transposes as an ordered sequence, and a single
			// shared row transposes as one band. Any other mix means the provider's
			// own row sequence no longer lines up with these row indexes.
			if ( 1 < $distinct && $summary['boxes'] !== $distinct && ! self::grid_children_explicitly_placed( (string) $parent, $parents, $placements, $conditions[ $parent ] ?? array(), $placed ) ) {
				$scrambled[ $parent ] = true;
			}
		}
		$shared = array();
		foreach ( $parents as $id => $parent ) {
			if ( isset( $scrambled[ $parent ] ) ) {
				$shared[ $id ] = true;
			}
		}
		return $shared;
	}

	/**
	 * Whether every source child of a grid keeps its own provider element and
	 * places itself explicitly under every condition that places any sibling.
	 * Only then are the source row indexes independent of the provider's own
	 * child order. The grid itself must have collapsed onto the provider form
	 * element, whose direct children those provider elements are.
	 *
	 * @param array<string,string>                $parents
	 * @param array<string,array<string,bool>>    $placements
	 * @param array<string,bool>                  $conditions
	 * @param array<string,mixed>|null            $placed
	 */
	private static function grid_children_explicitly_placed( string $grid, array $parents, array $placements, array $conditions, ?array $placed ): bool {
		if ( null === $placed || '' === $grid || isset( $placed[ $grid ] ) || array() === $conditions ) {
			return false;
		}
		$children = array_keys( array_filter( $parents, static fn ( string $candidate ): bool => $candidate === $grid ) );
		if ( count( $children ) < 2 ) {
			return false;
		}
		foreach ( $children as $child ) {
			if ( ! isset( $placed[ $child ] ) ) {
				return false;
			}
			foreach ( array_keys( $conditions ) as $condition ) {
				if ( true !== ( $placements[ $child ][ $condition ] ?? false ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * A placement that names both its row and column lines, so it never relies on
	 * auto-placement order.
	 *
	 * @param array<string,mixed> $layout
	 */
	private static function grid_placement_is_explicit( array $layout ): bool {
		$area = trim( (string) ( $layout['area'] ?? '' ) );
		if ( '' !== $area ) {
			return 1 === preg_match( '#^[0-9]+ / [0-9]+ / [0-9]+ / [0-9]+$#D', preg_replace( '#\s*/\s*#', ' / ', $area ) ?? '' );
		}
		return 1 === preg_match( '#^[0-9]+(?:\s*/\s*(?:[0-9]+|span [0-9]+))?$#D', trim( (string) ( $layout['row'] ?? '' ) ) ) && 1 === preg_match( '#^[0-9]+(?:\s*/\s*(?:[0-9]+|span [0-9]+))?$#D', trim( (string) ( $layout['column'] ?? '' ) ) );
	}

	/** @param array<string,mixed> $layout */
	private static function declared_grid_row( array $layout ): string {
		$area = trim( (string) ( $layout['area'] ?? '' ) );
		if ( '' !== $area ) {
			$parts = preg_split( '#\s*/\s*#', $area );
			return is_array( $parts ) ? trim( $parts[0] ) : '';
		}
		return trim( (string) ( $layout['row'] ?? '' ) );
	}

	/**
	 * Keep a submit's captured height with its padding on the rendered control.
	 * Only remove duplicate facts: layout-only heights and ancestor box heights
	 * still have their own layout owner. Responsive facts match by condition.
	 *
	 * @param array $graph Provider layout graph before box transposition.
	 * @param array $form  Source form with its captured presentation graph.
	 * @return array Layout graph without duplicate submit heights.
	 */
	public static function without_presented_submit_heights( array $graph, array $form ): array {
		$heights  = array();
		$variants = array();
		foreach ( $form['presentation_graph']['controls'] ?? array() as $row ) {
			$index = $row['index'] ?? null;
			if ( is_int( $index ) && 'submit' === ( $form['controls'][ $index ]['type'] ?? '' ) && isset( $row['control']['styles']['height'] ) ) {
				$heights[ 'control-' . $index ] = $row['control']['styles']['height'];
			}
		}
		foreach ( $form['presentation_graph']['variants'] ?? array() as $variant ) {
			$index = $variant['index'] ?? null;
			if ( is_int( $index ) && 'submit' === ( $form['controls'][ $index ]['type'] ?? '' ) && 'control' === ( $variant['role'] ?? '' ) && isset( $variant['style_patch']['height'] ) ) {
				$variants[ 'control-' . $index ][ wp_json_encode( $variant['condition'] ) ] = $variant['style_patch']['height'];
			}
		}
		foreach ( $graph['nodes'] ?? array() as $index => $node ) {
			if ( isset( $heights[ $node['id'] ] ) && ( $node['layout']['height'] ?? null ) === $heights[ $node['id'] ] ) {
				unset( $graph['nodes'][ $index ]['layout']['height'] );
			}
		}
		foreach ( $graph['variants'] ?? array() as $index => $variant ) {
			$height = $variants[ $variant['node'] ][ wp_json_encode( $variant['condition'] ) ] ?? null;
			if ( null !== $height && ( $variant['layout_patch']['height'] ?? null ) === $height ) {
				unset( $graph['variants'][ $index ]['layout_patch']['height'] );
				if ( empty( $graph['variants'][ $index ]['layout_patch'] ) ) {
					unset( $graph['variants'][ $index ] );
				}
			}
		}
		$graph['variants'] = array_values( $graph['variants'] ?? array() );
		return $graph;
	}

	public static function provider_layout_target_map( array $form, string $scope, array $presentation_descriptors, array $box_targets = array(), array $phone_popup_targets = array(), string $country_trigger_class = '' ): array {
		$selector_scope = '.' . $scope;
		$targets        = array();
		foreach ( $form['layout_graph']['nodes'] ?? array() as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) ) {
				continue;
			}
			$id = $node['id'];
			if ( 'form' !== $id && 'form-box' !== $id && 'field-list' !== $id && ! isset( $box_targets[ $id ] ) && ! preg_match( '/^(?:control|field)-[0-9]+$/D', $id ) ) {
				continue;
			}
			if ( 'form-box' === $id ) {
				// The provider renders its block wrapper at the source form's own position,
				// so that wrapper is the element carrying the form box's page placement.
				$selector     = ! empty( $form['provider_native_topology'] ) ? $selector_scope . '.ssi-native-form-topology.jetpack-contact-form-container' : $selector_scope;
				$part         = ! empty( $form['provider_native_topology'] ) ? 'native_form_box' : 'form_box';
				$capabilities = array( 'direct_child_layout', 'item_layout', 'responsive_layout' );
			} elseif ( 'field-list' === $id ) {
				$selector     = $selector_scope . ' .ssi-source-field-list';
				$part         = 'field_list';
				$capabilities = array( 'container_layout', 'responsive_layout' );
			} elseif ( 'form' === $id ) {
				// The source container layout (display, tracks, gap and row gap) must
				// reach the provider's flex container: the element whose children are
				// the fields. Jetpack nests it two ways. Older output puts its form
				// inside the scoped block wrapper, so the form is the container. Jetpack
				// 16.3 puts the scoped block wrapper inside a plain block form (under a
				// container that also carries the scope), so the wrapper is the
				// container; a later form on a page has no form element at all. The
				// form branch therefore steps aside whenever the block wrapper sits
				// inside it, and the wrapper branch only steps aside when the form is
				// its child, so one rule lands on exactly one element in every nesting
				// (#2105, #2159).
				$selector = ! empty( $form['provider_native_topology'] )
					? $selector_scope . '.ssi-native-form-topology form.jetpack-contact-form__form, ' . $selector_scope . '.ssi-native-form-topology.wp-block-jetpack-contact-form:not(:has(form.jetpack-contact-form__form)):not(form.jetpack-contact-form__form *)'
					: $selector_scope . ' > form.jetpack-contact-form__form:not(:has(.wp-block-jetpack-contact-form)), ' . $selector_scope . ':not(:has(> form.jetpack-contact-form__form))';
				$part     = ! empty( $form['provider_native_topology'] ) ? 'native_form' : 'form';
				// Jetpack's contact-form root includes hidden and error nodes, so it cannot
				// promise source direct-child relationships. Generated node hooks can.
				$capabilities = array( 'container_layout', 'responsive_layout' );
			} elseif ( preg_match( '/^field-([0-9]+)$/D', $id, $matches ) ) {
				$selector     = $selector_scope . ' .' . self::layout_node_class( $scope, 'control-' . $matches[1] ) . '-wrap';
				$part         = 'field';
				$capabilities = Static_Site_Importer_Provider_Layout_Overlay::LAYOUT_CAPABILITIES;
			} else {
				$hook = $box_targets[ $id ] ?? self::layout_node_class( $scope, $id );
				// A topology hook spelled `<node class>-wrap` addresses the front-end
				// copy of a field block's className on Jetpack's field wrapper.
				$part     = 1 === preg_match( '/^ssi-node-[a-f0-9]{12}-wrap$/D', $hook ) ? 'wrap' : 'box';
				$selector = $selector_scope . ' .' . $hook;
				if ( preg_match( '/^control-([0-9]+)$/D', $id, $control ) && in_array( (int) $control[1], $form['provider_source_box_submits'] ?? array(), true ) ) {
					$selector .= ' > .wp-block-button__link';
					$part      = 'submit_link_box';
				}
				$capabilities = Static_Site_Importer_Provider_Layout_Overlay::LAYOUT_CAPABILITIES;
			}
			$targets[] = array(
				'part'         => $part,
				'node'         => $id,
				'selector'     => $selector,
				'capabilities' => $capabilities,
			);
		}
		$presentation_targets = array();
		foreach ( $presentation_descriptors as $index => $descriptor ) {
			if ( ! $descriptor['has_presentation'] ) {
				continue;
			}
			$target = array(
				'index'        => $index,
				'destinations' => $descriptor['destinations'],
			);
			if ( '' !== $descriptor['control_class'] && isset( $phone_popup_targets[ $index ] ) && '' !== $country_trigger_class ) {
				$target['destinations'] = array_values( array_filter( $target['destinations'], static fn( array $destination ): bool => 'control' !== ( $destination['role'] ?? null ) ) );
				array_unshift( $target['destinations'], array(
					'part'       => 'country_trigger',
					'role'       => 'control',
					'selector'   => $selector_scope . ' .' . $country_trigger_class,
					'properties' => array_keys( Static_Site_Importer_Provider_Layout_Overlay::positioned_control_presentation_property_keys() ),
					'priority'   => 'important',
				) );
			}
			$offscreen_label = Static_Site_Importer_Form_Presentation_Descriptor::source_label_moved_off_screen( $form, (int) $index );
			foreach ( $target['destinations'] as &$destination ) {
				unset( $destination['class'] );
				// A label the source moves off-screen keeps its accessible name
				// only while it is out of flow. Its offsets alone would shift a
				// visible caption, so the label destination carries the position
				// too.
				if ( $offscreen_label && 'label' === ( $destination['part'] ?? null ) && ! in_array( 'position', $destination['properties'], true ) ) {
					$destination['properties'][] = 'position';
				}
			}
			unset( $destination );
			$presentation_targets[] = $target;
		}
		return array(
			'schema'               => Static_Site_Importer_Provider_Layout_Overlay::MAP_SCHEMA,
			'provider'             => Static_Site_Importer_Form_Seeder::PROVIDER_ID,
			'scope'                => $selector_scope,
			'targets'              => $targets,
			'presentation_targets' => $presentation_targets,
		);
	}
}
