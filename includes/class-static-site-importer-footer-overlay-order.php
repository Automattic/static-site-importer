<?php
/**
 * Places fixed overlays that trail a footer template part before the reference.
 *
 * A source page often ends with a floating overlay (a chat or back-to-top
 * button) after its footer. The overlay is fixed to the viewport, so its
 * document position does not affect layout. In a generated template, though,
 * it is a sibling after the footer template-part reference. The block editor
 * loads template parts asynchronously and renders the overlay first: until
 * the footer part arrives, the canvas lists the overlay right after the post
 * content, in a different order from the front end. With the overlay before
 * the footer reference, both canvases list the same blocks in the same order
 * while the part loads, and the front end renders the same.
 *
 * Only a block whose own class rules give it `position: fixed`, anchored on
 * both axes, moves. Any other trailing block stays after the footer, because
 * moving an in-flow block (or a fixed one placed at its static position)
 * would change the layout.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;

/** Fixed overlays after a footer template part move ahead of the reference. */
final class Static_Site_Importer_Footer_Overlay_Order {

	/** Block-markup fields of a resolved template. */
	private const TEMPLATE_FIELDS = array( 'canonical_block_markup', 'resolved_block_markup', 'materialized_block_markup' );

	/**
	 * Reorder every generated template of a resolved plan. Each serialization
	 * of a template (canonical, resolved and its theme write) moves the same
	 * blocks, so later passes that pair them block by block stay aligned.
	 *
	 * @param array<string,mixed> $resolved Resolved plan projection.
	 * @return array<string,mixed> The projection with overlays ahead of footers.
	 */
	public static function apply( array $resolved ): array {
		$fixed = self::anchored_fixed_classes( is_array( $resolved['writes'] ?? null ) ? $resolved['writes'] : array() );
		if ( array() === $fixed ) {
			return $resolved;
		}
		$footers = array();
		foreach ( is_array( $resolved['template_parts'] ?? null ) ? $resolved['template_parts'] : array() as $part ) {
			if ( is_array( $part ) && 'footer' === ( $part['area'] ?? null ) && is_string( $part['slug'] ?? null ) ) {
				$footers[ $part['slug'] ] = true;
			}
		}
		foreach ( is_array( $resolved['templates'] ?? null ) ? $resolved['templates'] : array() as $index => $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			foreach ( self::TEMPLATE_FIELDS as $field ) {
				if ( is_string( $template[ $field ] ?? null ) ) {
					$resolved['templates'][ $index ][ $field ] = self::reorder( $template[ $field ], $fixed, $footers );
				}
			}
		}
		foreach ( is_array( $resolved['writes'] ?? null ) ? $resolved['writes'] : array() as $index => $write ) {
			if ( ! is_array( $write ) || ! str_starts_with( (string) ( $write['target_path'] ?? '' ), 'templates/' ) || 'utf8' !== ( $write['payload']['encoding'] ?? null ) || ! is_string( $write['payload']['data'] ?? null ) ) {
				continue;
			}
			$data = self::reorder( $write['payload']['data'], $fixed, $footers );
			if ( $data !== $write['payload']['data'] ) {
				$resolved['writes'][ $index ]['payload']['data'] = $data;
				$resolved['writes'][ $index ]['payload_hash']    = hash( 'sha256', $data );
			}
			if ( is_string( $write['canonical_payload'] ?? null ) ) {
				$canonical = self::reorder( $write['canonical_payload'], $fixed, $footers );
				if ( $canonical !== $write['canonical_payload'] ) {
					$resolved['writes'][ $index ]['canonical_payload']      = $canonical;
					$resolved['writes'][ $index ]['canonical_payload_hash'] = hash( 'sha256', $canonical );
				}
			}
		}
		return $resolved;
	}

	/**
	 * Move each anchored fixed block that follows a footer template-part
	 * reference, among the reference's siblings, to just before it.
	 *
	 * @param string             $markup  Serialized block document.
	 * @param array<string,bool> $fixed   Classes whose own rules fix the element to the viewport.
	 * @param array<string,bool> $footers Slugs of footer-area template parts.
	 */
	public static function reorder( string $markup, array $fixed, array $footers = array() ): string {
		if ( array() === $fixed || ! str_contains( $markup, 'wp:template-part' ) ) {
			return $markup;
		}
		// Each pass moves blocks strictly earlier, so the loop ends.
		for ( $pass = 0; $pass < 64; ++$pass ) {
			$next = self::reorder_once( $markup, $fixed, $footers );
			if ( $next === $markup ) {
				break;
			}
			$markup = $next;
		}
		return $markup;
	}

	/** One move: the overlays trailing the first footer reference that has any. */
	private static function reorder_once( string $markup, array $fixed, array $footers ): string {
		$index   = new BlockMarkupIndex( $markup );
		$openers = array();
		foreach ( $index->tokens() as $token ) {
			if ( ! $token['closing'] ) {
				$openers[ $token['offset'] ] = $token;
			}
		}
		foreach ( $openers as $offset => $token ) {
			if ( 'template-part' !== $token['name'] || ! $token['self_closing'] || ! self::is_footer_reference( $token['attributes'], $footers ) ) {
				continue;
			}
			$reference = array(
				'offset' => $offset,
				'length' => strlen( $token['token'] ),
			);
			$parent    = $index->parent( $reference );
			$siblings  = null === $parent ? $index->topLevel() : $index->children(
				array(
					'offset' => $parent['offset'],
					'length' => $parent['length'],
				)
			);
			$overlays  = array();
			foreach ( $siblings as $sibling ) {
				if ( $sibling['offset'] > $offset && isset( $openers[ $sibling['offset'] ] ) && self::is_fixed_block( $openers[ $sibling['offset'] ]['attributes'], $fixed ) ) {
					$overlays[] = $sibling;
				}
			}
			if ( array() === $overlays ) {
				continue;
			}
			$moved = '';
			foreach ( $overlays as $overlay ) {
				$moved .= substr( $markup, $overlay['offset'], $overlay['length'] );
			}
			foreach ( array_reverse( $overlays ) as $overlay ) {
				$markup = substr( $markup, 0, $overlay['offset'] ) . substr( $markup, $overlay['offset'] + $overlay['length'] );
			}
			return substr( $markup, 0, $offset ) . $moved . substr( $markup, $offset );
		}
		return $markup;
	}

	/** @param array<string,bool> $footers Footer-area part slugs. */
	private static function is_footer_reference( string $attributes, array $footers ): bool {
		$decoded = json_decode( '' === $attributes ? '{}' : $attributes, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}
		return 'footer' === ( $decoded['area'] ?? null ) || ( is_string( $decoded['slug'] ?? null ) && isset( $footers[ $decoded['slug'] ] ) );
	}

	/** @param array<string,bool> $fixed Anchored fixed classes. */
	private static function is_fixed_block( string $attributes, array $fixed ): bool {
		$decoded = json_decode( '' === $attributes ? '{}' : $attributes, true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['className'] ?? null ) ) {
			return false;
		}
		$classes = preg_split( '/\s+/', trim( $decoded['className'] ) );
		if ( false === $classes ) {
			return false;
		}
		foreach ( $classes as $class ) {
			if ( isset( $fixed[ $class ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classes whose unconditional rules (outside any at-rule) select only that
	 * class and fix it to the viewport with an inset on both axes. A class that
	 * any other rule, conditional or compound, gives another position is left
	 * out: at some width or state it would be in flow.
	 *
	 * @param array<int|string,mixed> $writes Resolved theme writes.
	 * @return array<string,bool>
	 */
	public static function anchored_fixed_classes( array $writes ): array {
		$fixed = array();
		$other = array();
		foreach ( $writes as $write ) {
			if ( ! is_array( $write ) || ! str_ends_with( (string) ( $write['target_path'] ?? '' ), '.css' ) || ! is_string( $write['payload']['data'] ?? null ) ) {
				continue;
			}
			$css = $write['payload']['data'];
			if ( 'base64' === ( $write['payload']['encoding'] ?? null ) ) {
				$css = (string) base64_decode( $css, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reads a declared stylesheet payload.
			}
			if ( ! str_contains( $css, 'position' ) ) {
				continue;
			}
			foreach ( self::style_rules( preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? $css, false ) as $rule ) {
				$declarations = self::declarations( $rule['body'] );
				if ( ! isset( $declarations['position'] ) ) {
					continue;
				}
				$is_fixed = 'fixed' === strtolower( $declarations['position'] );
				foreach ( explode( ',', $rule['prelude'] ) as $selector ) {
					$selector = trim( $selector );
					preg_match_all( '/\.((?:[A-Za-z_-]|\\\\.)(?:[\w-]|\\\\.)*)/', $selector, $classes );
					$single = array() !== $classes[1] && 1 === count( array_unique( $classes[1] ) ) && str_repeat( '.' . $classes[1][0], count( $classes[1] ) ) === $selector;
					if ( $is_fixed && $single && ! $rule['conditional'] && self::anchored( $declarations ) ) {
						$fixed[ $classes[1][0] ] = true;
					} elseif ( ! $is_fixed ) {
						$other += array_fill_keys( $classes[1], true );
					}
				}
			}
		}
		return array_diff_key( $fixed, $other );
	}

	/**
	 * Both axes carry an inset other than auto, so the static position never applies.
	 *
	 * @param array<string,string> $declarations Declarations of one rule.
	 */
	private static function anchored( array $declarations ): bool {
		$edges = array_fill_keys( array( 'top', 'right', 'bottom', 'left' ), 'auto' );
		foreach ( $declarations as $property => $value ) {
			if ( 'inset' === $property ) {
				$parts = preg_split( '/\s+/', trim( $value ) );
				if ( false === $parts ) {
					return false;
				}
				$parts = array( $parts[0], $parts[1] ?? $parts[0], $parts[2] ?? $parts[0], $parts[3] ?? $parts[1] ?? $parts[0] );
				$edges = array_combine( array_keys( $edges ), $parts );
			} elseif ( isset( $edges[ $property ] ) ) {
				$edges[ $property ] = trim( $value );
			}
		}
		$set = static fn( string $value ): bool => '' !== $value && 'auto' !== strtolower( $value );
		return ( $set( $edges['top'] ) || $set( $edges['bottom'] ) ) && ( $set( $edges['left'] ) || $set( $edges['right'] ) );
	}

	/** @return array<string,string> Declarations in order, the last of each property winning. */
	private static function declarations( string $body ): array {
		$declarations = array();
		foreach ( explode( ';', $body ) as $declaration ) {
			$colon = strpos( $declaration, ':' );
			if ( false === $colon ) {
				continue;
			}
			$property = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			$value    = trim( preg_replace( '/!\s*important\s*$/i', '', substr( $declaration, $colon + 1 ) ) ?? '' );
			unset( $declarations[ $property ] );
			$declarations[ $property ] = $value;
		}
		return $declarations;
	}

	/**
	 * Style rules of a stylesheet, descending into at-rule blocks.
	 *
	 * @return list<array{prelude:string,body:string,conditional:bool}>
	 */
	private static function style_rules( string $css, bool $conditional ): array {
		$rules  = array();
		$length = strlen( $css );
		$offset = 0;
		while ( $offset < $length && preg_match( '/\G\s*([^{}]+)\{/', $css, $match, 0, $offset ) ) {
			$start = $offset + strlen( $match[0] );
			$depth = 1;
			$end   = $start;
			while ( $end < $length && 0 < $depth ) {
				$depth += '{' === $css[ $end ] ? 1 : ( '}' === $css[ $end ] ? -1 : 0 );
				++$end;
			}
			if ( 0 !== $depth ) {
				break;
			}
			// An at-statement (`@import ...;`) ahead of a rule ends at its semicolon.
			$prelude = trim( $match[1] );
			$prelude = trim( false === strrpos( $prelude, ';' ) ? $prelude : substr( $prelude, strrpos( $prelude, ';' ) + 1 ) );
			$body    = substr( $css, $start, $end - 1 - $start );
			if ( str_starts_with( $prelude, '@' ) ) {
				if ( str_contains( $body, '{' ) ) {
					$rules = array_merge( $rules, self::style_rules( $body, true ) );
				}
			} else {
				$rules[] = array(
					'prelude'     => $prelude,
					'body'        => $body,
					'conditional' => $conditional,
				);
			}
			$offset = $end;
		}
		return $rules;
	}
}
