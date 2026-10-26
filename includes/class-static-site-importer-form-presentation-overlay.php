<?php
/**
 * Bounded provider field presentation maps and scoped overlay CSS.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles readable form field presentation into bounded, scoped overlay CSS.
 *
 * @phpstan-type Surface array{prefix:string,css:array{key:string,label:string,rule:string},context:array{key:string,label:string,rule:string},control_container_chrome:bool,front_end_form_dom:bool,form_box_initials:array<string,string>,native_passthrough:array<string,string>,form_passthrough:array<int,array{suffix:string,declarations:string,strategy:string}>,form_layout_host:array{declarations:string,strategy:string}|null,field_control:array{suffix:string,properties:array<int,string>}|null,container_passthrough:array<int,array{suffix:string,declarations:string,strategy:string}>}
 * A compile pass accumulates one surface's declared surface, scope, targets,
 * presentation targets, rules, chrome, operations, losses and context CSS.
 *
 * @phpstan-type Surface_Pass array<string,mixed>
 */
class Static_Site_Importer_Form_Presentation_Overlay {
	public const MAP_SCHEMA             = 'generic/provider-layout-target-map/v1';
	public const OVERLAY_SCHEMA         = 'static-site-importer/provider-layout-overlay/v1';
	private const BASE_OVERLAY_BYTES    = 16384;
	private const BYTES_PER_MAPPED_NODE = 1024;
	private const MAX_OVERLAY_BYTES     = 65536;
	/** Authored values such as system font stacks exceed a few hundred bytes; bounded like the producer contract. */
	private const MAX_PRESENTATION_VALUE_BYTES = 1024;
	// A source stylesheet can author a media feature with the legacy `min-width:`/
	// `max-width:` prefix syntax or the modern comparison range syntax
	// (`width >= 40rem`) - Tailwind v4 emits every default breakpoint with the
	// latter. Both spellings resolve to the same condition, so both are admitted.
	private const MEDIA_FEATURE_QUERY = '(?:screen and )?\((?:(?:min|max)-(?:width|height): ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh)|(?:width|height) ?(?:>=|<=|>|<) ?[0-9]+(?:\.[0-9]+)?(?:px|em|rem|vw|vh))\)';

	public const SURFACE_FRONT_END = 'front_end';
	public const SURFACE_EDITOR    = 'editor';

	/** Capabilities a provider target needs for the overlay to own its full layout. */
	public const LAYOUT_CAPABILITIES = array( 'container_layout', 'direct_child_layout', 'item_layout', 'responsive_layout' );

	/** Selectors retained by the persisted overlay validator. */
	private const NATIVE_SUBMIT_LINK = ' :where(.wp-block-button.form-button-submit) > .wp-block-button__link';
	private const NATIVE_PASSTHROUGH = array(
		' .ssi-native-control-shell-wrap'      => 'display:contents',
		' .wp-block-button.form-button-submit' => 'display:contents',
	);

	/** Front-end and editor field-presentation surfaces. */
	private const SURFACES = array(
		self::SURFACE_FRONT_END => array(
			'prefix'                   => '',
			'css'                      => array(
				'key'   => 'css',
				'label' => 'provider layout overlay',
				'rule'  => 'compiled',
			),
			'context'                  => array(
				'key'   => 'context_css',
				'label' => 'form context fallback',
				'rule'  => 'compiled',
			),
			'control_container_chrome' => false,
			'front_end_form_dom'       => true,
			'form_box_initials'        => array(),
			'native_passthrough'       => array(),
			'form_passthrough'         => array(),
			'form_layout_host'         => null,
			'field_control'            => null,
			'container_passthrough'    => array(
				array(
					'suffix'       => '.jetpack-contact-form-container > form.jetpack-contact-form__form:has(> .wp-block-jetpack-contact-form):not(.submission-success)',
					'declarations' => 'display:contents',
					'strategy'     => 'provider_front_end_form_passthrough',
				),
			),
		),
		self::SURFACE_EDITOR    => array(
			'prefix'                   => '.editor-styles-wrapper ',
			'css'                      => array(
				'key'   => 'editor_css',
				'label' => 'editor control chrome',
				'rule'  => 'editor',
			),
			'context'                  => array(
				'key'   => 'editor_context_css',
				'label' => 'editor form context fallback',
				'rule'  => 'context',
			),
			'control_container_chrome' => true,
			'front_end_form_dom'       => false,
			'form_box_initials'        => array( 'box_sizing' => 'content-box' ),
			'native_passthrough'       => array(
				' .ssi-native-control-shell' => 'display:contents',
				'.ssi-native-form-topology'  => 'padding:0;border:0',
			),
			'form_passthrough'         => array(
				array(
					'suffix'       => ' > div.jetpack-contact-form',
					'declarations' => 'display:contents',
					'strategy'     => 'provider_editor_inner_blocks_passthrough',
				),
			),
			'form_layout_host'         => array(
				'declarations' => 'display:flex;flex-wrap:wrap;justify-content:flex-start;align-items:flex-start',
				'strategy'     => 'provider_editor_form_layout_host',
			),
			'field_control'            => array(
				'suffix'     => ' > div.jetpack-field__control',
				'properties' => array( 'display', 'flex-direction', 'flex-wrap', 'gap', 'row-gap', 'column-gap', 'align-items', 'align-content', 'justify-content', 'grid-template-columns', 'grid-template-rows' ),
			),
			'container_passthrough'    => array(),
		),
	);

	/** @return array{map?:array<string,mixed>,error?:string} */
	public static function validate_map( mixed $map, array $graph ): array {
		if ( ! is_array( $map ) || self::MAP_SCHEMA !== ( $map['schema'] ?? null ) || ! is_string( $map['provider'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $map['provider'] ) || ! is_string( $map['scope'] ?? null ) || ! preg_match( '/^\.ssi-form-[a-f0-9]{12}$/D', $map['scope'] ) || ! is_array( $map['targets'] ?? null ) || ! array_is_list( $map['targets'] ) || count( $map['targets'] ) > 128 || ! is_array( $map['presentation_targets'] ?? array() ) || ! array_is_list( $map['presentation_targets'] ?? array() ) || count( $map['presentation_targets'] ?? array() ) > 128 || array_diff( array_keys( $map ), array( 'schema', 'provider', 'scope', 'targets', 'presentation_targets' ) ) ) {
			return array( 'error' => 'provider layout target map is not a bounded canonical map.' );
		}
		$nodes = array();
		foreach ( $graph['nodes'] ?? array() as $node ) {
			if ( is_array( $node ) && is_string( $node['id'] ?? null ) ) {
				$nodes[ $node['id'] ] = true;
			}
		}
		$seen    = array();
		$targets = array();
		foreach ( $map['targets'] as $target ) {
			if ( ! is_array( $target ) || array_diff( array_keys( $target ), array( 'node', 'selector', 'capabilities' ) ) || ! is_string( $target['node'] ?? null ) || ! isset( $nodes[ $target['node'] ] ) || isset( $seen[ $target['node'] ] ) || ! is_string( $target['selector'] ?? null ) || ! self::safe_selector( $target['selector'], $map['scope'] ) || ! is_array( $target['capabilities'] ?? null ) || ! array_is_list( $target['capabilities'] ) || array_diff( $target['capabilities'], self::LAYOUT_CAPABILITIES ) ) {
				return array( 'error' => 'provider layout target map contains an unsafe target.' );
			}
			$seen[ $target['node'] ] = true;
			$targets[]               = array(
				'node'         => $target['node'],
				'selector'     => $target['selector'],
				'capabilities' => array_values( array_unique( $target['capabilities'] ) ),
			);
		}
		$presentation_targets = array();
		$seen_presentations   = array();
		foreach ( $map['presentation_targets'] ?? array() as $target ) {
			if ( ! is_array( $target ) || ! is_int( $target['index'] ?? null ) || $target['index'] < 0 || $target['index'] >= 128 || isset( $seen_presentations[ $target['index'] ] ) ) {
				return array( 'error' => 'provider presentation target map contains an unsafe target.' );
			}
			$destinations = $target['destinations'] ?? array();
			if ( ! self::has_only_keys( $target, array( 'index', 'control', 'label', 'destinations' ) ) || ! is_array( $destinations ) || ! array_is_list( $destinations ) || empty( $destinations ) || count( $destinations ) > 8 ) {
				return array( 'error' => 'provider presentation target map contains an unsafe target.' );
			}
			$clean = array(
				'index'        => $target['index'],
				'destinations' => array(),
			);
			foreach ( $destinations as $destination ) {
				if ( ! is_array( $destination ) || ! self::has_only_keys( $destination, array( 'role', 'selector', 'properties', 'aliases', 'resets', 'priority' ) ) || ! in_array( $destination['priority'] ?? '', array( '', 'important' ), true ) || ! in_array( $destination['role'] ?? null, array( 'control', 'label', 'required_marker', 'control_container' ), true ) || ! is_string( $destination['selector'] ?? null ) || ! self::safe_selector( $destination['selector'], $map['scope'] ) || ! is_array( $destination['properties'] ?? null ) || ! array_is_list( $destination['properties'] ) || ( empty( $destination['properties'] ) && empty( $destination['resets'] ) ) || count( $destination['properties'] ) > count( self::presentation_property_map() ) || array_diff( $destination['properties'], array_keys( self::presentation_property_map() ) ) || ! self::safe_presentation_aliases( $destination['aliases'] ?? array(), $destination['properties'] ) || ! self::safe_presentation_resets( $destination['resets'] ?? array() ) ) {
					return array( 'error' => 'provider presentation target map contains an unsafe destination.' );
				}
				$clean['destinations'][] = array_filter( array(
					'role'       => $destination['role'],
					'selector'   => $destination['selector'],
					'properties' => array_values( array_unique( $destination['properties'] ) ),
					'aliases'    => empty( $destination['aliases'] ) ? null : $destination['aliases'],
					'resets'     => empty( $destination['resets'] ) ? null : $destination['resets'],
					'priority'   => $destination['priority'] ?? null,
				), static fn( $value ): bool => null !== $value );
			}
			$seen_presentations[ $target['index'] ] = true;
			$presentation_targets[]                 = $clean;
		}
		return array(
			'map' => array(
				'schema'               => self::MAP_SCHEMA,
				'provider'             => $map['provider'],
				'scope'                => $map['scope'],
				'targets'              => $targets,
				'presentation_targets' => $presentation_targets,
			),
		);
	}

	/**
	 * Compile one declared surface of the overlay from that surface's own target map.
	 *
	 * The pass builds only the requested surface's rules, each addressed through
	 * the surface prefix where it is built. `overlay` carries the surface's own
	 * artifacts in the canonical overlay shape; `css` is the front-end stylesheet.
	 *
	 * @param array<string,mixed>     $graph              Provider layout graph.
	 * @param mixed                   $map                The surface's provider layout target map.
	 * @param array<string,mixed>     $presentation_graph Validated presentation graph.
	 * @param string                  $surface            A declared surface name (see SURFACES).
	 * @return array{overlay:array<string,mixed>,css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>}
	 */
	public static function compile( array $graph, mixed $map, array $presentation_graph = array(), string $surface = self::SURFACE_FRONT_END ): array {
		$declared      = self::surface( $surface );
		$validated     = self::validate_map( $map, $graph );
		$validated_map = $validated['map'] ?? null;
		if ( isset( $validated['error'] ) || ! is_array( $validated_map ) || ! isset( $validated_map['targets'] ) || ! is_array( $validated_map['targets'] ) ) {
			return self::failed_compile( $validated['error'] ?? 'provider layout target map could not be normalized.' );
		}
		$pass = self::open_pass( $declared, $validated_map );
		self::compile_control_containers( $pass, $presentation_graph );
		$base_presented = self::compile_controls( $pass, $presentation_graph );
		self::compile_variant_resets( $pass, $presentation_graph, $base_presented );
		self::compile_presentation_variants( $pass, $presentation_graph );
		return self::close_pass( $pass );
	}

	/**
	 * Compile field presentation on the front end and in the editor. The front
	 * end may omit facts its live source rules already carry.
	 */
	public static function compile_surfaces( array $graph, mixed $front_map, mixed $editor_map, array $presentation_graph = array(), ?array $front_presentation_graph = null ): array {
		$front  = self::compile( $graph, $front_map, $front_presentation_graph ?? $presentation_graph, self::SURFACE_FRONT_END );
		$editor = self::compile( $graph, $editor_map, $presentation_graph, self::SURFACE_EDITOR );
		$losses = array_merge( $editor['losses'], $front['losses'] );
		if ( array() === $front['overlay'] ) {
			return array(
				'overlay'    => array(),
				'css'        => '',
				'operations' => $front['operations'],
				'losses'     => $losses,
			);
		}
		return array(
			'overlay'    => self::overlay(
				array(
					'css'                => $front['css'],
					'editor_css'         => self::artifact( $editor['overlay'], 'editor_css' ),
					'context_css'        => self::artifact( $front['overlay'], 'context_css' ),
					'editor_context_css' => self::artifact( $editor['overlay'], 'editor_context_css' ),
				)
			),
			'css'        => $front['css'],
			'operations' => $front['operations'],
			'losses'     => $losses,
		);
	}

	/**
	 * Open one surface's compile pass: its targets, its context fallback, and the
	 * rule, chrome, operation, and loss lists every phase appends to.
	 *
	 * @param array                   $declared          Declared surface (see SURFACES).
	 * @param array<string,mixed>     $validated_map     Validated target map.
	 * @return Surface_Pass
	 * @phpstan-param Surface $declared
	 */
	private static function open_pass( array $declared, array $validated_map ): array {
		$targets = array();
		foreach ( $validated_map['targets'] as $target ) {
			$targets[ $target['node'] ] = $target;
		}
		$operations     = array();
		$context_losses = array();
		$context_css    = '';
		return array(
			'declared'             => $declared,
			'scope'                => $validated_map['scope'],
			'targets'              => $targets,
			'presentation_targets' => array_column( $validated_map['presentation_targets'] ?? array(), null, 'index' ),
			'rules'                => array(),
			'chrome'               => array(),
			'operations'           => $operations,
			// Receipt contract: every pass records the context fallback losses once
			// per context artifact the overlay carries (front end and editor).
			'losses'               => array_merge( $context_losses, $context_losses ),
			'context_css'          => $context_css,
		);
	}

	/**
	 * Close a pass: compact its rules, bound its artifacts, and build the result.
	 *
	 * @param array $pass Compile pass.
	 * @return array{overlay:array<string,mixed>,css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>}
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function close_pass( array $pass ): array {
		$declared = $pass['declared'];
		// Keep repeated rules until compaction can check intervening cascade barriers.
		$rules = self::compact_rules( array_merge( $pass['chrome'], $pass['rules'] ) );
		$css   = empty( $rules ) ? '' : '/* Static Site Importer ' . $declared['css']['label'] . ': ' . substr( hash( 'sha256', implode( "\n", $rules ) ), 0, 12 ) . " */\n" . implode( "\n", $rules ) . "\n";
		if ( strlen( $css ) + strlen( $pass['context_css'] ) > self::overlay_byte_limit( $css ) ) {
			return self::failed_compile( 'provider layout overlay exceeds its bounded size.' );
		}
		$artifacts = array(
			$declared['css']['key']     => $css,
			$declared['context']['key'] => $pass['context_css'],
		);
		return array(
			'overlay'    => self::overlay( $artifacts ),
			'css'        => $artifacts['css'] ?? '',
			'operations' => $pass['operations'],
			'losses'     => $pass['losses'],
		);
	}

	/**
	 * The presentation destinations a control index maps for one role.
	 *
	 * @param array $pass  Compile pass.
	 * @param mixed $index Control index (anything but an int maps none).
	 * @param mixed $role  Destination role.
	 * @return array<int,array<string,mixed>>
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function destinations_for( array $pass, mixed $index, mixed $role ): array {
		if ( ! is_int( $index ) ) {
			return array();
		}
		return array_filter( $pass['presentation_targets'][ $index ]['destinations'] ?? array(), static fn( array $destination ): bool => $role === $destination['role'] );
	}

	/**
	 * Compile presentation destinations into the pass.
	 *
	 * @param array                          $pass         Compile pass.
	 * @param array<int,array<string,mixed>> $destinations Destinations.
	 * @param array<string,mixed>            $styles       Resolved styles.
	 * @param int                            $index        Control index.
	 * @param string                         $role         Destination role.
	 * @param array<string,mixed>|null       $condition    Variant condition.
	 * @param bool                           $emit_resets  Whether base resets are emitted.
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function present( array &$pass, array $destinations, array $styles, int $index, string $role, ?array $condition, bool $emit_resets = true ): void {
		self::compile_presentation_destinations( $destinations, $styles, $index, $role, $condition, $pass['declared']['prefix'], $pass['rules'], $pass['operations'], $pass['losses'], $emit_resets );
	}

	/**
	 * Present each control container's base styles, with its surface chrome.
	 *
	 * @param array               $pass               Compile pass.
	 * @param array<string,mixed> $presentation_graph Validated presentation graph.
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function compile_control_containers( array &$pass, array $presentation_graph ): void {
		foreach ( $presentation_graph['control_containers'] ?? array() as $control_container ) {
			$index        = $control_container['index'] ?? null;
			$destinations = self::destinations_for( $pass, $index, 'control_container' );
			if ( ! is_int( $index ) || empty( $destinations ) || ! is_array( $control_container['styles'] ?? null ) ) {
				$pass['losses'][] = self::presentation_loss( 'control_container_unsupported', is_int( $index ) ? $index : 0, 'control_container' );
				continue;
			}
			$styles = self::resolve_presentation_cascade( $control_container['styles'], $control_container['provenance'] ?? array() );
			self::present( $pass, $destinations, $styles, $index, 'control_container', null );
			foreach ( $destinations as $destination ) {
				self::control_container_chrome( $pass['declared'], $destination, $control_container['styles'], $index, null, $pass['chrome'], $pass['losses'] );
			}
		}
	}

	/**
	 * Present each control's base control, label, and required-marker styles.
	 *
	 * @param array               $pass               Compile pass.
	 * @param array<string,mixed> $presentation_graph Validated presentation graph.
	 * @return array<string,true> "index\nrole" keys that received a base pass.
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function compile_controls( array &$pass, array $presentation_graph ): array {
		$base_presented = array();
		foreach ( $presentation_graph['controls'] ?? array() as $control ) {
			if ( ! is_array( $control ) || ! is_int( $control['index'] ?? null ) ) {
				continue;
			}
			foreach ( array( 'control', 'label', 'required_marker' ) as $role ) {
				if ( ! isset( $control[ $role ]['styles'] ) || ! is_array( $control[ $role ]['styles'] ) ) {
					continue;
				}
				$destinations = self::destinations_for( $pass, $control['index'], $role );
				if ( empty( $destinations ) ) {
					$pass['losses'][] = self::presentation_loss( 'provider_structure_mismatch', $control['index'], $role );
					continue;
				}
				$styles = self::resolve_presentation_cascade( $control[ $role ]['styles'], $control[ $role ]['provenance'] ?? array() );
				self::present( $pass, $destinations, $styles, $control['index'], $role, null );
				$base_presented[ $control['index'] . "\n" . $role ] = true;
			}
		}
		return $base_presented;
	}

	/**
	 * Emit provider-default resets for destinations that only responsive variants style.
	 *
	 * A responsive capture can scope every presentation fact to a media query,
	 * leaving a destination with no unconditional pass. Its provider-default
	 * resets still belong outside every query, ahead of the conditional patches
	 * that may override them, exactly as they would beside a base style set.
	 *
	 * @param array               $pass               Compile pass.
	 * @param array<string,mixed> $presentation_graph Validated presentation graph.
	 * @param array<string,true>  $base_presented     "index\nrole" keys that received a base pass.
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function compile_variant_resets( array &$pass, array $presentation_graph, array $base_presented ): void {
		foreach ( $presentation_graph['variants'] ?? array() as $variant ) {
			$index = $variant['index'] ?? null;
			$role  = $variant['role'] ?? null;
			if ( ! is_int( $index ) || ! in_array( $role, array( 'control', 'label', 'required_marker' ), true ) || isset( $base_presented[ $index . "\n" . $role ] ) ) {
				continue;
			}
			$base_presented[ $index . "\n" . $role ] = true;
			$destinations                            = array_filter( self::destinations_for( $pass, $index, $role ), static fn( array $destination ): bool => ! empty( $destination['resets'] ) );
			if ( ! empty( $destinations ) ) {
				self::present( $pass, $destinations, array(), $index, $role, null );
			}
		}
	}

	/**
	 * Present each responsive presentation patch, without repeating base resets.
	 *
	 * @param array               $pass               Compile pass.
	 * @param array<string,mixed> $presentation_graph Validated presentation graph.
	 * @phpstan-param Surface_Pass $pass
	 */
	private static function compile_presentation_variants( array &$pass, array $presentation_graph ): void {
		foreach ( $presentation_graph['variants'] ?? array() as $variant ) {
			$index = $variant['index'] ?? null;
			$role  = $variant['role'] ?? null;
			// Inline SVG parts are rendered by the companion's field-state projection.
			// They retain their source precedence in the validated v2 graph but have no
			// generic provider CSS destination here.
			if ( in_array( $role, array( 'visual_part', 'visual_group' ), true ) ) {
				continue;
			}
			// A producer can keep a stylesheet's implicit `media="all"` wrapper
			// (a device-split capture tags each device sheet that way). It holds at
			// every width, so its patch is unconditional rather than a lost variant.
			// It is still a patch: the base pass (or the reset pass above) already
			// released provider defaults, so it must not repeat those resets after
			// the base rule and undo the authored values there.
			$condition = self::is_media_all( $variant['condition'] ?? null ) ? null : ( $variant['condition'] ?? null );
			if ( 'control_container' === $role ) {
				$destinations = self::destinations_for( $pass, $index, 'control_container' );
				if ( ! is_int( $index ) || empty( $destinations ) || ( null !== $condition && ! self::safe_condition( $condition ) ) || ! is_array( $variant['style_patch'] ?? null ) ) {
					$pass['losses'][] = self::presentation_loss( 'control_container_unsupported', is_int( $index ) ? $index : 0, 'control_container' );
					continue;
				}
				foreach ( $destinations as $destination ) {
					$styles = self::resolve_presentation_cascade( $variant['style_patch'], $variant['provenance'] ?? array(), $variant['precedence'] ?? array() );
					self::present( $pass, array( $destination ), $styles, $index, 'control_container', $condition, false );
					self::control_container_chrome( $pass['declared'], $destination, $variant['style_patch'], $index, $condition, $pass['chrome'], $pass['losses'] );
				}
				continue;
			}
			$destinations = self::destinations_for( $pass, $index, $role );
			if ( ! is_int( $index ) || ! in_array( $role, array( 'control', 'label', 'required_marker' ), true ) || empty( $destinations ) || ( null !== $condition && ! self::safe_condition( $condition ) ) || ! is_array( $variant['style_patch'] ?? null ) ) {
				$pass['losses'][] = self::presentation_loss( 'responsive_layout_ownership', is_int( $index ) ? $index : 0, is_string( $role ) ? $role : 'control' );
				continue;
			}
			$styles = self::resolve_presentation_cascade( $variant['style_patch'], $variant['provenance'] ?? array(), $variant['precedence'] ?? array() );
			self::present( $pass, $destinations, $styles, $index, $role, $condition, false );
		}
	}

	/**
	 * Look up a declared surface.
	 *
	 * @param string $name Surface name.
	 * @return Surface
	 * @throws InvalidArgumentException When the surface is not declared.
	 */
	private static function surface( string $name ): array {
		if ( ! isset( self::SURFACES[ $name ] ) ) {
			// ssi-error-class: programmer
			throw new InvalidArgumentException( 'Unknown provider layout overlay surface.' );
		}
		return self::SURFACES[ $name ];
	}

	/**
	 * Address a selector (or a two-part selector list) inside a surface.
	 *
	 * @param string $prefix   Surface selector prefix.
	 * @param string $selector Scoped selector.
	 */
	private static function surface_selector( string $prefix, string $selector ): string {
		if ( '' === $prefix ) {
			return $selector;
		}
		return implode( ', ', array_map( static fn( string $part ): string => 1 === preg_match( '/^\.ssi-form-[a-f0-9]{12}/', $part ) ? $prefix . $part : $part, explode( ', ', $selector ) ) );
	}

	/**
	 * Emit one control container's authored declarations, unreset, at its prefixed
	 * destination on a chrome surface.
	 *
	 * Every pass validates these declarations, so each records their losses once
	 * more (the receipt contract); only a surface that declares chrome emits it.
	 *
	 * @param array                    $declared    Declared surface (see SURFACES).
	 * @param array<string,mixed>      $destination Control-container destination.
	 * @param array<string,mixed>      $styles      Authored styles.
	 * @param int                      $index       Control index.
	 * @param array<string,mixed>|null $condition   Variant condition.
	 * @param array<int,string>        $chrome      Chrome rules, appended to.
	 * @param array<mixed>             $losses      Losses, appended to.
	 * @phpstan-param Surface $declared
	 */
	private static function control_container_chrome( array $declared, array $destination, array $styles, int $index, ?array $condition, array &$chrome, array &$losses ): void {
		$declarations = self::presentation_declarations( $styles, $index, 'control_container', $losses, $destination['properties'] );
		if ( ! $declared['control_container_chrome'] || empty( $declarations ) ) {
			return;
		}
		$rule     = self::surface_selector( $declared['prefix'], self::authoritative_presentation_selector( $destination['selector'] ) ) . '{' . implode( ';', $declarations ) . '}';
		$chrome[] = null === $condition ? $rule : self::conditional_rule( $condition, $rule );
	}

	/**
	 * Assemble compiled artifacts into the canonical overlay with its integrity fields.
	 *
	 * @param array<string,string> $artifacts Artifact key (`css`, `editor_css`, `context_css`, `editor_context_css`) => compiled CSS.
	 * @return array<string,mixed>
	 */
	private static function overlay( array $artifacts ): array {
		if ( '' === implode( '', $artifacts ) ) {
			return array();
		}
		$css        = $artifacts['css'] ?? '';
		$editor_css = $artifacts['editor_css'] ?? '';
		$overlay    = array(
			'schema'        => self::OVERLAY_SCHEMA,
			'css'           => $css,
			'editor_css'    => $editor_css,
			'sha256'        => hash( 'sha256', $css ),
			'bytes'         => strlen( $css ),
			'editor_sha256' => hash( 'sha256', $editor_css ),
			'editor_bytes'  => strlen( $editor_css ),
		);
		foreach ( array( 'context', 'editor_context' ) as $key ) {
			$value = $artifacts[ $key . '_css' ] ?? '';
			if ( '' !== $value ) {
				$overlay[ $key . '_css' ]    = $value;
				$overlay[ $key . '_sha256' ] = hash( 'sha256', $value );
				$overlay[ $key . '_bytes' ]  = strlen( $value );
			}
		}
		return $overlay;
	}

	/**
	 * Read one compiled artifact from an overlay.
	 *
	 * @param array<string,mixed> $overlay Overlay.
	 * @param string              $key     Artifact key.
	 */
	private static function artifact( array $overlay, string $key ): string {
		$value = $overlay[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * A compile that degrades to one recorded structure loss.
	 *
	 * @param string $error Map or bound error.
	 * @return array{overlay:array<string,mixed>,css:string,operations:array<int,array<string,mixed>>,losses:array<int,array<string,mixed>>}
	 */
	private static function failed_compile( string $error ): array {
		return array(
			'overlay'    => array(),
			'css'        => '',
			'operations' => array(),
			'losses'     => array(
				array(
					'dimension'   => 'layout',
					'reason_code' => 'provider_structure_mismatch',
					'map_error'   => $error,
				),
			),
		);
	}

	/**
	 * Merge equivalent rules only across provably disjoint mapped node selectors.
	 *
	 * @param string[] $rules Compiled rules in cascade order.
	 * @return string[]
	 */
	private static function compact_rules( array $rules ): array {
		$compacted = array();
		$previous  = null;
		$parsed    = array();
		foreach ( $rules as $rule ) {
			if ( ! preg_match( '/^((?:@(?:media|container) [^{}]+\{)*)([^{}]+)\{([^{}]*)\}(\}*)$/D', $rule, $parts ) || substr_count( $parts[1], '{' ) !== strlen( $parts[4] ) ) {
				$compacted[] = $rule;
				$previous    = null;
				$parsed[]    = null;
				continue;
			}
			// Move this selector left only while every intervening rule is disjoint.
			// Unknown selectors, shared nodes, shorthands and at-rule interactions
			// are barriers; no property-independence assumption is needed.
			$merged = false;
			for ( $index = count( $parsed ) - 1; $index >= 0; --$index ) {
				$candidate = $parsed[ $index ];
				if ( null === $candidate ) {
					break;
				}
				if ( $candidate[1] === $parts[1] && $candidate[3] === $parts[3] && $candidate[4] === $parts[4] && substr_count( $candidate[2] . ', ' . $parts[2], ', ' ) < 128 ) {
					$candidate[2]       .= ', ' . $parts[2];
					$parsed[ $index ]    = $candidate;
					$compacted[ $index ] = $candidate[1] . $candidate[2] . '{' . $candidate[3] . '}' . $candidate[4];
					$previous            = end( $parsed );
					$merged              = true;
					break;
				}
				if ( ! self::disjoint_node_selectors( $parts[2], $candidate[2] ) ) {
					break;
				}
			}
			if ( $merged ) {
				continue;
			}
			if ( null !== $previous && $previous[3] === $parts[3] ) {
				if ( $previous[1] === $parts[1] && $previous[4] === $parts[4] && substr_count( $previous[2] . ', ' . $parts[2], ', ' ) < 128 ) {
					$previous[2] .= ', ' . $parts[2];
				} elseif ( $previous[2] === $parts[2] && '}' === $previous[4] && '}' === $parts[4] && preg_match( '/^@media ([^{}]+)\{$/D', $previous[1], $left ) && preg_match( '/^@media ([^{}]+)\{$/D', $parts[1], $right ) && substr_count( $left[1] . ',' . $right[1], ',' ) < 128 ) {
					$previous[1] = '@media ' . $left[1] . ',' . $right[1] . '{';
				} else {
					$previous = null;
				}
				if ( null !== $previous ) {
					$parsed[ count( $parsed ) - 1 ]       = $previous;
					$compacted[ count( $compacted ) - 1 ] = $previous[1] . $previous[2] . '{' . $previous[3] . '}' . $previous[4];
					continue;
				}
			}
			$compacted[] = $rule;
			$previous    = $parts;
			$parsed[]    = $parts;
		}
		return $compacted;
	}

	/**
	 * Only simple, per-node boxes prove that two selector lists cannot compete.
	 *
	 * @param string $left  First selector list.
	 * @param string $right Second selector list.
	 * @return bool
	 */
	private static function disjoint_node_selectors( string $left, string $right ): bool {
		$nodes = array();
		foreach ( array( $left, $right ) as $selectors ) {
			$group = array();
			foreach ( explode( ', ', $selectors ) as $selector ) {
				if ( ! preg_match( '/^(?:\.editor-styles-wrapper )?\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? (?:input)?\.(ssi-node-[a-f0-9]{12})(?:-(?:wrap|destination-[a-z][a-z0-9-]{0,31}))?$/D', $selector, $match ) ) {
					return false;
				}
				$group[] = $match[1];
			}
			$nodes[] = $group;
		}
		return ! array_intersect( $nodes[0], $nodes[1] );
	}

	/**
	 * Budget follows mapped node hooks, not selector repetitions or supplied metadata.
	 * The same calculation admits front-end and editor artifacts, including context.
	 * Base 16 KiB + 1 KiB per unique mapped node, capped at 64 KiB per surface.
	 *
	 * @param string $css Compiled surface CSS.
	 * @return int
	 */
	private static function overlay_byte_limit( string $css ): int {
		preg_match_all( '/\.ssi-node-[a-f0-9]{12}(?![a-f0-9])/', $css, $nodes );
		return min( self::MAX_OVERLAY_BYTES, self::BASE_OVERLAY_BYTES + count( array_unique( $nodes[0] ) ) * self::BYTES_PER_MAPPED_NODE );
	}

	/** CSS properties a form context rule may declare, keyed by fact name. */

	/** Validate a compiler-produced overlay before it is admitted to a stylesheet. */
	public static function validate_overlay( mixed $overlay ): ?array {
		if ( ! is_array( $overlay ) ) {
			return null;
		}
		$expected_keys = isset( $overlay['editor_css'] ) ? array( 'schema', 'css', 'editor_css', 'sha256', 'bytes', 'editor_sha256', 'editor_bytes' ) : array( 'schema', 'css', 'sha256', 'bytes' );
		foreach ( array( array( 'context_css', 'context_sha256', 'context_bytes' ), array( 'editor_context_css', 'editor_context_sha256', 'editor_context_bytes' ) ) as $optional_keys ) {
			if ( isset( $overlay[ $optional_keys[0] ] ) ) {
				if ( ! is_string( $overlay[ $optional_keys[0] ] ) || ! is_string( $overlay[ $optional_keys[1] ] ?? null ) || ! is_int( $overlay[ $optional_keys[2] ] ?? null ) ) {
					return null;
				}
				$expected_keys = array_merge( $expected_keys, $optional_keys );
			}
		}
		if ( array_keys( $overlay ) !== $expected_keys || self::OVERLAY_SCHEMA !== ( $overlay['schema'] ?? null ) || ! is_string( $overlay['css'] ?? null ) || ( isset( $overlay['editor_css'] ) && ( ! is_string( $overlay['editor_css'] ) || ! is_string( $overlay['editor_sha256'] ?? null ) || ! is_int( $overlay['editor_bytes'] ?? null ) ) ) || ! is_string( $overlay['sha256'] ?? null ) || ! is_int( $overlay['bytes'] ?? null ) ) {
			return null;
		}
		// Integrity of each artifact, in order: `css`, `editor_css`, `context_css`,
		// `editor_context_css`. The front-end `css` is checked even when empty.
		foreach ( array( 'css', 'context' ) as $role ) {
			foreach ( array( self::SURFACE_FRONT_END, self::SURFACE_EDITOR ) as $surface ) {
				$key   = self::SURFACES[ $surface ][ $role ]['key'];
				$value = $overlay[ $key ] ?? '';
				if ( 'css' !== $key && '' === $value ) {
					continue;
				}
				$field  = substr( $key, 0, -strlen( 'css' ) );
				$sha256 = $overlay[ $field . 'sha256' ];
				$bytes  = $overlay[ $field . 'bytes' ];
				if ( strlen( $value ) !== $bytes || $bytes > self::MAX_OVERLAY_BYTES || ! preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || ! hash_equals( $sha256, hash( 'sha256', $value ) ) ) {
					return null;
				}
			}
		}
		$css                = $overlay['css'];
		$editor_css         = $overlay['editor_css'] ?? '';
		$context_css        = $overlay['context_css'] ?? '';
		$editor_context_css = $overlay['editor_context_css'] ?? '';
		if ( strlen( $css ) + strlen( $context_css ) > self::overlay_byte_limit( $css ) || strlen( $editor_css ) + strlen( $editor_context_css ) > self::overlay_byte_limit( $editor_css ) ) {
			return null;
		}
		if ( '' === $css && '' === $editor_css && '' === $context_css && '' === $editor_context_css ) {
			return null;
		}
		foreach ( array( self::SURFACE_FRONT_END, self::SURFACE_EDITOR ) as $surface ) {
			foreach ( array( self::SURFACES[ $surface ]['css'], self::SURFACES[ $surface ]['context'] ) as $artifact ) {
				$value = self::artifact( $overlay, $artifact['key'] );
				if ( '' !== $value && ! self::safe_compiled_artifact( $value, $artifact['label'], $artifact['rule'] ) ) {
					return null;
				}
			}
		}
		return $overlay;
	}

	/**
	 * Admit one compiled artifact: its declared header, then every rule in its declared grammar.
	 *
	 * @param string $css     Compiled artifact.
	 * @param string $label   Declared header label.
	 * @param string $grammar Declared rule grammar: `compiled`, `editor` or `context`.
	 */
	private static function safe_compiled_artifact( string $css, string $label, string $grammar ): bool {
		if ( ! preg_match( '/^\/\* Static Site Importer ' . preg_quote( $label, '/' ) . ': [a-f0-9]{12} \*\/\n/', $css, $header ) ) {
			return false;
		}
		$body = substr( $css, strlen( $header[0] ) );
		if ( ! str_ends_with( $body, "\n" ) || str_contains( $body, 'url(' ) || str_contains( $body, '@import' ) ) {
			return false;
		}
		foreach ( array_filter( explode( "\n", trim( $body ) ) ) as $rule ) {
			$safe = match ( $grammar ) {
				'context' => self::safe_context_fallback_artifact_rule( $rule ),
				'editor'  => self::safe_editor_compiled_rule( $rule ),
				default   => self::safe_compiled_rule( $rule ),
			};
			if ( ! $safe ) {
				return false;
			}
		}
		return true;
	}

	/** The body of an admitted `@media` / `@container` rule, or null when the rule is not one. */
	private static function unwrap_condition( string $rule ): ?string {
		if ( preg_match( '/^@(?:media (' . self::MEDIA_FEATURE_QUERY . '(?:,' . self::MEDIA_FEATURE_QUERY . '){0,127})|container (' . self::MEDIA_FEATURE_QUERY . '))\{(.+)\}$/D', $rule, $matches ) ) {
			return $matches[3];
		}
		return null;
	}

	private static function safe_context_fallback_artifact_rule( string $rule ): bool {
		$body = self::unwrap_condition( $rule );
		if ( null !== $body ) {
			return self::safe_context_fallback_artifact_rule( $body );
		}
		return self::safe_context_fallback_rule( $rule );
	}

	private static function safe_editor_compiled_rule( string $rule ): bool {
		$body = self::unwrap_condition( $rule );
		if ( null !== $body ) {
			return self::safe_editor_compiled_rule( $body );
		}
		$prefix = self::SURFACES[ self::SURFACE_EDITOR ]['prefix'];
		if ( ! preg_match( '/^([^{}]+)\{([^{}]+)\}$/D', $rule, $matches ) ) {
			return false;
		}
		$selectors = explode( ', ', $matches[1] );
		return count( $selectors ) <= 128 && ! array_filter( $selectors, static fn( string $selector ): bool => ! str_starts_with( $selector, $prefix ) || ! self::safe_compiled_rule( substr( $selector, strlen( $prefix ) ) . '{' . $matches[2] . '}' ) );
	}

	private static function safe_compiled_rule( string $rule ): bool {
		if ( preg_match( '/^([^{}]+)\{([^{}]+)\}$/D', $rule, $list ) && str_contains( $list[1], ', ' ) ) {
			$selectors = explode( ', ', $list[1] );
			return count( $selectors ) <= 128 && ! array_filter( $selectors, static fn( string $selector ): bool => ! self::safe_compiled_rule( $selector . '{' . $list[2] . '}' ) );
		}
		$rule = str_replace( self::SURFACES[ self::SURFACE_EDITOR ]['field_control']['suffix'] . '{', '{', $rule );
		if ( self::safe_context_fallback_rule( $rule ) ) {
			return true;
		}
		if ( preg_match( '/^(\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})? \.ssi-node-[a-f0-9]{12})::placeholder\{color:revert;opacity:revert\}$/D', $rule ) ) {
			return true;
		}
		// Provider dropdown chrome may only be suppressed within a generated
		// select hook. Do not admit arbitrary declarations on provider icons.
		if ( preg_match( '/^\.ssi-form-[a-f0-9]{12} \.ssi-node-[a-f0-9]{12} \.jetpack-field-dropdown__icon\{display:none\}$/D', $rule ) ) {
			return true;
		}
		$body = self::unwrap_condition( $rule );
		if ( null !== $body ) {
			return self::safe_compiled_rule( $body );
		}
		// The provider form target is admitted as both of its rendered spellings,
		// so a compiled rule may carry that two-part selector list.
		$scope_selector = '\.ssi-form-[a-f0-9]{12}(?:\.ssi-form-[a-f0-9]{12})?(?:\.jetpack-contact-form-container)?(?: > [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*(?::not\(:has\(\.wp-block-jetpack-contact-form\)\))?| \.ssi-source-field-list| \.ssi-context-[a-f0-9]{12}| (?:input)?\.ssi-node-[a-f0-9]{12}(?:-(?:wrap|destination-[a-z][a-z0-9-]{0,31}))?(?: > \.wp-block-button__link(?: > span)?| > \.grunion-label-required| \.contact-form__checkbox-wrap| > \.wp-block-jetpack-option| > label| > input\.jetpack-field-option__checkbox| > div\.jetpack-field__control > \.wp-block-jetpack-label| select)?| \.ssi-source-choice-label--ssi-node-[a-f0-9]{12}(?: > \.jetpack-field-option__label-wrapper > \.rich-text)?| \.grunion-field-wrap \.contact-form__input-error:not\(\.has-errors\)| \.grunion-field-wrap \.contact-form__field-hints| \.grunion-field-wrap \.contact-form__field-format| \.ssi-field-row > (?:label|\.ssi-node-[a-f0-9]{12})| div\.form-button-submit| \.grunion-field-wrap > \.ssi-field-row| \.grunion-field-wrap \.grunion-field::placeholder|:not\(:has\(> [a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*\)\)(?::not\(form\.jetpack-contact-form__form \*\))?)?';
		if ( ! preg_match( '/^([^{}]+)\{([^{}]+)\}$/D', $rule, $matches ) ) {
			return false;
		}
		$native_safe = false;
		if ( preg_match( '/^(\.ssi-form-[a-f0-9]{12})/', $matches[1], $scope_match ) ) {
			$scope       = $scope_match[1];
			$selector    = str_starts_with( $matches[1], $scope . $scope ) ? substr( $matches[1], strlen( $scope ) ) : $matches[1];
			$native_safe = ( str_contains( $selector, '.ssi-native-form-topology' ) && self::safe_selector( $selector, $scope ) )
				|| ( in_array( substr( $selector, strlen( $scope ) ), array( ' .ssi-native-control-shell', ...array_keys( self::NATIVE_PASSTHROUGH ), self::SURFACES[ self::SURFACE_FRONT_END ]['container_passthrough'][0]['suffix'] ), true ) && 'display:contents' === $matches[2] );
		}
		if ( ! preg_match( '/^' . $scope_selector . '$/D', $matches[1] ) && ! $native_safe ) {
			return false;
		}
		$layout_allowed       = array( 'display', 'float', 'width', 'height', 'grid-template-columns', 'grid-template-rows', 'gap', 'row-gap', 'column-gap', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area', 'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end', 'position', 'z-index', 'pointer-events', ...array_values( self::box_property_map() ) );
		$presentation_allowed = array_merge( array_values( self::presentation_property_map() ), array( 'color', 'flex', 'font' ) );
		foreach ( explode( ';', $matches[2] ) as $declaration ) {
			$declaration = preg_replace( '/!important$/D', '', $declaration ) ?? $declaration;
			if ( 'transform:none' === $declaration || 'word-break:normal' === $declaration ) {
				continue;
			}
			if ( preg_match( '/^(--[a-z][a-z0-9-]{0,79}):(.+)$/D', $declaration, $alias ) ) {
				if ( ! self::safe_presentation_value( $alias[2] ) ) {
					return false;
				}
				continue;
			}
			if ( ! preg_match( '/^([a-z-]+):(.+)$/D', $declaration, $parts ) || ( ! in_array( $parts[1], $layout_allowed, true ) && ! in_array( $parts[1], $presentation_allowed, true ) ) || ( in_array( $parts[1], $presentation_allowed, true ) ? ! self::safe_presentation_value( $parts[2] ) : ! self::safe_value( str_replace( array( 'grid-template-columns', 'grid-template-rows', 'flex-direction', 'flex-wrap', 'align-items', 'align-content', 'justify-content', 'align-self', 'justify-self', 'flex-grow', 'flex-shrink', 'flex-basis', 'grid-column', 'grid-row', 'grid-area' ), array( 'columns', 'rows', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self', 'flex_grow', 'flex_shrink', 'flex_basis', 'column', 'row', 'area' ), $parts[1] ), $parts[2] ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function context_property_map(): array {
		$keys = array( 'color', 'font_family', 'font_size', 'font_style', 'font_weight', 'letter_spacing', 'line_height', 'text_transform', 'text_align', 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end', 'min_height', 'grid_column', 'grid_row', 'grid_area', 'order', 'align_self', 'justify_self' );
		return array_combine( $keys, array_map( static fn( string $key ): string => str_replace( '_', '-', $key ), $keys ) );
	}

	private static function safe_context_fallback_rule( string $rule ): bool {
		if ( preg_match( '/^:where\((\.ssi-form-[a-f0-9]{12})\) :where\(\.(ssi-context-[a-f0-9]{12})\)\.([A-Za-z_][A-Za-z0-9_-]{0,79})\{([^{}]+)\}$/D', $rule, $matches ) ) {
			$allowed = array( 'color', 'font-family', 'font-size', 'font-style', 'font-weight', 'letter-spacing', 'line-height', 'text-transform' );
			$body    = $matches[4];
		} elseif ( preg_match( '/^\.ssi-form-[a-f0-9]{12} \.ssi-context-[a-f0-9]{12}\{([^{}]+)\}$/D', $rule, $matches ) ) {
			$allowed = array_values( self::context_property_map() );
			$body    = $matches[1];
		} else {
			return false;
		}
		foreach ( explode( ';', $body ) as $declaration ) {
			if ( ! preg_match( '/^([a-z-]+):(.+)$/D', $declaration, $parts ) || ! in_array( $parts[1], $allowed, true ) || ! self::safe_presentation_value( $parts[2] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_selector( string $selector, string $scope ): bool {
		if ( str_ends_with( $selector, ' > .wp-block-button__link > span' ) ) {
			return self::safe_selector( substr( $selector, 0, -strlen( ' > span' ) ), $scope );
		}
		$native_scope = $scope . '.ssi-native-form-topology';
		if ( str_contains( $selector, '.ssi-native-form-topology' ) ) {
			$parts   = explode( ', ', $selector );
			$allowed = array( $native_scope, $native_scope . '.jetpack-contact-form-container', $native_scope . ' form.jetpack-contact-form__form', $native_scope . '.wp-block-jetpack-contact-form:not(:has(form.jetpack-contact-form__form)):not(form.jetpack-contact-form__form *)', $native_scope . ' > div.jetpack-contact-form', $native_scope . '.wp-block-jetpack-contact-form:has(form.jetpack-contact-form__form)', $native_scope . '.wp-block-jetpack-contact-form:where(form.jetpack-contact-form__form *)', $native_scope . ' form.jetpack-contact-form__form.submission-success', $native_scope . self::NATIVE_SUBMIT_LINK );
			return count( $parts ) <= 2 && empty( array_diff( $parts, $allowed ) );
		}
		$field_control = self::SURFACES[ self::SURFACE_EDITOR ]['field_control']['suffix'];
		if ( str_ends_with( $selector, $field_control ) ) {
			return self::safe_selector( substr( $selector, 0, -strlen( $field_control ) ), $scope );
		}
		// A select control's own padding is declared on the `<select>` element,
		// nested a level deeper than the generated node hook (an intervening
		// wrapper div sits between them), not on the control shell the node hook
		// otherwise addresses.
		if ( str_ends_with( $selector, ' select' ) ) {
			return self::safe_selector( substr( $selector, 0, -strlen( ' select' ) ), $scope );
		}
		if ( preg_match( '/^' . preg_quote( $scope, '/' ) . ' \.ssi-node-[a-f0-9]{12}::placeholder$/D', $selector ) ) {
			return true;
		}
		// A label node hook inside the field row the front-end runtime rebuilds.
		if ( preg_match( '/^' . preg_quote( $scope, '/' ) . ' \.ssi-field-row > \.ssi-node-[a-f0-9]{12}$/D', $selector ) ) {
			return true;
		}
		// The bare scope is the block wrapper the provider renders at the source form's
		// own position, so it carries the form box's placement inside the page.
		// A generated node hook resolves to the control, and its provider `-wrap` copy
		// resolves to that control's field shell.
		// The provider form target addresses the flex container that holds the
		// fields, which is the form element when the scoped block wrapper wraps
		// it and the block wrapper itself when the provider's form wraps it (or
		// renders no form at all). One target therefore carries both spellings:
		// the form branch excludes itself whenever the block wrapper sits inside
		// it, and the wrapper branch excludes itself whenever the form is its
		// child, so the declarations land on exactly one element. The native
		// topology target keeps its wrapper branch excluding itself inside the
		// form, so that spelling stays admitted too.
		$parts = explode( ', ', $selector );
		if ( count( $parts ) > 2 ) {
			return false;
		}
		$element = '[a-z][a-z0-9-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]{0,79})*';
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^' . preg_quote( $scope, '/' ) . '(?:\.jetpack-contact-form-container)?(?: > ' . $element . '(?::not\(:has\(\.wp-block-jetpack-contact-form\)\))?| \.ssi-source-field-list| \.ssi-context-[a-f0-9]{12}| (?:input)?\.ssi-node-[a-f0-9]{12}(?:-(?:wrap|destination-[a-z][a-z0-9-]{0,31}))?(?: > \.wp-block-button__link| > \.grunion-label-required| \.contact-form__checkbox-wrap| > \.wp-block-jetpack-option| > label| > input\.jetpack-field-option__checkbox| > div\.jetpack-field__control > \.wp-block-jetpack-label)?| \.ssi-source-choice-label--ssi-node-[a-f0-9]{12}(?: > \.jetpack-field-option__label-wrapper > \.rich-text)?|:not\(:has\(> ' . $element . '\)\)(?::not\(form\.jetpack-contact-form__form \*\))?)?$/D', $part ) ) {
				return false;
			}
		}
		return true;
	}
	private static function is_media_all( mixed $condition ): bool {
		return array(
			'kind'  => 'media',
			'query' => 'all',
		) === $condition;
	}

	private static function safe_condition( mixed $condition ): bool {
		if ( ! is_array( $condition ) ) {
			return false;
		}
		if ( in_array( $condition['kind'] ?? null, array( 'media', 'container' ), true ) ) {
			return array_keys( $condition ) === array( 'kind', 'query' ) && is_string( $condition['query'] ?? null ) && (bool) preg_match( '/^' . self::MEDIA_FEATURE_QUERY . '$/D', $condition['query'] );
		}
		return 'all' === ( $condition['kind'] ?? null ) && array_keys( $condition ) === array( 'kind', 'conditions' ) && is_array( $condition['conditions'] ) && count( $condition['conditions'] ) >= 2 && count( $condition['conditions'] ) <= 4 && array_is_list( $condition['conditions'] ) && ! array_filter( $condition['conditions'], static fn ( $part ): bool => ! self::safe_condition( $part ) );
	}
	private static function conditional_rule( array $condition, string $rule ): string {
		$conditions = 'all' === ( $condition['kind'] ?? null ) ? $condition['conditions'] : array( $condition );
		foreach ( array_reverse( $conditions ) as $part ) {
			$rule = '@' . $part['kind'] . ' ' . $part['query'] . '{' . $rule . '}';
		}
		return $rule;
	}

	/** @return array<string,string> */
	public static function layout_property_map(): array {
		return array(
			'display'             => 'display',
			'float'               => 'float',
			'position'            => 'position',
			'top'                 => 'top',
			'right'               => 'right',
			'bottom'              => 'bottom',
			'left'                => 'left',
			'width'               => 'width',
			'max_width'           => 'max-width',
			'height'              => 'height',
			'columns'             => 'grid-template-columns',
			'rows'                => 'grid-template-rows',
			'gap'                 => 'gap',
			'row_gap'             => 'row-gap',
			'column_gap'          => 'column-gap',
			'direction'           => 'flex-direction',
			'wrap'                => 'flex-wrap',
			'align_items'         => 'align-items',
			'align_content'       => 'align-content',
			'justify_content'     => 'justify-content',
			'align_self'          => 'align-self',
			'justify_self'        => 'justify-self',
			'order'               => 'order',
			'flex'                => 'flex',
			'flex_grow'           => 'flex-grow',
			'flex_shrink'         => 'flex-shrink',
			'flex_basis'          => 'flex-basis',
			'column'              => 'grid-column',
			'row'                 => 'grid-row',
			'area'                => 'grid-area',
			'margin_block_start'  => 'margin-block-start',
			'margin_block_end'    => 'margin-block-end',
			'margin_inline_start' => 'margin-inline-start',
			'margin_inline_end'   => 'margin-inline-end',
		) + self::box_property_map();
	}

	/**
	 * Source box facts a v3 layout graph assigns to the element that declares
	 * them. They stay on that element's provider target, never on an inner control.
	 *
	 * @return array<string,string>
	 */
	public static function box_property_map(): array {
		return array(
			'min_height'           => 'min-height',
			'margin_top'           => 'margin-top',
			'margin_right'         => 'margin-right',
			'margin_bottom'        => 'margin-bottom',
			'margin_left'          => 'margin-left',
			'padding'              => 'padding',
			'padding_top'          => 'padding-top',
			'padding_right'        => 'padding-right',
			'padding_bottom'       => 'padding-bottom',
			'padding_left'         => 'padding-left',
			'padding_block_start'  => 'padding-block-start',
			'padding_block_end'    => 'padding-block-end',
			'padding_inline_start' => 'padding-inline-start',
			'padding_inline_end'   => 'padding-inline-end',
		);
	}

	/** One to four box lengths; `min-height` and the longhands take exactly one. */
	private static function safe_box_value( string $fact, string $value ): bool {
		$tokens = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevelWhitespace( trim( $value ) );
		if ( array() === $tokens || count( $tokens ) > ( 'padding' === $fact ? 4 : 1 ) ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			if ( str_starts_with( $token, 'calc(' ) ? ! self::safe_calc_value( $token ) : ! preg_match( '/^(?:0|auto|-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|%|vw|vh|vmin|vmax|ch|ex|svh|dvh|lvh)|var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}\))$/D', $token ) ) {
				return false;
			}
			if ( str_starts_with( $token, '-' ) && ! str_starts_with( $fact, 'margin_' ) ) {
				return false;
			}
		}
		return 'auto' !== $value || 'min_height' === $fact || str_starts_with( $fact, 'margin_' );
	}

	private static function safe_value( string $fact, mixed $value ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}
		$value = (string) $value;
		if ( '' === $value || strlen( $value ) > self::MAX_PRESENTATION_VALUE_BYTES || preg_match( '/(?:url\(|[;{}\\\\]|!important|expression\()/i', $value ) ) {
			return false;
		}
		if ( isset( self::box_property_map()[ $fact ] ) ) {
			return self::safe_box_value( $fact, $value );
		}
		if ( in_array( $fact, array( 'width', 'height', 'flex_basis' ), true ) && in_array( $value, array( 'min-content', 'max-content', 'fit-content' ), true ) ) {
			return true;
		}
		if ( in_array( $fact, array( 'rows', 'columns' ), true ) ) {
			$tracks = \Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter::splitTopLevelWhitespace( $value );
			if ( count( $tracks ) > 1 ) {
				return ! array_filter( $tracks, static fn( string $track ): bool => ! self::safe_value( 'width', $track ) );
			}
			if ( in_array( $value, array( 'min-content', 'max-content' ), true ) ) {
				return true;
			}
		}
		if ( 'display' === $fact && 'contents' === $value ) {
			return true;
		}
		// CSS permits fractional lengths without a leading zero; the source
		// stylesheet and the overlay express the same value either way.
		if ( 1 === preg_match( '/^\.[0-9]+(?:px|rem|em|%|vw|vh|fr)$/D', $value ) ) {
			$value = '0' . $value;
		}
		if ( in_array( $fact, array( 'display', 'direction', 'wrap', 'align_items', 'align_content', 'justify_content', 'align_self', 'justify_self' ), true ) ) {
			// Source stylesheets drive these keywords through their own custom properties.
			// The overlay references the property the preserved source CSS already defines
			// rather than resolving it to a guessed keyword, exactly as lengths already do.
			$keyword = '(?:flex|grid|block|inline-flex|row|row-reverse|column|column-reverse|wrap|nowrap|wrap-reverse|flex-start|flex-end|center|stretch|baseline|space-between|space-around|space-evenly|start|end)';
			return (bool) preg_match( '/^(?:' . $keyword . '|var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}(?:, ?' . $keyword . ')?\))$/D', $value );
		}
		if ( in_array( $fact, array( 'order', 'flex_grow', 'flex_shrink' ), true ) ) {
			return (bool) preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/D', $value );
		}
		if ( 'flex' === $fact ) {
			// `flex: 1` is the one-number form of the same shorthand (`1 1 0%`).
			return (bool) preg_match( '/^(?:none|(?:[0-9]+(?:\.[0-9]+)?)(?: [0-9]+(?:\.[0-9]+)?)?(?: (?:auto|0|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh)))?)$/D', $value );
		}
		// Only the projection's neutralizing float reset is supported.
		if ( 'float' === $fact ) {
			return 'none' === $value;
		}
		if ( 'position' === $fact ) {
			return in_array( $value, array( 'relative', 'static' ), true );
		}
		if ( in_array( $fact, array( 'top', 'right', 'bottom', 'left' ), true ) ) {
			return 'auto' === $value || self::safe_calc_value( $value ) || (bool) preg_match( '/^(?:0|-?[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh))$/D', $value );
		}
		if ( 'z-index' === $fact ) {
			return '1' === $value;
		}
		if ( 'pointer-events' === $fact ) {
			return 'auto' === $value;
		}
		if ( in_array( $fact, array( 'column', 'row' ), true ) ) {
			return (bool) preg_match( '/^(?:auto|-1|[1-9][0-9]*|span [1-9][0-9]*)(?: ?\/ ?(?:auto|-1|[1-9][0-9]*|span [1-9][0-9]*))?$/D', $value );
		}
		if ( str_starts_with( $value, 'calc(' ) ) {
			return self::safe_calc_value( $value );
		}
		if ( 'area' === $fact ) {
			return (bool) preg_match( '/^(?:auto|[1-9][0-9]*|span [1-9][0-9]*)(?: ?\/ ?(?:auto|[1-9][0-9]*|span [1-9][0-9]*)){3}$/D', $value );
		}
		// A track size admits a bare zero alongside a unit-suffixed length, and
		// `repeat()` admits `minmax()` as its track argument - the exact shape
		// Tailwind's own `grid-cols-*` utilities compile every track list to
		// (`repeat(N, minmax(0, 1fr))`).
		$track = '(?:0|[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh|fr))';
		return (bool) preg_match( '/^(?:var\(--[a-zA-Z][a-zA-Z0-9_-]{0,79}(?:, ?(?:0|[0-9]+(?:\.[0-9]+)?(?:px|rem|em|%|vw|vh)))?\)|auto|none|0|span [1-9][0-9]*|[1-9][0-9]*|(?:[0-9]+(?:\.[0-9]+)?)(?:px|rem|em|%|vw|vh|fr)|minmax\(' . $track . ', ?' . $track . '\)|repeat\([1-9][0-9]*, ?(?:' . $track . '|minmax\(' . $track . ', ?' . $track . '\))\))+(?: ?\/ ?[1-9][0-9]*)?$/D', $value );
	}
	/**
	 * A source length can be authored as an arithmetic expression, which the
	 * browser evaluates. Admit that expression when it carries only numbers,
	 * units, arithmetic operators, and balanced parentheses, so an authored
	 * `calc()` keeps its declared geometry instead of being dropped to the
	 * consuming runtime's own default.
	 */
	private static function safe_calc_value( string $value ): bool {
		// A compact source stylesheet can author a fractional number with no
		// leading zero (".25rem"), same as an ordinary decimal ("0.25rem").
		if ( ! preg_match( '/^calc\((?:\s|(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|%|vw|vh|fr)?|[-+*\/()])+\)$/D', $value ) ) {
			return false;
		}
		$depth = 0;
		foreach ( str_split( $value ) as $character ) {
			$depth += '(' === $character ? 1 : ( ')' === $character ? -1 : 0 );
			if ( $depth < 0 ) {
				return false;
			}
		}
		return 0 === $depth;
	}

	/** Adapter maps may select only these captured presentation properties. */
	public static function presentation_property_map(): array {
		$keys = array( 'appearance', 'background', 'background_color', 'border', 'border_color', 'border_style', 'border_width', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color', 'border_top_style', 'border_right_style', 'border_bottom_style', 'border_left_style', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width', 'border_radius', 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius', 'box_sizing', 'color', 'display', 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'height', 'inset', 'letter_spacing', 'line_height', 'margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'margin_block_start', 'margin_block_end', 'margin_inline_start', 'margin_inline_end', 'max_width', 'min_height', 'min_width', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'padding_block_start', 'padding_block_end', 'padding_inline_start', 'padding_inline_end', 'text_align', 'text_decoration', 'text_indent', 'text_transform', 'vertical_align', 'width', 'flex_shrink', 'position', 'transform' );
		$keys = array_merge( $keys, array( 'font', 'align_items', 'flex_direction', 'gap', 'justify_content' ) );
		$keys = array_merge( $keys, array( 'align_self', 'justify_self', 'top', 'right', 'bottom', 'left' ) );
		$keys = array_merge( $keys, array( 'flex', 'flex_basis', 'flex_grow', 'margin_block', 'margin_inline', 'order', 'z_index' ) );
		// A source can author vertical/horizontal padding through the same two-value
		// logical shorthand already admitted for margin above.
		$keys = array_merge( $keys, array( 'padding_block', 'padding_inline' ) );
		return array_combine( $keys, array_map( static fn( string $key ): string => str_replace( '_', '-', $key ), $keys ) );
	}

	public static function presentation_property_keys(): array {
		return array_diff_key( self::presentation_property_map(), array_flip( array( 'flex_shrink', 'position', 'transform' ) ) );
	}

	/** Additional properties are valid only for explicitly declared positioned controls. */
	public static function positioned_control_presentation_property_keys(): array {
		return self::presentation_property_map();
	}

	/**
	 * Properties an explicitly declared positioned control's destination may
	 * carry but an ordinary control, label, or required-marker destination
	 * never may (see `presentation_property_keys()`).
	 *
	 * @return array<int,string>
	 */
	private static function positioned_control_only_presentation_property_keys(): array {
		return array_keys( array_diff_key( self::positioned_control_presentation_property_keys(), self::presentation_property_keys() ) );
	}

	/**
	 * Resolve overlapping declarations before the alphabetically keyed capture is
	 * serialized. Keep a stronger longhand after its shorthand: dropping the
	 * shorthand would also discard its non-conflicting components.
	 *
	 * @param array<string,string>              $styles Captured values.
	 * @param array<int,array<string,mixed>>    $provenance Source selector ownership.
	 * @param array<string,array<string,mixed>> $precedence Responsive cascade ranks.
	 * @return array<string,string>
	 */
	private static function resolve_presentation_cascade( array $styles, array $provenance, array $precedence = array() ): array {
		$ranks = array();
		foreach ( $provenance as $order => $fact ) {
			$selector = $fact['selector'] ?? '';
			$weight   = '[style]' === $selector ? 10000 : 0;
			if ( class_exists( '\\Automattic\\BlocksEngine\\PhpTransformer\\Css\\CssSelectorMatcher' ) && '[style]' !== $selector ) {
				$parsed = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::parse( $selector );
				$weight = \Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher::specificity( $parsed );
			}
			foreach ( $fact['properties'] ?? array() as $property ) {
				$ranks[ str_replace( '-', '_', $property ) ] = array( false, $weight, $order );
			}
		}
		foreach ( $precedence as $property => $rank ) {
			$ranks[ str_replace( '-', '_', $property ) ] = array( $rank['important'], $rank['specificity'], $rank['source_order'] );
		}
		$shorthands = array(
			'background'    => array( 'background_color' ),
			'border'        => array( 'border_color', 'border_style', 'border_width' ),
			'border_color'  => array(),
			'border_style'  => array(),
			'border_width'  => array(),
			'border_radius' => array( 'border_top_left_radius', 'border_top_right_radius', 'border_bottom_right_radius', 'border_bottom_left_radius' ),
			'font'          => array( 'font_family', 'font_size', 'font_style', 'font_variant', 'font_weight', 'line_height' ),
			'flex'          => array( 'flex_basis', 'flex_grow', 'flex_shrink' ),
			'inset'         => array( 'top', 'right', 'bottom', 'left' ),
		);
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			foreach ( array( 'color', 'style', 'width' ) as $component ) {
				$shorthands['border'][]                 = 'border_' . $side . '_' . $component;
				$shorthands[ 'border_' . $component ][] = 'border_' . $side . '_' . $component;
			}
		}
		foreach ( array( 'margin', 'padding' ) as $box ) {
			$shorthands[ $box ] = array_map( static fn( string $side ): string => $box . '_' . $side, array( 'top', 'right', 'bottom', 'left' ) );
			foreach ( array( 'block', 'inline' ) as $axis ) {
				$shorthands[ $box . '_' . $axis ] = array( $box . '_' . $axis . '_start', $box . '_' . $axis . '_end' );
			}
		}
		foreach ( $shorthands as $shorthand => $longhands ) {
			if ( ! isset( $styles[ $shorthand ] ) ) {
				continue;
			}
			foreach ( $longhands as $longhand ) {
				if ( isset( $styles[ $longhand ], $ranks[ $shorthand ], $ranks[ $longhand ] ) && $ranks[ $shorthand ] > $ranks[ $longhand ] ) {
					unset( $styles[ $longhand ] );
				}
			}
			// Move only overlapping declarations; unrelated facts retain their
			// stable producer order and do not churn existing overlay artifacts.
			$ordered = array();
			foreach ( $styles as $property => $value ) {
				if ( in_array( $property, $longhands, true ) && ! isset( $ordered[ $shorthand ] ) ) {
					$ordered[ $shorthand ] = $styles[ $shorthand ];
				}
				$ordered[ $property ] = $value;
			}
			$styles = $ordered;
		}
		return $styles;
	}

	private static function presentation_declarations( array $styles, int $index, string $role, array &$losses, array $properties = array(), array $aliases = array() ): array {
		$map          = self::presentation_property_map();
		$declarations = array();
		foreach ( $styles as $key => $value ) {
			if ( ! isset( $map[ $key ] ) || ! self::safe_presentation_value( $value ) ) {
				$losses[] = self::presentation_loss( 'unsafe_presentation_value', $index, $role );
				continue;
			}
			if ( ! in_array( $key, $properties, true ) ) {
				continue;
			}
			$declarations[] = $map[ $key ] . ':' . $value;
			if ( isset( $aliases[ $key ] ) ) {
				$declarations[] = $aliases[ $key ] . ':' . $value;
			}
		}
		return $declarations;
	}

	/** Compile one adapter-owned destination without knowing its provider or markup. */
	private static function compile_presentation_destinations( array $destinations, array $styles, int $index, string $role, ?array $condition, string $prefix, array &$rules, array &$operations, array &$losses, bool $emit_resets = true ): void {
		$represented = array();
		// The provider adds a flex item around the source button. Its percentage
		// width must resolve against a full row, not a shrink-to-fit label box.
		if ( isset( $styles['width'] ) && ( '100%' === $styles['width'] || null !== $condition ) ) {
			$basis = '100%' === $styles['width'] ? '100%' : 'auto';
			foreach ( $destinations as $destination ) {
				$suffix = ' > .wp-block-button__link';
				if ( str_ends_with( $destination['selector'], $suffix ) ) {
					$wrapper = substr( $destination['selector'], 0, -strlen( $suffix ) );
					$rule    = self::surface_selector( $prefix, self::authoritative_presentation_selector( $wrapper ) ) . '{flex-basis:' . $basis . '}';
					$rules[] = null !== $condition ? self::conditional_rule( $condition, $rule ) : $rule;
				}
			}
		}
		foreach ( $destinations as $destination ) {
			$represented = array_merge( $represented, $destination['properties'] );
			$emit_styles = $styles;
			if ( str_ends_with( (string) ( $destination['selector'] ?? '' ), ' > .wp-block-button__link' ) ) {
				$emit_styles = array_diff_key( $styles, array_flip( array( 'position', 'inset', 'top', 'right', 'bottom', 'left' ) ) );
			}
			$source_declarations = self::presentation_declarations( $emit_styles, $index, $role, $losses, $destination['properties'], $destination['aliases'] ?? array() );
			$reset_declarations  = array();
			// Provider defaults need one base reset. A responsive patch must carry
			// only its authored changes; repeating the reset in every media query
			// otherwise overrides a complete base font family or line-height.
			foreach ( null === $condition && $emit_resets ? ( $destination['resets'] ?? array() ) : array() as $property => $value ) {
				// An explicit source declaration is authoritative over a provider-default
				// neutralization at the same destination.
				if ( 'required_marker' !== $role && in_array( str_replace( '-', '_', $property ), $destination['properties'], true ) && array_key_exists( str_replace( '-', '_', $property ), $styles ) ) {
					continue;
				}
				$reset_declarations[] = $property . ':' . $value;
			}
			// Resets release provider defaults first; authored declarations then own
			// their exact longhand/shorthand box, including over a reset shorthand.
			$declarations = array_merge( $reset_declarations, $source_declarations );
			// A font shorthand reset leads, so every font longhand after it wins;
			// an authored font shorthand has already replaced it above.
			$font_reset = isset( $destination['resets']['font'] ) ? 'font:' . $destination['resets']['font'] : null;
			if ( null !== $font_reset && in_array( $font_reset, $reset_declarations, true ) ) {
				$declarations = array_merge( array( $font_reset ), array_values( array_diff( $declarations, array( $font_reset ) ) ) );
			}
			if ( empty( $declarations ) ) {
				continue;
			}
			if ( 'important' === ( $destination['priority'] ?? '' ) ) {
				$declarations = array_map( static fn( string $declaration ): string => $declaration . '!important', $declarations );
			}
			$rule         = self::surface_selector( $prefix, self::authoritative_presentation_selector( $destination['selector'] ) ) . '{' . implode( ';', $declarations ) . '}';
			$rules[]      = null === $condition ? $rule : self::conditional_rule( $condition, $rule );
			$operations[] = self::presentation_operation( $index, $role, $destination['selector'], null !== $condition );
		}
		// A captured property outside every ordinary destination's own vocabulary
		// (see `positioned_control_only_presentation_property_keys()`) is not a
		// coverage gap: no non-positioned control, label, or required-marker
		// destination for any field is ever allowed to carry it, so its absence
		// here is the documented, universal exclusion working as designed, not a
		// per-field fidelity regression worth declining provider materialization
		// over. Only a property this role's own vocabulary could have carried,
		// yet no destination actually represented, is a genuine structure
		// mismatch.
		if ( array_diff( array_keys( $styles ), $represented, self::positioned_control_only_presentation_property_keys() ) ) {
			$losses[] = self::presentation_loss( 'provider_structure_mismatch', $index, $role );
		}
	}

	private static function safe_presentation_aliases( mixed $aliases, array $properties ): bool {
		if ( ! is_array( $aliases ) || ! self::has_only_keys( $aliases, $properties ) ) {
			return false;
		}
		foreach ( $aliases as $property => $alias ) {
			if ( ! is_string( $property ) || ! is_string( $alias ) || ! preg_match( '/^--[a-z][a-z0-9-]{0,79}$/D', $alias ) ) {
				return false;
			}
		}
		return true;
	}

	private static function safe_presentation_resets( mixed $resets ): bool {
		if ( array(
			'color'   => 'revert',
			'opacity' => 'revert',
		) === $resets ) {
			return true;
		}
		if ( ! is_array( $resets ) || ! self::has_only_keys( $resets, array( 'flex', 'min-width', 'max-width', 'min-height', 'padding', 'border', 'background', 'text-indent', 'font-family', 'font-size', 'font-weight', 'font', 'margin', 'margin-block', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end', 'line-height', 'gap', 'display', 'flex-direction', 'align-items', 'align-self', 'order', 'width', 'height', 'appearance', 'transform', 'word-break', '--jetpack--contact-form--input-height' ) ) ) {
			return false;
		}
		foreach ( $resets as $property => $value ) {
			// A transform or word-break reset may only release the provider's own default.
			if ( ! is_string( $property ) || ! self::safe_presentation_value( $value ) || ( 'transform' === $property && 'none' !== $value ) || ( 'word-break' === $property && 'normal' !== $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function authoritative_presentation_selector( string $selector ): string {
		return preg_replace( '/^(\.ssi-form-[a-f0-9]{12})/', '$1$1', $selector, 1 ) ?? $selector;
	}

	/** A captured CSS value admitted into provider output must stay a plain declaration value. */
	public static function safe_presentation_value( mixed $value ): bool {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= self::MAX_PRESENTATION_VALUE_BYTES && ! preg_match( '/(?:url\(|@import|[;{}\\\\]|!important|expression\(|javascript:)/i', $value ) && (bool) preg_match( "~^[a-zA-Z0-9_#%.,()\\s+\\-*/'\"]+$~D", $value );
	}

	private static function presentation_operation( int $index, string $role, string $target, bool $responsive ): array {
		return array_filter( array(
			'dimension'   => 'presentation',
			'strategy'    => 'provider_presentation_transposition',
			'node_hash'   => hash( 'sha256', 'control-' . $index . ':' . $role ),
			'target_hash' => hash( 'sha256', $target ),
			'responsive'  => $responsive ? true : null,
		), static fn( $value ): bool => null !== $value );
	}

	private static function presentation_loss( string $reason, int $index, string $role ): array {
		return array(
			'dimension'   => 'presentation',
			'reason_code' => $reason,
			'node_hash'   => hash( 'sha256', 'control-' . $index . ':' . $role ),
		);
	}

	private static function has_only_keys( array $value, array $keys ): bool {
		return ! array_diff( array_keys( $value ), $keys );
	}
}
