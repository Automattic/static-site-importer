<?php
/**
 * Persist producer-declared navigation and resolve its exact references.
 *
 * @package StaticSiteImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Static_Site_Importer_Navigation_Entity_Materializer {
	public const TOKEN_PREFIX = '{{wordpress-site-plan:navigation:';
	public const META_KEY     = '_static_site_importer_reconciliation_identity';

	/** Admit all navigation declarations and references before destination writes. */
	public static function preflight( array $plan ): ?WP_Error {
		$entities   = array();
		$identities = array();
		foreach ( $plan['menus'] ?? array() as $menu ) {
			if ( ! isset( $menu['token'] ) ) {
				continue;
			}
			$token    = $menu['token'];
			$identity = $menu['reconciliation_identity'] ?? null;
			if ( ! is_string( $token ) || ! preg_match( '/^navigation-[a-f0-9]{16}$/', $token ) || isset( $entities[ $token ] )
				|| ! is_string( $menu['block_markup'] ?? null ) || '' === trim( $menu['block_markup'] )
				|| ! is_string( $identity ) || ! preg_match( '/^[a-f0-9]{64}$/', $identity ) || isset( $identities[ $identity ] ) ) {
				return new WP_Error( 'navigation_entity_declaration_invalid', 'Navigation declarations must have unique valid references and identities.' );
			}
			$entities[ $token ]      = 0;
			$identities[ $identity ] = true;
			$existing = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $identity, 'wp_navigation' );
			if ( $existing && 'wp_navigation' !== $existing->post_type ) {
				return new WP_Error( 'navigation_entity_identity_conflict' );
			}
		}
		$placeholder_ids = array_fill_keys( array_keys( $entities ), 1 );
		foreach ( $plan['writes'] ?? array() as $write ) {
			if ( 'utf8' === ( $write['payload']['encoding'] ?? null ) && str_contains( self::rewrite_references( $write['payload']['data'], $placeholder_ids ), self::TOKEN_PREFIX ) ) {
				return new WP_Error( 'navigation_reference_invalid' );
			}
		}
		foreach ( array( 'pages', 'template_parts', 'templates' ) as $group ) {
			foreach ( $plan[ $group ] ?? array() as $document ) {
				$markup = (string) ( $document['materialized_block_markup'] ?? $document['resolved_block_markup'] ?? $document['canonical_block_markup'] ?? '' );
				if ( str_contains( self::rewrite_references( $markup, $placeholder_ids ), self::TOKEN_PREFIX ) ) {
					return new WP_Error( 'navigation_reference_invalid' );
				}
				if ( ! preg_match_all( '/\{\{wordpress-site-plan:navigation:([^}]+)}}/', $markup, $matches ) ) {
					if ( str_contains( $markup, self::TOKEN_PREFIX ) ) {
						return new WP_Error( 'navigation_reference_invalid' );
					}
					continue;
				}
				foreach ( $matches[1] as $token ) {
					if ( ! array_key_exists( $token, $entities ) ) {
						return new WP_Error( 'navigation_reference_undeclared' );
					}
					++$entities[ $token ];
				}
			}
		}
		if ( array() !== $entities ) {
			if ( 'explicit_refs/v1' !== ( $plan['reference_semantics']['navigation_entities'] ?? null ) || in_array( 0, $entities, true ) ) {
				return new WP_Error( 'navigation_explicit_references_required' );
			}
			if ( ! post_type_exists( 'wp_navigation' ) ) {
				return new WP_Error( 'navigation_entity_post_type_unavailable' );
			}
		}
		return null;
	}

	/** @return array<string,mixed>|WP_Error */
	public static function materialize( array &$state ) {
		if ( 'classic' === ( $state['args']['theme_materialization'] ?? null ) ) {
			return $state;
		}
		$error = self::preflight( $state['resolved'] );
		if ( $error ) {
			return $error;
		}
		$refs = array();
		foreach ( $state['resolved']['menus'] ?? array() as $menu ) {
			if ( ! isset( $menu['token'] ) ) {
				continue;
			}
			$id = self::upsert( $menu, (string) ( $menu['resolved_block_markup'] ?? $menu['block_markup'] ), $state );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$refs[ $menu['token'] ] = $id;
			$state['applied']['navigation_entities'][] = array(
				'id'                      => $id,
				'token'                   => $menu['token'],
				'reconciliation_identity' => $menu['reconciliation_identity'],
			);
		}
		foreach ( $state['resolved']['writes'] ?? array() as $index => $write ) {
			if ( 'utf8' !== ( $write['payload']['encoding'] ?? null ) ) {
				continue;
			}
			$markup = self::rewrite_references( $write['payload']['data'], $refs );
			$state['resolved']['writes'][ $index ]['payload']['data'] = $markup;
			$state['resolved']['writes'][ $index ]['payload_hash'] = hash( 'sha256', $markup );
		}
		foreach ( array( 'pages', 'template_parts', 'templates' ) as $group ) {
			foreach ( $state['resolved'][ $group ] ?? array() as $index => $document ) {
				foreach ( array( 'resolved_block_markup', 'materialized_block_markup' ) as $field ) {
					if ( is_string( $document[ $field ] ?? null ) ) {
						$state['resolved'][ $group ][ $index ][ $field ] = self::rewrite_references( $document[ $field ], $refs );
					}
				}
			}
		}
		foreach ( $state['ordered_pages'] ?? array() as $index => $page ) {
			foreach ( array( 'resolved_block_markup', 'materialized_block_markup' ) as $field ) {
				if ( is_string( $page[ $field ] ?? null ) ) {
					$state['ordered_pages'][ $index ][ $field ] = self::rewrite_references( $page[ $field ], $refs );
				}
			}
		}
		return $state;
	}

	/** Resolve declared IDs only; navigation recognition belongs to the producer. */
	public static function rewrite_references( string $content, array $refs ): string {
		return (string) preg_replace_callback(
			'/"ref"\s*:\s*"\{\{wordpress-site-plan:navigation:(navigation-[a-f0-9]{16})}}"/',
			static fn( array $match ): string => isset( $refs[ $match[1] ] ) ? '"ref":' . (int) $refs[ $match[1] ] : $match[0],
			$content
		);
	}

	/** @return int|WP_Error */
	private static function upsert( array $menu, string $content, array &$state ) {
		$identity = $menu['reconciliation_identity'];
		$existing = Static_Site_Importer_Site_Plan_Persistence::reconciled_post( $identity, 'wp_navigation' );
		if ( $existing && 'wp_navigation' !== $existing->post_type ) {
			return new WP_Error( 'navigation_entity_identity_conflict' );
		}
		$title = trim( (string) ( $menu['title'] ?? '' ) ) ?: 'Navigation';
		$postarr = array(
			'post_title'   => $title,
			'post_name'    => sanitize_title( (string) ( $menu['target_slug'] ?? $title ) ) ?: 'navigation',
			'post_status'  => 'publish',
			'post_type'    => 'wp_navigation',
			'post_content' => wp_slash( $content ),
		);
		if ( $existing instanceof WP_Post ) {
			$postarr['ID'] = (int) $existing->ID;
			Static_Site_Importer_Site_Plan_Persistence::journal_post( $state, array( 'planned_existing_id' => $existing->ID, 'source_path' => $menu['source_path'] ) );
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'navigation_entity_write_failed' );
		}
		if ( ! isset( $postarr['ID'] ) ) {
			$state['rollback']['posts'][ $id ] = array( 'existing' => false );
		}
		$state['applied']['posts'][] = array( 'id' => $id, 'source_path' => $menu['source_path'], 'reconciliation_identity' => $identity, 'post_type' => 'wp_navigation' );
		if ( ! Static_Site_Importer_Site_Plan_Persistence::write_post_meta( $id, self::META_KEY, $identity ) ) {
			return new WP_Error( 'navigation_entity_metadata_write_failed' );
		}
		return $id;
	}
}
