<?php
/**
 * Normalizes rich-text carrier weights so the materialized cascade matches the source.
 *
 * The producer resolves a source inline's presentation in its source context. A
 * relative keyword such as `font-weight:bolder` is contextual there, but the
 * materialized carrier re-declares it inside the same semantic element the
 * source already resolved against (`<strong><mark style="font-weight:bolder">`).
 * The double relative resolution advances the computed weight one whole step
 * (400 -> 700 -> 900) compared to the source, which visibly regresses bold
 * front matter like legal disclosure lines. Consumer normalization drops an
 * inline relative weight the carrier's own nesting already provides, restoring
 * the source computation exactly.
 *
 * @package StaticSiteImporter
 */

/** Strips duplicated relative font weights from materialized rich-text carriers. */
final class Static_Site_Importer_Richtext_Weight_Normalizer {

	/**
	 * Normalize a resolved site plan projection in place and return it.
	 *
	 * Document markup and recorded runtime binding anchors are normalized with
	 * the same transform, so binding occurrence scans keep matching the
	 * materialized bytes they were recorded against.
	 *
	 * @param array<string,mixed> $resolved Resolved plan projection.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $resolved ): array {
		foreach ( array( 'pages', 'template_parts', 'templates' ) as $group ) {
			foreach ( array_keys( (array) ( $resolved[ $group ] ?? array() ) ) as $index ) {
				$markup = (string) ( $resolved[ $group ][ $index ]['resolved_block_markup'] ?? '' );
				if ( '' === $markup ) {
					continue;
				}
				$normalized = self::normalize_fragment( $markup );
				if ( $normalized !== $markup ) {
					$resolved[ $group ][ $index ]['resolved_block_markup'] = $normalized;
				}
			}
		}
		foreach ( array_keys( (array) ( $resolved['menus'] ?? array() ) ) as $index ) {
			$markup = (string) ( $resolved['menus'][ $index ]['resolved_block_markup'] ?? '' );
			if ( '' === $markup ) {
				continue;
			}
			$normalized = self::normalize_fragment( $markup );
			if ( $normalized !== $markup ) {
				$resolved['menus'][ $index ]['resolved_block_markup'] = $normalized;
			}
		}
		$resolved['runtime_declarations']      = self::normalize_binding_payloads( $resolved['runtime_declarations'] ?? array() );
		$resolved['runtime_entity_resolution'] = self::normalize_binding_payloads( $resolved['runtime_entity_resolution'] ?? array() );
		return $resolved;
	}

	/**
	 * Drop a carrier inline `font-weight:bolder` nested inside the semantic
	 * bold element that already provides the same relative step.
	 *
	 * @param string $markup Block markup fragment.
	 * @return string
	 */
	public static function normalize_fragment( string $markup ): string {
		if ( '' === $markup || ! str_contains( $markup, 'bolder' ) || ! (bool) preg_match( '/<(?:strong|b)\b/i', $markup ) ) {
			return $markup;
		}
		return (string) preg_replace_callback(
			'/<(strong|b)\b[^>]*>[\s\S]*?<\/\1>/i',
			static function ( array $match ): string {
				$scope = $match[0];
				if ( ! str_contains( $scope, 'bolder' ) ) {
					return $scope;
				}
				return (string) preg_replace_callback(
					'/<mark\b[^>]*>[\s\S]*?<\/mark>/i',
					static fn( array $carrier ): string => Static_Site_Importer_Richtext_Weight_Normalizer::strip_carrier_relative_weight( $carrier[0] ),
					$scope
				);
			},
			$markup
		);
	}

	/**
	 * Remove `font-weight:bolder` from one carrier element's inline style.
	 *
	 * Every other declaration and both quote shapes survive unchanged, and a
	 * carrier left without declarations loses its empty style attribute.
	 *
	 * @param string $carrier Full carrier element markup.
	 * @return string
	 */
	public static function strip_carrier_relative_weight( string $carrier ): string {
		$rewritten = (string) preg_replace_callback(
			'/<mark\b([^>]*)>/i',
			static function ( array $opening ): string {
				return Static_Site_Importer_Richtext_Weight_Normalizer::strip_carrier_style_attribute( $opening[0] );
			},
			$carrier
		);
		return is_string( $rewritten ) ? $rewritten : $carrier;
	}

	/**
	 * Rebuild a carrier opening tag without its duplicated relative weight.
	 *
	 * @param string $tag Full opening tag markup.
	 * @return string
	 */
	private static function strip_carrier_style_attribute( string $tag ): string {
		if ( ! preg_match( '/\bstyle\s*=\s*(["\'])([^"\']*)\1/i', $tag, $attributes ) ) {
			return $tag;
		}
		$quoter   = (string) $attributes[1];
		$original = (string) $attributes[2];
		if ( '' === $original ) {
			return $tag;
		}
		$kept = array();
		foreach ( explode( ';', $original ) as $declaration ) {
			$compact = strtolower( (string) preg_replace( '/\s+/', '', (string) $declaration ) );
			if ( 'font-weight:bolder' !== $compact ) {
				$kept[] = $declaration;
			}
		}
		if ( count( $kept ) === count( explode( ';', $original ) ) ) {
			return $tag;
		}
		$replacement = '';
		if ( '' !== trim( implode( '', $kept ) ) ) {
			$style       = trim( trim( str_replace( ';;', ';', ';' . implode( ';', $kept ) ), ';' ) );
			$replacement = ' style=' . $quoter . $style . $quoter;
		}
		$rewritten = preg_replace( '/\s*style\s*=\s*["\'][^"\']*["\']/i', $replacement, $tag, 1, $count );
		if ( 1 !== $count || ! is_string( $rewritten ) ) {
			return $tag;
		}
		return $rewritten;
	}

	/**
	 * Normalize the recorded generic runtime binding anchors inside declaration
	 * payloads and resolved entity expansions.
	 *
	 * @param array<int,mixed> $lists Runtime declaration list or entity list.
	 * @return array<int,mixed>
	 */
	private static function normalize_binding_payloads( array $lists ): array {
		foreach ( $lists as $list_index => $list ) {
			$entities = $list['payload']['entities'] ?? $list['entities'] ?? null;
			if ( ! is_array( $entities ) ) {
				continue;
			}
			$normalized = $entities;
			for ( $entity_index = 0, $count = count( $entities ); $entity_index < $count; ++$entity_index ) {
				$bindings = $entities[ $entity_index ]['bindings'] ?? null;
				if ( ! is_array( $bindings ) ) {
					continue;
				}
				foreach ( $bindings as $binding_index => $binding ) {
					if ( ! is_array( $binding ) || ! is_string( $binding['search_block_markup'] ?? null ) ) {
						continue;
					}
					$normalized_binding = self::normalize_fragment( (string) $binding['search_block_markup'] );
					if ( $normalized_binding !== $binding['search_block_markup'] ) {
						$normalized[ $entity_index ]['bindings'][ $binding_index ]['search_block_markup'] = $normalized_binding;
					}
				}
			}
			if ( $normalized !== $entities ) {
				if ( array_key_exists( 'payload', $list ) ) {
					$lists[ $list_index ]['payload']['entities'] = $normalized;
				} else {
					$lists[ $list_index ]['entities'] = $normalized;
				}
			}
		}
		return $lists;
	}
}
