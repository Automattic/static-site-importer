<?php
/**
 * Captured product presentation at the WooCommerce ownership boundary.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Product_Presentation {
	private const SELECTORS = array(
		'variation'      => 'select',
		'quantity'       => 'input.qty',
		'quantity-frame' => 'form.cart .quantity',
		'submit'         => '.single_add_to_cart_button',
		'submit-host'    => '.single_add_to_cart_button',
		'submit-layer'   => '.single_add_to_cart_button::before',
		'gallery'        => '.woocommerce-product-gallery',
		'viewport'       => '.flex-viewport',
		'image'          => '.woocommerce-product-gallery__image img',
		'thumbs'         => '.flex-control-thumbs',
		'thumb'          => '.flex-control-thumbs li',
		'thumbimg'       => '.flex-control-thumbs img',
	);

	private const LAYOUT_SELECTORS = array(
		'gallery-block'    => '',
		'form'             => 'form.cart',
		'variation-table'  => 'table.variations',
		'variation-body'   => 'table.variations tbody',
		'variation-row'    => 'table.variations tr',
		'variation-label'  => 'table.variations th',
		'variation-value'  => 'table.variations td',
		'variation-select' => 'table.variations select',
		'variation-wrap'   => '.single_variation_wrap',
		'purchase-row'     => '.woocommerce-variation-add-to-cart',
		'simple-row'       => 'form.cart:not(.variations_form)',
		'quantity-box'     => 'form.cart .quantity',
		'purchase-button'  => 'form.cart .single_add_to_cart_button',
	);

	/** Project only presentation, never source selectors or purchase behavior. */
	public static function capture( array $entity ): array {
		$rules       = array();
		$layers      = array();
		$conditional = array();
		$purchase    = array();
		$indexes     = array();
		$stepper     = self::quantity_stepper( $entity );
		$buttons     = $stepper ? array_column( $stepper['members'], 'index' ) : array();

		$purchase_wording = array();
		foreach ( $entity['controls'] ?? array() as $index => $control ) {
			if ( $buttons && in_array( $index, array( $buttons[0], $buttons[2] ), true ) ) {
				continue;
			}
			$tag  = strtolower( (string) ( $control['tag'] ?? '' ) );
			$type = strtolower( (string) ( $control['type'] ?? '' ) );
			$name = strtolower( (string) ( $control['name'] ?? '' ) );
			$role = 'select' === $tag ? 'variation' : ( 'input' === $tag && ( 'number' === $type || 'quantity' === $name ) ? 'quantity' : ( 'submit' === $type || ( 'button' === $tag && '' === $type ) || 'button' === strtolower( (string) ( $control['role'] ?? '' ) ) ? 'submit' : '' ) );
			if ( '' === $role ) {
				continue;
			}
			$styles  = $control['styles'] ?? self::block_styles( $control['presentation']['style'] ?? array() );
			$caption = array();
			foreach ( $entity['presentation_graph']['controls'] ?? array() as $row ) {
				if ( ( $row['index'] ?? null ) === $index ) {
					$styles  = isset( $row['control']['styles'] ) ? self::resolve_urls( $row['control']['styles'], $row['control']['provenance'] ?? array() ) : $styles;
					$caption = 'submit' === $role && isset( $row['caption']['styles'] ) ? self::resolve_urls( $row['caption']['styles'], $row['caption']['provenance'] ?? array() ) : array();
					break;
				}
			}
			if ( $caption ) {
				$layer = self::caption_layer( $styles, $caption );
				$paint = isset( $layer['layer'], $layer['button'] ) ? self::layer_declarations( $layer['layer'] ) : '';
				if ( '' !== $paint && isset( $layer['button'] ) ) {
					$styles = $layer['button'];
					$layers = array(
						'submit-host'  => self::host_declarations(),
						'submit-layer' => $paint,
					);
				}
			}
			$purchase[ $role ] = $styles;
			$indexes[ $role ]  = $index;
			$declarations      = self::declarations( $styles );
			// The source's own wording for the quantity caption and the button.
			$text = 'submit' === $role ? ( $control['text'] ?? '' ) : ( 'quantity' === $role && false !== ( $control['label_visible'] ?? true ) ? ( $control['label'] ?? '' ) : '' );
			$text = is_string( $text ) ? trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) : '';
			if ( '' !== $text && self::validate_wording( array( $role => array( 'label' => $text ) ) ) ) {
				$purchase_wording[ $role ] = array( 'label' => $text );
			}
			// Each variation may have its own captured design; do not flatten
			// several source selects onto the last control's presentation.
			$slot = 'variation' === $role ? $role . ':' . count( array_filter( array_keys( $rules ), static fn( $key ) => str_starts_with( $key, 'variation:' ) ) ) : $role;
			if ( $declarations ) {
				$rules[ $slot ] = $declarations;
				$conditional    = array_merge( $conditional, self::conditional_rules( $entity['presentation_graph']['variants'] ?? array(), $index, $slot, isset( $layers['submit-layer'] ) && 'submit' === $role ) );
			}
		}
		$layout  = self::purchase_layout( $purchase, $entity['presentation_graph'] ?? array(), $indexes );
		$frames  = $stepper ? array( 'quantity-frame' => self::frame_declarations( $stepper['styles'] ) ) : array();
		$overlay = self::artifact( array_merge( $rules, $layout, $frames, $layers, $conditional, self::gallery_rules( $entity['gallery'] ?? array() ) ) );
		$wording = self::validate_wording( $entity['variation_wording'] ?? array() );
		if ( $wording ) {
			$overlay                      = $overlay ? $overlay : self::artifact( array(), true );
			$overlay['variation_wording'] = $wording;
		}
		if ( $purchase_wording ) {
			$overlay                     = $overlay ? $overlay : self::artifact( array(), true );
			$overlay['purchase_wording'] = $purchase_wording;
		}
		return $overlay;
	}

	/** Recognise a dedicated three-control row by position, never source classes or wording. */
	public static function quantity_stepper( array $entity ): array {
		$controls = $entity['controls'] ?? array();
		foreach ( $entity['presentation_graph']['control_rows'] ?? array() as $row ) {
			if ( 'generic/computed-form-presentation/v3' !== ( $entity['presentation_graph']['schema'] ?? '' ) || ! is_array( $row['styles'] ?? null ) || isset( $row['flow'] ) || str_starts_with( (string) ( $row['styles']['flex_direction'] ?? '' ), 'column' ) ) {
				continue;
			}
			$members = $row['members'] ?? array();
			if ( 3 !== count( $members ) ) {
				continue;
			}
			$indices = array_column( $members, 'index' );
			if ( 3 !== count( $indices ) || ! is_int( $indices[1] ) || array( $indices[1] - 1, $indices[1], $indices[1] + 1 ) !== $indices ) {
				continue;
			}
			list( $before, $quantity, $after ) = array_map( static fn( $index ) => $controls[ $index ] ?? array(), $indices );
			if ( 'input' === ( $quantity['tag'] ?? '' ) && 'number' === ( $quantity['type'] ?? '' ) && 'button' === ( $before['tag'] ?? '' ) && 'button' === ( $after['tag'] ?? '' ) && 'submit' !== ( $before['type'] ?? '' ) && 'submit' !== ( $after['type'] ?? '' ) ) {
				return $row;
			}
		}
		return array();
	}

	/**
	 * A sliding-door button paints its cap on the control and its body on the
	 * caption carrier inside the control's padding. One native button draws the
	 * carrier as a `::before` layer inset by the control's padding, and lays its
	 * caption out inside both paddings. Declines when a padding is not in pixels.
	 *
	 * @return array{button: array<string,string>, layer: array<string,string>}|array{}
	 */
	private static function caption_layer( array $control, array $caption ): array {
		$outer = self::pixel_sides( (string) ( $control['padding'] ?? '0' ) );
		$inner = self::pixel_sides( (string) ( $caption['padding'] ?? '0' ) );
		if ( null === $outer || null === $inner ) {
			return array();
		}
		$layer = array( 'inset' => implode( ' ', array_map( static fn( $side ) => $side . 'px', $outer ) ) );
		foreach ( array( 'background', 'background_color', 'background_image', 'background_position', 'background_repeat', 'background_size', 'border_radius' ) as $property ) {
			if ( isset( $caption[ $property ] ) ) {
				$layer[ $property ] = $caption[ $property ];
			}
		}
		$control['padding'] = implode( ' ', array_map( static fn( $a, $b ) => ( $a + $b ) . 'px', $outer, $inner ) );
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			unset( $control[ 'padding_' . $side ] );
		}
		return array(
			'button' => $control,
			'layer'  => $layer,
		);
	}

	/** @return list<float>|null Top, right, bottom, left in pixels. */
	private static function pixel_sides( string $value ): ?array {
		$parts = preg_split( '/\s+/', trim( $value ) );
		if ( ! $parts || count( $parts ) > 4 ) {
			return null;
		}
		$sides = array();
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^(\d+(?:\.\d+)?)(?:px)?$/D', $part, $match ) || ( '0' !== $match[1] && ! str_ends_with( $part, 'px' ) ) ) {
				return null;
			}
			$sides[] = (float) $match[1] + 0;
		}
		list( $top, $right, $bottom, $left ) = array( $sides[0], $sides[1] ?? $sides[0], $sides[2] ?? $sides[0], $sides[3] ?? $sides[1] ?? $sides[0] );
		return array( $top, $right, $bottom, $left );
	}

	/**
	 * A breakpoint that repaints a control, or the caption layer it draws,
	 * restates that paint inside the same media query after the base rules.
	 *
	 * @param array<mixed> $variants Presentation graph variants.
	 * @return array<string,string> Slot@query => declarations.
	 */
	private static function conditional_rules( array $variants, int $index, string $slot, bool $layer ): array {
		$rules = array();
		foreach ( $variants as $variant ) {
			$role  = $variant['role'] ?? '';
			$query = self::media_query( $variant['condition'] ?? null );
			if ( ( $variant['index'] ?? null ) !== $index || '' === $query || ! is_array( $variant['style_patch'] ?? null ) || ! ( 'control' === $role || ( 'caption' === $role && $layer ) ) ) {
				continue;
			}
			$styles       = self::resolve_urls( $variant['style_patch'], is_array( $variant['provenance'] ?? null ) ? array_values( array_filter( $variant['provenance'], 'is_array' ) ) : array() );
			$declarations = 'caption' === $role ? self::layer_paint( $styles ) : self::declarations( $styles );
			if ( '' !== $declarations ) {
				$rules[ ( 'caption' === $role ? 'submit-layer' : $slot ) . '@' . $query ] = $declarations;
			}
		}
		return $rules;
	}

	/** Only paint changes under a condition; the base rule owns the layer box. */
	private static function layer_paint( array $styles ): string {
		return self::declarations( array_intersect_key( $styles, array_flip( array( 'background', 'background_color', 'background_image', 'background_position', 'background_repeat', 'background_size', 'border_radius' ) ) ) );
	}

	/** The button stacks its caption layer behind its own text. */
	private static function host_declarations(): string {
		return 'position:relative !important;isolation:isolate !important;';
	}

	/** The caption layer's box and paint; nothing else reaches the pseudo-element. */
	private static function layer_declarations( array $styles ): string {
		$paint = array_intersect_key( $styles, array_flip( array( 'background', 'background_color', 'background_image', 'background_position', 'background_repeat', 'background_size', 'border_radius' ) ) );
		$inset = (string) ( $styles['inset'] ?? '' );
		if ( ! $paint || ! preg_match( '/^(?:\d+(?:\.\d+)?px ){3}\d+(?:\.\d+)?px$/D', $inset ) ) {
			return '';
		}
		$paint = self::declarations( $paint );
		return '' === $paint ? '' : 'content:"" !important;position:absolute !important;inset:' . $inset . ' !important;z-index:-1 !important;' . $paint;
	}

	/**
	 * Resolve each `url()` in a captured value against the stylesheet that
	 * declared it, as a site-root path the theme asset map rewrites.
	 *
	 * @param array<string,string> $styles
	 * @param list<array<string,mixed>> $provenance
	 * @return array<string,string>
	 */
	private static function resolve_urls( array $styles, array $provenance ): array {
		$sources = array();
		foreach ( $provenance as $fact ) {
			foreach ( (array) ( $fact['properties'] ?? array() ) as $property ) {
				$sources[ str_replace( '-', '_', (string) $property ) ] = (string) ( $fact['source_path'] ?? '' );
			}
		}
		foreach ( $styles as $property => $value ) {
			if ( ! str_contains( $value, 'url(' ) ) {
				continue;
			}
			$base                = dirname( $sources[ $property ] ?? '' );
			$styles[ $property ] = (string) preg_replace_callback(
				'/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
				static function ( array $url ) use ( $base ): string {
					$ref = trim( $url[2] );
					if ( '' === $ref || str_starts_with( $ref, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) ) {
						return 'url(' . $ref . ')';
					}
					$parts = array();
					foreach ( explode( '/', ( '.' === $base || '' === $base ? '' : $base . '/' ) . $ref ) as $segment ) {
						if ( '..' === $segment ) {
							array_pop( $parts );
						} elseif ( '' !== $segment && '.' !== $segment ) {
							$parts[] = $segment;
						}
					}
					return 'url(/' . implode( '/', $parts ) . ')';
				},
				$value
			);
		}
		return $styles;
	}

	/** Container paint is distinct from input paint; do not guess absent chrome. */
	private static function frame_declarations( array $styles ): string {
		$frame = array();
		foreach ( $styles as $property => $value ) {
			$property = str_replace( '-', '_', $property );
			if ( in_array( $property, array( 'width', 'height', 'box_sizing', 'background', 'background_color' ), true ) || str_starts_with( $property, 'border' ) ) {
				$frame[ $property ] = $value;
			}
		}
		return self::declarations( $frame );
	}

	/** One deterministic scope and stylesheet artifact for all captured product rules. */
	private static function artifact( array $rules, bool $allow_empty = false ): array {
		if ( ! $rules && ! $allow_empty ) {
			return array();
		}
		$hash  = substr( hash( 'sha256', (string) json_encode( $rules ) ), 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic standalone contract.
		$class = 'ssi-product-' . $hash;
		$css   = '';
		foreach ( $rules as $slot => $declarations ) {
			list( $slot, $media ) = array_pad( explode( '@', $slot, 2 ), 2, '' );
			$parts                = explode( ':', $slot );
			$selector             = 'layout' === $parts[0] ? self::LAYOUT_SELECTORS[ $parts[1] ] : self::SELECTORS[ $parts[0] ];
			if ( 'variation' === $parts[0] ) {
				$selector = '.variations tr:nth-child(' . ( (int) $parts[1] + 1 ) . ') select';
			}
			$scope = '.' . $class . '.' . $class;
			// Rebuild legacy auto-width receipts exactly; new galleries fill only the inner element.
			$rule = ( 'gallery' === $slot && str_contains( $declarations, 'width:auto !important;' ) ? $scope . $selector . ',' : '' ) . $scope . ( '' === $selector ? '' : ' ' . $selector ) . '{' . $declarations . '}';
			$css .= '' === $media ? $rule : '@media ' . $media . '{' . $rule . '}';
		}
		return array(
			'schema'    => 'static-site-importer/product-presentation/v1',
			'className' => $class,
			'rules'     => $rules,
			'css'       => $css,
		);
	}

	/**
	 * Remove native table/intrinsic track constraints before applying control paint.
	 *
	 * Without a shared row fact, the entity carries only control CSS.
	 * In particular, input width is NOT quantity-wrapper width. Keep the painted
	 * input inside a flexible quantity track when the button has an absolute width.
	 * Container-relative button widths cannot size a row track: use the captured
	 * absolute quantity width (or auto) and give the button the remaining space.
	 * Never invent a source gap from the remaining column space.
	 */
	private static function purchase_layout( array $controls, array $graph = array(), array $indexes = array() ): array {
		$styles = array();
		$full   = array(
			'width'      => '100%',
			'min-width'  => '0',
			'box-sizing' => 'border-box',
		);
		if ( isset( $controls['variation'] ) ) {
			foreach ( array( 'form', 'variation-table', 'variation-body', 'variation-row', 'variation-label', 'variation-value', 'variation-wrap' ) as $slot ) {
				$styles[ 'layout:' . $slot ] = array_merge( $full, array( 'display' => 'block' ) );
			}
			foreach ( array( 'variation-label', 'variation-value' ) as $slot ) {
				$styles[ 'layout:' . $slot ]['padding'] = '0';
			}
			$styles['layout:variation-select'] = $full;
		}
		if ( isset( $controls['submit'], $controls['quantity'] ) ) {
			$width    = self::absolute_length( $controls['submit']['width'] ?? '' );
			$quantity = self::absolute_length( $controls['quantity']['width'] ?? '' );
			$row      = array_merge(
				$full,
				array(
					'display'               => 'grid',
					'grid-template-columns' => $width ? 'minmax(0,1fr) minmax(0,' . $width . ')' : ( '' !== $quantity ? $quantity : 'auto' ) . ' minmax(0,1fr)',
				)
			);
			$gap      = self::length( $controls['submit']['column_gap'] ?? $controls['submit']['gap'] ?? '' );
			if ( '' !== $gap ) {
				$row['column-gap'] = $gap;
			}
			$fallback = $row;
			$fact     = self::purchase_row_fact( $graph, $indexes );
			if ( 'stacked' === ( $fact['flow'] ?? null ) ) {
				// Block flow gives each control its own line; the button keeps its captured width.
				$styles['layout:purchase-row']    = array_merge( $full, array( 'display' => 'block' ) );
				$styles['layout:simple-row']      = $styles['layout:purchase-row'];
				$styles['layout:quantity-box']    = array(
					'display' => 'flex',
					'margin'  => '0',
				);
				$styles['layout:purchase-button'] = array( 'margin' => '0' );
				return array_map( array( self::class, 'layout_declarations' ), $styles );
			}
			if ( $fact ) {
				$row = self::row_geometry( $fact, $fallback );
			}
			$styles['layout:purchase-row'] = $row;
			$styles['layout:simple-row']   = $row;
			if ( $fact ) {
				$conditions = array();
				foreach ( $graph['variants'] ?? array() as $variant ) {
					if ( ( $variant['index'] ?? null ) !== $fact['index'] || ! in_array( $variant['role'] ?? '', array( 'control_row', 'control_row_item' ), true ) ) {
						continue;
					}
					$query = self::media_query( $variant['condition'] ?? null );
					if ( '' === $query || ! is_array( $variant['style_patch'] ?? null ) ) {
						continue;
					}
					$conditions[ $query ] ??= $fact;
					if ( 'control_row' === $variant['role'] ) {
						$conditions[ $query ]['styles'] = array_merge( $conditions[ $query ]['styles'], $variant['style_patch'] );
					} elseif ( isset( $conditions[ $query ]['items'][ $variant['member_index'] ?? '' ] ) ) {
						$member                                   = $variant['member_index'];
						$conditions[ $query ]['items'][ $member ] = array_merge( $conditions[ $query ]['items'][ $member ], $variant['style_patch'] );
					}
				}
				foreach ( $conditions as $query => $conditional ) {
					foreach ( array( 'purchase-row', 'simple-row' ) as $slot ) {
						$styles[ 'layout:' . $slot . '@' . $query ] = self::row_geometry( $conditional, $fallback );
					}
				}
			}
			$styles['layout:quantity-box']    = array_merge( $full, array( 'margin' => '0' ) );
			$styles['layout:purchase-button'] = array_merge( $full, array( 'margin' => '0' ) );
		}
		return array_map( array( self::class, 'layout_declarations' ), $styles );
	}

	/**
	 * Find the nearest shared horizontal row by positional control identity,
	 * never by source selector. Outer stacks also contain both controls, so the
	 * row with the fewest members wins and column rows never qualify.
	 */
	private static function purchase_row_fact( array $graph, array $indexes ): array {
		if ( ! isset( $indexes['quantity'], $indexes['submit'] ) ) {
			return array();
		}
		$nearest = array();
		foreach ( $graph['control_rows'] ?? array() as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['index'] ?? null ) || ! is_array( $row['styles'] ?? null ) || str_starts_with( (string) ( $row['styles']['flex_direction'] ?? '' ), 'column' ) ) {
				continue;
			}
			$items = array();
			foreach ( $row['members'] ?? array() as $member ) {
				if ( is_int( $member['index'] ?? null ) && is_array( $member['styles'] ?? null ) ) {
					$items[ $member['index'] ] = $member['styles'];
				}
			}
			if ( isset( $items[ $indexes['quantity'] ], $items[ $indexes['submit'] ] ) && ( ! $nearest || count( $items ) < count( $nearest['items'] ) ) ) {
				$nearest = array(
					'index'  => $row['index'],
					'styles' => $row['styles'],
					'items'  => $items,
					'order'  => array( $indexes['quantity'], $indexes['submit'] ),
					'flow'   => $row['flow'] ?? null,
				);
			}
		}
		return $nearest;
	}

	/** Closed media syntax shared by capture and receipt reconstruction. */
	private static function media_query( mixed $condition ): string {
		if ( ! is_array( $condition ) || array_diff( array_keys( $condition ), array( 'kind', 'query' ) ) || 'media' !== ( $condition['kind'] ?? '' ) || ! is_string( $condition['query'] ?? null ) ) {
			return '';
		}
		$feature = '\\((?:(?:min-|max-)?(?:width|height):\\s*\\d+(?:\\.\\d+)?(?:px|em|rem)|orientation:\\s*(?:portrait|landscape))\\)';
		return preg_match( '/^(?:(?:only )?(?:all|screen|print)(?: and ' . $feature . ')*|' . $feature . '(?: and ' . $feature . ')*)$/D', $condition['query'] ) ? $condition['query'] : '';
	}

	/** Map supported flex distributions; unsupported or wrapped rows retain the fallback. */
	private static function row_geometry( array $fact, array $fallback ): array {
		$row    = $fallback;
		$tracks = array();
		foreach ( $fact['order'] as $index ) {
			$item  = $fact['items'][ $index ];
			$grow  = $item['flex_grow'] ?? '';
			$basis = $item['flex_basis'] ?? '0';
			if ( ! is_string( $grow ) || ! preg_match( '/^(?:\\d+(?:\\.\\d+)?|\\.\\d+)$/D', $grow ) || (float) $grow <= 0 || ( '' === self::absolute_length( $basis ) && ! in_array( $basis, array( '0%', '0.0%' ), true ) ) ) {
				$tracks = array();
				break;
			}
			$tracks[] = 'minmax(' . ( in_array( $basis, array( '0%', '0.0%' ), true ) ? '0' : $basis ) . ',' . $grow . 'fr)';
		}
		if ( 2 === count( $tracks ) && 'wrap' !== ( $fact['styles']['flex_wrap'] ?? '' ) ) {
			$row['grid-template-columns'] = implode( ' ', $tracks );
		}
		$gap = self::absolute_length( $fact['styles']['column_gap'] ?? $fact['styles']['gap'] ?? '' );
		if ( '' !== $gap ) {
			$row['column-gap'] = $gap;
		}
		return $row;
	}

	/** Small layout-only vocabulary, also used to verify serialized receipts. */
	private static function layout_declarations( array $styles ): string {
		$css = '';
		foreach ( $styles as $property => $value ) {
			$allowed = array(
				'width'      => array( '100%' ),
				'max-width'  => array( 'none' ),
				'min-width'  => array( '0' ),
				'box-sizing' => array( 'border-box' ),
				'display'    => array( 'block', 'flex', 'grid' ),
				'padding'    => array( '0' ),
				'margin'     => array( '0' ),
				'overflow'   => array( 'visible' ),
			);
			$track   = 'grid-template-columns' === $property && is_string( $value ) && ( 'auto minmax(0,1fr)' === $value || preg_match( '/^(?:minmax\\(0,1fr\\) minmax\\(0,(?:0|\\d+(?:\\.\\d+)?px)\\)|(?:0|\\d+(?:\\.\\d+)?px) minmax\\(0,1fr\\))$/D', $value ) );
			$track   = $track || ( 'grid-template-columns' === $property && is_string( $value ) && preg_match( '/^minmax\\((?:0|\\d+(?:\\.\\d+)?px),(?:\\d+(?:\\.\\d+)?|\\.\\d+)fr\\) minmax\\((?:0|\\d+(?:\\.\\d+)?px),(?:\\d+(?:\\.\\d+)?|\\.\\d+)fr\\)$/D', $value ) && ! preg_match( '/,0(?:\\.0+)?fr\\)/', $value ) );
			$gap     = 'column-gap' === $property && '' !== self::length( $value );
			if ( $track || $gap || in_array( $value, $allowed[ $property ] ?? array(), true ) ) {
				$css .= $property . ':' . $value . ' !important;';
			}
		}
		return $css;
	}

	/** Read serialized inline presentation, not source-platform selectors. */
	private static function inline_styles( string $html ): array {
		$styles = array();
		if ( preg_match( '/\bstyle=["\x27]([^"\x27]*)["\x27]/i', $html, $match ) ) {
			foreach ( explode( ';', html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) ) as $declaration ) {
				$pair = explode( ':', $declaration, 2 );
				if ( 2 === count( $pair ) ) {
					$styles[ trim( strtolower( $pair[0] ) ) ] = trim( $pair[1] );
				}
			}
		}
		return $styles;
	}

	/** Admit only captured lengths, never arbitrary CSS syntax. */
	private static function length( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh))$/D', $value ) ? $value : '';
	}

	/** Only captured pixel lengths are independent of the container and font. */
	private static function absolute_length( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^(?:0|\d+(?:\.\d+)?px)$/D', $value ) ? $value : '';
	}

	/** Admit only positive numeric ratios, including the cascade's spaced form. */
	private static function aspect_ratio( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/D', $value, $parts ) || (float) $parts[1] <= 0 || (float) $parts[2] <= 0 ) {
			return '';
		}
		return $value;
	}

	/** Capture image boxes and strip arrangement before the source subtree is deleted. */
	public static function gallery_attrs( array $node ): array {
		$attrs    = $node['attrs'] ?? array();
		$authored = str_ends_with( (string) ( $node['blockName'] ?? '' ), '/authored-carousel' );
		if ( $authored && ( self::aspect_ratio( $attrs['stageAspectRatio'] ?? '' ) || self::length( $attrs['thumbnailWidth'] ?? '' ) ) ) {
			return array(
				'main'         => array( 'aspectRatio' => self::aspect_ratio( $attrs['stageAspectRatio'] ?? '' ) ),
				'thumbnail'    => empty( $attrs['thumbnails'] ) ? array() : array( 'width' => self::length( $attrs['thumbnailWidth'] ?? '' ) ),
				'position'     => array(
					'left'   => 'left',
					'right'  => 'right',
					'top'    => 'above',
					'bottom' => 'below',
				)[ $attrs['thumbnailPosition'] ?? '' ] ?? 'below',
				'gap'          => self::length( $attrs['stageGap'] ?? '' ),
				'thumbnailGap' => self::length( $attrs['thumbnailGap'] ?? '' ),
			);
		}
		$boxes = array();
		$gaps  = array();
		$walk  = static function ( array $block ) use ( &$walk, &$boxes, &$gaps ): void {
			$start = count( $boxes );
			$html  = $block['innerHTML'] ?? '';
			preg_match_all( '/<img\b[^>]*>/i', $html, $images );
			foreach ( $images[0] as $image ) {
				$css = self::inline_styles( $image );
				$box = array();
				foreach ( array( 'width', 'height' ) as $dimension ) {
					$value = $css[ $dimension ] ?? $block['attrs']['style']['dimensions'][ $dimension ] ?? $block['attrs'][ $dimension ] ?? '';
					if ( '' === $value && preg_match( '/\b' . $dimension . '=["\x27](\d+(?:\.\d+)?)["\x27]/i', $image, $match ) ) {
						$value = $match[1] . 'px';
					}
					$box[ $dimension ] = self::length( is_numeric( $value ) ? $value . 'px' : $value );
				}
				$ratio = self::aspect_ratio( $css['aspect-ratio'] ?? $block['attrs']['style']['dimensions']['aspectRatio'] ?? '' );
				$fit   = $css['object-fit'] ?? $block['attrs']['style']['dimensions']['objectFit'] ?? '';
				if ( $ratio ) {
					$box['aspectRatio'] = $ratio;
				}
				if ( in_array( $fit, array( 'cover', 'contain' ), true ) ) {
					$box['objectFit'] = $fit;
				}
				if ( $ratio || ( str_ends_with( $box['width'], 'px' ) && str_ends_with( $box['height'], 'px' ) ) ) {
					$boxes[] = $box;
				}
			}
			foreach ( $block['innerBlocks'] ?? array() as $child ) {
				$walk( $child );
			}
			$css = self::inline_styles( $html );
			$gap = self::length( $css['gap'] ?? $block['attrs']['style']['spacing']['blockGap'] ?? '' );
			if ( '' !== $gap && count( $boxes ) > $start ) {
				$gaps[] = array(
					'boxes' => array_slice( $boxes, $start ),
					'gap'   => $gap,
				);
			}
		};
		$walk( $node );
		if ( ! $boxes ) {
			return $authored ? array( 'authored' => true ) : array();
		}
		$first = $boxes[0];
		usort( $boxes, static fn( $a, $b ) => (float) $a['width'] <=> (float) $b['width'] );
		$main        = $boxes[ count( $boxes ) - 1 ];
		$thumb       = (float) $boxes[0]['width'] < (float) $main['width'] / 2 ? $boxes[0] : array();
		$root        = self::inline_styles( $node['innerHTML'] ?? '' );
		$direction   = $root['flex-direction'] ?? ( 'vertical' === ( $node['attrs']['layout']['orientation'] ?? '' ) ? 'column' : ( 'flex' === ( $node['attrs']['layout']['type'] ?? '' ) ? 'row' : '' ) );
		$thumb_first = $first === $thumb;
		if ( 'row-reverse' === $direction ) {
			$thumb_first = ! $thumb_first;
		}
		$thumbnail_gap = '';
		foreach ( $gaps as $group ) {
			if ( $thumb && count( $group['boxes'] ) > 1 && ! array_filter( $group['boxes'], static fn( $box ) => $box !== $thumb ) ) {
				$thumbnail_gap = $group['gap'];
				break;
			}
		}
		return array(
			'main'         => $main,
			'thumbnail'    => $thumb,
			'position'     => in_array( $direction, array( 'row', 'row-reverse' ), true ) ? ( $thumb_first ? 'left' : 'right' ) : 'below',
			'gap'          => self::length( $root['gap'] ?? $node['attrs']['style']['spacing']['blockGap'] ?? '' ),
			'thumbnailGap' => $thumbnail_gap,
		);
	}

	/** Gallery-only property allowlist; shared by capture and receipt validation. */
	private static function gallery_declarations( array $styles ): string {
		$css     = self::declarations( $styles );
		$allowed = array(
			'display'        => array( 'flex' ),
			'flex-direction' => array( 'row', 'column' ),
			'flex-shrink'    => array( '0' ),
			'flex'           => array( '1 1 0', '0 0 auto' ),
			'float'          => array( 'none' ),
			'order'          => array( '-1', '0' ),
			'object-fit'     => array( 'contain', 'cover' ),
			'flex-basis'     => array(),
			'gap'            => array(),
			'margin'         => array(),
			'aspect-ratio'   => array(),
		);
		foreach ( $allowed as $property => $values ) {
			$value  = $styles[ $property ] ?? '';
			$length = in_array( $property, array( 'flex-basis', 'gap', 'margin' ), true ) && self::length( $value );
			$ratio  = 'aspect-ratio' === $property && self::aspect_ratio( $value );
			if ( is_string( $value ) && ( $length || $ratio || in_array( $value, $values, true ) ) ) {
				$css .= $property . ':' . $value . ' !important;';
			}
		}
		return $css;
	}

	/** Project captured geometry onto the native Woo gallery, never frozen markup. */
	private static function gallery_rules( array $gallery ): array {
		$width  = self::length( $gallery['main']['width'] ?? '' );
		$height = self::length( $gallery['main']['height'] ?? '' );
		$ratio  = self::aspect_ratio( $gallery['main']['aspectRatio'] ?? '' );
		// Preserve the source width cascade, but not its carousel-only layout.
		$wrapper = $gallery ? array(
			'layout:gallery-block' => self::layout_declarations( array(
				'display'    => 'block',
				'max-width'  => 'none',
				'min-width'  => '0',
				'box-sizing' => 'border-box',
				'overflow'   => 'visible',
			) ),
		) : array();
		if ( ( ! $width || ! $height ) && ! $ratio ) {
			return $wrapper ? array_merge( $wrapper, array(
				'gallery' => self::gallery_declarations( array(
					'width' => '100%',
					'float' => 'none',
				) ),
			) ) : array();
		}
		$side   = in_array( $gallery['position'] ?? '', array( 'left', 'right' ), true );
		$styles = array(
			'gallery'  => array(
				'width'          => '100%',
				'display'        => 'flex',
				'flex-direction' => $side ? 'row' : 'column',
				'float'          => 'none',
				'gap'            => self::length( $gallery['gap'] ?? '' ),
			),
			'viewport' => array(
				'width'      => $width,
				'height'     => $height,
				'max_width'  => '100%',
				'flex-basis' => $side ? $width : '',
			),
			'image'    => array(
				'width'      => '100%',
				'height'     => $height,
				'object-fit' => 'contain',
			),
		);
		if ( $ratio ) {
			$styles['viewport']              = array(
				'width'        => $side ? 'auto' : '100%',
				'flex'         => $side ? '1 1 0' : '0 0 auto',
				'min-width'    => '0',
				'aspect-ratio' => $ratio,
			);
			$styles['image']['height']       = '100%';
			$styles['image']['aspect-ratio'] = $ratio;
			$styles['image']['object-fit']   = 'cover';
		}
		if ( in_array( $gallery['main']['objectFit'] ?? '', array( 'cover', 'contain' ), true ) ) {
			$styles['image']['object-fit'] = $gallery['main']['objectFit'];
		}
		$thumb_w = self::length( $gallery['thumbnail']['width'] ?? '' );
		$thumb_h = self::length( $gallery['thumbnail']['height'] ?? '' );
		if ( $thumb_w && ( $thumb_h || $ratio ) ) {
			$styles['thumbs'] = array(
				'padding'        => '0',
				'display'        => 'flex',
				'flex-direction' => $side ? 'column' : 'row',
				'flex-shrink'    => '0',
				'gap'            => self::length( $gallery['thumbnailGap'] ?? '' ),
				'order'          => in_array( $gallery['position'] ?? '', array( 'left', 'above' ), true ) ? '-1' : '0',
				'margin'         => '0',
			);
			$styles['thumb']  = array(
				'width'  => $thumb_w,
				'height' => $thumb_h,
				'float'  => 'none',
				'margin' => '0',
			);
			if ( ! $thumb_h ) {
				$styles['thumb']['aspect-ratio'] = $ratio;
				$styles['thumb']['flex-shrink']  = '0';
				if ( $side ) {
					$styles['thumbs']['width'] = $thumb_w;
				}
			}
			$styles['thumbimg'] = array(
				'width'      => '100%',
				'height'     => '100%',
				'object-fit' => 'contain',
			);
		}
		return array_merge( $wrapper, array_map( array( self::class, 'gallery_declarations' ), $styles ) );
	}

	/** Consume the producer's block-style presentation as well as graph facts. */
	private static function block_styles( array $style ): array {
		$styles = array();
		$groups = array(
			'color'      => array(
				'text'       => 'color',
				'background' => 'background_color',
			),
			'typography' => array(
				'fontSize'      => 'font_size',
				'fontFamily'    => 'font_family',
				'fontWeight'    => 'font_weight',
				'fontStyle'     => 'font_style',
				'lineHeight'    => 'line_height',
				'letterSpacing' => 'letter_spacing',
				'textTransform' => 'text_transform',
			),
			'border'     => array(
				'color'  => 'border_color',
				'width'  => 'border_width',
				'style'  => 'border_style',
				'radius' => 'border_radius',
			),
			'dimensions' => array(
				'width'     => 'width',
				'height'    => 'height',
				'minHeight' => 'min_height',
			),
		);
		foreach ( $groups as $group => $properties ) {
			foreach ( $properties as $key => $property ) {
				if ( isset( $style[ $group ][ $key ] ) && is_string( $style[ $group ][ $key ] ) ) {
					$styles[ $property ] = $style[ $group ][ $key ];
				}
			}
		}
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $style['spacing']['padding'][ $side ] ) ) {
				$styles[ 'padding_' . $side ] = $style['spacing']['padding'][ $side ];
			}
		}
		return $styles;
	}

	/** Allowlisted computed CSS, with values unable to escape a declaration. */
	private static function declarations( array $styles ): string {
		$properties = array( 'width', 'height', 'min_width', 'max_width', 'min_height', 'max_height', 'box_sizing', 'border', 'border_width', 'border_style', 'border_color', 'border_radius', 'background', 'background_color', 'background_image', 'background_position', 'background_repeat', 'background_size', 'color', 'font_family', 'font_size', 'font_weight', 'font_style', 'line_height', 'letter_spacing', 'text_transform', 'text_align', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'box_shadow' );
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			foreach ( array( 'width', 'style', 'color' ) as $part ) {
				$properties[] = 'border_' . $side . '_' . $part;
			}
		}
		foreach ( array( 'top_left', 'top_right', 'bottom_left', 'bottom_right' ) as $corner ) {
			$properties[] = 'border_' . $corner . '_radius';
		}
		$css = '';
		foreach ( $properties as $property ) {
			$value = $styles[ $property ] ?? $styles[ str_replace( '_', '-', $property ) ] ?? null;
			// Only site-root asset paths may paint; the theme asset map rewrites them.
			$probe = is_string( $value ) ? (string) preg_replace( '#url\(/(?!/)(?:(?!\.\.)[A-Za-z0-9_./-])+\)#', '', $value ) : '';
			if ( ! is_string( $value ) || '' === trim( $value ) || preg_match( '/[;{}<>\\\\]|\/\*|url\s*\(|expression\s*\(|@/i', $probe ) ) {
				continue;
			}
			$css .= str_replace( '_', '-', $property ) . ':' . trim( $value ) . ' !important;';
		}
		return $css;
	}

	/** Stored wording is canonical plain text, never HTML or executable syntax. */
	public static function validate_wording( mixed $wording ): ?array {
		if ( ! is_array( $wording ) ) {
			return null;
		}
		foreach ( $wording as $attribute => $row ) {
			if ( ! is_string( $attribute ) || ! preg_match( '/^[a-z0-9_%\-]+$/D', $attribute ) || ! is_array( $row ) || ! $row ) {
				return null;
			}
			foreach ( $row as $key => $text ) {
				if ( ! in_array( $key, array( 'label', 'placeholder' ), true ) || ! is_string( $text ) || '' === $text || trim( $text ) !== $text || preg_match( '/[<>\x00-\x1f\x7f]|\s{2,}/u', $text ) || html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) !== $text ) {
					return null;
				}
			}
		}
		return $wording;
	}

	/** Rebuild a structured artifact before allowing stylesheet publication. */
	public static function validate_overlay( mixed $overlay ): ?array {
		if ( ! is_array( $overlay ) || 'static-site-importer/product-presentation/v1' !== ( $overlay['schema'] ?? '' ) || ! is_array( $overlay['rules'] ?? null ) ) {
			return null;
		}
		$controls = array();
		$gallery  = array();
		$layout   = array();
		$frames   = array();
		foreach ( $overlay['rules'] as $slot => $css ) {
			list( $base_slot, $media ) = array_pad( explode( '@', $slot, 2 ), 2, '' );
			$is_conditional            = '' !== $media && 1 === preg_match( '/^(?:variation:\d+|quantity|submit|submit-layer)$/D', $base_slot );
			if ( '' !== $media && ( ! ( $is_conditional || in_array( $base_slot, array( 'layout:purchase-row', 'layout:simple-row' ), true ) ) || '' === self::media_query(
				array(
					'kind'  => 'media',
					'query' => $media,
				)
			) ) ) {
				return null;
			}
			$is_gallery = in_array( $slot, array( 'gallery', 'viewport', 'image', 'thumbs', 'thumb', 'thumbimg' ), true );
			$is_layout  = str_starts_with( $slot, 'layout:' ) && isset( self::LAYOUT_SELECTORS[ substr( $base_slot, 7 ) ] );
			if ( ! is_string( $css ) || ( ! $is_gallery && ! $is_layout && ! $is_conditional && ! preg_match( '/^(?:variation:\d+|quantity|quantity-frame|submit|submit-host|submit-layer)$/D', $slot ) ) ) {
				return null;
			}
			$styles = array();
			foreach ( explode( ';', $css ) as $declaration ) {
				if ( '' === $declaration ) {
					continue;
				}
				$pair = explode( ':', $declaration, 2 );
				if ( 2 !== count( $pair ) || ! str_ends_with( $pair[1], ' !important' ) ) {
					return null;
				}
				$styles[ $pair[0] ] = substr( $pair[1], 0, -11 );
			}
			if ( $is_conditional ) {
				$styles          = array_combine( array_map( static fn( $key ) => str_replace( '-', '_', $key ), array_keys( $styles ) ), $styles );
				$frames[ $slot ] = 'submit-layer' === $base_slot ? self::layer_paint( $styles ) : self::declarations( $styles );
				continue;
			}
			if ( 'quantity-frame' === $slot ) {
				$frames[ $slot ] = self::frame_declarations( $styles );
				continue;
			}
			if ( 'submit-host' === $slot ) {
				$frames[ $slot ] = self::host_declarations();
				continue;
			}
			if ( 'submit-layer' === $slot ) {
				$frames[ $slot ] = self::layer_declarations( array_combine( array_map( static fn( $key ) => str_replace( '-', '_', $key ), array_keys( $styles ) ), $styles ) );
				continue;
			}
			if ( $is_layout ) {
				$layout[ $slot ] = self::layout_declarations( $styles );
				continue;
			}
			if ( $is_gallery ) {
				$gallery[ $slot ] = self::gallery_declarations( $styles );
				continue;
			}
			$controls[] = array(
				'tag'    => str_starts_with( $slot, 'variation:' ) ? 'select' : 'input',
				'type'   => 'quantity' === $slot ? 'number' : 'submit',
				'styles' => $styles,
			);
		}
		$expected = self::capture( array( 'controls' => $controls ) );
		$rules    = array_filter( $expected['rules'] ?? array(), static fn( $slot ) => ! str_starts_with( $slot, 'layout:' ), ARRAY_FILTER_USE_KEY );
		$expected = self::artifact( array_merge( $rules, $layout, $frames, $gallery ) );
		if ( array_key_exists( 'variation_wording', $overlay ) ) {
			$wording = self::validate_wording( $overlay['variation_wording'] );
			if ( ! $wording ) {
				return null;
			}
			$expected                      = $expected ? $expected : self::artifact( array(), true );
			$expected['variation_wording'] = $wording;
		}
		if ( array_key_exists( 'purchase_wording', $overlay ) ) {
			$wording = self::validate_wording( $overlay['purchase_wording'] );
			if ( ! $wording || array_diff_key( $wording, array( 'quantity' => true, 'submit' => true ) ) || array_filter( $wording, static fn( array $row ): bool => array( 'label' ) !== array_keys( $row ) ) ) {
				return null;
			}
			$expected                     = $expected ? $expected : self::artifact( array(), true );
			$expected['purchase_wording'] = $wording;
		}
		return $expected === $overlay ? $expected : null;
	}
}
