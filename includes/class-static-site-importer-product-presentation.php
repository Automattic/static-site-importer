<?php
/**
 * Captured purchase-control presentation at the WooCommerce ownership boundary.
 *
 * @package StaticSiteImporter
 */
defined( 'ABSPATH' ) || exit;

final class Static_Site_Importer_Product_Presentation {
	private const SELECTORS = array(
		'variation' => 'select',
		'quantity'  => 'input.qty',
		'submit'    => '.single_add_to_cart_button',
	);

	/** Project only presentation, never source selectors or purchase behavior. */
	public static function capture( array $entity ): array {
		$rules = array();
		foreach ( $entity['controls'] ?? array() as $index => $control ) {
			$tag  = strtolower( (string) ( $control['tag'] ?? '' ) );
			$type = strtolower( (string) ( $control['type'] ?? '' ) );
			$name = strtolower( (string) ( $control['name'] ?? '' ) );
			$role = 'select' === $tag ? 'variation' : ( 'input' === $tag && ( 'number' === $type || 'quantity' === $name ) ? 'quantity' : ( 'submit' === $type || ( 'button' === $tag && '' === $type ) ? 'submit' : '' ) );
			if ( '' === $role ) {
				continue;
			}
			$styles = $control['styles'] ?? self::block_styles( $control['presentation']['style'] ?? array() );
			foreach ( $entity['presentation_graph']['controls'] ?? array() as $row ) {
				if ( ( $row['index'] ?? null ) === $index ) {
					$styles = $row['control']['styles'] ?? $styles;
					break;
				}
			}
			$declarations = self::declarations( $styles );
			if ( $declarations ) {
				// Each variation may have its own captured design; do not flatten
				// several source selects onto the last control's presentation.
				$slot           = 'variation' === $role ? $role . ':' . count( array_filter( array_keys( $rules ), static fn( $key ) => str_starts_with( $key, 'variation:' ) ) ) : $role;
				$rules[ $slot ] = $declarations;
			}
		}
		if ( ! $rules ) {
			return array();
		}
		$hash  = substr( hash( 'sha256', (string) json_encode( $rules ) ), 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic standalone contract.
		$class = 'ssi-product-' . $hash;
		$css   = '';
		foreach ( $rules as $slot => $declarations ) {
			$parts    = explode( ':', $slot );
			$selector = self::SELECTORS[ $parts[0] ];
			if ( 'variation' === $parts[0] ) {
				$selector = '.variations tr:nth-child(' . ( (int) $parts[1] + 1 ) . ') select';
			}
			$css .= '.' . $class . '.' . $class . ' ' . $selector . '{' . $declarations . '}';
		}
		return array(
			'schema'    => 'static-site-importer/product-presentation/v1',
			'className' => $class,
			'rules'     => $rules,
			'css'       => $css,
		);
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
		$properties = array( 'width', 'height', 'min_width', 'max_width', 'min_height', 'max_height', 'box_sizing', 'border', 'border_width', 'border_style', 'border_color', 'border_radius', 'background', 'background_color', 'color', 'font_family', 'font_size', 'font_weight', 'font_style', 'line_height', 'letter_spacing', 'text_transform', 'text_align', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'box_shadow' );
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
			if ( ! is_string( $value ) || '' === trim( $value ) || preg_match( '/[;{}<>\\\\]|\/\*|url\s*\(|expression\s*\(|@/i', $value ) ) {
				continue;
			}
			$css .= str_replace( '_', '-', $property ) . ':' . trim( $value ) . ' !important;';
		}
		return $css;
	}

	/** Rebuild a structured artifact before allowing stylesheet publication. */
	public static function validate_overlay( mixed $overlay ): ?array {
		if ( ! is_array( $overlay ) || 'static-site-importer/product-presentation/v1' !== ( $overlay['schema'] ?? '' ) || ! is_array( $overlay['rules'] ?? null ) ) {
			return null;
		}
		$controls = array();
		foreach ( $overlay['rules'] as $slot => $css ) {
			if ( ! is_string( $css ) || ! preg_match( '/^(?:variation:\d+|quantity|submit)$/D', $slot ) ) {
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
			$controls[] = array(
				'tag'    => str_starts_with( $slot, 'variation:' ) ? 'select' : 'input',
				'type'   => 'quantity' === $slot ? 'number' : 'submit',
				'styles' => $styles,
			);
		}
		$expected = self::capture( array( 'controls' => $controls ) );
		return $expected === $overlay ? $expected : null;
	}
}
