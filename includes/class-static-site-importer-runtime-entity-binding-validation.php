<?php
/**
 * Validates provider entity bindings before any runtime materialization begins.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Static_Site_Importer_Classic_Theme_Projection' ) ) {
	require_once __DIR__ . '/class-static-site-importer-classic-theme-projection.php';
}

final class Static_Site_Importer_Runtime_Entity_Binding_Validation {
	/** Validate every classic source identity before dependencies or seeders run. */
	public static function preflight_classic_runtime_entity_bindings( array $projection, array $lifecycle, array $args ) {
		$bindings = array();
		$claims   = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			if ( ! is_callable( $prepared['adapter']['classic_binding_callback'] ?? null ) ) {
				return new WP_Error( 'static_site_importer_classic_provider_render_unavailable', 'Classic provider entity lacks an adapter-owned server render callback.', array( 'declaration_id' => $declaration_id ) );
			}
			$manifest = is_array( $prepared['manifest'] ?? null ) ? $prepared['manifest'] : array();
			$key      = isset( $manifest['products'] ) ? 'products' : 'forms';
			foreach ( $manifest[ $key ] ?? array() as $entity ) {
				if ( ! is_array( $entity ) ) {
					continue; }
				$source   = (string) ( $entity['source_path'] ?? '' );
				$selector = (string) ( $entity['selector'] ?? '' );
				if ( '' === $source || '' === $selector ) {
					return new WP_Error( 'static_site_importer_classic_html_binding_invalid', 'Classic provider entity lacks a canonical leaf source selector.' ); }
				$claim = $source . "\n" . $selector . "\n1";
				if ( isset( $claims[ $claim ] ) ) {
					return new WP_Error(
						'static_site_importer_classic_html_binding_duplicate',
						'Classic provider entities claim the same source DOM identity.',
						array(
							'selector'    => $selector,
							'source_path' => $source,
						)
					); }
				$claims[ $claim ] = true;
				$bindings[]       = array(
					'source_path' => $source,
					'selector'    => $selector,
					'occurrence'  => 1,
				);
			}
		}
		return Static_Site_Importer_Classic_Theme_Projection::preflight_bindings( $projection, $bindings );
	}

	/** Verify every declared source anchor before providers create or update entities. */
	public static function preflight_runtime_entity_binding_anchors( array $plan, array $lifecycle, array $args ) {
		$pages = array();
		foreach ( is_array( $plan['pages'] ?? null ) ? $plan['pages'] : array() as $page ) {
			if ( is_array( $page ) && is_string( $page['source_path'] ?? null ) ) {
				$pages[ $page['source_path'] ] = $page;
			}
		}
		$claims = array();
		$ranges = array();
		foreach ( $lifecycle['entities'] as $declaration_id => $prepared ) {
			$waiver_arg = (string) ( $prepared['adapter']['waiver_arg'] ?? '' );
			if ( '' !== $waiver_arg && ! empty( $args[ $waiver_arg ] ) ) {
				continue;
			}
			$manifest = isset( $prepared['manifest'] ) && is_array( $prepared['manifest'] ) ? $prepared['manifest'] : array();
			$entities = isset( $manifest['products'] ) && is_array( $manifest['products'] ) ? $manifest['products'] : ( isset( $manifest['forms'] ) && is_array( $manifest['forms'] ) ? $manifest['forms'] : array() );
			foreach ( $entities as $entity ) {
				$entity_bindings = is_array( $entity ) && is_array( $entity['bindings'] ?? null ) ? $entity['bindings'] : array();
				foreach ( $entity_bindings as $binding ) {
					if ( empty( $binding ) ) {
						continue;
					}
					// A `commerce_collection` binding anchors a detected product
					// grid region shared by every one of its member products, so
					// the entity/binding registry can resolve that one shared
					// anchor to one native product-display block carrying every
					// seeded member. A single product can legitimately belong to
					// more than one grid at once (real content overlap, e.g. the
					// same product shown on a homepage grid and a catalog grid),
					// in which case it carries one such binding per grid, each
					// anchored to that grid's own distinct source-page region —
					// so this is judged per individual binding, not by how many
					// bindings the owning entity carries in total. Every other
					// binding role (a single-entity claim, e.g. one product's own
					// `[add_to_cart]` anchor) keeps this gate's existing
					// one-claim-per-occurrence uniqueness requirement unchanged:
					// two of those on the same occurrence is the real corruption
					// this gate protects against, and still conflicts.
					$is_collection_anchor = is_array( $binding ) && 'commerce_collection' === ( $binding['role'] ?? null );
					$claim                = $binding['source_path'] . "\n" . hash( 'sha256', $binding['search_block_markup'] ) . "\n" . $binding['occurrence'];
					if ( isset( $claims[ $claim ] ) ) {
						if ( $is_collection_anchor && 'commerce_collection' === $claims[ $claim ] ) {
							continue;
						}
						return new WP_Error(
							'static_site_importer_runtime_binding_claim_conflict',
							'Two provider entities claim the same canonical source-page binding occurrence.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$claims[ $claim ] = $is_collection_anchor ? 'commerce_collection' : true;
					$page             = $pages[ $binding['source_path'] ] ?? array();
					if ( ! empty( $page['skip_materialization'] ) ) {
						return new WP_Error(
							'static_site_importer_runtime_binding_target_protected',
							'A provider binding targets a protected page that cannot be materialized.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$matches = substr_count( (string) ( $page['resolved_block_markup'] ?? '' ), (string) $binding['search_block_markup'] );
					if ( $matches < (int) $binding['occurrence'] ) {
						return new WP_Error(
							'static_site_importer_runtime_binding_cardinality_mismatch',
							'A canonical provider binding does not have its declared source-page occurrence.',
							array(
								'status'         => 'rejected',
								'declaration_id' => $declaration_id,
							)
						);
					}
					$content  = (string) $page['resolved_block_markup'];
					$position = 0;
					for ( $occurrence = 0; $occurrence < (int) $binding['occurrence']; ++$occurrence ) {
						$found = strpos( $content, $binding['search_block_markup'], $position );
						if ( false === $found ) {
							return new WP_Error(
								'static_site_importer_runtime_binding_cardinality_mismatch',
								'A canonical provider binding does not have its declared source-page occurrence.',
								array(
									'status'         => 'rejected',
									'declaration_id' => $declaration_id,
								)
							);
						}
						$position = $found;
						if ( $occurrence + 1 < (int) $binding['occurrence'] ) {
							$position += strlen( $binding['search_block_markup'] );
						}
					}
					$end = $position + strlen( $binding['search_block_markup'] );
					foreach ( $ranges[ $binding['source_path'] ] ?? array() as $range ) {
						if ( $position < $range['end'] && $end > $range['start'] ) {
							return new WP_Error(
								'static_site_importer_runtime_binding_claim_conflict',
								'Provider entity bindings claim overlapping canonical source-page ranges.',
								array(
									'status'         => 'rejected',
									'declaration_id' => $declaration_id,
								)
							);
						}
					}
					$ranges[ $binding['source_path'] ][] = array(
						'start' => $position,
						'end'   => $end,
					);
				}
			}
		}
		return true;
	}
}
