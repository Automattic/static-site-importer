<?php
/**
 * Projects a producer-declared multi-step form track onto provider form steps.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps `generic/form-steps/v1` onto Jetpack's native multi-step form blocks.
 *
 * The producer proves from source CSS that a form's control-bearing panels sit
 * in a clipped, paged track, so one panel shows at a time. Jetpack owns that
 * paging natively: each panel becomes a `jetpack/form-step`, the track becomes
 * the `jetpack/form-step-container`, the source's own navigation copy names the
 * step buttons, and the trailing completion panel becomes the confirmation the
 * provider shows after a successful submit.
 */
final class Static_Site_Importer_Form_Step_Projection {
	/** Generated-block key that records which source control a field came from. */
	public const CONTROL_KEY = 'ssi_source_control';

	/**
	 * Resolve which declared step owns every mapped control.
	 *
	 * @param array<string,mixed>      $form                Validated form row with `steps`.
	 * @param array<int,mixed>         $controls            Source controls.
	 * @param array<int,bool>          $suppressed_controls Controls the provider does not render.
	 * @return array{track:string,steps:array<int,string>,completion:?string,navigation:?string,control_steps:array<int,int>}|null
	 */
	public static function plan( array $form, array $controls, array $suppressed_controls ): ?array {
		$steps = $form['steps'] ?? null;
		$nodes = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $steps ) || ! is_array( $nodes ) || ! is_array( $steps['steps'] ?? null ) ) {
			return null;
		}
		$step_ids = array();
		foreach ( $steps['steps'] as $position => $step ) {
			$step_ids[ (string) $step['wrapper'] ] = $position;
		}
		$parents = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$parents[ $node['id'] ] = is_string( $node['parent'] ?? null ) ? $node['parent'] : null;
			}
		}
		$control_steps = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || 'control' !== ( $node['kind'] ?? null ) || ! is_int( $node['control'] ?? null ) ) {
				continue;
			}
			$step = null;
			for ( $parent = $node['parent'] ?? null; is_string( $parent ); $parent = $parents[ $parent ] ?? null ) {
				if ( isset( $step_ids[ $parent ] ) ) {
					$step = $step_ids[ $parent ];
					break;
				}
			}
			if ( null !== $step ) {
				$control_steps[ $node['control'] ] = $step;
				continue;
			}
			// A rendered control outside every step would have no step to live in.
			if ( isset( $controls[ $node['control'] ] ) && ! isset( $suppressed_controls[ $node['control'] ] ) ) {
				return null;
			}
		}
		return array(
			'track'         => (string) $steps['track'],
			'steps'         => array_keys( $step_ids ),
			'completion'    => is_string( $steps['completion'] ?? null ) ? $steps['completion'] : null,
			'navigation'    => is_string( $steps['navigation'] ?? null ) ? $steps['navigation'] : null,
			'control_steps' => $control_steps,
		);
	}

	/**
	 * Move in-form copy that belongs to the completion panel or the step
	 * navigation out of the form's context, where it would otherwise render as
	 * permanent copy beside the fields.
	 *
	 * @param array<string,mixed> $form Validated form row.
	 * @param array<string,mixed> $plan Step plan.
	 * @return array{form:array<string,mixed>,completion:array<int,array<string,mixed>>,navigation:array<int,array<string,mixed>>}
	 */
	public static function claim_context( array $form, array $plan ): array {
		$selectors = array();
		// Copy-only nodes may already be split into the source context graph.
		$nodes = array_merge(
			is_array( $form['layout_graph']['nodes'] ?? null ) ? $form['layout_graph']['nodes'] : array(),
			is_array( $form['source_context_graph']['nodes'] ?? null ) ? $form['source_context_graph']['nodes'] : array()
		);
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && in_array( $node['id'] ?? null, array( $plan['completion'], $plan['navigation'] ), true ) && is_string( $node['source']['selector'] ?? null ) ) {
				$selectors[ $node['id'] === $plan['completion'] ? 'completion' : 'navigation' ] = $node['source']['selector'];
			}
		}
		$claimed = array(
			'completion' => array(),
			'navigation' => array(),
		);
		foreach ( array( 'context_before', 'context_after' ) as $position ) {
			if ( ! is_array( $form['form'][ $position ] ?? null ) ) {
				continue;
			}
			$kept = array();
			foreach ( $form['form'][ $position ] as $item ) {
				$selector = is_array( $item ) && is_string( $item['source_selector'] ?? null ) ? $item['source_selector'] : '';
				$owner    = null;
				foreach ( $selectors as $role => $root ) {
					if ( '' !== $selector && ( $selector === $root || str_starts_with( $selector, $root . ' > ' ) ) ) {
						$owner = $role;
					}
				}
				if ( null === $owner ) {
					$kept[] = $item;
					continue;
				}
				$claimed[ $owner ][] = $item;
			}
			$form['form'][ $position ] = $kept;
		}
		// Their source boxes now belong to the confirmation and the navigation.
		if ( is_array( $form['source_context_graph']['nodes'] ?? null ) ) {
			$owned = array();
			foreach ( $form['source_context_graph']['nodes'] as $index => $node ) {
				$selector = is_array( $node ) && is_string( $node['source']['selector'] ?? null ) ? $node['source']['selector'] : '';
				foreach ( $selectors as $root ) {
					if ( '' !== $selector && ( $selector === $root || str_starts_with( $selector, $root . ' > ' ) ) ) {
						$owned[ (string) $node['id'] ] = true;
						unset( $form['source_context_graph']['nodes'][ $index ] );
					}
				}
			}
			$form['source_context_graph']['nodes']    = array_values( $form['source_context_graph']['nodes'] );
			$form['source_context_graph']['variants'] = array_values( array_filter( is_array( $form['source_context_graph']['variants'] ?? null ) ? $form['source_context_graph']['variants'] : array(), static fn ( $variant ): bool => ! is_array( $variant ) || ! isset( $owned[ $variant['node'] ?? '' ] ) ) );
		}
		return array(
			'form'       => $form,
			'completion' => $claimed['completion'],
			'navigation' => $claimed['navigation'],
		);
	}

	/**
	 * Clear layout facts the step blocks represent, so the generic topology
	 * projection neither transposes nor reports them.
	 *
	 * @param array<string,mixed> $form     Form row.
	 * @param array<int,string>   $node_ids Layout graph node ids.
	 * @return array<string,mixed>
	 */
	public static function without_layout( array $form, array $node_ids ): array {
		if ( ! is_array( $form['layout_graph']['nodes'] ?? null ) || array() === $node_ids ) {
			return $form;
		}
		$owned = array_fill_keys( $node_ids, true );
		foreach ( $form['layout_graph']['nodes'] as $index => $node ) {
			if ( is_array( $node ) && isset( $owned[ $node['id'] ?? '' ] ) ) {
				$form['layout_graph']['nodes'][ $index ]['layout']     = array();
				$form['layout_graph']['nodes'][ $index ]['provenance'] = array();
			}
		}
		$form['layout_graph']['variants'] = array_values( array_filter( is_array( $form['layout_graph']['variants'] ?? null ) ? $form['layout_graph']['variants'] : array(), static fn ( $variant ): bool => ! is_array( $variant ) || ! isset( $owned[ $variant['node'] ?? '' ] ) ) );
		return $form;
	}

	/**
	 * Lift the track and step panels out of the control topology: their children
	 * sit directly in the provider step, so the generic projection sees each step's
	 * fields as form-level siblings and never mistakes the track for the form box.
	 *
	 * @param array<string,mixed> $form     Form row.
	 * @param array<int,string>   $node_ids Wrapper ids to lift.
	 * @return array<string,mixed>
	 */
	public static function without_wrappers( array $form, array $node_ids ): array {
		$nodes = $form['control_topology']['nodes'] ?? null;
		if ( ! is_array( $nodes ) || array() === $node_ids ) {
			return $form;
		}
		$lifted  = array_fill_keys( $node_ids, true );
		$parents = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$parents[ $node['id'] ] = is_string( $node['parent'] ?? null ) ? $node['parent'] : null;
			}
		}
		$kept   = array();
		$orders = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || isset( $lifted[ $node['id'] ?? '' ] ) ) {
				continue;
			}
			$parent = $node['parent'] ?? null;
			$depth  = (int) ( $node['depth'] ?? 0 );
			for ( $ancestor = $parent; is_string( $ancestor ); $ancestor = $parents[ $ancestor ] ?? null ) {
				if ( isset( $lifted[ $ancestor ] ) ) {
					--$depth;
				}
			}
			while ( is_string( $parent ) && isset( $lifted[ $parent ] ) ) {
				$parent = $parents[ $parent ] ?? null;
			}
			$key = $parent ?? '$root';
			// Nodes arrive in document order, so renumbering keeps sibling order.
			$node['order'] = $orders[ $key ] ?? 0;
			$orders[ $key ] = $node['order'] + 1;
			$node['depth']  = max( 0, $depth );
			$node['parent'] = $parent;
			$kept[] = $node;
		}
		$form['control_topology']['nodes'] = $kept;
		return $form;
	}

	/**
	 * Split the provider's flat field blocks into their declared steps.
	 *
	 * @param array<int,array<string,mixed>> $blocks Provider field blocks in source order.
	 * @param array<string,mixed>            $plan   Step plan.
	 * @return array<int,array<int,array<string,mixed>>>|null One block list per step, or null when a block spans steps.
	 */
	public static function partition( array $blocks, array $plan ): ?array {
		$steps = array_fill( 0, count( $plan['steps'] ), array() );
		foreach ( $blocks as $block ) {
			$owners = array_values( array_unique( array_map( static fn ( int $control ): ?int => $plan['control_steps'][ $control ] ?? null, self::source_controls( $block ) ), SORT_REGULAR ) );
			if ( 1 !== count( $owners ) || ! is_int( $owners[0] ) ) {
				return null;
			}
			$steps[ $owners[0] ][] = $block;
		}
		foreach ( $steps as $step_blocks ) {
			if ( array() === $step_blocks ) {
				return null;
			}
		}
		return $steps;
	}

	/**
	 * Build the provider step blocks.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $step_blocks Field blocks per step.
	 * @param array<int,string>                         $step_hooks  Generated layout hook class per step.
	 * @param array<string,mixed>                       $submit      Provider submit button block.
	 * @param array<int,array<string,mixed>>            $navigation  Source navigation copy.
	 * @return array<int,array<string,mixed>>
	 */
	public static function blocks( array $step_blocks, array $step_hooks, array $submit, array $navigation ): array {
		$steps = array();
		foreach ( $step_blocks as $index => $blocks ) {
			$attrs = array();
			if ( '' !== ( $step_hooks[ $index ] ?? '' ) ) {
				$attrs['className'] = $step_hooks[ $index ];
			}
			$steps[] = array(
				'name'        => 'jetpack/form-step',
				'attrs'       => $attrs,
				'innerBlocks' => $blocks,
				'saved'       => array(
					'open'  => "\n<div class=\"" . htmlspecialchars( trim( 'wp-block-jetpack-form-step ' . ( $attrs['className'] ?? '' ) ), ENT_QUOTES ) . '">',
					'close' => "</div>\n",
				),
			);
		}
		$next_text = '';
		foreach ( $navigation as $item ) {
			if ( is_string( $item['text'] ?? null ) && '' !== trim( $item['text'] ) ) {
				$next_text = trim( $item['text'] );
				break;
			}
		}
		$submit['attrs']['className'] = trim( (string) ( $submit['attrs']['className'] ?? '' ) );
		return array(
			array(
				'name'        => 'jetpack/form-step-container',
				'attrs'       => array(),
				'innerBlocks' => $steps,
				'saved'       => array(
					'open'  => "\n<div class=\"jetpack-form-steps-wrapper\"><div class=\"wp-block-jetpack-form-step-container jetpack-form-step-container\">",
					'close' => "</div></div>\n",
				),
			),
			array(
				'name'  => 'jetpack/form-progress-indicator',
				'attrs' => array( 'variant' => 'dots' ),
			),
			array(
				'name'        => 'jetpack/form-step-navigation',
				'attrs'       => array(
					'layout' => array(
						'type'           => 'flex',
						'justifyContent' => 'right',
					),
				),
				'innerBlocks' => array(
					self::navigation_button( 'Back', 'is-style-outline form-button-previous is-previous', 'Previous button' ),
					self::navigation_button( '' !== $next_text ? $next_text : 'Next', 'form-button-next is-next', 'Next button' ),
					$submit,
				),
				'saved'       => array(
					'open'  => "\n<div class=\"wp-block-jetpack-form-step-navigation__wrapper\"><div class=\"wp-block-jetpack-form-step-navigation\">",
					'close' => "</div></div>\n",
				),
			),
		);
	}

	/**
	 * Map completion copy onto the provider's own confirmation message.
	 *
	 * @param array<int,array<string,mixed>> $completion Source completion copy.
	 * @return array<string,string|bool>
	 */
	public static function confirmation_attributes( array $completion ): array {
		$heading = '';
		$message = array();
		foreach ( $completion as $item ) {
			$text = is_string( $item['text'] ?? null ) ? trim( $item['text'] ) : '';
			if ( '' === $text ) {
				continue;
			}
			if ( '' === $heading && 'heading' === ( $item['type'] ?? null ) ) {
				$heading = $text;
				continue;
			}
			$message[] = $text;
		}
		if ( '' === $heading && array() === $message ) {
			return array();
		}
		// The source panel is the whole confirmation, so the provider's
		// submission summary would be copy the source never showed.
		return array_merge(
			array(
				'confirmationType' => 'text',
				'disableSummary'   => true,
			),
			array_filter(
				array(
					'customThankyouHeading' => $heading,
					'customThankyouMessage' => implode( "\n\n", $message ),
				),
				static fn ( string $value ): bool => '' !== $value
			)
		);
	}

	/**
	 * Remove the source-control bookkeeping key from generated blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Generated blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function without_source_controls( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			unset( $blocks[ $index ][ self::CONTROL_KEY ] );
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $index ]['innerBlocks'] = self::without_source_controls( $block['innerBlocks'] );
			}
		}
		return $blocks;
	}

	/** @return array<int,int> */
	private static function source_controls( array $block ): array {
		$controls = is_int( $block[ self::CONTROL_KEY ] ?? null ) ? array( $block[ self::CONTROL_KEY ] ) : array();
		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $child ) {
			if ( is_array( $child ) ) {
				$controls = array_merge( $controls, self::source_controls( $child ) );
			}
		}
		return $controls;
	}

	/** @return array<string,mixed> */
	private static function navigation_button( string $text, string $class_name, string $name ): array {
		return array(
			'name'    => 'core/button',
			'attrs'   => array(
				'tagName'   => 'button',
				'className' => $class_name,
				'metadata'  => array( 'name' => $name ),
			),
			'content' => $text,
			'wrapper' => 'button',
		);
	}
}
