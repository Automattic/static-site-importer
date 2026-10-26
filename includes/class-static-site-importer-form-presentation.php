<?php
/**
 * Field presentation helpers for readable Jetpack forms.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Form_Presentation_Overlay' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-presentation-overlay.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Field_Markup' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-field-markup.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Textarea_Rows' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-textarea-rows.php';
}
if ( ! class_exists( 'Static_Site_Importer_Form_Presentation_Descriptor' ) ) {
	require_once __DIR__ . '/class-static-site-importer-form-presentation-descriptor.php';
}

/**
 * Resolves source field visibility, scope and presentation destinations.
 */
final class Static_Site_Importer_Form_Presentation {
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
		$box     = $properties ?? Static_Site_Importer_Form_Presentation_Overlay::box_property_map();
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

	/**
	 * Collapse each field's inactive provider error. Jetpack renders it as a
	 * zero-height flex item, which still takes a gap slot in a flex field box.
	 *
	 * @param array<string,mixed> $overlay Compiled provider overlay.
	 * @param string              $scope   Provider form scope.
	 * @return array<string,mixed>
	 */
	public static function collapse_inactive_field_errors( array $overlay, string $scope ): array {
		if ( ! isset( $overlay['overlay'] ) || ! is_array( $overlay['overlay'] ) || ! is_string( $overlay['css'] ?? null ) || ! preg_match( '/^ssi-form-[a-f0-9]{12}$/D', $scope ) ) {
			return $overlay;
		}
		return self::with_overlay_css( $overlay, rtrim( $overlay['css'] ) . "\n" . self::provider_base_rules( $scope ) . "\n" );
	}

	/**
	 * Inactive field errors take no space, and a placeholder without an
	 * authored colour keeps the browser default over the provider's `inherit`.
	 */
	private static function provider_base_rules( string $scope ): string {
		return '.' . $scope . ' .grunion-field-wrap .contact-form__input-error:not(.has-errors){display:none}' . "\n" . '.' . $scope . ' .grunion-field-wrap .grunion-field::placeholder{color:revert}';
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

	/** The overlay carrying CSS, with its digest and size restated. */
	private static function with_overlay_css( array $overlay, string $css ): array {
		$overlay['css']               = $css;
		$overlay['overlay']['css']    = $css;
		$overlay['overlay']['sha256'] = hash( 'sha256', $css );
		$overlay['overlay']['bytes']  = strlen( $css );
		return $overlay;
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

	public static function provider_layout_target_map( array $form, string $scope, array $presentation_descriptors ): array {
		$selector_scope       = '.' . $scope;
		$targets              = array();
		$presentation_targets = array();
		foreach ( $presentation_descriptors as $index => $descriptor ) {
			if ( ! $descriptor['has_presentation'] ) {
				continue;
			}
			$target          = array(
				'index'        => $index,
				'destinations' => $descriptor['destinations'],
			);
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
			'schema'               => Static_Site_Importer_Form_Presentation_Overlay::MAP_SCHEMA,
			'provider'             => Static_Site_Importer_Form_Seeder::PROVIDER_ID,
			'scope'                => $selector_scope,
			'targets'              => $targets,
			'presentation_targets' => $presentation_targets,
		);
	}
}
